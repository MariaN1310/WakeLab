<?php
session_start();
require 'php/auth.php';
requireLogin('login.php');
session_write_close(); // liberar lock — index.php no escribe sesión

require 'php/db.php';

try {
    $servers      = $pdo->query("SELECT * FROM servers ORDER BY id")->fetchAll();
} catch (Throwable $e) { $servers = []; }

// Mapa id→hostname para resolver nombres de dependencias
$serverNames = [];
foreach ($servers as $s) $serverNames[$s['id']] = $s['hostname'];

try {
    $schedules    = $pdo->query("SELECT * FROM schedules")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
} catch (Throwable $e) { $schedules = []; }

try {
    $idle_configs = $pdo->query("SELECT * FROM idle_config")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
} catch (Throwable $e) { $idle_configs = []; }

try {
    $api_tokens   = $pdo->query("SELECT * FROM api_tokens")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
} catch (Throwable $e) { $api_tokens = []; }

try {
    $wake_proxies = $pdo->query(
        "SELECT wp.*, s.hostname AS srv_hostname
         FROM wake_proxies wp JOIN servers s ON s.id=wp.server_id ORDER BY wp.name"
    )->fetchAll();
} catch (Throwable $e) { $wake_proxies = []; }

try {
    $tn_ssh = [];
    $rows = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'srv_%_ssh_%'")->fetchAll();
    foreach ($rows as $r) {
        if (preg_match('/^srv_(\d+)_ssh_(user|pass|port)$/', $r['key'], $m)) {
            $tn_ssh[(int)$m[1]][$m[2]] = $r['value'];
        }
    }
} catch (Throwable $e) { $tn_ssh = []; }

try {
    $shutdownTimeoutMap = [];
    $rows = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'srv_%_shutdown_timeout'")->fetchAll();
    foreach ($rows as $r) {
        if (preg_match('/^srv_(\d+)_shutdown_timeout$/', $r['key'], $m)) {
            $shutdownTimeoutMap[(int)$m[1]] = intval($r['value']);
        }
    }
} catch (Throwable $e) { $shutdownTimeoutMap = []; }

try {
    $visibilityRows = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'srv_%_visible'")->fetchAll();
    $hiddenServers = [];
    foreach ($visibilityRows as $r) {
        if (preg_match('/^srv_(\d+)_visible$/', $r['key'], $m) && $r['value'] === '0') {
            $hiddenServers[(int)$m[1]] = true;
        }
    }
} catch (Throwable $e) { $hiddenServers = []; }

// SSH configurado + idle deployado por servidor (#51)
$sshConfiguredMap = [];
$idleDeployedMap  = [];
$sshKeyOkMap      = [];
$wakeLabKeyReady  = file_exists('/var/www/.ssh/id_ed25519.pub');
try {
    $sshDeployRows = $pdo->query(
        "SELECT `key`,`value` FROM settings WHERE `key` LIKE 'srv_%_ssh_user' OR `key` LIKE 'srv_%_idle_deployed' OR `key` LIKE 'srv_%_ssh_key_ok'"
    )->fetchAll();
    foreach ($sshDeployRows as $r) {
        if (preg_match('/^srv_(\d+)_ssh_user$/', $r['key'], $m)) {
            $sshConfiguredMap[(int)$m[1]] = trim((string)$r['value']) !== '';
        } elseif (preg_match('/^srv_(\d+)_idle_deployed$/', $r['key'], $m)) {
            $idleDeployedMap[(int)$m[1]] = $r['value'] === '1';
        } elseif (preg_match('/^srv_(\d+)_ssh_key_ok$/', $r['key'], $m)) {
            $sshKeyOkMap[(int)$m[1]] = $r['value'] === '1';
        }
    }
} catch (Throwable $e) {}

// Servidores visibles en dashboard y sidebar (excluye los ocultos)
$visibleServers = array_filter($servers, fn($s) => empty($hiddenServers[$s['id']]));

