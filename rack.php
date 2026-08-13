<?php
session_start();
require 'php/auth.php';
require 'php/db.php';
require_once 'php/config.php';

// ── Auth: token kiosk en URL → validar token; sin token → sesión normal ──
$kioskToken = trim($_GET['k'] ?? $_GET['kiosk'] ?? '');
$authed = false;

if ($kioskToken !== '') {
    try {
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='kiosk_token' LIMIT 1");
        $s->execute();
        $stored = $s->fetchColumn();
        if ($stored && $stored !== '' && hash_equals($stored, $kioskToken)) {
            $authed = true;
        }
    } catch (Throwable $e) {}
} else {
    $authed = tryRestoreSession();
}

if (!$authed) {
    http_response_code(401);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>WakeLab</title>
    <style>body{margin:0;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f1117;font-family:system-ui,sans-serif;color:#8b8fa8}
    .box{text-align:center}.icon{font-size:48px;margin-bottom:16px}.msg{font-size:15px;font-weight:600;color:#e0e0e0;margin-bottom:6px}.sub{font-size:12px}</style>
    </head><body><div class="box"><div class="icon">🔒</div><div class="msg">Token inválido</div><div class="sub">Verificá el token e intentá de nuevo.</div></div></body></html>';
    exit;
}
session_write_close();

// ── Data ──────────────────────────────────────────────────────
function rackSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
        $s->execute([$key]);
        $r = $s->fetchColumn();
        return $r !== false ? $r : $default;
    } catch (Throwable $e) { return $default; }
}

try { $servers = $pdo->query("SELECT * FROM servers ORDER BY id")->fetchAll(); }
catch (Throwable $e) { $servers = []; }

try {
    $schedules = $pdo->query("SELECT * FROM schedules")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
} catch (Throwable $e) { $schedules = []; }

try {
    $idle_configs = $pdo->query("SELECT * FROM idle_config")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
} catch (Throwable $e) { $idle_configs = []; }

try {
    $hiddenServers = [];
    $rows = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'srv_%_visible'")->fetchAll();
    foreach ($rows as $r) {
        if (preg_match('/^srv_(\d+)_visible$/', $r['key'], $m) && $r['value'] === '0')
            $hiddenServers[(int)$m[1]] = true;
    }
} catch (Throwable $e) { $hiddenServers = []; }

try {
    $sshConfiguredMap = [];
    $idleDeployedMap  = [];
    $rows = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'srv_%_ssh_user' OR `key` LIKE 'srv_%_idle_deployed'")->fetchAll();
    foreach ($rows as $r) {
        if (preg_match('/^srv_(\d+)_ssh_user$/', $r['key'], $m))
            $sshConfiguredMap[(int)$m[1]] = trim((string)$r['value']) !== '';
        elseif (preg_match('/^srv_(\d+)_idle_deployed$/', $r['key'], $m))
            $idleDeployedMap[(int)$m[1]] = $r['value'] === '1';
    }
} catch (Throwable $e) { $sshConfiguredMap = []; $idleDeployedMap = []; }

$wakeLabKeyReady = file_exists('/var/www/.ssh/id_ed25519.pub');
$visibleServers  = array_filter($servers, fn($s) => empty($hiddenServers[$s['id']]));
$serverNames     = array_column($servers, 'hostname', 'id');

try {
    $wake_proxies = $pdo->query(
        "SELECT wp.*, s.hostname AS srv_hostname FROM wake_proxies wp JOIN servers s ON s.id=wp.server_id ORDER BY wp.name"
    )->fetchAll();
} catch (Throwable $e) { $wake_proxies = []; }

/* V2 — UPS UI deshabilitado temporalmente
try {
    $upsEvents = $pdo->query(
        "SELECT ups_name, event, hosts_affected, status, created_at FROM ups_events
         WHERE id IN (SELECT MAX(id) FROM ups_events GROUP BY ups_name)
         ORDER BY ups_name"
    )->fetchAll();
} catch (Throwable $e) { $upsEvents = []; }

// Hosts con ups_managed activo (para mostrar si están en riesgo)
$upsManagedHosts = array_filter($servers, fn($s) => !empty($s['ups_managed']));
$upsManagedCount = count($upsManagedHosts);
*/
$upsEvents = []; $upsManagedHosts = []; $upsManagedCount = 0;

$pollingMs = intval(rackSetting($pdo, 'polling_interval_sec', '30')) * 1000;

$bcPveId = rackSetting($pdo, 'nut_last_pve_id');
$bcVmid  = rackSetting($pdo, 'nut_last_vmid');
$bcUps   = rackSetting($pdo, 'nut_last_ups_name');

