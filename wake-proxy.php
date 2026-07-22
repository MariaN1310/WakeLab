<?php
ini_set('display_errors', '0');
error_reporting(0);
require __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/hyp_client.php';
require_once __DIR__ . '/php/pve.php';

// ─────────────────────────────────────────────────────────────
// RATE LIMITING — máx 60 requests por IP en 60 segundos
(function(): void {
    $ip   = preg_replace('/[^a-f0-9:.]/', '', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0')[0]);
    $file = sys_get_temp_dir() . '/wakelab_wp_' . $ip . '.json';
    $now  = time();
    $data = ['count' => 0, 'window_start' => $now];
    if (file_exists($file)) {
        $raw = json_decode((string)file_get_contents($file), true);
        if (is_array($raw)) $data = $raw;
    }
    if ($now - $data['window_start'] > 60) {
        $data = ['count' => 0, 'window_start' => $now];
    }
    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);
    if ($data['count'] > 60) {
        http_response_code(429);
        exit('Too Many Requests');
    }
})();

// ─────────────────────────────────────────────────────────────
// TOKEN AUTH
// Verifica el header X-Wake-Proxy-Token (seteado por NPM/Nginx)
// o el query param ?wpt=. Si el secret no está configurado en
// settings, se omite la verificación (backwards compatible).
// ─────────────────────────────────────────────────────────────
(function() use ($pdo): void {
    $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='wake_proxy_secret' LIMIT 1");
    $s->execute();
    $stored = (string)($s->fetchColumn() ?: '');
    if ($stored === '') { http_response_code(403); echo '<!DOCTYPE html><html><body>Wake Proxy not configured.</body></html>'; exit; }

    $incoming = $_SERVER['HTTP_X_WAKE_PROXY_TOKEN']
             ?? $_SERVER['HTTP_X_WAKEPROXY_TOKEN']
             ?? $_GET['wpt']
             ?? '';

    if (!hash_equals($stored, (string)$incoming)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso denegado</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{background:#0d1117;color:#c9d1d9;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
.card{background:#161b22;border:1px solid #f8514966;border-radius:12px;padding:2rem 2.5rem;max-width:420px;width:100%;text-align:center}
.icon{font-size:2rem;margin-bottom:1rem}h2{color:#f85149;font-size:1.1rem;margin-bottom:.5rem}p{font-size:.85rem;color:#8b949e;line-height:1.5}code{background:#21262d;padding:2px 6px;border-radius:4px;font-size:.8rem;color:#e3b341}</style>
</head><body><div class="card"><div class="icon">🔒</div>
<h2>Token inválido o ausente</h2>
<p>Este Wake Proxy requiere autenticación.<br>Configurá el header en NPM:</p>
<p style="margin-top:.75rem"><code>X-Wake-Proxy-Token: &lt;token&gt;</code></p>
<p style="margin-top:.75rem">El token lo encontrás en <b>WakeLab → Wake Proxy → NPM config</b>.</p>
</div></body></html>';
        exit;
    }
})();

// ─────────────────────────────────────────────────────────────
// IP / BOT FILTER
(function() use ($pdo): void {
    // Resolve real client IP (X-Forwarded-For first, then REMOTE_ADDR)
    $rawIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0')[0]);
    $clientIp = preg_replace('/[^a-f0-9:.]/', '', $rawIp) ?: '0.0.0.0';

    // ── Bot / User-Agent block ────────────────────────────────
    $blockBots = getSplashSettingEarly($pdo, 'wp_block_bots', '0') === '1';
    if ($blockBots) {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $defaultBotPatterns = [
            'googlebot','bingbot','slurp','duckduckbot','baiduspider','yandexbot','sogou',
            'facebot','ia_archiver','semrushbot','ahrefsbot','dotbot','mj12bot','blexbot',
            'petalbot','bytespider','gptbot','ccbot','anthropic-ai','claudebot','oai-searchbot',
            'dataforseobot','serpstatbot','seokicks','rogerbot','linkdexbot','exabot',
            'nmap','nikto','sqlmap','masscan','zgrab','nuclei','dirbuster','gobuster',
            'python-requests','go-http-client','curl/','wget/','libwww-perl','scrapy',
        ];
        $customUa = getSplashSettingEarly($pdo, 'wp_blocked_ua', '');
        $customPatterns = $customUa !== '' ? array_filter(array_map('trim', explode("\n", strtolower($customUa)))) : [];
        $allPatterns = array_merge($defaultBotPatterns, $customPatterns);
        foreach ($allPatterns as $pattern) {
            if ($pattern !== '' && strpos($ua, $pattern) !== false) {
                http_response_code(503);
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:system-ui;padding:2rem;background:#0d1117;color:#8b949e;text-align:center"><p style="margin-top:4rem">Service temporarily unavailable.</p></body></html>';
                exit;
            }
        }
    }

    // ── Local-only mode ───────────────────────────────────────
    $localOnly = getSplashSettingEarly($pdo, 'wp_local_only', '0') === '1';
    if (!$localOnly) return;

    // Check blocked IPs/CIDRs first
    $blockedRaw = getSplashSettingEarly($pdo, 'wp_blocked_ips', '');
    if ($blockedRaw !== '') {
        $blocked = array_filter(array_map('trim', explode(',', $blockedRaw)));
        foreach ($blocked as $cidr) {
            if (ipInCidr($clientIp, $cidr)) {
                http_response_code(403);
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:system-ui;padding:2rem;background:#0d1117;color:#8b949e;text-align:center"><p style="margin-top:4rem">Access denied.</p></body></html>';
                exit;
            }
        }
    }

    // Check allowed ranges
    $rangesRaw = getSplashSettingEarly($pdo, 'wp_allowed_ranges', '192.168.0.0/16,10.0.0.0/8,172.16.0.0/12');
    $ranges = array_filter(array_map('trim', explode(',', $rangesRaw)));
    foreach ($ranges as $cidr) {
        if (ipInCidr($clientIp, $cidr)) return; // allowed
    }

    // Not in any allowed range
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:system-ui;padding:2rem;background:#0d1117;color:#8b949e;text-align:center"><p style="margin-top:4rem">Service temporarily unavailable.</p></body></html>';
    exit;
})();

function getSplashSettingEarly(PDO $pdo, string $key, string $default): string {
    try {
        $s = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v !== false ? (string)$v : $default;
    } catch (Throwable $e) { return $default; }
}

function ipInCidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return $ip === $cidr;
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipLong  = ip2long($ip);
    $subLong = ip2long($subnet);
    if ($ipLong === false || $subLong === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ipLong & $mask) === ($subLong & $mask);
}

// ─────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────

function tcpCheck(string $ip, int $port, int $timeout = 1): bool {
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if ($sock) { fclose($sock); return true; }
    return false;
}

// Locks en DB (settings) — persisten entre reinicios del container
function getLockTime(int $proxyId): int {
    global $pdo;
    $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
    $s->execute(["wp_boot_{$proxyId}"]);
    return (int)($s->fetchColumn() ?: 0);
}

/**
 * Atomic lock acquisition — prevents duplicate triggerWake calls when multiple
 * browser requests hit wake-proxy.php simultaneously (e.g. main page + favicon).
 * INSERT IGNORE for new locks (only one concurrent INSERT wins); CAS UPDATE for expired ones.
 */
function tryAcquireLock(int $proxyId, int $existingTs = 0): bool {
    global $pdo;
    if ($existingTs === 0) {
        $st = $pdo->prepare("INSERT IGNORE INTO settings (`key`,`value`,`description`) VALUES (?,?,'')");
        $st->execute(["wp_boot_{$proxyId}", (string)time()]);
        return $st->rowCount() > 0;
    } else {
        $st = $pdo->prepare("UPDATE settings SET `value`=? WHERE `key`=? AND `value`=?");
        $st->execute([(string)time(), "wp_boot_{$proxyId}", (string)$existingTs]);
        return $st->rowCount() > 0;
    }
}

function clearLock(int $proxyId): void {
    global $pdo;
    $pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute(["wp_boot_{$proxyId}"]);
}

/**
 * SSH to the server and run `docker start {container}`.
 * For LXC guests: runs via `pct exec {vmid} -- docker start {container}`.
 * For bare-metal: runs `docker start {container}` directly on srv_ip.
 * Uses stored SSH creds (srv_{id}_ssh_*) from settings table.
 */
function isSafeHost(string $host): bool {
    if (strlen($host) > 255) return false;
    return (bool)(filter_var($host, FILTER_VALIDATE_IP)
               || preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9._-]{0,253}[a-zA-Z0-9])?$/', $host));
}

function sshDockerStart(PDO $pdo, array $proxy): void {
    $srvId    = (int)$proxy['server_id'];
    $srvIp    = $proxy['srv_ip'];
    $vmid     = !empty($proxy['guest_vmid']) ? (int)$proxy['guest_vmid'] : 0;
    $container = $proxy['docker_container'];

    if (!isSafeHost($srvIp)) return;

    $stmt = $pdo->prepare(
        "SELECT `key`, value FROM settings
         WHERE `key` IN (
             'srv_{$srvId}_ssh_user',
             'srv_{$srvId}_ssh_pass',
             'srv_{$srvId}_ssh_port'
         )"
    );
    $stmt->execute();
    $creds   = array_column($stmt->fetchAll(), 'value', 'key');
    $sshUser = $creds["srv_{$srvId}_ssh_user"] ?? 'root';
    $sshPass = wlDecrypt($creds["srv_{$srvId}_ssh_pass"] ?? '');
    $sshPort = intval($creds["srv_{$srvId}_ssh_port"] ?? 22) ?: 22;

    // pct exec for LXC guests, direct docker start otherwise
    $dockerCmd = $vmid
        ? 'pct exec ' . $vmid . ' -- docker start ' . escapeshellarg($container)
        : 'docker start ' . escapeshellarg($container);

    $sshOpts = '-o StrictHostKeyChecking=no -o ConnectTimeout=5 -p ' . $sshPort;
    $keyOpt  = '-i /var/www/.ssh/id_ed25519';

    if ($sshPass) {
        $cmd = sprintf('sshpass -p %s ssh %s %s %s@%s %s 2>&1',
            escapeshellarg($sshPass), $sshOpts, $keyOpt,
            escapeshellarg($sshUser), escapeshellarg($srvIp),
            escapeshellarg($dockerCmd));
    } else {
        $cmd = sprintf('ssh %s %s %s@%s %s 2>&1',
            $sshOpts, $keyOpt,
            escapeshellarg($sshUser), escapeshellarg($srvIp),
            escapeshellarg($dockerCmd));
    }
    shell_exec($cmd);
}

function triggerWake(PDO $pdo, array $proxy): void {
    $srvId = (int)$proxy['server_id'];

    // Check if host is already online
    $hostUp = tcpCheck($proxy['srv_ip'], (int)$proxy['srv_port']);

    if (!$hostUp) {
        // Host offline — send WoL magic packet
        $mac = preg_replace('/[^a-f0-9]/i', '', $proxy['srv_mac'] ?? '');
        if (strlen($mac) === 12) {
            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($sock) {
                socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
                $packet = str_repeat("\xff", 6) . str_repeat(hex2bin($mac), 16);
                @socket_sendto($sock, $packet, strlen($packet), 0, '255.255.255.255', 9);
                socket_close($sock);
            }
        }
        logWakeProxy($pdo, $srvId, 'info',
            "Wake Proxy: WoL enviado para '{$proxy['name']}' ({$proxy['domain']})");
    } else {
        // Host online — start guest (if any), then docker (if any)
        if (!empty($proxy['guest_vmid'])) {
            $tokStmt = $pdo->prepare("SELECT * FROM api_tokens WHERE server_id=?");
            $tokStmt->execute([$srvId]);
            $tok = $tokStmt->fetch() ?: null;
            if ($tok) {
                $srv = [
                    'id'              => $srvId,
                    'ip'              => $proxy['srv_ip'],
                    'port'            => $proxy['srv_port'],
                    'hypervisor_type' => $proxy['srv_type'],
                ];
                try {
                    $client = HypFactory::make($srv, $tok);
                    if ($client instanceof PVEClient) {
                        $client->startGuest((int)$proxy['guest_vmid'], $proxy['guest_vmtype'] ?? 'qemu');
                        logWakeProxy($pdo, $srvId, 'info',
                            "Wake Proxy: startGuest vmid={$proxy['guest_vmid']} para '{$proxy['name']}'");
                    }
                } catch (Throwable $e) { /* silencioso */ }
            }
        }
        if (!empty($proxy['docker_container'])) {
            // sshDockerStart uses pct exec when guest_vmid is set, direct SSH otherwise
            sshDockerStart($pdo, $proxy);
            logWakeProxy($pdo, $srvId, 'info',
                "Wake Proxy: docker start '{$proxy['docker_container']}' en '{$proxy['name']}'");
        }
    }
}

function logWakeProxy(PDO $pdo, ?int $srvId, string $level, string $msg): void {
    try {
        $pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (?,?,?,?)")
            ->execute([$srvId, $level, $msg, gmdate('Y-m-d H:i:s')]);
    } catch (Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────
// LOOKUP
// ─────────────────────────────────────────────────────────────

function getSplashSetting(PDO $pdo, string $key, string $default): string {
    $s = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v !== false ? (string)$v : $default;
}

$rawHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host    = explode(':', $rawHost)[0]; // strip port if present

$stmt = $pdo->prepare(
    "SELECT wp.*, s.ip AS srv_ip, s.port AS srv_port, s.mac AS srv_mac,
            s.hypervisor_type AS srv_type
     FROM wake_proxies wp
     JOIN servers s ON s.id = wp.server_id
     WHERE wp.domain = ?
     LIMIT 1"
);
$stmt->execute([$host]);
$proxy = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proxy) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="font-family:monospace;padding:2rem">
        <h2>404 — proxy no configurado</h2>
        <p>No hay ningún Wake Proxy para <b>' . htmlspecialchars($rawHost) . '</b>.</p>
        </body></html>';
    exit;
}

// Proxy inactivo → pass-through directo sin wake ni splash
if (!$proxy['active']) {
    $scheme  = $_SERVER['HTTP_X_FORWARDED_PROTO']
             ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $selfHost = $_SERVER['HTTP_HOST'] ?? $proxy['domain'];
    // Redirigimos al mismo dominio para que Nginx siga haciendo proxy al destino real.
    // El servicio puede estar offline, pero no es responsabilidad de WakeLab en este caso.
    $uri = (function(): string {
        $raw   = $_SERVER['HTTP_X_ORIGINAL_URI'] ?? $_SERVER['REQUEST_URI'] ?? '/';
        $parts = parse_url($raw);
        $u     = $parts['path'] ?? '/';
        if (!empty($parts['query']))    $u .= '?' . $parts['query'];
        if (!empty($parts['fragment'])) $u .= '#' . $parts['fragment'];
        return '/' . ltrim($u, '/');
    })();
    // Si el destino está online, redirigir directo; si no, pasar sin splash
    $destUp = (bool)@fsockopen($proxy['dest_ip'], (int)$proxy['dest_port'], $e, $es, 2);
    if ($destUp) {
        header('Location: ' . $scheme . '://' . $selfHost . $uri, true, 302);
    } else {
        // Servicio offline y proxy desactivado: devolver 503 limpio sin splash
        http_response_code(503);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Servicio no disponible</title></head>'
           . '<body style="font-family:system-ui;padding:2rem;background:#0d1117;color:#c9d1d9;text-align:center">'
           . '<p style="margin-top:4rem;color:#8b949e">' . htmlspecialchars($proxy['name']) . ' no está disponible.</p>'
           . '</body></html>';
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// SERVICE UP? → redirect
// ─────────────────────────────────────────────────────────────

// Resolve original request URI for post-boot redirect.
// X-Original-URI es seteado por NPM via proxy_set_header X-Original-URI $request_uri.
function resolveOriginalUri(): string {
    $raw = $_SERVER['HTTP_X_ORIGINAL_URI'] // NPM
        ?? $_SERVER['REQUEST_URI']         // fallback
        ?? '/';
    // parse_url extracts only path+query+fragment — strips any scheme/host,
    // prevents header injection and protocol-relative redirects.
    $parts = parse_url($raw);
    $uri   = $parts['path'] ?? '/';
    if (!empty($parts['query']))    $uri .= '?' . $parts['query'];
    if (!empty($parts['fragment'])) $uri .= '#' . $parts['fragment'];
    return '/' . ltrim($uri, '/');
}

if (tcpCheck($proxy['dest_ip'], (int)$proxy['dest_port'])) {
    $pdo->prepare("UPDATE wake_proxies SET last_proxy_hit=NOW() WHERE id=?")
        ->execute([(int)$proxy['id']]);
    clearLock((int)$proxy['id']);

    // Redirect back to the proxy domain (not the internal IP) so the browser
    // goes through NPM which proxies to the now-running service.
    $scheme   = $_SERVER['HTTP_X_FORWARDED_PROTO']
             ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $selfHost = $_SERVER['HTTP_HOST'] ?? $proxy['domain'];
    $uri      = resolveOriginalUri();
    header('Location: ' . $scheme . '://' . $selfHost . $uri, true, 302);
    exit;
}

// ─────────────────────────────────────────────────────────────
// SERVICE DOWN → wake + splash
// ─────────────────────────────────────────────────────────────

$proxyId   = (int)$proxy['id'];
$lockTime  = getLockTime($proxyId);
$elapsed   = $lockTime > 0 ? (time() - $lockTime) : 0;
$timedOut  = $lockTime > 0 && $elapsed > (int)$proxy['boot_timeout_sec'];

if ($timedOut) {
    // Notificar a WakeLab que el host no levantó en tiempo
    $wlBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $wlBase = rtrim($wlBase, '/');
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    $notifUrl = $wlBase . $basePath . '/php/api.php';
    $ch = curl_init($notifUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'action'     => 'wake_timeout_event',
            'proxy_id'   => $proxyId,
            'proxy_name' => $proxy['name'] ?? '',
            'server_id'  => $proxy['server_id'],
            'elapsed'    => $elapsed,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Internal-Token: ' . (getenv('WAKELAB_SECRET') ?: '')],
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

if (tryAcquireLock($proxyId, $lockTime)) {
    triggerWake($pdo, $proxy);
}

// Build splash
$name        = htmlspecialchars($proxy['name']);
$hasGuest    = !empty($proxy['guest_vmid']);
$hasDocker   = !empty($proxy['docker_container']);
$layer       = $hasDocker ? 3 : ($hasGuest ? 2 : 1);
$timeout     = (int)$proxy['boot_timeout_sec'];
$guestLabel  = ($proxy['guest_vmtype'] ?? 'qemu') === 'lxc' ? 'LXC' : 'VM';
$splashMode  = getSplashSetting($pdo, 'wake_proxy_splash_mode', 'detailed');
$maxRetries  = (int)getSplashSetting($pdo, 'wake_proxy_max_retries', '3');

$steps = [['label' => 'Enviando señal de encendido al host', 'phase' => 'wol_sent']];
$steps[] = ['label' => 'Esperando boot del host',   'phase' => 'host_online'];
if ($layer >= 2) $steps[] = ['label' => "Esperando inicio del $guestLabel", 'phase' => 'guest_online'];
if ($layer >= 3) $steps[] = ['label' => 'Esperando inicio del contenedor Docker', 'phase' => 'docker_online'];
$steps[] = ['label' => 'Verificando que el servicio responde', 'phase' => 'service_online'];
$steps[] = ['label' => 'Redirigiendo...', 'phase' => 'done'];

$stepsJson = json_encode($steps);

// Scheme: check X-Forwarded-Proto (set by NPM/nginx) before falling back to HTTPS env var
$scheme   = $_SERVER['HTTP_X_FORWARDED_PROTO']
           ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$selfHost = explode(':', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost')[0];
$basePath  = $_SERVER['HTTP_X_WAKELAB_PREFIX']
           ?? rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/wake-proxy.php'), '/');
$apiBase   = $scheme . '://' . $selfHost . $basePath . '/php/api.php';
$originUrl = $scheme . '://' . $selfHost;

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciando <?= $name ?>…</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d1117;color:#c9d1d9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
     display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;padding:2rem;gap:1.25rem}
.card{background:#161b22;border:1px solid #30363d;border-radius:12px;padding:2rem 2.5rem;
      max-width:460px;width:100%;text-align:center}
.wakelab-brand{display:flex;align-items:center;gap:.4rem;color:#484f58;font-size:.78rem;font-weight:500;letter-spacing:.03em;user-select:none}
.wakelab-brand img{width:16px;height:16px;opacity:.5;object-fit:contain}
.service-name{font-size:1.4rem;font-weight:600;color:#f0f6fc;margin-bottom:.25rem}
.service-sub{font-size:.85rem;color:#8b949e}
.spinner{width:44px;height:44px;border:3px solid #30363d;border-top-color:#58a6ff;
         border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
/* detailed */
.steps{text-align:left;margin-bottom:1rem}
.step{display:flex;align-items:center;gap:.75rem;padding:.45rem .5rem;
      border-radius:6px;font-size:.875rem;color:#8b949e;transition:color .3s}
.step.active{color:#f0f6fc}
.step.done-step{color:#3fb950}
.step-icon{width:18px;height:18px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.75rem}
.step-spinner{width:14px;height:14px;border:2px solid #30363d;border-top-color:#58a6ff;
              border-radius:50%;animation:spin .7s linear infinite}
/* progress */
.progress-bar-wrap{background:#21262d;border-radius:4px;height:4px;overflow:hidden}
.progress-bar{height:100%;background:#58a6ff;border-radius:4px;transition:width .6s ease;width:0%}
/* messages */
.status-msg{font-size:.8rem;color:#8b949e;min-height:1.2em}
.retry-banner{color:#e3b341;font-size:.82rem;display:none}
.error-banner{color:#f85149;font-size:.875rem;display:none}
.retry-btn{margin-top:.75rem;padding:.4rem 1.2rem;background:transparent;border:1px solid #30363d;
           color:#c9d1d9;border-radius:6px;cursor:pointer;font-size:.85rem}
.retry-btn:hover{border-color:#58a6ff;color:#58a6ff}
/* simple-only spacer */
.simple-gap{height:1.75rem}
</style>
</head>
<body>
<div class="card">
<?php if ($splashMode === 'simple'): ?>
    <div style="margin-bottom:1.5rem"><div class="spinner" id="spinner"></div></div>
    <div class="service-name"><?= $name ?></div>
    <div class="service-sub" style="margin-bottom:1.5rem"><?= htmlspecialchars($proxy['domain']) ?></div>
    <div class="progress-bar-wrap" style="margin-bottom:1rem"><div class="progress-bar" id="prog"></div></div>
    <div class="status-msg" id="status-msg">Iniciando servicio...</div>
    <div class="retry-banner" id="retry-banner"></div>
    <div class="error-banner" id="error-banner">
        No se pudo iniciar el servicio.
        <br><button class="retry-btn" onclick="manualRetry()">Reintentar</button>
    </div>
<?php else: ?>
    <div style="margin-bottom:1.25rem"><div class="spinner" id="spinner"></div></div>
    <div class="service-name"><?= $name ?></div>
    <div class="service-sub" style="margin-bottom:.35rem"><?= htmlspecialchars($proxy['domain']) ?></div>
    <div style="font-size:.75rem;color:#484f58;margin-bottom:1.25rem">
        <?= htmlspecialchars($proxy['dest_ip']) ?>:<?= (int)$proxy['dest_port'] ?>
        &nbsp;·&nbsp;<span id="elapsed-counter">0s</span>
        &nbsp;·&nbsp;timeout <?= $timeout ?>s
    </div>
    <div class="progress-bar-wrap" style="margin-bottom:1.25rem"><div class="progress-bar" id="prog"></div></div>
    <div class="steps" id="steps-list"></div>
    <div class="status-msg" id="status-msg">Iniciando...</div>
    <div class="retry-banner" id="retry-banner"></div>
    <div class="error-banner" id="error-banner">
        El servicio no respondió tras <?= $maxRetries ?> intentos.
        <br><button class="retry-btn" onclick="manualRetry()">Reintentar</button>
    </div>
<?php endif; ?>
</div>

<div class="wakelab-brand">
    <img src="<?= htmlspecialchars($scheme.'://'.$selfHost.$basePath) ?>/assets/icons/web-app-manifest-192x192.png" alt="">
    WakeLab
</div>

<script>
const PROXY_ID    = <?= $proxyId ?>;
const TIMEOUT_MS  = <?= $timeout * 1000 ?>;
const MAX_RETRIES = <?= $maxRetries ?>;
const API_BASE    = <?= json_encode($apiBase) ?>;
const STEPS       = <?= $stepsJson ?>;
const REDIRECT_URI = <?= json_encode(resolveOriginalUri()) ?>;
const ORIGIN_URL   = <?= json_encode($originUrl) ?>;
const SPLASH_MODE  = <?= json_encode($splashMode) ?>;

const PHASE_ORDER = ['wol_sent','host_online','guest_online','docker_online','service_online','done'];

let currentPhase = 'wol_sent';
let started      = Date.now();
let retryCount   = 0;
let done         = false;

function phaseIndex(p){ return PHASE_ORDER.indexOf(p); }

function setProgress(pct) {
    document.getElementById('prog').style.width = pct + '%';
}

function renderSteps(activePhase) {
    const el = document.getElementById('steps-list');
    if (!el) return;
    const activeIdx = phaseIndex(activePhase);
    el.innerHTML = STEPS.map(s => {
        const sIdx = phaseIndex(s.phase);
        const isDone   = sIdx < activeIdx;
        const isActive = sIdx === activeIdx;
        const cls = isDone ? 'step done-step' : (isActive ? 'step active' : 'step');
        let icon;
        if (isDone)        icon = '<span style="color:#3fb950">✓</span>';
        else if (isActive) icon = '<div class="step-spinner"></div>';
        else               icon = '<span style="color:#30363d">○</span>';
        return `<div class="${cls}"><div class="step-icon">${icon}</div>${s.label}</div>`;
    }).join('');
    if (STEPS.length > 1) {
        setProgress(Math.round((activeIdx / (STEPS.length - 1)) * 100));
    }
}

const MSG = {
    wol_sent:      'Señal de encendido enviada. Esperando respuesta del host...',
    host_online:   'Host online. Verificando servicios internos...',
    guest_online:  'Host online. Iniciando contenedor...',
    docker_online: 'Contenedor iniciado. Verificando servicio...',
    service_online:'Servicio detectado. Redirigiendo...',
    done:          'Listo.',
};

function setStatus(msg) {
    document.getElementById('status-msg').textContent = msg;
}

function showRetrying(attempt) {
    const el = document.getElementById('retry-banner');
    el.style.display = 'block';
    el.textContent = `Reintentando... (intento ${attempt} de ${MAX_RETRIES})`;
    setStatus('');
}

function showError() {
    document.getElementById('spinner').style.display = 'none';
    document.getElementById('retry-banner').style.display = 'none';
    document.getElementById('error-banner').style.display = 'block';
    setStatus('');
    setProgress(0);
}

function manualRetry() {
    document.getElementById('error-banner').style.display = 'none';
    document.getElementById('spinner').style.display = '';
    retryCount = 0;
    started = Date.now();
    done = false;
    doRetry().then(() => { setTimeout(poll, 3000); });
}

async function doRetry() {
    try {
        await fetch(API_BASE, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'wake_proxy_retry', id: PROXY_ID})});
    } catch(e) {}
}

async function poll() {
    if (done) return;

    // Timeout → auto-retry or show error
    if (Date.now() - started > TIMEOUT_MS) {
        if (retryCount < MAX_RETRIES) {
            retryCount++;
            started = Date.now();
            showRetrying(retryCount);
            await doRetry();
            setTimeout(poll, 5000);
        } else {
            showError();
        }
        return;
    }

    try {
        const r = await fetch(`${API_BASE}?action=wake_proxy_status&id=${PROXY_ID}`);
        const d = await r.json();
        if (!d.data) { setTimeout(poll, 8000); return; }

        const phase = d.data.phase;
        currentPhase = phase;
        if (SPLASH_MODE === 'detailed') {
            renderSteps(phase);
        } else {
            // simple: animate progress based on elapsed time
            const elapsed = Date.now() - started;
            setProgress(Math.min(90, Math.round((elapsed / TIMEOUT_MS) * 90)));
        }
        setStatus(MSG[phase] || '');
        document.getElementById('retry-banner').style.display = 'none';

        if (phase === 'done' || d.data.status === 'online') {
            done = true;
            setStatus('Redirigiendo...');
            setProgress(100);
            setTimeout(() => { window.location.href = ORIGIN_URL + REDIRECT_URI; }, 600);
            return;
        }
    } catch(e) {
        setStatus('Verificando...');
    }
    setTimeout(poll, 8000);
}

// Elapsed counter (detailed mode only)
if (SPLASH_MODE === 'detailed') {
    const elapsedEl = document.getElementById('elapsed-counter');
    if (elapsedEl) {
        setInterval(() => {
            if (done) return;
            const totalMs = Date.now() - started + (retryCount * TIMEOUT_MS);
            const s = Math.floor(totalMs / 1000);
            elapsedEl.textContent = s < 60 ? s + 's' : Math.floor(s/60) + 'm' + (s%60) + 's';
        }, 1000);
    }
}

// Init
if (SPLASH_MODE === 'detailed') renderSteps('wol_sent');
setStatus(MSG['wol_sent']);
setTimeout(poll, 5000);
</script>
</body>
</html>