function getSettingFallback(PDO $pdo, string $key, string $default): string {
    try {
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
        $s->execute([$key]);
        $r = $s->fetch();
        return $r ? $r['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}
/**
 * Renderiza bloque SSH con toggle Clave/Contraseña (#5)
 * @param string $prefix   Prefijo de IDs: tn_ssh, omv_ssh, etc.
 * @param int    $id       Server ID
 * @param array  $ssh      ['user'=>, 'pass'=>, 'port'=>]
 * @param bool   $keyReady WakeLab key existe en /var/www/.ssh/
 * @param string $saveCall JS function a llamar al guardar
 * @param string $hint     Hint extra (opcional)
 */
function sshBlock(string $prefix, int $id, array $ssh, bool $keyReady, string $saveCall, string $hint = '', bool $keyAuthorized = false): string {
    $uid = "{$prefix}_{$id}";

    // Badge "authorized" — siempre renderizado, visible solo si $keyAuthorized
    $authorizedBadge =
        "<div id=\"ssh-block-ok-{$uid}\" " . (!$keyAuthorized ? "style=\"display:none\"" : "") . ">"
        . "<div style=\"display:flex;align-items:center;justify-content:space-between\">"
        .   "<span style=\"display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--green)\"><i class=\"bi bi-key-fill\"></i> SSH with WakeLab key</span>"
        .   "<button type=\"button\" class=\"btn btn-link btn-sm\" style=\"font-size:10px;color:var(--text-dim);padding:0\" "
        .     "onclick=\"sshBlockReset('{$uid}',{$id});\">reconfigure</button>"
        . "</div>"
        . "</div>";

    $hasPass   = !empty($ssh['pass']);
    $usePass   = $hasPass;
    $user      = htmlspecialchars($ssh['user'] ?? '');
    $port      = htmlspecialchars($ssh['port'] ?? '22');
    $passHint  = $hasPass ? '••••••••' : 'SSH password';

    $keyBadge = $keyReady
        ? '<span style="display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:2px 7px;border-radius:4px;background:var(--green-bg);border:1px solid var(--green-bdr);color:var(--green)"><i class="bi bi-key-fill"></i> WakeLab key ready</span>'
        : '<span style="display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:2px 7px;border-radius:4px;background:var(--amber-bg);border:1px solid var(--amber-bdr);color:var(--amber)"><i class="bi bi-key"></i> Key not generated</span>';

    $hintHtml  = $hint ? "<div class=\"cfg-hint\"><span class=\"cfg-hint-icon\">ℹ</span><span>{$hint}</span></div>" : '';
    $passDisplay = $usePass ? '' : 'display:none';
    $keyClass  = !$usePass ? ' active' : '';
    $passClass = $usePass  ? ' active' : '';

    return "<div class=\"cfg-section\" id=\"ssh-block-{$uid}\">"
        . $authorizedBadge
        . "<div id=\"ssh-block-form-{$uid}\"" . ($keyAuthorized ? " style=\"display:none\"" : "") . ">"
        . "<div class=\"cfg-section-title\">ssh <span style=\"font-size:9px;text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-dim)\">(deploy idle script)</span></div>"
        . "<div style=\"margin-bottom:8px\">{$keyBadge}</div>"
        . $hintHtml
        . "<div class=\"form-row\"><span class=\"form-label\">User</span><input id=\"{$uid}_user\" class=\"form-control\" value=\"{$user}\" placeholder=\"root\"></div>"
        . "<div class=\"form-row\"><span class=\"form-label\">Authentication</span>"
        .   "<div style=\"display:flex;gap:0;border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden\">"
        .     "<button type=\"button\" id=\"btn-key-{$uid}\" onclick=\"setSshAuth('{$uid}',false)\" class=\"ssh-auth-btn{$keyClass}\"><i class=\"bi bi-key-fill\"></i> SSH Key</button>"
        .     "<button type=\"button\" id=\"btn-pass-{$uid}\" onclick=\"setSshAuth('{$uid}',true)\" class=\"ssh-auth-btn{$passClass}\" style=\"border-left:1px solid var(--border)\"><i class=\"bi bi-lock-fill\"></i> Password</button>"
        .   "</div>"
        . "</div>"
        . "<div id=\"pass-row-{$uid}\" style=\"{$passDisplay}\">"
        .   "<div class=\"form-row\"><span class=\"form-label\">Password</span><input id=\"{$uid}_pass\" class=\"form-control\" type=\"password\" placeholder=\"{$passHint}\" autocomplete=\"new-password\"></div>"
        . "</div>"
        . "<div class=\"form-row\"><span class=\"form-label\">Port</span><input id=\"{$uid}_port\" class=\"form-control\" type=\"number\" min=\"1\" max=\"65535\" value=\"{$port}\" placeholder=\"22\" style=\"max-width:90px\"></div>"
        . "<button type=\"button\" class=\"btn btn-outline-success btn-sm mt-2 w-100\" onclick=\"{$saveCall}\">save SSH</button>"
        . "</div>"
        . "</div>";
}

$pollingIntervalSec  = intval(getSettingFallback($pdo, 'polling_interval_sec', '30'));
$sshDefaultUser      = getSettingFallback($pdo, 'ssh_default_user', 'root');
$sshDefaultPort      = getSettingFallback($pdo, 'ssh_default_port', '22');
$debugMode          = getSettingFallback($pdo, 'debug_mode', '0') === '1';
$serverTimezone     = getSettingFallback($pdo, 'timezone', '');
$displayTimezone    = getSettingFallback($pdo, 'timezone_display', $serverTimezone);
$globalTimezone     = $serverTimezone; // backward compat

function convertScheduleTime(string $hhmmss, string $fromTz, string $toTz): string {
    if (!$fromTz || !$toTz || $fromTz === $toTz) return $hhmmss;
    try {
        new DateTimeZone($fromTz); // valida antes de usarla
        new DateTimeZone($toTz);
        $dt = DateTime::createFromFormat('H:i:s', $hhmmss, new DateTimeZone($fromTz));
        if (!$dt) return $hhmmss;
        $dt->setTimezone(new DateTimeZone($toTz));
        return $dt->format('H:i:s');
    } catch (Throwable) { return $hhmmss; }
}

$HYP_LABELS = [
    'pve'     => 'Proxmox VE',
    'pbs'     => 'Proxmox BS',
    'truenas' => 'TrueNAS',
    'omv'     => 'OpenMediaVault',
    'generic' => 'Generic',
    'windows' => 'Windows PC',
    'linux'   => 'Linux PC',
];
$HYP_ICONS = [
    'pve'     => 'bi-hdd-stack',
    'pbs'     => 'bi-shield-check',
    'truenas' => 'bi-hdd-rack',
    'omv'     => 'bi-server',
    'generic' => 'bi-pc-display',
    'windows' => 'bi-windows',
    'linux'   => 'bi-terminal-fill',
];
$HYP_PORTS = ['pve'=>8006,'pbs'=>8007,'truenas'=>443,'omv'=>80,'windows'=>22,'linux'=>22];
$HYP_AUTH  = ['pve'=>'pve_token','pbs'=>'pbs_token','truenas'=>'truenas_apikey','omv'=>'omv_password'];
$DAYS_LABELS = ['mon'=>'M','tue'=>'T','wed'=>'W','thu'=>'T','fri'=>'F','sat'=>'S','sun'=>'S'];

function timePair(string $id, string $value, string $cls = 'form-select', string $mStyle = ''): string {
    $val   = substr($value, 0, 5);
    $parts = explode(':', $val);
    $h     = intval($parts[0] ?? 8);
    $rawM  = intval($parts[1] ?? 0);
    $m     = sprintf('%02d', (int)round($rawM / 5) * 5 % 60);
    $hOpts = '';
    for ($i = 0; $i < 24; $i++) {
        $hv = sprintf('%02d', $i);
        $sel = $i === $h ? ' selected' : '';
        $hOpts .= "<option value=\"$hv\"$sel>$hv hs</option>";
    }
    $mOpts = '';
    for ($i = 0; $i < 60; $i += 5) {
        $mv = sprintf('%02d', $i);
        $sel = $mv === $m ? ' selected' : '';
        $mOpts .= "<option value=\"$mv\"$sel>:$mv</option>";
    }
    $ms = $mStyle ? " style=\"$mStyle\"" : '';
    return "<div style=\"display:flex;gap:4px;align-items:center\">"
         . "<select id=\"{$id}_h\" class=\"$cls\">$hOpts</select>"
         . "<select id=\"{$id}_m\" class=\"$cls\"$ms>$mOpts</select>"
         . "</div>";
}

// NPM config auto-detection — usa la URL pública del browser
$_npmProto   = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$_npmHost    = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'WEBSERVER_HOST';
$_wlBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/');
$_wlPort     = getenv('WEB_PORT') ?: '8472';

// Wake Proxy secret token — auto-generado si no existe
(function() use ($pdo): void {
    $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='wake_proxy_secret' LIMIT 1");
    $s->execute();
    if (!$s->fetchColumn()) {
        $token = bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO settings (`key`,`value`,`description`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
            ->execute(['wake_proxy_secret', $token, 'Token secreto para autenticar el Wake Proxy']);
    }
})();
$_wpTokenRow = $pdo->query("SELECT `value` FROM settings WHERE `key`='wake_proxy_secret' LIMIT 1")->fetchColumn();
$npmWpToken  = $_wpTokenRow ?: '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <meta name="theme-color" content="#0d1117">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="WakeLab">
        <title>WakeLab</title>
        <link rel="icon" type="image/png" href="assets/icons/favicon-96x96.png" sizes="96x96">
        <link rel="shortcut icon" href="assets/icons/favicon.ico">
        <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/apple-touch-icon.png">
        <link rel="manifest" href="assets/icons/site.webmanifest">
        <link rel="stylesheet" href="assets/bootstrap/bootstrap.min.css">
        <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.min.css">
        <link rel="stylesheet" href="assets/vendor/dataTables.bootstrap5.min.css">
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body id="app-body"<?= $debugMode ? '' : ' class="debug-mode-off"' ?>>
        <div class="topbar">
            <div class="topbar-logo">
                <button class="hamburger-btn" id="hamburger-btn" onclick="toggleSidebar()" title="Menu" style="margin-right:8px">&#9776;</button>
                <img src="assets/icons/web-app-manifest-192x192.png" alt="WakeLab" class="logo-icon" style="object-fit:contain;border:none;background:none;">
                WakeLab
            </div>
            <div class="topbar-right">
                <div class="topbar-stats">
                    <span><span class="dot dot-green"></span><span id="count-online">—</span> online</span>
                    <span><span class="dot dot-gray"></span><span id="count-offline">—</span> offline</span>
                </div>
                <button id="live-indicator" class="sync-btn" onclick="manualSync()" title="sync now">
                    <svg id="sync-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                        <polyline points="21 3 21 9 15 9"/>
                    </svg>
                </button>
                <button class="theme-toggle" id="theme-toggle-btn" onclick="toggleTheme()" title="toggle theme"><i id="theme-icon" class="bi bi-sun-fill"></i></button>
                <span class="topbar-user"><i class="bi bi-person-fill"></i><?= htmlspecialchars($_SESSION['usuario']) ?></span>
                <a href="logout.php" class="topbar-logout" title="log out"><i class="bi bi-box-arrow-right"></i></a>
            </div>
        </div>

        <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
        <div class="app-layout">

        <!-- ══ SIDEBAR ══════════════════════════════════════════════════ -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-nav">

                <button class="sidebar-item active" id="sb-dashboard"
                        type="button" onclick="sidebarNav(this,'htab-dashboard')">
                    <i class="bi bi-grid-fill"></i><span class="sb-text"> Dashboard</span>
                </button>

                <button class="sidebar-item" id="sb-wake-proxy"
                        type="button" onclick="sidebarNav(this,'htab-wake-proxy')" title="Wake Proxy">
                    <i class="bi bi-lightning-charge-fill"></i><span class="sb-text"> Wake Proxy</span>
                    <span id="sb-wp-alert" class="tab-alert-badge" style="display:none;margin-left:auto"></span>
                </button>

                <div class="sidebar-divider"></div>
                <div class="sidebar-group-label">Hosts</div>

                <?php foreach ($servers as $srv): ?>
                <button class="sidebar-item sidebar-srv-item" id="sb-srv-<?= $srv['id'] ?>"
                        data-srv-id="<?= $srv['id'] ?>"
                        <?= !empty($hiddenServers[$srv['id']]) ? 'style="display:none"' : '' ?>
                        type="button" onclick="sidebarNav(this,'htab-srv-<?= $srv['id'] ?>')"
                        title="<?= htmlspecialchars($srv['hostname']) ?>">
                    <span class="dot dot-gray sidebar-dot" id="sdot-<?= $srv['id'] ?>"></span>
                    <span class="sb-text"><?= htmlspecialchars($srv['hostname']) ?></span>
                    <span id="sb-alert-<?= $srv['id'] ?>" class="tab-alert-badge" style="display:none;margin-left:auto"></span>
                </button>
                <?php endforeach; ?>

                <div class="sidebar-divider"></div>

                <button class="sidebar-item" id="sb-logs"
                        type="button" onclick="sidebarNav(this,'htab-logs')" title="Logs">
                    <i class="bi bi-journal-text"></i><span class="sb-text"> Logs</span>
                </button>

                <button class="sidebar-item" id="sb-hosts-db"
                        type="button" onclick="sidebarNav(this,'htab-hosts-db')" title="Hosts DB">
                    <i class="bi bi-server"></i><span class="sb-text"> Hosts DB</span>
                </button>

            </div>
            <div class="sidebar-footer">
                <button class="sidebar-item" id="sb-guide"
                        type="button" onclick="openSetupGuide()" title="Setup Guide">
                    <i class="bi bi-book-half"></i><span class="sb-text"> Guide</span>
                </button>
                <button class="sidebar-item" id="sb-push"
                        type="button" onclick="sidebarNav(this,'htab-push')" title="Settings">
                    <i class="bi bi-gear-fill"></i><span class="sb-text"> Settings</span>
                </button>
                <div class="sidebar-collapse-btn">
                    <button onclick="toggleSidebarCollapse()" id="sidebar-collapse-icon" title="Collapse sidebar">&#8249;&#8249;</button>
                </div>
            </div>
        </nav>

        <!-- ══ CONTENT AREA ═════════════════════════════════════════════ -->
        <div class="content-area">
        <div class="content-main">

        <!-- Nav oculto: Bootstrap Tab lo necesita para gestionar estado (.nav parent) -->
        <ul class="nav" id="hiddenTabs" style="display:none" aria-hidden="true">
            <li class="nav-item"><button class="nav-link active" id="htab-dashboard" data-bs-toggle="tab" data-bs-target="#tab-dashboard" type="button"></button></li>
            <?php foreach ($servers as $srv): ?>
            <li class="nav-item"><button class="nav-link" id="htab-srv-<?= $srv['id'] ?>" data-bs-toggle="tab" data-bs-target="#tab-srv-<?= $srv['id'] ?>" type="button"></button></li>
            <?php endforeach; ?>
            <li class="nav-item"><button class="nav-link" id="htab-hosts-db"   data-bs-toggle="tab" data-bs-target="#tab-hosts-db"   type="button"></button></li>
            <li class="nav-item"><button class="nav-link" id="htab-wake-proxy" data-bs-toggle="tab" data-bs-target="#tab-wake-proxy" type="button"></button></li>
            <li class="nav-item"><button class="nav-link" id="htab-logs"       data-bs-toggle="tab" data-bs-target="#tab-logs"       type="button"></button></li>
            <li class="nav-item"><button class="nav-link" id="htab-push" data-bs-toggle="tab" data-bs-target="#tab-push" type="button"></button></li>
        </ul>

        <div class="tab-content">

        <!-- ══ DASHBOARD ══════════════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="tab-dashboard" role="tabpanel">
            <div class="d-flex align-items-center justify-content-between mt-3 mb-2 gap-2">
                <div class="sec-label mb-0">host status</div>
                <div class="d-flex align-items-center gap-2">
                    <div class="dash-search-wrap">
                        <i class="bi bi-search dash-search-icon"></i>
                        <input type="search" id="dash-search" class="dash-search-input" placeholder="filter hosts…" oninput="filterDashboard(this.value)">
                    </div>
                    <button class="btn btn-outline-success btn-sm" onclick="openAddModal()">+ add</button>
                </div>
            </div>
            <?php if (empty($servers) || isset($_GET['onboarding'])): ?>
            <!-- ══ ONBOARDING WIZARD ════════════════════════════════════════ -->
            <div id="onboarding-overlay" class="ob-overlay">

                <!-- Progress bar -->
                <div class="ob-progress-bar">
                    <div class="ob-progress-fill" id="ob-progress-fill" style="width:25%"></div>
                </div>

                <!-- ── Step 1: Welcome ─────────────────────────────────────── -->
                <div class="ob-step active" id="ob-step-1">
                    <div class="ob-step-inner ob-step-welcome">
                        <div class="ob-logo-wrap">
                            <img src="assets/icons/web-app-manifest-192x192.png" alt="WakeLab" width="72" height="72">
                        </div>
                        <h1 class="ob-title">Welcome to WakeLab</h1>
                        <p class="ob-desc">Your homelab control panel. Monitor servers, wake them remotely, automate schedules, and shut them down when they're idle.</p>
                        <div class="ob-feature-grid">
                            <div class="ob-feat"><i class="bi bi-activity"></i><span>Live monitoring</span></div>
                            <div class="ob-feat"><i class="bi bi-lightning-charge-fill"></i><span>Wake on LAN</span></div>
                            <div class="ob-feat"><i class="bi bi-clock-history"></i><span>Boot & shutdown schedules</span></div>
                            <div class="ob-feat"><i class="bi bi-moon-stars-fill"></i><span>Idle auto-shutdown</span></div>
                            <div class="ob-feat"><i class="bi bi-bell-fill"></i><span>Telegram alerts</span></div>
                            <div class="ob-feat"><i class="bi bi-cpu"></i><span>Proxmox / TrueNAS / PBS</span></div>
                        </div>
                        <button class="ob-btn-primary" onclick="obGoTo(2)">
                            Get started <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>

                <!-- ── Step 2: Add server ──────────────────────────────────── -->
                <div class="ob-step" id="ob-step-2">
                    <div class="ob-step-inner">
                        <div class="ob-step-header">
                            <span class="ob-step-num">1 of 3</span>
                            <h2 class="ob-step-title">Add your first server</h2>
                            <p class="ob-step-sub">Fill in the basics. You can edit everything later.</p>
                        </div>

                        <!-- Type picker -->
                        <div class="ob-field-group">
                            <label class="ob-label">What type of host?</label>
                            <div class="ob-type-grid" id="ob-type-grid">
                                <?php foreach ($HYP_LABELS as $hk => $hv): ?>
                                <button type="button" class="ob-type-btn" data-val="<?= $hk ?>" onclick="obSelectType('<?= $hk ?>')">
                                    <img src="assets/icons/<?= $hk ?>.svg" width="28" height="28">
                                    <span><?= $hv ?></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="ob-form-cols">
                            <div class="ob-field-group">
                                <label class="ob-label" for="ob-hn">Hostname</label>
                                <input type="text" id="ob-hn" class="ob-input" placeholder="pve01 / truenas / myserver">
                            </div>
                            <div class="ob-field-group">
                                <label class="ob-label" for="ob-ip">IP address</label>
                                <input type="text" id="ob-ip" class="ob-input font-mono" placeholder="192.168.1.x">
                            </div>
                        </div>

                        <div class="ob-field-group" id="ob-mac-group">
                            <label class="ob-label" for="ob-mac">MAC address <span class="ob-label-hint">— needed for Wake-on-LAN</span></label>
                            <input type="text" id="ob-mac" class="ob-input font-mono" placeholder="AA:BB:CC:DD:EE:FF">
                            <div class="ob-hint"><i class="bi bi-info-circle me-1"></i>Find it with <code>ip link show</code> (Linux) or <code>Get-NetAdapter</code> (Windows). Leave blank to skip WoL for now.</div>
                        </div>

                        <div class="ob-actions-row">
                            <button class="ob-btn-ghost" onclick="obGoTo(1)"><i class="bi bi-arrow-left me-1"></i>Back</button>
                            <button class="ob-btn-primary" onclick="obGoTo(3)">Continue <i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                </div>

                <!-- ── Step 3: Access config ───────────────────────────────── -->
                <div class="ob-step" id="ob-step-3">
                    <div class="ob-step-inner">
                        <div class="ob-step-header">
                            <span class="ob-step-num">2 of 3</span>
                            <h2 class="ob-step-title">Configure access</h2>
                            <p class="ob-step-sub">WakeLab needs to communicate with your server to fetch metrics and execute shutdown commands.</p>
                        </div>

                        <!-- PVE/PBS/TrueNAS: API token -->
                        <div id="ob-access-api" style="display:none">
                            <div class="ob-access-card">
                                <div class="ob-access-card-header">
                                    <i class="bi bi-key-fill" style="color:var(--amber)"></i>
                                    <strong>API token</strong>
                                    <span class="ob-badge ob-badge-required">Required for metrics</span>
                                </div>
                                <p class="ob-access-desc" id="ob-api-desc">WakeLab uses the hypervisor's REST API to read CPU, RAM, disk usage and manage VMs. Without it, you'll only get ping status.</p>
                                <div id="ob-pve-api-steps" style="display:none">
                                    <div class="ob-mini-steps">
                                        <div class="ob-mini-step"><span>1</span><div>In Proxmox web UI → <b>Datacenter → API Tokens → Add</b></div></div>
                                        <div class="ob-mini-step"><span>2</span><div>User: <code>root@pam</code> · Token ID: anything (e.g. <code>panel</code>) · <b>uncheck Privilege Separation</b></div></div>
                                        <div class="ob-mini-step"><span>3</span><div>Copy the token secret shown — it's displayed <b>only once</b></div></div>
                                    </div>
                                </div>
                                <div id="ob-pbs-api-steps" style="display:none">
                                    <div class="ob-mini-steps">
                                        <div class="ob-mini-step"><span>1</span><div>In PBS web UI → <b>Configuration → API Tokens → Add</b></div></div>
                                        <div class="ob-mini-step"><span>2</span><div>User: <code>root@pam</code> · Token ID: anything (e.g. <code>panel</code>)</div></div>
                                        <div class="ob-mini-step"><span>3</span><div>Copy the token secret — shown <b>only once</b></div></div>
                                    </div>
                                </div>
                                <div id="ob-tn-api-steps" style="display:none">
                                    <div class="ob-mini-steps">
                                        <div class="ob-mini-step"><span>1</span><div>In TrueNAS → <b>Credentials → API Keys → Add</b></div></div>
                                        <div class="ob-mini-step"><span>2</span><div>Give it a name (e.g. <code>wakelab</code>), copy the key — shown <b>only once</b></div></div>
                                        <div class="ob-mini-step"><span>3</span><div>No user/token ID needed — paste the API key directly below</div></div>
                                    </div>
                                </div>
                                <div class="ob-token-fields" id="ob-token-fields">
                                    <div id="ob-user-row">
                                        <label class="ob-label" for="ob-api-user">API User</label>
                                        <input type="text" id="ob-api-user" class="ob-input" value="root@pam">
                                    </div>
                                    <div id="ob-tid-row">
                                        <label class="ob-label" for="ob-api-tid">Token ID</label>
                                        <input type="text" id="ob-api-tid" class="ob-input" value="panel">
                                    </div>
                                    <div>
                                        <label class="ob-label" for="ob-api-secret" id="ob-secret-label">Token Secret</label>
                                        <input type="password" id="ob-api-secret" class="ob-input" placeholder="paste token secret here">
                                    </div>
                                </div>
                                <button class="ob-btn-skip" onclick="obSkipApi()"><i class="bi bi-skip-forward me-1"></i>Skip for now — I'll add it later in settings</button>
                            </div>
                        </div>

                        <!-- Linux/Windows/Generic: SSH -->
                        <div id="ob-access-ssh" style="display:none">
                            <div class="ob-access-card">
                                <div class="ob-access-card-header">
                                    <i class="bi bi-terminal-fill" style="color:var(--blue)"></i>
                                    <strong>SSH access</strong>
                                    <span class="ob-badge ob-badge-required">Required for shutdown &amp; idle</span>
                                </div>
                                <div class="ob-how-ssh">
                                    <div class="ob-how-ssh-title">How it works — two options:</div>
                                    <div class="ob-ssh-options">
                                        <div class="ob-ssh-opt ob-ssh-opt-active" id="ob-ssh-opt-auto" onclick="obSshMode('auto')">
                                            <div class="ob-ssh-opt-header"><i class="bi bi-magic"></i> <strong>Auto (recommended)</strong></div>
                                            <p>Enter your SSH password <b>one time only</b>. WakeLab will copy its public key to the server automatically and never store the password.</p>
                                        </div>
                                        <div class="ob-ssh-opt" id="ob-ssh-opt-manual" onclick="obSshMode('manual')">
                                            <div class="ob-ssh-opt-header"><i class="bi bi-clipboard"></i> <strong>Manual</strong></div>
                                            <p>Copy WakeLab's public key and paste it into <code>~/.ssh/authorized_keys</code> yourself. No password needed here.</p>
                                        </div>
                                    </div>

                                    <div id="ob-ssh-auto-form">
                                        <div class="ob-form-cols">
                                            <div class="ob-field-group">
                                                <label class="ob-label" for="ob-ssh-user">SSH user</label>
                                                <input type="text" id="ob-ssh-user" class="ob-input" placeholder="root / administrator" value="root">
                                            </div>
                                            <div class="ob-field-group">
                                                <label class="ob-label" for="ob-ssh-pass">SSH password <span class="ob-label-hint">used once, not stored</span></label>
                                                <input type="password" id="ob-ssh-pass" class="ob-input" placeholder="password" autocomplete="new-password">
                                            </div>
                                        </div>
                                        <div class="ob-hint"><i class="bi bi-shield-check me-1" style="color:var(--green)"></i>WakeLab will run <code>ssh-copy-id</code> to add its key, then discard the password. After that it connects with the key only.</div>
                                    </div>

                                    <div id="ob-ssh-manual-form" style="display:none">
                                        <p class="ob-hint mb-2">Copy WakeLab's public key and add it to your server:</p>
                                        <div class="ob-pubkey-box">
                                            <code id="ob-pubkey-text" style="font-size:11px;word-break:break-all;color:var(--text)">loading…</code>
                                            <button class="ob-copy-btn" onclick="obCopyPubKey(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                        </div>
                                        <div class="ob-mini-steps mt-2">
                                            <div class="ob-mini-step"><span>1</span><div>Copy the key above</div></div>
                                            <div class="ob-mini-step"><span>2</span><div>On the server, run: <code>mkdir -p ~/.ssh && echo "PASTE_KEY" >> ~/.ssh/authorized_keys</code></div></div>
                                            <div class="ob-mini-step"><span>3</span><div>Come back here and click Continue</div></div>
                                        </div>
                                    </div>
                                </div>
                                <button class="ob-btn-skip" onclick="obSkipSsh()"><i class="bi bi-skip-forward me-1"></i>Skip — I'll configure SSH later</button>
                            </div>
                        </div>

                        <!-- No access needed (generic ping-only) -->
                        <div id="ob-access-none" style="display:none">
                            <div class="ob-access-card ob-access-card-muted">
                                <i class="bi bi-wifi" style="font-size:1.8rem;color:var(--text-dim)"></i>
                                <p style="margin:8px 0 0">This host type uses <b>ping only</b> — no API or SSH needed. WakeLab will monitor its online/offline status and send WoL packets.</p>
                            </div>
                        </div>

                        <div class="ob-actions-row">
                            <button class="ob-btn-ghost" onclick="obGoTo(2)"><i class="bi bi-arrow-left me-1"></i>Back</button>
                            <button class="ob-btn-primary" onclick="obSave()">
                                <i class="bi bi-check2 me-1"></i>Save &amp; continue
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Step 4: Done ────────────────────────────────────────── -->
                <div class="ob-step" id="ob-step-4">
                    <div class="ob-step-inner ob-step-done">
                        <div class="ob-done-icon"><i class="bi bi-check-circle-fill"></i></div>
                        <h2 class="ob-title" id="ob-done-title">Server added!</h2>
                        <p class="ob-desc" id="ob-done-desc">WakeLab is now monitoring your server. Head to the dashboard to see it live.</p>
                        <div class="ob-next-steps">
                            <div class="ob-next-step"><i class="bi bi-gear me-2" style="color:var(--text-dim)"></i>Configure schedules and idle shutdown from the server card</div>
                            <div class="ob-next-step"><i class="bi bi-bell me-2" style="color:var(--text-dim)"></i>Set up Telegram notifications in Settings → Notifications</div>
                            <div class="ob-next-step"><i class="bi bi-plus-circle me-2" style="color:var(--text-dim)"></i>Add more servers anytime from the + add button in the navbar</div>
                        </div>
                        <button class="ob-btn-primary" onclick="location.href='/'">
                            <i class="bi bi-speedometer2 me-2"></i>Go to dashboard
                        </button>
                    </div>
                </div>

            </div><!-- /#onboarding-overlay -->
            <?php else: ?>
            <div class="row g-3">
            <?php foreach ($servers as $srv):
                $cSch = $schedules[$srv['id']]    ?? null;
                $cIdl = $idle_configs[$srv['id']] ?? null;
                $schOn  = $cSch && intval($cSch['active']);
                $shutOn = $cSch && intval($cSch['shutdown_active'] ?? 0);
                $idlOn  = $cIdl && intval($cIdl['active']);
                // Horarios en display TZ — siempre calculados para mostrar aunque estén inactivos
                $schBt = $cSch && !empty($cSch['boot_time'])
                    ? substr(convertScheduleTime($cSch['boot_time'], $globalTimezone, $displayTimezone), 0, 5) : null;
                $schSt = $cSch && !empty($cSch['shutdown_time'])
                    ? substr(convertScheduleTime($cSch['shutdown_time'], $globalTimezone, $displayTimezone), 0, 5) : null;
                $depId   = intval($srv['depends_on_server_id'] ?? 0);
                $depName = $depId ? ($serverNames[$depId] ?? null) : null;
                // Asegurar URL absoluta
                $srvUrl = trim($srv['url'] ?? '');
                if ($srvUrl && !preg_match('/^https?:\/\//i', $srvUrl)) {
                    $proto  = ($srv['port'] == 80 || $srv['port'] == 8080) ? 'http' : 'https';
                    $srvUrl = "{$proto}://{$srvUrl}";
                }
            ?>
            <div class="col-sm-6 col-lg-4 col-xl-3 srv-card-col" id="card-col-<?= $srv['id'] ?>" data-ip="<?= htmlspecialchars($srv['ip'] ?? '') ?>" <?= !empty($hiddenServers[$srv['id']]) ? 'style="display:none"' : '' ?>>
            <div class="srv-card" id="card-<?= $srv['id'] ?>" onclick="showSrvTab(<?= $srv['id'] ?>)">

                <!-- Fila 1: [dot hostname ↗]  [role] -->
                <div class="srv-card-row" style="margin-bottom:4px">
                    <div class="srv-name">
                        <span class="dot dot-gray" id="dot-<?= $srv['id'] ?>"></span>
                        <?= htmlspecialchars($srv['hostname']) ?>
                        <?php if ($srvUrl): ?>
                        <a href="<?= htmlspecialchars($srvUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"
                           class="srv-ext-link" onclick="event.stopPropagation()" title="Open interface">
                            <i class="bi bi-arrow-up-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    <span class="srv-role"><?= htmlspecialchars($srv['role']) ?></span>
                </div>

                <!-- Fila 2: [ip ping]  [dep badge] -->
                <div class="srv-card-row" style="margin-bottom:6px">
                    <div style="display:flex;align-items:center;gap:6px">
                        <span class="srv-ip-text"><?= htmlspecialchars($srv['ip']) ?></span>
                        <span id="card-ping-<?= $srv['id'] ?>" class="ping-none">—</span>
                    </div>
                    <?php if ($depName): ?>
                    <span class="srv-dep-badge" title="Depends on: <?= htmlspecialchars($depName) ?>">↑ <?= htmlspecialchars($depName) ?></span>
                    <?php endif; ?>
                </div>

                <div class="srv-metrics" id="card-metrics-<?= $srv['id'] ?>">
                    <div class="skeleton-metric"><div class="skeleton-line h6" style="width:40%;margin-bottom:6px"></div><div class="skeleton-line h14" style="width:70%"></div></div>
                    <div class="skeleton-metric"><div class="skeleton-line h6" style="width:40%;margin-bottom:6px"></div><div class="skeleton-line h14" style="width:50%"></div></div>
                    <div class="skeleton-metric"><div class="skeleton-line h6" style="width:30%;margin-bottom:6px"></div><div class="skeleton-line h14" style="width:60%"></div></div>
                </div>
                <div class="srv-btns" id="card-btns-<?= $srv['id'] ?>">
                    <div class="skeleton-line" style="height:28px;width:100%;border-radius:6px"></div>
                </div>

                <!-- Quick config -->
                <?php
                    $srvIdCard   = $srv['id'];
                    $srvTypeCard = $srv['hypervisor_type'] ?? 'generic';
                    $needsSsh    = !in_array($srvTypeCard, ['pve','generic']);
                    $sshOk       = !$needsSsh || !empty($sshConfiguredMap[$srvIdCard]) || $wakeLabKeyReady;
                    $deployOk    = !empty($idleDeployedMap[$srvIdCard]);
                    $idleBlocked = !$sshOk || !$deployOk;
                    $blockReason = $idleBlocked ? (!$sshOk ? 'Configure SSH first' : 'Deploy the script first') : '';
                ?>
                <div class="card-quick-cfg" onclick="event.stopPropagation()">
                    <div class="card-quick-row">
                        <!-- Encendido -->
                        <div class="quick-col" title="Scheduled boot<?= $schBt ? ': '.$schBt : '' ?>">
                            <label class="card-quick-item">
                                <span class="quick-label" style="color:var(--green)">↑</span>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input quick-toggle" type="checkbox"
                                           id="qsch-<?= $srvIdCard ?>"
                                           <?= $schOn ? 'checked' : '' ?>
                                           <?= !$cSch ? 'disabled' : '' ?>
                                           onchange="quickToggle(<?= $srvIdCard ?>,'schedule',this.checked)">
                                </div>
                            </label>
                            <span class="quick-time" style="<?= $schOn ? 'color:var(--green)' : 'opacity:.35' ?>"><?= $schBt ? htmlspecialchars($schBt) : '--:--' ?></span>
                        </div>
                        <!-- Apagado -->
                        <div class="quick-col" title="Scheduled shutdown<?= $schSt ? ': '.$schSt : '' ?>">
                            <label class="card-quick-item">
                                <span class="quick-label" style="color:var(--red)">↓</span>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input quick-toggle" type="checkbox"
                                           id="qshut-<?= $srvIdCard ?>"
                                           <?= $shutOn ? 'checked' : '' ?>
                                           <?= !$cSch ? 'disabled' : '' ?>
                                           onchange="quickToggle(<?= $srvIdCard ?>,'shutdown',this.checked)">
                                </div>
                            </label>
                            <span class="quick-time" style="<?= $shutOn ? 'color:var(--red)' : 'opacity:.35' ?>"><?= $schSt ? htmlspecialchars($schSt) : '--:--' ?></span>
                        </div>
                        <!-- Separador -->
                        <div class="quick-divider"></div>
                        <!-- Idle -->
                        <div class="quick-col push-right" title="<?= $idleBlocked ? htmlspecialchars($blockReason) : 'Idle auto-shutdown' ?>">
                            <label class="card-quick-item">
                                <span class="quick-label" style="<?= $idleBlocked ? 'color:var(--text-dim)' : '' ?>">IDLE</span>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input quick-toggle" type="checkbox"
                                           id="qidl-<?= $srvIdCard ?>"
                                           <?= $idlOn ? 'checked' : '' ?>
                                           <?= (!$cIdl || $idleBlocked) ? 'disabled' : '' ?>
                                           onchange="quickToggle(<?= $srvIdCard ?>,'idle',this.checked)">
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

            </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ TAB POR SERVIDOR ═══════════════════════════════════════ -->
        <?php foreach ($servers as $srv):
            $id   = $srv['id'];
            $type = $srv['hypervisor_type'] ?? 'pve';
            $sch  = $schedules[$id]    ?? null;
            $idl  = $idle_configs[$id] ?? null;
            $tok  = $api_tokens[$id]   ?? null;
            $det  = json_decode($idl['detectors_json']        ?? '{}', true) ?? [];
            $prms = json_decode($idl['detector_params_json']  ?? '{}', true) ?? [];
            $days = json_decode($sch['days_json']             ?? '["mon","tue","wed","thu","fri","sat","sun"]', true) ?? [];
            $idleLimitMin = intval(($idl['idle_limit_sec'] ?? 1800)/60);
            $cpuThresh    = $det['cpu_threshold'] ?? 20;
            $hypLabel     = $HYP_LABELS[$type] ?? $type;
            $isVm         = !empty($srv['proxmox_vmid']);
            $srvUrl       = trim($srv['url'] ?? '');
            if ($srvUrl && !preg_match('/^https?:\/\//i', $srvUrl)) {
                $proto  = ($srv['port'] == 80 || $srv['port'] == 8080) ? 'http' : 'https';
                $srvUrl = "{$proto}://{$srvUrl}";
            }
            // #51: prereqs idle
            // #51: prereqs idle
            $srvNeedsSsh    = !in_array($type, ['pve','generic']);
            $srvSshOk       = !$srvNeedsSsh || !empty($sshConfiguredMap[$id]) || $wakeLabKeyReady;
            $srvDeployOk    = !empty($idleDeployedMap[$id]);
            $srvIdleBlocked = !$srvSshOk || !$srvDeployOk;
        ?>
        <div class="tab-pane fade" id="tab-srv-<?= $id ?>" role="tabpanel">

            <!-- HERO BANNER: header + métricas + acciones -->
            <div class="srv-hero mt-3 mb-2" id="ctrl-panel-<?= $id ?>">

                <!-- Fila superior: identidad + botones -->
                <div class="srv-hero-top">
                    <div class="d-flex align-items-center gap-3">
                        <div class="srv-tab-hdr-icon">
                            <img src="assets/icons/<?= htmlspecialchars($type) ?>.svg" width="36" height="36" alt="<?= htmlspecialchars($type) ?>" style="filter:drop-shadow(0 1px 3px rgba(0,0,0,.4))">
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="srv-tab-hdr-title"><?= htmlspecialchars($srv['hostname']) ?></div>
                                <?php if ($srvUrl): ?>
                                <a href="<?= htmlspecialchars($srvUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"
                                   class="srv-ext-link" title="Open interface" onclick="event.stopPropagation()">
                                    <i class="bi bi-arrow-up-right"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="srv-tab-hdr-sub"><?= htmlspecialchars($hypLabel) ?><?= $srv['ip'] ? ' · <span class="srv-tab-hdr-ip">'.htmlspecialchars($srv['ip']).'</span>' : '' ?> · <span id="ping-<?= $id ?>" class="ping-none">—</span></div>
                        </div>
                    </div>
                    <div class="srv-hero-btns" id="srv-btns-<?= $id ?>">
                        <button class="btn btn-outline-success btn-sm" id="wol-btn-<?= $id ?>"
                                onclick="confirmAction(<?= $id ?>,'<?= htmlspecialchars($srv['hostname'],ENT_QUOTES) ?>','wol')">
                            <i class="bi bi-lightning-charge-fill me-1"></i>wake up
                        </button>
                        <button class="btn btn-outline-primary btn-sm" id="reboot-btn-<?= $id ?>"
                                onclick="confirmAction(<?= $id ?>,'<?= htmlspecialchars($srv['hostname'],ENT_QUOTES) ?>','reboot')">
                            <i class="bi bi-arrow-clockwise me-1"></i>reboot
                        </button>
                        <button class="btn btn-outline-danger btn-sm"
                                onclick="confirmAction(<?= $id ?>,'<?= htmlspecialchars($srv['hostname'],ENT_QUOTES) ?>','shutdown')">
                            <i class="bi bi-power me-1"></i>shutdown
                        </button>
                    </div>
                </div>

                <!-- Stats compactos + toggle gráficas -->
                <div class="srv-hero-stats">
                    <div class="srv-hero-stats-left">
                        <span class="srv-stat-grp">
                            <span class="srv-stat-label">Status</span>
                            <span class="srv-stat-val" id="status-<?= $id ?>">—</span>
                        </span>
                        <span class="srv-stat-div srv-metric-item"></span>
                        <span class="srv-stat-grp srv-metric-item">
                            <span class="srv-stat-label">CPU</span>
                            <span class="srv-stat-val blue" id="cpu-<?= $id ?>">—</span>
                        </span>
                        <span class="srv-stat-div srv-metric-item srv-stat-div-mid"></span>
                        <span class="srv-stat-grp srv-metric-item">
                            <span class="srv-stat-label">RAM</span>
                            <span class="srv-stat-val blue" id="ram-<?= $id ?>">—</span>
                            <?php if (in_array($type, ['pve', 'windows', 'linux'])): ?>
                            <span class="srv-stat-div"></span>
                            <span class="srv-stat-label">Disk</span>
                            <span class="srv-stat-val blue" id="disk-<?= $id ?>">—</span>
                            <?php endif; ?>
                        </span>
                        <span class="srv-stat-div srv-metric-item"></span>
                        <span class="srv-stat-grp srv-metric-item">
                            <span class="srv-stat-label">Uptime</span>
                            <span class="srv-stat-val" id="uptime-<?= $id ?>">—</span>
                        </span>
                    </div>
                </div>


            </div>

            <div class="accordion" id="acc-srv-<?= $id ?>">

            <!-- VMs / GUESTS -->
            <?php if ($srv['api_enabled'] && $type === 'pve'): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-vms-<?= $id ?>">
                        <span class="acc-dot" style="background:#58a6ff"></span>
                        VMs &amp; LXC
                        <span id="vm-summary-<?= $id ?>" style="font-size:10px;font-weight:400;color:var(--text-dim);margin-left:6px"></span>
                    </button>
                </h2>
                <div id="acc-vms-<?= $id ?>" class="accordion-collapse collapse show">
                    <div class="accordion-body">
                        <div class="vm-filter-wrap">
                            <input type="text" id="vm-filter-<?= $id ?>" class="vm-filter"
                                placeholder="filter by name or ID…"
                                oninput="filterVMs(<?= $id ?>, this.value)">
                        </div>
                        <div id="vm-list-<?= $id ?>" class="vm-grid">
                            <?php for($i=0;$i<6;$i++): ?>
                            <div class="skeleton-vm">
                                <div class="skeleton-line h14" style="width:65%"></div>
                                <div class="skeleton-line h6"  style="width:40%"></div>
                                <div class="skeleton-line h6"  style="width:100%"></div>
                                <div class="skeleton-line h6"  style="width:100%"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif ($srv['api_enabled'] && $type === 'pbs'): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-vms-<?= $id ?>">
                        <span class="acc-dot" style="background:#58a6ff"></span>datastores &amp; tasks
                        <span id="vm-summary-<?= $id ?>" style="font-size:10px;font-weight:400;color:var(--text-dim);margin-left:6px"></span>
                    </button>
                </h2>
                <div id="acc-vms-<?= $id ?>" class="accordion-collapse collapse show">
                    <div class="accordion-body">
                        <div id="vm-list-<?= $id ?>" class="vm-grid">
                            <?php for($i=0;$i<6;$i++): ?>
                            <div class="skeleton-vm">
                                <div class="skeleton-line h14" style="width:65%"></div>
                                <div class="skeleton-line h6"  style="width:40%"></div>
                                <div class="skeleton-line h6"  style="width:100%"></div>
                                <div class="skeleton-line h6"  style="width:100%"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif ($srv['api_enabled'] && $type === 'truenas'): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-vms-<?= $id ?>">
                        <span class="acc-dot" style="background:#58a6ff"></span>
                        Resources
                        <span id="vm-summary-<?= $id ?>" style="font-size:10px;font-weight:400;color:var(--text-dim);margin-left:6px"></span>
                    </button>
                </h2>
                <div id="acc-vms-<?= $id ?>" class="accordion-collapse collapse show">
                    <div class="accordion-body">
                        <div id="vm-list-<?= $id ?>" class="vm-grid">
                            <?php for($i=0;$i<6;$i++): ?>
                            <div class="skeleton-vm">
                                <div class="skeleton-line h14" style="width:65%"></div>
                                <div class="skeleton-line h6"  style="width:40%"></div>
                                <div class="skeleton-line h6"  style="width:100%"></div>
                                <div class="skeleton-line h6"  style="width:100%"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif ($srv['api_enabled'] && $type === 'omv'): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-omv-<?= $id ?>">
                        <span class="acc-dot" style="background:#ee7f00"></span>
                        Filesystems &amp; disks
                        <span id="omv-summary-<?= $id ?>" style="font-size:10px;font-weight:400;color:var(--text-dim);margin-left:6px"></span>
                    </button>
                </h2>
                <div id="acc-omv-<?= $id ?>" class="accordion-collapse collapse show">
                    <div class="accordion-body">
                        <div id="omv-extra-<?= $id ?>">
                            <div style="display:flex;flex-wrap:wrap;gap:10px">
                                <?php for($i=0;$i<4;$i++): ?>
                                <div class="skeleton-vm" style="flex:1;min-width:120px">
                                    <div class="skeleton-line h6"  style="width:50%;margin-bottom:6px"></div>
                                    <div class="skeleton-line h14" style="width:80%"></div>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            </div><!-- /.accordion (vms) -->

            <div class="row g-2">
            <div class="col-md-6"><div class="accordion">

            <!-- SCHEDULE -->
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= (!$sch||!$sch['active'])?'collapsed':'' ?>" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-sch-<?= $id ?>">
                        <span class="acc-dot" style="background:#d29922"></span>schedule boot / shutdown
                        <?php if ($isVm): ?><span style="font-size:10px;color:var(--blue);margin-left:6px;font-weight:400">· VM #<?= intval($srv['proxmox_vmid']) ?> via API</span><?php endif; ?>
                    </button>
                </h2>
                <div id="acc-sch-<?= $id ?>" class="accordion-collapse collapse <?= ($sch&&$sch['active'])?'show':'' ?>">
                    <div class="accordion-body" style="padding:0">
                        <div data-autosave-sch="<?= $id ?>">
                            <?php
                                $bootDisp = convertScheduleTime($sch['boot_time']??'08:00:00', $serverTimezone, $displayTimezone);
                                $shutRaw  = $sch['shutdown_time'] ?? '22:00:00';
                                $shutDisp = convertScheduleTime($shutRaw ?: '22:00:00', $serverTimezone, $displayTimezone);
                                $hasShutdown = !empty($sch['shutdown_active']) && intval($sch['shutdown_active']) === 1;
                                $savedMethod = $sch['method'] ?? '';
                                $defaultMethod = $isVm ? 'Proxmox API' : 'Wake on LAN';
                                $effectiveMethod = $savedMethod ?: $defaultMethod;
                                $isActive = $sch && $sch['active'];
                            ?>

                            <!-- Timeline visual: encendido → apagado -->
                            <div class="sch-timeline" style="padding:14px 16px 12px;display:flex;align-items:center;gap:0">

                                <!-- Bloque encendido -->
                                <div style="flex:1;background:color-mix(in srgb,var(--green) 8%,transparent);border:1px solid color-mix(in srgb,var(--green) <?= $isActive ? '20%' : '10%' ?>,transparent);border-radius:10px;padding:12px 14px;opacity:<?= $isActive ? '1' : '.45' ?>;transition:opacity .2s,border-color .2s" id="boot-block-<?= $id ?>">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                        <div style="display:flex;align-items:center;gap:6px">
                                            <i class="bi bi-sunrise-fill" style="color:var(--green);font-size:13px"></i>
                                            <span style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--green)">Boot</span>
                                            <?php if ($displayTimezone && $displayTimezone !== $serverTimezone): ?>
                                            <span id="sch-tz-badge-<?= $id ?>" style="font-size:10px;color:var(--blue);background:var(--blue-bg);padding:1px 6px;border-radius:20px;border:1px solid var(--blue-bdr)"><?= htmlspecialchars($displayTimezone) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-check form-switch mb-0" style="padding:0">
                                            <input class="form-check-input" type="checkbox" role="switch" style="width:28px;height:15px;margin:0"
                                                id="sch-toggle-<?= $id ?>" <?= $isActive ? 'checked' : '' ?>
                                                aria-label="Enable schedule"
                                                onchange="
                                                    const bb=document.getElementById('boot-block-<?= $id ?>');
                                                    const br=document.getElementById('boot-row-<?= $id ?>');
                                                    const bo=document.getElementById('boot-row-off-<?= $id ?>');
                                                    const on=this.checked;
                                                    bb.style.opacity=on?'1':'.45';
                                                    bb.style.borderColor='color-mix(in srgb,var(--green) '+(on?'20%':'10%')+',transparent)';
                                                    br.style.display=on?'':'none';
                                                    bo.style.display=on?'none':'';
                                                    autoSaveSchedule(<?= $id ?>)">
                                        </div>
                                    </div>
                                    <div id="boot-row-<?= $id ?>" style="display:<?= $isActive ? '' : 'none' ?>">
                                        <?= timePair('boot_'.$id, $bootDisp, 'form-select sch-time-select', 'width:64px') ?>
                                    </div>
                                    <div id="boot-row-off-<?= $id ?>" style="display:<?= $isActive ? 'none' : '' ?>;font-size:11px;color:var(--text-dim)">disabled</div>
                                </div>

                                <!-- Separador -->
                                <div style="width:10px;flex-shrink:0"></div>

                                <!-- Bloque apagado -->
                                <div style="flex:1;background:color-mix(in srgb,var(--red) 8%,transparent);border:1px solid color-mix(in srgb,var(--red) <?= $hasShutdown ? '20%' : '10%' ?>,transparent);border-radius:10px;padding:12px 14px;opacity:<?= $hasShutdown ? '1' : '.45' ?>;transition:opacity .2s,border-color .2s" id="shut-block-<?= $id ?>">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                        <div style="display:flex;align-items:center;gap:6px">
                                            <i class="bi bi-moon-fill" style="color:var(--red);font-size:12px"></i>
                                            <span style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--red)">Shutdown</span>
                                        </div>
                                        <div class="form-check form-switch mb-0" style="padding:0">
                                            <input class="form-check-input" type="checkbox" role="switch" style="width:28px;height:15px;margin:0"
                                                id="shut-toggle-<?= $id ?>" <?= $hasShutdown ? 'checked' : '' ?>
                                                aria-label="Enable automatic shutdown"
                                                onchange="
                                                    const b=document.getElementById('shut-block-<?= $id ?>');
                                                    const r=document.getElementById('shut-row-<?= $id ?>');
                                                    const ro=document.getElementById('shut-row-off-<?= $id ?>');
                                                    b.style.opacity=this.checked?'1':'.45';
                                                    b.style.borderColor='color-mix(in srgb,var(--red) '+(this.checked?'20%':'10%')+',transparent)';
                                                    r.style.display=this.checked?'':'none';
                                                    ro.style.display=this.checked?'none':'';
                                                    autoSaveSchedule(<?= $id ?>)">
                                        </div>
                                    </div>
                                    <div id="shut-row-<?= $id ?>" style="display:<?= $hasShutdown ? '' : 'none' ?>">
                                        <?= timePair('shut_'.$id, $shutDisp, 'form-select sch-time-select', 'width:64px') ?>
                                    </div>
                                    <div id="shut-row-off-<?= $id ?>" style="display:<?= $hasShutdown ? 'none' : '' ?>;font-size:11px;color:var(--text-dim)">disabled</div>
                                </div>
                            </div>

                            <!-- Método + días en fila debajo -->
                            <div style="padding:0 16px 14px;display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
                                <!-- Método (izquierda, tamaño encendido) -->
                                <div style="display:flex;align-items:center;gap:5px;flex-shrink:0">
                                    <i class="bi bi-lightning-charge-fill" style="color:var(--text-dim);font-size:10px"></i>
                                    <select class="form-select" id="meth_<?= $id ?>" style="font-size:10px;font-weight:600;padding:2px 22px 2px 6px;height:auto;width:auto;min-width:0;color:var(--text-muted)" onchange="autoSaveSchedule(<?= $id ?>)">
                                        <?php foreach(['Wake on LAN','Proxmox API','SSH'] as $m): ?>
                                        <option <?= $effectiveMethod===$m?'selected':'' ?>><?= $m ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <!-- Días (derecha, flex-grow) -->
                                <div style="display:flex;align-items:center;gap:5px;flex:1;justify-content:flex-end">
                                    <span style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim);white-space:nowrap">Days</span>
                                    <div style="display:flex;gap:3px" id="days-<?= $id ?>">
                                        <?php foreach($DAYS_LABELS as $key=>$lbl): ?>
                                        <button type="button" class="day-btn <?= in_array($key,$days)?'on':'' ?>" data-day="<?= $key ?>"
                                                onclick="this.classList.toggle('on'); this.setAttribute('aria-pressed',this.classList.contains('on')); autoSaveSchedule(<?= $id ?>)"
                                                aria-pressed="<?= in_array($key,$days)?'true':'false' ?>"
                                                aria-label="<?= $key ?>"><?= $lbl ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            </div><!-- /.accordion (schedule) -->
            </div><!-- col -->
            <div class="col-md-6"><div class="accordion">

            <?php if ($type !== 'windows'): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= (!$idl||!$idl['active'])?'collapsed':'' ?>" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-idle-<?= $id ?>">
                        <span class="acc-dot" style="background:#3fb950"></span>idle script (auto-shutdown)
                        <span id="idle-lock-badge-<?= $id ?>" style="margin-left:8px;display:<?= $srvIdleBlocked ? 'inline-flex' : 'none' ?>;align-items:center;gap:4px;font-size:10px;font-weight:600;color:var(--amber);background:var(--amber-bg);border:1px solid var(--amber-bdr);padding:1px 7px;border-radius:20px;letter-spacing:.03em">
                            <i class="bi bi-lock-fill" style="font-size:9px"></i>
                            <?php
                                $pendingSteps = [];
                                if ($srvNeedsSsh && !$srvSshOk) $pendingSteps[] = 'SSH';
                                if (!$srvDeployOk) $pendingSteps[] = 'deploy';
                                echo implode(' + ', $pendingSteps) ?: 'completar pasos';
                            ?>
                        </span>
                    </button>
                </h2>
                <div id="acc-idle-<?= $id ?>" class="accordion-collapse collapse <?= ($idl&&$idl['active'])?'show':'' ?>">
                    <div class="accordion-body">
                        <div data-autosave-idl="<?= $id ?>">
                            <div class="toggle-row mt-2">
                                <label class="form-label mb-0" for="idle-toggle-<?= $id ?>">Script active</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                        id="idle-toggle-<?= $id ?>" <?= ($idl&&$idl['active'])?'checked':'' ?>
                                        <?= $srvIdleBlocked ? 'disabled' : '' ?>>
                                </div>
                            </div>
                            <hr>
                            <div class="slider-row">
                                <span class="slider-label">Idle limit (min)</span>
                                <input type="range" min="5" max="120" step="5" value="<?= $idleLimitMin ?>" id="idle-limit-<?= $id ?>" oninput="this.nextElementSibling.textContent=this.value+' min'">
                                <span class="slider-val"><?= $idleLimitMin ?> min</span>
                            </div>
                            <hr>
                            <div class="sec-mini">activity detectors</div>

                            <!-- Grid 2 col para detectores simples -->
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 8px;margin-bottom:4px">
                            <?php if ($type !== 'pbs'): ?>
                            <div class="det-row" style="margin:0">
                                <input type="checkbox" class="form-check-input" id="det-smb-<?= $id ?>" <?= !empty($det['smb'])?'checked':'' ?>>
                                <span class="det-label">SMB / Samba</span>
                            </div>
                            <?php endif; ?>
                            <div class="det-row" style="margin:0">
                                <input type="checkbox" class="form-check-input" id="det-ssh-<?= $id ?>" <?= !empty($det['ssh'])?'checked':'' ?>>
                                <span class="det-label">SSH sessions active</span>
                            </div>
                            <?php if ($type === 'pbs'): ?>
                            <div class="det-row" style="margin:0">
                                <input type="checkbox" class="form-check-input" id="det-pbs-<?= $id ?>" <?= !empty($det['pbs'])?'checked':'' ?>
                                    onchange="document.getElementById('pbs-postbackup-row-<?= $id ?>').style.display=this.checked?'':'none'">
                                <span class="det-label">Active backup tasks <span style="font-size:9px;color:var(--text-dim)">(local)</span></span>
                            </div>
                            <?php endif; ?>
                            </div>

                            <!-- CPU: full width con slider inline -->
                            <div class="det-row" style="flex-wrap:nowrap;gap:8px;align-items:center">
                                <input type="checkbox" class="form-check-input" id="det-cpu-<?= $id ?>" <?= !empty($det['cpu'])?'checked':'' ?>
                                    onchange="const s=document.getElementById('idle-cpu-<?= $id ?>');s.disabled=!this.checked;s.style.opacity=this.checked?'1':'.4'">
                                <span class="det-label" style="white-space:nowrap">CPU &gt; threshold</span>
                                <input type="range" min="5" max="80" step="1" value="<?= $cpuThresh ?>" id="idle-cpu-<?= $id ?>"
                                    style="flex:1;min-width:0;opacity:<?= empty($det['cpu']) ? '.4' : '1' ?>"
                                    <?= empty($det['cpu']) ? 'disabled' : '' ?>
                                    oninput="document.getElementById('cpu-val-<?= $id ?>').textContent=this.value+'%'">
                                <span class="slider-val" id="cpu-val-<?= $id ?>" style="min-width:32px;text-align:right"><?= $cpuThresh ?>%</span>
                            </div>

                            <!-- Detectores con parámetros: full width -->
                            <?php if ($type !== 'pbs'): ?>
                            <div class="det-row">
                                <input type="checkbox" class="form-check-input" id="det-jellyfin-<?= $id ?>" <?= !empty($det['jellyfin'])?'checked':'' ?>>
                                <span class="det-label">Jellyfin</span>
                                <div class="det-params">
                                    <span class="det-param-label">host</span><input class="form-control det-host" id="prm-jf-host-<?= $id ?>" value="<?= htmlspecialchars(($prms['jellyfin']['host']??'')==='localhost'?'':($prms['jellyfin']['host']??'')) ?>" placeholder="192.168.x.x">
                                    <span class="det-param-label">port</span><input class="form-control port" id="prm-jf-port-<?= $id ?>" value="<?= htmlspecialchars($prms['jellyfin']['port']??'8096') ?>">
                                    <span class="det-param-label">api key</span><input class="form-control" id="prm-jf-token-<?= $id ?>" value="<?= htmlspecialchars($prms['jellyfin']['token']??'') ?>" placeholder="opcional">
                                </div>
                            </div>
                            <div class="det-row">
                                <input type="checkbox" class="form-check-input" id="det-qbit-<?= $id ?>" <?= !empty($det['qbit'])?'checked':'' ?>>
                                <span class="det-label">qBittorrent</span>
                                <div class="det-params">
                                    <span class="det-param-label">host</span><input class="form-control det-host" id="prm-qb-host-<?= $id ?>" value="<?= htmlspecialchars(($prms['qbit']['host']??'')==='localhost'?'':($prms['qbit']['host']??'')) ?>" placeholder="192.168.x.x">
                                    <span class="det-param-label">port</span><input class="form-control port" id="prm-qb-port-<?= $id ?>" value="<?= htmlspecialchars($prms['qbit']['port']??'8080') ?>">
                                </div>
                            </div>
                            <?php else: /* PBS: hidden inputs */ ?>
                            <input type="hidden" id="det-smb-<?= $id ?>"      value="">
                            <input type="hidden" id="det-jellyfin-<?= $id ?>"  value="">
                            <input type="hidden" id="det-qbit-<?= $id ?>"      value="">
                            <input type="hidden" id="prm-jf-host-<?= $id ?>"   value="">
                            <input type="hidden" id="prm-jf-port-<?= $id ?>"   value="8096">
                            <input type="hidden" id="prm-jf-token-<?= $id ?>"  value="">
                            <input type="hidden" id="prm-qb-host-<?= $id ?>"   value="">
                            <input type="hidden" id="prm-qb-port-<?= $id ?>"   value="8080">
                            <?php endif; ?>

                            <?php if ($type === 'pbs'):
                                $pbsPostbackup = false;
                                try {
                                    $stPbsPb = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
                                    $stPbsPb->execute(["srv_{$id}_pbs_postbackup"]);
                                    $rPbsPb = $stPbsPb->fetch();
                                    $pbsPostbackup = $rPbsPb && $rPbsPb['value'] === '1';
                                } catch (Throwable $e) {}
                            ?>
                            <!-- PBS postbackup row (solo tipo pbs) -->
                            <div class="det-row" id="pbs-postbackup-row-<?= $id ?>" style="<?= !empty($det['pbs'])?'':'display:none' ?>">
                                <input type="checkbox" class="form-check-input" id="pbs-postbackup-<?= $id ?>" <?= $pbsPostbackup ? 'checked' : '' ?>
                                    onchange="fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set_pbs_postbackup',server_id:<?= $id ?>,value:this.checked?1:0})})">
                                <span class="det-label" style="padding-left:1rem">↳ Shutdown when backup finishes <span style="font-size:9px;color:var(--text-dim)">(without waiting for idle)</span></span>
                            </div>
                            <input type="hidden" id="prm-pbs-host-<?= $id ?>" value="localhost">
                            <input type="hidden" id="prm-pbs-port-<?= $id ?>" value="8007">
                            <?php elseif ($type === 'pve'): ?>
                            <!-- PVE: PBS externo (full width con parámetros) -->
                            <div class="det-row">
                                <input type="checkbox" class="form-check-input" id="det-pbs-<?= $id ?>" <?= !empty($det['pbs'])?'checked':'' ?>>
                                <span class="det-label">PBS job active <span style="font-size:9px;color:var(--text-dim)">(external server)</span></span>
                                <div class="det-params">
                                    <span class="det-param-label">host</span><input class="form-control det-host" id="prm-pbs-host-<?= $id ?>" value="<?= htmlspecialchars(($prms['pbs']['host']??'')==='localhost'?'':($prms['pbs']['host']??'')) ?>" placeholder="192.168.x.x">
                                    <span class="det-param-label">port</span><input class="form-control port" id="prm-pbs-port-<?= $id ?>" value="<?= htmlspecialchars($prms['pbs']['port']??'8007') ?>">
                                </div>
                            </div>
                            <?php else: /* TrueNAS y otros: sin detector PBS */ ?>
                            <input type="hidden" id="det-pbs-<?= $id ?>"      value="">
                            <input type="hidden" id="prm-pbs-host-<?= $id ?>" value="">
                            <input type="hidden" id="prm-pbs-port-<?= $id ?>" value="8007">
                            <?php endif; ?>

                            <div style="margin-top:12px">
                                <button type="button" id="deploy-btn-<?= $id ?>" class="btn btn-outline-success btn-sm w-100" onclick="deployIdle(<?= $id ?>,this)">→ Deploy SSH</button>
                            </div>
                        </div>
                        <div id="script-output-<?= $id ?>"></div>

                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" disabled style="opacity:.5;cursor:default">
                        <span class="acc-dot" style="background:#555"></span>idle script <span style="font-size:.75rem;margin-left:6px;font-weight:400;color:var(--text-dim)">(not available on Windows)</span>
                    </button>
                </h2>
            </div>
            <?php endif; ?>

            </div><!-- /.accordion (idle) -->
            </div><!-- col -->
            </div><!-- row -->

            <div class="accordion">

            <!-- CONFIGURACIÓN GENERAL + TOKEN -->
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-cfg-<?= $id ?>">
                        <span class="acc-dot" style="background:#8b949e"></span>general configuration
                    </button>
                </h2>
                <div id="acc-cfg-<?= $id ?>" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <form onsubmit="saveServer(event,<?= $id ?>)">
                        <div class="row g-3">

                            <!-- COL IZQUIERDA -->
                            <div class="col-lg-6 cfg-col">

                                <div class="cfg-section">
                                    <div class="cfg-section-title">identity</div>
                                    <div class="form-row"><span class="form-label">Hostname</span><input id="hn_<?= $id ?>" class="form-control" value="<?= htmlspecialchars($srv['hostname']) ?>"></div>
                                    <div class="form-row"><span class="form-label">Web access URL</span><input id="url_<?= $id ?>" class="form-control" value="<?= htmlspecialchars(preg_replace('/^https?:\/\//i','', $srv['url'] ?? '')) ?>" placeholder="192.168.1.10:8006 o dominio"></div>
                                    <input type="hidden" id="not_<?= $id ?>" value="<?= htmlspecialchars($srv['notes']??'') ?>">
                                    <div class="type-picker type-picker-sm" id="edit-type-picker-<?= $id ?>" style="margin-top:10px">
                                        <?php foreach ($HYP_LABELS as $hk => $hv): ?>
                                        <button type="button" class="type-btn<?= $type===$hk?' selected':'' ?>" data-val="<?= $hk ?>" onclick="selectHostType('edit_<?= $id ?>','<?= $hk ?>')">
                                            <img src="assets/icons/<?= $hk ?>.svg" width="18" height="18">
                                            <span><?= $hv ?></span>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" id="hyp_<?= $id ?>" value="<?= htmlspecialchars($type) ?>">
                                    <div class="form-row" style="margin-top:8px">
                                        <span class="form-label">Has API</span>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" id="api_toggle_<?= $id ?>" role="switch"
                                                <?= $srv['api_enabled'] ? 'checked' : '' ?>
                                                onchange="onApiChange(<?= $id ?>,this.checked)">
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <!-- COL DERECHA -->
                            <div class="col-lg-6 cfg-col">

                                <div class="cfg-section">
                                    <div class="cfg-section-title">network</div>
                                    <div class="form-row">
                                        <span class="form-label">Is VM</span>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" id="is_vm_<?= $id ?>" role="switch"
                                                <?= $isVm ? 'checked' : '' ?>
                                                onchange="onIsVmChange(<?= $id ?>,this.checked)">
                                        </div>
                                    </div>
                                    <div id="vm-section-<?= $id ?>" style="display:<?= $isVm ? 'block' : 'none' ?>">
                                        <div class="form-row">
                                            <span class="form-label">Proxmox</span>
                                            <select id="pve_srv_<?= $id ?>" class="form-select">
                                                <option value="">— select —</option>
                                                <?php foreach ($servers as $s): if ($s['hypervisor_type'] !== 'pve') continue; ?>
                                                <option value="<?= $s['id'] ?>" <?= intval($srv['proxmox_server_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s['hostname'], ENT_QUOTES) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-row">
                                            <span class="form-label">VMID</span>
                                            <input type="number" id="vmid_<?= $id ?>" class="form-control" min="1" style="max-width:90px"
                                                value="<?= intval($srv['proxmox_vmid'] ?? 0) ?: '' ?>" placeholder="100">
                                        </div>
                                        <div class="form-row">
                                            <span class="form-label">Depends on <span class="cfg-hint-icon" title="WakeLab boots this host first if it's offline">ℹ</span></span>
                                            <select id="dep_<?= $id ?>" class="form-select" style="max-width:160px">
                                                <option value="">— none —</option>
                                                <?php foreach ($servers as $s): if ($s['id'] === $id) continue; ?>
                                                <option value="<?= $s['id'] ?>" <?= intval($srv['depends_on_server_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s['hostname'], ENT_QUOTES) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div id="mac-row-<?= $id ?>" <?= $isVm ? 'style="display:none"' : '' ?>>
                                        <div class="form-row"><span class="form-label">MAC <span class="fmodal-label-hint">WoL — comma-separated for multiple</span></span><input id="mac_<?= $id ?>" class="form-control" value="<?= htmlspecialchars($srv['mac']) ?>" placeholder="AA:BB:CC:DD:EE:FF"></div>
                                    </div>
                                    <div class="form-row">
                                        <span class="form-label">Shutdown timeout <span class="fmodal-label-hint">seconds</span></span>
                                        <input id="shut_timeout_<?= $id ?>" type="number" min="30" max="600" class="form-control" style="width:90px"
                                            value="<?= $shutdownTimeoutMap[$id] ?? 90 ?>"
                                            onchange="_debounce('shut_timeout_<?= $id ?>',()=>saveShutdownTimeout(<?= $id ?>),0)">
                                    </div>
                                </div>

                                <?php /* V2 — Sección UPS por servidor deshabilitada temporalmente
                                <div class="cfg-section">
                                    <div class="cfg-section-title">UPS</div>
                                    ... ups_managed, ups_priority, ups_ignore_delay, ups_last_resort ...
                                </div>
                                */ ?>

                                <!-- Credenciales -->
                                <div id="token-section-<?= $id ?>" style="<?= !$srv['api_enabled'] ? 'display:none' : '' ?>">
                                <?php if ($type === 'pve' || $type === 'pbs'): ?>
                                    <?php if ($tok && !empty($tok['token_secret'])): ?>
                                    <div class="cfg-section" id="token-configured-<?= $id ?>">
                                        <div style="display:flex;align-items:center;justify-content:space-between">
                                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--green)"><i class="bi bi-check-circle-fill"></i> API Token configured</span>
                                            <button type="button" class="btn btn-link btn-sm" style="font-size:10px;color:var(--text-dim);padding:0"
                                                onclick="tokenReconfigure(<?= $id ?>)">reconfigure</button>
                                        </div>
                                        <input type="hidden" id="apu_<?= $id ?>" value="<?= htmlspecialchars($tok['api_user']??'root@pam') ?>">
                                        <input type="hidden" id="tid_<?= $id ?>" value="<?= htmlspecialchars($tok['token_id']??'panel') ?>">
                                        <input type="hidden" id="tsc_<?= $id ?>" value="">
                                    </div>
                                    <?php else: ?>
                                    <div class="cfg-section" id="token-configured-<?= $id ?>">
                                        <div class="cfg-section-title">token <?= $hypLabel ?></div>
                                        <div class="cfg-hint">
                                            <span class="cfg-hint-icon">ℹ</span>
                                            <span><?= $type==='pve' ? 'Datacenter → API Tokens → Add, no Privilege Separation' : 'Configuration → User Management → API Tokens' ?>. User <code>root@pam</code>, ID <code>panel</code></span>
                                        </div>
                                        <div class="form-row"><span class="form-label">User</span><input id="apu_<?= $id ?>" class="form-control" value="<?= htmlspecialchars($tok['api_user']??'root@pam') ?>"></div>
                                        <div class="form-row"><span class="form-label">Token ID</span><input id="tid_<?= $id ?>" class="form-control" value="<?= htmlspecialchars($tok['token_id']??'panel') ?>"></div>
                                        <div class="form-row"><span class="form-label">Secret</span><input id="tsc_<?= $id ?>" class="form-control" type="password" placeholder="UUID" autocomplete="new-password"></div>
                                    </div>
                                    <?php endif; ?>
                                <?php elseif ($type === 'truenas'):
                                    $tnSsh = $tn_ssh[$id] ?? [];
                                ?>
                                    <?php if ($tok && !empty($tok['token_secret'])): ?>
                                    <div class="cfg-section" id="token-configured-<?= $id ?>">
                                        <div style="display:flex;align-items:center;justify-content:space-between">
                                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--green)"><i class="bi bi-check-circle-fill"></i> API Key configured</span>
                                            <button type="button" class="btn btn-link btn-sm" style="font-size:10px;color:var(--text-dim);padding:0"
                                                onclick="tokenReconfigure(<?= $id ?>)">reconfigure</button>
                                        </div>
                                        <input type="hidden" id="apu_<?= $id ?>" value="truenas">
                                        <input type="hidden" id="tid_<?= $id ?>" value="apikey">
                                        <input type="hidden" id="tsc_<?= $id ?>" value="">
                                    </div>
                                    <?php else: ?>
                                    <div class="cfg-section" id="token-configured-<?= $id ?>">
                                        <div class="cfg-section-title">api key</div>
                                        <div class="form-row"><span class="form-label">API Key</span><input id="tsc_<?= $id ?>" class="form-control" type="password" placeholder="paste API key" autocomplete="new-password"></div>
                                        <input type="hidden" id="apu_<?= $id ?>" value="truenas">
                                        <input type="hidden" id="tid_<?= $id ?>" value="apikey">
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!($sshKeyOkMap[$id] ?? false) && !($idleDeployedMap[$id] ?? false)): ?>
                                    <?= sshBlock('tn_ssh', $id, $tnSsh, $wakeLabKeyReady, "saveTrueNASSSH({$id})") ?>
                                    <?php endif; ?>
                                <?php elseif ($type === 'omv'):
                                    $omvSsh = $tn_ssh[$id] ?? [];
                                ?>
                                    <div class="cfg-section">
                                        <div class="cfg-section-title">web credentials</div>
                                        <div class="form-row"><span class="form-label">User</span><input id="apu_<?= $id ?>" class="form-control" value="<?= htmlspecialchars($tok['api_user']??'admin') ?>" placeholder="admin"></div>
                                        <div class="form-row"><span class="form-label">Password</span><input id="tsc_<?= $id ?>" class="form-control" type="password" placeholder="<?= $tok?'••••••••':'OMV password' ?>" autocomplete="new-password"></div>
                                        <input type="hidden" id="tid_<?= $id ?>" value="">
                                    </div>
                                    <?php if (!($sshKeyOkMap[$id] ?? false) && !($idleDeployedMap[$id] ?? false)): ?>
                                    <?= sshBlock('omv_ssh', $id, $omvSsh, $wakeLabKeyReady, "saveOMVSSH({$id})", 'Authorize key on OMV: <code>mkdir -p ~/.ssh && cat id_ed25519.pub >> ~/.ssh/authorized_keys</code>') ?>
                                    <?php endif; ?>
                                <?php elseif ($type === 'windows' || $type === 'linux'):
                                    $pcSsh = $tn_ssh[$id] ?? [];
                                    $defaultUser = ($type === 'windows') ? 'Administrator' : 'root';
                                ?>
                                    <input type="hidden" id="apu_<?= $id ?>" value="">
                                    <input type="hidden" id="tid_<?= $id ?>" value="">
                                    <input type="hidden" id="tsc_<?= $id ?>" value="">
                                    <?php if (!($sshKeyOkMap[$id] ?? false) && !($idleDeployedMap[$id] ?? false)): ?>
                                    <?= sshBlock('tn_ssh', $id, array_merge(['user'=>$defaultUser], $pcSsh), $wakeLabKeyReady, "saveTrueNASSSH({$id})") ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <input type="hidden" id="apu_<?= $id ?>" value="">
                                    <input type="hidden" id="tid_<?= $id ?>"  value="">
                                    <input type="hidden" id="tsc_<?= $id ?>"  value="">
                                <?php endif; ?>

                                <!-- Autorizar clave SSH (solo si aún no se deployó el script y no está ya autorizada) -->
                                <?php $sshAlreadyOk = !empty($sshKeyOkMap[$id]) || !empty($idleDeployedMap[$id]); ?>
                                <div class="cfg-section" id="ssh-authorized-<?= $id ?>" <?= $sshAlreadyOk ? '' : 'style="display:none"' ?>>
                                    <div style="display:flex;align-items:center;justify-content:space-between">
                                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--green)"><i class="bi bi-check-circle-fill"></i> SSH access authorized</span>
                                        <button type="button" class="btn btn-link btn-sm" style="font-size:10px;color:var(--text-dim);padding:0"
                                            onclick="sshAuthorizeReset(<?= $id ?>)">reconfigure</button>
                                    </div>
                                </div>
                                <div class="cfg-section" id="ssh-authorize-<?= $id ?>" <?= $sshAlreadyOk ? 'style="display:none"' : '' ?>>
                                    <div class="cfg-section-title">ssh key <span style="font-size:9px;text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-dim)">(authorize access from WakeLab)</span></div>
                                    <?php if ($type === 'truenas'): ?>
                                    <div class="cfg-hint"><span class="cfg-hint-icon">ℹ</span><span>WakeLab installs the key via TrueNAS API automatically. No password needed.</span></div>
                                    <?php else: ?>
                                    <div class="cfg-hint"><span class="cfg-hint-icon">ℹ</span><span>Enter the root password <b>one time only</b> so WakeLab can add its public key to the server. You can delete the password afterwards.</span></div>
                                    <div class="form-row">
                                        <span class="form-label">User</span>
                                        <input id="auth_user_<?= $id ?>" class="form-control" value="root" style="max-width:110px">
                                    </div>
                                    <div class="form-row">
                                        <span class="form-label">Port</span>
                                        <input id="auth_port_<?= $id ?>" class="form-control" type="number" value="22" style="max-width:80px">
                                    </div>
                                    <div class="form-row">
                                        <span class="form-label">Password</span>
                                        <input id="auth_pass_<?= $id ?>" class="form-control" type="password" placeholder="root password (once)" autocomplete="new-password">
                                    </div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm mt-2 w-100"
                                        onclick="authorizeSSHKey(<?= $id ?>)">🔑 authorize SSH key on this server</button>
                                    <div id="auth-result-<?= $id ?>" style="font-size:.78rem;margin-top:6px"></div>
                                </div>

                                </div><!-- /token-section -->

                            </div>

                        </div><!-- /row -->

                        <!-- Footer: acciones -->
                        <div class="cfg-footer">
                            <div style="display:flex;gap:6px;align-items:center">
                                <button type="submit" class="btn btn-success btn-sm">save</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="testConnection(<?= $id ?>)">test</button>
                            </div>
                            <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="confirmDeleteServer(<?= $id ?>,'<?= htmlspecialchars($srv['hostname'],ENT_QUOTES) ?>')">delete</button>
                        </div>
                        <div id="test-steps-<?= $id ?>" style="margin-top:8px"></div>
                        </form>
                    </div>
                </div>
            </div>

            </div><!-- /.accordion (cfg) -->

            <div class="accordion">

            <!-- EVENTOS RECIENTES -->
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-logs-<?= $id ?>"
                            onclick="loadServerLogs(<?= $id ?>)">
                        <span class="acc-dot" style="background:#484f58"></span>recent events
                    </button>
                </h2>
                <div id="acc-logs-<?= $id ?>" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                            <div class="btn-group btn-group-sm" id="srv-log-filter-lvl-<?= $id ?>" role="group">
                                <button type="button" class="btn btn-outline-secondary active" data-lvl="" onclick="setSrvLogLvl(this,<?= $id ?>)" title="all levels" style="font-size:11px;padding:3px 8px">all</button>
                                <button type="button" class="btn btn-outline-secondary" data-lvl="ok"   onclick="setSrvLogLvl(this,<?= $id ?>)" title="ok"><i class="bi bi-check-circle-fill log-lvl-ok"></i></button>
                                <button type="button" class="btn btn-outline-secondary" data-lvl="warn" onclick="setSrvLogLvl(this,<?= $id ?>)" title="warning"><i class="bi bi-exclamation-triangle-fill log-lvl-warn"></i></button>
                                <button type="button" class="btn btn-outline-secondary" data-lvl="err"  onclick="setSrvLogLvl(this,<?= $id ?>)" title="error"><i class="bi bi-x-circle-fill log-lvl-err"></i></button>
                                <button type="button" class="btn btn-outline-secondary" data-lvl="info" onclick="setSrvLogLvl(this,<?= $id ?>)" title="info"><i class="bi bi-info-circle-fill log-lvl-info"></i></button>
                            </div>
                            <button class="btn btn-outline-secondary btn-sm" style="font-size:10px;padding:2px 8px"
                                onclick="loadServerLogs(<?= $id ?>)" title="Refresh logs">↻ refresh</button>
                        </div>
                        <div id="srv-log-list-<?= $id ?>" class="srv-log-list">
                            <div class="loading"><span class="spinner"></span>loading…</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php
                // ── Info del host ────────────────────────────────────────
                if ($isVm) {
                    $wakeHow = "Proxmox API · VM #{$srv['proxmox_vmid']}";
                } else {
                    $em = $effectiveMethod ?? 'Wake on LAN';
                    if ($em === 'Wake on LAN')
                        $wakeHow = $srv['mac'] ? "Wake on LAN → {$srv['mac']}" : "Wake on LAN ⚠ (sin MAC)";
                    else
                        $wakeHow = $em;
                }
                switch ($type) {
                    case 'pve':     $shutHow = "SSH shutdown -h now" . (!$isVm ? " · guests via qm/pct first" : ""); break;
                    case 'pbs':     $shutHow = "SSH shutdown -h now"; break;
                    case 'truenas': $shutHow = "midclt system.shutdown + SSH"; break;
                    case 'omv':     $shutHow = "SSH shutdown -h now"; break;
                    default:        $shutHow = "SSH shutdown -h now";
                }
                $detList = [];
                if (!empty($det['smb']))      $detList[] = 'SMB';
                if (!empty($det['ssh']))      $detList[] = 'SSH sessions';
                if (!empty($det['cpu']))      $detList[] = "CPU>{$cpuThresh}%";
                if (!empty($det['jellyfin'])) $detList[] = 'Jellyfin';
                if (!empty($det['qbit']))     $detList[] = 'qBittorrent';
                if (!empty($det['pbs']))      $detList[] = 'PBS tasks';
                $bootT  = $sch['boot_time']     ? substr($sch['boot_time'],0,5)     : '—';
                $shutT  = $sch['shutdown_time'] ? substr($sch['shutdown_time'],0,5) : '—';
            ?>
            <!-- ── DEBUG accordion ──────────────────────────────────────── -->
            <div class="accordion-item" data-debug>
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#acc-dbg-<?= $id ?>">
                        <span class="acc-dot" style="background:#3d444d"></span>debug
                    </button>
                </h2>
                <div id="acc-dbg-<?= $id ?>" class="accordion-collapse collapse">
                    <div class="accordion-body" style="padding:14px 16px">

                        <!-- ── Sección: Info del host ── -->
                        <div class="dbg-section-title"><i class="bi bi-info-circle me-1"></i>Host information</div>
                        <div class="dbg-info-grid">
                            <span class="dbg-info-key">Type</span>
                            <span class="dbg-info-val"><?= htmlspecialchars($hypLabel) ?></span>

                            <span class="dbg-info-key">IP / Port</span>
                            <span class="dbg-info-val"><?= htmlspecialchars($srv['ip']) ?>:<?= $srv['port'] ?></span>

                            <span class="dbg-info-key">MAC</span>
                            <span class="dbg-info-val"><?= htmlspecialchars($srv['mac'] ?: '—') ?></span>

                            <span class="dbg-info-key">How it boots</span>
                            <span class="dbg-info-val"><?= htmlspecialchars($wakeHow) ?></span>

                            <span class="dbg-info-key">How it shuts down</span>
                            <span class="dbg-info-val"><?= htmlspecialchars($shutHow) ?></span>

                            <span class="dbg-info-key">Schedule</span>
                            <span class="dbg-info-val">
                                <?php if ($sch && ($sch['active'] ?? 0)): ?>
                                    Boot <?= $bootT ?> · Shutdown <?= $shutT ?>
                                    · <span style="color:var(--green)">active</span>
                                <?php else: ?>
                                    <span style="color:var(--text-dim)">inactive</span>
                                <?php endif; ?>
                            </span>

                            <span class="dbg-info-key">Idle</span>
                            <span class="dbg-info-val">
                                <?php if ($idl && ($idl['active'] ?? 0)): ?>
                                    Limit <?= $idleLimitMin ?>min
                                    · detectors: <?= empty($detList) ? 'none' : htmlspecialchars(implode(', ', $detList)) ?>
                                    · <span style="color:var(--green)">active</span>
                                <?php else: ?>
                                    <span style="color:var(--text-dim)">inactive</span>
                                <?php endif; ?>
                            </span>

                            <?php if ($srv['api_enabled']): ?>
                            <span class="dbg-info-key">API</span>
                            <span class="dbg-info-val">enabled · user <?= htmlspecialchars($tok['api_user'] ?? '—') ?></span>
                            <?php endif; ?>
                        </div>

                        <hr class="dbg-sep">

                        <!-- ── Sección: Conexión SSH ── -->
                        <div class="dbg-section-title"><i class="bi bi-terminal me-1"></i>SSH Connection</div>
                        <div class="debug-check-row">
                            <span class="debug-label-inline">SSH Key</span>
                            <button class="btn btn-xs-dbg" onclick="checkSshKey(<?= $id ?>, this)">
                                Verify
                            </button>
                        </div>
                        <pre id="dbg-ssh-<?= $id ?>" class="debug-pre" style="display:none"></pre>

                        <hr class="dbg-sep">

                        <!-- ── Idle / auto-shutdown section ── -->
                        <div class="dbg-section-title"><i class="bi bi-moon me-1"></i>Idle (auto-shutdown)</div>

                        <div class="debug-check-row">
                            <span class="debug-label-inline">1. idle_config API</span>
                            <button class="btn btn-xs-dbg" onclick="debugIdleApi(<?= $id ?>, '<?= htmlspecialchars($srv['hostname'], ENT_QUOTES) ?>', this)">
                                Test
                            </button>
                        </div>
                        <pre id="dbg-api-<?= $id ?>" class="debug-pre" style="display:none"></pre>

                        <div class="debug-check-row mt-2">
                            <span class="debug-label-inline">2. Cron &amp; remote script</span>
                            <button class="btn btn-xs-dbg" onclick="debugIdleCron(<?= $id ?>, this)">
                                Verify
                            </button>
                        </div>
                        <pre id="dbg-cron-<?= $id ?>" class="debug-pre" style="display:none"></pre>

                        <div class="debug-check-row mt-2">
                            <span class="debug-label-inline">3. Remote log</span>
                            <button class="btn btn-xs-dbg" onclick="fetchIdleLog(<?= $id ?>, this)">
                                View log
                            </button>
                        </div>
                        <pre id="idle-log-<?= $id ?>" class="debug-pre" style="display:none"></pre>

                        <div class="debug-check-row mt-2">
                            <span class="debug-label-inline">4. Verify idle_active</span>
                            <button class="btn btn-xs-dbg" onclick="debugIdleActive(<?= $id ?>, this)">
                                Test
                            </button>
                        </div>
                        <pre id="dbg-idle-active-<?= $id ?>" class="debug-pre" style="display:none"></pre>

                        <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--border-sub)" class="debug-check-row">
                            <span class="debug-label-inline" style="color:#f85149">Remove script, log and cron from server</span>
                            <button class="btn btn-xs-dbg" style="border-color:#f85149;color:#f85149"
                                    onclick="cleanIdleHost(<?= $id ?>, this)">
                                <i class="bi bi-trash me-1"></i>Clean up
                            </button>
                        </div>
                        <pre id="dbg-clean-<?= $id ?>" class="debug-pre" style="display:none"></pre>

                    </div>
                </div>
            </div>
            <?php // end debug accordion vars block ?>

            </div><!-- /.accordion -->
        </div><!-- /.tab-pane -->
        <?php endforeach; ?>

        <!-- ══ HOSTS DB ════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tab-hosts-db" role="tabpanel">

            <!-- Header -->
            <div class="hdb-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="hdb-icon"><i class="bi bi-database"></i></div>
                    <div>
                        <div class="hdb-title">Registered hosts</div>
                        <div class="hdb-sub"><?= count($servers) ?> server<?= count($servers)!==1?'s':'' ?> in the database</div>
                    </div>
                </div>
                <button class="btn btn-outline-success btn-sm d-flex align-items-center gap-1" onclick="openAddModal()">
                    <i class="bi bi-plus-lg"></i> Add host
                </button>
            </div>

            <div class="card">
                <div class="card-body p-3">
                <div class="table-responsive">
                    <table class="table wl-table mb-0" id="hdb-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Hostname</th>
                                <th>IP : Port</th>
                                <th>MAC</th>
                                <th>Type</th>
                                <th>Role</th>
                                <th>API</th>
                                <th>Schedule</th>
                                <th title="Show in dashboard and sidebar">Visible</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($servers as $srv):
                            $hyp      = $srv['hypervisor_type'] ?? 'generic';
                            $hl       = $HYP_LABELS[$hyp] ?? $hyp;
                            $hIcon    = $HYP_ICONS[$hyp]  ?? 'bi-server';
                            $sch      = $schedules[$srv['id']] ?? null;
                            $schActive = $sch && $sch['active'];
                            $schTxt   = ($sch && $sch['boot_time'])
                                ? substr($sch['boot_time'],0,5).'–'.substr($sch['shutdown_time'] ?? '—',0,5)
                                : null;
                            $mac = $srv['mac'] ? htmlspecialchars($srv['mac']) : '<span style="color:var(--text-dim)">—</span>';
                        ?>
                        <tr>
                            <td data-order="0"><span class="badge bg-secondary" id="db-status-<?= $srv['id'] ?>">—</span></td>
                            <td>
                                <span class="hdb-hn"><?= htmlspecialchars($srv['hostname']) ?></span>
                            </td>
                            <td><span class="hdb-mono"><?= htmlspecialchars($srv['ip'].':'.($srv['port']??8006)) ?></span></td>
                            <td><span class="hdb-mono"><?= $mac ?></span></td>
                            <td>
                                <span class="hyp-badge hyp-<?= $hyp ?>">
                                    <img src="assets/icons/<?= htmlspecialchars($hyp) ?>.svg" width="14" height="14" style="vertical-align:-2px;margin-right:4px"><?= $hl ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($srv['role'] ?? '—') ?></td>
                            <td>
                                <?php if ($srv['api_enabled']): ?>
                                    <span class="api-on"><i class="bi bi-check-circle-fill"></i>on</span>
                                <?php else: ?>
                                    <span class="api-off"><i class="bi bi-dash-circle"></i>off</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($schTxt): ?>
                                    <?php if ($schActive): ?>
                                        <span class="sch-active"><i class="bi bi-clock-fill"></i><?= $schTxt ?></span>
                                    <?php else: ?>
                                        <span class="sch-off"><?= $schTxt ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="sch-off">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $isVisible = empty($hiddenServers[$srv['id']]); ?>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                        id="vis-<?= $srv['id'] ?>" <?= $isVisible ? 'checked' : '' ?>
                                        onchange="toggleServerVisibility(<?= $srv['id'] ?>, this.checked)"
                                        title="<?= $isVisible ? 'Visible in dashboard' : 'Hidden from dashboard' ?>">
                                </div>
                            </td>
                            <td class="text-end" style="white-space:nowrap">
                                <button class="btn btn-outline-secondary btn-sm py-0 px-2"
                                    onclick="sidebarNav(document.getElementById('sb-srv-<?= $srv['id'] ?>'), 'htab-srv-<?= $srv['id'] ?>')"
                                    title="Go to server"><i class="bi bi-box-arrow-up-right"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            </div>
        </div>

        <!-- ══ WAKE PROXY ═════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tab-wake-proxy" role="tabpanel">

            <!-- Header -->
            <div class="wp-hdr mt-3 mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="wp-hdr-icon"><i class="bi bi-lightning-charge-fill"></i></div>
                    <div>
                        <div class="wp-hdr-title">Wake Proxy</div>
                        <div class="wp-hdr-sub">Boot-on-demand — services that start automatically when accessed</div>
                    </div>
                </div>
                <button class="btn btn-outline-success btn-sm" onclick="openWakeProxyModal()">
                    <i class="bi bi-plus-lg me-1"></i>add proxy
                </button>
            </div>

            <?php if (empty($wake_proxies)): ?>
            <!-- Empty state -->
            <div class="ob-empty-wrap" style="max-width:560px;margin:40px auto;text-align:center">
                <div class="ob-icon-wrap" style="width:64px;height:64px;border-radius:18px;background:var(--bg-card);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px">
                    <i class="bi bi-lightning-charge-fill" style="color:var(--amber)"></i>
                </div>
                <div style="font-size:18px;font-weight:700;color:var(--text);margin-bottom:8px">Boot-on-demand</div>
                <div style="font-size:13px;color:var(--text-muted);line-height:1.6;margin-bottom:28px">
                    Wake Proxy intercepts access to a domain and, if the server is off, wakes it up automatically before redirecting you. No manual intervention needed.
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:28px;text-align:left">
                    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px">
                        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px"><i class="bi bi-diagram-3 me-1"></i>How it works</div>
                        <div style="font-size:12px;color:var(--text-dim);line-height:1.6">Point a domain to the proxy. When accessed, the proxy sends WoL to the server, waits for it to boot, and redirects you to the service.</div>
                    </div>
                    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px">
                        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px"><i class="bi bi-layers me-1"></i>Boot layers</div>
                        <div style="font-size:12px;color:var(--text-dim);line-height:1.6">
                            <span style="color:var(--text-muted)">Layer 1:</span> Host only<br>
                            <span style="color:var(--blue)">Layer 2:</span> Host + VM/LXC<br>
                            <span style="color:var(--amber)">Layer 3:</span> Host + VM + Docker
                        </div>
                    </div>
                    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px">
                        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px"><i class="bi bi-globe me-1"></i>Requirement</div>
                        <div style="font-size:12px;color:var(--text-dim);line-height:1.6">You need a domain pointing to this server and port 80/443 accessible from the internet or your network.</div>
                    </div>
                    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px">
                        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px"><i class="bi bi-clock me-1"></i>Timeout</div>
                        <div style="font-size:12px;color:var(--text-dim);line-height:1.6">Configure how many seconds to wait for the server to boot before showing an error to the user.</div>
                    </div>
                </div>

                <button class="btn btn-success" onclick="openWakeProxyModal()">
                    <i class="bi bi-plus-lg me-1"></i>Add first proxy
                </button>
            </div>
            <?php else: ?>

            <!-- Tabla DataTables -->
            <div class="card mb-3">
                <div class="card-body p-3">
                    <table class="table wl-table table-hover mb-0 w-100" id="wp-table">
                        <thead><tr>
                            <th>status</th><th>name</th><th>domain</th><th>verification</th>
                            <th>server</th><th>layer</th><th>active</th><th></th>
                        </tr></thead>
                        <tbody id="wp-tbody">
                        <?php foreach ($wake_proxies as $wp):
                            $layer = !empty($wp['docker_container']) ? 3 : (!empty($wp['guest_vmid']) ? 2 : 1);
                            $layerLabel = ['','Host','Host + Guest','Host + Guest + Docker'][$layer];
                            $layerColor = ['','var(--text-muted)','var(--blue)','var(--amber)'][$layer];
                            $wpDomain = htmlspecialchars($wp['domain'] ?? '');
                            $wpSrvId  = intval($wp['server_id'] ?? 0);
                            $wpSrvHn  = htmlspecialchars($wp['srv_hostname'] ?? '');
                            $wpGuest  = $wp['guest_vmid'] ? ' <span style="color:var(--text-dim)">/ vmid '.intval($wp['guest_vmid']).'</span>' : '';
                        ?>
                        <tr id="wp-row-<?= $wp['id'] ?>">
                            <td data-order="0"><span class="badge bg-secondary" id="wp-status-<?= $wp['id'] ?>">—</span></td>
                            <td class="fw-medium"><?= htmlspecialchars($wp['name']) ?></td>
                            <td><?php if ($wpDomain): ?><a href="https://<?= $wpDomain ?>" target="_blank" rel="noopener" style="font-family:monospace;font-size:.82rem"><?= $wpDomain ?></a><?php else: ?><span style="color:var(--text-dim)">—</span><?php endif; ?></td>
                            <td><span style="font-family:monospace;font-size:.82rem"><?= htmlspecialchars($wp['dest_protocol'].'://'.$wp['dest_ip'].':'.$wp['dest_port']) ?></span></td>
                            <td><?php if ($wpSrvId): ?><a href="#" onclick="event.preventDefault();sidebarNav(document.getElementById('sb-srv-<?= $wpSrvId ?>'),'htab-srv-<?= $wpSrvId ?>')" style="color:var(--blue)"><?= $wpSrvHn ?></a><?php else: ?><?= $wpSrvHn ?><?php endif; ?><?= $wpGuest ?></td>
                            <td><span class="wp-layer-badge" style="color:<?= $layerColor ?>"><?= $layerLabel ?></span></td>
                            <td>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                        <?= $wp['active'] ? 'checked' : '' ?>
                                        onchange="toggleWakeProxy(<?= $wp['id'] ?>, this.checked)">
                                </div>
                            </td>
                            <td>
                                <button class="btn btn-outline-secondary btn-sm py-0 px-2" title="Edit proxy"
                                    onclick="openWakeProxyModal(<?= htmlspecialchars(json_encode($wp), ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-outline-danger btn-sm py-0 px-2 ms-1" title="Delete proxy"
                                    onclick="confirmDeleteWakeProxy(<?= $wp['id'] ?>, '<?= htmlspecialchars($wp['name'], ENT_QUOTES) ?>')"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Accordion: SSH Key + NPM Config -->
            <div class="accordion wp-setup-acc" id="wp-setup-accordion">

                <!-- Token -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button"
                                data-bs-toggle="collapse" data-bs-target="#wp-acc-token">
                            <i class="bi bi-shield-lock-fill wp-acc-icon" style="color:var(--amber)"></i>
                            <span class="wp-acc-title">Auth token</span>
                            <span class="wp-acc-sub">included in the NPM block to authenticate requests</span>
                        </button>
                    </h2>
                    <div id="wp-acc-token" class="accordion-collapse collapse">
                        <div class="accordion-body">
                            <p style="font-size:.75rem;color:var(--text-muted);margin-bottom:12px">
                                This token is sent by NPM as the <code>X-Wake-Proxy-Token</code> header. WakeLab validates it before processing any wake request.
                                If you regenerate it, update the NPM block in all your Proxy Hosts.
                            </p>
                            <div style="background:var(--bg-deep);border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px;display:flex;align-items:center;gap:10px">
                                <i class="bi bi-shield-lock-fill" style="color:var(--amber);font-size:16px;flex-shrink:0"></i>
                                <code id="wp-token-display" style="flex:1;font-size:.75rem;color:var(--text);word-break:break-all;background:none;padding:0"><?= htmlspecialchars($npmWpToken) ?></code>
                                <button class="btn btn-sm btn-outline-secondary" style="flex-shrink:0;white-space:nowrap" onclick="copyWpToken()" title="Copy token">
                                    <i class="bi bi-copy" id="wp-token-copy-icon"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" style="flex-shrink:0;white-space:nowrap" onclick="regenerateWpToken()" title="Regenerate token">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NPM Config -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button"
                                data-bs-toggle="collapse" data-bs-target="#wp-acc-npm">
                            <i class="bi bi-diagram-3-fill wp-acc-icon" style="color:var(--blue)"></i>
                            <span class="wp-acc-title">Nginx Proxy Manager</span>
                            <span class="wp-acc-sub">Advanced block in each Proxy Host</span>
                        </button>
                    </h2>
                    <div id="wp-acc-npm" class="accordion-collapse collapse">
                        <div class="accordion-body">
                            <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:12px">
                                In each NPM Proxy Host, open the <b>Advanced</b> tab and paste this block.<br>
                                NPM keeps pointing to the real service — WakeLab only intercepts when it doesn't respond (502/503/504).
                            </p>

                            <div style="position:relative">
                                <pre id="npm-block" class="script-box" style="font-size:.78rem;max-height:none;padding-right:70px">proxy_intercept_errors on;
