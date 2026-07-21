<?php
// REST /api/v2.0 para status; WebSocket JSON-RPC 2.0 para acciones y CPU/RAM (25.04+)

// ── WebSocket JSON-RPC 2.0 ───────────────────────────────────────

class TrueNASWS {
	private $sock    = null;
	private int $nextId = 1;
	public ?array $lastRpcError = null;
	public string $connectError = '';

	public function __construct(string $host, int $port, string $apiKey, int $timeoutSec = 5) {
		$ctx = stream_context_create(['ssl' => [
			'verify_peer'      => false,
			'verify_peer_name' => false,
		]]);

		$this->sock = @stream_socket_client(
			"ssl://{$host}:{$port}", $errno, $errstr, $timeoutSec,
			STREAM_CLIENT_CONNECT, $ctx
		);
		if (!$this->sock) {
			$this->connectError = "SSL socket failed — {$errstr} (errno {$errno})";
			return;
		}

		stream_set_timeout($this->sock, $timeoutSec);

		$wsKey = base64_encode(random_bytes(16));
		fwrite($this->sock,
			"GET /api/current HTTP/1.1\r\n"
		  . "Host: {$host}\r\n"
		  . "Upgrade: websocket\r\n"
		  . "Connection: Upgrade\r\n"
		  . "Sec-WebSocket-Key: {$wsKey}\r\n"
		  . "Sec-WebSocket-Version: 13\r\n\r\n"
		);

		$buf = '';
		while (!feof($this->sock)) {
			$c = @fread($this->sock, 1);
			if ($c === false || $c === '') break;
			$buf .= $c;
			if (str_ends_with($buf, "\r\n\r\n")) break;
		}
		if (!str_contains($buf, ' 101 ')) {
			$statusLine = trim(explode("\r\n", $buf)[0] ?? '');
			$this->connectError = "WS upgrade failed — server responded: {$statusLine}";
			@fclose($this->sock); $this->sock = null; return;
		}

		$auth = $this->rpc('auth.login_with_api_key', [$apiKey]);
		if ($auth !== true) {
			$rpcErr = $this->lastRpcError;
			$this->connectError = 'API key auth failed' . ($rpcErr ? ' — ' . ($rpcErr['message'] ?? json_encode($rpcErr)) : '');
			@fclose($this->sock); $this->sock = null;
		}
	}

	public function isConnected(): bool { return $this->sock !== null; }

	public function subscribeFirst(string $event, int $maxWait = 5): mixed {
		if (!$this->sock) return null;

		$id = $this->nextId++;
		$this->wsSend(json_encode([
			'jsonrpc' => '2.0',
			'method'  => 'core.subscribe',
			'params'  => [$event],
			'id'      => $id,
		]));

		$deadline = microtime(true) + $maxWait;
		while (microtime(true) < $deadline) {
			$raw = $this->wsRecv();
			if ($raw === null) break;
			$msg = json_decode($raw, true);
			if (!is_array($msg)) continue;
			if (isset($msg['id']) && $msg['id'] === $id) continue;

			if (isset($msg['method']) && $msg['method'] === $event) {
				$p = $msg['params'] ?? null;
				return is_array($p) ? $p : null;
			}

			if (isset($msg['method'], $msg['params']) && $msg['method'] === 'collection_update') {
				$p = $msg['params'];
				if (is_array($p) && ($p['collection'] ?? '') === $event) {
					return $p['fields'] ?? $p;
				}
			}
		}
		return null;
	}

	public function rpc(string $method, array $params = []): mixed {
		if (!$this->sock) return null;
		$this->lastRpcError = null;
		$id = $this->nextId++;
		$this->wsSend(json_encode([
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
			'id'      => $id,
		]));

		$deadline = microtime(true) + 5;
		while (microtime(true) < $deadline) {
			$raw = $this->wsRecv();
			if ($raw === null) break;
			$msg = json_decode($raw, true);
			if (!is_array($msg)) continue;
			if (array_key_exists('id', $msg) && $msg['id'] === $id) {
				if (isset($msg['error'])) {
					$this->lastRpcError = $msg['error'];
					return null;
				}
				return $msg['result'] ?? null;
			}
		}
		return null;
	}