// Helper: format TIME column HH:MM:SS → HH:MM
function fmtTime(?string $t): string {
    if (!$t) return '--:--';
    return substr($t, 0, 5);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WakeLab · Rack</title>
<link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');})();</script>
<link rel="stylesheet" href="assets/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/style.css">
<style>
/* Rack page layout — mínimo, el resto lo hace body.app-rack de style.css */
html, body { height: 100%; margin: 0; overflow: hidden; }
body { display: flex; flex-direction: column; }

.rk-nav {
    display: flex; align-items: center; gap: 10px;
    padding: 0 14px; height: 46px; flex-shrink: 0;
    background: var(--bg-card); border-bottom: 1px solid var(--border);
}
.rk-nav-logo {
    display: flex; align-items: center; gap: 8px;
    font-size: 15px; font-weight: 700; color: var(--blue);
    letter-spacing: .04em; text-decoration: none; flex-shrink: 0;
}
.rk-nav-logo img { width: 22px; height: 22px; border-radius: 5px; }
.rk-nav-sep { width: 1px; height: 22px; background: var(--border); flex-shrink: 0; }
.rk-nav-ups { display: flex; align-items: center; gap: 8px; flex: 1; overflow: hidden; }
.rk-ups-pill {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; color: var(--text-dim); white-space: nowrap;
    background: var(--bg-deep); border: 1px solid var(--border-sub);
    border-radius: var(--radius-sm); padding: 3px 9px;
    transition: border-color .2s;
}
.rk-ups-pill--warn  { border-color: var(--amber-bdr); color: var(--amber); background: var(--amber-bg); }
.rk-ups-pill--danger { border-color: var(--red-bdr);  color: var(--red);   background: var(--red-bg);   }
.rk-ups-pill--ok    { border-color: var(--green-bdr); color: var(--green); background: var(--green-bg); }
.rk-ups-pill .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.rk-ups-pill strong { color: inherit; font-weight: 600; }
.rk-ups-ago  { font-size: 10px; opacity: .7; margin-left: 2px; }
.rk-ups-sep  { width: 1px; height: 16px; background: var(--border-sub); margin: 0 2px; }
.rk-ups-srv-chip {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; color: var(--text-dim); white-space: nowrap;
    padding: 2px 8px; border-radius: var(--radius-sm);
    border: 1px solid var(--border-sub); background: var(--bg-deep);
}
.rk-nav-end { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.sync-btn {
    display: flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 6px; border: 1px solid var(--border-sub);
    background: var(--bg-deep); color: var(--text-sub); cursor: pointer; padding: 0;
    transition: color .15s, border-color .15s;
}
.sync-btn:hover { color: var(--text); border-color: var(--border); }
.sync-btn.syncing svg { animation: rk-spin 700ms linear infinite; }
@keyframes rk-spin { to { transform: rotate(360deg); } }

/* Body = sidebar + main */
.rk-body { display: flex; flex: 1; overflow: hidden; }

/* Sidebar icon-only */
.rk-sidebar {
    width: 50px; flex-shrink: 0;
    background: var(--bg-card); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; align-items: center;
    padding: 10px 0; gap: 6px;
}
.rk-sb-btn {
    width: 34px; height: 34px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: var(--text-dim);
    background: none; border: 1px solid transparent;
    cursor: pointer; text-decoration: none; position: relative;
    transition: color .15s, border-color .15s, background .15s;
}
.rk-sb-btn:hover { color: var(--text); border-color: var(--border); }
.rk-sb-btn.active { color: var(--blue); border-color: var(--border); background: var(--bg-deep); }
.rk-sb-btn i::before { vertical-align: middle; }
.rk-sb-dot {
    position: absolute; top: 3px; right: 3px;
    width: 7px; height: 7px; border-radius: 50%;
    border: 1.5px solid var(--bg-card);
}

/* Main scroll area */
.rk-main { flex: 1; overflow-y: auto; padding: 10px; }

/* Cards grid */
.rk-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
/* Cancelar el max-width:25% de body.app-rack .srv-card-col que rompía el grid */
.rk-cards-grid .srv-card-col {
    max-width: none !important;
    flex: none !important;
    width: 100%;
}
@media (max-width: 900px) { .rk-cards-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 500px) { .rk-cards-grid { grid-template-columns: 1fr; } }

/* Wake proxy view */
.rk-wp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 8px; }
.rk-wp-card {
    flex-direction: row !important; /* override body.app-rack .srv-card column */
    align-items: center !important;
    padding: 10px 14px !important;
    cursor: default;
    gap: 12px;
}
.rk-wp-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: var(--text-dim); }
.rk-wp-dot.online  { background: var(--green); }
.rk-wp-dot.offline { background: var(--red); }