error_page 502 503 504 =200 /_wl/wake-proxy.php;

location /_wl/ {
    proxy_pass       http://&lt;IP&gt;:<?= htmlspecialchars($_wlPort) ?>/;
    proxy_set_header Host               $host;
    proxy_set_header X-Forwarded-Proto  $scheme;
    proxy_set_header X-Original-URI     $request_uri;
    proxy_set_header X-WakeLab-Prefix  "/_wl";
    proxy_set_header X-Wake-Proxy-Token "<?= htmlspecialchars($npmWpToken) ?>";
}</pre>
                                <button onclick="copyNpmBlock()" title="Copy" style="position:absolute;top:8px;right:8px;background:var(--bg-hover);border:1px solid var(--border);border-radius:4px;padding:3px 8px;font-size:11px;color:var(--text-muted);cursor:pointer">
                                    <i class="bi bi-copy" id="npm-copy-icon"></i>
                                </button>
                            </div>

                            <div style="margin-top:10px;font-size:.75rem;color:var(--text-dim);display:flex;flex-direction:column;gap:4px">
                                <div>① Replace <code style="color:var(--amber)">&lt;IP&gt;</code> with WakeLab's server IP, e.g. <code>192.168.1.10</code>. <strong style="color:var(--text)">⚠ Use the internal IP — never the public domain. NPM must reach WakeLab directly on the local network.</strong></div>
                                <div>② The token is already included. If you regenerate it, update this block in all your Proxy Hosts.</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /accordion -->
        </div>

        <!-- ══ MODAL WAKE PROXY ════════════════════════════════════════ -->
        <div class="modal fade" id="wpModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-body">

                        <!-- Header -->
                        <div class="fmodal-header">
                            <div class="fmodal-icon-wrap fmodal-icon-amber" id="wp-modal-icon-wrap">
                                <i class="bi" id="wp-modal-icon"></i>
                            </div>
                            <div>
                                <div class="fmodal-title" id="wp-modal-title">Add Wake Proxy</div>
                                <div class="fmodal-sub">Boot proxy for external services</div>
                            </div>
                        </div>

                        <form onsubmit="saveWakeProxy(event)" id="wp-form">
                        <input type="hidden" id="wp-id" value="">

                        <div class="fmodal-field">
                            <label for="wp-name">Name</label>
                            <input type="text" id="wp-name" class="form-control" required placeholder="Jellyfin">
                        </div>
                        <div class="fmodal-field">
                            <label for="wp-domain">Domain</label>
                            <input type="text" id="wp-domain" class="form-control font-mono" required placeholder="jellyfin.blancomariano.com.ar">
                        </div>

                        <!-- ¿Qué despertar? -->
                        <div class="fmodal-section">
                            <div class="fmodal-section-bar"></div>
                            <span class="fmodal-section-label"><i class="bi bi-power me-1"></i>What to wake?</span>
                        </div>
                        <p class="fmodal-hint">Select the server to power on. If the service lives inside an LXC/VM, select that too.</p>

                        <div class="fmodal-field">
                            <label for="wp-server">Server</label>
                            <select id="wp-server" class="form-select" onchange="onWpServerChange(this.value)" required>
                                <option value="">— select —</option>
                                <?php foreach ($servers as $s): ?>
                                <option value="<?= $s['id'] ?>"
                                        data-type="<?= $s['hypervisor_type'] ?>">
                                    <?= htmlspecialchars($s['hostname']) ?>
                                    <? if ($s['hypervisor_type'] !== 'pve'): ?>
                                     (<?= htmlspecialchars($s['hypervisor_type']) ?>)
                                    <? endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="wp-guest-row" class="fmodal-field" style="display:none">
                            <label for="wp-guest">LXC / VM</label>
                            <select id="wp-guest" class="form-select" onchange="onWpGuestChange(this.value)">
                                <option value="">— service directly on host —</option>
                            </select>
                        </div>

                        <div id="wp-docker-row" class="fmodal-field" style="display:none">
                            <label for="wp-docker">Docker container <span class="fmodal-label-hint">(optional, for splash and timeout)</span></label>
                            <input type="text" id="wp-docker" class="form-control font-mono" placeholder="container name">
                        </div>

                        <!-- ¿Cuándo está listo? -->
                        <div class="fmodal-section">
                            <div class="fmodal-section-bar"></div>
                            <span class="fmodal-section-label"><i class="bi bi-check-circle me-1"></i>When is it ready?</span>
                        </div>
                        <p class="fmodal-hint">IP and port where wake-proxy checks that the service responded (may differ from what is configured in NPM).</p>

                        <div class="row g-2 mb-3">
                            <div class="col-3">
                                <label class="fmodal-label" for="wp-proto">Protocol</label>
                                <select id="wp-proto" class="form-select form-select-sm">
                                    <option value="http">HTTP</option>
                                    <option value="https">HTTPS</option>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="fmodal-label" for="wp-ip">IP</label>
                                <input type="text" id="wp-ip" class="form-control form-control-sm" required placeholder="192.168.1.x">
                            </div>
                            <div class="col-4">
                                <label class="fmodal-label" for="wp-port">Port</label>
                                <input type="number" id="wp-port" class="form-control form-control-sm" required min="1" max="65535" placeholder="8096">
                            </div>
                        </div>

                        <div class="fmodal-field">
                            <label class="fmodal-label" for="wp-timeout">Boot timeout (sec)<span id="wp-timeout-hint" class="fmodal-label-hint"></span></label>
                            <input type="number" id="wp-timeout" class="form-control" min="60" value="240">
                        </div>

                        <div class="fmodal-toggle-row">
                            <label class="fmodal-toggle-label" for="wp-active">Active</label>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="wp-active" role="switch" checked>
                            </div>
                        </div>

                        <div class="modal-actions">
                            <button type="submit" class="btn btn-outline-success">Save</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="closeWakeProxyModal()">Cancel</button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ LOGS ════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tab-logs" role="tabpanel">
            <div class="logs-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="logs-icon"><i class="bi bi-journal-text"></i></div>
                    <div>
                        <div class="logs-title">Activity history</div>
                        <div class="logs-sub" id="log-count">loading…</div>
                    </div>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <div class="log-search-wrap">
                        <i class="bi bi-search log-search-icon"></i>
                        <input type="search" id="log-search" class="log-search-input" placeholder="search…" oninput="loadLogs()">
                    </div>
                    <select class="form-select form-select-sm" id="log-filter-srv" onchange="loadLogs()" style="width:130px">
                        <option value="">all hosts</option>
                        <?php foreach ($servers as $srv): ?>
                        <option value="<?= htmlspecialchars($srv['hostname']) ?>"><?= htmlspecialchars($srv['hostname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="btn-group btn-group-sm" id="log-filter-lvl" role="group">
                        <button type="button" class="btn btn-outline-secondary active" data-lvl="" onclick="setLogLvl(this)" title="all" style="font-size:11px;padding:3px 8px">all</button>
                        <button type="button" class="btn btn-outline-secondary" data-lvl="ok"   onclick="setLogLvl(this)" title="ok"><i class="bi bi-check-circle-fill log-lvl-ok"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-lvl="warn" onclick="setLogLvl(this)" title="warn"><i class="bi bi-exclamation-triangle-fill log-lvl-warn"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-lvl="err"  onclick="setLogLvl(this)" title="error"><i class="bi bi-x-circle-fill log-lvl-err"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-lvl="info" onclick="setLogLvl(this)" title="info"><i class="bi bi-info-circle-fill log-lvl-info"></i></button>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm px-2" onclick="loadLogs()" title="Refresh">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>
            <div class="log-entries" id="log-entries"><div class="log-loading"><span class="spinner"></span>loading…</div></div>
        </div>

        <!-- ══ SETTINGS ═══════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tab-push" role="tabpanel">
            <div class="mt-3">

                <!-- ── Settings top-level tabs ────────────────────────── -->
                <div class="notif-tabs mb-3" id="settings-top-tabs">

                    <div class="notif-tab-item active" id="sp-tab-notificaciones" onclick="settingsSwitch('notificaciones')">
                        <div class="notif-tab-btn">
                            <span class="notif-tab-icon-wrap notif-icon-push"><i class="bi bi-bell-fill"></i></span>
                            <span class="notif-tab-name">Notifications</span>
                        </div>
                    </div>

                    <div class="notif-tab-item" id="sp-tab-sistema" onclick="settingsSwitch('sistema')">
                        <div class="notif-tab-btn">
                            <span class="notif-tab-icon-wrap" style="color:#8b949e"><i class="bi bi-sliders"></i></span>
                            <span class="notif-tab-name">System</span>
                        </div>
                    </div>

                    <?php /* V2 — Tab UPS deshabilitado temporalmente
                    <div class="notif-tab-item" id="sp-tab-ups" onclick="settingsSwitch('ups')">
                        <div class="notif-tab-btn">
                            <span class="notif-tab-icon-wrap" style="color:#f0a500"><i class="bi bi-lightning-charge-fill"></i></span>
                            <span class="notif-tab-name">UPS</span>
                        </div>
                    </div>
                    */ ?>

                    <div class="notif-tab-item" id="sp-tab-cuenta" onclick="settingsSwitch('cuenta')">
                        <div class="notif-tab-btn">
                            <span class="notif-tab-icon-wrap" style="color:#8b949e"><i class="bi bi-person-fill"></i></span>
                            <span class="notif-tab-name">Account</span>
                        </div>
                    </div>

                </div>

                <!-- ════ Panel: Notificaciones ═══════════════════════════ -->
                <div id="sp-panel-notificaciones">

                <!-- ── Toggle global ───────────────────────────────────── -->
                <div class="card mb-3" id="notif-global-card">
                    <div class="card-body d-flex align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="notif-panel-icon notif-icon-push" style="width:32px;height:32px;font-size:14px"><i class="bi bi-bell-fill"></i></span>
                            <div>
                                <div style="font-size:13px;font-weight:600;color:var(--text)">Notifications</div>
                                <div style="font-size:11px;color:var(--text-dim)">Enable or mute all channels at once</div>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="notif-global-toggle" style="width:2.4em;height:1.2em"
                                   onchange="saveNotifyGlobal('enabled', this.checked)">
                        </div>
                    </div>
                </div>

                <!-- ── Eventos (qué notificar) ────────────────────────── -->
                <div class="card mb-3" id="notif-events-card">
                    <div class="card-body">
                        <div class="sec-label mb-3">What to notify?</div>
                        <div class="nevt-grid">

                            <label class="nevt-row">
                                <div class="nevt-info">
                                    <span class="nevt-dot" style="background:#f85149"></span>
                                    <div>
                                        <div class="nevt-name">Server offline</div>
                                        <div class="nevt-desc">When a host stops responding to ping</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input nevt-input" type="checkbox" id="nevt-server_down"
                                           onchange="saveNotifyEvents()" checked>
                                </div>
                            </label>

                            <label class="nevt-row">
                                <div class="nevt-info">
                                    <span class="nevt-dot" style="background:#3fb950"></span>
                                    <div>
                                        <div class="nevt-name">Server online</div>
                                        <div class="nevt-desc">When a host comes back online</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input nevt-input" type="checkbox" id="nevt-server_up"
                                           onchange="saveNotifyEvents()" checked>
                                </div>
                            </label>

                            <label class="nevt-row">
                                <div class="nevt-info">
                                    <span class="nevt-dot" style="background:#e3b341"></span>
                                    <div>
                                        <div class="nevt-name">Schedule executed</div>
                                        <div class="nevt-desc">Automatic boot or shutdown by schedule</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input nevt-input" type="checkbox" id="nevt-schedule"
                                           onchange="saveNotifyEvents()" checked>
                                </div>
                            </label>

                            <label class="nevt-row">
                                <div class="nevt-info">
                                    <span class="nevt-dot" style="background:#79c0ff"></span>
                                    <div>
                                        <div class="nevt-name">Idle shutdown</div>
                                        <div class="nevt-desc">Automatic shutdown due to detected inactivity</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input nevt-input" type="checkbox" id="nevt-idle"
                                           onchange="saveNotifyEvents()" checked>
                                </div>
                            </label>

                            <label class="nevt-row">
                                <div class="nevt-info">
                                    <span class="nevt-dot" style="background:#f78166"></span>
                                    <div>
                                        <div class="nevt-name">Host didn't boot</div>
                                        <div class="nevt-desc">Wake Proxy exhausted the boot timeout without a response</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input nevt-input" type="checkbox" id="nevt-wake_timeout"
                                           onchange="saveNotifyEvents()" checked>
                                </div>
                            </label>

                            <label class="nevt-row">
                                <div class="nevt-info">
                                    <span class="nevt-dot" style="background:#8b949e"></span>
                                    <div>
                                        <div class="nevt-name">Guest in unknown state</div>
                                        <div class="nevt-desc">VM or LXC in unknown state for more than X minutes</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input nevt-input" type="checkbox" id="nevt-guest_unknown"
                                           onchange="saveNotifyEvents()" checked>
                                </div>
                            </label>

                            <label class="nevt-row nevt-row-last">
                                <div class="nevt-info">
                                    <span class="nevt-dot" style="background:#d29922"></span>
                                    <div>
                                        <div class="nevt-name">Errors</div>
                                        <div class="nevt-desc">Failures in scripts, SSH, hypervisor APIs</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input nevt-input" type="checkbox" id="nevt-error"
                                           onchange="saveNotifyEvents()" checked>
                                </div>
                            </label>

                        </div>

                        <!-- Configuración avanzada en fila -->
                        <div class="nevt-advanced">
                            <div class="nevt-adv-item">
                                <span class="nevt-adv-label">Batching</span>
                                <input type="number" id="notif-down-delay" class="form-control form-control-sm font-mono"
                                       min="0" max="300" style="width:60px" placeholder="30"
                                       onchange="saveNotifyTimings()">
                                <span class="nevt-adv-unit">sec</span>
                            </div>
                            <div class="nevt-adv-sep"></div>
                            <div class="nevt-adv-item">
                                <span class="nevt-adv-label">Unknown threshold</span>
                                <input type="number" id="notif-unknown-min" class="form-control form-control-sm font-mono"
                                       min="1" max="120" style="width:60px" placeholder="10"
                                       onchange="saveNotifyTimings()">
                                <span class="nevt-adv-unit">min</span>
                            </div>
                            <span id="nevt-saved-badge" class="nevt-saved" style="display:none">
                                <i class="bi bi-check-circle-fill"></i> saved
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ── Canal tabs ──────────────────────────────────────── -->
                <div class="notif-tabs mb-3" id="notif-channels-wrap">

                    <div class="notif-tab-item active" id="nt-tab-push" onclick="ntSwitch('push')">
                        <div class="notif-tab-btn">
                            <span class="notif-tab-icon-wrap notif-icon-push"><i class="bi bi-bell-fill"></i></span>
                            <span class="notif-tab-name">Push</span>
                        </div>
                        <div class="form-check form-switch mb-0" onclick="event.stopPropagation()">
                            <input class="form-check-input" type="checkbox" id="push-enabled"
                                   onchange="toggleNotifChannel('push', this.checked)">
                        </div>
                    </div>

                    <div class="notif-tab-item" id="nt-tab-tg" onclick="ntSwitch('tg')">
                        <div class="notif-tab-btn">
                            <span class="notif-tab-icon-wrap notif-icon-tg"><i class="bi bi-telegram"></i></span>
                            <span class="notif-tab-name">Telegram</span>
                        </div>
                        <div class="form-check form-switch mb-0" onclick="event.stopPropagation()">
                            <input class="form-check-input" type="checkbox" id="tg-enabled"
                                   onchange="toggleNotifChannel('telegram', this.checked)">
                        </div>
                    </div>

                    <div class="notif-tab-item" id="nt-tab-email" onclick="ntSwitch('email')">
                        <div class="notif-tab-btn">
                            <span class="notif-tab-icon-wrap notif-icon-email"><i class="bi bi-envelope-fill"></i></span>
                            <div>
                                <span class="notif-tab-name">Email</span>
                                <div style="font-size:10px;color:var(--amber);line-height:1.2;margin-top:1px"><i class="bi bi-key-fill me-1"></i>Account recovery</div>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-0" onclick="event.stopPropagation()">
                            <input class="form-check-input" type="checkbox" id="email-enabled"
                                   onchange="toggleNotifChannel('email', this.checked)">
                        </div>
                    </div>

                    <div class="notif-tab-item" id="nt-tab-tpl" onclick="ntSwitch('tpl')">
                        <div class="notif-tab-btn">
                            <span class="notif-tab-icon-wrap notif-icon-tpl"><i class="bi bi-stars"></i></span>
                            <span class="notif-tab-name">AI + Templates</span>
                        </div>
                    </div>

                </div>

                <!-- ── Panel: Push ─────────────────────────────────────── -->
                <div class="card mb-3 notif-panel" id="nt-panel-push">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="notif-panel-icon notif-icon-push"><i class="bi bi-bell-fill"></i></span>
                            <div>
                                <div class="push-hdr-title">Push Notifications</div>
                                <div class="push-hdr-sub">Alerts on your phone even when the app is closed · <strong><u>Requires HTTPS</u></strong> and PHP 8.1+</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span id="push-status-text" class="push-status-txt" style="font-size:12px">Checking…</span>
                            <button id="push-subscribe-btn" class="btn btn-sm btn-outline-primary"
                                    onclick="togglePushSubscription()">Enable</button>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid var(--border-sub)">
                            <span style="font-size:11px;color:var(--text-dim)">
                                <i class="bi bi-phone me-1"></i>Subscribed devices: <strong id="push-device-count">—</strong>
                            </span>
                            <button class="btn btn-sm btn-outline-secondary" onclick="sendTestPush()">
                                <i class="bi bi-send me-1"></i>Test
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Panel: Telegram ─────────────────────────────────── -->
                <div class="card mb-3 notif-panel" id="nt-panel-tg" style="display:none">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="notif-panel-icon notif-icon-tg"><i class="bi bi-telegram"></i></span>
                            <div>
                                <div class="push-hdr-title">Telegram Bot</div>
                                <div class="push-hdr-sub">Messages via Telegram Bot API</div>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" style="font-size:12px;color:var(--text-muted)">
                                <i class="bi bi-key me-1"></i>Bot Token
                            </label>
                            <input type="text" class="form-control form-control-sm font-mono" id="tg-token"
                                   placeholder="123456789:ABCDEFGHabcdefgh..."
                                   oninput="_debounce('tg', saveTelegramSettings)">
                        </div>
                        <div class="mb-0">
                            <label class="form-label" style="font-size:12px;color:var(--text-muted)">
                                <i class="bi bi-hash me-1"></i>Chat ID
                            </label>
                            <input type="text" class="form-control form-control-sm font-mono" id="tg-chat-id"
                                   placeholder="-100123456789"
                                   oninput="_debounce('tg', saveTelegramSettings)">
                            <div class="info-box mt-2" style="font-size:11px">
                                <i class="bi bi-info-circle me-1"></i>
                                Send a message to your bot →
                                <code style="font-size:10px">api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code>
                                → copy the <code>chat.id</code>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-3 pt-2" style="border-top:1px solid var(--border-sub)">
                            <button class="btn btn-sm btn-outline-secondary" onclick="testTelegram()">
                                <i class="bi bi-send me-1"></i>Test
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Panel: Email ────────────────────────────────────── -->
                <div class="card mb-3 notif-panel" id="nt-panel-email" style="display:none">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="notif-panel-icon notif-icon-email"><i class="bi bi-envelope-fill"></i></span>
                            <div>
                                <div class="push-hdr-title">Email</div>
                                <div class="push-hdr-sub">Alerts by email</div>
                            </div>
                        </div>

                        <!-- Estado SMTP -->
                        <div id="email-smtp-status-banner" style="display:flex;align-items:center;gap:10px;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;border:1px solid var(--border-sub);background:var(--bg-deep)">
                            <i class="bi bi-circle-fill" id="email-smtp-status-dot" style="font-size:8px;color:var(--text-dim)"></i>
                            <span id="email-smtp-status-txt" style="color:var(--text-muted)">Checking SMTP…</span>
                            <a href="#" onclick="settingsSwitch('cuenta');return false;" style="margin-left:auto;font-size:11px;color:var(--blue);text-decoration:none">
                                <i class="bi bi-gear me-1"></i>Configure SMTP
                            </a>
                        </div>

                        <div class="mb-0">
                            <label class="form-label" style="font-size:12px;color:var(--text-muted)">
                                <i class="bi bi-send me-1"></i>Recipient (to)
                            </label>
                            <input type="email" class="form-control form-control-sm font-mono" id="email-to"
                                   placeholder="tu@email.com"
                                   oninput="_debounce('email', saveEmailSettings)">
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-3 pt-2" style="border-top:1px solid var(--border-sub)">
                            <button class="btn btn-sm btn-outline-secondary" onclick="testEmail()">
                                <i class="bi bi-send me-1"></i>Test
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Panel: Mensajes / IA ───────────────────────────── -->
                <div class="card mb-3 notif-panel" id="nt-panel-tpl" style="display:none">
                    <div class="card-body">

                        <!-- ── Cabecera IA ──────────────────────────────── -->
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="notif-panel-icon" style="background:linear-gradient(135deg,#a371f7 0%,#7c3aed 100%);color:#fff"><i class="bi bi-stars"></i></span>
                                <div>
                                    <div class="push-hdr-title">AI-generated messages</div>
                                    <div class="push-hdr-sub">Generates contextual messages · Falls back to templates on failure</div>
                                </div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="ai-enabled" onchange="saveAiEnabled(this)">
                            </div>
                        </div>

                        <!-- ── Config IA ────────────────────────────────── -->
                        <div id="ai-config-body">
                            <div class="form-row mb-2">
                                <span class="form-label">Provider</span>
                                <select id="ai-provider" class="form-control form-control-sm" style="max-width:220px" onchange="updateAiPlaceholder(this.value); _debounce('ai', saveAiConfig, 0)">
                                    <option value="openai">OpenAI</option>
                                    <option value="anthropic">Anthropic (Claude)</option>
                                    <option value="gemini">Google Gemini</option>
                                </select>
                            </div>
                            <div class="form-row mb-2">
                                <span class="form-label">Model</span>
                                <input id="ai-model" class="form-control form-control-sm" placeholder="gpt-4o-mini  /  claude-haiku-4-5" style="max-width:260px"
                                       oninput="_debounce('ai', saveAiConfig)">
                            </div>
                            <div class="form-row mb-2">
                                <span class="form-label">API Key</span>
                                <input id="ai-api-key" type="password" class="form-control form-control-sm" autocomplete="new-password" placeholder="sk-…  /  sk-ant-…" style="max-width:320px"
                                       oninput="_debounce('ai', saveAiConfig)">
                            </div>

                            <!-- ── Personalización ─────────────────── -->
                            <div style="border-top:1px solid var(--border-sub);margin:14px 0 10px;padding-top:10px">
                                <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-dim);margin-bottom:10px">Customization</div>

                                <!-- Toggles en grilla -->
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;margin-bottom:12px">
                                    <label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:12px;color:var(--text-muted)">
                                        <input type="checkbox" id="ai-use-emojis" class="form-check-input mt-0" onchange="_debounce('ai', saveAiConfig, 0)">
                                        <span>Use emojis</span>
                                    </label>
                                    <label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:12px;color:var(--text-muted)">
                                        <input type="checkbox" id="ai-highlight" class="form-check-input mt-0" onchange="_debounce('ai', saveAiConfig, 0)">
                                        <span>Highlight host / IP / time</span>
                                    </label>
                                    <label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:12px;color:var(--text-muted)">
                                        <input type="checkbox" id="ai-no-repeat" class="form-check-input mt-0" onchange="_debounce('ai', saveAiConfig, 0)">
                                        <span>Vary messages</span>
                                    </label>
                                    <div class="d-flex align-items-center gap-2">
                                        <span style="font-size:12px;color:var(--text-muted)">Tone</span>
                                        <select id="ai-tone" class="form-control form-control-sm" style="max-width:120px;font-size:11px" onchange="_debounce('ai', saveAiConfig, 0)">
                                            <option value="informal">Informal</option>
                                            <option value="formal">Formal</option>
                                        </select>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span style="font-size:12px;color:var(--text-muted)">Language</span>
                                        <select id="ai-language" class="form-control form-control-sm" style="max-width:130px;font-size:11px" onchange="_debounce('ai', saveAiConfig, 0)">
                                            <option value="en">English</option>
                                            <option value="es">Spanish</option>
                                            <option value="pt">Portuguese</option>
                                            <option value="fr">French</option>
                                            <option value="de">German</option>
                                            <option value="it">Italian</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Contexto extra -->
                                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Homelab context <span style="color:var(--text-dim)">(optional)</span></div>
                                <textarea id="ai-extra-context" class="form-control form-control-sm" rows="3"
                                          style="font-size:11px;resize:vertical;line-height:1.6"
                                          placeholder="E.g.: I have 3 Proxmox servers at home, a NAS with TrueNAS and an offsite backup. srv-backup is critical. I prefer direct, to-the-point messages."
                                          oninput="_debounce('ai', saveAiConfig)"></textarea>

                                <!-- Info: contexto enviado automáticamente -->
                                <div class="info-box mt-2" style="font-size:11px">
                                    <span style="color:var(--text-dim)">The AI automatically receives: event, hostname, IP, <b>cause</b> (you shut it down / schedule / idle / unexpected), date and time.</span>
                                </div>
                            </div>

                            <!-- Resultado del test -->
                            <div id="ai-test-result" style="display:none" class="info-box mt-2 mb-2">
                                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Preview — <i>pve01 was manually shut down</i>:</div>
                                <div id="ai-test-msg" style="font-size:12px;line-height:1.7;color:var(--text-main)"></div>
                                <div id="ai-test-meta" style="font-size:10px;color:var(--text-dim);margin-top:5px"></div>
                            </div>

                            <div class="d-flex gap-2 mt-2 pt-2" style="border-top:1px solid var(--border-sub)">
                                <button class="btn btn-sm btn-outline-secondary" onclick="testAiConfig(this)">
                                    <i class="bi bi-play me-1"></i>Test
                                </button>
                            </div>
                        </div>

                        <!-- ── Divider ───────────────────────────────────── -->
                        <div class="d-flex align-items-center gap-2 mt-4 mb-3">
                            <hr style="flex:1;border-color:var(--border-sub);margin:0">
                            <span style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim)">Templates · fallback when AI is off or fails</span>
                            <hr style="flex:1;border-color:var(--border-sub);margin:0">
                        </div>

                        <!-- ── Plantillas ────────────────────────────────── -->
                        <?php
                        $tplDefs = [
                            'server_down' => ['🔴','Server offline'],
                            'server_up'   => ['🟢','Server online'],
                            'schedule'    => ['🕐','Schedule executed'],
                            'idle'        => ['💤','Idle shutdown'],
                            'error'       => ['❌','Error'],
                        ];
                        foreach ($tplDefs as $key => [$ico, $label]): ?>
                        <div class="mb-3">
                            <label class="form-label mb-1" style="font-size:12px;color:var(--text-muted)"><?= $ico ?> <?= $label ?></label>
                            <textarea class="form-control form-control-sm font-mono tpl-field"
                                      id="tpl-<?= $key ?>" data-event="<?= $key ?>"
                                      rows="5" style="resize:vertical;font-size:11px;line-height:1.6"
                                      placeholder="Loading…"
                                      oninput="_debounce('tpl', saveTemplates)"></textarea>
                        </div>
                        <?php endforeach; ?>

                        <!-- Placeholder reference -->
                        <div class="info-box mt-2" style="font-size:11px">
                            <div style="font-weight:600;margin-bottom:6px;color:var(--text-muted)">Available placeholders</div>
                            <div style="display:grid;grid-template-columns:auto 1fr;gap:3px 12px;line-height:1.8">
                                <code>{hostname}</code><span style="color:var(--text-dim)">Server name — e.g.: <em>pve01</em></span>
                                <code>{ip}</code><span style="color:var(--text-dim)">Server IP — e.g.: <em>192.168.1.10</em></span>
                                <code>{datetime}</code><span style="color:var(--text-dim)">Date and time — e.g.: <em>02/05/2026 14:35</em></span>
                                <code>{time}</code><span style="color:var(--text-dim)">Time only — e.g.: <em>14:35</em></span>
                                <code>{saludo}</code><span style="color:var(--text-dim)">Good morning / Good afternoon / Good evening</span>
                                <code>{momento}</code><span style="color:var(--text-dim)">in the morning / at midday / in the afternoon / at dawn</span>
                                <code>{title}</code><span style="color:var(--text-dim)">Event title</span>
                                <code>{body}</code><span style="color:var(--text-dim)">Event detail</span>
                            </div>
                            <div style="margin-top:8px;padding-top:6px;border-top:1px solid var(--border-sub);color:var(--text-dim)">
                                Separate variants with <code>---</code> on its own line · One is chosen at random
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3 pt-2" style="border-top:1px solid var(--border-sub)">
                            <button class="btn btn-sm btn-outline-secondary" onclick="resetTemplates(this)">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Defaults
                            </button>
                        </div>
                    </div>
                </div>


                </div><!-- /.sp-panel-notificaciones -->

                <!-- ════ Panel: Sistema ══════════════════════════════════ -->
                <div id="sp-panel-sistema" style="display:none">

                    <!-- Sub-tabs de Sistema -->
                    <div class="notif-tabs mb-3">
                        <div class="notif-tab-item active" id="sys-tab-general" onclick="sysSwitch('general')">
                            <div class="notif-tab-btn">
                                <span class="notif-tab-icon-wrap" style="color:#58a6ff"><i class="bi bi-gear"></i></span>
                                <span class="notif-tab-name">General</span>
                            </div>
                        </div>
                        <div class="notif-tab-item" id="sys-tab-tiempos" onclick="sysSwitch('tiempos')">
                            <div class="notif-tab-btn">
                                <span class="notif-tab-icon-wrap" style="color:#79c0ff"><i class="bi bi-clock-history"></i></span>
                                <span class="notif-tab-name">Timings</span>
                            </div>
                        </div>
                        <div class="notif-tab-item" id="sys-tab-ui" onclick="sysSwitch('ui')">
                            <div class="notif-tab-btn">
                                <span class="notif-tab-icon-wrap" style="color:#3fb950"><i class="bi bi-display"></i></span>
                                <span class="notif-tab-name">Interface</span>
                            </div>
                        </div>
                        <div class="notif-tab-item" id="sys-tab-wakeproxy" onclick="sysSwitch('wakeproxy')">
                            <div class="notif-tab-btn">
                                <span class="notif-tab-icon-wrap" style="color:#f0883e"><i class="bi bi-lightning-charge"></i></span>
                                <span class="notif-tab-name">Wake Proxy</span>
                            </div>
                        </div>
                        <div class="notif-tab-item" id="sys-tab-admin" onclick="sysSwitch('admin')">
                            <div class="notif-tab-btn">
                                <span class="notif-tab-icon-wrap" style="color:#8b949e"><i class="bi bi-wrench-adjustable"></i></span>
                                <span class="notif-tab-name">Admin</span>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-panel: General -->
                    <div id="sys-panel-general">

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="sec-label">Base URL</div>
                                        <p style="font-size:11px;color:var(--text-muted);margin:6px 0 10px">Public address of WakeLab — used in emails, idle scripts, etc.</p>
                                        <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-link-45deg me-1"></i>WakeLab URL</label>
                                        <input type="url" id="cfg-wakelab-url" class="form-control form-control-sm font-mono"
                                               placeholder="https://wakelab.tudominio.com"
                                               oninput="_debounce('wakelab-url', saveWakelabUrl)">
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="sec-label">Global SSH</div>
                                        <p style="font-size:11px;color:var(--text-muted);margin:6px 0 10px">Default values inherited by all hosts.</p>
                                        <div class="row g-2">
                                            <div class="col-8">
                                                <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-person me-1"></i>User</label>
                                                <input type="text" id="cfg-ssh-user" class="form-control form-control-sm font-mono" placeholder="root" value="<?= htmlspecialchars($sshDefaultUser) ?>"
                                                       oninput="_debounce('global-ssh', saveGlobalSSH)">
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-plug me-1"></i>Port</label>
                                                <input type="number" id="cfg-ssh-port" class="form-control form-control-sm font-mono" min="1" max="65535" placeholder="22" value="<?= htmlspecialchars($sshDefaultPort) ?>"
                                                       oninput="_debounce('global-ssh', saveGlobalSSH)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mt-3">
                            <div class="card-body">
                                <div class="sec-label">Timezone</div>
                                <div class="row g-0">
                                    <div class="col-12 col-md-6">
                                        <div class="push-evt-row" style="border-bottom:none;padding-right:16px">
                                            <div class="push-evt-info">
                                                <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-hdd-stack"></i></span>
                                                <div>
                                                    <span class="push-evt-label">Server TZ</span>
                                                    <div style="font-size:11px;color:var(--text-dim)">Where the equipment is located</div>
                                                </div>
                                            </div>
                                            <select class="form-select form-select-sm" id="ui-timezone-server" style="max-width:180px" onchange="saveServerTz(this.value)">
                                                <option value="">— not configured —</option>
                                                <option value="America/Argentina/Buenos_Aires" <?= $serverTimezone==='America/Argentina/Buenos_Aires'?'selected':'' ?>>Argentina (BUE)</option>
                                                <option value="America/New_York" <?= $serverTimezone==='America/New_York'?'selected':'' ?>>New York (EST)</option>
                                                <option value="America/Chicago" <?= $serverTimezone==='America/Chicago'?'selected':'' ?>>Chicago (CST)</option>
                                                <option value="America/Denver" <?= $serverTimezone==='America/Denver'?'selected':'' ?>>Denver (MST)</option>
                                                <option value="America/Los_Angeles" <?= $serverTimezone==='America/Los_Angeles'?'selected':'' ?>>Los Angeles (PST)</option>
                                                <option value="America/Sao_Paulo" <?= $serverTimezone==='America/Sao_Paulo'?'selected':'' ?>>São Paulo (BRT)</option>
                                                <option value="Europe/London" <?= $serverTimezone==='Europe/London'?'selected':'' ?>>London (GMT)</option>
                                                <option value="Europe/Paris" <?= $serverTimezone==='Europe/Paris'?'selected':'' ?>>Paris / Madrid (CET)</option>
                                                <option value="Europe/Berlin" <?= $serverTimezone==='Europe/Berlin'?'selected':'' ?>>Berlin (CET)</option>
                                                <option value="Europe/Moscow" <?= $serverTimezone==='Europe/Moscow'?'selected':'' ?>>Moscow (MSK)</option>
                                                <option value="Asia/Dubai" <?= $serverTimezone==='Asia/Dubai'?'selected':'' ?>>Dubai (GST)</option>
                                                <option value="Asia/Kolkata" <?= $serverTimezone==='Asia/Kolkata'?'selected':'' ?>>India (IST)</option>
                                                <option value="Asia/Shanghai" <?= $serverTimezone==='Asia/Shanghai'?'selected':'' ?>>China (CST)</option>
                                                <option value="Asia/Tokyo" <?= $serverTimezone==='Asia/Tokyo'?'selected':'' ?>>Tokyo (JST)</option>
                                                <option value="Australia/Sydney" <?= $serverTimezone==='Australia/Sydney'?'selected':'' ?>>Sydney (AEDT)</option>
                                                <option value="UTC" <?= $serverTimezone==='UTC'?'selected':'' ?>>UTC</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6" style="border-left:1px solid var(--border-sub)">
                                        <div class="push-evt-row" style="border-bottom:none;padding-left:16px">
                                            <div class="push-evt-info">
                                                <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-globe"></i></span>
                                                <div>
                                                    <span class="push-evt-label">My timezone</span>
                                                    <div style="font-size:11px;color:var(--text-dim)">Where you are — changes the display</div>
                                                </div>
                                            </div>
                                            <select class="form-select form-select-sm" id="ui-timezone" style="max-width:180px" onchange="saveUiPref('timezone', this.value)">
                                                <option value="">— same as server —</option>
                                                <option value="America/Argentina/Buenos_Aires">Argentina (BUE)</option>
                                                <option value="America/New_York">New York (EST)</option>
                                                <option value="America/Chicago">Chicago (CST)</option>
                                                <option value="America/Denver">Denver (MST)</option>
                                                <option value="America/Los_Angeles">Los Angeles (PST)</option>
                                                <option value="America/Sao_Paulo">São Paulo (BRT)</option>
                                                <option value="Europe/London">London (GMT)</option>
                                                <option value="Europe/Paris">Paris / Madrid (CET)</option>
                                                <option value="Europe/Berlin">Berlin (CET)</option>
                                                <option value="Europe/Moscow">Moscow (MSK)</option>
                                                <option value="Asia/Dubai">Dubai (GST)</option>
                                                <option value="Asia/Kolkata">India (IST)</option>
                                                <option value="Asia/Shanghai">China (CST)</option>
                                                <option value="Asia/Tokyo">Tokyo (JST)</option>
                                                <option value="Australia/Sydney">Sydney (AEDT)</option>
                                                <option value="UTC">UTC</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /#sys-panel-general -->

                    <!-- Sub-panel: Tiempos -->
                    <div id="sys-panel-tiempos" style="display:none">

                        <div class="card">
                            <div class="card-body">
                                <div class="sec-label">Polling and timeouts</div>
                                <div class="row g-3 mt-1">
                                    <div class="col-6">
                                        <label class="form-label" style="font-size:12px;color:var(--text-muted)">
                                            <i class="bi bi-arrow-repeat me-1"></i>Status polling
                                            <i class="bi bi-info-circle ms-1" style="color:var(--text-dim);cursor:help" data-bs-toggle="tooltip" data-bs-placement="top" title="How often (in seconds) WakeLab checks host status. Lower = more responsive, more network load. Recommended: 30s"></i>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" id="cfg-polling" class="form-control form-control-sm font-mono" min="5" max="3600" placeholder="30" oninput="_debounce('tiempos', saveTiempos)">
                                            <span class="input-group-text" style="background:var(--bg-deep);border-color:var(--border);color:var(--text-dim)">s</span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" style="font-size:12px;color:var(--text-muted)">
                                            <i class="bi bi-clock me-1"></i>Cache TTL
                                            <i class="bi bi-info-circle ms-1" style="color:var(--text-dim);cursor:help" data-bs-toggle="tooltip" data-bs-placement="top" title="How long (in seconds) status responses are cached on the server. Reduces calls to Proxmox/TrueNAS APIs. Recommended: 10s"></i>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" id="cfg-cache-ttl" class="form-control form-control-sm font-mono" min="5" max="600" placeholder="10" oninput="_debounce('tiempos', saveTiempos)">
                                            <span class="input-group-text" style="background:var(--bg-deep);border-color:var(--border);color:var(--text-dim)">s</span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" style="font-size:12px;color:var(--text-muted)">
                                            <i class="bi bi-hdd-network me-1"></i>API Timeout
                                            <i class="bi bi-info-circle ms-1" style="color:var(--text-dim);cursor:help" data-bs-toggle="tooltip" data-bs-placement="top" title="Maximum wait time when querying a host API (Proxmox, TrueNAS, etc.). If the host takes longer, it is marked as not responding. Recommended: 8s"></i>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" id="cfg-api-timeout" class="form-control form-control-sm font-mono" min="2" max="60" placeholder="8" oninput="_debounce('tiempos', saveTiempos)">
                                            <span class="input-group-text" style="background:var(--bg-deep);border-color:var(--border);color:var(--text-dim)">s</span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" style="font-size:12px;color:var(--text-muted)">
                                            <i class="bi bi-wifi me-1"></i>Ping timeout
                                            <i class="bi bi-info-circle ms-1" style="color:var(--text-dim);cursor:help" data-bs-toggle="tooltip" data-bs-placement="top" title="Maximum time for TCP port ping. If the host doesn't respond in this time, it is considered offline. Recommended: 3s"></i>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" id="cfg-ping-timeout" class="form-control form-control-sm font-mono" min="1" max="30" placeholder="3" oninput="_debounce('tiempos', saveTiempos)">
                                            <span class="input-group-text" style="background:var(--bg-deep);border-color:var(--border);color:var(--text-dim)">s</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /#sys-panel-tiempos -->

                    <!-- Sub-panel: Interfaz -->
                    <div id="sys-panel-ui" style="display:none">
                        <div class="card">
                            <div class="card-body">
                                <div class="sec-label">Display preferences</div>

                                <div class="push-evt-row">
                                    <div class="push-evt-info">
                                        <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-eye-slash"></i></span>
                                        <div>
                                            <span class="push-evt-label">Hide offline servers</span>
                                            <div style="font-size:11px;color:var(--text-dim)">Hides the card of powered-off hosts in the dashboard</div>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="ui-hide-offline"
                                               onchange="saveUiPref('hideOffline', this.checked)">
                                    </div>
                                </div>

                                <div class="push-evt-row">
                                    <div class="push-evt-info">
                                        <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-bar-chart-line"></i></span>
                                        <div>
                                            <span class="push-evt-label">Hide metrics</span>
                                            <div style="font-size:11px;color:var(--text-dim)">Hides CPU/RAM/Disk and does not query the server for them</div>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="ui-hide-metrics"
                                               onchange="saveUiPref('hideMetrics', this.checked)">
                                    </div>
                                </div>

                                <div class="push-evt-row">
                                    <div class="push-evt-info">
                                        <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-chevron-bar-contract"></i></span>
                                        <div>
                                            <span class="push-evt-label">Auto-collapse VMs</span>
                                            <div style="font-size:11px;color:var(--text-dim)">If all VMs/LXCs on a host are powered off, collapses the section automatically</div>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="ui-auto-collapse"
                                               onchange="saveUiPref('autoCollapse', this.checked)">
                                    </div>
                                </div>

                                <div class="push-evt-row">
                                    <div class="push-evt-info">
                                        <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-layout-text-window"></i></span>
                                        <span class="push-evt-label">Density</span>
                                    </div>
                                    <select class="form-select form-select-sm" id="ui-density" style="max-width:130px"
                                            onchange="saveUiPref('density', this.value)">
                                        <option value="normal">Normal</option>
                                        <option value="compact">Compact</option>
                                    </select>
                                </div>


                            </div>
                        </div>
                    <!-- Kiosk / Rack URL -->
                    <?php
                        $kioskToken = getSettingFallback($pdo, 'kiosk_token', '');
                        $kioskBase  = rtrim(getSettingFallback($pdo, 'wakelab_base_url', ''), '/');
                        if (!$kioskBase) {
                            $isKioskHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                                         || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
                            $kioskBase = ($isKioskHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                                       . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
                        }
                        $kioskUrl = $kioskBase . '/rack.php' . ($kioskToken ? '?k=' . $kioskToken : '');
                    ?>
                    <div class="card mt-3">
                        <div class="card-body">
                            <div class="sec-label">Rack / Kiosk URL</div>
                            <p style="font-size:11px;color:var(--text-muted);margin:6px 0 10px">
                                Open this URL on a dedicated display — no login required.
                                Keep it private (LAN only).
                            </p>
                            <div class="d-flex gap-2 align-items-center mb-2">
                                <input type="text" id="kiosk-token-input" class="form-control form-control-sm font-mono"
                                       value="<?= htmlspecialchars($kioskToken) ?>" readonly style="flex:1">
                                <button class="btn btn-sm btn-outline-secondary" onclick="genKioskToken()" title="Generate new token">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </div>
                            <?php if ($kioskToken): ?>
                            <div class="d-flex gap-2 align-items-center">
                                <code id="kiosk-url-display" style="font-size:10px;color:var(--text-dim);word-break:break-all;flex:1"><?= htmlspecialchars($kioskUrl) ?></code>
                                <button class="btn btn-sm btn-outline-secondary" onclick="copyKioskUrl()" title="Copy URL">
                                    <i class="bi bi-copy" id="kiosk-copy-icon"></i>
                                </button>
                                <a href="<?= htmlspecialchars($kioskUrl) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Open rack view">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>
                            <?php else: ?>
                            <p style="font-size:11px;color:var(--text-dim)">Generate a token to get the kiosk URL.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div><!-- /#sys-panel-ui -->

                    <!-- Sub-panel: Wake Proxy -->
                    <div id="sys-panel-wakeproxy" style="display:none">
                        <div class="card">
                            <div class="card-body">
                                <div class="sec-label">Behaviour</div>

                                <div class="push-evt-row">
                                    <div class="push-evt-info">
                                        <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-play-circle"></i></span>
                                        <div>
                                            <span class="push-evt-label">Splash</span>
                                            <div style="font-size:11px;color:var(--text-dim)">Waiting screen while waking a service</div>
                                        </div>
                                    </div>
                                    <select class="form-select form-select-sm" id="ui-splash-mode" style="max-width:130px"
                                            onchange="saveWakeSplashMode(this.value)">
                                        <option value="detailed">Detailed</option>
                                        <option value="simple">Simple</option>
                                    </select>
                                </div>

                                <div class="push-evt-row" style="border-bottom:none">
                                    <div class="push-evt-info">
                                        <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-arrow-repeat"></i></span>
                                        <div>
                                            <span class="push-evt-label">Automatic retries</span>
                                            <div style="font-size:11px;color:var(--text-dim)">Attempts before showing an error in the splash</div>
                                        </div>
                                    </div>
                                    <input type="number" class="form-control form-control-sm" id="ui-splash-retries"
                                           style="max-width:80px" min="1" max="10" value="3"
                                           onchange="saveWakeSplashRetries(this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="card mt-3">
                            <div class="card-body">
                                <div class="sec-label">Security</div>
                                <p style="font-size:11px;color:var(--text-muted);margin:6px 0 10px">
                                    Set this header in Nginx Proxy Manager for each proxy entry pointing to WakeLab:
                                </p>
                                <div style="background:var(--bg-deep);border:1px solid var(--border);border-radius:6px;padding:10px 12px;font-size:12px;margin-bottom:12px">
                                    <span style="color:var(--text-dim)">X-Wake-Proxy-Token:</span>
                                    <code style="color:var(--blue);user-select:all;margin-left:6px" id="wp-token-val">loading…</code>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary" onclick="regenWakeProxyToken(this)">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Regenerate token
                                </button>
                            </div>
                        </div>
                    </div><!-- /#sys-panel-wakeproxy -->

                    <!-- Sub-panel: Admin -->
                    <div id="sys-panel-admin" style="display:none">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="sec-label">Logs</div>
                                        <div class="push-evt-row" style="border-bottom:none">
                                            <div class="push-evt-info">
                                                <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-journal-text"></i></span>
                                                <div>
                                                    <span class="push-evt-label">Max retention</span>
                                                    <div style="font-size:11px;color:var(--text-dim)">Events in DB — oldest are removed when limit is exceeded</div>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <select class="form-select form-select-sm" id="cfg-log-retention" style="max-width:90px" onchange="saveLogSettings()">
                                                    <option value="200">200</option>
                                                    <option value="500">500</option>
                                                    <option value="1000" selected>1000</option>
                                                    <option value="2000">2000</option>
                                                    <option value="5000">5000</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-end mt-3 pt-2" style="border-top:1px solid var(--border-sub)">
                                            <button class="btn btn-sm btn-outline-danger" onclick="clearEvents(this)">
                                                <i class="bi bi-trash me-1"></i>Clear full log
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="sec-label">Debug</div>

                                        <div class="push-evt-row">
                                            <div class="push-evt-info">
                                                <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-bug"></i></span>
                                                <div>
                                                    <span class="push-evt-label">Debug mode</span>
                                                    <div style="font-size:11px;color:var(--text-dim)">Shows the Debug accordion on each server</div>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" id="debug-mode-toggle"
                                                       <?= $debugMode ? 'checked' : '' ?>
                                                       onchange="toggleDebugMode(this)">
                                            </div>
                                        </div>

                                        <div class="push-evt-row">
                                            <div class="push-evt-info">
                                                <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-plug"></i></span>
                                                <div>
                                                    <span class="push-evt-label">Debug webhook UPS</span>
                                                    <div style="font-size:11px;color:var(--text-dim)">Logs each incoming request to the webhook (useful for diagnosing Nutify)</div>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" id="webhook-debug-toggle"
                                                       <?= getSettingFallback($pdo, 'webhook_debug', '0') === '1' ? 'checked' : '' ?>
                                                       onchange="fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_setting',key:'webhook_debug',value:this.checked?'1':'0'})})">
                                            </div>
                                        </div>

                                        <div class="push-evt-row" style="border-bottom:none">
                                            <div class="push-evt-info">
                                                <span class="notif-evt-icon" style="color:#8b949e"><i class="bi bi-arrow-clockwise"></i></span>
                                                <div>
                                                    <span class="push-evt-label">Force cache refresh</span>
                                                    <div style="font-size:11px;color:var(--text-dim)">Invalidates the cache and queries immediately</div>
                                                </div>
                                            </div>
                                            <button class="btn btn-sm btn-outline-secondary" onclick="manualSync()">
                                                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Backup / Restore -->
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="sec-label">Backup / Restore</div>
                                        <div class="row g-3 mt-1">
                                            <!-- Export -->
                                            <div class="col-12 col-md-6">
                                                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px">
                                                    <i class="bi bi-download me-1"></i><strong>Export</strong> — downloads a JSON with all servers, schedules, settings and credentials.
                                                </div>
                                                <div class="d-flex gap-2 align-items-center">
                                                    <input type="password" id="cfg-export-pass" class="form-control form-control-sm font-mono"
                                                           placeholder="Password (optional)" autocomplete="new-password" style="max-width:200px">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="cfgExport()">
                                                        <i class="bi bi-download me-1"></i>Export
                                                    </button>
                                                </div>
                                                <div style="font-size:11px;color:var(--text-dim);margin-top:6px">Without password the file is plaintext JSON. With password it's encrypted (AES-256-GCM).</div>
                                            </div>
                                            <!-- Import -->
                                            <div class="col-12 col-md-6" style="border-left:1px solid var(--border-sub);padding-left:20px">
                                                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px">
                                                    <i class="bi bi-upload me-1"></i><strong>Import</strong> — <span style="color:var(--red)">replaces</span> all current config with the backup.
                                                </div>
                                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                                    <input type="file" id="cfg-import-file" accept=".json" class="form-control form-control-sm" style="max-width:220px">
                                                    <input type="password" id="cfg-import-pass" class="form-control form-control-sm font-mono"
                                                           placeholder="Password (if encrypted)" style="max-width:180px">
                                                </div>
                                                <div class="d-flex gap-2 mt-2">
                                                    <button class="btn btn-sm btn-outline-danger" onclick="cfgImport()">
                                                        <i class="bi bi-upload me-1"></i>Import &amp; replace
                                                    </button>
                                                    <span id="cfg-import-status" style="font-size:11px;color:var(--text-dim);align-self:center"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- /#sys-panel-admin -->

                </div>

                <!-- ════ Panel: Cuenta ═══════════════════════════════════ -->
                <div id="sp-panel-cuenta" style="display:none">

                    <!-- Avatar + info de sesión -->
                    <div class="card mb-3">
                        <div class="card-body" style="padding:20px 24px">
                            <div class="d-flex align-items-center gap-4">
                                <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--blue) 0%,#1a4a7a 100%);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:26px;font-weight:700;color:#fff;letter-spacing:-1px;user-select:none">
                                    <?= strtoupper(substr(htmlspecialchars($_SESSION['usuario']), 0, 1)) ?>
                                </div>
                                <div style="min-width:0">
                                    <div style="font-size:17px;font-weight:600;color:var(--text);line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($_SESSION['usuario']) ?></div>
                                    <div style="font-size:12px;color:var(--text-dim);margin-top:2px"><?= htmlspecialchars($_SESSION['email'] ?? 'No email') ?></div>
                                    <div style="margin-top:8px">
                                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--green);background:color-mix(in srgb,var(--green) 12%,transparent);border:1px solid color-mix(in srgb,var(--green) 25%,transparent);border-radius:20px;padding:2px 10px">
                                            <i class="bi bi-circle-fill" style="font-size:7px"></i>Active session
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Col izquierda: datos -->
                        <div class="col-12 col-md-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="sec-label">Login details</div>
                                    <div class="row g-3 mt-1">
                                        <div class="col-12">
                                            <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-person me-1"></i>Username</label>
                                            <input type="text" id="acc-usuario" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($_SESSION['usuario']) ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-envelope me-1"></i>Email</label>
                                            <input type="email" id="acc-email" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Col derecha: contraseña -->
                        <div class="col-12 col-md-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="sec-label">Change password</div>
                                    <div class="row g-3 mt-1">
                                        <div class="col-12">
                                            <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-lock me-1"></i>Current password</label>
                                            <input type="password" id="acc-pass-current" class="form-control form-control-sm" autocomplete="current-password" placeholder="Required to save">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-key me-1"></i>New password</label>
                                            <input type="password" id="acc-pass-new" class="form-control form-control-sm" autocomplete="new-password" placeholder="Leave empty to keep unchanged">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-key-fill me-1"></i>Confirm password</label>
                                            <input type="password" id="acc-pass-confirm" class="form-control form-control-sm" autocomplete="new-password">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button class="btn btn-sm btn-outline-primary" onclick="saveUser(this)">
                            <i class="bi bi-floppy me-1"></i>Save changes
                        </button>
                    </div>

                    <!-- SMTP -->
                    <div class="card mt-3">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between mb-1">
                                <div class="sec-label">SMTP Configuration</div>
                                <span style="font-size:11px;color:var(--text-dim)">Used for alerts and password recovery</span>
                            </div>
                            <div style="display:flex;align-items:flex-start;gap:10px;background:color-mix(in srgb,var(--amber) 10%,transparent);border:1px solid color-mix(in srgb,var(--amber) 30%,transparent);border-radius:8px;padding:10px 14px;margin:10px 0 16px;font-size:12px;color:var(--text-muted)">
                                <i class="bi bi-exclamation-triangle-fill mt-1" style="color:var(--amber);flex-shrink:0"></i>
                                <span>Without SMTP configured you cannot recover the password by email — you would have to do it from the terminal.</span>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-12 col-md-5">
                                    <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-hdd-network me-1"></i>SMTP server</label>
                                    <input type="text" class="form-control form-control-sm font-mono" id="email-smtp-host" placeholder="smtp.gmail.com"
                                           oninput="_debounce('email', saveEmailSettings)">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" style="font-size:12px;color:var(--text-muted)">Port</label>
                                    <input type="number" class="form-control form-control-sm font-mono" id="email-smtp-port" value="587" min="1" max="65535"
                                           oninput="_debounce('email', saveEmailSettings)">
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-shield-lock me-1"></i>Encryption</label>
                                    <select class="form-select form-select-sm" id="email-smtp-secure"
                                            onchange="_debounce('email', saveEmailSettings, 0)">
                                        <option value="tls">STARTTLS (587)</option>
                                        <option value="ssl">SSL/TLS (465)</option>
                                        <option value="none">No encryption (25)</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-person me-1"></i>SMTP user</label>
                                    <input type="text" class="form-control form-control-sm font-mono" id="email-smtp-user" placeholder="user@gmail.com"
                                           oninput="_debounce('email', saveEmailSettings)">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-lock me-1"></i>Password / App password</label>
                                    <input type="password" class="form-control form-control-sm font-mono" id="email-smtp-pass" placeholder="••••••••"
                                           oninput="_debounce('email', saveEmailSettings)">
                                </div>
                                <div class="col-12 col-md-7">
                                    <label class="form-label" style="font-size:12px;color:var(--text-muted)"><i class="bi bi-envelope me-1"></i>Sender (from)</label>
                                    <input type="text" class="form-control form-control-sm font-mono" id="email-from" placeholder="wakelab@tudominio.com"
                                           oninput="_debounce('email', saveEmailSettings)">
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label" style="font-size:12px;color:var(--text-muted)">Name</label>
                                    <input type="text" class="form-control form-control-sm" id="email-from-name" value="WakeLab"
                                           oninput="_debounce('email', saveEmailSettings)">
                                </div>
                            </div>
                            <div class="info-box mt-2 mb-3" style="font-size:11px">
                                <i class="bi bi-google me-1"></i>
                                <strong>Gmail:</strong> enable <em>App passwords</em> (Account → Security → 2-step verification).
                            </div>
                            <div class="d-flex justify-content-end gap-2 pt-2" style="border-top:1px solid var(--border-sub)">
                                <button class="btn btn-sm btn-outline-secondary" onclick="testEmail()">
                                    <i class="bi bi-send me-1"></i>Test
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php /* V2 — Panel UPS deshabilitado temporalmente */ ?>
                <?php /* <script>
                function _upsHeaderEl(){return document.getElementById('ups-custom-headers-val');}
                function _upsSetHeader(v){var h=_upsHeaderEl();if(h)h.textContent='{"X-Webhook-Token": "'+(v||'YOUR_TOKEN')+'"}';}
                function upsTokenInput(v){
                    v=v.trim();
                    _upsSetHeader(v);
                    clearTimeout(window._upsTok);
                    window._upsTok=setTimeout(function(){
                        fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},
                            body:JSON.stringify({action:'update_setting',key:'ups_webhook_token',value:v})});
                    },900);
                }
                function upsTokenGenerate(){
                    var t=Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2);
                    var el=document.getElementById('ups-webhook-token');
                    if(el)el.value=t;
                    _upsSetHeader(t);
                    fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},
                        body:JSON.stringify({action:'update_setting',key:'ups_webhook_token',value:t})});
                }
                function upsTokenCopy(btn){
                    var v=(document.getElementById('ups-webhook-token')||{}).value||'';
                    var i=btn.querySelector('i');
                    _clipboardCopy(v,function(){i.className='bi bi-check-lg';setTimeout(function(){i.className='bi bi-copy';},1800);},function(){});
                }
                </script>
                <?php
                $upsToken   = getSettingFallback($pdo, 'ups_webhook_token', '');
                $upsBaseUrl = rtrim(getSettingFallback($pdo, 'wakelab_base_url', ''), '/');
                if (!$upsBaseUrl) {
                    $isHttps   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                              || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
                    $proto     = $isHttps ? 'https' : 'http';
                    $httpHost  = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $basePath  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
                    $upsBaseUrl = $proto . '://' . $httpHost . $basePath;
                }
                $upsWebhookUrl = $upsBaseUrl . '/webhook.php';
                ?>
                <div id="sp-panel-ups" style="display:none">
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="sec-label mb-3">UPS Webhook (Nutify / NUT)</div>

                            <!-- Token -->
                            <div class="mb-3">
                                <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px">Authentication token</div>
                                <div style="background:var(--bg-deep);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px;display:flex;align-items:center;gap:10px">
                                    <i class="bi bi-shield-lock-fill" style="color:var(--amber);font-size:15px;flex-shrink:0"></i>
                                    <input type="text" id="ups-webhook-token"
                                        style="flex:1;background:none;border:none;outline:none;font-family:monospace;font-size:.75rem;color:var(--text);min-width:0"
                                        value="<?= htmlspecialchars($upsToken) ?>"
                                        placeholder="(no token — generate one)"
                                        oninput="upsTokenInput(this.value)">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" style="flex-shrink:0;white-space:nowrap"
                                        onclick="upsTokenGenerate()">
                                        <i class="bi bi-arrow-repeat me-1"></i>Generate
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" style="flex-shrink:0"
                                        onclick="upsTokenCopy(this)">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>


                            <!-- Config Nutify -->
                            <div class="mb-3">
                                <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px">How to configure in Nutify</div>
                                <div style="display:flex;flex-direction:column;gap:6px;font-size:12px">
                                    <?php
                                    $customHeaderVal = '{"X-Webhook-Token": "' . ($upsToken ?: 'YOUR_TOKEN') . '"}';
                                    $copyFields = [
                                        'URL'            => $upsWebhookUrl,
                                        'Custom Headers' => $customHeaderVal,
                                    ];
                                    $staticFields = [
                                        'Content Type'   => 'application/json',
                                        'Authentication' => 'None (do not use Bearer Token)',
                                        'Verify SSL'     => 'Disable — this is local HTTP, not HTTPS',
                                    ];
                                    foreach (array_merge($copyFields, $staticFields) as $label => $val):
                                        $canCopy = isset($copyFields[$label]);
                                        $isHeaders = ($label === 'Custom Headers');
                                        $valEsc  = htmlspecialchars($val);
                                        $valJs   = htmlspecialchars(json_encode($val), ENT_QUOTES);
                                    ?>
                                    <div style="background:var(--bg-deep);border:1px solid var(--border);border-radius:var(--radius);padding:8px 12px;display:flex;align-items:center;gap:10px">
                                        <span style="font-size:10px;font-weight:700;color:var(--text-dim);min-width:100px;flex-shrink:0"><?= htmlspecialchars($label) ?></span>
                                        <code id="<?= $isHeaders ? 'ups-custom-headers-val' : '' ?>" style="flex:1;font-size:11px;color:var(--text);background:none;padding:0;word-break:break-all"><?= $valEsc ?></code>
                                        <?php if ($canCopy): ?>
                                        <button class="btn btn-sm btn-outline-secondary" style="flex-shrink:0;padding:2px 7px"
                                            onclick="(function(btn){var v=<?= $isHeaders ? "document.getElementById('ups-custom-headers-val').textContent" : $valJs ?>;var i=btn.querySelector('i');_clipboardCopy(v,function(){i.className='bi bi-check-lg';setTimeout(function(){i.className='bi bi-copy'},1800)},function(){});})(this)">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <div style="font-size:11px;color:var(--text-dim);padding:4px 2px">
                                        <i class="bi bi-info-circle me-1"></i>Events to enable in Nutify: <code style="font-size:10px">onbatt</code>, <code style="font-size:10px">lowbatt</code>, <code style="font-size:10px">online</code>.
                                    </div>
                                </div>
                            </div>

                            <!-- Timer -->
                            <div class="form-row" style="border-top:1px solid var(--border-sub);padding-top:14px;margin-top:4px">
                                <span class="form-label">Wait timer <span class="fmodal-label-hint">seconds before shutdown (0 = immediate)</span></span>
                                <input type="number" min="0" max="3600" class="form-control" style="width:90px"
                                    id="ups-delay-sec"
                                    value="<?= intval(getSettingFallback($pdo,'ups_shutdown_delay_sec','0')) ?>"
                                    oninput="_debounce('ups_delay', () => fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_setting',key:'ups_shutdown_delay_sec',value:document.getElementById('ups-delay-sec').value})}))">
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="sec-label mb-1">Last UPS events</div>
                            <div id="ups-events-list" style="font-size:12px;color:var(--text-dim);margin-top:8px">
                                <button class="btn btn-outline-secondary btn-sm" onclick="loadUpsEvents()">Load events</button>
                            </div>
                        </div>
                    </div>
                </div>
                */ ?>

            </div>
        </div>

        </div><!-- /.tab-content -->
        </div><!-- /.content-main -->

        <!-- ══ FOOTER ═══════════════════════════════════════════════════ -->
        <footer class="app-footer">
            <span>WakeLab <span class="app-footer-version">v1.0</span></span>
            <span class="app-footer-sep">·</span>
            <span>Mariano Blanco</span>
            <span class="app-footer-sep">·</span>
            <span><?= date('Y') ?></span>
        </footer>

        </div><!-- /.content-area -->
        </div><!-- /.app-layout -->

        <!-- ══ VM DRAWER (offcanvas) ══════════════════════════════════ -->
        <div class="offcanvas offcanvas-end" id="vmDrawer" tabindex="-1">
            <div class="offcanvas-header">
                <div class="d-flex align-items-center gap-2">
                    <span id="vd-type-badge" class="vm-type">VM</span>
                    <span class="offcanvas-title" id="vd-name">—</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
            </div>
            <div class="offcanvas-body">

                <div class="vm-drawer-section">
                    <div class="vm-drawer-section-title">status</div>
                    <div class="vm-drawer-metrics">
                        <div class="metric metric-row"><span class="metric-label">ID</span><span class="metric-val sm" id="vd-id">—</span></div>
                        <div class="metric metric-row"><span class="metric-label">Status</span><span id="vd-status">—</span></div>
                        <div class="metric metric-row"><span class="metric-label">CPU</span><span class="metric-val blue sm" id="vd-cpu">—</span></div>
                        <div class="metric" style="display:flex;align-items:center;justify-content:center"><span class="metric-val sm" id="vd-vcpu">—</span></div>
                    </div>
                    <div style="font-size:10px;color:var(--text-dim);margin:8px 0 3px">RAM</div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px">
                        <span id="vd-ram-txt">—</span><span id="vd-ram-pct" style="color:var(--blue)">—</span>
                    </div>
                    <div class="prog-wrap" style="height:6px"><div class="prog-bar prog-blue" id="vd-ram-bar" style="width:0%"></div></div>
                    <div style="font-size:10px;color:var(--text-dim);margin:10px 0 3px">Disk</div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px">
                        <span id="vd-dsk-txt">—</span><span id="vd-dsk-pct" style="color:var(--green)">—</span>
                    </div>
                    <div class="prog-wrap" style="height:6px"><div class="prog-bar prog-green" id="vd-dsk-bar" style="width:0%"></div></div>
                </div>

                <div class="vm-drawer-section">
                    <div class="vm-drawer-section-title">actions</div>
                    <div class="vm-drawer-actions" id="vd-actions"></div>
                </div>

                <div class="vm-drawer-section" id="vd-url-section" style="display:none">
                    <div class="vm-drawer-section-title" style="display:flex;align-items:center;justify-content:space-between">
                        web interface
                        <button id="vd-url-toggle-btn" onclick="toggleUrlConfig()" style="background:none;border:none;color:var(--text-dim);font-size:10px;cursor:pointer">configure ▾</button>
                    </div>
                    <div id="vd-url-open-row" style="display:none">
                        <a id="vd-url-open-link" href="#" target="_blank" rel="noopener"
                           class="srv-ext-link d-flex align-items-center gap-1 mt-1"
                           style="font-size:13px;opacity:.8"
                           onclick="event.preventDefault();openGuestUrl()">
                            <i class="bi bi-arrow-up-right"></i> open interface
                        </a>
                    </div>
                    <div id="vd-url-config" class="drawer-schedule" style="display:none;margin-top:8px">
                        <div class="drawer-schedule-row" style="margin-bottom:0">
                            <span class="drawer-schedule-label">URL</span>
                            <input type="text" class="drawer-time-input" id="vd-url-input"
                                placeholder="https://uptime.yourdomain.com or 192.168.1.x:8080"
                                style="width:100%;max-width:200px">
                        </div>
                        <div style="margin-top:10px">
                            <button class="btn btn-outline-success w-100" onclick="saveGuestMeta()">save</button>
                        </div>
                    </div>
                </div>

                <!-- Sección vinculada: visible solo cuando el guest es un host registrado -->
                <div class="vm-drawer-section" id="vd-linked-section" style="display:none">
                    <div class="vm-drawer-section-title" style="display:flex;align-items:center;justify-content:space-between">
                        linked server
                        <button id="vd-linked-goto" style="background:none;border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-size:10px;color:var(--blue);cursor:pointer">go to tab →</button>
                    </div>
                    <div id="vd-linked-info" style="margin-top:8px;font-size:11px;display:flex;flex-direction:column;gap:5px">
                        <!-- poblado por JS -->
                    </div>
                </div>

                <div class="vm-drawer-section" id="vd-section-schedule">
                    <div class="vm-drawer-section-title">boot / shutdown schedule</div>
                    <div style="display:flex;gap:8px;padding:2px 0 4px">
                        <!-- Boot block -->
                        <div style="flex:1;background:color-mix(in srgb,var(--green) 8%,transparent);border:1px solid color-mix(in srgb,var(--green) 15%,transparent);border-radius:10px;padding:10px 12px;transition:opacity .2s,border-color .2s" id="vd-boot-block">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                <div style="display:flex;align-items:center;gap:5px">
                                    <i class="bi bi-sunrise-fill" style="color:var(--green);font-size:12px"></i>
                                    <span style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--green)">Boot</span>
                                </div>
                                <div class="form-check form-switch mb-0" style="padding:0">
                                    <input class="form-check-input" type="checkbox" role="switch" style="width:28px;height:15px;margin:0" id="vd-sch-toggle" aria-label="Enable boot schedule"
                                        onchange="
                                            const bl=document.getElementById('vd-boot-block');
                                            const ro=document.getElementById('vd-boot-row-off');
                                            const rn=document.getElementById('vd-boot-row');
                                            const on=this.checked;
                                            bl.style.opacity=on?'1':'.45';
                                            bl.style.borderColor='color-mix(in srgb,var(--green) '+(on?'20%':'10%')+',transparent)';
                                            rn.style.display=on?'':'none';
                                            ro.style.display=on?'none':'';
                                            saveVmSchedule()">
                                </div>
                            </div>
                            <div id="vd-boot-row"><?= timePair('vd-boot-time', '08:00', 'drawer-time-select', 'width:58px') ?></div>
                            <div id="vd-boot-row-off" style="display:none;font-size:11px;color:var(--text-dim)">disabled</div>
                        </div>
                        <!-- Shutdown block -->
                        <div style="flex:1;background:color-mix(in srgb,var(--red) 8%,transparent);border:1px solid color-mix(in srgb,var(--red) 15%,transparent);border-radius:10px;padding:10px 12px;transition:opacity .2s,border-color .2s" id="vd-shut-block">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                <div style="display:flex;align-items:center;gap:5px">
                                    <i class="bi bi-moon-fill" style="color:var(--red);font-size:11px"></i>
                                    <span style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--red)">Shutdown</span>
                                </div>
                                <div class="form-check form-switch mb-0" style="padding:0">
                                    <input class="form-check-input" type="checkbox" role="switch" style="width:28px;height:15px;margin:0" id="vd-shut-toggle" aria-label="Enable shutdown schedule"
                                        onchange="
                                            const bl=document.getElementById('vd-shut-block');
                                            const ro=document.getElementById('vd-shut-row-off');
                                            const rn=document.getElementById('vd-shut-row');
                                            const on=this.checked;
                                            bl.style.opacity=on?'1':'.45';
                                            bl.style.borderColor='color-mix(in srgb,var(--red) '+(on?'20%':'10%')+',transparent)';
                                            rn.style.display=on?'':'none';
                                            ro.style.display=on?'none':'';
                                            saveVmSchedule()">
                                </div>
                            </div>
                            <div id="vd-shut-row"><?= timePair('vd-shutdown-time', '00:00', 'drawer-time-select', 'width:58px') ?></div>
                            <div id="vd-shut-row-off" style="display:none;font-size:11px;color:var(--text-dim)">disabled</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ══ MODAL ACCIÓN ════════════════════════════════════════════ -->
        <div class="modal fade" id="actionModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered modal-action-dialog">
                <div class="modal-content action-modal p-0">
                    <div class="modal-body p-0">

                        <!-- Banda de color + ícono -->
                        <div class="am-header" id="am-header">
                            <div class="am-icon-ring" id="am-icon-ring">
                                <i class="bi" id="modal-icon"></i>
                            </div>
                        </div>

                        <!-- Texto central -->
                        <div class="am-body">
                            <div class="am-title" id="modalTitle">Confirm</div>
                            <div class="am-desc"  id="modalMsg"></div>
                            <div class="am-badge" id="modal-srv-badge"></div>
                        </div>

                        <!-- Botones -->
                        <div class="am-footer">
                            <button class="btn am-confirm-btn" id="confirmBtn">Execute</button>
                            <button class="btn am-cancel-btn"  onclick="closeModal()">Cancel</button>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- ══ MODAL AGREGAR HOST ══════════════════════════════════════ -->
        <div class="modal fade" id="addModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-body">

                        <!-- Header -->
                        <div class="fmodal-header">
                            <div class="fmodal-icon-wrap fmodal-icon-blue">
                                <i class="bi bi-server"></i>
                            </div>
                            <div>
                                <div class="fmodal-title">Add new host</div>
                                <div class="fmodal-sub">Register a server in WakeLab</div>
                            </div>
                        </div>

                        <form onsubmit="addNewServer(event)">

                            <!-- Identificación -->
                            <div class="fmodal-field">
                                <label for="new_hn">Hostname</label>
                                <input type="text" id="new_hn" class="form-control" required>
                            </div>
                            <div class="fmodal-field">
                                <label for="new_addr">IP or URL</label>
                                <input type="text" id="new_addr" class="form-control" required placeholder="192.168.1.x or host:8006">
                            </div>
                            <div id="new-mac-row" class="fmodal-field">
                                <label for="new_mac">MAC <span class="fmodal-label-hint">(Wake-on-LAN, optional — comma-separated for multiple)</span></label>
                                <input type="text" id="new_mac" class="form-control font-mono" placeholder="AA:BB:CC:DD:EE:FF">
                            </div>

                            <!-- Tipo de sistema — visual picker -->
                            <div class="fmodal-field">
                                <span style="display:block;font-size:11px;font-weight:500;color:var(--text-muted);margin-bottom:8px">System type</span>
                                <div class="type-picker" id="new-type-picker">
                                    <?php foreach ($HYP_LABELS as $hk => $hv): ?>
                                    <button type="button" class="type-btn" data-val="<?= $hk ?>" onclick="selectHostType('new','<?= $hk ?>')">
                                        <img src="assets/icons/<?= $hk ?>.svg" width="22" height="22">
                                        <span><?= $hv ?></span>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" id="new_hyp">
                            </div>

                            <!-- Rol -->
                            <div class="fmodal-field">
                                <label for="new_role" style="font-size:11px;font-weight:500;color:var(--text-muted)">Role</label>
                                <select id="new_role" class="form-select" required>
                                    <option value="" disabled selected>— choose —</option>
                                    <option value="primary">Primary</option>
                                    <option value="media">Multimedia</option>
                                    <option value="pbs">Backup (PBS)</option>
                                    <option value="node">Cluster node</option>
                                    <option value="nas">NAS</option>
                                    <option value="pc">PC / Desktop</option>
                                </select>
                            </div>

                            <!-- VM toggle -->
                            <div class="fmodal-toggle-row">
                                <label class="fmodal-toggle-label" for="new_is_vm">Is it a VM or LXC?</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="new_is_vm" role="switch" onchange="onNewIsVmChange(this.checked)">
                                </div>
                            </div>

                            <div id="new-vm-section" style="display:none">
                                <div class="fmodal-section">
                                    <div class="fmodal-section-bar"></div>
                                    <span class="fmodal-section-label"><i class="bi bi-cpu me-1"></i>VM / LXC Configuration</span>
                                </div>
                                <div class="fmodal-field">
                                    <label for="new_pve_srv">Hypervisor Proxmox</label>
                                    <select id="new_pve_srv" class="form-select">
                                        <option value="">— select —</option>
                                        <?php foreach ($servers as $s): if ($s['hypervisor_type'] !== 'pve') continue; ?>
                                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['hostname'], ENT_QUOTES) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fmodal-field">
                                    <label for="new_vmid">VMID</label>
                                    <input type="number" id="new_vmid" class="form-control" placeholder="100" min="1">
                                </div>
                            </div>

                            <!-- API toggle -->
                            <div id="new-api-row" class="fmodal-toggle-row">
                                <label class="fmodal-toggle-label" for="new_api">API enabled</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="new_api" role="switch" onchange="onNewApiChange(this.checked)">
                                </div>
                            </div>

                            <div id="new-token-section" style="display:none">
                                <div class="fmodal-section">
                                    <div class="fmodal-section-bar"></div>
                                    <span class="fmodal-section-label"><i class="bi bi-key me-1"></i>API Credentials <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></span>
                                </div>
                                <div id="nr-user-row" class="fmodal-field">
                                    <label class="form-label">API User</label>
                                    <input type="text" id="new_apu" class="form-control" value="root@pam">
                                </div>
                                <div id="nr-tid-row" class="fmodal-field">
                                    <label class="form-label">Token ID</label>
                                    <input type="text" id="new_tid" class="form-control" value="panel">
                                </div>
                                <div class="fmodal-field">
                                    <label id="nr-secret-label" class="form-label">Token Secret</label>
                                    <input type="password" id="new_tsc" class="form-control" placeholder="UUID / API key">
                                </div>
                            </div>

                            <!-- SSH section (PC types) -->
                            <div id="new-ssh-section" style="display:none">
                                <div class="fmodal-section">
                                    <div class="fmodal-section-bar"></div>
                                    <span class="fmodal-section-label"><i class="bi bi-terminal me-1"></i>SSH Credentials</span>
                                </div>
                                <div class="fmodal-field">
                                    <label class="form-label">SSH User</label>
                                    <input type="text" id="new_ssh_user" class="form-control" placeholder="Administrator / root">
                                </div>
                                <div class="fmodal-field">
                                    <label class="form-label">SSH Password <span class="fmodal-label-hint">(empty = SSH key)</span></label>
                                    <input type="password" id="new_ssh_pass" class="form-control" placeholder="SSH password" autocomplete="new-password">
                                </div>
                            </div>

                            <!-- Dependencies -->
                            <div class="fmodal-section">
                                <div class="fmodal-section-bar"></div>
                                <span class="fmodal-section-label"><i class="bi bi-diagram-3 me-1"></i>Dependencies</span>
                            </div>
                            <div class="fmodal-field">
                                <label for="new_dep">Depends on <span class="fmodal-label-hint">(boots first)</span></label>
                                <select id="new_dep" class="form-select">
                                    <option value="">— none —</option>
                                    <?php foreach ($servers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['hostname'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Idle toggle (visible según tipo de sistema) -->
                            <div id="new-idle-row" class="fmodal-toggle-row" style="display:none">
                                <label class="fmodal-toggle-label" for="new_idle">Auto-shutdown on inactivity</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="new_idle" role="switch" onchange="onNewIdleChange(this.checked)">
                                </div>
                            </div>

                            <!-- SSH para idle (tipos no-PC) -->
                            <div id="new-idle-ssh-section" style="display:none">
                                <div class="fmodal-section">
                                    <div class="fmodal-section-bar"></div>
                                    <span class="fmodal-section-label"><i class="bi bi-terminal me-1"></i>SSH Access</span>
                                </div>
                                <div class="mb-3 p-2 rounded" style="background:var(--bg-deep);font-size:11px;color:var(--text-muted);line-height:1.5">
                                    <i class="bi bi-info-circle me-1" style="color:var(--blue)"></i>
                                    WakeLab will use this password <strong style="color:var(--text-dim)">one time only</strong> to authorize its SSH key on the server.
                                    After that the idle script connects with the key alone — the password is not saved.
                                </div>
                                <div class="fmodal-field">
                                    <label class="form-label">SSH User</label>
                                    <input type="text" id="new_idle_user" class="form-control" placeholder="root" value="root">
                                </div>
                                <div class="fmodal-field">
                                    <label class="form-label">SSH Password</label>
                                    <input type="password" id="new_idle_pass" class="form-control" placeholder="SSH password" autocomplete="new-password">
                                </div>
                                <div class="fmodal-field">
                                    <label class="form-label">SSH Port</label>
                                    <input type="number" id="new_idle_port" class="form-control" value="22" min="1" max="65535">
                                </div>
                            </div>

                            <div class="modal-actions">
                                <button type="submit" class="btn btn-outline-success">Register</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="closeAddModal()">Cancel</button>
                            </div>
                        </form>

                        <!-- Paso 2: deploy idle (mostrado tras registrar si idle estaba activado) -->
                        <div id="new-step2" style="display:none;padding-top:8px">
                            <div class="text-center py-3">
                                <i class="bi bi-check-circle-fill" style="color:var(--green);font-size:2.2rem"></i>
                                <div style="font-weight:600;margin-top:8px;font-size:15px">Host registered</div>
                            </div>
                            <div id="new-step2-idle" style="display:none">
                                <div class="fmodal-section">
                                    <div class="fmodal-section-bar"></div>
                                    <span class="fmodal-section-label"><i class="bi bi-upload me-1"></i>Step 2 — Deploy idle script</span>
                                </div>
                                <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Send the auto-shutdown script to the server to enable idle.</p>
                                <button type="button" id="new-deploy-btn" class="btn btn-outline-primary w-100" onclick="deployIdleForNew()">
                                    <i class="bi bi-upload me-1"></i>Deploy idle script
                                </button>
                                <div id="new-deploy-result" style="display:none;margin-top:8px;font-size:12px;text-align:center"></div>
                            </div>
                            <div class="modal-actions mt-3">
                                <button type="button" class="btn btn-outline-success" onclick="location.reload()">Go to dashboard</button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- ══ TOAST ════════════════════════════════════════════════════ -->
        <div class="position-fixed bottom-0 end-0 p-3" id="toast-container" style="z-index:1100">
            <div id="toast-el" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-inner">
                    <div class="toast-icon-col">
                        <i id="toast-icon" class="bi bi-check-circle-fill"></i>
                    </div>
                    <div class="toast-content">
                        <div id="toast-msg" class="toast-msg-text"></div>
                        <div id="toast-detail" class="toast-detail-text" style="display:none"></div>
                    </div>
                    <button type="button" class="btn-close toast-close-btn" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>

        <script>
            window.APP_CONFIG = { pollingInterval: <?= $pollingIntervalSec * 1000 ?> };
            window.WP_DATA = <?= json_encode(array_values($wake_proxies)) ?>;
            window.WAKELAB_KEY_READY = <?= $wakeLabKeyReady ? 'true' : 'false' ?>;

            // Dark/light toggle
            (function() {
                if (localStorage.getItem('theme') === 'light') document.documentElement.classList.add('light');
            })();
            function _applyThemeColor(isLight) {
                const color = isLight ? '#f6f8fa' : '#0d1117';
                document.querySelector('meta[name="theme-color"]')?.setAttribute('content', color);
            }
            function toggleTheme() {
                const isLight = document.documentElement.classList.toggle('light');
                localStorage.setItem('theme', isLight ? 'light' : 'dark');
                document.getElementById('theme-icon').className = isLight ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
                _applyThemeColor(isLight);
            }
            document.addEventListener('DOMContentLoaded', () => {
                const isLight = document.documentElement.classList.contains('light');
                if (isLight) document.getElementById('theme-icon').className = 'bi bi-moon-stars-fill';
                _applyThemeColor(isLight);
                // Inicializar Bootstrap tooltips
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el =>
                    bootstrap.Tooltip.getOrCreateInstance(el));
            });
        </script>
        <script src="assets/vendor/jquery.min.js"></script>
        <script src="assets/bootstrap/bootstrap.bundle.min.js"></script>
        <script src="assets/vendor/dataTables.min.js"></script>
        <script src="assets/vendor/dataTables.bootstrap5.min.js"></script>
        <script src="assets/js/api.js"></script>
        <script src="assets/js/ui.js"></script>
        <script src="assets/js/app.js"></script>
        <script>
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('sw.js').catch(() => {});
            }
            // Auto-abrir guía si viene de agregar servidor
            document.addEventListener('DOMContentLoaded', () => {
                const guideType = localStorage.getItem('wl_new_guide');
                if (guideType) {
                    localStorage.removeItem('wl_new_guide');
                    openSetupGuide(guideType);
                }
            });
        </script>

        <!-- ══ SETUP GUIDE DRAWER ══════════════════════════════════════════ -->
        <div class="offcanvas offcanvas-end setup-guide-offcanvas" tabindex="-1"
             id="setupGuideDrawer" aria-labelledby="setupGuideLabel">

            <div class="offcanvas-header">
                <div class="guide-header-inner">
                    <i class="bi bi-book-half guide-header-icon"></i>
                    <div>
                        <h5 class="offcanvas-title" id="setupGuideLabel">Setup Guide</h5>
                        <p class="guide-header-sub">Configure your server in WakeLab</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>

            <div class="offcanvas-body guide-body">

                <!-- Tabs -->
                <div class="guide-tabs" role="tablist">
                    <button class="guide-tab-btn active" role="tab" onclick="switchGuideTab('linux',this)"   data-guide="linux">Linux</button>
                    <button class="guide-tab-btn"        role="tab" onclick="switchGuideTab('windows',this)" data-guide="windows">Windows</button>
                    <button class="guide-tab-btn"        role="tab" onclick="switchGuideTab('pve',this)"     data-guide="pve">Proxmox VE</button>
                    <button class="guide-tab-btn"        role="tab" onclick="switchGuideTab('pbs',this)"     data-guide="pbs">PBS</button>
                    <button class="guide-tab-btn"        role="tab" onclick="switchGuideTab('truenas',this)" data-guide="truenas">TrueNAS</button>
                    <button class="guide-tab-btn"        role="tab" onclick="switchGuideTab('generic',this)" data-guide="generic">Generic</button>
                </div>

                <!-- ── LINUX ──────────────────────────────────────────────── -->
                <div class="guide-tab-content active" id="guide-linux">
                    <div class="guide-section-title"><i class="bi bi-broadcast-pin"></i> Wake-on-LAN</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>Enable WoL on the network adapter:</p>
                                <div class="guide-code">
                                    <code>sudo ethtool -s eth0 wol g</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                                <p class="guide-note">Replace <code>eth0</code> with your interface (<code>ip link</code> to list them).</p>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>To persist after reboots, create a systemd service:</p>
                                <div class="guide-code">
                                    <code>sudo nano /etc/systemd/system/wol.service</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                                <div class="guide-code mt-1">
                                    <code>[Unit]
