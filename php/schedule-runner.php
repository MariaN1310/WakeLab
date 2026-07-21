#!/usr/bin/env php
<?php
$projectRoot = __DIR__;

// ── Lock de proceso — previene ejecuciones simultáneas del cron ──
$_runnerLock = fopen(sys_get_temp_dir() . '/wakelab-schedule-runner.lock', 'c');
if (!$_runnerLock || !flock($_runnerLock, LOCK_EX | LOCK_NB)) {
    echo '[' . gmdate('Y-m-d H:i:s') . '] [info] schedule-runner already running — exiting' . PHP_EOL;
    exit(0);
}

require $projectRoot . '/db.php';
require_once $projectRoot . '/hyp_client.php';
require_once $projectRoot . '/pve.php';
require_once $projectRoot . '/pbs.php';
require_once $projectRoot . '/truenas.php';
require_once $projectRoot . '/omv.php';
require_once $projectRoot . '/notify.php';

function pushSchedule(PDO $pdo, string $hostname, string $action, string $time): void {
    $isShut = $action !== 'boot';
    $icon   = '⏰';
    $verb   = $isShut ? 'Shutdown signal sent' : 'Boot signal sent';
    $ipRow  = $pdo->prepare("SELECT ip FROM servers WHERE hostname=? LIMIT 1");
    $ipRow->execute([$hostname]);
    $ip = (string)($ipRow->fetchColumn() ?: '');
    WakeNotify::notifyAll($pdo, [
        'title'          => "$icon $hostname — schedule $time",
        'body'           => "$verb to $hostname",
        'hostname'       => $hostname,
        'ip'             => $ip,
        'pending_action' => $isShut ? 'schedule_shutdown' : 'schedule_wol',
        'tag'            => 'schedule-' . $hostname,
        'url'            => './',
    ], 'schedule');
}

function setPendingAction(PDO $pdo, int $srvId, string $action): void {
    $val = json_encode(['action' => $action, 'ts' => time()]);
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
        ->execute(['pa_' . $srvId, $val]);
}

const LOCK_TTL      = 30;
const LOCK_PRIORITY = ['manual' => 1, 'schedule' => 2, 'idle' => 3];

function tryAcquireLock(PDO $pdo, int $srvId, string $source): bool {
    $key  = 'lock_' . $srvId;
    $prio = LOCK_PRIORITY[$source] ?? 1;
    $row  = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
    $row->execute([$key]);
    $existing = $row->fetchColumn();
    if ($existing) {
        $lock = json_decode($existing, true);
        $age  = time() - ($lock['ts'] ?? 0);
        if ($age < LOCK_TTL) {
            $existingPrio = LOCK_PRIORITY[$lock['source'] ?? 'manual'] ?? 1;
            if ($prio <= $existingPrio) return false;
        }
    }
    $val = json_encode(['source' => $source, 'ts' => time()]);
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
        ->execute([$key, $val]);
    return true;
}

function releaseLock(PDO $pdo, int $srvId): void {
    $pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute(['lock_' . $srvId]);
}

// TZ: env var tiene prioridad; fallback a DB settings; fallback a UTC
$tzEnv = getenv('TZ');
if (!$tzEnv) {
    try {
        $tzRow = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='timezone'");
        $tzRow->execute();
        $tzDb = (string)($tzRow->fetchColumn() ?: '');
        if ($tzDb) $tzEnv = $tzDb;
    } catch (Throwable) {}
}
if ($tzEnv) date_default_timezone_set($tzEnv);
$tz = new DateTimeZone($tzEnv ?: date_default_timezone_get() ?: 'UTC');
$now     = new DateTime('now', $tz);
$nowTime = $now->format('H:i');   // HH:MM exacto — schedules siempre son :00 o :30
$dayMap  = ['Mon'=>'mon','Tue'=>'tue','Wed'=>'wed','Thu'=>'thu','Fri'=>'fri','Sat'=>'sat','Sun'=>'sun'];
$todayKey = $dayMap[$now->format('D')] ?? '';