	public function close(): void {
		if ($this->sock) { @fclose($this->sock); $this->sock = null; }
	}

	private function wsSend(string $payload): void {
		if (!$this->sock) return;
		$len  = strlen($payload);
		$mask = random_bytes(4);

		if ($len <= 125)       $hdr = chr(0x81) . chr(0x80 | $len);
		elseif ($len <= 65535) $hdr = chr(0x81) . chr(0xFE) . pack('n', $len);
		else                   $hdr = chr(0x81) . chr(0xFF) . pack('J', $len);

		$masked = '';
		for ($i = 0; $i < $len; $i++) $masked .= $payload[$i] ^ $mask[$i % 4];

		@fwrite($this->sock, $hdr . $mask . $masked);
	}

	private function wsRecv(): ?string {
		if (!$this->sock) return null;

		$h = @fread($this->sock, 2);
		if (!$h || strlen($h) < 2) return null;

		$opcode = ord($h[0]) & 0x0F;
		$masked = (ord($h[1]) & 0x80) !== 0;
		$len    = ord($h[1]) & 0x7F;

		if ($len === 126) {
			$ext = @fread($this->sock, 2);
			if (!$ext || strlen($ext) < 2) return null;
			$len = unpack('n', $ext)[1];
		} elseif ($len === 127) {
			$ext = @fread($this->sock, 8);
			if (!$ext || strlen($ext) < 8) return null;
			$len = unpack('J', $ext)[1];
		}

		$maskBytes = $masked ? @fread($this->sock, 4) : '';

		$data = '';
		$left = (int)$len;
		while ($left > 0) {
			$chunk = @fread($this->sock, min($left, 8192));
			if ($chunk === false || $chunk === '') break;
			$data .= $chunk;
			$left -= strlen($chunk);
		}

		if ($masked && $maskBytes) {
			$decoded = '';
			for ($i = 0; $i < strlen($data); $i++) $decoded .= $data[$i] ^ $maskBytes[$i % 4];
			$data = $decoded;
		}

		if ($opcode === 9) {
			@fwrite($this->sock, chr(0x8A) . chr(0x00));
			return $this->wsRecv();
		}
		if ($opcode === 8) {
			@fclose($this->sock); $this->sock = null; return null;
		}

		return $data !== '' ? $data : null;
	}
}

class TrueNASClient extends HypClient {
	private ?string $apiKey;

	public function __construct(string $host, int $port, string $apiKey) {
		parent::__construct($host, $port);
		$this->apiKey = trim($apiKey) ?: null;
	}

	// ── WebSocket helpers ────────────────────────────────────────

	private function wsOpen(int $timeoutSec = 10): ?TrueNASWS {
		if (!$this->apiKey) {
			$this->lastError = ['http' => 0, 'curl_err' => 1, 'curl_msg' => 'Sin API key configurada', 'body' => '', 'url' => ''];
			return null;
		}
		$ws = new TrueNASWS($this->host, $this->port, $this->apiKey, $timeoutSec);
		if (!$ws->isConnected()) {
			$reason = $ws->connectError ?: 'WS connect/auth failed';
			$this->lastError = ['http' => 0, 'curl_err' => 1, 'curl_msg' => $reason,
			                    'body' => '', 'url' => "wss://{$this->host}:{$this->port}/api/current"];
			return null;
		}
		return $ws;
	}

	/** Abre WS, hace una llamada RPC y cierra. Devuelve null si no conecta o hay error. */
	private function wsRpc(string $method, array $params = [], int $timeoutSec = 10): mixed {
		$ws = $this->wsOpen($timeoutSec);
		if (!$ws) {
			$this->lastError = ['http' => 0, 'curl_err' => 1, 'curl_msg' => 'WS connect/auth failed',
			                    'body' => '', 'url' => "wss://{$this->host}:{$this->port}/api/current"];
			return null;
		}
		$result = $ws->rpc($method, $params);
		$err    = $ws->lastRpcError;
		$ws->close();
		if ($err !== null) {
			$this->lastError = ['http' => 0, 'curl_err' => 0,
			                    'curl_msg' => $err['message'] ?? json_encode($err),
			                    'body' => json_encode($err),
			                    'url' => "wss://{$this->host}:{$this->port}/api/current"];
			return null;
		}
		$this->lastError = ['http' => 200, 'curl_err' => 0, 'curl_msg' => '', 'body' => '', 'url' => ''];
		return $result;
	}