/* Toast */
.rk-toast {
    position: fixed; bottom: 16px; right: 16px; z-index: 9999;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 10px 14px; font-size: 12px;
    opacity: 0; transform: translateY(6px);
    transition: opacity .2s, transform .2s; pointer-events: none;
}
.rk-toast.show { opacity: 1; transform: translateY(0); }
</style>
</head>
<body id="app-body" class="app-rack">

<!-- Navbar -->
<nav class="rk-nav">
    <span class="rk-nav-logo">
        <img src="assets/icons/web-app-manifest-192x192.png" alt="WakeLab"> WakeLab
    </span>
    <div class="rk-nav-sep"></div>
    <!-- UPS battery pill -->
    <div class="rk-nav-ups">
        <span id="rk-ups-pill" class="rk-ups-pill" style="display:none;cursor:pointer" onclick="rkView('ups')" title="UPS battery status">
            <i id="rk-ups-icon" class="bi bi-battery-full" style="font-size:13px"></i>
            <strong id="rk-ups-pct">—%</strong>
            <span class="rk-ups-sep"></span>
            <span id="rk-ups-runtime" style="font-size:10px;opacity:.8"></span>
            <span class="rk-ups-sep"></span>
            <span id="rk-ups-status-label" class="rk-ups-ago"></span>
        </span>
    </div>
    <div class="rk-nav-end">
        <div class="topbar-stats">
            <span><span class="dot dot-green"></span><span id="rk-cnt-on">—</span> online</span>
            <span><span class="dot dot-gray"></span><span id="rk-cnt-off">—</span> offline</span>
        </div>
        <button class="sync-btn" onclick="rkSync(true)" title="Refresh">
            <svg id="rk-sync-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                <path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/>
            </svg>
        </button>
        <button class="theme-toggle" onclick="rkToggleTheme()" title="Toggle theme">
            <i id="rk-theme-icon" class="bi bi-sun-fill"></i>
        </button>
    </div>
</nav>