Description=Wake-on-LAN
[Service]
Type=oneshot
ExecStart=/sbin/ethtool -s eth0 wol g
[Install]
WantedBy=multi-user.target</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                                <div class="guide-code mt-1">
                                    <code>sudo systemctl enable --now wol.service</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="guide-section-title mt-3"><i class="bi bi-terminal"></i> Shutdown por SSH</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>Authorize WakeLab's SSH key on the server:</p>
                                <div class="guide-code">
                                    <code>mkdir -p ~/.ssh && chmod 700 ~/.ssh
cat /path/to/id_ed25519.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>Verify from the WakeLab container:</p>
                                <div class="guide-code">
                                    <code>docker exec webserver su -s /bin/bash www-data -c "ssh root@IP echo OK"</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="guide-note mt-3">
                        <i class="bi bi-info-circle-fill"></i>
                        Server type: <strong>Generic</strong>. No API, TCP ping + WoL + SSH only.
                    </div>
                </div>

                <!-- ── WINDOWS ────────────────────────────────────────────── -->
                <div class="guide-tab-content" id="guide-windows">
                    <div class="guide-section-title"><i class="bi bi-broadcast-pin"></i> Wake-on-LAN</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>Enable WoL in BIOS/UEFI: look for <em>Wake on LAN</em> or <em>Power On By PCI-E</em> and enable it.</p>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>In Windows: Device Manager → your network adapter → Properties → Power Management:</p>
                                <div class="guide-note">Enable <strong>Allow this device to wake the computer</strong> and <strong>Only allow a magic packet to wake the computer</strong>.</div>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">3</span>
                            <div>
                                <p>Disable "Fast Startup" — it can interfere with WoL:</p>
                                <div class="guide-code">
                                    <code>powercfg /h off</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="guide-section-title mt-3"><i class="bi bi-terminal"></i> Shutdown por SSH</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>Install OpenSSH Server on Windows:</p>
                                <div class="guide-code">
                                    <code>Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0