echo '[' . gmdate('Y-m-d H:i:s') . '] [tick] runner OK — hora local: ' . $nowTime . PHP_EOL;

// ── Helpers ─────────────────────────────────────────────────

function logEv(PDO $pdo, ?int $srvId, string $level, string $msg): void {
	$ts = gmdate('Y-m-d H:i:s');
	echo "[$ts] [$level] $msg\n";
	$pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (?,?,?,?)")->execute([$srvId,$level,$msg,$ts]);
}

function getSettingVal(PDO $pdo, string $key, string $default = ''): string {
	$st = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
	$st->execute([$key]);
	$v = $st->fetchColumn();
	return $v !== false ? (string)$v : $default;
}

function isSafeHost(string $host): bool {
	return (bool)(filter_var($host, FILTER_VALIDATE_IP)
			   || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $host));
}

function sendWOL(string $mac, string $broadcast = '255.255.255.255'): bool {
	if (!function_exists('socket_create')) return false;
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

function sshCommand(string $ip, string $cmd): string {
	if (!isSafeHost($ip)) return "Error: invalid host: {$ip}";
	$ssh = "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes "
		 . escapeshellarg("root@{$ip}") . " " . escapeshellarg($cmd) . " 2>&1";
	return shell_exec($ssh) ?? '';
}

/** SSH con usuario/contraseña o clave pública según credenciales guardadas en settings. */
function sshShutdown(PDO $pdo, int $srvId, string $ip): string {
	if (!isSafeHost($ip)) return "Error: invalid host: {$ip}";

	// Cargar credenciales SSH guardadas (igual que deploy_idle_script)
	$rows = $pdo->query(
		"SELECT `key`, value FROM settings
		 WHERE `key` IN ('srv_{$srvId}_ssh_user','srv_{$srvId}_ssh_pass','srv_{$srvId}_ssh_port')"
	)->fetchAll();
	$creds = array_column($rows, 'value', 'key');

	$sshUser = wlDecrypt($creds["srv_{$srvId}_ssh_user"] ?? '');
	$sshPass = wlDecrypt($creds["srv_{$srvId}_ssh_pass"] ?? '');
	$sshPort = intval($creds["srv_{$srvId}_ssh_port"] ?? 22) ?: 22;

	// Comando: intentar shutdown con sudo si no es root, sin sudo si es root
	$shutCmd = ($sshUser && $sshUser !== 'root')
		? 'sudo /sbin/shutdown -h now "wakelab: schedule shutdown"'
		: '/sbin/shutdown -h now "wakelab: schedule shutdown"';

	$portArg = $sshPort !== 22 ? " -p {$sshPort}" : '';
	$keyArg  = file_exists('/var/www/.ssh/id_ed25519') ? ' -i /var/www/.ssh/id_ed25519' : '';

	if ($sshUser && $sshPass) {
		// Autenticación por contraseña (sshpass)
		$sshpassBin = trim((string)shell_exec('which sshpass 2>/dev/null'));
		if (!$sshpassBin) return 'Error: sshpass not available';
		$user = $sshUser ?: 'root';
		$ssh  = $sshpassBin . ' -p ' . escapeshellarg($sshPass)
			  . ' ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5' . $portArg
			  . ' ' . escapeshellarg("{$user}@{$ip}")
			  . ' ' . escapeshellarg($shutCmd) . ' 2>&1';
	} elseif ($sshUser) {
		// Clave pública, usuario personalizado
		$ssh = 'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes' . $keyArg . $portArg
			 . ' ' . escapeshellarg("{$sshUser}@{$ip}")
			 . ' ' . escapeshellarg($shutCmd) . ' 2>&1';
	} else {
		// Fallback: root + clave pública de WakeLab
		$ssh = 'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes' . $keyArg . $portArg
			 . ' ' . escapeshellarg("root@{$ip}")
			 . ' ' . escapeshellarg($shutCmd) . ' 2>&1';
	}

	return shell_exec($ssh) ?? '';
}

function pingHost(string $ip): bool {
	if (!isSafeHost($ip)) return false;
	// Intentar ping primero, caer a TCP si no está disponible
	$pingBin = trim((string)shell_exec('which ping 2>/dev/null'));
	if ($pingBin) {
		$out = shell_exec("ping -c1 -W2 " . escapeshellarg($ip) . " 2>/dev/null");
		return str_contains((string)$out, '1 received') || str_contains((string)$out, '1 packets received');
	}
	// Fallback TCP: probar puertos comunes
	foreach ([22, 80, 443, 8006, 8007] as $port) {
		$sock = @fsockopen($ip, $port, $errno, $errstr, 2);
		if ($sock) { fclose($sock); return true; }
	}
	return false;
}

function makePVEClient(PDO $pdo, int $serverId): ?PVEClient {
	$tok = $pdo->prepare("SELECT * FROM api_tokens WHERE server_id = ?");
	$tok->execute([$serverId]);
	$t = $tok->fetch();
	if (!$t || $t['auth_type'] !== 'pve_token') return null;

	$srv = $pdo->prepare("SELECT ip, port FROM servers WHERE id = ?");
	$srv->execute([$serverId]);
	$s = $srv->fetch();
	if (!$s) return null;

	return new PVEClient($s['ip'], (int)$s['port'], $t['api_user'], $t['token_id'], wlDecrypt($t['token_secret']));
}

// ════════════════════════════════════════════════════════════
// SECCIÓN 1: Schedules de servidores
// ════════════════════════════════════════════════════════════
$schedules = $pdo->query("
	SELECT s.*, sch.boot_time, sch.shutdown_time, sch.method, sch.days_json,
	       sch.active, sch.shutdown_active
	FROM servers s
	INNER JOIN schedules sch ON s.id = sch.server_id
	WHERE (sch.active = 1 OR sch.shutdown_active = 1)
	  AND (sch.boot_time IS NOT NULL OR sch.shutdown_time IS NOT NULL)
")->fetchAll();

if (!empty($schedules)) {
	foreach ($schedules as $srv) {
		$srvId        = $srv['id'];
		$hostname     = $srv['hostname'];
		$ip           = $srv['ip'];
		$mac          = $srv['mac'];
		$method       = $srv['method'] ?? 'Wake on LAN';
		$days         = json_decode($srv['days_json'] ?? '[]', true) ?? [];
		$proxmoxVmid  = intval($srv['proxmox_vmid']      ?? 0);
		$proxmoxSrvId = intval($srv['proxmox_server_id'] ?? 0);
		$isVm         = $proxmoxVmid > 0 && $proxmoxSrvId > 0;

		if (!empty($days) && !in_array($todayKey, $days)) {
			continue;
		}

		$bootTime = $srv['boot_time']     ? substr($srv['boot_time'], 0, 5) : null;
		$shutTime = $srv['shutdown_time'] ? substr($srv['shutdown_time'], 0, 5) : null;
		if ($isVm) {
			$pveCheck = makePVEClient($pdo, $proxmoxSrvId);
			$isOnline = $pveCheck ? ($pveCheck->getGuestStatus($proxmoxVmid) === 'running') : pingHost($ip);
		} else {
			$isOnline = pingHost($ip);
		}

		$bootActive = intval($srv['active'] ?? 0);
		$shutActive = intval($srv['shutdown_active'] ?? 0);

		// Boot dependency — encender dep si está offline antes de bootear el target
		if ($bootActive && $bootTime && $nowTime === $bootTime && !$isOnline) {
			$depId = intval($srv['depends_on_server_id'] ?? 0);
			if ($depId) {
				$depStmt = $pdo->prepare("SELECT * FROM servers WHERE id=?");
				$depStmt->execute([$depId]);
				$dep = $depStmt->fetch();
				if ($dep && !pingHost($dep['ip'])) {
					$ok = sendWOL($dep['mac']);
					logEv($pdo, $depId, 'info', ($ok ? "WoL sent to {$dep['hostname']}" : "Error sending WoL to {$dep['hostname']}") . " (required to boot {$hostname})");
				}
			}
		}

		// Boot
		if ($bootActive && $bootTime && $nowTime === $bootTime && $isOnline) {
			logEv($pdo, $srvId, 'info', "$hostname: scheduled boot at $bootTime — already online, skipping WoL");
		}
		if ($bootActive && $bootTime && $nowTime === $bootTime && !$isOnline) {
			if (!tryAcquireLock($pdo, $srvId, 'schedule')) {
				logEv($pdo, $srvId, 'info', "$hostname: scheduled boot skipped — action in progress");
				continue;
			}
			setPendingAction($pdo, $srvId, 'schedule_wol');
			logEv($pdo, $srvId, 'info', "$hostname: scheduled boot at $bootTime via " . ($isVm ? 'Proxmox API' : $method));
			if ($isVm) {
				$pve = makePVEClient($pdo, $proxmoxSrvId);
				if ($pve) {
					$ok  = $pve->startGuest($proxmoxVmid);
					$err = $ok ? '' : ' — ' . ($pve->lastError['body'] ?? 'unknown error');
					logEv($pdo, $srvId, $ok?'ok':'err', $ok ? "$hostname: VM $proxmoxVmid started successfully" : "$hostname: error starting VM $proxmoxVmid$err");
					if ($ok) pushSchedule($pdo, $hostname, 'boot', $bootTime);
				} else {
					logEv($pdo, $srvId, 'err', "$hostname: no Proxmox token configured for host #{$proxmoxSrvId}");
				}
			} elseif ($method === 'Wake on LAN' || $method === 'WOL') {
				$ok = sendWOL($mac);
				logEv($pdo, $srvId, $ok?'ok':'err', $ok ? "$hostname: Wake-on-LAN sent" : "$hostname: error sending Wake-on-LAN");
				if ($ok) pushSchedule($pdo, $hostname, 'boot', $bootTime);
			} elseif ($method === 'SSH') {
				logEv($pdo, $srvId, 'warn', "$hostname: SSH method not available for boot — configure Wake-on-LAN");
			} elseif ($method === 'Proxmox API') {
				logEv($pdo, $srvId, 'warn', "$hostname: Proxmox API selected but VMID or Proxmox server not configured");
			}
			releaseLock($pdo, $srvId);
		}

		// Shutdown — re-evaluar online porque $isOnline puede ser viejo (calculado antes del boot)
		$isOnlineForShut = $isVm
			? (($pveCheck2 = makePVEClient($pdo, $proxmoxSrvId)) ? ($pveCheck2->getGuestStatus($proxmoxVmid) === 'running') : pingHost($ip))
			: pingHost($ip);
		if ($shutActive && $shutTime && $nowTime === $shutTime && $isOnlineForShut) {
			if (!tryAcquireLock($pdo, $srvId, 'schedule')) {
				logEv($pdo, $srvId, 'info', "$hostname: scheduled shutdown skipped — action in progress");
				continue;
			}
			setPendingAction($pdo, $srvId, 'schedule_shutdown');
			if ($isVm) {
				// VM en Proxmox: intentar API graceful primero, luego SSH como respaldo
				$pve = makePVEClient($pdo, $proxmoxSrvId);
				$apiOk = false;
				if ($pve) {
					$apiOk = $pve->shutdownGuest($proxmoxVmid);
					$err   = $apiOk ? '' : ' — ' . ($pve->lastError['body'] ?? 'unknown error');
					logEv($pdo, $srvId, 'info', $apiOk
						? "$hostname: scheduled shutdown ($shutTime) sent via Proxmox API"
						: "$hostname: scheduled shutdown ($shutTime) — Proxmox API failed$err, trying SSH");
				} else {
					logEv($pdo, $srvId, 'warn', "$hostname: no Proxmox token for host #{$proxmoxSrvId}, using SSH for shutdown");
				}
				// SSH como respaldo (necesario si el guest no responde ACPI)
				$out   = sshShutdown($pdo, $srvId, $ip);
				$sshOk = trim($out) === '' || (!str_contains(strtolower($out), 'error') && !str_contains(strtolower($out), 'denied'));
				logEv($pdo, $srvId, $sshOk?'warn':'err', $sshOk
					? "$hostname: shutdown sent via SSH"
					: "$hostname: SSH shutdown error" . (trim($out) ? ' — ' . trim($out) : ''));
				if ($apiOk || $sshOk) pushSchedule($pdo, $hostname, 'shutdown', $shutTime);
			} else {
				// Bare metal: SSH directo con credenciales guardadas
				logEv($pdo, $srvId, 'info', "$hostname: scheduled shutdown at $shutTime via SSH");
				$out = sshShutdown($pdo, $srvId, $ip);
				$ok  = trim($out) === '' || (!str_contains(strtolower($out), 'error') && !str_contains(strtolower($out), 'denied'));
				logEv($pdo, $srvId, $ok?'warn':'err', $ok
					? "$hostname: shutdown sent via SSH"
					: "$hostname: SSH shutdown error" . (trim($out) ? ' — ' . trim($out) : ''));
				if ($ok) pushSchedule($pdo, $hostname, 'shutdown', $shutTime);
			}
			releaseLock($pdo, $srvId);
		}
	}
}

// ════════════════════════════════════════════════════════════
// SECCIÓN 2: Guest schedules (VMs / LXCs via Proxmox API)
// ════════════════════════════════════════════════════════════
$guestScheds = $pdo->query("
	SELECT gs.*, s.hostname AS srv_hostname, s.ip AS srv_ip, s.port AS srv_port, s.hypervisor_type
	FROM guest_schedules gs
	INNER JOIN servers s ON s.id = gs.server_id
	WHERE (gs.boot_active = 1 OR gs.shutdown_active = 1)
	  AND (gs.boot_time IS NOT NULL OR gs.shutdown_time IS NOT NULL)
	ORDER BY gs.server_id, gs.vmid
")->fetchAll();

if (!empty($guestScheds)) {
	$pveClients = [];

	foreach ($guestScheds as $gs) {
		$srvId     = $gs['server_id'];
		$vmid      = (int)$gs['vmid'];
		$vmtype    = $gs['vmtype'];
		$typeLabel = $vmtype === 'lxc' ? 'LXC' : 'VM';
		$labelBase = "{$gs['srv_hostname']} / {$typeLabel} {$vmid}";
		$bootTime  = $gs['boot_time']     ? substr($gs['boot_time'], 0, 5)     : null;
		$shutTime  = $gs['shutdown_time'] ? substr($gs['shutdown_time'], 0, 5) : null;

		if ($gs['hypervisor_type'] !== 'pve') {
			logEv($pdo, $srvId, 'warn', "$labelBase: only Proxmox guests are supported (detected type: {$gs['hypervisor_type']})");
			continue;
		}

		if (!isset($pveClients[$srvId])) {
			$pveClients[$srvId] = makePVEClient($pdo, $srvId);
		}
		$pve = $pveClients[$srvId];

		if (!$pve) {
			logEv($pdo, $srvId, 'err', "$labelBase: no Proxmox token configured — cannot run schedule");
			continue;
		}

		$node      = $pve->getFirstNode();
		$guests    = $pve->getGuests($node);
		$guestInfo = null;
		foreach ($guests as $g) {
			if ((int)($g['vmid'] ?? 0) === $vmid) { $guestInfo = $g; break; }
		}

		if (!$guestInfo) {
			logEv($pdo, $srvId, 'warn', "$labelBase: VMID $vmid not found in Proxmox — check configuration");
			continue;
		}

		$guestName = $guestInfo['name'] ?? '';
		$label     = $guestName ? "{$gs['srv_hostname']} / {$typeLabel} {$vmid} ({$guestName})" : $labelBase;

		$status   = $guestInfo['status'] ?? 'unknown';
		$isOnline = ($status === 'running');

		// Boot
		if ($bootTime && $nowTime === $bootTime && !$isOnline && intval($gs['boot_active'] ?? 0)) {
			logEv($pdo, $srvId, 'info', "$label: scheduled boot at $bootTime");
			$ok  = $pve->startGuest($vmid, $vmtype, $node);
			$err = $ok ? '' : ' — ' . ($pve->lastError['body'] ?? 'unknown error');
			logEv($pdo, $srvId, $ok?'ok':'err', $ok ? "$label: started successfully" : "$label: error starting$err");
			if ($ok) pushSchedule($pdo, $label, 'boot', $bootTime);
		}

		// Shutdown
		if ($shutTime && $nowTime === $shutTime && $isOnline && intval($gs['shutdown_active'] ?? 0)) {
			setPendingAction($pdo, $srvId, 'schedule_shutdown');
			logEv($pdo, $srvId, 'info', "$label: scheduled shutdown at $shutTime");
			$ok  = $pve->shutdownGuest($vmid, $vmtype, $node);
			$err = $ok ? '' : ' — ' . ($pve->lastError['body'] ?? 'unknown error');
			logEv($pdo, $srvId, $ok?'warn':'err', $ok ? "$label: shutdown sent" : "$label: error shutting down$err");
			if ($ok) pushSchedule($pdo, $label, 'shutdown', $shutTime);
		}
	}
}

// ══════════════════════════════════════════════════════════════
// UPS — procesar apagados pendientes por timer
// ══════════════════════════════════════════════════════════════
$upsState     = '';
$onbattSince  = 0;
$pendingHosts = [];
$delaySec     = 0;

try {
    $upsRows = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('ups_current_state','ups_onbatt_since','ups_pending_hosts','ups_shutdown_delay_sec')")->fetchAll();
    $upsMap  = array_column($upsRows, 'value', 'key');
    $upsState    = $upsMap['ups_current_state']      ?? '';
    $onbattSince = intval($upsMap['ups_onbatt_since'] ?? 0);
    $delaySec    = intval($upsMap['ups_shutdown_delay_sec'] ?? 0);
    $pendingHosts = json_decode($upsMap['ups_pending_hosts'] ?? '[]', true) ?: [];
} catch (Throwable) {}

if ($upsState === 'onbatt' && $delaySec > 0 && $onbattSince > 0 && count($pendingHosts) > 0) {
    $elapsed = time() - $onbattSince;
    $remaining = $delaySec - $elapsed;
    if ($remaining > 0) {
        logEv($pdo, null, 'info', "UPS timer: on battery — {$remaining}s remaining before shutting down pending hosts");
    } else {
        logEv($pdo, null, 'warn', "UPS timer expired ({$elapsed}s) — shutting down pending hosts");

        // Ordenar por prioridad
        usort($pendingHosts, fn($a, $b) => ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10));

        foreach ($pendingHosts as $ph) {
            $srvRow = $pdo->prepare("SELECT * FROM servers WHERE id=?");
            $srvRow->execute([$ph['id']]);
            $srv = $srvRow->fetch();
            if (!$srv) continue;

            // Verificar si sigue online (puede que ya se haya apagado solo)
            if (!pingHost($srv['ip'])) {
                logEv($pdo, $srv['id'], 'info', "{$srv['hostname']}: already offline, no action");
                continue;
            }

            // Marcar pending action para que el frontend lo muestre como apagado por UPS
            setPendingAction($pdo, $srv['id'], 'ups_shutdown_timer');

            $out   = sshShutdown($pdo, $srv['id'], $srv['ip']);
            $sshOk = trim($out) === '' || (!str_contains(strtolower($out), 'error') && !str_contains(strtolower($out), 'denied'));
            logEv($pdo, $srv['id'], $sshOk ? 'warn' : 'err',
                $sshOk ? "{$srv['hostname']}: shutdown via SSH (UPS timer)" : "{$srv['hostname']}: SSH error UPS timer — $out");
        }

        // Limpiar hosts pendientes — ya procesados
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('ups_pending_hosts','[]') ON DUPLICATE KEY UPDATE `value`='[]'")->execute();
    }
}

?>
