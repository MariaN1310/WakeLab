<?php
ini_set('display_errors', '0');
error_reporting(0);
session_start();
require 'db.php';
try {
    $_tzRow = $pdo->query("SELECT `value` FROM settings WHERE `key`='timezone' LIMIT 1")->fetch();
    $_tz = ($_tzRow && $_tzRow['value']) ? $_tzRow['value'] : (getenv('TZ') ?: 'UTC');
} catch (Throwable) {
    $_tz = getenv('TZ') ?: 'UTC';
}
date_default_timezone_set($_tz);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hyp_client.php';
require_once __DIR__ . '/pve.php';
require_once __DIR__ . '/pbs.php';
require_once __DIR__ . '/truenas.php';
require_once __DIR__ . '/omv.php';
header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
// For POST requests, action must come from the JSON body; GET requests use the querystring.
// Never allow ?action= to override a POST body — prevents action-smuggling.
$action = ($_SERVER['REQUEST_METHOD'] === 'POST')
    ? ($data['action'] ?? null)
    : ($_GET['action'] ?? null);

// Acciones públicas — no requieren sesión (idle scripts, wake-proxy)
const PUBLIC_ACTIONS   = ['idle_active', 'idle_config', 'wake_proxy_status', 'wake_proxy_retry'];
const INTERNAL_ACTIONS = ['idle_event', 'wake_timeout_event'];