	// ── HypClient abstract ───────────────────────────────────────

	public function getNodes(): ?array {
		$info = $this->wsRpc('system.info');
		return $info ? [['node' => $info['hostname'] ?? 'truenas']] : null;
	}

	public function getGuests(string $n = ''): array { return []; }

	public function getNodeStats(string $node = ''): ?array {
		// getNodeStats y getAllExtra comparten la misma sesión WS para evitar doble conexión.
		// La sesión se abre en getAllExtra(); aquí usamos el resultado cacheado.
		if ($this->_cachedStats !== null) return $this->_cachedStats;
		// Fallback: abrir sesión propia si se llama sin getAllExtra previo
		$ws = $this->wsOpen(10);
		if (!$ws) return null;
		[$stats] = $this->_fetchStatsFromWS($ws);
		$ws->close();
		return $stats;
	}

	private ?array $_cachedStats = null;
	private ?array $_cachedExtra = null;

	private function _fetchStatsFromWS(TrueNASWS $ws): array {
		$info = $ws->rpc('system.info') ?? [];
		$cpu  = null; $memUsed = null;
		$rt   = $ws->subscribeFirst('reporting.realtime', 5);
		if (is_array($rt)) {
			if (isset($rt['cpu']['cpu']['usage']))         $cpu = round((float)$rt['cpu']['cpu']['usage'], 1);
			elseif (isset($rt['cpu']['average']['usage'])) $cpu = round((float)$rt['cpu']['average']['usage'], 1);
			elseif (is_array($rt['cpu'] ?? null)) {
				$vals = array_filter(array_column($rt['cpu'], 'usage'), fn($v) => is_numeric($v));
				if ($vals) $cpu = round(array_sum($vals) / count($vals), 1);
			}
			if (isset($rt['memory']['physical_memory_total'], $rt['memory']['physical_memory_available'])) {
				$memUsed = round(((float)$rt['memory']['physical_memory_total'] - (float)$rt['memory']['physical_memory_available']) / 1073741824, 2);
			} elseif (isset($rt['memory']['used'])) {
				$memUsed = round((float)$rt['memory']['used'] / 1073741824, 2);
			}
		}
		$stats = [
			'node'      => $info['hostname']       ?? 'truenas',
			'cpu'       => $cpu,
			'mem'       => $memUsed,
			'mem_total' => isset($info['physmem']) ? round($info['physmem'] / 1073741824, 2) : null,
			'uptime'    => $info['uptime_seconds'] ?? null,
		];
		return [$stats, $info];
	}

	// ── Acciones del sistema ─────────────────────────────────────

	private function wsAction(string $method): array {
		$ws = $this->wsOpen(10);
		if (!$ws) {
			return ['ok' => false, 'http' => 0, 'curl_err' => 0,
			        'curl_msg' => 'WebSocket: conexión o autenticación fallida',
			        'body' => '', 'url' => "wss://{$this->host}:{$this->port}/api/current"];
		}

		$result   = $ws->rpc($method, ['WakeLab']);
		$rpcError = $ws->lastRpcError;
		$ws->close();

		if ($rpcError !== null) {
			return ['ok' => false, 'http' => 0, 'curl_err' => 0,
			        'curl_msg' => 'TrueNAS error: ' . ($rpcError['message'] ?? json_encode($rpcError)),
			        'body' => json_encode($rpcError),
			        'url' => "wss://{$this->host}:{$this->port}/api/current"];
		}

		return ['ok' => true, 'http' => 200, 'curl_err' => 0, 'curl_msg' => '',
		        'body' => $result !== null ? json_encode($result) : '(conexión cerrada — sistema ejecutando comando)',
		        'url' => "wss://{$this->host}:{$this->port}/api/current"];
	}