<!-- Body -->
<div class="rk-body">

    <!-- Sidebar -->
    <aside class="rk-sidebar">
        <button class="rk-sb-btn active" id="rk-sb-dash" onclick="rkView('dash')" title="Servers">
            <i class="bi bi-speedometer2"></i>
        </button>
        <button class="rk-sb-btn" id="rk-sb-wp" onclick="rkView('wp')" title="Wake Proxies">
            <i class="bi bi-diagram-3-fill"></i>
            <?php $wpActive = count(array_filter($wake_proxies, fn($w) => !empty($w['active']))); ?>
            <span class="rk-sb-dot" style="background:<?= $wpActive < count($wake_proxies) ? 'var(--amber)' : 'var(--green)' ?>"></span>
        </button>
        <?php if ($bcPveId ?? ''): ?>
        <button class="rk-sb-btn" id="rk-sb-ups" onclick="rkView('ups')" title="UPS Battery">
            <i class="bi bi-battery-half"></i>
            <span class="rk-sb-dot" id="rk-ups-sb-dot" style="background:var(--green)"></span>
        </button>
        <?php endif; ?>
    </aside>

    <!-- Main -->
    <main class="rk-main">

        <!-- View: servers -->
        <div id="rk-view-dash">
        <div class="rk-cards-grid">
        <?php foreach ($visibleServers as $srv):
            $id     = $srv['id'];
            $sch    = $schedules[$id]    ?? null;
            $idl    = $idle_configs[$id] ?? null;
            $schOn  = !empty($sch['active']);
            $shutOn = !empty($sch['shutdown_active']);
            $idlOn  = !empty($idl['active']);
            $schBt  = fmtTime($sch['boot_time']     ?? null);
            $schSt  = fmtTime($sch['shutdown_time'] ?? null);
            $cSch   = !empty($sch);
            $srvTypeCard = $srv['hypervisor_type'] ?? 'generic';
            $needsSsh    = !in_array($srvTypeCard, ['pve','generic']);
            $sshOk       = !$needsSsh || !empty($sshConfiguredMap[$id]) || $wakeLabKeyReady;
            $deployOk    = !empty($idleDeployedMap[$id]);
            $idleBlocked = !$sshOk || !$deployOk;
            $srvUrl  = trim($srv['url'] ?? '');
            if ($srvUrl && !preg_match('/^https?:\/\//i', $srvUrl)) {
                $proto  = ($srv['port'] == 80 || $srv['port'] == 8080) ? 'http' : 'https';
                $srvUrl = "{$proto}://{$srvUrl}";
            }
            $depId   = intval($srv['depends_on_server_id'] ?? 0);
            $depName = $depId ? ($serverNames[$depId] ?? null) : null;
        ?>
        <div class="srv-card-col" id="card-col-<?= $id ?>">
        <div class="srv-card" id="card-<?= $id ?>">

            <div class="srv-card-row" style="margin-bottom:4px">
                <div class="srv-name">
                    <span class="dot dot-gray" id="dot-<?= $id ?>"></span>
                    <?= htmlspecialchars($srv['hostname']) ?>
                </div>
                <span class="srv-role"><?= htmlspecialchars($srv['role'] ?? '') ?></span>
            </div>

            <div class="srv-card-row" style="margin-bottom:6px">
                <div style="display:flex;align-items:center;gap:6px">
                    <span class="srv-ip-text"><?= htmlspecialchars($srv['ip']) ?></span>
                    <span id="card-ping-<?= $id ?>" class="ping-none">—</span>
                </div>
                <?php if ($depName): ?>
                <span class="srv-dep-badge" title="Depende de: <?= htmlspecialchars($depName) ?>">↑ <?= htmlspecialchars($depName) ?></span>
                <?php endif; ?>
            </div>

            <div class="srv-metrics" id="card-metrics-<?= $id ?>">
                <div class="skeleton-metric"><div class="skeleton-line h6" style="width:40%;margin-bottom:6px"></div><div class="skeleton-line h14" style="width:70%"></div></div>
                <div class="skeleton-metric"><div class="skeleton-line h6" style="width:40%;margin-bottom:6px"></div><div class="skeleton-line h14" style="width:50%"></div></div>
                <div class="skeleton-metric"><div class="skeleton-line h6" style="width:30%;margin-bottom:6px"></div><div class="skeleton-line h14" style="width:60%"></div></div>
            </div>

            <div class="srv-btns" id="card-btns-<?= $id ?>">
                <button class="rack-btn rack-btn-wake rack-only"
                        onclick="event.stopPropagation();rkAction(<?= $id ?>,'<?= htmlspecialchars($srv['hostname'],ENT_QUOTES) ?>','wol')">
                    <i class="bi bi-lightning-charge-fill"></i><span>Wake</span>
                </button>
                <button class="rack-btn rack-btn-reboot rack-only"
                        onclick="event.stopPropagation();rkAction(<?= $id ?>,'<?= htmlspecialchars($srv['hostname'],ENT_QUOTES) ?>','reboot')">
                    <i class="bi bi-arrow-clockwise"></i><span>Reboot</span>
                </button>
                <button class="rack-btn rack-btn-shut rack-only"
                        onclick="event.stopPropagation();rkAction(<?= $id ?>,'<?= htmlspecialchars($srv['hostname'],ENT_QUOTES) ?>','shutdown')">
                    <i class="bi bi-power"></i><span>Shutdown</span>
                </button>
            </div>

            <div class="rack-only rack-toggles" onclick="event.stopPropagation()">
                <label class="rack-toggle-item">
                    <span class="rack-toggle-label" style="color:var(--green)"><i class="bi bi-arrow-up-circle"></i> Boot</span>
                    <span class="rack-toggle-time"><?= $schBt ?></span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" <?= $schOn ? 'checked' : '' ?> <?= !$cSch ? 'disabled' : '' ?>
                               onchange="rkQuickToggle(<?= $id ?>,'schedule',this.checked)">
                    </div>
                </label>
                <label class="rack-toggle-item">
                    <span class="rack-toggle-label" style="color:var(--red)"><i class="bi bi-arrow-down-circle"></i> Shut</span>
                    <span class="rack-toggle-time"><?= $schSt ?></span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" <?= $shutOn ? 'checked' : '' ?> <?= !$cSch ? 'disabled' : '' ?>
                               onchange="rkQuickToggle(<?= $id ?>,'shutdown',this.checked)">
                    </div>
                </label>
                <label class="rack-toggle-item" <?= $idleBlocked ? 'title="Configure SSH/idle first"' : '' ?>>
                    <span class="rack-toggle-label" style="color:<?= $idleBlocked ? 'var(--text-dim)' : 'var(--amber)' ?>"><i class="bi bi-moon-stars-fill"></i> Idle</span>
                    <span class="rack-toggle-time">&nbsp;</span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" <?= $idlOn ? 'checked' : '' ?> <?= $idleBlocked ? 'disabled' : '' ?>
                               onchange="rkQuickToggle(<?= $id ?>,'idle',this.checked)">
                    </div>
                </label>
            </div>

        </div>
        </div>
        <?php endforeach; ?>
        </div><!-- /.rk-cards-grid -->
        </div><!-- /#rk-view-dash -->

        <!-- View: wake proxies -->
        <div id="rk-view-wp" style="display:none">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;flex-wrap:wrap">
                <span style="font-size:13px;font-weight:600"><i class="bi bi-diagram-3-fill me-2" style="color:var(--blue)"></i>Wake Proxies</span>
                <span style="font-size:11px;color:var(--text-dim)">
                    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-right:3px"></span><span id="rk-wp-cnt-on">—</span> online &nbsp;
                    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--red);margin-right:3px"></span><span id="rk-wp-cnt-off">—</span> offline &nbsp;
                    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--text-dim);margin-right:3px"></span><span id="rk-wp-cnt-in">—</span> inactive
                </span>
            </div>
            <div class="rk-wp-grid">
            <?php foreach ($wake_proxies as $wp): ?>
            <div class="srv-card rk-wp-card">
                <span class="rk-wp-dot" id="rk-wp-dot-<?= $wp['id'] ?>"></span>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($wp['name']) ?></div>
                    <div style="font-size:10px;color:var(--text-dim);margin-top:2px"><?= htmlspecialchars($wp['domain']) ?></div>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:1px">
                        <i class="bi bi-server" style="font-size:9px"></i>
                        <?= htmlspecialchars($wp['srv_hostname']) ?> · <?= htmlspecialchars($wp['dest_ip']) ?>:<?= intval($wp['dest_port']) ?>
                    </div>
                </div>
                <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;<?= $wp['active'] ? 'background:var(--green-bg);color:var(--green)' : 'background:var(--bg-deep);color:var(--text-dim);border:1px solid var(--border-sub)' ?>">
                    <?= $wp['active'] ? 'active' : 'inactive' ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($wake_proxies)): ?>
                <div style="padding:40px;text-align:center;color:var(--text-dim);font-size:12px">No wake proxies configured</div>
            <?php endif; ?>
            </div>
        </div><!-- /#rk-view-wp -->

        <!-- View: UPS Battery -->
        <?php if ($bcPveId ?? ''): ?>
        <div id="rk-view-ups" style="display:none;max-width:480px;margin:0 auto">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
                <span style="font-size:13px;font-weight:600"><i class="bi bi-battery-half me-2" style="color:var(--amber)"></i>UPS Battery</span>
                <button onclick="rkFetchBattery()" style="background:none;border:1px solid var(--border);border-radius:var(--radius);padding:3px 10px;font-size:11px;color:var(--text-muted);cursor:pointer">
                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                </button>
                <span id="rk-ups-updated" style="font-size:10px;color:var(--text-dim);margin-left:auto"></span>
            </div>

            <!-- Battery widget -->
            <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:16px">
                <div id="rk-bat-widget" style="min-height:80px">
                    <span style="color:var(--text-dim);font-size:12px">Loading…</span>
                </div>
            </div>

            <!-- Last event -->
            <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:16px 20px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim);margin-bottom:10px">Last Event</div>
                <div id="rk-bat-lastevent" style="font-size:12px;color:var(--text-dim)">—</div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- Toast -->