// Acciones internas — requieren WAKELAB_SECRET via header X-Internal-Token
if (in_array($action, INTERNAL_ACTIONS, true)) {
    $secret   = getenv('WAKELAB_SECRET') ?: '';
    $received = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
    if ($secret === '' || !hash_equals($secret, $received)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
} elseif (!in_array($action, PUBLIC_ACTIONS, true) && !tryRestoreSession()) {
    // Kiosk token auth — permite acciones de rack sin sesión
    $kioskActions = ['get_status', 'server_action', 'set_schedule_active', 'set_shutdown_active', 'set_idle_active'];
    if (in_array($action, $kioskActions, true)) {
        $kioskHeader = $_SERVER['HTTP_X_KIOSK_TOKEN'] ?? '';
        if ($kioskHeader !== '') {
            try {
                $kRow = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='kiosk_token' LIMIT 1");
                $kRow->execute();
                $kStored = (string)($kRow->fetchColumn() ?: '');
                if ($kStored === '' || !hash_equals($kStored, $kioskHeader)) {
                    http_response_code(401);
                    echo json_encode(['status' => 'error', 'message' => 'Invalid kiosk token']);
                    exit;
                }
                // Token válido — continuar
            } catch (Throwable) {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
                exit;
            }
        } else {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
            exit;
        }
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        exit;
    }
}

// Liberar lock de sesión para acciones que no escriben $_SESSION.
// Evita bloquear requests concurrentes (index.php, etc.) durante pings largos.
// update_profile es la única acción que escribe sesión — la excluimos.
if ($action !== 'update_profile') {
    session_write_close();
}

// ─────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────

// Cache por request para settings (evita N queries por key)
$_settingsCache = [];

function getSetting(PDO $pdo, string $key, string $default = ''): string {
	global $_settingsCache;
	if (array_key_exists($key, $_settingsCache)) {
		return $_settingsCache[$key];
	}
	try {
		$s = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?");
		$s->execute([$key]);
		$row = $s->fetch();
		$val = $row ? wlDecrypt($row['value']) : $default;
		$_settingsCache[$key] = $val;
	} catch (Throwable $e) {
		$_settingsCache[$key] = $default;
	}
	return $_settingsCache[$key];
}

function logEv(PDO $pdo, ?int $srvId, string $level, string $msg): void {
	try {
		$pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (?,?,?,?)")
			->execute([$srvId, $level, $msg, gmdate('Y-m-d H:i:s')]);
		// Podar solo cada 50 inserciones — evita COUNT(*) en cada log
		if ((int)$pdo->lastInsertId() % 50 === 0) {
			$retention = max(100, intval(getSetting($pdo, 'event_retention', '1000')));
			$count = (int)$pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
			if ($count > $retention) {
				$pdo->exec("DELETE FROM events ORDER BY id ASC LIMIT " . ($count - $retention));
			}
		}
	} catch (Throwable $e) { /* silencioso — no romper flujo */ }
}

function getToken(PDO $pdo, int $srvId): ?array {
	$s = $pdo->prepare("SELECT * FROM api_tokens WHERE server_id=?");
	$s->execute([$srvId]);
	$row = $s->fetch();
	if ($row && isset($row['token_secret'])) {
		$row['token_secret'] = wlDecrypt($row['token_secret']);
	}
	return $row ?: null;
}

// ── Signal lock: evita señales paralelas al mismo host ─────────
// TTL 30s. Prioridad: idle(3) > schedule(2) > manual(1).
// tryAcquireLock devuelve true si adquirió el lock, false si ya hay uno activo de igual/mayor prioridad.
const LOCK_TTL = 30;
const LOCK_PRIORITY = ['manual' => 1, 'schedule' => 2, 'idle' => 3];

function tryAcquireLock(PDO $pdo, int $srvId, string $source): bool {
	$key  = 'lock_' . $srvId;
	$prio = LOCK_PRIORITY[$source] ?? 1;

	$row = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
	$row->execute([$key]);
	$existing = $row->fetchColumn();

	if ($existing) {
		$lock = json_decode($existing, true);
		$age  = time() - ($lock['ts'] ?? 0);
		if ($age < LOCK_TTL) {
			$existingPrio = LOCK_PRIORITY[$lock['source'] ?? 'manual'] ?? 1;
			if ($prio <= $existingPrio) return false; // bloqueado por igual o mayor prioridad
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

// ── Pending action: marca que el servidor va a cambiar de estado intencionalmente ──
// Se usa para diferenciar "se apagó por schedule/idle/manual" de "se cayó solo"
function setPendingAction(PDO $pdo, int $srvId, string $action): void {
	$val = json_encode(['action' => $action, 'ts' => time()]);
	$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
		->execute(['pa_' . $srvId, $val]);
}

function injectPendingActions(PDO $pdo, array &$results, array $statusMap = []): void {
	if (empty($results)) return;
	$srvIds = array_column($results, 'id');
	$keys   = array_map(fn($id) => 'pa_' . $id, $srvIds);
	$in     = implode(',', array_fill(0, count($keys), '?'));
	$rows   = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ($in)");
	$rows->execute($keys);
	$pendingMap = []; $toDelete = [];
	$ttl    = 1200; // 20 minutos
	$wolTTL = 300; // 5 minutos — umbral para alerta de WoL fallido
	$wolActions = ['manual_wol', 'schedule_wol'];
	foreach ($rows->fetchAll() as $row) {
		$id     = intval(substr($row['key'], 3));
		$data   = json_decode($row['value'], true);
		$age    = time() - ($data['ts'] ?? 0);
		$action = $data['action'] ?? '';
		$isWol  = in_array($action, $wolActions);
		$curStatus = $statusMap[$id] ?? null;

		if ($age >= $ttl) {
			// TTL expiró — si era WoL y el host aún está offline, loguear alerta
			if ($isWol && $curStatus === 'offline') {
				$hostname = '';
				foreach ($results as $r) { if ($r['id'] == $id) { $hostname = $r['hostname']; break; } }
				logEv($pdo, $id, 'warn', "{$hostname}: no respondió al WoL después de " . round($age / 60) . " min");
			}
			$toDelete[] = $row['key'];
		} elseif ($isWol && $curStatus === 'online') {
			// Host se prendió exitosamente — pasar PA al frontend ANTES de limpiar
			// (si no, el frontend ve pending_action=null y lo trata como "encendido externo")
			$pendingMap[$id] = $action;
			$toDelete[] = $row['key'];
		} elseif ($isWol && $curStatus === 'offline' && $age >= $wolTTL) {
			// 5 min offline tras WoL — alerta temprana, eliminar para no repetir
			$hostname = '';
			foreach ($results as $r) { if ($r['id'] == $id) { $hostname = $r['hostname']; break; } }
			logEv($pdo, $id, 'warn', "{$hostname}: no respondió al WoL después de " . round($age / 60) . " min");
			$toDelete[] = $row['key'];
		} else {
			$pendingMap[$id] = $action;
		}
	}
	foreach ($toDelete as $k) {
		$pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute([$k]);
	}

	// Propagar shutdown del host a sus guests/dependientes (sin PA propio)
	$shutdownActions = ['manual_shutdown','schedule_shutdown','idle_shutdown','ups_shutdown','ups_shutdown_timer'];
	foreach ($results as &$r) {
		if (isset($pendingMap[$r['id']])) continue;
		$hostId  = intval($r['proxmox_server_id']   ?? 0);
		$depId   = intval($r['depends_on_server_id'] ?? 0);
		$parentId = 0;
		if ($hostId && isset($pendingMap[$hostId]) && in_array($pendingMap[$hostId], $shutdownActions)) {
			$parentId = $hostId;
		} elseif ($depId && isset($pendingMap[$depId]) && in_array($pendingMap[$depId], $shutdownActions)) {
			$parentId = $depId;
		}
		if ($parentId) {
			// VMs de Proxmox: usar 'host_shutdown' (el host ya notifica, la VM se silencia)
			// Dependencias lógicas: heredar el PA real para que la notificación refleje la causa
			$pendingMap[$r['id']] = ($parentId === $hostId)
				? 'host_shutdown'
				: $pendingMap[$parentId];
		}
	}
	unset($r);

	foreach ($results as &$r) {
		$r['pending_action'] = $pendingMap[$r['id']] ?? null;
	}
	unset($r);
}

// Respuesta estandarizada
function ok(mixed $data, string $message = ''): string {
	return json_encode(['status' => 'success', 'data' => $data, 'message' => $message]);
}
function err(string $message, int $http = 200): string {
	http_response_code($http);
	return json_encode(['status' => 'error', 'data' => null, 'message' => $message]);
}

// ─────────────────────────────────────────────────────────────
// CACHE de get_status (archivo JSON en /tmp, TTL configurable)
// ─────────────────────────────────────────────────────────────
function getCacheFile(): string {
	return sys_get_temp_dir() . '/homelab_status_cache.json';
}

function readCache(int $ttl): ?array {
	$file = getCacheFile();
	if (!file_exists($file)) return null;
	if ((time() - filemtime($file)) > $ttl) return null;
	$raw = file_get_contents($file);
	if (!$raw) return null;
	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : null;
}


function writeCache(array $data): void {
	$f       = getCacheFile();
	$written = file_put_contents($f, json_encode($data), LOCK_EX);
	if ($written === false) {
		error_log('[WakeLab] writeCache: no se pudo escribir en ' . $f . ' — verificar permisos de ' . sys_get_temp_dir());
	}
}

function invalidateCache(): void {
	$f = getCacheFile();
	if (file_exists($f) && !unlink($f)) {
		error_log('[WakeLab] invalidateCache: no se pudo eliminar ' . $f);
	}
}

// Valida que el host sea una IP o un hostname seguro antes de usarlo en shell_exec.
function isSafeHost(string $host): bool {
	if (strlen($host) > 255) return false;
	return (bool)(filter_var($host, FILTER_VALIDATE_IP)
			   || preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9._-]{0,253}[a-zA-Z0-9])?$/', $host));
}

// Valida que el path remoto sea absoluto y no contenga traversal ni caracteres peligrosos.
function isSafePath(string $path): bool {
	if (!str_starts_with($path, '/')) return false;
	if (str_contains($path, '..'))    return false;
	return (bool)preg_match('/^[a-zA-Z0-9._\/-]+$/', $path);
}

// #13 — Resuelve credenciales SSH: per-server primero, cae a global defaults
function pushSSHKey(string $ip, string $user, string $pass, int $port = 22): ?string {
	$pubFile = '/var/www/.ssh/id_ed25519.pub';
	if (!file_exists($pubFile)) return 'Public key not found';
	$pub = trim(file_get_contents($pubFile));

	$authCmd = sprintf(
		'mkdir -p ~/.ssh && chmod 700 ~/.ssh && ' .
		'grep -qxF %s ~/.ssh/authorized_keys 2>/dev/null || echo %s >> ~/.ssh/authorized_keys && ' .
		'chmod 600 ~/.ssh/authorized_keys && echo AUTHORIZED',
		escapeshellarg($pub), escapeshellarg($pub)
	);
	$opts = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 -p ' . $port;
	$cmd  = sprintf('sshpass -p %s ssh %s %s@%s %s 2>&1',
		escapeshellarg($pass), $opts, escapeshellarg($user), escapeshellarg($ip),
		escapeshellarg($authCmd));

	$out = shell_exec($cmd) ?? '';
	return str_contains($out, 'AUTHORIZED') ? null : trim($out);
}

function resolveSSHCreds(PDO $pdo, int $srvId): array {
	$st = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN (?,?,?)");
	$st->execute(["srv_{$srvId}_ssh_user", "srv_{$srvId}_ssh_pass", "srv_{$srvId}_ssh_port"]);
	$cfg = array_map('wlDecrypt', array_column($st->fetchAll(), 'value', 'key'));
	$defUser = getSetting($pdo, 'ssh_default_user', 'root');
	$defPort = intval(getSetting($pdo, 'ssh_default_port', '22')) ?: 22;
	return [
		'user' => ($cfg["srv_{$srvId}_ssh_user"] ?? '') ?: $defUser,
		'pass' => $cfg["srv_{$srvId}_ssh_pass"] ?? '',
		'port' => isset($cfg["srv_{$srvId}_ssh_port"]) && $cfg["srv_{$srvId}_ssh_port"]
		          ? (intval($cfg["srv_{$srvId}_ssh_port"]) ?: $defPort) : $defPort,
	];
}

// ─────────────────────────────────────────────────────────────

switch ($action) {

// ─────────────────────────────────────────────────────────────
case 'get_status':
	// Pre-cargar todas las settings en cache para evitar N queries individuales
	{
		global $_settingsCache;
		$allRows = $pdo->query("SELECT `key`, `value` FROM settings")->fetchAll();
		foreach ($allRows as $r) {
			if (!array_key_exists($r['key'], $_settingsCache)) {
				$_settingsCache[$r['key']] = wlDecrypt($r['value']);
			}
		}
	}

	$cacheTTL       = intval(getSetting($pdo, 'status_cache_ttl_sec', '25'));
	$apiTimeout     = intval(getSetting($pdo, 'api_timeout_sec', '6'));
	$pingTimeout    = intval(getSetting($pdo, 'ping_timeout_sec', '3'));
	$includeMetrics = ($_GET['metrics'] ?? '1') !== '0';

	// Servir desde cache si es válido (pending_action siempre se inyecta fresco)
	$cached = readCache($cacheTTL);
	if ($cached !== null) {
		injectPendingActions($pdo, $cached);
		echo ok($cached, 'cached');
		break;
	}

	$servers = $pdo->query("SELECT * FROM servers ORDER BY id")->fetchAll();

	// Pre-cargar tokens en un solo batch
	$tokenMap = [];
	foreach ($pdo->query("SELECT * FROM api_tokens")->fetchAll() as $t) {
		if (isset($t['token_secret'])) $t['token_secret'] = wlDecrypt($t['token_secret']);
		$tokenMap[(int)$t['server_id']] = $t;
	}

	// Pre-cargar SSH settings de todos los PCs en un solo batch
	$srvIds = array_column($servers, 'id');
	$pcTypes = ['windows', 'linux'];
	$pcServerIds = array_column(
		array_filter($servers, fn($s) => in_array($s['hypervisor_type'] ?? '', $pcTypes)),
		'id'
	);
	$sshSettingsMap = [];
	if (!empty($pcServerIds)) {
		$sshKeys = [];
		foreach ($pcServerIds as $pid) {
			$sshKeys[] = "srv_{$pid}_ssh_user";
			$sshKeys[] = "srv_{$pid}_ssh_pass";
			$sshKeys[] = "srv_{$pid}_ssh_port";
		}
		$in = implode(',', array_fill(0, count($sshKeys), '?'));
		$st = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ($in)");
		$st->execute($sshKeys);
		$sshSettingsMap = array_map('wlDecrypt', array_column($st->fetchAll(), 'value', 'key'));
	}

	// Pre-cargar mapa de VMs registradas: [pve_server_id][vmid] = {server_id, hostname}
	$vmLinkedMap = [];
	foreach ($pdo->query("SELECT id, hostname, proxmox_server_id, proxmox_vmid FROM servers WHERE proxmox_vmid IS NOT NULL")->fetchAll() as $vlr) {
		$pveId = intval($vlr['proxmox_server_id'] ?? 0);
		$vmid  = intval($vlr['proxmox_vmid']      ?? 0);
		if ($pveId && $vmid) {
			$vmLinkedMap[$pveId][$vmid] = ['server_id' => $vlr['id'], 'hostname' => $vlr['hostname']];
		}
	}

	// Pre-cargar URLs de guest_meta: [server_id][vmid] = url
	$vmUrlMap = [];
	foreach ($pdo->query("SELECT server_id, vmid, url FROM guest_meta WHERE url IS NOT NULL AND url != ''")->fetchAll() as $gmr) {
		$vmUrlMap[intval($gmr['server_id'])][intval($gmr['vmid'])] = $gmr['url'];
	}

	$results      = [];
	$onlineIds    = [];

	foreach ($servers as $srv) {
		$srvId  = $srv['id'];
		$tok    = $tokenMap[$srvId] ?? [];

		if (in_array($srv['hypervisor_type'] ?? '', $pcTypes)) {
			$tok['pc_ssh_user'] = $sshSettingsMap["srv_{$srvId}_ssh_user"] ?? '';
			$tok['pc_ssh_pass'] = $sshSettingsMap["srv_{$srvId}_ssh_pass"] ?? '';
		}

		// Estado inicial: unknown (no sabemos nada aún)
		$status         = 'unknown';
		$vms            = [];
		$node_cpu       = null;
		$node_mem       = null;
		$node_mem_total = null;
		$node_uptime    = null;
		$pve_error      = null;
		$extra          = [];

		try {
			$client = HypFactory::make($srv, $tok);

			// Ping TCP — determina online/offline
			$pingOk = $client->ping();

			if ($pingOk === true) {
				$status      = 'online';
				$onlineIds[] = $srvId;

				// Fetch metrics: API types need token_secret; PC types need api_enabled + SSH
				$isPcType = in_array($srv['hypervisor_type'] ?? '', ['windows', 'linux']);
				$fetchMetrics = $includeMetrics && $srv['api_enabled'] && (
					$isPcType || (!empty($tok['token_secret']))
				);
				if ($fetchMetrics) {
					if ($isPcType) require_once __DIR__ . '/pc.php';
					// Para TrueNAS: getAllExtra() abre la sesión WS y cachea stats + extra en una sola conexión.
					// Debe llamarse antes de getNodeStats() para que éste use el cache.
					$type = $srv['hypervisor_type'] ?? 'pve';
					if ($type === 'truenas' && $client instanceof TrueNASClient) {
						$all = $client->getAllExtra();
					}
					$stats = $client->getNodeStats();

					if ($stats !== null) {
						$node_cpu       = $stats['cpu'];
						$node_mem       = $stats['mem'];
						$node_mem_total = $stats['mem_total'];
						$node_uptime    = $stats['uptime'] ?? null;
						$nodeName       = $stats['node'] ?? '';
					} else {
						// API no responde pero ping OK — anotamos el error
						$pve_error = $client->lastError;
						logEv($pdo, $srvId, 'warn',
							"{$srv['hostname']}: API sin respuesta (HTTP {$client->lastError['http']}) — el servidor responde ping pero la API no");
						$nodeName = '';
					}

					// Guests solo si tenemos el nombre del nodo
					$vms = $client->getGuests($nodeName);

					// Taggear VMs que son servidores registrados (proxmox_vmid coincide) e inyectar URLs
					if (($srv['hypervisor_type'] ?? '') === 'pve') {
						foreach ($vms as &$vm) {
							$vmid = intval($vm['vmid'] ?? 0);
							if (!empty($vmLinkedMap[$srvId][$vmid])) {
								$vm['linked_server_id'] = $vmLinkedMap[$srvId][$vmid]['server_id'];
								$vm['linked_hostname']  = $vmLinkedMap[$srvId][$vmid]['hostname'];
							}
							if (!empty($vmUrlMap[$srvId][$vmid])) {
								$vm['url'] = $vmUrlMap[$srvId][$vmid];
							}
						}
						unset($vm);
					}

					// Extra por tipo ($type ya definido arriba)
					if ($type === 'pbs' && $client instanceof PBSClient) {
						$extra['datastores']   = $client->getDatastores() ?? [];
						$extra['tasks']        = $client->getTasks(25)    ?? [];
					}
					if ($type === 'truenas' && $client instanceof TrueNASClient) {
						// $all ya calculado arriba (antes de getNodeStats)
						$extra['pools']      = $all['pools']     ?? [];
						$extra['apps']       = $all['apps']      ?? [];
						$extra['vms']        = $all['vms']       ?? [];
						$extra['alerts']     = $all['alerts']    ?? [];
						$extra['disks']      = $all['disks']     ?? [];
						$extra['disk_temps'] = $all['diskTemps'] ?? null;
					}
					if ($type === 'omv' && $client instanceof OMVClient) {
						$extra['filesystems'] = $client->getFilesystems() ?? [];
						$extra['disk_temps']  = $client->getDiskTemps()   ?? null;
					}
				}

			} elseif ($pingOk === false) {
				$status = 'offline';

			} else {
				// null = timeout o error de red
				$status = 'unknown';
				logEv($pdo, $srvId, 'warn', "Ping timeout/error para {$srv['hostname']}");
			}

		} catch (Throwable $e) {
			$status    = 'unknown';
			$pve_error = ['exception' => $e->getMessage()];
			logEv($pdo, $srvId, 'err', "Error en get_status para {$srv['hostname']}: " . $e->getMessage());
		}

		$results[] = [
			'id'                    => $srvId,
			'hostname'              => $srv['hostname'],
			'ip'                    => $srv['ip'],
			'role'                  => $srv['role'],
			'hypervisor_type'       => $srv['hypervisor_type'],
			'status'                => $status,
			'ping_ms'               => $client->lastPingMs ?? null,
			'node_cpu'              => $node_cpu,
			'node_mem'              => $node_mem,
			'node_mem_total'        => $node_mem_total,
			'node_disk'             => $stats['disk']       ?? null,
			'node_disk_total'       => $stats['disk_total'] ?? null,
			'node_uptime'           => $node_uptime,
			'vms'                   => $vms,
			'extra'                 => $extra,
			'pve_error'             => $pve_error,
			'shutdown_timeout'      => intval(getSetting($pdo, "srv_{$srvId}_shutdown_timeout", '90')),
			'proxmox_server_id'     => $srv['proxmox_server_id']    ? intval($srv['proxmox_server_id'])    : null,
			'depends_on_server_id'  => $srv['depends_on_server_id'] ? intval($srv['depends_on_server_id']) : null,
		];
	}

	// Batch UPDATE last_seen para todos los servidores online (1 query en vez de N)
	if (!empty($onlineIds)) {
		$in = implode(',', array_fill(0, count($onlineIds), '?'));
		$pdo->prepare("UPDATE servers SET last_seen=NOW() WHERE id IN ($in)")
			->execute($onlineIds);
	}

	// ── #23 Guest unknown alert ──────────────────────────────────
	$unknownAlertMin = intval(getSetting($pdo, 'unknown_guest_alert_min', '10'));
	$now = time();
	// Batch-load existing gu_* keys to avoid N+1 queries
	$guKeys = [];
	foreach ($results as $srv) {
		foreach ($srv['vms'] ?? [] as $vm) {
			$guKeys[] = "gu_{$srv['id']}_{$vm['vmid']}_unk";
		}
	}
	$guMap = [];
	if (!empty($guKeys)) {
		$guIn  = implode(',', array_fill(0, count($guKeys), '?'));
		$guStmt = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ($guIn)");
		$guStmt->execute($guKeys);
		foreach ($guStmt->fetchAll() as $row) $guMap[$row['key']] = (int)$row['value'];
	}
	$guInsert = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	$guDelete = $pdo->prepare("DELETE FROM settings WHERE `key`=?");
	foreach ($results as $srv) {
		foreach ($srv['vms'] ?? [] as $vm) {
			$gKey = "gu_{$srv['id']}_{$vm['vmid']}_unk";
			$vmStatus = $vm['status'] ?? '';
			if ($vmStatus === 'unknown') {
				if (!isset($guMap[$gKey])) {
					$guInsert->execute([$gKey, $now]); // primer unknown — registrar timestamp
				} else {
					$age = $now - $guMap[$gKey];
					if ($age >= $unknownAlertMin * 60) {
						$vmName = $vm['name'] ?? "Guest #{$vm['vmid']}";
						$mins   = round($age / 60);
						logEv($pdo, $srv['id'], 'warn', "{$vmName} (vmid {$vm['vmid']}) en estado unknown por {$mins} min en {$srv['hostname']}");
						require_once __DIR__ . '/notify.php';
						WakeNotify::notifyAll($pdo, [
							'title'    => "❓ Guest sin estado: {$vmName}",
							'body'     => "{$vmName} en {$srv['hostname']} lleva {$mins} min en estado unknown",
							'tag'      => "guest-unknown-{$srv['id']}-{$vm['vmid']}",
							'url'      => './',
							'hostname' => $vmName,
							'ip'       => $srv['ip'],
						], 'guest_unknown');
						$guDelete->execute([$gKey]); // re-arma en el próximo ciclo unknown
					}
				}
			} else {
				// Estado conocido — limpiar tracking
				if (isset($guMap[$gKey])) $guDelete->execute([$gKey]);
			}
		}
	}

	writeCache($results);
	$statusMap = array_column($results, 'status', 'id');
	injectPendingActions($pdo, $results, $statusMap);
	echo ok($results);
	break;

// ─────────────────────────────────────────────────────────────
// Exponer config al frontend (polling interval)
// ─────────────────────────────────────────────────────────────
case 'get_config':
	// Auto-generar wake_proxy_secret si no existe
	$wpSecret = getSetting($pdo, 'wake_proxy_secret', '');
	if ($wpSecret === '') {
		$wpSecret = bin2hex(random_bytes(24));
		$pdo->prepare("INSERT INTO settings (`key`,`value`,`description`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
			->execute(['wake_proxy_secret', $wpSecret, 'Token secreto para autenticar el Wake Proxy']);
		$_settingsCache['wake_proxy_secret'] = $wpSecret;
	}
	echo ok([
		'polling_interval_sec' => intval(getSetting($pdo, 'polling_interval_sec', '30')),
		'api_timeout_sec'      => intval(getSetting($pdo, 'api_timeout_sec',      '6')),
		'status_cache_ttl_sec' => intval(getSetting($pdo, 'status_cache_ttl_sec', '25')),
		'ping_timeout_sec'     => intval(getSetting($pdo, 'ping_timeout_sec',     '3')),
		'wakelab_base_url'          =>        getSetting($pdo, 'wakelab_base_url',          ''),
		'event_retention'           =>        getSetting($pdo, 'event_retention',           '1000'),
		'wake_proxy_splash_mode'    =>        getSetting($pdo, 'wake_proxy_splash_mode',    'detailed'),
		'wake_proxy_max_retries'    =>        getSetting($pdo, 'wake_proxy_max_retries',    '3'),
		'wake_proxy_secret'         =>        $wpSecret,
		'wp_local_only'             =>        getSetting($pdo, 'wp_local_only',             '0'),
		'wp_allowed_ranges'         =>        getSetting($pdo, 'wp_allowed_ranges',         '192.168.0.0/16,10.0.0.0/8,172.16.0.0/12'),
		'wp_blocked_ips'            =>        getSetting($pdo, 'wp_blocked_ips',            ''),
		'wp_block_bots'             =>        getSetting($pdo, 'wp_block_bots',             '0'),
		'wp_blocked_ua'             =>        getSetting($pdo, 'wp_blocked_ua',             ''),
	]);
	break;

// ─────────────────────────────────────────────────────────────
case 'regenerate_wake_proxy_token':
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo err('Method not allowed'); break; }
	$newSecret = bin2hex(random_bytes(24));
	$pdo->prepare("INSERT INTO settings (`key`,`value`,`description`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
		->execute(['wake_proxy_secret', $newSecret, 'Token secreto para autenticar el Wake Proxy']);
	echo ok(['secret' => $newSecret]);
	break;

// ─────────────────────────────────────────────────────────────
case 'clear_events':
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo err('Method not allowed'); break; }
	$pdo->exec("DELETE FROM events");
	echo ok(null, 'Event log cleared');
	break;

// ─────────────────────────────────────────────────────────────
case 'toggle_visibility':
	$srvId   = intval($data['server_id'] ?? 0);
	$visible = ($data['visible'] ?? true) ? '1' : '0';
	if (!$srvId) { echo err('server_id required'); break; }
	$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
		->execute(["srv_{$srvId}_visible", $visible]);
	invalidateCache();
	echo ok(null, 'Visibility updated');
	break;

case 'set_pbs_postbackup':
	$srvId = intval($data['server_id'] ?? 0);
	$val   = intval($data['value'] ?? 0) ? '1' : '0';
	if (!$srvId) { echo err('Invalid ID'); break; }
	$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
		->execute(["srv_{$srvId}_pbs_postbackup", $val]);
	echo ok(null, 'Saved');
	break;

case 'update_setting':
	$key   = trim($data['key']   ?? '');
	$value = trim($data['value'] ?? '');
	$allowed = ['polling_interval_sec','api_timeout_sec','status_cache_ttl_sec','ping_timeout_sec',
	            'wakelab_base_url','event_retention','timezone','timezone_display',
	            'ssh_default_user','ssh_default_port',
	            'ai_enabled','ai_provider','ai_model','ai_api_key',
	            'ai_use_emojis','ai_highlight','ai_tone','ai_no_repeat','ai_extra_context','ai_language',
	            'wake_proxy_splash_mode','wake_proxy_max_retries',
		            'wp_local_only','wp_allowed_ranges','wp_blocked_ips','wp_block_bots','wp_blocked_ua',
		            'ups_webhook_token','ups_shutdown_delay_sec',
            'kiosk_token'];
	if (!in_array($key, $allowed)) {
		echo err("Setting '$key' not allowed");
		break;
	}
	if ($key === 'kiosk_token' && $value !== '' && strlen($value) < 8) {
		echo err('kiosk_token must be at least 8 characters');
		break;
	}
	if ($key === 'ups_webhook_token' && $value !== '' && strlen($value) < 16) {
		echo err('ups_webhook_token must be at least 16 characters');
		break;
	}
	$stored = ($key === 'ai_api_key' && $value !== '') ? wlEncrypt($value) : $value;
	$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
		->execute([$key, $stored]);
	invalidateCache();   // forzar re-fetch con nueva config
	echo ok(null, "Setting '$key' updated");
	break;

// ─────────────────────────────────────────────────────────────
case 'get_ups_events':
	try {
		$rows = $pdo->query("SELECT id,event,ups_name,hosts_affected,status,created_at FROM ups_events ORDER BY created_at DESC LIMIT 50")->fetchAll();
		foreach ($rows as &$r) {
			$r['hosts_affected'] = json_decode($r['hosts_affected'] ?? '[]', true);
		}
		echo ok($rows);
	} catch (PDOException $e) {
		echo ok([]);
	}
	break;

// ─────────────────────────────────────────────────────────────
case 'save_ups_server':
	$srvId       = intval($data['server_id']    ?? 0);
	$upsManaged  = intval($data['ups_managed']   ?? 0) ? 1 : 0;
	$upsPrio     = max(1, min(99, intval($data['ups_priority']    ?? 10)));
	$ignoreDelay = intval($data['ups_ignore_delay'] ?? 0) ? 1 : 0;
	$lastResort  = intval($data['ups_last_resort']  ?? 0) ? 1 : 0;
	if (!$srvId) { echo err('Missing server_id'); break; }
	$pdo->prepare("UPDATE servers SET ups_managed=?, ups_priority=?, ups_ignore_delay=?, ups_last_resort=? WHERE id=?")
		->execute([$upsManaged, $upsPrio, $ignoreDelay, $lastResort, $srvId]);
	echo ok(null, 'UPS settings saved');
	break;

// ─────────────────────────────────────────────────────────────
case 'dismiss_truenas_alert':
	$srvId = intval($data['server_id'] ?? 0);
	$uuid  = trim($data['uuid'] ?? '');
	if (!$srvId || !$uuid) { echo err('Missing server_id or uuid'); break; }
	$srvR = $pdo->prepare("SELECT * FROM servers WHERE id=?"); $srvR->execute([$srvId]);
	$srv  = $srvR->fetch();
	if (!$srv || ($srv['hypervisor_type'] ?? '') !== 'truenas') { echo err('Server not found or not TrueNAS'); break; }
	$tokR = $pdo->prepare("SELECT * FROM api_tokens WHERE server_id=?"); $tokR->execute([$srvId]);
	$tok  = $tokR->fetch();
	if (!$tok) { echo err('No API token configured for this server'); break; }
	try {
		require_once __DIR__ . '/truenas.php';
		$client = HypFactory::make($srv, $tok);
		if (!($client instanceof TrueNASClient)) { echo err('Client is not TrueNAS'); break; }
		$ok = $client->dismissAlert($uuid);
		if ($ok) {
			invalidateCache();
			echo ok(null, 'Alert dismissed');
		} else {
			echo err('Could not dismiss alert — check WebSocket connection');
		}
	} catch (Throwable $e) {
		echo err('Error: ' . $e->getMessage());
	}
	break;

// ─────────────────────────────────────────────────────────────
case 'save_truenas_ssh':
	$srvId   = intval($data['server_id'] ?? 0);
	$sshUser = trim($data['ssh_user'] ?? '');
	$sshPass = $data['ssh_pass'] ?? '';
	$sshPort = intval($data['ssh_port'] ?? 22);
	if ($sshPort < 1 || $sshPort > 65535) $sshPort = 22;

	if (!$srvId) { echo err('server_id required'); break; }

	$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
		->execute(["srv_{$srvId}_ssh_user", $sshUser]);
	$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
		->execute(["srv_{$srvId}_ssh_port", (string)$sshPort]);

	if ($sshPass !== '') {
		$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
			->execute(["srv_{$srvId}_ssh_pass", wlEncrypt($sshPass)]);
	}

	$srvNameR = $pdo->prepare("SELECT hostname, ip FROM servers WHERE id=?"); $srvNameR->execute([$srvId]);
	$srvNameRow = $srvNameR->fetch();
	logEv($pdo, $srvId, 'info', "Credenciales SSH actualizadas para " . ($srvNameRow['hostname'] ?? "servidor #{$srvId}"));

	// Auto-autorizar clave SSH si tenemos contraseña e IP
	$autoMsg = null;
	if ($sshPass !== '' && !empty($srvNameRow['ip'])) {
		$autoMsg = pushSSHKey($srvNameRow['ip'], $sshUser, $sshPass, $sshPort);
	}

	echo ok(['ssh_key_pushed' => $autoMsg === null, 'ssh_key_msg' => $autoMsg], 'SSH saved');
	break;

// ─────────────────────────────────────────────────────────────
case 'test_connection':
	$srvId = intval($data['server_id'] ?? 0);
	$srvR  = $pdo->prepare("SELECT * FROM servers WHERE id=?");
	$srvR->execute([$srvId]);
	$srv = $srvR->fetch();
	if (!$srv) { echo err('Server not found', 404); break; }

	$tok    = getToken($pdo, $srvId);
	$client = HypFactory::make($srv, $tok);
	$steps  = $client->testConnection();
	$isOk   = (bool)(end($steps)['ok'] ?? false);

	logEv($pdo, $srvId, $isOk ? 'ok' : 'warn',
		"Test de conexión " . ($isOk ? 'exitoso' : 'fallido') . " — {$srv['hostname']} ({$srv['hypervisor_type']})");
	echo ok(['ok' => $isOk, 'steps' => $steps, 'type' => $srv['hypervisor_type']]);
	break;

// ─────────────────────────────────────────────────────────────
case 'check_ssh_key':
	$srvId = intval($data['server_id'] ?? 0);
	$srvR  = $pdo->prepare("SELECT * FROM servers WHERE id=?");
	$srvR->execute([$srvId]);
	$srv   = $srvR->fetch();
	if (!$srv) { echo err('Server not found', 404); break; }
	$ip      = $srv['ip'];
	$srvType = $srv['hypervisor_type'] ?? 'pve';
	$isTN    = ($srvType === 'truenas');
	$isOMV   = ($srvType === 'omv');
	if (!trim((string)shell_exec('which ssh 2>/dev/null'))) { echo err('ssh not available on the web server'); break; }
	if (!isSafeHost($ip)) { echo err('Invalid IP'); break; }
	// Resolve credentials (same logic as deploy_idle_script)
	$sshUser = 'root'; $sshPass = ''; $sshPort = 22;
	if ($isTN || $isOMV) {
		try {
			$st = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN (?,?,?)");
			$st->execute(["srv_{$srvId}_ssh_user","srv_{$srvId}_ssh_pass","srv_{$srvId}_ssh_port"]);
			$cfg = [];
			foreach ($st->fetchAll() as $r) $cfg[$r['key']] = wlDecrypt($r['value']);
		} catch (Throwable) { $cfg = []; }
		$sshUser = $cfg["srv_{$srvId}_ssh_user"] ?? 'root';
		if (!$sshUser) $sshUser = 'root';
		$sshPass = $cfg["srv_{$srvId}_ssh_pass"] ?? '';
		$sshPort = intval($cfg["srv_{$srvId}_ssh_port"] ?? 22) ?: 22;
	}
	$hasSshpass  = (bool)trim((string)shell_exec('which sshpass 2>/dev/null'));
	$usePassword = ($isTN || $isOMV) && $sshPass !== '';
	if ($usePassword && !$hasSshpass) { echo err('sshpass not available'); break; }
	$sshpassPfx = $usePassword ? 'sshpass -p ' . escapeshellarg($sshPass) . ' ' : '';
	$portOpt    = $sshPort !== 22 ? "-p {$sshPort}" : '';
	// BatchMode=yes for key auth (fails immediately if no key), no for password
	$batchMode  = $usePassword ? 'no' : 'yes';
	$sshOpts    = "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o BatchMode={$batchMode} {$portOpt}";
	$target     = escapeshellarg("{$sshUser}@{$ip}");
	$raw = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} echo WAKELAB_SSH_OK 2>&1");
	$ok  = str_contains($raw, 'WAKELAB_SSH_OK');
	// Classify error
	$detail = trim($raw);
	if (!$ok) {
		if (str_contains($raw, 'Permission denied') || str_contains($raw, 'publickey'))
			$detail = "❌ Permission denied — SSH key not authorized on {$sshUser}@{$ip}. Add the www-data public key to the server.";
		elseif (str_contains($raw, 'Connection refused'))
			$detail = "❌ Connection refused — SSH not active on {$ip}:{$sshPort}";
		elseif (str_contains($raw, 'No route to host') || str_contains($raw, 'Connection timed out'))
			$detail = "❌ Host unreachable — {$ip}:{$sshPort} not responding";
		elseif (str_contains($raw, 'Could not resolve'))
			$detail = "❌ DNS resolution failed — check server IP";
		else
			$detail = "❌ {$detail}";
	} else {
		$detail = "✅ SSH connection OK — {$sshUser}@{$ip}" . ($sshPort !== 22 ? ":{$sshPort}" : "") . " · " . ($usePassword ? "password" : "SSH key");
	}
	echo ok(['ok' => $ok, 'detail' => $detail]);
	break;

case 'get_logs':
	$limit  = min(intval($_GET['limit'] ?? 50), 500);
	$offset = max(intval($_GET['offset'] ?? 0), 0);
	$srvF   = trim($_GET['server']    ?? '');
	$lvlF   = trim($_GET['level']     ?? '');
	$srvId  = intval($_GET['server_id'] ?? 0);
	$search = trim($_GET['search']    ?? '');

	$where = []; $params = [];
	if ($srvId > 0)       { $where[] = 'e.server_id=?'; $params[] = $srvId; }
	elseif ($srvF !== '') { $where[] = 's.hostname=?';   $params[] = $srvF; }
	if ($lvlF !== '')     { $where[] = 'e.level=?';      $params[] = $lvlF; }
	if ($search !== '')   {
		$search = substr($search, 0, 200);
		$searchEsc = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search);
		$where[] = 'e.message LIKE ?'; $params[] = '%' . $searchEsc . '%';
	}
	$wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

	$stmt = $pdo->prepare(
		"SELECT e.id, e.timestamp, e.level, e.message,
				COALESCE(s.hostname,'system') AS hostname
		 FROM events e
		 LEFT JOIN servers s ON e.server_id = s.id
		 $wSQL
		 ORDER BY e.timestamp DESC LIMIT ? OFFSET ?"
	);
	$params[] = $limit + 1; // fetch one extra to detect if there's more
	$params[] = $offset;
	$stmt->execute($params);
	$rows = $stmt->fetchAll();
	$hasMore = count($rows) > $limit;
	if ($hasMore) array_pop($rows);
	echo ok(['rows' => $rows, 'has_more' => $hasMore, 'offset' => $offset, 'limit' => $limit]);
	break;

// ─────────────────────────────────────────────────────────────
case 'generate_idle_script':
	$srvId = intval($data['server_id'] ?? 0);
	$srvR  = $pdo->prepare("SELECT * FROM servers WHERE id=?"); $srvR->execute([$srvId]);
	$server = $srvR->fetch();
	$idlR  = $pdo->prepare("SELECT * FROM idle_config WHERE server_id=?"); $idlR->execute([$srvId]);
	$cfg   = $idlR->fetch();

	if (!$server) { echo err('Server not found', 404); break; }

	$det    = json_decode($cfg['detectors_json']       ?? '{}', true) ?? [];
	$prms   = json_decode($cfg['detector_params_json'] ?? '{}', true) ?? [];
	$idle   = intval($cfg['idle_limit_sec']     ?? 1800);
	$check  = intval($cfg['check_interval_sec'] ?? 300);
	$chkMin = max(1, intval($check / 60));
	$hn        = $server['hostname'];
	$srvType   = $server['hypervisor_type'] ?? 'pve';
	$isTrueNAS  = ($srvType === 'truenas');
	$isPBS      = ($srvType === 'pbs');
	$isOMV      = ($srvType === 'omv');

	// Para OMV: si SSH user es root, se comporta igual que TrueNAS (rutas en /root/)
	$omvSshUser = '';
	if ($isOMV) {
		try {
			$stSSH = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key`=?");
			$stSSH->execute(["srv_{$srvId}_ssh_user"]);
			$r = $stSSH->fetch();
			$omvSshUser = $r ? ($r['value'] ?? '') : '';
		} catch (Throwable $e) {}
	}
	$omvAsRoot = $isOMV && ($omvSshUser === 'root' || $omvSshUser === '');

	$path    = $cfg['remote_path'] ?? ($isTrueNAS || $omvAsRoot ? '/root/idle-shutdown.sh' : ($isOMV ? '/tmp/idle-shutdown.sh' : '/usr/local/bin/idle-shutdown.sh'));
	$logFile = ($isTrueNAS || $omvAsRoot) ? '/root/wakelab-idle.log' : ($isOMV ? '/tmp/wakelab-idle.log' : '/var/log/wakelab-idle.log');
	$blocks    = '';

	$_secret = getenv('WAKELAB_SECRET') ?: '';

	// Prefer manually configured base URL (avoids hairpin NAT issues with public domain)
	$stWlUrl = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='wakelab_base_url'");
	$stWlUrl->execute();
	$wlUrlRow   = $stWlUrl->fetch();
	$wlUrlOverride = $wlUrlRow ? trim((string)$wlUrlRow['value']) : '';
	if ($wlUrlOverride !== '') {
		$wakelabUrl = rtrim($wlUrlOverride, '/');
	} else {
		$isHttps    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
		           || ($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on';
		$proto      = $isHttps ? 'https' : 'http';
		$httpHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$basePath   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/WakeLab/php/api.php')), '/');
		$wakelabUrl = "{$proto}://{$httpHost}{$basePath}";
	}

	$script = <<<BASH
#!/bin/bash
# ================================================================
#  idle-shutdown.sh — WakeLab
#  Servidor : {$hn}
#
#  Config dinámica: detectores y tiempos se leen desde WakeLab
#  en cada ejecución. Solo WAKELAB_URL y WL_HOST están hardcodeados.
#
#  Instalación:
#    cp idle-shutdown-{$hn}.sh {$path}
#    chmod +x {$path}
#    echo "*/{$chkMin} * * * * root {$path}" > /etc/cron.d/wakelab-idle
# ================================================================

# ── Configuración local (única parte hardcodeada) ────────────────
WAKELAB_URL="{$wakelabUrl}"
WL_HOST="{$hn}"
WL_TOKEN="{$_secret}"
STATE_FILE="/tmp/wakelab_idle_{$hn}"   # acumula segundos de inactividad
LOCK_FILE="/tmp/wakelab_idle_{$hn}.lock"
LOG_FILE="{$logFile}"

# ── Helpers ──────────────────────────────────────────────────────
log()    { echo "[\$(date '+%Y-%m-%d %H:%M:%S')] [\$HOSTNAME] \$1" >> "\$LOG_FILE"; }
notify() {
    # Notifica a WakeLab un evento (ej: shutdown). Fallo silencioso.
    curl -sLk --max-time 5 \
        -H "X-Internal-Token: \${WL_TOKEN}" \
        "\${WAKELAB_URL}/php/api.php?action=idle_event&host=\${WL_HOST}&event=\$1" \
        >/dev/null 2>&1 || true
}

# ── Lock: evita ejecuciones solapadas ────────────────────────────
exec 9>"\$LOCK_FILE"
flock -n 9 || exit 0

log "=== ciclo iniciado ==="

# ── Fetch config desde WakeLab ───────────────────────────────────
# Devuelve KEY=value: WL_ACTIVE, IDLE_LIMIT, CHECK_INT, SRV_TYPE,
# USE_SUDO, DET_SMB/SSH/CPU/JELLY/QBIT/PBS y sus parámetros.
# Si WakeLab no responde → exit 0 (fail-safe: no apagar).
IDLE_CFG_RAW="\$(curl -sLk --max-time 8 "\${WAKELAB_URL}/php/api.php?action=idle_config&host=\${WL_HOST}" 2>/dev/null)"
if [ -z "\$IDLE_CFG_RAW" ]; then
    log "ERROR: WakeLab no responde (\${WAKELAB_URL}) — saliendo sin apagar"
    exit 0
fi
eval "\$IDLE_CFG_RAW" 2>/dev/null || true
if [ "\${WL_ACTIVE:-0}" != "1" ]; then
    log "WL_ACTIVE=0 — idle desactivado en WakeLab para este host, saliendo"
    exit 0
fi
log "Config: IDLE_LIMIT=\${IDLE_LIMIT}s | SMB=\${DET_SMB:-0} SSH=\${DET_SSH:-0} CPU=\${DET_CPU:-0} JELLY=\${DET_JELLY:-0} QBIT=\${DET_QBIT:-0} PBS=\${DET_PBS:-0}"

# ── Estado de idle ────────────────────────────────────────────────
# STATE_FILE guarda los segundos acumulados sin actividad.
{ read -r IDLE_TIME < "\$STATE_FILE"; } 2>/dev/null || IDLE_TIME=0
ACTIVITY=0

# ── Detectores de actividad ──────────────────────────────────────
# Cada bloque setea ACTIVITY=1 si detecta uso.
# Habilitados/deshabilitados dinámicamente via DET_* desde WakeLab.

# SMB — conexiones Samba activas (no aplica en PBS)
if [ "\${DET_SMB:-0}" = "1" ] && [ "\$SRV_TYPE" != "pbs" ]; then
    SMB_USERS=\$(smbstatus -b 2>/dev/null | grep -cE "^[0-9]+" || true)
    [ "\${SMB_USERS:-0}" -gt 0 ] && log "Actividad SMB: \$SMB_USERS conexión/es" && ACTIVITY=1
fi

# SSH — sesiones TCP establecidas en puerto 22
if [ "\${DET_SSH:-0}" = "1" ]; then
    SSH_SESSIONS=\$(ss -tnH state established 'sport = :22' 2>/dev/null | awk 'END{print NR}')
    [ "\${SSH_SESSIONS:-0}" -gt 0 ] && log "SSH activo: \$SSH_SESSIONS sesión/es" && ACTIVITY=1
fi

# CPU — uso promedio sobre umbral configurable
if [ "\${DET_CPU:-0}" = "1" ]; then
    CPU_IDLE=\$(vmstat 1 1 2>/dev/null | awk 'NR==4{print \$15}')
    CPU_USAGE=\$((100 - \${CPU_IDLE:-100}))
    [ "\${CPU_USAGE:-0}" -gt "\${CPU_THRESH:-20}" ] && log "CPU alta: \${CPU_USAGE}% (umbral: \${CPU_THRESH}%)" && ACTIVITY=1
fi

# Jellyfin — sesiones de reproducción activas
if [ "\${DET_JELLY:-0}" = "1" ]; then
    JELLY_AUTH=\${JELLY_TOKEN:+"?api_key=\${JELLY_TOKEN}"}
    JELLY_ACTIVE=\$(curl -s --max-time 3 \
        "http://\${JELLY_HOST:-localhost}:\${JELLY_PORT:-8096}/Sessions\${JELLY_AUTH}" \
        2>/dev/null | grep -c "NowPlayingItem" || true)
    [ "\${JELLY_ACTIVE:-0}" -gt 0 ] && log "Jellyfin activo: \$JELLY_ACTIVE sesión/es" && ACTIVITY=1
fi

# qBittorrent — descarga activa (> 1 KB/s)
if [ "\${DET_QBIT:-0}" = "1" ]; then
    QBIT_SPEED=\$(curl -s --max-time 3 \
        "http://\${QBIT_HOST:-localhost}:\${QBIT_PORT:-8080}/api/v2/transfer/info" \
        2>/dev/null | grep -oP '"dl_info_speed":\\s*\\K[0-9]+' || echo 0)
    [ "\${QBIT_SPEED:-0}" -gt 1024 ] && log "qBittorrent activo: \${QBIT_SPEED} B/s" && ACTIVITY=1
fi

# PBS — tareas de backup en progreso
if [ "\${DET_PBS:-0}" = "1" ]; then
    if [ "\$SRV_TYPE" = "pbs" ]; then
        # Script corriendo en el propio PBS: usa CLI local (sin credenciales)
        PBS_RUNNING=0
        command -v proxmox-backup-manager >/dev/null 2>&1 && \
            PBS_RUNNING=\$(proxmox-backup-manager task list --output-format json 2>/dev/null \
                | grep -c '"running"' || true)
    else
        # PBS externo: consulta API REST
        PBS_RUNNING=\$(curl -sk --max-time 3 \
            "https://\${PBS_HOST:-localhost}:\${PBS_PORT:-8007}/api2/json/nodes/localhost/tasks?limit=10" \
            2>/dev/null | grep -c '"running"' || true)
    fi
    [ "\${PBS_RUNNING:-0}" -gt 0 ] && log "PBS activo: \$PBS_RUNNING tarea/s" && ACTIVITY=1
fi

# PBS post-backup: apagar inmediatamente al terminar todas las tareas
if [ "\${PBS_POSTBACKUP:-0}" = "1" ] && [ "\${DET_PBS:-0}" = "1" ] && [ "\$SRV_TYPE" = "pbs" ]; then
    PBS_PREV_FILE="/tmp/wakelab_pbs_was_running_{$hn}"
    { read -r PBS_WAS_RUNNING < "\$PBS_PREV_FILE"; } 2>/dev/null || PBS_WAS_RUNNING=0
    echo "\${PBS_RUNNING:-0}" > "\$PBS_PREV_FILE"
    if [ "\${PBS_WAS_RUNNING:-0}" -gt 0 ] && [ "\${PBS_RUNNING:-0}" -eq 0 ]; then
        log "==== Backup finalizado — apagado inmediato (post-backup) ===="
        notify "shutdown"
        echo 0 > "\$STATE_FILE"
        systemctl poweroff
        exit 0
    fi
fi

# ── Resultado de la ronda ─────────────────────────────────────────
if [ "\$ACTIVITY" -eq 1 ]; then
    echo 0 > "\$STATE_FILE"
    log "Actividad detectada — contador reseteado (era \${IDLE_TIME}s)"
    exit 0
fi

IDLE_TIME=\$((IDLE_TIME + CHECK_INT))
echo "\$IDLE_TIME" > "\$STATE_FILE"
log "Sin actividad — idle acumulado: \${IDLE_TIME}s / \${IDLE_LIMIT}s"

# ── Apagado (solo si se supera el límite) ────────────────────────
if [ "\$IDLE_TIME" -ge "\$IDLE_LIMIT" ]; then
    log "==== Límite idle alcanzado — iniciando apagado ===="
    notify "shutdown"
    echo 0 > "\$STATE_FILE"

    # PVE: hay que bajar guests antes de apagar el host
    if [ "\$SRV_TYPE" = "pve" ]; then

        # 1. Apagar VMs QEMU
        VM_IDS=\$(qm list 2>/dev/null | grep " running " | awk '{print \$1}')
        if [ -n "\$VM_IDS" ]; then
            log "Apagando VMs: \$(echo \$VM_IDS | tr '\\n' ' ')"
            for VMID in \$VM_IDS; do qm shutdown "\$VMID" --timeout 180 2>/dev/null & done
            T=0; while [ \$T -lt 180 ]; do
                [ "\$(qm list 2>/dev/null | grep -c ' running ' || true)" = "0" ] && break
                sleep 5; T=\$((T+5))
            done
            # Force stop las que no respondieron
            for VMID in \$(qm list 2>/dev/null | grep " running " | awk '{print \$1}'); do
                log "Force stop VM \$VMID"; qm stop "\$VMID" 2>/dev/null
            done
        else
            log "Sin VMs corriendo"
        fi

        # 2. Apagar contenedores LXC
        CT_IDS=\$(pct list 2>/dev/null | grep " running " | awk '{print \$1}')
        if [ -n "\$CT_IDS" ]; then
            log "Apagando LXCs: \$(echo \$CT_IDS | tr '\\n' ' ')"
            for CTID in \$CT_IDS; do pct shutdown "\$CTID" --timeout 120 2>/dev/null & done
            T=0; while [ \$T -lt 120 ]; do
                [ "\$(pct list 2>/dev/null | grep -c ' running ' || true)" = "0" ] && break
                sleep 5; T=\$((T+5))
            done
            for CTID in \$(pct list 2>/dev/null | grep " running " | awk '{print \$1}'); do
                log "Force stop LXC \$CTID"; pct stop "\$CTID" 2>/dev/null
            done
        else
            log "Sin LXCs corriendo"
        fi

        # 3. Apagar host
        log "Apagando host PVE"
        /sbin/shutdown -h now "WakeLab: idle shutdown"

    # TrueNAS: bajar VMs via midclt, apps y pools los maneja systemd
    elif [ "\$SRV_TYPE" = "truenas" ]; then

        VM_IDS=\$(midclt call vm.query '[["status.state","=","RUNNING"]]' 2>/dev/null \
            | grep -oP '"id":\s*\K[0-9]+' || true)
        if [ -n "\$VM_IDS" ]; then
            log "Apagando VMs: \$(echo \$VM_IDS | tr '\\n' ' ')"
            for VMID in \$VM_IDS; do midclt call vm.poweroff "[\$VMID]" 2>/dev/null & done
            log "Esperando VMs (30s)..."
            sleep 30
        else
            log "Sin VMs corriendo"
        fi

        # Apps (k3s) y pools ZFS los cierra systemd durante el shutdown
        # midclt es async (encola job, exit=0 inmediato) → /sbin/shutdown como garantía
        log "Apagando via midclt + shutdown"
        midclt call system.shutdown '{"delay": 0}' 2>/dev/null || true
        sleep 5
        /sbin/shutdown -h now "WakeLab: idle shutdown"

    # PBS / OMV / genérico: apagado directo
    else
        # USE_SUDO=1 solo en OMV con usuario no-root
        if [ "\${USE_SUDO:-0}" = "1" ]; then
            sudo /sbin/shutdown -h now "WakeLab: idle shutdown"
        else
            /sbin/shutdown -h now "WakeLab: idle shutdown"
        fi
    fi
fi
BASH;

	logEv($pdo, $srvId, 'info', "Script idle generado para {$hn}");
	echo ok(['script' => $script, 'filename' => "idle-shutdown-{$hn}.sh", 'remote_path' => $path]);
	break;

// ─────────────────────────────────────────────────────────────
case 'authorize_wakelab_key':
try {
	$srvId = intval($data['server_id'] ?? 0);
	if (!$srvId) { echo err('server_id required'); break; }

	$pubKey = '/var/www/.ssh/id_ed25519.pub';
	if (!file_exists($pubKey)) { echo err('WakeLab SSH key not found — generate the keys first'); break; }
	if (!trim((string)shell_exec('which sshpass 2>/dev/null'))) { echo err('sshpass not installed in the container — add sshpass to docker-compose'); break; }
	if (!trim((string)shell_exec('which ssh-copy-id 2>/dev/null'))) { echo err('ssh-copy-id not available'); break; }

	$srvR = $pdo->prepare("SELECT ip FROM servers WHERE id=?"); $srvR->execute([$srvId]);
	$srv  = $srvR->fetch();
	if (!$srv) { echo err('Server not found'); break; }
	$ip = $srv['ip'];
	if (!isSafeHost($ip)) { echo err('Invalid IP'); break; }

	// Leer creds con fallback a global defaults (#13)
	['user' => $sshUser, 'pass' => $sshPass, 'port' => $sshPort] = resolveSSHCreds($pdo, $srvId);

	if (!$sshPass) { echo err('No password saved — enter SSH credentials first'); break; }

	$portOpt = $sshPort !== 22 ? "-p {$sshPort}" : '';
	$target  = escapeshellarg("{$sshUser}@{$ip}");

	// Limpiar entrada vieja en known_hosts para evitar conflicto de clave
	shell_exec("ssh-keygen -R " . escapeshellarg($ip) . " 2>/dev/null");

	$cmd = 'sshpass -p ' . escapeshellarg($sshPass)
		. " ssh-copy-id -i " . escapeshellarg($pubKey)
		. " -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=no"
		. ($portOpt ? " {$portOpt}" : '')
		. " {$target} 2>&1";
	$out = (string)shell_exec($cmd);

	// ssh-copy-id devuelve 0 en éxito — difícil de detectar por output, buscamos señal de éxito
	$ok = stripos($out, 'Number of key(s) added') !== false
	   || stripos($out, 'already exist') !== false
	   || stripos($out, 'keys remain') !== false;
	if (!$ok) {
		logEv($pdo, $srvId, 'warn', "authorize_wakelab_key output: " . substr($out, 0, 200));
		echo err('SSH key copy failed: ' . trim($out));
		break;
	}

	// Limpiar contraseña de settings (solo si la copia fue exitosa)
	$del = $pdo->prepare("DELETE FROM settings WHERE `key`=?");
	$del->execute(["srv_{$srvId}_ssh_pass"]);

	// Marcar clave como autorizada para ocultar el bloque SSH
	$pdo->prepare("INSERT INTO settings (`key`,`value`,`description`) VALUES (?,?,'') ON DUPLICATE KEY UPDATE `value`=?")
	    ->execute(["srv_{$srvId}_ssh_key_ok", '1', '1']);

	logEv($pdo, $srvId, 'ok', "Clave SSH de WakeLab autorizada en {$ip}");
	echo ok(['output' => trim($out)]);
} catch (Throwable $e) { echo err($e->getMessage()); }
break;

// ─────────────────────────────────────────────────────────────
case 'reset_ssh_key_ok':
try {
	$srvId = intval($data['server_id'] ?? 0);
	if (!$srvId) { echo err('server_id required'); break; }
	$pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute(["srv_{$srvId}_ssh_key_ok"]);
	echo ok([]);
} catch (Throwable $e) { echo err($e->getMessage()); }
break;

// ─────────────────────────────────────────────────────────────
case 'deploy_idle_script':
try {
	$srvId  = intval($data['server_id'] ?? 0);
	$script = $data['script'] ?? '';
	if (!$script) { echo err('Empty script'); break; }

	$srvR = $pdo->prepare("SELECT * FROM servers WHERE id=?"); $srvR->execute([$srvId]);
	$srv  = $srvR->fetch();
	$idlR = $pdo->prepare("SELECT remote_path,check_interval_sec FROM idle_config WHERE server_id=?"); $idlR->execute([$srvId]);
	$idl  = $idlR->fetch();

	if (!$srv) { echo err('Server not found', 404); break; }

	$ip        = $srv['ip'];
	$isTrueNAS   = ($srv['hypervisor_type'] === 'truenas');
	$isOMV       = ($srv['hypervisor_type'] === 'omv');
	$useUserCron = $isTrueNAS || $isOMV;
	$chkM        = max(1, intval(intval($idl['check_interval_sec'] ?? 300) / 60));

	if (!trim((string)shell_exec('which ssh 2>/dev/null'))) {
		echo err('ssh not available on the web server');
		break;
	}
	if (!isSafeHost($ip)) { echo err('Invalid IP/hostname'); break; }

	// Resolve SSH credentials con fallback a global defaults (#13)
	['user' => $sshUser, 'pass' => $sshPass, 'port' => $sshPort] = resolveSSHCreds($pdo, $srvId);

	// Resolver path del script
	if ($isTrueNAS) {
		// TrueNAS: home dir del usuario
		$homeDir = ($sshUser === 'root') ? '/root' : "/home/{$sshUser}";
		$path    = "{$homeDir}/idle-shutdown.sh";
	} elseif ($isOMV) {
		// OMV: root → /root/ (igual que TrueNAS), no-root → /tmp/
		$storedPath = $idl['remote_path'] ?? '';
		$omvDefault = ($sshUser === 'root') ? '/root/idle-shutdown.sh' : '/tmp/idle-shutdown.sh';
		$path = ($storedPath && $storedPath !== '/usr/local/bin/idle-shutdown.sh')
			? $storedPath
			: $omvDefault;
	} else {
		$path = $idl['remote_path'] ?? '/usr/local/bin/idle-shutdown.sh';
	}

	if (!isSafePath($path)) { echo err('Invalid remote path'); break; }

	$hasSshpass  = (bool)trim((string)shell_exec('which sshpass 2>/dev/null'));
	$usePassword = ($isTrueNAS || $isOMV) && $sshPass !== '';
	if ($usePassword && !$hasSshpass) {
		echo err('sshpass not available — install sshpass in the container or configure an SSH key');
		break;
	}

	$sshpassPfx = $usePassword ? 'sshpass -p ' . escapeshellarg($sshPass) . ' ' : '';
	$portOptScp = $sshPort !== 22 ? "-P {$sshPort}" : '';
	$portOptSsh = $sshPort !== 22 ? "-p {$sshPort}" : '';
	$target     = escapeshellarg("{$sshUser}@{$ip}");
	$sshOpts    = "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 {$portOptSsh}";

	$tmp = tempnam(sys_get_temp_dir(), 'idle_');
	chmod($tmp, 0600);
	file_put_contents($tmp, $script);

	// Crear directorio destino si no existe
	$destDir = dirname($path);
	$mkdirOut = trim((string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} 'mkdir -p " . escapeshellarg($destDir) . " && echo OK' 2>&1"));
	if (!str_contains($mkdirOut, 'OK')) {
		logEv($pdo, $srvId, 'warn', "Deploy mkdir falló en {$sshUser}@{$ip}:{$destDir} — {$mkdirOut}");
		echo err("Could not create destination directory {$destDir} on {$sshUser}@{$ip}. Detail: {$mkdirOut}");
		@unlink($tmp ?? '');
		break;
	}

	$scpRaw = (string)shell_exec("{$sshpassPfx}scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 {$portOptScp} "
		. escapeshellarg($tmp) . " "
		. "{$target}:" . escapeshellarg($path) . " 2>&1");
	@unlink($tmp);

	// Filter informational "Permanently added" lines — only treat real errors as failure
	$scpErrors = implode("\n", array_filter(
		array_map('trim', explode("\n", $scpRaw)),
		fn($l) => $l !== '' && !str_starts_with($l, 'Warning: Permanently added')
	));

	if ($scpErrors !== '') {
		logEv($pdo, $srvId, 'err', "Deploy SCP falló a {$ip}: {$scpErrors}");
		if (str_contains($scpErrors, 'Permission denied') || str_contains($scpErrors, 'publickey')) {
			echo err("SCP: permission denied on {$sshUser}@{$ip}:{$path} — check SSH user/password. Detail: {$scpErrors}");
		} elseif (str_contains($scpErrors, 'Connection refused')) {
			echo err("SCP failed: SSH rejected on port {$sshPort} — verify SSH is enabled. Detail: {$scpErrors}");
		} else {
			echo err("SCP failed: {$scpErrors}");
		}
		break;
	}

	// Install cron: TrueNAS/OMV usan user crontab; otros usan /etc/cron.d (requieren root)
	if ($useUserCron) {
		$cronEntry = "*/{$chkM} * * * * bash {$path}";
		$cronCmd   = "chmod +x " . escapeshellarg($path)
		           . " && (crontab -l 2>/dev/null | grep -v " . escapeshellarg($path) . "; echo " . escapeshellarg($cronEntry) . ") | crontab -"
		           . " && echo OK";
	} else {
		$cronEntry = "*/{$chkM} * * * * root {$path}";
		$cronCmd   = "chmod +x " . escapeshellarg($path)
		           . " && echo " . escapeshellarg($cronEntry) . " > /etc/cron.d/wakelab-idle"
		           . " && echo OK";
	}

	$sshOut = shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($cronCmd) . " 2>&1");
	$isOk   = str_contains((string)$sshOut, 'OK');

	// Gather debug info regardless of outcome
	$debugOut = '';
	if ($useUserCron) {
		$debugCmd = "echo '--- script ---' && ls -la " . escapeshellarg($path)
		          . " && echo '--- crontab ---' && crontab -l 2>&1"
		          . " && echo '--- cron service ---' && systemctl is-active cron 2>/dev/null || systemctl is-active crond 2>/dev/null || echo 'cron status unknown'"
		          . " && echo '--- last log ---' && (grep -i idle /var/log/syslog 2>/dev/null | tail -5 || journalctl -u cron --since '10 min ago' 2>/dev/null | tail -5 || echo 'no log found')";
		$debugRaw = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($debugCmd) . " 2>&1");
		// Filtrar warnings informativos de SSH (sin home dir, host key)
		$debugOut = trim(implode("\n", array_filter(
			array_map('trim', explode("\n", $debugRaw)),
			fn($l) => $l !== ''
				&& !str_starts_with($l, 'Warning: Permanently added')
				&& !str_contains($l, 'Could not chdir to home directory')
		)));
	}

	$detail = trim(implode("\n", array_filter([trim((string)$sshOut), $debugOut])));
	logEv($pdo, $srvId, $isOk ? 'ok' : 'err',
		$isOk
			? "Script de inactividad deployado en {$ip} → {$path}"
			: "Error al deployar script de inactividad en {$ip}: {$sshOut}");
	if ($isOk) {
		$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
			->execute(["srv_{$srvId}_idle_deployed", '1']);
	}
	echo ok(['path' => $path, 'cron' => $cronEntry ?? '', 'detail' => $detail],
			 $isOk ? 'Script deployed' : 'Deploy completed with errors');
} catch (Throwable $e) {
	echo err('Internal error: ' . $e->getMessage());
}
	break;

// ─────────────────────────────────────────────────────────────
case 'update_server': {
	$id   = intval($data['id']);
	$_hn  = trim($data['hostname'] ?? '');
	$_ip  = trim($data['ip']       ?? '');
	$_mac = trim($data['mac']      ?? '');
	$_ht  = trim($data['hypervisor_type'] ?? 'pve');
	if (!isSafeHost($_hn)) { echo err('Invalid hostname'); break; }
	if ($_ip !== '' && !filter_var($_ip, FILTER_VALIDATE_IP) && !isSafeHost($_ip)) { echo err('Invalid IP'); break; }
	if (!in_array($_ht, ['pve','pbs','truenas','omv','generic','windows','linux'])) { echo err('Invalid hypervisor type'); break; }
	if ($_mac !== '' && !preg_match('/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/', $_mac)) { echo err('Invalid MAC address'); break; }
	$depOn = ($data['depends_on_server_id'] !== '' && $data['depends_on_server_id'] !== null && $data['depends_on_server_id'] != 0)
		? intval($data['depends_on_server_id']) : null;
	$pdo->prepare("UPDATE servers SET hostname=?,ip=?,port=?,mac=?,hypervisor_type=?,notes=?,url=?,api_enabled=?,proxmox_server_id=?,proxmox_vmid=?,depends_on_server_id=? WHERE id=?")
		->execute([
			$_hn, $_ip, intval($data['port'] ?? 8006),
			$_mac, $_ht,
			$data['notes'] ?? '', preg_replace('/^https?:\/\//i', '', trim($data['url'] ?? '')),
			intval($data['api_enabled'] ?? 0),
			($data['proxmox_server_id'] !== '' && $data['proxmox_server_id'] !== null) ? intval($data['proxmox_server_id']) : null,
			($data['proxmox_vmid'] !== '' && $data['proxmox_vmid'] !== null) ? intval($data['proxmox_vmid']) : null,
			$depOn,
			$id
		]);

	if (!empty($data['token_secret'])) {
		$pdo->prepare("INSERT INTO api_tokens (server_id,auth_type,api_user,token_id,token_secret)
					   VALUES (?,?,?,?,?)
					   ON DUPLICATE KEY UPDATE auth_type=VALUES(auth_type),api_user=VALUES(api_user),
											   token_id=VALUES(token_id),token_secret=VALUES(token_secret)")
			->execute([$id, $data['auth_type'] ?? 'pve_token',
						$data['api_user'] ?? 'root@pam', $data['token_id'] ?? 'panel', wlEncrypt($data['token_secret'])]);
	} elseif (isset($data['auth_type'])) {
		$pdo->prepare("INSERT INTO api_tokens (server_id,auth_type,api_user,token_id,token_secret)
					   VALUES (?,?,?,?,'')
					   ON DUPLICATE KEY UPDATE auth_type=VALUES(auth_type),api_user=VALUES(api_user),token_id=VALUES(token_id)")
			->execute([$id, $data['auth_type'], $data['api_user'] ?? 'root@pam', $data['token_id'] ?? '']);
	}

	invalidateCache();
	logEv($pdo, $id, 'info', 'Configuración actualizada');
	echo ok(null, 'Server updated');
	break;
}

// ─────────────────────────────────────────────────────────────
case 'set_schedule_active':
	$srvId  = intval($data['server_id'] ?? 0);
	$active = intval($data['active'] ?? 0) ? 1 : 0;
	$pdo->prepare("UPDATE schedules SET active=? WHERE server_id=?")
		->execute([$active, $srvId]);
	echo ok(null, 'Boot schedule ' . ($active ? 'enabled' : 'disabled'));
	break;

// ─────────────────────────────────────────────────────────────
case 'set_shutdown_active':
	$srvId  = intval($data['server_id'] ?? 0);
	$active = intval($data['active'] ?? 0) ? 1 : 0;
	$pdo->prepare("UPDATE schedules SET shutdown_active=? WHERE server_id=?")
		->execute([$active, $srvId]);
	echo ok(null, 'Shutdown schedule ' . ($active ? 'enabled' : 'disabled'));
	break;

// ─────────────────────────────────────────────────────────────
case 'set_idle_active':
	$srvId  = intval($data['server_id'] ?? 0);
	$active = intval($data['active'] ?? 0) ? 1 : 0;
	$stmt   = $pdo->prepare("UPDATE idle_config SET active=? WHERE server_id=?");
	$stmt->execute([$active, $srvId]);
	echo ok(null, 'Idle ' . ($active ? 'enabled' : 'disabled'));
	break;

// ─────────────────────────────────────────────────────────────
case 'update_schedule':
	// Convertir tiempos de TZ de visualización → TZ del servidor antes de guardar
	$displayTzSch = trim($data['display_tz'] ?? '');
	$serverTzSch  = (function() use ($pdo): string {
		$r = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='timezone'");
		$r->execute(); return (string)($r->fetchColumn() ?: '');
	})();
	$convertTimeSch = function(string $t) use ($displayTzSch, $serverTzSch): string {
		if (!$displayTzSch || !$serverTzSch || $displayTzSch === $serverTzSch) return $t;
		try {
			$dt = DateTime::createFromFormat('H:i:s', $t, new DateTimeZone($displayTzSch));
			if (!$dt) return $t;
			$dt->setTimezone(new DateTimeZone($serverTzSch));
			return $dt->format('H:i:s');
		} catch (Throwable) { return $t; }
	};
	$bootTimeSch = $convertTimeSch($data['boot_time'] ?? '08:00:00');
	$shutRawSch  = (array_key_exists('shutdown_time', $data) && $data['shutdown_time'] !== null)
		? $data['shutdown_time'] : null;
	$shutTime = $shutRawSch !== null ? $convertTimeSch($shutRawSch) : null;
	$shutActive = isset($data['shutdown_active']) ? intval($data['shutdown_active']) : ($shutTime !== null ? 1 : 0);
	$pdo->prepare("INSERT INTO schedules (server_id,boot_time,shutdown_time,method,active,shutdown_active,days_json)
				   VALUES (?,?,?,?,?,?,?)
				   ON DUPLICATE KEY UPDATE boot_time=VALUES(boot_time),shutdown_time=VALUES(shutdown_time),
										   method=VALUES(method),active=VALUES(active),
										   shutdown_active=VALUES(shutdown_active),days_json=VALUES(days_json)")
		->execute([
			intval($data['server_id']),
			$bootTimeSch,
			$shutTime ?? '22:00:00',
			$data['method']    ?? 'Wake on LAN',
			isset($data['active']) ? intval($data['active']) : 1,
			$shutActive,
			json_encode($data['days'] ?? ['mon','tue','wed','thu','fri','sat','sun'])
		]);
	echo ok(null, 'Schedule saved');
	break;

// ─────────────────────────────────────────────────────────────
case 'update_idle':
	$pdo->prepare("INSERT INTO idle_config
					 (server_id,idle_limit_sec,check_interval_sec,detectors_json,detector_params_json,remote_path,active)
				   VALUES (?,?,?,?,?,?,?)
				   ON DUPLICATE KEY UPDATE
					 idle_limit_sec=VALUES(idle_limit_sec),check_interval_sec=VALUES(check_interval_sec),
					 detectors_json=VALUES(detectors_json),detector_params_json=VALUES(detector_params_json),
					 remote_path=VALUES(remote_path),active=VALUES(active)")
		->execute([
			intval($data['server_id']),
			intval($data['idle_limit_sec']     ?? 1800),
			intval($data['check_interval_sec'] ?? 300),
			json_encode($data['detectors']       ?? (object)[]),
			json_encode($data['detector_params'] ?? (object)[]),
			$data['remote_path'] ?? '/usr/local/bin/idle-shutdown.sh',
			isset($data['active']) ? intval($data['active']) : 1
		]);
	echo ok(null, 'Idle config saved');
	break;

// ─────────────────────────────────────────────────────────────
case 'server_action':
	$srvId  = intval($data['server_id']);
	$cmd    = $data['command'] ?? '';
	$source = ($data['source'] ?? '') === 'rack' ? '[rack] ' : '';

	$srvR = $pdo->prepare("SELECT * FROM servers WHERE id=?");
	$srvR->execute([$srvId]);
	$srv  = $srvR->fetch();

	if (!$srv) { echo err('Server not found'); break; }

	$ip  = $srv['ip'];
	$mac = $srv['mac'] ?? '';
	$result = '';
	$isOk   = false;

	// Verificar estado actual antes de ejecutar
	$tcpPort   = intval($srv['port'] ?? 8006);
	$tcpSock   = @fsockopen($ip, $tcpPort, $_e, $_es, 2);
	$isOnline  = ($tcpSock !== false);
	if ($tcpSock) fclose($tcpSock);

	if ($cmd === 'wol' && $isOnline) {
		echo json_encode(['status' => 'already', 'message' => 'Server is already online']);
		break;
	}
	if (in_array($cmd, ['shutdown', 'reboot']) && !$isOnline) {
		echo json_encode(['status' => 'already', 'message' => 'Server is already offline']);
		break;
	}

	if (!tryAcquireLock($pdo, $srvId, 'manual')) {
		echo json_encode(['status' => 'locked', 'message' => 'A signal is already in progress for this server']);
		break;
	}

	if ($cmd === 'wol') {
		// ── Boot dependency: encender dependencia primero si está offline ──
		$depId = intval($srv['depends_on_server_id'] ?? 0);
		if ($depId) {
			$depR = $pdo->prepare("SELECT * FROM servers WHERE id=?");
			$depR->execute([$depId]);
			$dep = $depR->fetch();
			if ($dep) {
				// TCP check al puerto del dep
				$depPort   = intval($dep['port'] ?? 8006);
				$depSock   = @fsockopen($dep['ip'], $depPort, $e, $es, 3);
				$depOnline = ($depSock !== false);
				if ($depSock) fclose($depSock);

				if (!$depOnline) {
					// WoL al dep
					$depMac = str_replace([':', '-', '.'], '', $dep['mac'] ?? '');
					if (strlen($depMac) === 12 && function_exists('socket_create')) {
						$pkt  = str_repeat(chr(0xFF), 6) . str_repeat(pack('H*', $depMac), 16);
						$sk   = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
						socket_set_option($sk, SOL_SOCKET, SO_BROADCAST, 1);
						socket_sendto($sk, $pkt, strlen($pkt), 0, '255.255.255.255', 9);
						socket_close($sk);
					}
					logEv($pdo, $depId, 'info', "Boot dependency: WoL enviado a {$dep['hostname']} (requerido por {$srv['hostname']})");
				}
			}
		}

		$proxmoxVmid  = intval($srv['proxmox_vmid']      ?? 0);
		$proxmoxSrvId = intval($srv['proxmox_server_id'] ?? 0);

		if ($proxmoxVmid && $proxmoxSrvId) {
			// VM en Proxmox — arrancar via API
			$pveSrvR = $pdo->prepare("SELECT * FROM servers WHERE id=?");
			$pveSrvR->execute([$proxmoxSrvId]);
			$pveSrv = $pveSrvR->fetch();
			if (!$pveSrv) { releaseLock($pdo, $srvId); echo err('Configured Proxmox server not found'); break; }
			$pveTok    = getToken($pdo, $proxmoxSrvId);
			$pveClient = HypFactory::make($pveSrv, $pveTok);
			if (!($pveClient instanceof PVEClient)) { releaseLock($pdo, $srvId); echo err('The selected Proxmox server is not of type PVE'); break; }
			$isOk   = $pveClient->startGuest($proxmoxVmid);
			$result = $isOk
				? "VM {$proxmoxVmid} arrancada via Proxmox API ({$pveSrv['hostname']})"
				: "Error arrancando VM via Proxmox: HTTP ".($pveClient->lastError['http'] ?? '?');

		} else {
			// Bare metal — magic packet UDP broadcast (#15 multi-MAC)
			$macRaw = $srv['mac'] ?? '';
			$macs = array_values(array_filter(
				array_map(fn($m) => str_replace([':', '-', '.', ' '], '', trim($m)), explode(',', $macRaw)),
				fn($m) => strlen($m) === 12
			));
			if (empty($macs)) {
				releaseLock($pdo, $srvId); echo err('Invalid MAC address for WoL (or configure Proxmox if this is a VM)'); break;
			}
			if (!function_exists('socket_create')) {
				releaseLock($pdo, $srvId); echo err('PHP sockets extension not available — enable extension=sockets in php.ini'); break;
			}
			$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			if ($sock === false) {
				releaseLock($pdo, $srvId); echo err('Could not create UDP socket: '.socket_strerror(socket_last_error())); break;
			}
			socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
			// Calcular broadcast de la subred del target para salir bien desde Docker
			$targets = ['255.255.255.255'];
			if ($ip && ($long = ip2long($ip)) !== false) {
				// Asumir /24 — broadcast = x.x.x.255
				$subnetBcast = long2ip(($long & 0xFFFFFF00) | 0xFF);
				if ($subnetBcast !== '255.255.255.255') $targets[] = $subnetBcast;
			}
			$sentCount = 0;
			foreach ($macs as $m) {
				$packet = str_repeat(chr(0xFF), 6) . str_repeat(pack('H*', $m), 16);
				foreach ($targets as $bcast) {
					socket_sendto($sock, $packet, strlen($packet), 0, $bcast, 9);
				}
				$sentCount++;
			}
			socket_close($sock);
			$isOk   = $sentCount > 0;
			$macsFmt = array_map(fn($m) => implode(':', str_split($m, 2)), $macs);
			$result = $isOk
				? 'WoL manual: ' . implode(', ', $macsFmt)
				: 'Error enviando magic packet';
		}

	} elseif (in_array($cmd, ['shutdown', 'reboot'])) {
		if (!isSafeHost($ip)) { releaseLock($pdo, $srvId); echo err('Invalid IP/hostname'); break; }
		// TrueNAS: primero intentar SSH con contraseña, luego WebSocket
		if (($srv['hypervisor_type'] ?? '') === 'truenas') {
			$sshUser = getSetting($pdo, "srv_{$srvId}_ssh_user", '');
			$sshPass = getSetting($pdo, "srv_{$srvId}_ssh_pass", '');
			$sshDone = false;

			if ($sshUser && $sshPass) {
				$sshpassBin = trim((string)shell_exec('which sshpass 2>/dev/null'));
				if ($sshpassBin) {
					$sshCmd = $cmd === 'shutdown'
						? 'sudo shutdown -h now 2>&1 || sudo poweroff 2>&1 || shutdown -h now 2>&1'
						: 'sudo shutdown -r now 2>&1 || sudo reboot 2>&1 || shutdown -r now 2>&1';
					$out = shell_exec(
						$sshpassBin . ' -p ' . escapeshellarg($sshPass)
						. ' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5'
						. ' ' . escapeshellarg("{$sshUser}@{$ip}")
						. ' ' . escapeshellarg($sshCmd) . ' 2>&1'
					);
					// $sshUser@$ip ya está en escapeshellarg — safe
					$outStr = trim((string)$out);
					if (!str_contains($outStr, 'Permission denied')
					 && !str_contains($outStr, 'Connection refused')
					 && !str_contains($outStr, 'No route')) {
						$isOk   = true;
						$result = "Comando '$cmd' enviado vía SSH a {$sshUser}@{$ip}";
						$sshDone = true;
					} else {
						$result = "SSH error: $outStr";
					}
				}
			}

			if (!$sshDone) {
				$tok    = getToken($pdo, $srvId);
				$client = HypFactory::make($srv, $tok);
				if ($client instanceof TrueNASClient) {
					$res    = $cmd === 'shutdown' ? $client->shutdown() : $client->reboot();
					$isOk   = $res['ok'];
					$result = $isOk
						? "Comando '$cmd' enviado vía WebSocket"
						: "TrueNAS: SSH not configured and WebSocket failed — body: {$res['body']}";
				} else {
					$isOk = false; $result = 'Error instantiating TrueNAS client';
				}
			}

		} elseif (($srv['hypervisor_type'] ?? '') === 'pbs') {
			// PBS — apagar/reiniciar via API
			$tok    = getToken($pdo, $srvId);
			$client = HypFactory::make($srv, $tok);
			if ($client instanceof PBSClient) {
				$isOk   = $cmd === 'shutdown' ? $client->shutdown() : $client->reboot();
				$result = $isOk
					? "Comando '$cmd' enviado via PBS API"
					: "PBS API error (verificar token/permisos Sys.PowerMgmt)";
			} else {
				$isOk = false; $result = 'Error instantiating PBS client';
			}

		} elseif (($srv['hypervisor_type'] ?? '') === 'omv') {
			// OMV — prioridad: SSH con credenciales guardadas → Proxmox API (si es VM) → OMV RPC
			$sshUser = getSetting($pdo, "srv_{$srvId}_ssh_user", '');
			$sshPass = getSetting($pdo, "srv_{$srvId}_ssh_pass", '');
			$sshPort = intval(getSetting($pdo, "srv_{$srvId}_ssh_port", '22')) ?: 22;
			$isOk    = false;
			$result  = '';

			// 1. SSH con credenciales guardadas (wakelab u otro usuario con sudo)
			if ($sshUser) {
				$sshPassBin = trim((string)shell_exec('which sshpass 2>/dev/null'));
				$isRoot     = ($sshUser === 'root');
				$sshCmd     = $cmd === 'shutdown'
					? ($isRoot ? '/sbin/shutdown -h now' : 'sudo /sbin/shutdown -h now')
					: ($isRoot ? '/sbin/shutdown -r now' : 'sudo /sbin/shutdown -r now');
				$portArg    = $sshPort !== 22 ? " -p {$sshPort}" : '';

				if ($sshPass && $sshPassBin) {
					$out = shell_exec(
						$sshPassBin . ' -p ' . escapeshellarg($sshPass)
						. ' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5' . $portArg
						. ' ' . escapeshellarg("{$sshUser}@{$ip}")
						. ' ' . escapeshellarg($sshCmd) . ' 2>&1'
					);
				} else {
					$out = shell_exec(
						'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 -o BatchMode=yes' . $portArg
						. ' ' . escapeshellarg("{$sshUser}@{$ip}")
						. ' ' . escapeshellarg($sshCmd) . ' 2>&1'
					);
				}
				$outStr = trim((string)$out);
				if (!str_contains($outStr, 'Permission denied')
				 && !str_contains($outStr, 'Connection refused')
				 && !str_contains($outStr, 'No route')
				 && !str_contains($outStr, 'sudo:')) {
					$isOk   = true;
					$result = "Comando '$cmd' enviado vía SSH ({$sshUser}@{$ip})";
				} else {
					$result = "SSH falló: {$outStr}";
				}
			}

			// 2. Proxmox API si es una VM (funciona si hay guest agent instalado en OMV)
			if (!$isOk) {
				$proxmoxVmid  = intval($srv['proxmox_vmid']      ?? 0);
				$proxmoxSrvId = intval($srv['proxmox_server_id'] ?? 0);
				if ($proxmoxVmid && $proxmoxSrvId) {
					$pveSrvR = $pdo->prepare("SELECT * FROM servers WHERE id=?");
					$pveSrvR->execute([$proxmoxSrvId]);
					$pveSrv = $pveSrvR->fetch();
					$pveTok = $pveSrv ? getToken($pdo, $proxmoxSrvId) : null;
					if ($pveSrv && $pveTok) {
						$pveClient = HypFactory::make($pveSrv, $pveTok);
						if ($pveClient instanceof PVEClient) {
							$isOk   = $cmd === 'shutdown'
								? $pveClient->shutdownGuest($proxmoxVmid)
								: $pveClient->rebootGuest($proxmoxVmid);
							$result = $isOk
								? "Comando '$cmd' enviado via Proxmox API (VM {$proxmoxVmid})"
								: "Proxmox API falló — " . json_encode($pveClient->lastError);
						}
					}
				}
			}

			// 3. OMV RPC como último recurso (requiere usuario admin, no wakelab)
			if (!$isOk) {
				$tok    = getToken($pdo, $srvId);
				$client = HypFactory::make($srv, $tok);
				if ($client instanceof OMVClient) {
					$isOk   = $cmd === 'shutdown' ? $client->shutdown() : $client->reboot();
					$result = $isOk
						? "Command '$cmd' sent via OMV RPC API"
						: "OMV: SSH not configured and RPC API failed (user needs admin permissions in OMV)";
				}
			}

		} elseif (($srv['hypervisor_type'] ?? '') === 'windows') {
			// Windows — SSH → PowerShell shutdown
			require_once __DIR__ . '/pc.php';
			$sshUser = getSetting($pdo, "srv_{$srvId}_ssh_user", 'Administrator') ?: 'Administrator';
			$sshPass = getSetting($pdo, "srv_{$srvId}_ssh_pass", '');
			$sshPort = intval(getSetting($pdo, "srv_{$srvId}_ssh_port", '22')) ?: 22;
			$pc = new PCClient($ip, $sshPort, 'windows', $sshUser, $sshPass);
			$winCmd = $cmd === 'shutdown' ? 'shutdown /s /t 0' : 'shutdown /r /t 0';
			$out    = $pc->sshExec($winCmd);
			// shutdown /s no devuelve output si OK
			$isOk   = !str_contains((string)$out, 'Access is denied')
				   && !str_contains((string)$out, 'Permission denied')
				   && !str_contains((string)$out, 'Connection refused');
			$result = $isOk
				? "Comando '$cmd' enviado a {$sshUser}@{$ip} (Windows SSH)"
				: "Windows SSH error: " . trim((string)$out);

		} elseif (($srv['hypervisor_type'] ?? '') === 'linux') {
			// Linux PC — SSH con creds configurables (usuario + contraseña o clave)
			require_once __DIR__ . '/pc.php';
			$sshUser = getSetting($pdo, "srv_{$srvId}_ssh_user", 'root') ?: 'root';
			$sshPass = getSetting($pdo, "srv_{$srvId}_ssh_pass", '');
			$sshPort = intval(getSetting($pdo, "srv_{$srvId}_ssh_port", '22')) ?: 22;
			$pc = new PCClient($ip, $sshPort, 'linux', $sshUser, $sshPass);
			$linCmd = $cmd === 'shutdown'
				? '{ sudo /sbin/shutdown -h now 2>/dev/null || /sbin/shutdown -h now 2>/dev/null; } &'
				: '{ sudo /sbin/shutdown -r now 2>/dev/null || /sbin/shutdown -r now 2>/dev/null; } &';
			$out  = $pc->sshExec($linCmd);
			$isOk = !str_contains((string)$out, 'Permission denied')
				 && !str_contains((string)$out, 'Connection refused')
				 && !str_contains((string)$out, 'No route');
			$result = $isOk
				? "Comando '$cmd' enviado a {$sshUser}@{$ip} (Linux SSH)"
				: "Linux SSH error: " . trim((string)$out);

		} else {
			// Genérico — SSH root con clave pública
			$sshBin = trim((string)shell_exec('which ssh 2>/dev/null'));
			if (!$sshBin) {
				releaseLock($pdo, $srvId); echo err('ssh not available in the container'); break;
			}
			$sshCmd = $cmd === 'shutdown'
				? '/sbin/shutdown -h now "WakeLab: shutdown"'
				: '/sbin/shutdown -r now "WakeLab: reboot"';
			$out    = shell_exec("ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 -o BatchMode=yes "
						. escapeshellarg("root@{$ip}") . " " . escapeshellarg($sshCmd) . " 2>&1");
			$isOk   = !str_contains((string)$out, 'Permission denied')
				   && !str_contains((string)$out, 'Connection refused')
				   && !str_contains((string)$out, 'No route');
			$result = $isOk ? "Comando '$cmd' enviado vía SSH" : "SSH error: " . trim((string)$out);
		}

	} else {
		releaseLock($pdo, $srvId); echo err("Unrecognized command '$cmd'"); break;
	}

	releaseLock($pdo, $srvId);
	$level = $isOk ? ($cmd === 'wol' ? 'ok' : 'warn') : 'err';
	logEv($pdo, $srvId, $level, $source . $result);
	if ($isOk) {
		$pa = match($cmd) {
			'wol'      => 'manual_wol',
			'shutdown' => 'manual_shutdown',
			'reboot'   => 'manual_reboot',
			default    => 'manual_' . $cmd,
		};
		setPendingAction($pdo, $srvId, $pa);
	}
	invalidateCache();
	echo ok(null, $result);
	break;

// ─────────────────────────────────────────────────────────────
case 'add_server': {
	$_hn  = trim($data['hostname'] ?? '');
	$_ip  = trim($data['ip']       ?? '');
	$_mac = trim($data['mac']      ?? '');
	$_ht  = trim($data['hypervisor_type'] ?? 'pve');
	if (!isSafeHost($_hn)) { echo err('Invalid hostname'); break; }
	if ($_ip !== '' && !filter_var($_ip, FILTER_VALIDATE_IP) && !isSafeHost($_ip)) { echo err('Invalid IP'); break; }
	if (!in_array($_ht, ['pve','pbs','truenas','omv','generic','windows','linux'])) { echo err('Invalid hypervisor type'); break; }
	if ($_mac !== '' && !preg_match('/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/', $_mac)) { echo err('Invalid MAC address'); break; }
	$depOn = (!empty($data['depends_on_server_id'])) ? intval($data['depends_on_server_id']) : null;
	$pdo->prepare("INSERT INTO servers (hostname,ip,port,mac,role,hypervisor_type,notes,url,api_enabled,proxmox_server_id,proxmox_vmid,depends_on_server_id)
				   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
		->execute([
			$_hn, $_ip, intval($data['port'] ?? 8006),
			$_mac, $data['role'] ?? 'pve',
			$_ht, $data['notes'] ?? '',
			(function($u){ $u=trim($u); return ($u&&!preg_match('/^https?:\/\//i',$u))?'https://'.$u:$u; })($data['url']??''), intval($data['api_enabled'] ?? 0),
			!empty($data['proxmox_server_id']) ? intval($data['proxmox_server_id']) : null,
			!empty($data['proxmox_vmid'])      ? intval($data['proxmox_vmid'])      : null,
			$depOn,
		]);
	$newId = $pdo->lastInsertId();
	$pdo->prepare("INSERT IGNORE INTO schedules (server_id, active) VALUES (?, 0)")->execute([$newId]);

	if (!empty($data['token_secret'])) {
		$pdo->prepare("INSERT INTO api_tokens (server_id,auth_type,api_user,token_id,token_secret) VALUES (?,?,?,?,?)")
			->execute([$newId, $data['auth_type'] ?? 'pve_token',
						$data['api_user'] ?? 'root@pam', $data['token_id'] ?? 'panel', wlEncrypt($data['token_secret'])]);
	}
	// PC types: save SSH credentials
	$pcSshPushed = null;
	if ($_ht === 'windows' || $_ht === 'linux' || $_ht === 'generic') {
		$setSsh = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
		$_pcSshUser = trim($data['pc_ssh_user'] ?? '');
		$_pcSshPass = $data['pc_ssh_pass'] ?? '';
		if ($_pcSshUser) $setSsh->execute(["srv_{$newId}_ssh_user", $_pcSshUser]);
		if ($_pcSshPass) $setSsh->execute(["srv_{$newId}_ssh_pass", wlEncrypt($_pcSshPass)]);
		$setSsh->execute(["srv_{$newId}_ssh_port", '22']);

		// Auto-autorizar clave SSH
		if ($_pcSshPass && !empty($data['ip'])) {
			$pcSshPushed = pushSSHKey(trim($data['ip']), $_pcSshUser ?: 'root', $_pcSshPass, 22);
		}
	}
	logEv($pdo, $newId, 'ok', "Servidor registrado: {$_hn}");
	invalidateCache();
	echo ok(['id' => $newId], 'Server registered');
	break;
}

// ─────────────────────────────────────────────────────────────
case 'guest_action':
	$srvId  = intval($data['server_id']);
	$vmid   = intval($data['vmid'] ?? 0);
	$vmtype = $data['vmtype'] ?? 'qemu';
	$cmd    = $data['command'] ?? '';

	$srvR = $pdo->prepare("SELECT * FROM servers WHERE id=?"); $srvR->execute([$srvId]);
	$srv  = $srvR->fetch();
	$tok  = getToken($pdo, $srvId);

	if (!$srv || !$tok || empty($tok['token_secret'])) {
		echo err('Server or token not configured');
		break;
	}

	$hypType = $srv['hypervisor_type'] ?? 'pve';

	// ── TrueNAS: apps y VMs bhyve ──────────────────────────────
	if ($hypType === 'truenas') {
		require_once __DIR__ . '/truenas.php';
		$tnClient = new TrueNASClient($srv['ip'], intval($srv['port'] ?? 443),
		                               $tok['token_secret'], $tok['auth_type'] ?? 'api_key');
		$appName  = $data['app_name'] ?? '';

		if ($vmtype === 'truenas_app' && $appName) {
			$res = $cmd === 'start' ? $tnClient->startApp($appName) : $tnClient->stopApp($appName);
			$isOk = $res['ok'] ?? false;
			logEv($pdo, $srvId, $isOk ? 'ok' : 'err',
			      "TrueNAS app '{$appName}' {$cmd} — " . ($isOk ? 'OK' : ($res['curl_msg'] ?? 'error')));
			invalidateCache();
			echo $isOk ? ok([], "App {$appName} {$cmd}") : err($res['curl_msg'] ?? 'Error');
			break;
		}
		if ($vmtype === 'truenas_vm' && $vmid) {
			$res = $cmd === 'start' ? $tnClient->startVM($vmid) : $tnClient->stopVM($vmid);
			$isOk = $res['ok'] ?? false;
			logEv($pdo, $srvId, $isOk ? 'ok' : 'err',
			      "TrueNAS VM #{$vmid} {$cmd} — " . ($isOk ? 'OK' : ($res['curl_msg'] ?? 'error')));
			invalidateCache();
			echo $isOk ? ok([], "VM #{$vmid} {$cmd}") : err($res['curl_msg'] ?? 'Error');
			break;
		}
		echo err('Invalid type or parameters for TrueNAS');
		break;
	}

	// ── PVE / default ──────────────────────────────────────────
	$vmtype = in_array($vmtype, ['qemu','lxc']) ? $vmtype : 'qemu';
	$cmdMap = ['stop' => 'shutdown', 'reboot' => 'reboot', 'start' => 'start'];
	$pveCmd = $cmdMap[$cmd] ?? null;
	if (!$pveCmd) { echo err("Unrecognized command '$cmd'"); break; }

	$client   = new PVEClient($srv['ip'], intval($srv['port'] ?? 8006),
							   $tok['api_user'], $tok['token_id'], $tok['token_secret']);

	// Verificar estado actual del guest antes de ejecutar
	$guestStatus = $client->getGuestStatus($vmid);
	if ($cmd === 'start' && $guestStatus === 'running') {
		echo json_encode(['status' => 'already', 'message' => "Guest #{$vmid} is already running"]);
		break;
	}
	if ($cmd === 'stop' && $guestStatus === 'stopped') {
		echo json_encode(['status' => 'already', 'message' => "Guest #{$vmid} is already stopped"]);
		break;
	}

	// Lock por guest (clave compuesta srvId+vmid)
	$guestLockId = $srvId * 100000 + $vmid;
	if (!tryAcquireLock($pdo, $guestLockId, 'manual')) {
		echo json_encode(['status' => 'locked', 'message' => "A signal is already in progress for guest #{$vmid}"]);
		break;
	}

	$nodes    = $client->getNodes();
	$nodeName = preg_replace('/[^a-zA-Z0-9._-]/', '', $nodes[0]['node'] ?? $srv['hostname']);

	// URL idéntica para lxc y qemu — Proxmox REST usa el mismo path
	$url     = "https://{$srv['ip']}:{$srv['port']}/api2/json/nodes/{$nodeName}/{$vmtype}/{$vmid}/status/{$pveCmd}";
	$authHdr = "PVEAPIToken={$tok['api_user']}!{$tok['token_id']}={$tok['token_secret']}";

	// Proxmox espera application/x-www-form-urlencoded en estos endpoints,
	// NO application/json. Con JSON manda 500 silencioso en LXC.
	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_URL            => $url,
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => '',    // body vacío — form-encoded sin parámetros
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => false,
		CURLOPT_TIMEOUT        => 10,
		CURLOPT_HTTPHEADER     => [
			"Authorization: $authHdr",
			"Content-Type: application/x-www-form-urlencoded",
		],
	]);
	$respBody = curl_exec($ch);
	$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$cerr     = curl_error($ch);
	curl_close($ch);

	$isOk = ($http >= 200 && $http < 300);

	// Extraer mensaje de error real de Proxmox si hay fallo
	$pveMsg = '';
	if (!$isOk && $respBody) {
		$decoded = json_decode($respBody, true);
		$pveMsg  = $decoded['errors']
				   ?? $decoded['message']
				   ?? (is_string($decoded['data'] ?? null) ? $decoded['data'] : '');
		if (is_array($pveMsg)) $pveMsg = implode('; ', $pveMsg);
	}

	$detail = $isOk
		? "OK — UPID: " . (json_decode($respBody, true)['data'] ?? '?')
		: "HTTP {$http}" . ($pveMsg ? " — {$pveMsg}" : '') . ($cerr ? " | curl: $cerr" : '');

	releaseLock($pdo, $guestLockId);
	logEv($pdo, $srvId, $isOk ? 'ok' : 'err',
		  "Guest #{$vmid} ({$vmtype}/{$pveCmd}) — {$detail}");

	invalidateCache();
	echo ok(['http' => $http, 'pve_response' => $respBody], $detail);
	break;

// ─────────────────────────────────────────────────────────────
case 'update_guest_schedule':
	$gsDtz = trim($data['display_tz'] ?? '');
	$gsStored = (string)(getSetting($pdo, 'timezone', ''));
	$gsConvert = function(string $t) use ($gsDtz, $gsStored): string {
		if (!$gsDtz || !$gsStored || $gsDtz === $gsStored) return $t;
		try {
			$dt = DateTime::createFromFormat('H:i:s', $t, new DateTimeZone($gsDtz));
			if (!$dt) return $t;
			$dt->setTimezone(new DateTimeZone($gsStored));
			return $dt->format('H:i:s');
		} catch (Throwable) { return $t; }
	};
	$pdo->prepare("INSERT INTO guest_schedules (server_id,vmid,vmtype,boot_time,shutdown_time,boot_active,shutdown_active)
				   VALUES (?,?,?,?,?,?,?)
				   ON DUPLICATE KEY UPDATE boot_time=VALUES(boot_time),shutdown_time=VALUES(shutdown_time),boot_active=VALUES(boot_active),shutdown_active=VALUES(shutdown_active),vmtype=VALUES(vmtype)")
		->execute([
			intval($data['server_id']),
			intval($data['vmid']),
			in_array($data['vmtype']??'', ['qemu','lxc']) ? $data['vmtype'] : 'qemu',
			$gsConvert($data['boot_time']     ?? '08:00:00'),
			$gsConvert($data['shutdown_time'] ?? '00:00:00'),
			isset($data['active'])          ? intval($data['active'])          : 1,
			isset($data['shutdown_active']) ? intval($data['shutdown_active']) : 0,
		]);
	logEv($pdo, intval($data['server_id']), 'info', "Schedule guest #{$data['vmid']} saved");
	echo ok(null, 'Schedule saved');
	break;

// ─────────────────────────────────────────────────────────────
case 'get_guest_schedule':
	$stmt = $pdo->prepare("SELECT boot_time, shutdown_time, boot_active, shutdown_active FROM guest_schedules WHERE server_id = ? AND vmid = ?");
	$stmt->execute([intval($data['server_id'] ?? 0), intval($data['vmid'] ?? 0)]);
	$gsRow = $stmt->fetch() ?: null;
	if ($gsRow) {
		$gsDtzGet = trim($data['display_tz'] ?? '');
		$gsStoredGet = (string)(getSetting($pdo, 'timezone', ''));
		if ($gsDtzGet && $gsStoredGet && $gsDtzGet !== $gsStoredGet) {
			$gsConvGet = function(string $t) use ($gsStoredGet, $gsDtzGet): string {
				try {
					$dt = DateTime::createFromFormat('H:i:s', $t, new DateTimeZone($gsStoredGet));
					if (!$dt) return $t;
					$dt->setTimezone(new DateTimeZone($gsDtzGet));
					return $dt->format('H:i:s');
				} catch (Throwable) { return $t; }
			};
			$gsRow['boot_time']     = $gsConvGet($gsRow['boot_time']     ?? '08:00:00');
			$gsRow['shutdown_time'] = $gsConvGet($gsRow['shutdown_time'] ?? '00:00:00');
		}
	}
	echo ok($gsRow);
	break;

// ─────────────────────────────────────────────────────────────
case 'get_guest_idle':
	$stmt = $pdo->prepare("SELECT idle_limit_sec, active FROM guest_idle WHERE server_id = ? AND vmid = ?");
	$stmt->execute([intval($data['server_id'] ?? 0), intval($data['vmid'] ?? 0)]);
	echo ok($stmt->fetch() ?: null);
	break;

// ─────────────────────────────────────────────────────────────
case 'get_guest_data':
	// Batch: schedule + idle + meta en una sola llamada
	$gSrvId = intval($data['server_id'] ?? 0);
	$gVmid  = intval($data['vmid'] ?? 0);
	$gDtz   = trim($data['display_tz'] ?? '');

	$schStmt = $pdo->prepare("SELECT boot_time, shutdown_time, boot_active, shutdown_active FROM guest_schedules WHERE server_id=? AND vmid=?");
	$schStmt->execute([$gSrvId, $gVmid]);
	$schRow = $schStmt->fetch() ?: null;
	if ($schRow && $gDtz) {
		$gStoredTz = (string)getSetting($pdo, 'timezone', '');
		if ($gStoredTz && $gDtz !== $gStoredTz) {
			$conv = function(string $t) use ($gStoredTz, $gDtz): string {
				try {
					$dt = DateTime::createFromFormat('H:i:s', $t, new DateTimeZone($gStoredTz));
					if (!$dt) return $t;
					$dt->setTimezone(new DateTimeZone($gDtz));
					return $dt->format('H:i:s');
				} catch (Throwable) { return $t; }
			};
			$schRow['boot_time']     = $conv($schRow['boot_time']     ?? '08:00:00');
			$schRow['shutdown_time'] = $conv($schRow['shutdown_time'] ?? '00:00:00');
		}
	}

	$idleStmt = $pdo->prepare("SELECT idle_limit_sec, active FROM guest_idle WHERE server_id=? AND vmid=?");
	$idleStmt->execute([$gSrvId, $gVmid]);
	$idleRow = $idleStmt->fetch() ?: null;

	$metaStmt = $pdo->prepare("SELECT * FROM guest_meta WHERE server_id=? AND vmid=?");
	$metaStmt->execute([$gSrvId, $gVmid]);
	$metaRow = $metaStmt->fetch() ?: null;

	echo ok(['schedule' => $schRow, 'idle' => $idleRow, 'meta' => $metaRow]);
	break;

// ─────────────────────────────────────────────────────────────
case 'update_guest_idle':
	$pdo->prepare("INSERT INTO guest_idle (server_id,vmid,vmtype,idle_limit_sec,active)
				   VALUES (?,?,?,?,?)
				   ON DUPLICATE KEY UPDATE idle_limit_sec=VALUES(idle_limit_sec),active=VALUES(active),vmtype=VALUES(vmtype)")
		->execute([
			intval($data['server_id']),
			intval($data['vmid']),
			in_array($data['vmtype'] ?? '', ['qemu', 'lxc']) ? $data['vmtype'] : 'qemu',
			intval($data['idle_limit_sec'] ?? 1800),
			isset($data['active']) ? intval($data['active']) : 1,
		]);
	logEv($pdo, intval($data['server_id']), 'info', "Idle guest #{$data['vmid']} saved");
	echo ok(null, 'Idle config saved');
	break;

// ─────────────────────────────────────────────────────────────
case 'delete_server':
	$srvId = intval($data['server_id'] ?? 0);
	if (!$srvId) { echo err('Invalid ID'); break; }
	// Las FK con ON DELETE CASCADE eliminan tokens, schedules, idle_config, events
	$pdo->prepare("DELETE FROM servers WHERE id=?")->execute([$srvId]);
	invalidateCache();
	logEv($pdo, null, 'warn', "Server #$srvId deleted");
	echo ok(null, 'Server deleted');
	break;

// ─────────────────────────────────────────────────────────────
case 'idle_config':
	// Devuelve KEY=value (texto plano) para eval directo en bash
	$host = preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['host'] ?? $data['host'] ?? '');
	header('Content-Type: text/plain');
	if (!$host) { echo 'WL_ACTIVE=0'; break; }
	$r = $pdo->prepare(
		"SELECT ic.*, s.id AS srv_id, s.hypervisor_type
		 FROM idle_config ic JOIN servers s ON s.id=ic.server_id
		 WHERE s.hostname=? LIMIT 1"
	);
	$r->execute([$host]);
	$row = $r->fetch();
	if (!$row || intval($row['active']) !== 1) { echo 'WL_ACTIVE=0'; break; }
	// Wake-proxy check
	$hitR = $pdo->prepare(
		"SELECT last_proxy_hit FROM wake_proxies
		 WHERE server_id=? AND active=1 AND last_proxy_hit IS NOT NULL
		 ORDER BY last_proxy_hit DESC LIMIT 1"
	);
	$hitR->execute([(int)$row['srv_id']]);
	$hitRow = $hitR->fetch();
	$icActive = 1;
	if ($hitRow && (time() - strtotime($hitRow['last_proxy_hit'])) < (int)$row['idle_limit_sec']) {
		$icActive = 0;
	}
	$srvType = $row['hypervisor_type'] ?? 'pve';
	$isOmv   = ($srvType === 'omv');
	$useSudo = 0;
	if ($isOmv || $srvType === 'linux') {
		$st = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
		$st->execute(["srv_{$row['srv_id']}_ssh_user"]);
		$rr = $st->fetch();
		$sshU = $rr ? trim((string)$rr['value']) : 'root';
		$useSudo = ($sshU !== '' && $sshU !== 'root') ? 1 : 0;
	}
	$det  = json_decode($row['detectors_json']       ?? '{}', true) ?? [];
	$prms = json_decode($row['detector_params_json'] ?? '{}', true) ?? [];
	// Single-quote a value for bash assignment: the only shell-special char inside
	// single quotes that can escape is a lone ' itself, which we replace with '"'"'.
	// This makes eval safe for any string value stored in the DB.
	$sq = fn(string $v): string => "'" . str_replace("'", "'\"'\"'", $v) . "'";
	$lines = [
		'WL_ACTIVE='   . $icActive,
		'IDLE_LIMIT='  . (int)($row['idle_limit_sec']     ?? 1800),
		'CHECK_INT='   . (int)($row['check_interval_sec'] ?? 300),
		'SRV_TYPE='    . $sq($srvType),
		'USE_SUDO='    . $useSudo,
		'DET_SMB='     . (empty($det['smb'])      ? 0 : 1),
		'DET_SSH='     . (empty($det['ssh'])      ? 0 : 1),
		'DET_CPU='     . (empty($det['cpu'])      ? 0 : 1),
		'CPU_THRESH='  . (int)($det['cpu_threshold'] ?? 20),
		'DET_JELLY='   . (empty($det['jellyfin']) ? 0 : 1),
		'DET_QBIT='    . (empty($det['qbit'])     ? 0 : 1),
		'DET_PBS='     . (empty($det['pbs'])      ? 0 : 1),
	];
	if (!empty($det['jellyfin'])) {
		$jf = $prms['jellyfin'] ?? [];
		$lines[] = 'JELLY_HOST=' . $sq($jf['host']  ?? 'localhost');
		$lines[] = 'JELLY_PORT=' . (int)($jf['port'] ?? 8096);
		$lines[] = 'JELLY_TOKEN=' . $sq($jf['token'] ?? '');
	}
	if (!empty($det['qbit'])) {
		$qb = $prms['qbit'] ?? [];
		$lines[] = 'QBIT_HOST=' . $sq($qb['host'] ?? 'localhost');
		$lines[] = 'QBIT_PORT=' . (int)($qb['port'] ?? 8080);
	}
	if (!empty($det['pbs']) && $srvType !== 'pbs') {
		$pb = $prms['pbs'] ?? [];
		$lines[] = 'PBS_HOST=' . $sq($pb['host'] ?? 'localhost');
		$lines[] = 'PBS_PORT=' . (int)($pb['port'] ?? 8007);
	}
	if ($srvType === 'pbs') {
		$ppbR = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
		$ppbR->execute(["srv_{$row['srv_id']}_pbs_postbackup"]);
		$lines[] = 'PBS_POSTBACKUP=' . ($ppbR->fetchColumn() === '1' ? 1 : 0);
	}
	echo implode("\n", $lines);
	break;

// ─────────────────────────────────────────────────────────────
case 'idle_active':
	$host = preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['host'] ?? $data['host'] ?? '');
	if (!$host) { echo '0'; break; }
	$r = $pdo->prepare(
		"SELECT ic.active, ic.idle_limit_sec, s.id AS srv_id
		 FROM idle_config ic JOIN servers s ON s.id=ic.server_id
		 WHERE s.hostname=? LIMIT 1"
	);
	$r->execute([$host]);
	$row = $r->fetch();
	if (!$row || intval($row['active']) !== 1) { echo '0'; break; }
	// Recent wake-proxy hit for this server → pause idle detection
	$hitR = $pdo->prepare(
		"SELECT last_proxy_hit FROM wake_proxies
		 WHERE server_id=? AND active=1 AND last_proxy_hit IS NOT NULL
		 ORDER BY last_proxy_hit DESC LIMIT 1"
	);
	$hitR->execute([(int)$row['srv_id']]);
	$hitRow = $hitR->fetch();
	if ($hitRow && (time() - strtotime($hitRow['last_proxy_hit'])) < (int)$row['idle_limit_sec']) {
		echo '0'; break;
	}
	echo '1';
	break;

// ─────────────────────────────────────────────────────────────
case 'log_event':
$leId  = intval($data['server_id'] ?? 0);
$leMsg = trim(substr($data['message'] ?? '', 0, 500));
$leLvl = in_array($data['level'] ?? '', ['ok','warn','err','info']) ? $data['level'] : 'info';
if (!$leMsg) { echo err('message required'); break; }
// Verify server_id belongs to a real server if provided
if ($leId > 0) {
    $leChk = $pdo->prepare("SELECT id FROM servers WHERE id=? LIMIT 1");
    $leChk->execute([$leId]);
    if (!$leChk->fetchColumn()) { echo err('invalid server_id'); break; }
}
logEv($pdo, $leId ?: null, $leLvl, $leMsg);
echo ok([]);
break;

// ─────────────────────────────────────────────────────────────
case 'idle_event':
	$host  = preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['host']  ?? $data['host']  ?? '');
	$event = preg_replace('/[^a-zA-Z0-9_-]/',  '', $_GET['event'] ?? $data['event'] ?? 'unknown');
	if (!$host) { echo err('host required'); break; }
	$srvR = $pdo->prepare("SELECT * FROM servers WHERE hostname=? LIMIT 1");
	$srvR->execute([$host]);
	$srv   = $srvR->fetch();
	$idleMsg = match($event) {
		'shutdown' => "Idle shutdown — no activity detected on {$host}",
		'error'    => "Error in idle script on {$host}",
		default    => "Idle [{$host}]: {$event}",
	};
	$level = match($event) { 'shutdown' => 'warn', 'error' => 'err', default => 'info' };
	logEv($pdo, $srv ? (int)$srv['id'] : null, $level, $idleMsg);

	// Notificar idle shutdown (push + telegram + email)
	if ($event === 'shutdown' && $srv) {
		try {
			// Marcar pending para que el frontend no duplique la notificación de offline
			setPendingAction($pdo, (int)$srv['id'], 'idle_shutdown');
			require_once __DIR__ . '/notify.php';
			WakeNotify::notifyAll($pdo, [
				'title'          => '💤 ' . htmlspecialchars($host) . ' — shutdown signal',
				'body'           => "Idle shutdown signal sent to $host",
				'hostname'       => $host,
				'ip'             => $srv['ip'] ?? '',
				'pending_action' => 'idle_shutdown',
				'tag'            => 'idle-' . ($srv['id'] ?? 0),
				'url'            => './',
			], 'idle');
		} catch (Throwable) {}
	}

	echo ok(null, 'Event logged');
	break;

// ─────────────────────────────────────────────────────────────
// TELEGRAM NOTIFICATIONS
// ─────────────────────────────────────────────────────────────

case 'get_telegram_settings':
	echo ok([
		'enabled' => getSetting($pdo, 'telegram_enabled', '0') === '1',
		'token'   => getSetting($pdo, 'telegram_token',   ''),
		'chat_id' => getSetting($pdo, 'telegram_chat_id', ''),
	]);
	break;

case 'save_telegram_settings':
	$ins = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	$ins->execute(['telegram_token',   wlEncrypt(trim($data['token']   ?? ''))]);
	$ins->execute(['telegram_chat_id', trim($data['chat_id'] ?? '')]);
	echo ok(null, 'Saved');
	break;

case 'test_telegram':
	$token  = trim($data['token']   ?? getSetting($pdo, 'telegram_token',   ''));
	$chatId = trim($data['chat_id'] ?? getSetting($pdo, 'telegram_chat_id', ''));
	if (!$token || !$chatId) { echo err('token and chat_id required'); break; }
	try {
		require_once __DIR__ . '/notify.php';
		$res = WakeNotify::sendTelegram($token, $chatId, "🔔 <b>WakeLab — test</b>\nTelegram notifications are working correctly.");
		echo ok(['http_code' => $res['code']], 'Message sent');
	} catch (Throwable $e) { echo err($e->getMessage()); }
	break;

// ─────────────────────────────────────────────────────────────
// EMAIL NOTIFICATIONS
// ─────────────────────────────────────────────────────────────

case 'get_email_settings':
	echo ok([
		'enabled'     => getSetting($pdo, 'email_enabled',     '0') === '1',
		'smtp_host'   => getSetting($pdo, 'email_smtp_host',   ''),
		'smtp_port'   => getSetting($pdo, 'email_smtp_port',   '587'),
		'smtp_secure' => getSetting($pdo, 'email_smtp_secure', 'tls'),
		'smtp_user'   => getSetting($pdo, 'email_smtp_user',   ''),
		'smtp_pass'   => getSetting($pdo, 'email_smtp_pass',   '') !== '' ? '••••••••' : '',
		'from'        => getSetting($pdo, 'email_from',        ''),
		'from_name'   => getSetting($pdo, 'email_from_name',   'WakeLab'),
		'to'          => getSetting($pdo, 'email_to',          ''),
		'wakelab_url' => getSetting($pdo, 'wakelab_url',       ''),
	]);
	break;

case 'save_email_settings':
	$ins = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	$ins->execute(['email_smtp_host',   trim($data['smtp_host']   ?? '')]);
	$ins->execute(['email_smtp_port',   trim($data['smtp_port']   ?? '587')]);
	$ins->execute(['email_smtp_secure', trim($data['smtp_secure'] ?? 'tls')]);
	$ins->execute(['email_smtp_user',   trim($data['smtp_user']   ?? '')]);
	if (($data['smtp_pass'] ?? '') !== '' && ($data['smtp_pass'] ?? '') !== '••••••••') {
		$ins->execute(['email_smtp_pass', wlEncrypt($data['smtp_pass'])]);
	}
	$ins->execute(['email_from',      trim($data['from']      ?? '')]);
	$ins->execute(['email_from_name', trim($data['from_name'] ?? 'WakeLab')]);
	$ins->execute(['email_to',        trim($data['to']        ?? '')]);
	$ins->execute(['wakelab_url',     trim($data['wakelab_url'] ?? '')]);
	echo ok(null, 'Saved');
	break;

case 'test_email':
	try {
		require_once __DIR__ . '/notify.php';
		$cfg = [
			'host'      => trim($data['smtp_host']   ?? getSetting($pdo, 'email_smtp_host',   '')),
			'port'      => trim($data['smtp_port']   ?? getSetting($pdo, 'email_smtp_port',   '587')),
			'secure'    => trim($data['smtp_secure'] ?? getSetting($pdo, 'email_smtp_secure', 'tls')),
			'user'      => trim($data['smtp_user']   ?? getSetting($pdo, 'email_smtp_user',   '')),
			'pass'      => ($data['smtp_pass'] ?? '') !== '' && ($data['smtp_pass'] ?? '') !== '••••••••'
			                ? $data['smtp_pass']
			                : getSetting($pdo, 'email_smtp_pass', ''),
			'from'      => trim($data['from']        ?? getSetting($pdo, 'email_from',        '')),
			'from_name' => trim($data['from_name']   ?? getSetting($pdo, 'email_from_name',   'WakeLab')),
			'to'        => trim($data['to']          ?? getSetting($pdo, 'email_to',          '')),
		];
		if (!$cfg['host'] || !$cfg['to']) { echo err('host and recipient required'); break; }
		$wlUrl = trim($data['wakelab_url'] ?? getSetting($pdo, 'wakelab_url', ''));
		WakeNotify::sendEmail($cfg, '🔔 WakeLab — test', WakeNotify::testEmailHtml($wlUrl));
		echo ok(null, 'Email sent');
	} catch (Throwable $e) { echo err($e->getMessage()); }
	break;

// ─────────────────────────────────────────────────────────────
// PLANTILLAS DE NOTIFICACIÓN
// ─────────────────────────────────────────────────────────────

case 'get_templates':
	$evts = ['server_down','server_up','schedule','idle','error','guest_unknown'];
	$in   = implode(',', array_fill(0, count($evts), '?'));
	$keys = array_map(fn($e) => "tpl_{$e}", $evts);
	$st   = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ($in)");
	$st->execute($keys);
	$rows = array_column($st->fetchAll(), 'value', 'key');
	$tpls = [];
	foreach ($evts as $e) $tpls[$e] = $rows["tpl_{$e}"] ?? '';
	echo ok($tpls);
	break;

case 'save_templates':
	$ins = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	foreach (['server_down','server_up','schedule','idle','error','guest_unknown'] as $e) {
		if (array_key_exists($e, $data)) {
			$ins->execute(["tpl_{$e}", $data[$e]]);
		}
	}
	echo ok(null, 'Templates saved');
	break;

case 'reset_templates':
	// Restaura los defaults originales desde DB.sql (seeds en tabla settings)
	$defaults = $pdo->prepare(
		"SELECT `key`,`value` FROM settings WHERE `key` LIKE 'tpl_default_%'"
	);
	$defaults->execute();
	$ins = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	$tpls = [];
	foreach ($defaults->fetchAll() as $row) {
		$evt = substr($row['key'], strlen('tpl_default_'));
		$ins->execute(["tpl_{$evt}", $row['value']]);
		$tpls[$evt] = $row['value'];
	}
	echo ok($tpls, 'Templates restored');
	break;

// ─────────────────────────────────────────────────────────────
// DEBUG MODE
// ─────────────────────────────────────────────────────────────

case 'update_debug_mode':
	$val = ($data['enabled'] ?? false) ? '1' : '0';
	$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('debug_mode',?) ON DUPLICATE KEY UPDATE `value`=?")
	    ->execute([$val, $val]);
	echo ok(null, 'Debug mode ' . ($val === '1' ? 'enabled' : 'disabled'));
	break;

// ─────────────────────────────────────────────────────────────
case 'update_user':
	if (empty($_SESSION['id'])) { echo err('Invalid session', 401); break; }
	$userId      = intval($_SESSION['id']);
	$newUsuario  = trim($data['usuario']     ?? '');
	$newEmail    = trim($data['email']       ?? '');
	$passCurrent = $data['pass_current']     ?? '';
	$passNew     = $data['pass_new']         ?? null;

	if (!$newUsuario || !$newEmail) { echo err('Username and email are required'); break; }
	if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) { echo err('Invalid email'); break; }

	// Verify current password
	$row = $pdo->prepare("SELECT contrasena FROM usuarios WHERE id=?");
	$row->execute([$userId]);
	$user = $row->fetch();
	if (!$user || !password_verify($passCurrent, $user['contrasena'])) {
		echo err('Current password is incorrect'); break;
	}

	// Check unique constraints (exclude self)
	$dup = $pdo->prepare("SELECT id FROM usuarios WHERE (usuario=? OR email=?) AND id!=?");
	$dup->execute([$newUsuario, $newEmail, $userId]);
	if ($dup->fetch()) { echo err('Username or email already in use'); break; }

	if ($passNew) {
		if (strlen($passNew) < 8) { echo err('New password must be at least 8 characters'); break; }
		$hash = password_hash($passNew, PASSWORD_DEFAULT);
		$pdo->prepare("UPDATE usuarios SET usuario=?,email=?,contrasena=? WHERE id=?")
		    ->execute([$newUsuario, $newEmail, $hash, $userId]);
	} else {
		$pdo->prepare("UPDATE usuarios SET usuario=?,email=? WHERE id=?")
		    ->execute([$newUsuario, $newEmail, $userId]);
	}
	$_SESSION['usuario'] = $newUsuario;
	$_SESSION['email']   = $newEmail;
	logEv($pdo, null, 'info', "Usuario #{$userId} actualizó sus datos de acceso");
	echo ok(null, 'Profile updated');
	break;

// ─────────────────────────────────────────────────────────────
// IDLE LOG (debug)
// ─────────────────────────────────────────────────────────────

case 'get_idle_log':
case 'debug_idle_cron':
case 'clean_idle_host':
	$srvId = intval($data['server_id'] ?? 0);
	if (!$srvId) { echo err('Invalid ID'); break; }
	$srvR = $pdo->prepare("SELECT * FROM servers WHERE id=?"); $srvR->execute([$srvId]);
	$srv  = $srvR->fetch();
	$idlR = $pdo->prepare("SELECT remote_path,check_interval_sec FROM idle_config WHERE server_id=?"); $idlR->execute([$srvId]);
	$idlD = $idlR->fetch();
	if (!$srv) { echo err('Server not found'); break; }
	$ip      = $srv['ip'];
	$srvType = $srv['hypervisor_type'] ?? 'pve';
	$isTN    = ($srvType === 'truenas');
	$isOMV   = ($srvType === 'omv');
	// ── Resolver credenciales SSH igual que deploy_idle_script ──────
	$sshUser = 'root'; $sshPass = ''; $sshPort = 22;
	if ($isTN || $isOMV) {
		try {
			$stSSH = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN (?,?,?)");
			$stSSH->execute(["srv_{$srvId}_ssh_user","srv_{$srvId}_ssh_pass","srv_{$srvId}_ssh_port"]);
			$sshCfg = [];
			foreach ($stSSH->fetchAll() as $r) $sshCfg[$r['key']] = wlDecrypt($r['value']);
		} catch (Throwable) { $sshCfg = []; }
		$sshUser = $sshCfg["srv_{$srvId}_ssh_user"] ?? 'root';
		if (!$sshUser) $sshUser = 'root';
		$sshPass = $sshCfg["srv_{$srvId}_ssh_pass"] ?? '';
		$sshPort = intval($sshCfg["srv_{$srvId}_ssh_port"] ?? 22) ?: 22;
	}
	$omvAsRoot = $isOMV && ($sshUser === 'root' || $sshUser === '');
	// ── Log path ────────────────────────────────────────────────────
	$logFile = ($isTN || $omvAsRoot) ? '/root/wakelab-idle.log'
	         : ($isOMV               ? '/tmp/wakelab-idle.log'
	         :                         '/var/log/wakelab-idle.log');
	// ── Remote path — misma lógica que deploy_idle_script ───────────
	if ($isTN) {
		$homeDir    = ($sshUser === 'root') ? '/root' : "/home/{$sshUser}";
		$remotePath = "{$homeDir}/idle-shutdown.sh";
	} elseif ($isOMV) {
		$stored     = $idlD['remote_path'] ?? '';
		$omvDefault = $omvAsRoot ? '/root/idle-shutdown.sh' : '/tmp/idle-shutdown.sh';
		$remotePath = ($stored && $stored !== '/usr/local/bin/idle-shutdown.sh') ? $stored : $omvDefault;
	} else {
		$remotePath = $idlD['remote_path'] ?? '/usr/local/bin/idle-shutdown.sh';
	}
	$chkMinD = max(1, intval(intval($idlD['check_interval_sec'] ?? 300) / 60));
	if (!trim((string)shell_exec('which ssh 2>/dev/null'))) { echo err('ssh not available on the web server'); break; }
	if (!isSafeHost($ip)) { echo err('Invalid IP'); break; }
	$hasSshpass  = (bool)trim((string)shell_exec('which sshpass 2>/dev/null'));
	$usePassword = ($isTN || $isOMV) && $sshPass !== '';
	if ($usePassword && !$hasSshpass) { echo err('sshpass not available — configure an SSH key in the container'); break; }
	$sshpassPfx = $usePassword ? 'sshpass -p ' . escapeshellarg($sshPass) . ' ' : '';
	$portOpt    = $sshPort !== 22 ? "-p {$sshPort}" : '';
	$sshOpts    = "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 {$portOpt}";
	$target     = escapeshellarg("{$sshUser}@{$ip}");
	if ($action === 'get_idle_log') {
		$logCmd = "if [ -f " . escapeshellarg($logFile) . " ]; then"
		        . " tail -100 " . escapeshellarg($logFile) . ";"
		        . " else echo '(log no encontrado: {$logFile})';"
		        . " fi";
		$out = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($logCmd) . " 2>&1");
		echo ok(['log' => $out ?: '(no content)', 'log_path' => $logFile]);
	} elseif ($action === 'debug_idle_cron') {
		$cronEntry = ($isTN || $isOMV)
			? "*/{$chkMinD} * * * * bash {$remotePath}"
			: "*/{$chkMinD} * * * * root {$remotePath}";

		// ── Script check ─────────────────────────────────────────
		$scriptCheck = "test -f " . escapeshellarg($remotePath)
		             . " && echo 'Script encontrado: {$remotePath}'"
		             . " || echo 'Script NO encontrado: {$remotePath}'";

		// ── LOG_FILE line from the deployed script ────────────────
		$logFileCheck = "grep -m1 '^LOG_FILE=' " . escapeshellarg($remotePath)
		              . " 2>/dev/null || echo 'LOG_FILE=(no legible)'";

		// ── Cron check — unified format ───────────────────────────
		if ($isTN || $isOMV) {
			// user crontab
			$cronCheck = "ENTRY=\$(crontab -l 2>/dev/null | grep -F " . escapeshellarg($remotePath) . ");"
			           . " if [ -n \"\$ENTRY\" ]; then echo \"Cron instalado: \$ENTRY\";"
			           . " else echo 'Cron NO instalado para {$remotePath}'; fi";
		} else {
			$cronCheck = "if test -f /etc/cron.d/wakelab-idle; then echo 'Cron instalado: /etc/cron.d/wakelab-idle';"
			           . " else echo 'Cron NO instalado: /etc/cron.d/wakelab-idle no existe'; fi";
		}

		// ── Crond status ──────────────────────────────────────────
		$crondCheck = "systemctl is-active cron 2>/dev/null || systemctl is-active crond 2>/dev/null || echo 'unknown'";

		// ── WakeLab connectivity from remote host ─────────────────
		// Grep URL/HOST from deployed script, test curl back to WakeLab
		$wakelabCheck = "WL_URL=\$(grep -m1 '^WAKELAB_URL=' " . escapeshellarg($remotePath) . " 2>/dev/null | cut -d= -f2-);"
		              . " WL_HOST=\$(grep -m1 '^WL_HOST=' " . escapeshellarg($remotePath) . " 2>/dev/null | cut -d= -f2-);"
		              . " if [ -n \"\$WL_URL\" ]; then"
		              . "   RES=\$(curl -sLk --max-time 5 \"\${WL_URL}/php/api.php?action=idle_active&host=\${WL_HOST}\" 2>&1);"
		              . "   if [ -n \"\$RES\" ]; then echo \"WakeLab OK: \${WL_URL} → \${RES}\";"
		              . "   else echo \"WakeLab NO alcanzable: \${WL_URL}\"; fi;"
		              . " else echo 'WAKELAB_URL no encontrada en script'; fi";

		$scriptOut  = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($scriptCheck)  . " 2>&1");
		$logFileOut = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($logFileCheck) . " 2>&1");
		$cronOut    = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($cronCheck)    . " 2>&1");
		$crondOut   = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($crondCheck)   . " 2>&1");
		$wlOut      = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($wakelabCheck) . " 2>&1");
		echo ok([
			'script'      => trim($scriptOut)  ?: '(no output)',
			'log_file'    => trim($logFileOut) ?: '(no output)',
			'cron'        => trim($cronOut)    ?: '(no output)',
			'crond'       => trim($crondOut)   ?: '(no output)',
			'wakelab'     => trim($wlOut)      ?: '(no output)',
			'script_path' => $remotePath,
			'log_path'    => $logFile,
			'cron_entry'  => $cronEntry,
		]);
	} else {
		// clean_idle_host — borra script, log y entrada cron
		if ($isTN || $isOMV) {
			// Remove by matching the exact path (entry format has no "wakelab" keyword)
			$cleanCmd = "(crontab -l 2>/dev/null | grep -vF " . escapeshellarg($remotePath) . ") | crontab - 2>/dev/null; "
			          . "rm -f " . escapeshellarg($remotePath) . " " . escapeshellarg($logFile) . "; "
			          . "echo 'LIMPIEZA OK'";
		} else {
			$cleanCmd = "rm -f " . escapeshellarg($remotePath) . " "
			          . escapeshellarg($logFile) . " /etc/cron.d/wakelab-idle 2>/dev/null; "
			          . "echo 'LIMPIEZA OK'";
		}
		$out = (string)shell_exec("{$sshpassPfx}ssh {$sshOpts} {$target} " . escapeshellarg($cleanCmd) . " 2>&1");
		$ok  = str_contains($out, 'LIMPIEZA OK');
		if ($ok) logEv($pdo, $srvId, 'warn', "Idle host limpiado ({$sshUser}@{$ip}): script, log y cron eliminados");
		echo ok(['result' => trim($out) ?: '(no output)', 'cleaned' => $ok]);
	}
	break;

// ─────────────────────────────────────────────────────────────
// PUSH NOTIFICATIONS
// ─────────────────────────────────────────────────────────────

case 'generate_vapid':
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo err('Method not allowed'); break; }
	require_once __DIR__ . '/push.php';
	$keys = WakePush::generateKeys();
	$ins  = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	$adminEmail = $pdo->query("SELECT email FROM usuarios ORDER BY id LIMIT 1")->fetchColumn();
	$vapidSubject = $adminEmail ? "mailto:{$adminEmail}" : 'mailto:admin@localhost';
	$ins->execute(['vapid_public',  $keys['public']]);
	$ins->execute(['vapid_private', wlEncrypt($keys['private'])]);
	$ins->execute(['vapid_subject', $vapidSubject]);
	echo ok(['public_key' => $keys['public']]);
	break;

case 'get_vapid_public':
	echo ok(['public_key' => getSetting($pdo, 'vapid_public', '')]);
	break;

case 'push_subscribe':
	$endpoint = $data['endpoint'] ?? '';
	$p256dh   = $data['p256dh']   ?? '';
	$auth     = $data['auth']     ?? '';
	if (!$endpoint || !$p256dh || !$auth) { echo err('faltan campos'); break; }
	try {
		$hash = hash('sha256', $endpoint);
		$pdo->prepare(
			"INSERT INTO push_subscriptions (endpoint_hash,endpoint,p256dh,auth) VALUES (?,?,?,?)
			 ON DUPLICATE KEY UPDATE endpoint=VALUES(endpoint), p256dh=VALUES(p256dh), auth=VALUES(auth)"
		)->execute([$hash, $endpoint, $p256dh, $auth]);
		echo ok(['subscribed' => true]);
	} catch (Throwable $e) { echo err('DB: ' . $e->getMessage()); }
	break;

case 'push_unsubscribe':
	$endpoint = $data['endpoint'] ?? '';
	if (!$endpoint) { echo err('endpoint required'); break; }
	$hash = hash('sha256', $endpoint);
	$pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash=?")->execute([$hash]);
	echo ok(['unsubscribed' => true]);
	break;

case 'get_push_settings':
	$vapidReady = (bool) getSetting($pdo, 'vapid_public', '');
	try { $cnt = (int) $pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn(); }
	catch (Throwable) { $cnt = 0; }
	echo ok(['enabled' => getSetting($pdo, 'push_enabled', '1') === '1', 'vapid_ready' => $vapidReady, 'subscription_count' => $cnt]);
	break;

case 'save_push_settings':
	$ins2 = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	$ins2->execute(['push_enabled', ($data['enabled'] ?? true) ? '1' : '0']);
	echo ok(null, 'Saved');
	break;

case 'get_notify_events':
	$evtKeys = ['server_down','server_up','schedule','idle','error','guest_unknown'];
	$evts    = [];
	foreach ($evtKeys as $k) $evts[$k] = getSetting($pdo, "notify_event_$k", '1') === '1';
	echo ok($evts);
	break;

case 'save_notify_events':
	$evtKeys = ['server_down','server_up','schedule','idle','error','guest_unknown'];
	$evts    = $data['events'] ?? [];
	$ins2    = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	foreach ($evtKeys as $k) $ins2->execute(["notify_event_$k", isset($evts[$k]) ? ($evts[$k] ? '1' : '0') : '1']);
	echo ok(null, 'Saved');
	break;

case 'get_notify_global':
	echo ok([
		'enabled'           => getSetting($pdo, 'notify_global_enabled',   '1') === '1',
		'down_delay_sec'    => intval(getSetting($pdo, 'notify_down_delay_sec', '60')),
		'unknown_guest_min' => intval(getSetting($pdo, 'unknown_guest_alert_min','10')),
	]);
	break;

case 'save_notify_global':
	$ins3 = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
	if (array_key_exists('enabled', $data))           $ins3->execute(['notify_global_enabled',   ($data['enabled'] ?? true) ? '1' : '0']);
	if (array_key_exists('down_delay_sec', $data))    $ins3->execute(['notify_down_delay_sec',   max(0, intval($data['down_delay_sec']))]);
	if (array_key_exists('unknown_guest_min', $data)) $ins3->execute(['unknown_guest_alert_min', max(1, intval($data['unknown_guest_min']))]);
	echo ok(null, 'Saved');
	break;

case 'toggle_notify_channel':
	$channel = preg_replace('/[^a-z]/', '', $data['channel'] ?? '');
	if (!in_array($channel, ['push','telegram','email'])) { echo err('invalid channel'); break; }
	$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
	    ->execute(["{$channel}_enabled", ($data['enabled'] ?? false) ? '1' : '0']);
	echo ok(null);
	break;

case 'send_push_event':
	$evtName = $data['event'] ?? '';
	if (!$evtName) { echo err('event required'); break; }
	try {
		require_once __DIR__ . '/notify.php';

		// Enriquecer payload con hostname/ip/pending_action para la IA
		$srvId   = intval($data['server_id'] ?? 0);
		$srvHost = ''; $srvIp = '';
		if ($srvId > 0) {
			$srvQ = $pdo->prepare("SELECT hostname, ip FROM servers WHERE id=? LIMIT 1");
			$srvQ->execute([$srvId]);
			$srvRow  = $srvQ->fetch(PDO::FETCH_ASSOC) ?: [];
			$srvHost = $srvRow['hostname'] ?? '';
			$srvIp   = $srvRow['ip']       ?? '';
		}

		WakeNotify::notifyAll($pdo, [
			'title'          => $data['title']          ?? 'WakeLab',
			'body'           => $data['body']            ?? '',
			'tag'            => $data['tag']             ?? $evtName,
			'url'            => $data['url']             ?? './',
			'hostname'       => $srvHost,
			'ip'             => $srvIp,
			'pending_action' => $data['pending_action']  ?? null,
		], $evtName);
		echo ok(null, 'notification sent');
	} catch (Throwable $e) { echo err('error: ' . $e->getMessage()); }
	break;

// ─────────────────────────────────────────────────────────────
case 'get_ai_config':
	require_once __DIR__ . '/ai.php';
	$cfg    = WakeAI::loadConfig($pdo);
	$masked = $cfg['ai_api_key'] !== '' ? '••••••••' : '';
	echo ok([
		'ai_enabled'       => $cfg['ai_enabled'],
		'ai_provider'      => $cfg['ai_provider'],
		'ai_model'         => $cfg['ai_model'],
		'ai_api_key'       => $masked,
		'ai_use_emojis'    => $cfg['ai_use_emojis'],
		'ai_highlight'     => $cfg['ai_highlight'],
		'ai_tone'          => $cfg['ai_tone'],
		'ai_no_repeat'     => $cfg['ai_no_repeat'],
		'ai_language'      => $cfg['ai_language'],
		'ai_extra_context' => $cfg['ai_extra_context'],
	]);
	break;

// ─────────────────────────────────────────────────────────────
case 'test_ai':
	try {
		require_once __DIR__ . '/ai.php';
		require_once __DIR__ . '/notify.php';
		$result = WakeAI::test($pdo);
		if ($result['ok']) {
			// Enviar a Telegram si está configurado
			$tgToken  = getSetting($pdo, 'telegram_token',   '');
			$tgChatId = getSetting($pdo, 'telegram_chat_id', '');
			$tgSent   = false;
			if ($tgToken !== '' && $tgChatId !== '') {
				try {
					WakeNotify::sendTelegram($tgToken, $tgChatId, $result['message'], true);
					$tgSent = true;
				} catch (Throwable $e2) {
					error_log('[WakeLab] test_ai telegram error: ' . $e2->getMessage());
				}
			}
			$result['tg_sent'] = $tgSent;
			echo ok($result, 'Mensaje generado');
		} else {
			echo err($result['error'] ?? 'Unknown error');
		}
	} catch (Throwable $e) { echo err('error: ' . $e->getMessage()); }
	break;

case 'debug_push':
	require_once __DIR__ . '/push.php';
	$vapidPub  = getSetting($pdo, 'vapid_public',  '');
	$vapidPriv = getSetting($pdo, 'vapid_private', '');
	try { $subs = $pdo->query("SELECT id, endpoint, created_at FROM push_subscriptions")->fetchAll(PDO::FETCH_ASSOC); }
	catch (Throwable $e) { $subs = ['error' => $e->getMessage()]; }

	$sendResult = null;
	if (!empty($subs) && is_array($subs) && $vapidPub && $vapidPriv) {
		$firstSub = $pdo->query("SELECT * FROM push_subscriptions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
		try {
			$code = WakePush::send(
				$firstSub,
				['title'=>'🔔 WakeLab Debug','body'=>'Test directo','tag'=>'debug'],
				$vapidPub, $vapidPriv,
				getSetting($pdo,'vapid_subject','mailto:admin@localhost')
			);
			$sendResult = ['http_code' => $code, 'ok' => ($code >= 200 && $code < 300)];
		} catch (Throwable $e) {
			$sendResult = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
		}
	}

	echo ok([
		'php_version'       => PHP_VERSION,
		'openssl_version'   => OPENSSL_VERSION_TEXT,
		'vapid_public_set'  => (bool)$vapidPub,
		'vapid_private_set' => (bool)$vapidPriv,
		'vapid_public_key'  => $vapidPub ? substr($vapidPub, 0, 20) . '…' : null,
		'subscription_count'=> count(is_array($subs) ? $subs : []),
		'subscriptions'     => $subs,
		'send_result'       => $sendResult,
	]);
	break;

// ─────────────────────────────────────────────────────────────
case 'invalidate_cache':
	invalidateCache();
	echo ok(null, 'Cache invalidated');
	break;

// ─────────────────────────────────────────────────────────────
case 'update_guest_meta':
	$pdo->prepare("INSERT INTO guest_meta (server_id, vmid, url)
				   VALUES (?,?,?)
				   ON DUPLICATE KEY UPDATE url=VALUES(url)")
		->execute([intval($data['server_id']), intval($data['vmid']), trim($data['url'] ?? '')]);
	echo ok(null, 'Meta saved');
	break;

// ─────────────────────────────────────────────────────────────
case 'get_guest_meta':
	try {
		$s = $pdo->prepare("SELECT * FROM guest_meta WHERE server_id=? AND vmid=?");
		$s->execute([intval($data['server_id']), intval($data['vmid'])]);
		echo ok($s->fetch() ?: null);
	} catch (Throwable $e) { echo ok(null); }
	break;

// ─────────────────────────────────────────────────────────────
// DEBUG — idle_active: simula la lógica del script idle-shutdown.sh
//
// USO:
//   GET  php/api.php?action=debug_idle_active&server_id=<ID>
//
// Evalúa paso a paso qué devolvería idle_active para ese servidor
// y explica el motivo de cada decisión.
// ─────────────────────────────────────────────────────────────
case 'debug_idle_active':
	$srvId = intval($_GET['server_id'] ?? $data['server_id'] ?? 0);
	if (!$srvId) { echo err('Missing server_id'); break; }

	$steps  = [];
	$result = '0';

	// 1. Buscar idle_config + hostname
	$r = $pdo->prepare(
		"SELECT ic.active, ic.idle_limit_sec, s.hostname
		 FROM idle_config ic JOIN servers s ON s.id=ic.server_id
		 WHERE ic.server_id=? LIMIT 1"
	);
	$r->execute([$srvId]);
	$row = $r->fetch();

	if (!$row) {
		$steps[] = ['paso' => 1, 'ok' => false, 'msg' => 'No existe idle_config para este servidor'];
		echo ok(['resultado' => '0', 'pasos' => $steps]);
		break;
	}
	$steps[] = ['paso' => 1, 'ok' => true, 'msg' => "idle_config encontrada — hostname: {$row['hostname']}, idle_limit_sec: {$row['idle_limit_sec']}"];

	// 2. idle activo?
	if (intval($row['active']) !== 1) {
		$steps[] = ['paso' => 2, 'ok' => false, 'msg' => 'idle_config.active = 0 → devuelve 0 (idle desactivado)'];
		echo ok(['resultado' => '0', 'pasos' => $steps]);
		break;
	}
	$steps[] = ['paso' => 2, 'ok' => true, 'msg' => 'idle_config.active = 1 → continúa'];

	// 3. ¿hubo hit reciente en wake_proxies?
	$hitR = $pdo->prepare(
		"SELECT last_proxy_hit FROM wake_proxies
		 WHERE server_id=? AND active=1 AND last_proxy_hit IS NOT NULL
		 ORDER BY last_proxy_hit DESC LIMIT 1"
	);
	$hitR->execute([$srvId]);
	$hitRow = $hitR->fetch();

	if ($hitRow) {
		$elapsed = time() - strtotime($hitRow['last_proxy_hit']);
		$limit   = (int)$row['idle_limit_sec'];
		if ($elapsed < $limit) {
			$steps[] = ['paso' => 3, 'ok' => false,
				'msg' => "Wake Proxy hit hace {$elapsed}s, idle_limit={$limit}s → aún en ventana post-boot, devuelve 0 (no apagar)"];
			echo ok(['resultado' => '0', 'pasos' => $steps]);
			break;
		}
		$steps[] = ['paso' => 3, 'ok' => true,
			'msg' => "Wake Proxy hit hace {$elapsed}s, idle_limit={$limit}s → fuera de ventana post-boot, continúa"];
	} else {
		$steps[] = ['paso' => 3, 'ok' => true, 'msg' => 'Sin hits en wake_proxies → continúa'];
	}

	// 4. Resultado final
	$result = '1';
	$steps[] = ['paso' => 4, 'ok' => true, 'msg' => 'idle_active devolvería 1 → el script ejecutará detección de idle'];

	echo ok(['resultado' => $result, 'hostname' => $row['hostname'], 'pasos' => $steps]);
	break;

// ─────────────────────────────────────────────────────────────
// DEBUG — TrueNAS WebSocket
//
// USO:
//   GET  php/api.php?action=debug_truenas_ws&server_id=<ID>
//   POST php/api.php  body: {"action":"debug_truenas_ws","server_id":<ID>}
//
// Qué hace: ejecuta 4 pasos en orden y muestra el resultado de cada uno:
//   1. SSL socket     → abre ssl://IP:port, confirma que acepta TLS
//   2. WS upgrade     → envía HTTP Upgrade: websocket, espera 101
//   3. auth           → auth.login_with_api_key con el token guardado
//                       si result != true, el token es incorrecto
//   4. subscribe      → core.subscribe reporting.realtime,
//                       captura hasta 5 mensajes raw para inspección
//
// Cuándo usar:
//   - reboot/shutdown vía API falla → ver en qué paso se rompe
//   - Paso 1 falla  → TrueNAS no escucha en ese puerto/IP
//   - Paso 2 falla  → el endpoint WebSocket no es /api/current
//   - Paso 3 falla  → API key inválida o expirada
//   - Paso 4 vacío  → autenticó pero reporting.realtime no devuelve datos
// ─────────────────────────────────────────────────────────────
case 'debug_truenas_ws':
	$srvId = intval($_GET['server_id'] ?? $data['server_id'] ?? 0);
	if (!$srvId) { echo err('Missing server_id'); break; }

	$srvR = $pdo->prepare("SELECT * FROM servers WHERE id=?"); $srvR->execute([$srvId]);
	$srv  = $srvR->fetch();
	$tok  = getToken($pdo, $srvId);

	if (!$srv) { echo err('Server not found'); break; }
	if (!$tok || empty($tok['token_secret'])) { echo err('Token not configured'); break; }

	$host   = $srv['ip'];
	$port   = intval($srv['port'] ?? 443);
	$apiKey = $tok['token_secret'];

	$steps = [];

	// Step 1: SSL socket
	$ctx = stream_context_create(['ssl' => [
		'verify_peer'      => false,
		'verify_peer_name' => false,
	]]);
	$sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
	$steps[] = ['step' => "SSL socket ssl://{$host}:{$port}", 'ok' => (bool)$sock,
				'detail' => $sock ? 'conectado' : "error $errno: $errstr"];
	if (!$sock) { echo ok($steps); break; }
	stream_set_timeout($sock, 8);

	// Step 2: WS upgrade
	$wsKey = base64_encode(random_bytes(16));
	fwrite($sock, "GET /api/current HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$wsKey}\r\nSec-WebSocket-Version: 13\r\n\r\n");
	$buf = '';
	while (!feof($sock)) {
		$c = @fread($sock, 1);
		if ($c === false || $c === '') break;
		$buf .= $c;
		if (str_ends_with($buf, "\r\n\r\n")) break;
	}
	$upgraded = str_contains($buf, ' 101 ');
	$steps[] = ['step' => 'WebSocket upgrade', 'ok' => $upgraded, 'detail' => trim(strtok($buf, "\r\n"))];
	if (!$upgraded) { @fclose($sock); echo ok($steps); break; }

	// Helper: send WS frame
	$wsSend = function(string $payload) use ($sock): void {
		$len = strlen($payload);
		$mask = random_bytes(4);
		$masked = '';
		for ($i = 0; $i < $len; $i++) $masked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
		$hdr = chr(0x81);
		if ($len < 126)        $hdr .= chr(0x80 | $len);
		elseif ($len < 65536)  $hdr .= chr(0xFE) . pack('n', $len);
		else                   $hdr .= chr(0xFF) . pack('N', $len);
		fwrite($sock, $hdr . $mask . $masked);
	};

	// Helper: recv WS frame
	$wsRecv = function() use ($sock): ?string {
		$h = @fread($sock, 2); if (!$h || strlen($h) < 2) return null;
		$opcode = ord($h[0]) & 0x0F;
		if ($opcode === 8) { @fclose($sock); return null; }
		if ($opcode === 9) { fwrite($sock, chr(0x8A) . chr(0x00)); return ''; }
		$len = ord($h[1]) & 0x7F;
		if ($len === 126) { $e = @fread($sock, 2); $len = unpack('n', $e)[1]; }
		elseif ($len === 127) { $e = @fread($sock, 8); $len = unpack('J', $e)[1]; }
		$data = ''; $rem = $len;
		while ($rem > 0) { $chunk = @fread($sock, min($rem, 4096)); if (!$chunk) break; $data .= $chunk; $rem -= strlen($chunk); }
		return $data;
	};

	// Step 3: auth
	$id = 1;
	$wsSend(json_encode(['jsonrpc'=>'2.0','method'=>'auth.login_with_api_key','params'=>[$apiKey],'id'=>$id]));
	$raw = '';
	$authOk = false;
	$deadline = time() + 6;
	while (time() < $deadline) {
		$f = $wsRecv();
		if ($f === null) break;
		if ($f === '') continue;
		$raw = $f;
		$msg = json_decode($f, true);
		if (isset($msg['id']) && $msg['id'] === $id) { $authOk = ($msg['result'] === true); break; }
	}
	$steps[] = ['step' => 'auth.login_with_api_key', 'ok' => $authOk, 'raw' => $raw];
	if (!$authOk) { @fclose($sock); echo ok($steps); break; }

	// Step 4: subscribe reporting.realtime — capturar raw
	$id = 2;
	$wsSend(json_encode(['jsonrpc'=>'2.0','method'=>'core.subscribe','params'=>['reporting.realtime'],'id'=>$id]));
	$msgs = [];
	$deadline = time() + 8;
	while (time() < $deadline && count($msgs) < 5) {
		$f = $wsRecv();
		if ($f === null) break;
		if ($f === '') continue;
		$msgs[] = json_decode($f, true) ?? $f;
	}
	$steps[] = ['step' => 'core.subscribe reporting.realtime', 'ok' => !empty($msgs),
				'messages_received' => count($msgs), 'raw' => $msgs];

	@fclose($sock);
	echo ok($steps);
	break;

// ─────────────────────────────────────────────────────────────
// DEBUG — PBS: prueba raw de endpoints clave
//
// USO:
//   GET  php/api.php?action=debug_pbs&server_id=<ID>
//   POST php/api.php  body: {"action":"debug_pbs","server_id":<ID>}
//
// Qué hace: llama /version (sin auth), /nodes/localhost/status,
//   /admin/datastore y /nodes/localhost/tasks con las credenciales
//   guardadas en DB. Devuelve HTTP code + body de cada endpoint.
//
// Cuándo usar: si test_connection pasa pero el dashboard no muestra
//   datos, o si sospechás que un endpoint específico falla.
// ─────────────────────────────────────────────────────────────
case 'debug_pbs':
	$srvId = intval($_GET['server_id'] ?? $data['server_id'] ?? 0);
	if (!$srvId) { echo err('Missing server_id'); break; }

	$srvR = $pdo->prepare("SELECT * FROM servers WHERE id=?"); $srvR->execute([$srvId]);
	$srv  = $srvR->fetch();
	$tok  = getToken($pdo, $srvId);
	if (!$srv) { echo err('Server not found'); break; }

	$host   = $srv['ip'];
	$port   = intval($srv['port'] ?? 8007);
	$user   = trim($tok['api_user']    ?? '');
	$tokId  = trim($tok['token_id']    ?? '');
	$secret = trim($tok['token_secret'] ?? '');
	// Formato correcto PBS: PBSAPIToken=user@realm!tokenId:secret  (separador : no =)
	$authHdr        = "Authorization: PBSAPIToken={$user}!{$tokId}:{$secret}";
	$authHdrDisplay = "Authorization: PBSAPIToken={$user}!{$tokId}:[REDACTED]";

	$endpoints = [
		['url' => "https://{$host}:{$port}/api2/json/version",                    'auth' => false],
		['url' => "https://{$host}:{$port}/api2/json/nodes/localhost/status",      'auth' => true],
		['url' => "https://{$host}:{$port}/api2/json/admin/datastore",             'auth' => true],
		['url' => "https://{$host}:{$port}/api2/json/nodes/localhost/tasks?limit=5",'auth' => true],
	];

	$results = [];
	foreach ($endpoints as $ep) {
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $ep['url'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT        => 5,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_HTTPHEADER     => $ep['auth'] ? [$authHdr] : [],
		]);
		$body     = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_error($ch);
		curl_close($ch);
		$results[] = [
			'endpoint' => $ep['url'],
			'auth'     => $ep['auth'],
			'http'     => $httpCode,
			'curl_err' => $curlErr ?: null,
			'body'     => $body ?: null,
		];
	}

	echo ok(['auth_header' => $authHdrDisplay, 'endpoints' => $results]);
	break;

// ─────────────────────────────────────────────────────────────
// Schedule e idle de un servidor (usado por el VM drawer cuando el guest
// es un host registrado, para mostrar su configuración sin duplicar datos)
// ─────────────────────────────────────────────────────────────
case 'get_server_schedule':
	$srvId = intval($data['server_id'] ?? 0);
	if (!$srvId) { echo err('server_id required'); break; }

	$schS = $pdo->prepare("SELECT boot_time, shutdown_time, active, method FROM schedules WHERE server_id=?");
	$schS->execute([$srvId]);
	$schRow = $schS->fetch() ?: null;

	$idlS = $pdo->prepare("SELECT idle_limit_sec, check_interval_sec, active FROM idle_config WHERE server_id=?");
	$idlS->execute([$srvId]);
	$idlRow = $idlS->fetch() ?: null;

	echo ok(['schedule' => $schRow, 'idle' => $idlRow]);
	break;

// ─────────────────────────────────────────────────────────────
// WAKE PROXY — CRUD
// ─────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────
// SSH KEY — get public key / authorize on server
// ─────────────────────────────────────────────────────────────
case 'get_ssh_pubkey':
	$pub = @file_get_contents('/var/www/.ssh/id_ed25519.pub');
	if ($pub) {
		echo ok(['pubkey' => trim($pub)]);
	} else {
		echo err('Key not found. Check /var/www/.ssh/id_ed25519.pub');
	}
	break;

case 'authorize_ssh_key':
	$srvId   = intval($data['server_id'] ?? 0);
	$onePass = trim($data['one_time_pass'] ?? '');
	$sshUser = trim($data['ssh_user'] ?? 'root');
	$sshPort = intval($data['ssh_port'] ?? 22) ?: 22;

	if (!$srvId) { echo err('server_id required'); break; }

	$srvR = $pdo->prepare("SELECT * FROM servers WHERE id=?");
	$srvR->execute([$srvId]);
	$srvRow = $srvR->fetch();
	if (!$srvRow) { echo err('Server not found'); break; }
	$srvIp   = $srvRow['ip'];
	$srvType = $srvRow['hypervisor_type'] ?? '';

	$pub = @file_get_contents('/var/www/.ssh/id_ed25519.pub');
	if (!$pub) { echo err('Public key not found in the container'); break; }
	$pub = trim($pub);

	// ── TrueNAS: usar API REST en lugar de SSH con contraseña ──────────────
	if ($srvType === 'truenas') {
		$tok = getToken($pdo, $srvId);
		$apiKey = $tok['token_secret'] ?? '';
		if (!$apiKey) { echo err('API Key de TrueNAS no configurada'); break; }
		$apiPort = intval($srvRow['port'] ?? 443);
		$baseUrl = "https://{$srvIp}:{$apiPort}/api/v2.0";

		// Obtener todos los usuarios y buscar root en PHP
		$ch = curl_init("{$baseUrl}/user");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$apiKey}"],
		]);
		$resp     = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_error($ch);
		curl_close($ch);
		$allUsers = json_decode((string)$resp, true);
		if (!is_array($allUsers)) {
			$detail = $curlErr ?: "HTTP {$httpCode}: " . substr((string)$resp, 0, 200);
			echo err("Error connecting to TrueNAS API (check your API Key): {$detail}");
			break;
		}
		// Buscar root; si no existe buscar uid=0
		$rootUser = null;
		foreach ($allUsers as $u) {
			if (($u['username'] ?? '') === 'root' || ($u['uid'] ?? -1) === 0) {
				$rootUser = $u;
				break;
			}
		}
		if (!$rootUser) {
			$names = implode(', ', array_column($allUsers, 'username'));
			echo err("Root user not found in TrueNAS. Available users: {$names}");
			break;
		}
		$userId      = $rootUser['id'];
		$existingKey = trim($rootUser['sshpubkey'] ?? '');

		// Añadir clave si no está ya
		if (str_contains($existingKey, $pub)) {
			$newKey = $existingKey;
		} else {
			$newKey = $existingKey ? $existingKey . "\n" . $pub : $pub;
		}

		$ch = curl_init("{$baseUrl}/user/id/{$userId}");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_CUSTOMREQUEST  => 'PUT',
			CURLOPT_POSTFIELDS     => json_encode(['sshpubkey' => $newKey]),
			CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$apiKey}", 'Content-Type: application/json'],
		]);
		$putResp = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode >= 200 && $httpCode < 300) {
			$ins = $pdo->prepare("INSERT INTO settings (`key`,`value`,`description`) VALUES (?,?,'') ON DUPLICATE KEY UPDATE `value`=?");
			$ins->execute(["srv_{$srvId}_ssh_key_ok",  '1',    '1']);
			$ins->execute(["srv_{$srvId}_ssh_user", wlEncrypt('root'),  wlEncrypt('root')]);
			$ins->execute(["srv_{$srvId}_ssh_port", wlEncrypt('22'),    wlEncrypt('22')]);
			logEv($pdo, $srvId, 'ok', "Clave SSH autorizada en TrueNAS {$srvIp} via API");
			echo ok([], 'Clave autorizada en ' . $srvIp . ' via API');
		} else {
			$detail = substr((string)$putResp, 0, 200);
			logEv($pdo, $srvId, 'err', "Error al autorizar clave SSH en TrueNAS {$srvIp} via API (HTTP {$httpCode}): {$detail}");
			echo err("Error API TrueNAS (HTTP {$httpCode}): {$detail}");
		}
		break;
	}

	// ── Otros servidores: SSH con contraseña o clave ────────────────────────
	$authCmd = sprintf(
		'mkdir -p ~/.ssh && chmod 700 ~/.ssh && ' .
		'grep -qxF %s ~/.ssh/authorized_keys 2>/dev/null || echo %s >> ~/.ssh/authorized_keys && ' .
		'chmod 600 ~/.ssh/authorized_keys && echo AUTHORIZED',
		escapeshellarg($pub), escapeshellarg($pub)
	);

	$sshOpts = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 -p ' . $sshPort;
	if ($onePass) {
		$cmd = sprintf('sshpass -p %s ssh %s %s@%s %s 2>&1',
			escapeshellarg($onePass), $sshOpts,
			escapeshellarg($sshUser), escapeshellarg($srvIp),
			escapeshellarg($authCmd));
	} else {
		$cmd = sprintf('ssh %s -i /var/www/.ssh/id_ed25519 %s@%s %s 2>&1',
			$sshOpts, escapeshellarg($sshUser), escapeshellarg($srvIp),
			escapeshellarg($authCmd));
	}
	$out = shell_exec($cmd) ?? '';
	if (str_contains($out, 'AUTHORIZED')) {
		$ins = $pdo->prepare("INSERT INTO settings (`key`,`value`,`description`) VALUES (?,?,'') ON DUPLICATE KEY UPDATE `value`=?");
		$ins->execute(["srv_{$srvId}_ssh_key_ok",  '1',                    '1']);
		$ins->execute(["srv_{$srvId}_ssh_user", wlEncrypt($sshUser),  wlEncrypt($sshUser)]);
		$ins->execute(["srv_{$srvId}_ssh_port", wlEncrypt((string)$sshPort), wlEncrypt((string)$sshPort)]);
		logEv($pdo, $srvId, 'ok', "Clave SSH autorizada en {$srvIp} (usuario {$sshUser})");
		echo ok(['output' => trim($out)], 'Clave autorizada en ' . $srvIp);
	} else {
		logEv($pdo, $srvId, 'err', "Error al autorizar clave SSH en {$srvIp}: " . substr(trim($out), 0, 200));
		echo err('Error: ' . trim($out));
	}
	break;

case 'get_wake_proxies':
	$rows = $pdo->query(
		"SELECT wp.*, s.hostname AS srv_hostname
		 FROM wake_proxies wp
		 JOIN servers s ON s.id = wp.server_id
		 ORDER BY wp.name"
	)->fetchAll();
	echo ok($rows);
	break;

// ─────────────────────────────────────────────────────────────
case 'add_wake_proxy':
	$name    = trim($data['name']    ?? '');
	$domain  = strtolower(trim($data['domain'] ?? ''));
	$srvId   = intval($data['server_id']  ?? 0);
	$destIp  = trim($data['dest_ip']  ?? '');
	$destPort= intval($data['dest_port'] ?? 0);
	if (!$name || !$domain || !$srvId || !$destIp || !$destPort) {
		echo err('Required fields: name, domain, server_id, dest_ip, dest_port'); break;
	}
	$pdo->prepare(
		"INSERT INTO wake_proxies
		 (name,domain,server_id,guest_vmid,guest_vmtype,docker_container,
		  dest_ip,dest_port,dest_protocol,boot_timeout_sec,active)
		 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
	)->execute([
		$name, $domain, $srvId,
		($data['guest_vmid']        ?? null) ?: null,
		($data['guest_vmtype']      ?? null) ?: null,
		($data['docker_container']  ?? null) ?: null,
		$destIp, $destPort,
		in_array($data['dest_protocol']??'', ['http','https']) ? $data['dest_protocol'] : 'http',
		max(60, intval($data['boot_timeout_sec'] ?? 240)),
		isset($data['active']) ? intval($data['active']) : 1,
	]);
	$newId = (int)$pdo->lastInsertId();
	logEv($pdo, $srvId, 'info', "Wake Proxy creado: '$name' → $domain");
	echo ok(['id' => $newId], 'Proxy creado');
	break;

// ─────────────────────────────────────────────────────────────
case 'update_wake_proxy':
	$id      = intval($data['id']        ?? 0);
	$name    = trim($data['name']        ?? '');
	$domain  = strtolower(trim($data['domain'] ?? ''));
	$srvId   = intval($data['server_id'] ?? 0);
	$destIp  = trim($data['dest_ip']     ?? '');
	$destPort= intval($data['dest_port'] ?? 0);
	if (!$id || !$name || !$domain || !$srvId || !$destIp || !$destPort) {
		echo err('Required fields: id, name, domain, server_id, dest_ip, dest_port'); break;
	}
	$pdo->prepare(
		"UPDATE wake_proxies SET
		 name=?,domain=?,server_id=?,guest_vmid=?,guest_vmtype=?,docker_container=?,
		 dest_ip=?,dest_port=?,dest_protocol=?,boot_timeout_sec=?,active=?
		 WHERE id=?"
	)->execute([
		$name, $domain, $srvId,
		($data['guest_vmid']        ?? null) ?: null,
		($data['guest_vmtype']      ?? null) ?: null,
		($data['docker_container']  ?? null) ?: null,
		$destIp, $destPort,
		in_array($data['dest_protocol']??'', ['http','https']) ? $data['dest_protocol'] : 'http',
		max(60, intval($data['boot_timeout_sec'] ?? 240)),
		isset($data['active']) ? intval($data['active']) : 1,
		$id,
	]);
	logEv($pdo, $srvId, 'info', "Wake Proxy actualizado: '$name' → $domain");
	echo ok(null, 'Proxy actualizado');
	break;

// ─────────────────────────────────────────────────────────────
case 'delete_wake_proxy':
	$id = intval($data['id'] ?? 0);
	if (!$id) { echo err('Invalid ID'); break; }
	$row = $pdo->prepare("SELECT name FROM wake_proxies WHERE id=?");
	$row->execute([$id]);
	$proxy = $row->fetch();
	$pdo->prepare("DELETE FROM wake_proxies WHERE id=?")->execute([$id]);
	logEv($pdo, null, 'warn', "Wake Proxy eliminado: '" . ($proxy['name'] ?? $id) . "'");
	echo ok(null, 'Proxy eliminado');
	break;

// ─────────────────────────────────────────────────────────────
// WAKE PROXY — STATUS (splash page polling)
// ─────────────────────────────────────────────────────────────
case 'wake_proxy_status':
	$id = intval($_GET['id'] ?? $data['id'] ?? 0);
	if (!$id) { echo err('id required'); break; }

	$stmt = $pdo->prepare(
		"SELECT wp.*, s.ip AS srv_ip, s.port AS srv_port, s.mac AS srv_mac,
		        s.hypervisor_type AS srv_type
		 FROM wake_proxies wp
		 JOIN servers s ON s.id = wp.server_id
		 WHERE wp.id = ? LIMIT 1"
	);
	$stmt->execute([$id]);
	$proxy = $stmt->fetch();
	if (!$proxy) { echo err('proxy not found'); break; }

	$lockS = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
	$lockS->execute(["wp_boot_{$id}"]);
	$lockTime = (int)($lockS->fetchColumn() ?: 0);
	$elapsed  = $lockTime > 0 ? (time() - $lockTime) : 0;

	// 1. Service up?
	$sock = @fsockopen($proxy['dest_ip'], (int)$proxy['dest_port'], $errno, $errstr, 2);
	if ($sock) {
		fclose($sock);
		$pdo->prepare("UPDATE wake_proxies SET last_proxy_hit=NOW() WHERE id=?")->execute([$id]);
		$pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute(["wp_boot_{$id}"]);
		echo ok([
			'status'  => 'online',
			'phase'   => 'done',
			'elapsed' => $elapsed,
			'url'     => $proxy['dest_protocol'].'://'.$proxy['dest_ip'].':'.$proxy['dest_port'],
		]);
		break;
	}

	// 2. Determine phase
	$hSock = @fsockopen($proxy['srv_ip'], (int)$proxy['srv_port'], $e, $es, 2);
	$hostUp = (bool)$hSock;
	if ($hSock) fclose($hSock);

	// Solo ejecutar acciones de boot si hay una sesión de boot activa (lock presente)
	// El polling del dashboard NO debe disparar starts — solo el acceso real al dominio lo hace.
	$bootActive = ($lockTime > 0 && $elapsed < ($proxy['boot_timeout'] ?? 300));

	$phase = 'wol_sent';
	if ($hostUp) {
		$phase = 'host_online';
		// Try to start guest if needed — SOLO durante boot activo
		if ($bootActive && !empty($proxy['guest_vmid'])) {
			$tokS = $pdo->prepare("SELECT * FROM api_tokens WHERE server_id=?");
			$tokS->execute([(int)$proxy['server_id']]);
			$tok = $tokS->fetch() ?: null;
			if ($tok) {
				$srv = ['id'=>$proxy['server_id'],'ip'=>$proxy['srv_ip'],
				        'port'=>$proxy['srv_port'],'hypervisor_type'=>$proxy['srv_type']];
				try {
					$client = HypFactory::make($srv, $tok);
					if ($client instanceof PVEClient) {
						$gStatus = $client->getGuestStatus((int)$proxy['guest_vmid']);
						if ($gStatus === 'running') {
							$phase = 'guest_online';
						} else {
							$client->startGuest((int)$proxy['guest_vmid'], $proxy['guest_vmtype'] ?? 'qemu');
						}
					}
				} catch (Throwable $e) {}
			}
		} elseif (!$bootActive && !empty($proxy['guest_vmid'])) {
			// Sin boot activo: solo chequeamos si el guest está corriendo
			$tokS = $pdo->prepare("SELECT * FROM api_tokens WHERE server_id=?");
			$tokS->execute([(int)$proxy['server_id']]);
			$tok = $tokS->fetch() ?: null;
			if ($tok) {
				$srv = ['id'=>$proxy['server_id'],'ip'=>$proxy['srv_ip'],
				        'port'=>$proxy['srv_port'],'hypervisor_type'=>$proxy['srv_type']];
				try {
					$client = HypFactory::make($srv, $tok);
					if ($client instanceof PVEClient) {
						$gStatus = $client->getGuestStatus((int)$proxy['guest_vmid']);
						if ($gStatus === 'running') $phase = 'guest_online';
					}
				} catch (Throwable $e) {}
			}
		}

		// Docker container start — SOLO durante boot activo
		if ($bootActive && !empty($proxy['docker_container'])) {
			$guestReady = empty($proxy['guest_vmid']) || $phase === 'guest_online' || $hostUp;
			if ($guestReady) {
				$dLockS = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
				$dLockS->execute(["wp_docker_{$id}"]);
				$lastTry = (int)($dLockS->fetchColumn() ?: 0);
				if (time() - $lastTry >= 30) {
					$pdo->prepare("INSERT INTO settings (`key`,`value`,`description`) VALUES (?,?,'') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
						->execute(["wp_docker_{$id}", (string)time()]);
					$srvId    = (int)$proxy['server_id'];
					$srvIp    = $proxy['srv_ip'];
					$vmid     = !empty($proxy['guest_vmid']) ? (int)$proxy['guest_vmid'] : 0;
					$container = $proxy['docker_container'];
					$cStmt = $pdo->prepare(
						"SELECT `key`, value FROM settings
						 WHERE `key` IN (
						     'srv_{$srvId}_ssh_user',
						     'srv_{$srvId}_ssh_pass',
						     'srv_{$srvId}_ssh_port'
						 )"
					);
					$cStmt->execute();
					$creds   = array_map('wlDecrypt', array_column($cStmt->fetchAll(), 'value', 'key'));
					$sshUser = $creds["srv_{$srvId}_ssh_user"] ?? 'root';
					$sshPass = $creds["srv_{$srvId}_ssh_pass"] ?? '';
					$sshPort = intval($creds["srv_{$srvId}_ssh_port"] ?? 22) ?: 22;
					$dockerCmd = $vmid
						? 'pct exec ' . $vmid . ' -- docker start ' . escapeshellarg($container)
						: 'docker start ' . escapeshellarg($container);
					$sshOpts = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 -p ' . $sshPort;
					$keyOpt  = '-i /var/www/.ssh/id_ed25519';
					if ($sshPass) {
						$sshCmd = sprintf('sshpass -p %s ssh %s %s %s@%s %s 2>&1',
							escapeshellarg($sshPass), $sshOpts, $keyOpt,
							escapeshellarg($sshUser), escapeshellarg($srvIp),
							escapeshellarg($dockerCmd));
					} else {
						$sshCmd = sprintf('ssh %s %s %s@%s %s 2>&1',
							$sshOpts, $keyOpt,
							escapeshellarg($sshUser), escapeshellarg($srvIp),
							escapeshellarg($dockerCmd));
					}
					shell_exec($sshCmd);
				}
				$phase = 'docker_online';
			}
		}
	}

	echo ok([
		'status'   => 'booting',
		'phase'    => $phase,
		'elapsed'  => $elapsed,
		'timeout'  => (int)$proxy['boot_timeout_sec'],
	]);
	break;

// ─────────────────────────────────────────────────────────────
// WAKE PROXY — timeout notification (#22)
// ─────────────────────────────────────────────────────────────
case 'wake_timeout_event':
	$proxyName = trim($data['proxy_name'] ?? '');
	$srvId     = intval($data['server_id'] ?? 0);
	$elapsed   = intval($data['elapsed']   ?? 0);
	$msg       = "Wake Proxy: '{$proxyName}' no respondió tras {$elapsed}s de espera";
	logEvent($pdo, $srvId ?: null, 'warn', $msg);
	// Notificar por todos los canales configurados
	if (file_exists(__DIR__ . '/notify.php')) {
		require_once __DIR__ . '/notify.php';
		WakeNotify::notifyAll($pdo, 'wake_timeout', [
			'title' => 'Host did not come up',
			'body'  => $msg,
		]);
	}
	echo ok(['logged' => true]);
	break;

// ─────────────────────────────────────────────────────────────
// WAKE PROXY — retry (reset lock + re-trigger wake signal)
// ─────────────────────────────────────────────────────────────
case 'wake_proxy_retry':
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo err('POST required'); break; }
	$id = intval($data['id'] ?? 0);
	if (!$id) { echo err('id required'); break; }
	$stmt = $pdo->prepare(
		"SELECT wp.*, s.ip AS srv_ip, s.port AS srv_port, s.mac AS srv_mac,
		        s.hypervisor_type AS srv_type
		 FROM wake_proxies wp
		 JOIN servers s ON s.id = wp.server_id
		 WHERE wp.id = ? AND wp.active = 1 LIMIT 1"
	);
	$stmt->execute([$id]);
	$proxy = $stmt->fetch();
	if (!$proxy) { echo err('proxy not found'); break; }

	// Reset lock
	$pdo->prepare("INSERT INTO settings (`key`,`value`,`description`) VALUES (?,?,'') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
		->execute(["wp_boot_{$id}", (string)time()]);
	// Remove docker lock so start is re-attempted
	$pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute(["wp_docker_{$id}"]);

	// Check if host is already up
	$hostUp = (bool)@fsockopen($proxy['srv_ip'], (int)$proxy['srv_port'], $e, $es, 2);

	if (!$hostUp) {
		// Send WoL
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
		$pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (?,?,?,?)")
		    ->execute([(int)$proxy['server_id'], 'info',
		              "Wake Proxy: reintento WoL para '{$proxy['name']}'", gmdate('Y-m-d H:i:s')]);
	} elseif (!empty($proxy['guest_vmid'])) {
		$tokS = $pdo->prepare("SELECT * FROM api_tokens WHERE server_id=?");
		$tokS->execute([(int)$proxy['server_id']]);
		$tok = $tokS->fetch() ?: null;
		if ($tok) {
			$srv = ['id'=>$proxy['server_id'],'ip'=>$proxy['srv_ip'],
			        'port'=>$proxy['srv_port'],'hypervisor_type'=>$proxy['srv_type']];
			try {
				$client = HypFactory::make($srv, $tok);
				if ($client instanceof PVEClient) {
					$client->startGuest((int)$proxy['guest_vmid'], $proxy['guest_vmtype'] ?? 'qemu');
				}
			} catch (Throwable $e) {}
		}
	}
	echo ok(['retried' => true, 'host_was_up' => $hostUp]);
	break;

// ─────────────────────────────────────────────────────────────
// WAKE PROXY — get guests for a PVE server (form dropdown)
// ─────────────────────────────────────────────────────────────
case 'get_pve_guests':
	$srvId = intval($_GET['server_id'] ?? $data['server_id'] ?? 0);
	if (!$srvId) { echo err('server_id required'); break; }
	$srvS = $pdo->prepare("SELECT * FROM servers WHERE id=? AND hypervisor_type='pve' LIMIT 1");
	$srvS->execute([$srvId]);
	$srv = $srvS->fetch();
	if (!$srv) { echo err('PVE server not found'); break; }
	$tok = getToken($pdo, $srvId);
	if (!$tok) { echo ok([], 'sin token configurado'); break; }
	try {
		$client = HypFactory::make($srv, $tok);
		$guests = $client->getGuests();
		$out = array_map(fn($g) => [
			'vmid'   => $g['vmid'],
			'name'   => $g['name'] ?? "VM {$g['vmid']}",
			'type'   => $g['type'] ?? 'vm',
			'status' => $g['status'] ?? 'unknown',
			'ip'     => $g['ip'] ?? null,
		], $guests);
		echo ok($out);
	} catch (Throwable $e) {
		echo err('Error fetching guests: ' . $e->getMessage());
	}
	break;

// ─────────────────────────────────────────────────────────────
case 'export_config': {
	try {
	// Claves de settings que son runtime/volátiles — no exportar
	$skipPrefixes = ['pa_', 'gu_', 'wp_boot_', 'wp_docker_', 'wl_cache'];
	$skipExact    = ['wakelab_version'];

	$servers    = $pdo->query("SELECT * FROM servers ORDER BY id")->fetchAll();
	$schedules  = $pdo->query("SELECT * FROM schedules ORDER BY server_id")->fetchAll();
	$idleConfig = $pdo->query("SELECT * FROM idle_config ORDER BY server_id")->fetchAll();
	$wakeProxy  = $pdo->query("SELECT * FROM wake_proxies ORDER BY id")->fetchAll();
	$guestMeta  = $pdo->query("SELECT * FROM guest_meta ORDER BY server_id, vmid")->fetchAll();
	$apiTokens  = $pdo->query("SELECT * FROM api_tokens ORDER BY server_id")->fetchAll();

	// Settings: excluir claves volátiles
	$allSettings = $pdo->query("SELECT `key`,`value` FROM settings")->fetchAll();
	$settings = [];
	foreach ($allSettings as $s) {
		$k = $s['key'];
		if (in_array($k, $skipExact)) continue;
		$skip = false;
		foreach ($skipPrefixes as $p) { if (str_starts_with($k, $p)) { $skip = true; break; } }
		if (!$skip) $settings[] = $s;
	}

	// Descifrar todos los secretos para que sean portables
	foreach ($apiTokens as &$t) {
		if (!empty($t['token_secret'])) $t['token_secret'] = wlDecrypt($t['token_secret']);
	} unset($t);
	foreach ($settings as &$s) {
		// Descifrar valores cifrados (ssh_pass, api keys, etc.)
		if (str_starts_with($s['value'] ?? '', 'enc:')) $s['value'] = wlDecrypt($s['value']);
	} unset($s);

	$payload = [
		'wakelab_export' => '1.0',
		'exported_at'    => date('c'),
		'encrypted'      => false,
		'data' => [
			'servers'    => $servers,
			'schedules'  => $schedules,
			'idle_config'=> $idleConfig,
			'wake_proxy' => $wakeProxy,
			'guest_meta' => $guestMeta,
			'api_tokens' => $apiTokens,
			'settings'   => $settings,
		],
	];

	$password = trim($data['password'] ?? '');
	if ($password !== '') {
		$salt    = random_bytes(16);
		$iv      = random_bytes(12);
		$tag     = '';
		$key     = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
		$plain   = json_encode($payload['data']);
		$enc     = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
		$payload['encrypted'] = true;
		$payload['salt']      = base64_encode($salt);
		$payload['iv']        = base64_encode($iv);
		$payload['tag']       = base64_encode($tag);
		$payload['data']      = base64_encode($enc);
	}

	echo ok($payload);
	} catch (Throwable $e) {
		echo err('Export failed: ' . $e->getMessage());
	}
	break;
}

case 'import_config': {
	$raw      = $data['payload'] ?? null;
	$password = trim($data['password'] ?? '');

	if (!is_array($raw) || ($raw['wakelab_export'] ?? '') !== '1.0') {
		echo err('Invalid export file'); break;
	}

	$importData = $raw['data'];

	if ($raw['encrypted'] ?? false) {
		if ($password === '') { echo err('File is encrypted — password required'); break; }
		$salt = base64_decode($raw['salt'] ?? '');
		$iv   = base64_decode($raw['iv']   ?? '');
		$tag  = base64_decode($raw['tag']  ?? '');
		$enc  = base64_decode($raw['data'] ?? '');
		$key  = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
		$dec  = openssl_decrypt($enc, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($dec === false) { echo err('Wrong password or corrupted file'); break; }
		$importData = json_decode($dec, true);
		if (!is_array($importData)) { echo err('Decryption failed — invalid data'); break; }
	}

	try {
		$pdo->beginTransaction();

		// Servers
		if (!empty($importData['servers'])) {
			$pdo->exec("DELETE FROM servers");
			$ins = $pdo->prepare("INSERT INTO servers (id,hostname,ip,port,mac,role,hypervisor_type,notes,url,api_enabled,proxmox_server_id,proxmox_vmid,depends_on_server_id,shutdown_timeout,last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
			foreach ($importData['servers'] as $r) {
				$ins->execute([$r['id'],$r['hostname'],$r['ip'],$r['port'] ?? 8006,$r['mac'] ?? '',$r['role'] ?? 'pve',$r['hypervisor_type'] ?? 'pve',$r['notes'] ?? '',$r['url'] ?? '',$r['api_enabled'] ?? 0,$r['proxmox_server_id'] ?? null,$r['proxmox_vmid'] ?? null,$r['depends_on_server_id'] ?? null,$r['shutdown_timeout'] ?? 90,$r['last_seen'] ?? null]);
			}
		}

		// Schedules
		if (!empty($importData['schedules'])) {
			$pdo->exec("DELETE FROM schedules");
			$ins = $pdo->prepare("INSERT INTO schedules (server_id,boot_time,shutdown_time,active,shutdown_active,days_json) VALUES (?,?,?,?,?,?)");
			foreach ($importData['schedules'] as $r) {
				$ins->execute([$r['server_id'],$r['boot_time'] ?? null,$r['shutdown_time'] ?? null,$r['active'] ?? 0,$r['shutdown_active'] ?? 0,$r['days_json'] ?? null]);
			}
		}

		// Idle config
		if (!empty($importData['idle_config'])) {
			$pdo->exec("DELETE FROM idle_config");
			$ins = $pdo->prepare("INSERT INTO idle_config (server_id,idle_limit_sec,check_interval_sec,detectors_json,detector_params_json,remote_path,active) VALUES (?,?,?,?,?,?,?)");
			foreach ($importData['idle_config'] as $r) {
				$ins->execute([$r['server_id'],$r['idle_limit_sec'] ?? 1800,$r['check_interval_sec'] ?? 300,$r['detectors_json'] ?? null,$r['detector_params_json'] ?? null,$r['remote_path'] ?? '/usr/local/bin/idle-shutdown.sh',$r['active'] ?? 1]);
			}
		}

		// Wake proxies
		if (!empty($importData['wake_proxy'])) {
			$pdo->exec("DELETE FROM wake_proxies");
			$ins = $pdo->prepare("INSERT INTO wake_proxies (id,name,domain,server_id,guest_vmid,guest_vmtype,docker_container,dest_ip,dest_port,dest_protocol,boot_timeout_sec,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
			foreach ($importData['wake_proxy'] as $r) {
				$ins->execute([$r['id'],$r['name'] ?? '',$r['domain'] ?? '',$r['server_id'],$r['guest_vmid'] ?? null,$r['guest_vmtype'] ?? null,$r['docker_container'] ?? null,$r['dest_ip'] ?? '',$r['dest_port'] ?? 80,$r['dest_protocol'] ?? 'http',$r['boot_timeout_sec'] ?? 240,$r['active'] ?? 1]);
			}
		}

		// Guest meta
		if (!empty($importData['guest_meta'])) {
			$pdo->exec("DELETE FROM guest_meta");
			$ins = $pdo->prepare("INSERT INTO guest_meta (server_id,vmid,url,notes) VALUES (?,?,?,?)");
			foreach ($importData['guest_meta'] as $r) {
				$ins->execute([$r['server_id'],$r['vmid'],$r['url'] ?? null,$r['notes'] ?? null]);
			}
		}

		// API tokens — re-cifrar con el WAKELAB_SECRET de este servidor
		if (!empty($importData['api_tokens'])) {
			$pdo->exec("DELETE FROM api_tokens");
			$ins = $pdo->prepare("INSERT INTO api_tokens (server_id,auth_type,api_user,token_id,token_secret) VALUES (?,?,?,?,?)");
			foreach ($importData['api_tokens'] as $r) {
				$secret = !empty($r['token_secret']) ? wlEncrypt($r['token_secret']) : '';
				$ins->execute([$r['server_id'],$r['auth_type'] ?? 'none',$r['api_user'] ?? '',$r['token_id'] ?? '',$secret]);
			}
		}

		// Settings — re-cifrar secretos con el WAKELAB_SECRET de este servidor
		if (!empty($importData['settings'])) {
			// Preservar claves del servidor actual que no deben pisarse
			$preserve = ['wakelab_secret','kiosk_token'];
			$existingPreserve = [];
			foreach ($preserve as $pk) {
				$r = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?"); $r->execute([$pk]);
				$v = $r->fetchColumn();
				if ($v !== false) $existingPreserve[$pk] = $v;
			}
			$pdo->exec("DELETE FROM settings WHERE `key` NOT LIKE 'pa_%' AND `key` NOT LIKE 'gu_%' AND `key` NOT LIKE 'wp_boot_%' AND `key` NOT LIKE 'wp_docker_%'");
			$ins = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
			foreach ($importData['settings'] as $s) {
				if (in_array($s['key'], array_keys($existingPreserve))) continue;
				$val = $s['value'] ?? '';
				// Detectar si era un secreto (SSH pass, api key) — re-cifrar
				$secretKeys = ['_ssh_pass','ai_api_key','_token_secret'];
				$isSecret = false;
				foreach ($secretKeys as $sk) { if (str_contains($s['key'], $sk)) { $isSecret = true; break; } }
				if ($isSecret && $val !== '') $val = wlEncrypt($val);
				$ins->execute([$s['key'], $val]);
			}
			// Restaurar claves preservadas
			foreach ($existingPreserve as $pk => $pv) {
				$ins->execute([$pk, $pv]);
			}
		}

		$pdo->commit();
		invalidateCache();
		logEv($pdo, 0, 'info', 'Config importada desde backup');
		echo ok(null, 'Config imported successfully');
	} catch (Throwable $e) {
		$pdo->rollBack();
		echo err('Import failed: ' . $e->getMessage());
	}
	break;
}

// ─────────────────────────────────────────────────────────────
default:
	echo err("Unknown action: " . htmlspecialchars((string)$action), 400);
}
?>
