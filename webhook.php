<?php
/**
 * WakeLab — Webhook UPS (Nutify/NUT)
 * POST /webhook.php
 * Header: X-Webhook-Token: <token>
 */

require_once __DIR__ . '/php/config.php';
require_once __DIR__ . '/php/db.php';

if (!function_exists('getSetting')) {
    function getSetting(PDO $pdo, string $key, string $default = ''): string {
        $st = $pdo->prepare("SELECT value FROM settings WHERE `key`=?");
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? (string)$row['value'] : $default;
    }
}

function wh_setSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
        ->execute([$key, $value]);
}

function wh_setPendingAction(PDO $pdo, int $srvId, string $action): void {
    $val = json_encode(['action' => $action, 'ts' => time()]);
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
        ->execute(['pa_' . $srvId, $val]);
}

header('Content-Type: application/json');

function wh_ok(string $msg, array $data = []): void {
    echo json_encode(['status' => 'ok', 'message' => $msg] + $data);
    exit;
}
function wh_err(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

$rawDebug    = file_get_contents('php://input');
$webhookDebug = getSetting($pdo, 'webhook_debug', '0') === '1';

if ($webhookDebug) {
    $tokenPresent = isset($_SERVER['HTTP_X_WEBHOOK_TOKEN']) ? '(present)' : '(missing)';
    $pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (NULL,'info',?,?)")
        ->execute(["Webhook UPS [debug] — method=" . $_SERVER['REQUEST_METHOD']
            . " token_header=" . $tokenPresent
            . " body=" . substr($rawDebug, 0, 400),
            gmdate('Y-m-d H:i:s')]);
}

// ── Auth ────────────────────────────────────────────────────
$token = getSetting($pdo, 'ups_webhook_token', '');
if ($token === '') wh_err(503, 'Webhook token not configured');

// Apache stripea Authorization — usar apache_request_headers() como fallback
$allHeaders  = function_exists('apache_request_headers') ? apache_request_headers() : [];
$authHeader  = $_SERVER['HTTP_AUTHORIZATION']
            ?? $allHeaders['Authorization']
            ?? $allHeaders['authorization']
            ?? '';
$bearerToken = str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : '';
$received    = $bearerToken ?: ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');

if (!hash_equals($token, $received)) wh_err(401, 'Unauthorized');

// ── Payload ─────────────────────────────────────────────────
// $rawDebug ya fue leído arriba — php://input es rewindable en PHP
$payload = json_decode($rawDebug, true);

// Nutify manda event_type en mayúsculas (TEST, ONBATT, ONLINE, LOWBATT, SHUTDOWN)
// Formato legacy NUT manda event en minúsculas
$event   = strtolower(trim($payload['event_type'] ?? $payload['event'] ?? ''));
$upsName = trim($payload['ups_data']['ups_model'] ?? $payload['ups_name'] ?? 'unknown');

if ($event === 'test' || $payload['test'] ?? false) {
    $pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (NULL,'info',?,?)")
        ->execute(["Webhook UPS — connection test OK (UPS: $upsName)", gmdate('Y-m-d H:i:s')]);
    wh_ok('Test received successfully');
}

if (!in_array($event, ['onbatt', 'online', 'lowbatt', 'shutdown'])) {
    wh_err(400, "Unknown event: $event");
}

// ── Helpers ─────────────────────────────────────────────────
function wh_log(PDO $pdo, ?int $srvId, string $level, string $msg): void {
    $ts = gmdate('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (?,?,?,?)")
        ->execute([$srvId, $level, $msg, $ts]);
}

function wh_sendWOL(string $mac, string $broadcast = '255.255.255.255'): bool {
    $mac = str_replace([':', '-'], '', $mac);
    if (strlen($mac) !== 12) return false;
    $packet = str_repeat(chr(0xFF), 6) . str_repeat(pack('H*', $mac), 16);
    $sock   = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$sock) return false;
    socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
    $result = socket_sendto($sock, $packet, strlen($packet), 0, $broadcast, 9);
    socket_close($sock);
    return $result !== false;
}

function wh_sshShutdown(PDO $pdo, int $srvId, string $ip): string {
    if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $ip)) {
        return "Error: invalid host";
    }
    $rows = $pdo->prepare("SELECT `key`,value FROM settings WHERE `key` IN (?,?,?)");
    $rows->execute(["srv_{$srvId}_ssh_user", "srv_{$srvId}_ssh_pass", "srv_{$srvId}_ssh_port"]);
    $creds = array_column($rows->fetchAll(), 'value', 'key');

    $sshUser = wlDecrypt($creds["srv_{$srvId}_ssh_user"] ?? '');
    $sshPass = wlDecrypt($creds["srv_{$srvId}_ssh_pass"] ?? '');
    $sshPort = intval($creds["srv_{$srvId}_ssh_port"] ?? 22) ?: 22;
    $portArg = $sshPort !== 22 ? " -p {$sshPort}" : '';
    $keyArg  = file_exists('/var/www/.ssh/id_ed25519') ? ' -i /var/www/.ssh/id_ed25519' : '';
    $shutCmd = ($sshUser && $sshUser !== 'root')
        ? 'sudo /sbin/shutdown -h now "wakelab: ups shutdown"'
        : '/sbin/shutdown -h now "wakelab: ups shutdown"';

    if ($sshUser && $sshPass) {
        $bin = trim((string)shell_exec('which sshpass 2>/dev/null'));
        if (!$bin) return 'Error: sshpass not available';
        $ssh = $bin . ' -p ' . escapeshellarg($sshPass)
             . ' ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5' . $portArg
             . ' ' . escapeshellarg("{$sshUser}@{$ip}")
             . ' ' . escapeshellarg($shutCmd) . ' 2>&1';
    } elseif ($sshUser) {
        $ssh = 'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes' . $keyArg . $portArg
             . ' ' . escapeshellarg("{$sshUser}@{$ip}") . ' ' . escapeshellarg($shutCmd) . ' 2>&1';
    } else {
        $ssh = 'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes' . $keyArg . $portArg
             . ' ' . escapeshellarg("root@{$ip}") . ' ' . escapeshellarg($shutCmd) . ' 2>&1';
    }
    return shell_exec($ssh) ?? '';
}