<div class="rk-toast" id="rk-toast"></div>

<!-- Confirm modal -->
<div class="modal fade" id="rk-modal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:340px">
        <div class="modal-content" style="background:var(--bg-card);border:1px solid var(--border);border-radius:14px;color:var(--text);overflow:hidden">
            <div class="modal-body text-center" style="padding:32px 28px 24px">
                <div id="rk-modal-icon" style="font-size:36px;margin-bottom:14px"></div>
                <div id="rk-modal-msg" style="font-size:17px;font-weight:600;margin-bottom:6px"></div>
                <div id="rk-modal-sub" style="font-size:12px;color:var(--text-dim);margin-bottom:24px"></div>
                <div class="d-flex gap-3 justify-content-center">
                    <button class="btn btn-outline-secondary" style="min-width:90px;border-radius:8px" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn" id="rk-modal-ok" style="min-width:110px;border-radius:8px;font-weight:600"></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap/bootstrap.bundle.min.js"></script>
<script>
const KIOSK_TOKEN = <?= json_encode($kioskToken) ?>;
function rkApiFetch(url, opts = {}) {
    if (KIOSK_TOKEN) {
        opts.headers = Object.assign({}, opts.headers || {}, {'X-Kiosk-Token': KIOSK_TOKEN});
    }
    return fetch(url, opts);
}
// ── Theme ──────────────────────────────────────────────────
(function(){
    const light = document.documentElement.classList.contains('light');
    document.getElementById('rk-theme-icon').className = light ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
})();
function rkToggleTheme() {
    const light = document.documentElement.classList.toggle('light');
    localStorage.setItem('theme', light ? 'light' : 'dark');
    document.getElementById('rk-theme-icon').className = light ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
}