Start-Service sshd
Set-Service -Name sshd -StartupType Automatic</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>Authorize WakeLab's key. On Windows, for administrators:</p>
                                <div class="guide-code">
                                    <code>cat id_ed25519.pub >> C:\ProgramData\ssh\administrators_authorized_keys</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="guide-note mt-3">
                        <i class="bi bi-info-circle-fill"></i>
                        Server type: <strong>Generic</strong>. The shutdown command is <code>shutdown /s /t 0</code>.
                    </div>
                </div>

                <!-- ── PROXMOX VE ──────────────────────────────────────────── -->
                <div class="guide-tab-content" id="guide-pve">
                    <div class="guide-section-title"><i class="bi bi-key"></i> API Token</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>In Proxmox VE → Datacenter → Permissions → API Tokens → Add:</p>
                                <ul class="guide-list">
                                    <li>User: <code>root@pam</code></li>
                                    <li>Token ID: <code>wakelab</code></li>
                                    <li>Privilege Separation: <strong>disabled</strong></li>
                                </ul>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>Copy the token secret immediately — it will not be shown again.</p>
                                <div class="guide-warn"><i class="bi bi-exclamation-triangle-fill"></i> The secret only appears once when creating the token.</div>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">3</span>
                            <div>
                                <p>In WakeLab when adding the server:</p>
                                <ul class="guide-list">
                                    <li>Type: <strong>Proxmox VE</strong></li>
                                    <li>Auth type: <strong>API Token</strong></li>
                                    <li>API User: <code>root@pam</code></li>
                                    <li>Token ID: <code>wakelab</code></li>
                                    <li>Token Secret: the copied value</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="guide-section-title mt-3"><i class="bi bi-broadcast-pin"></i> Wake-on-LAN</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>WoL is sent directly to the Proxmox host — enable it in BIOS/UEFI and on the adapter:</p>
                                <div class="guide-code">
                                    <code>ethtool -s eth0 wol g</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── PBS ────────────────────────────────────────────────── -->
                <div class="guide-tab-content" id="guide-pbs">
                    <div class="guide-section-title"><i class="bi bi-key"></i> API Token</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>In Proxmox Backup Server → Configuration → Access → API Tokens → Add:</p>
                                <ul class="guide-list">
                                    <li>User: <code>root@pam</code></li>
                                    <li>Token Name: <code>wakelab</code></li>
                                </ul>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>In PBS → Configuration → Access → Permissions → Add:</p>
                                <ul class="guide-list">
                                    <li>Path: <code>/</code></li>
                                    <li>User/Token: <code>root@pam!wakelab</code></li>
                                    <li>Role: <strong>Admin</strong></li>
                                </ul>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">3</span>
                            <div>
                                <p>In WakeLab when adding the server:</p>
                                <ul class="guide-list">
                                    <li>Type: <strong>Proxmox Backup Server</strong></li>
                                    <li>Auth type: <strong>API Token</strong></li>
                                    <li>Default port: <code>8007</code></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="guide-note mt-3">
                        <i class="bi bi-info-circle-fill"></i>
                        PBS shows datastores and recent backup tasks. WoL and SSH same as Linux.
                    </div>
                </div>

                <!-- ── TRUENAS ─────────────────────────────────────────────── -->
                <div class="guide-tab-content" id="guide-truenas">
                    <div class="guide-section-title"><i class="bi bi-key"></i> API Key</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>In TrueNAS SCALE → Top-right menu → API Keys → Add:</p>
                                <ul class="guide-list">
                                    <li>Name: <code>wakelab</code></li>
                                    <li>Copy the generated key</li>
                                </ul>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>In WakeLab when adding the server:</p>
                                <ul class="guide-list">
                                    <li>Type: <strong>TrueNAS SCALE</strong></li>
                                    <li>Auth type: <strong>API Key</strong></li>
                                    <li>Port: <code>443</code> (HTTPS) or <code>80</code> (HTTP)</li>
                                </ul>
                                <div class="guide-warn mt-1"><i class="bi bi-exclamation-triangle-fill"></i> TrueNAS uses WebSocket. If the connection fails, use "Debug WS" in the server panel.</div>
                            </div>
                        </div>
                    </div>

                    <div class="guide-section-title mt-3"><i class="bi bi-key-fill"></i> SSH Access (remote shutdown)</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>Make sure you have the <strong>API Key configured</strong> and the <strong>SSH service enabled</strong> in TrueNAS (System → Services → SSH).</p>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>In WakeLab → server config → click <strong>"authorize SSH key"</strong>. WakeLab installs the key automatically via API — no password needed.</p>
                                <div class="guide-note">Once authorized, the SSH section disappears and WakeLab can shut down/restart the server.</div>
                            </div>
                        </div>
                    </div>

                    <div class="guide-section-title mt-3"><i class="bi bi-broadcast-pin"></i> Wake-on-LAN</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>TrueNAS has no native WoL command in the UI. Enable it in BIOS and on the adapter from the shell:</p>
                                <div class="guide-code">
                                    <code>ethtool -s igb0 wol g</code>
                                    <button class="guide-code-copy" onclick="copyGuideCmd(this)" title="Copy"><i class="bi bi-copy"></i></button>
                                </div>
                                <p class="guide-note">In TrueNAS, adapters are typically named <code>igb0</code>, <code>em0</code>, etc.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── GENÉRICO ────────────────────────────────────────────── -->
                <div class="guide-tab-content" id="guide-generic">
                    <div class="guide-section-title"><i class="bi bi-info-circle"></i> Generic server</div>
                    <div class="guide-steps">
                        <div class="guide-step">
                            <span class="guide-step-num">1</span>
                            <div>
                                <p>The Generic type uses only TCP ping to detect whether the server is online. No API.</p>
                                <div class="guide-note">Monitoring port is configurable — use the port of any active service (e.g. <code>22</code> for SSH, <code>80</code> for HTTP).</div>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">2</span>
                            <div>
                                <p>For Wake-on-LAN: enter the server's MAC address. The magic packet is sent via UDP broadcast to port 9.</p>
                            </div>
                        </div>
                        <div class="guide-step">
                            <span class="guide-step-num">3</span>
                            <div>
                                <p>For SSH shutdown: configure SSH keys as in the Linux guide.</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /.offcanvas-body -->
        </div><!-- /#setupGuideDrawer -->
    </body>
</html>