function wh_isSafeHost(string $ip): bool {
    if (strlen($ip) > 255) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $ip)) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return true; // hostname, allow
    // Bloquear loopback y link-local; RFC-1918 (LAN) es intencional
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) return false; // loopback/reserved
    return true;
}

function wh_pingHost(string $ip): bool {
    $pingBin = trim((string)shell_exec('which ping 2>/dev/null'));
    if ($pingBin) {
        $out = shell_exec("ping -c1 -W2 " . escapeshellarg($ip) . " 2>/dev/null");
        return str_contains((string)$out, '1 received') || str_contains((string)$out, '1 packets received');
    }
    // Only probe well-known server ports; validate host first to prevent SSRF to loopback/link-local
    if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $ip)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) return false;
    foreach ([22, 80, 443, 8006] as $port) {
        $sock = @fsockopen($ip, $port, $e, $s, 2);
        if ($sock) { fclose($sock); return true; }
    }
    return false;
}

function wh_shutdownServer(PDO $pdo, array $srv, string $reason): string {
    $srvId        = $srv['id'];
    $ip           = $srv['ip'];
    $hostname     = $srv['hostname'];
    $proxmoxVmid  = intval($srv['proxmox_vmid']      ?? 0);
    $proxmoxSrvId = intval($srv['proxmox_server_id'] ?? 0);

    if ($proxmoxVmid && $proxmoxSrvId) {
        require_once __DIR__ . '/php/pve.php';
        $tok = $pdo->prepare("SELECT * FROM api_tokens WHERE server_id=?");
        $tok->execute([$proxmoxSrvId]);
        $t = $tok->fetch();
        $s = $pdo->prepare("SELECT ip,port FROM servers WHERE id=?");
        $s->execute([$proxmoxSrvId]);
        $host = $s->fetch();
        if ($t && $host) {
            $pve = new PVEClient($host['ip'], (int)$host['port'], $t['api_user'], $t['token_id'], wlDecrypt($t['token_secret']));
            $ok  = $pve->shutdownGuest($proxmoxVmid);
            $msg = $ok ? "$hostname: shutdown via Proxmox API ($reason)" : "$hostname: Proxmox API error, trying SSH";
            wh_log($pdo, $srvId, $ok ? 'warn' : 'info', $msg);
            if ($ok) return 'proxmox';
        }
    }

    $out   = wh_sshShutdown($pdo, $srvId, $ip);
    $sshOk = trim($out) === '' || (!str_contains(strtolower($out), 'error') && !str_contains(strtolower($out), 'denied'));
    wh_log($pdo, $srvId, $sshOk ? 'warn' : 'err',
        $sshOk ? "$hostname: shutdown via SSH ($reason)" : "$hostname: SSH error ($reason) — $out");
    return $sshOk ? 'ssh' : 'error';
}

// ── Servidores UPS managed ───────────────────────────────────
$upsManagedServers = $pdo->query(
    "SELECT * FROM servers WHERE ups_managed=1 ORDER BY ups_priority ASC"
)->fetchAll();

$affectedLog = [];