// ── Sidebar view switch ────────────────────────────────────
function rkView(name) {
    const views = ['dash','wp','ups'];
    views.forEach(v => {
        const el = document.getElementById('rk-view-' + v);
        if (el) el.style.display = v === name ? '' : 'none';
        const btn = document.getElementById('rk-sb-' + v);
        if (btn) btn.classList.toggle('active', v === name);
    });
    if (name === 'wp') rkCheckWakeProxies();
    if (name === 'ups') rkFetchBattery();
}

// ── Toast ──────────────────────────────────────────────────
let _tt;
function rkToast(msg, type) {
    const el = document.getElementById('rk-toast');
    el.textContent = msg;
    el.style.cssText += ';border-left:3px solid ' + (type==='ok'?'var(--green)':type==='err'?'var(--red)':'var(--amber)');
    el.classList.add('show');
    clearTimeout(_tt);
    _tt = setTimeout(() => el.classList.remove('show'), 3000);
}

// ── Confirm + action ───────────────────────────────────────
const _modal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('rk-modal'));
function rkAction(id, hn, act) {
    const cfg = {
        wol:      { label:'Wake up',    icon:'⚡', color:'btn-success', sub:'Send Wake-on-LAN magic packet' },
        reboot:   { label:'Reboot',     icon:'🔄', color:'btn-primary', sub:'Restart the host gracefully'  },
        shutdown: { label:'Shut down',  icon:'⏻',  color:'btn-danger',  sub:'Power off the host'           },
    };
    const c = cfg[act];
    document.getElementById('rk-modal-icon').textContent = c.icon;
    document.getElementById('rk-modal-msg').textContent  = c.label + ' ' + hn + '?';
    document.getElementById('rk-modal-sub').textContent  = c.sub;
    const ok = document.getElementById('rk-modal-ok');
    ok.className = 'btn ' + c.color;
    ok.style.minWidth = '110px'; ok.style.borderRadius = '8px'; ok.style.fontWeight = '600';
    ok.textContent = c.label;
    ok.onclick = () => { _modal().hide(); rkDoAction(id, hn, act); };
    _modal().show();
}
function rkDoAction(id, hn, act) {
    rkApiFetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'server_action', command: act, server_id: id, source:'rack'})})
    .then(r=>r.json())
    .then(d=>{
        const ok = d.status==='success'||d.status==='already';
        const labels = {wol:'Wake enviado',reboot:'Reboot enviado',shutdown:'Shutdown enviado'};
        rkToast(ok ? (labels[act]||act)+' — '+hn : (d.message||'Error'), ok?'ok':'err');
    })
    .catch(()=>rkToast('Request failed','err'));
}

// ── Quick toggle ───────────────────────────────────────────
function rkQuickToggle(id, type, val) {
    const action = type==='schedule' ? 'set_schedule_active'
                 : type==='shutdown' ? 'set_shutdown_active'
                 : 'set_idle_active';
    rkApiFetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action, server_id:id, active: val?1:0})})
    .then(r=>r.json())
    .then(d=>{ if(d.status!=='success') rkToast(d.message||'Error','err'); })
    .catch(()=>rkToast('Error','err'));
}

// ── Polling ────────────────────────────────────────────────
const POLL_MS = <?= $pollingMs ?>;
let _syncing = false;
let _syncFadeTimer = null;