	public function reboot(): array   { return $this->wsAction('system.reboot'); }
	public function shutdown(): array { return $this->wsAction('system.shutdown'); }

	public function startApp(string $name): array  { return $this->wsActionWithParam('app.start',  $name); }
	public function stopApp(string $name): array   { return $this->wsActionWithParam('app.stop',   $name); }
	public function startVM(int $id): array        { return $this->wsActionWithParam('vm.start',   $id);   }
	public function stopVM(int $id): array         { return $this->wsActionWithParam('vm.stop',    $id, ['force_after_timeout' => true]); }

	private function wsActionWithParam(string $method, mixed $param, array $extra = []): array {
		$ws = $this->wsOpen(10);
		if (!$ws) {
			return ['ok' => false, 'http' => 0, 'curl_err' => 0,
			        'curl_msg' => 'WebSocket: conexión o autenticación fallida',
			        'body' => '', 'url' => "wss://{$this->host}:{$this->port}/api/current"];
		}
		$params = $extra ? [$param, $extra] : [$param];
		$result = $ws->rpc($method, $params);
		$rpcErr = $ws->lastRpcError;
		$ws->close();
		if ($rpcErr !== null) {
			return ['ok' => false, 'http' => 0, 'curl_err' => 0,
			        'curl_msg' => 'TrueNAS error: ' . ($rpcErr['message'] ?? json_encode($rpcErr)),
			        'body' => json_encode($rpcErr), 'url' => ''];
		}
		return ['ok' => true, 'http' => 200, 'curl_err' => 0, 'curl_msg' => '',
		        'body' => $result !== null ? json_encode($result) : '', 'url' => ''];
	}

	// ── Datos para el dashboard — UNA sola sesión WS ────────────

	public function getPools(): ?array  { return $this->wsRpc('pool.query'); }
	public function getAlerts(): ?array { return $this->wsRpc('alert.list'); }
	public function getDisks(): ?array  { return $this->wsRpc('disk.query'); }
	public function getVMs(): ?array    { return $this->wsRpc('vm.query'); }
	public function getDiskTemps(): ?array { return null; } // usa getAllExtra

	/**
	 * Abre UNA sesión WS y ejecuta stats + todos los RPCs del dashboard.
	 * Luego getNodeStats() retorna el resultado cacheado sin abrir otra sesión.
	 */
	public function getAllExtra(): array {
		if ($this->_cachedExtra !== null) return $this->_cachedExtra;

		$ws = $this->wsOpen(10);
		if (!$ws) return [];

		// Stats (CPU/RAM/uptime) en la misma sesión
		[$this->_cachedStats] = $this->_fetchStatsFromWS($ws);

		$pools  = $ws->rpc('pool.query')  ?? [];
		$alerts = $ws->rpc('alert.list')  ?? [];
		$disks  = $ws->rpc('disk.query')  ?? [];
		$vms    = $ws->rpc('vm.query')    ?? [];

		$apps = $ws->rpc('app.query');
		if ($apps === null) {
			$apps = $ws->rpc('chart.release.query') ?? [];
			foreach ($apps as &$r) {
				if (!isset($r['state']) && isset($r['status'])) $r['state'] = $r['status'];
			}
			unset($r);
		}

		$diskTemps = null;
		$diskNames = array_values(array_filter(array_column($disks, 'name')));
		if ($diskNames) {
			$diskTemps = $ws->rpc('disk.temperature_agg', [$diskNames]);
		}

		$ws->close();
		$this->_cachedExtra = compact('pools', 'apps', 'vms', 'alerts', 'disks', 'diskTemps');
		return $this->_cachedExtra;
	}

	public function dismissAlert(string $uuid): bool {
		$this->wsRpc('alert.dismiss', [$uuid]);
		return ($this->lastError['http'] ?? 0) === 200;
	}

	public function getApps(): ?array {
		$apps = $this->wsRpc('app.query');
		if ($apps !== null) return $apps;

		// Fallback: versiones SCALE 22/23 usan chart.release.query
		$releases = $this->wsRpc('chart.release.query');
		if ($releases === null) return null;
		foreach ($releases as &$r) {
			if (!isset($r['state']) && isset($r['status'])) $r['state'] = $r['status'];
		}
		return $releases;
	}