// ── Lógica por evento ────────────────────────────────────────
switch ($event) {

    case 'onbatt':
        $delaySec = intval(getSetting($pdo, 'ups_shutdown_delay_sec', '0'));
        wh_log($pdo, null, 'warn',
            "UPS '$upsName': on battery" . ($delaySec > 0 ? " — timer $delaySec s before shutdown" : " — immediate shutdown"));

        $pendingHosts = [];

        foreach ($upsManagedServers as $srv) {
            $isOn = wh_pingHost($srv['ip']);
            $pdo->prepare("UPDATE servers SET ups_last_state=? WHERE id=?")
                ->execute([$isOn ? 'online' : 'offline', $srv['id']]);

            if (!$isOn) continue;

            // Último recurso: solo apagar en lowbatt, nunca en onbatt
            if (intval($srv['ups_last_resort'] ?? 0)) {
                wh_log($pdo, $srv['id'], 'info', "{$srv['hostname']}: marked as last resort — skipped on ONBATT, will only shut down on low battery");
                $affectedLog[] = ['hostname' => $srv['hostname'], 'priority' => $srv['ups_priority'], 'result' => 'last_resort_skip'];
                continue;
            }

            $ignoreDelay = intval($srv['ups_ignore_delay'] ?? 0);

            if ($delaySec > 0 && !$ignoreDelay) {
                // Encolar para que el cron lo procese después del delay
                $pendingHosts[] = [
                    'id'       => $srv['id'],
                    'hostname' => $srv['hostname'],
                    'priority' => $srv['ups_priority'],
                ];
                // Marcar pending action para que el frontend sepa que es UPS cuando aparezca offline
                wh_setPendingAction($pdo, $srv['id'], 'ups_shutdown_timer');
                $affectedLog[] = ['hostname' => $srv['hostname'], 'priority' => $srv['ups_priority'], 'result' => 'pending'];
            } else {
                // Apagar inmediatamente (sin timer o ignore_delay=1)
                wh_setPendingAction($pdo, $srv['id'], 'ups_shutdown');
                $result = wh_shutdownServer($pdo, $srv, $ignoreDelay ? "UPS onbatt (no timer)" : "UPS onbatt");
                $affectedLog[] = ['hostname' => $srv['hostname'], 'priority' => $srv['ups_priority'], 'result' => $result];
            }
        }

        // Guardar estado UPS y hosts pendientes en settings
        wh_setSetting($pdo, 'ups_current_state', 'onbatt');
        wh_setSetting($pdo, 'ups_onbatt_since', (string)time());
        wh_setSetting($pdo, 'ups_name_active', $upsName);
        wh_setSetting($pdo, 'ups_pending_hosts', json_encode($pendingHosts));
        break;

    case 'lowbatt':
        wh_log($pdo, null, 'err', "UPS '$upsName': low battery — immediate shutdown of all hosts");
        foreach ($upsManagedServers as $srv) {
            $isOn = wh_pingHost($srv['ip']);
            $pdo->prepare("UPDATE servers SET ups_last_state=? WHERE id=?")
                ->execute([$isOn ? 'online' : 'offline', $srv['id']]);
            if ($isOn) {
                wh_setPendingAction($pdo, $srv['id'], 'ups_shutdown');
                $result = wh_shutdownServer($pdo, $srv, "UPS lowbatt");
                $affectedLog[] = ['hostname' => $srv['hostname'], 'priority' => $srv['ups_priority'], 'result' => $result];
            }
        }
        // Limpiar timer pendiente — ya apagamos todo
        wh_setSetting($pdo, 'ups_current_state', 'lowbatt');
        wh_setSetting($pdo, 'ups_pending_hosts', '[]');
        break;

    case 'online':
        $prevState = getSetting($pdo, 'ups_current_state', '');
        wh_log($pdo, null, 'ok', "UPS '$upsName': power restored — cancelling timer and waking hosts");

        // Cancelar timer pendiente
        wh_setSetting($pdo, 'ups_current_state', 'online');
        wh_setSetting($pdo, 'ups_pending_hosts', '[]');
        wh_setSetting($pdo, 'ups_onbatt_since', '');

        foreach ($upsManagedServers as $srv) {
            if ($srv['ups_last_state'] !== 'online') continue;
            $mac  = $srv['mac'] ?? '';
            $macs = array_values(array_filter(
                array_map(fn($m) => str_replace([':', '-'], '', trim($m)), explode(',', $mac)),
                fn($m) => strlen($m) === 12
            ));
            $ok = false;
            foreach ($macs as $m) {
                if (wh_sendWOL($m)) $ok = true;
            }
            wh_log($pdo, $srv['id'], $ok ? 'ok' : 'err',
                $ok ? "{$srv['hostname']}: WoL sent (UPS online)" : "{$srv['hostname']}: error sending WoL");
            $affectedLog[] = ['hostname' => $srv['hostname'], 'result' => $ok ? 'wol_sent' : 'error'];
        }
        break;

    case 'shutdown':
        wh_log($pdo, null, 'info', "UPS '$upsName': shutdown signal received — no additional action");
        break;
}

// ── Registrar en ups_events ──────────────────────────────────
$pdo->prepare(
    "INSERT INTO ups_events (event, ups_name, hosts_affected, status, created_at) VALUES (?,?,?,?,?)"
)->execute([
    $event,
    $upsName,
    json_encode($affectedLog),
    'processed',
    gmdate('Y-m-d H:i:s'),
]);

wh_ok("Event '$event' processed", ['affected' => count($affectedLog)]);