function rkUpdateCard(srv) {
    const id   = srv.id;
    const isOn = srv.status === 'online';

    const dot = document.getElementById('dot-' + id);
    if (dot) dot.className = 'dot ' + (isOn ? 'dot-green' : 'dot-gray');
    // V2 — UPS chip dot deshabilitado
    // const upsDot = document.getElementById('ups-dot-' + id);
    // if (upsDot) upsDot.className = 'dot ' + (isOn ? 'dot-green' : 'dot-gray');

    // ping (fila IP)
    const ping = document.getElementById('card-ping-' + id);
    if (ping) { ping.textContent = ''; ping.className = 'ping-none'; }

    // metrics
    const metrics = document.getElementById('card-metrics-' + id);
    if (metrics) {
        const cpu = srv.node_cpu != null ? srv.node_cpu + '%' : '—';
        const ram = srv.node_mem_total != null
            ? (srv.node_mem != null ? srv.node_mem : '—') + '/' + srv.node_mem_total + ' GB'
            : '—';
        const cpuColor = srv.node_cpu != null ? (srv.node_cpu > 80 ? 'var(--amber)' : 'var(--blue)') : '';
        metrics.innerHTML =
            `<div class="metric"><div class="metric-label">CPU</div><div class="metric-val" style="color:${cpuColor}">${cpu}</div></div>` +
            `<div class="metric"><div class="metric-label">RAM</div><div class="metric-val">${ram}</div></div>` +
            `<div class="metric"><div class="metric-label">PING</div><div class="metric-val">${srv.ping_ms != null ? srv.ping_ms + 'ms' : '—'}</div></div>`;
    }

    // buttons — same logic as app.js
    const btns = document.getElementById('card-btns-' + id);
    if (btns) {
        const hn = srv.hostname || '';
        const hnJ = JSON.stringify(hn); // safe for inline onclick attribute
        btns.innerHTML =
            `<button class="rack-btn rack-btn-wake rack-only${isOn?' rack-btn-disabled':''}"
                ${isOn?'disabled':`onclick="event.stopPropagation();rkAction(${id},${hnJ},'wol')"`}>
                <i class="bi bi-lightning-charge-fill"></i><span>Wake</span></button>` +
            `<button class="rack-btn rack-btn-reboot rack-only${!isOn?' rack-btn-disabled':''}"
                ${!isOn?'disabled':`onclick="event.stopPropagation();rkAction(${id},${hnJ},'reboot')"`}>
                <i class="bi bi-arrow-clockwise"></i><span>Reboot</span></button>` +
            `<button class="rack-btn rack-btn-shut rack-only${!isOn?' rack-btn-disabled':''}"
                ${!isOn?'disabled':`onclick="event.stopPropagation();rkAction(${id},${hnJ},'shutdown')"`}>
                <i class="bi bi-power"></i><span>Shutdown</span></button>`;
    }
}

function rkSync(manual = false) {
    if (_syncing && !manual) return;

    // Reiniciar animación (restart CSS para que se vea aunque ya estuviera girando)
    const btn = document.querySelector('.sync-btn');
    if (btn) { btn.classList.remove('syncing'); void btn.offsetWidth; btn.classList.add('syncing'); }

    if (_syncing) return; // ya hay fetch en vuelo, sólo retriggeramos la animación
    _syncing = true;

    const t0 = Date.now();
    rkApiFetch('php/api.php?action=get_status&metrics=1')
        .then(r=>r.json())
        .then(d=>{
            if(d.status==='success'&&Array.isArray(d.data)){
                d.data.forEach(rkUpdateCard);
                const on=d.data.filter(s=>s.status==='online').length;
                const off=d.data.length-on;
                const elOn=document.getElementById('rk-cnt-on'); if(elOn) elOn.textContent=on;
                const elOff=document.getElementById('rk-cnt-off'); if(elOff) elOff.textContent=off;
            }
        })
        .catch(()=>{})
        .finally(()=>{
            clearTimeout(_syncFadeTimer);
            _syncFadeTimer = setTimeout(()=>{
                _syncing = false;
                btn?.classList.remove('syncing');
            }, Math.max(0, 750 - (Date.now() - t0))); // ≥1 vuelta completa (animación=700ms)
        });
}

function rkCheckWakeProxies() {
    <?php if (!empty($wake_proxies)): ?>
    const proxies = <?= json_encode(array_map(fn($w)=>['id'=>(int)$w['id'],'active'=>(bool)$w['active']], $wake_proxies)) ?>;
    let on=0, off=0, inactive=0;
    Promise.allSettled(proxies.map(p => {
        if (!p.active) { inactive++; return Promise.resolve(); }
        return fetch(`php/api.php?action=wake_proxy_status&id=${p.id}`)
            .then(r=>r.json())
            .then(d=>{
                const isOn = d.data?.status==='online';
                const dot = document.getElementById('rk-wp-dot-'+p.id);
                if (dot) dot.className = 'rk-wp-dot ' + (isOn?'online':'offline');
                if (isOn) on++; else off++;
            }).catch(()=>off++);
    })).then(()=>{
        ['on','off','in'].forEach((k,i)=>{ const el=document.getElementById('rk-wp-cnt-'+k); if(el) el.textContent=[on,off,inactive][i]; });
        const dot=document.querySelector('#rk-sb-wp .rk-sb-dot');
        if(dot) dot.style.background=off>0?'var(--red)':'var(--green)';
    });
    <?php endif; ?>
}

// ── UPS Battery ────────────────────────────────────────────
<?php if ($bcPveId ?? ''): ?>
let _upsPollTimer = null;
let _upsOnBattery = false;