	// ── Test de conexión ─────────────────────────────────────────

	public function testConnection(): array {
		$steps = [];

		$alive = $this->ping();
		$steps[] = ['step' => "TCP :{$this->port}", 'ok' => $alive, 'detail' => $alive ? 'Puerto alcanzable' : 'No responde'];
		if (!$alive) return $steps;

		$steps[] = ['step' => 'API Key', 'ok' => (bool)$this->apiKey, 'detail' => $this->apiKey ? 'Presente' : 'Sin API key'];
		if (!$this->apiKey) return $steps;

		// Abrir una sola sesión WS para todos los pasos
		$ws = $this->wsOpen(10);
		$wsUrl = "wss://{$this->host}:{$this->port}/api/current";
		$steps[] = ['step' => 'WebSocket auth', 'ok' => (bool)$ws,
		            'detail' => $ws ? "$wsUrl — autenticado" : "$wsUrl — falló (verificar API key y puerto)"];
		if (!$ws) return $steps;

		// system.info
		$info = $ws->rpc('system.info') ?? [];
		$steps[] = ['step' => 'system.info', 'ok' => !empty($info),
		            'detail' => !empty($info)
		                ? 'Host: ' . ($info['hostname'] ?? '?') . ' — v' . ($info['version'] ?? '?')
		                : 'Sin respuesta'];

		// pool.query
		$pools = $ws->rpc('pool.query');
		$steps[] = ['step' => 'pool.query', 'ok' => is_array($pools),
		            'detail' => is_array($pools) ? count($pools) . ' pool/s' : 'Sin respuesta'];

		// vm.query
		$vms = $ws->rpc('vm.query');
		$steps[] = ['step' => 'vm.query', 'ok' => is_array($vms),
		            'detail' => is_array($vms) ? count($vms) . ' VM/s' : 'Sin respuesta'];

		// app.query (con fallback a chart.release.query)
		$apps = $ws->rpc('app.query');
		if (is_array($apps)) {
			$steps[] = ['step' => 'app.query', 'ok' => true, 'detail' => count($apps) . ' app/s'];
		} else {
			$chart = $ws->rpc('chart.release.query');
			$steps[] = ['step' => 'app.query / chart.release.query', 'ok' => is_array($chart),
			            'detail' => is_array($chart) ? count($chart) . ' app/s (chart.release)' : 'Sin respuesta'];
		}

		// reporting.realtime (subscription)
		$rt = $ws->subscribeFirst('reporting.realtime', 6);
		$ws->close();

		if ($rt !== null) {
			$cpuVal = null;
			if (isset($rt['cpu']['cpu']['usage']))     $cpuVal = round((float)$rt['cpu']['cpu']['usage'], 1);
			elseif (isset($rt['cpu']['average']['usage'])) $cpuVal = round((float)$rt['cpu']['average']['usage'], 1);
			elseif (is_array($rt['cpu'] ?? null)) {
				$vals = array_filter(array_column($rt['cpu'], 'usage'), fn($v) => is_numeric($v));
				if ($vals) $cpuVal = round(array_sum($vals) / count($vals), 1);
			}
			$memGB = null;
			if (isset($rt['memory']['physical_memory_total'], $rt['memory']['physical_memory_available'])) {
				$memGB = round(((float)$rt['memory']['physical_memory_total'] - (float)$rt['memory']['physical_memory_available']) / 1073741824, 2);
			} elseif (isset($rt['memory']['used'])) {
				$memGB = round((float)$rt['memory']['used'] / 1073741824, 2);
			}
			$steps[] = ['step' => 'reporting.realtime', 'ok' => true,
			            'detail' => 'CPU: ' . ($cpuVal ?? '?') . '% — RAM usada: ' . ($memGB ?? '?') . ' GB'];
		} else {
			$ws->close();
			$steps[] = ['step' => 'reporting.realtime', 'ok' => false,
			            'detail' => 'Sin respuesta'];
		}

		return $steps;
	}
}