function rkFetchBattery() {
    rkApiFetch('php/api.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'ups_battery_status'})
    }).then(r=>r.json()).then(d=>{
        if (d.status !== 'success') return;
        const b = d.data;
        _upsOnBattery = (b.status || '').includes('OB');
        _rkRenderBattery(b);
        _rkUpdatePill(b);
        _rkSetPollInterval();
    }).catch(()=>{});
}

function _rkRenderBattery(b) {
    const pct     = b.charge != null ? +b.charge : null;
    const runtime = b.runtime != null ? Math.round(+b.runtime / 60) : null;
    const status  = b.status  || '—';
    const onBat   = status.includes('OB');
    const lowBat  = status.includes('LB');

    const barColor = lowBat ? 'var(--red)' : onBat ? 'var(--amber)' : 'var(--green)';
    const icon     = lowBat ? 'bi-battery' : onBat ? 'bi-battery-half' : 'bi-battery-full';
    const statusLabel = onBat ? (lowBat ? 'Low battery' : 'On battery') : 'Online';

    const widget = document.getElementById('rk-bat-widget');
    if (!widget) return;

    widget.innerHTML = `
        <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:6px">
            <i class="bi ${icon}" style="font-size:22px;color:${barColor}"></i>
            <span style="font-size:36px;font-weight:700;line-height:1;color:${barColor}">${pct != null ? pct+'%' : '—'}</span>
            <span style="font-size:12px;color:var(--text-dim);margin-left:4px">${statusLabel}</span>
        </div>
        <div style="background:var(--bg-deep);border-radius:6px;height:8px;overflow:hidden;margin-bottom:14px">
            <div style="width:${pct ?? 0}%;height:100%;background:${barColor};border-radius:6px;transition:width .4s"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:11px">
            <div><span style="color:var(--text-dim)">Runtime</span><br><strong>${runtime != null ? runtime+'min' : '—'}</strong></div>
            <div><span style="color:var(--text-dim)">UPS</span><br><strong>${b.ups_name || '—'}</strong></div>
            <div><span style="color:var(--text-dim)">Input voltage</span><br><strong>${b.input_voltage != null ? b.input_voltage+'V' : '—'}</strong></div>
            <div><span style="color:var(--text-dim)">Battery voltage</span><br><strong>${b.battery_voltage != null ? b.battery_voltage+'V' : '—'}</strong></div>
        </div>`;

    const upd = document.getElementById('rk-ups-updated');
    if (upd) upd.textContent = 'Updated ' + new Date().toLocaleTimeString();
}

function _rkUpdatePill(b) {
    const pct     = b.charge  != null ? +b.charge  : null;
    const runtime = b.runtime != null ? Math.round(+b.runtime / 60) : null;
    const status  = b.status || '';
    const onBat   = status.includes('OB');
    const lowBat  = status.includes('LB');

    const pill    = document.getElementById('rk-ups-pill');
    const icon    = document.getElementById('rk-ups-icon');
    const pctEl   = document.getElementById('rk-ups-pct');
    const rtEl    = document.getElementById('rk-ups-runtime');
    const label   = document.getElementById('rk-ups-status-label');
    const dot     = document.getElementById('rk-ups-sb-dot');

    if (pill) pill.style.display = '';
    if (pctEl) pctEl.textContent = pct != null ? pct+'%' : '—%';
    if (rtEl)  rtEl.textContent  = runtime != null ? runtime+'min' : '';
    if (label) label.textContent = lowBat ? 'LOW' : onBat ? 'BATT' : 'OK';

    pill?.classList.toggle('rk-ups-pill--danger', lowBat);
    pill?.classList.toggle('rk-ups-pill--warn', onBat && !lowBat);
    pill?.classList.toggle('rk-ups-pill--ok', !onBat);

    const iconClass = lowBat ? 'bi-battery' : onBat ? 'bi-battery-half' : 'bi-battery-full';
    if (icon) icon.className = 'bi ' + iconClass;

    const dotColor = lowBat ? 'var(--red)' : onBat ? 'var(--amber)' : 'var(--green)';
    if (dot) dot.style.background = dotColor;
}

function _rkSetPollInterval() {
    clearInterval(_upsPollTimer);
    const ms = _upsOnBattery ? 10000 : 5 * 60 * 1000;
    _upsPollTimer = setInterval(rkFetchBattery, ms);
}

// Auto-start battery polling
rkFetchBattery();
<?php endif; ?>

rkSync();
setInterval(rkSync, POLL_MS);
</script>
</body>
</html>
