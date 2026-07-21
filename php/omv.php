<?php

class OMVClient extends HypClient {
	private string  $user;
	private string  $password;
	private ?string $cookieFile = null;
	private bool    $loggedIn   = false;

	public function __construct(string $host, int $port, string $user, string $password) {
		parent::__construct($host, $port);
		$this->user     = trim($user);
		$this->password = $password;
	}

	public function __destruct() {
		if ($this->cookieFile && file_exists($this->cookieFile)) {
			@unlink($this->cookieFile);
		}
	}

	// ── Helpers ──────────────────────────────────────────────────

	private function baseUrl(): string {
		$scheme = ($this->port === 443 || $this->port === 4443) ? 'https' : 'http';
		return "{$scheme}://{$this->host}:{$this->port}";
	}

	private function cookieFile(): string {
		if (!$this->cookieFile) {
			$this->cookieFile = tempnam(sys_get_temp_dir(), 'omv_cookie_');
		}
		return $this->cookieFile;
	}

	/** Base curl options shared by all requests. Cookie jar handles session automatically. */
	private function baseCurlOpts(string $body): array {
		return [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_COOKIEFILE     => $this->cookieFile(),  // read cookies
			CURLOPT_COOKIEJAR      => $this->cookieFile(),  // save cookies
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'X-Requested-With: XMLHttpRequest',         // required by OMV
			],
		];
	}

	// ── Autenticación ────────────────────────────────────────────

	/** Login. Devuelve true si autenticó. */
	private function login(): bool {
		$body = json_encode([
			'service' => 'Session',
			'method'  => 'login',
			'params'  => ['username' => $this->user, 'password' => $this->password],
			'options' => null,
		]);

		$ch = curl_init($this->baseUrl() . '/rpc.php');
		curl_setopt_array($ch, $this->baseCurlOpts($body));
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200 || !$response) return false;

		$decoded = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) return false;
		if (!empty($decoded['error'])) return false;

		// Login OK — cookie jar ya guardó el PHPSESSID automáticamente
		$this->loggedIn = true;
		return true;
	}

	/** Llama un método RPC. Login automático si no hay sesión. */
	private function rpc(string $service, string $method, array $params = []): mixed {
		if (!$this->loggedIn && !$this->login()) {
			$this->lastError = [
				'http'     => 401,
				'curl_err' => null,
				'body'     => 'OMV login failed — verificar usuario/contraseña',
				'url'      => $this->baseUrl() . '/rpc.php',
			];
			return null;
		}

		$body = json_encode([
			'service' => $service,
			'method'  => $method,
			'params'  => $params,
			'options' => null,
		]);

		$ch = curl_init($this->baseUrl() . '/rpc.php');
		curl_setopt_array($ch, $this->baseCurlOpts($body));
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_error($ch);
		curl_close($ch);

		$this->lastError = [
			'url'      => $this->baseUrl() . "/rpc.php ({$service}.{$method})",
			'http'     => $httpCode,
			'curl_err' => $curlErr ?: null,
			'body'     => $response ?: null,
		];

		if ($httpCode !== 200 || !$response) return null;

		$decoded = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) return null;
		if (!empty($decoded['error'])) {
			$this->lastError['body'] = json_encode($decoded['error']);
			return null;
		}

		return $decoded['response'] ?? null;
	}

	// ── HypClient abstract ───────────────────────────────────────

	public function getNodes(): ?array {
		$info = $this->rpc('System', 'getInformation');
		return $info ? [['node' => $info['hostname'] ?? 'omv']] : null;
	}

	public function getGuests(string $node = ''): array { return []; }

	public function getNodeStats(string $node = ''): ?array {
		$info = $this->rpc('System', 'getInformation');
		if (!$info) return null;

		// OMV 8: cpuUtilization (float 0–100). Fallbacks para versiones anteriores.
		$cpu = null;
		foreach (['cpuUtilization', 'cpuUsage', 'cpuUsagePercent', 'cpu', 'cpuLoad'] as $cpuField) {
			if (!isset($info[$cpuField])) continue;
			$raw = $info[$cpuField];
			if (is_array($raw)) {
				// {'user':X,'sys':Y,...} o loadAverage {'1min':X,...}
				$raw = isset($raw['user'])
					? ($raw['user'] ?? 0) + ($raw['sys'] ?? 0) + ($raw['iowait'] ?? 0)
					: ($raw['1min'] ?? array_values($raw)[0] ?? 0);
			}
			$val = (float)str_replace('%', '', (string)$raw);
			$cpu = ($val > 0 && $val <= 1.0) ? round($val * 100, 1) : round($val, 1);
			break;
		}

		// memUsed / memTotal en bytes
		$memUsed  = isset($info['memUsed'])  ? round($info['memUsed']  / 1073741824, 2) : null;
		$memTotal = isset($info['memTotal']) ? round($info['memTotal'] / 1073741824, 2) : null;

		return [
			'node'      => $info['hostname'] ?? 'omv',
			'cpu'       => $cpu,
			'mem'       => $memUsed,
			'mem_total' => $memTotal,
			'uptime'    => $info['uptime'] ?? null,
		];
	}

	// ── Datos para el dashboard ──────────────────────────────────

	public function getFilesystems(): ?array {
		return $this->rpc('FileSystemMgmt', 'enumerateFilesystems');
	}

	public function getDiskTemps(): ?array {
		$disks = $this->rpc('Smart', 'getListBg', ['start' => 0, 'limit' => 50]);
		if (!is_array($disks)) return null;

		$result = [];
		foreach ($disks as $d) {
			$dev  = $d['devicefile'] ?? $d['device'] ?? null;
			$temp = $d['temperature'] ?? null;
			if ($dev && $temp !== null && is_numeric($temp)) {
				$result[$dev] = (int)$temp;
			}
		}
		return $result ?: null;
	}

	// ── Acciones ─────────────────────────────────────────────────

	public function shutdown(): bool {
		$res = $this->rpc('PowerMgmt', 'shutdown');
		return $res !== null || ($this->lastError['http'] ?? 0) === 200;
	}

	public function reboot(): bool {
		$res = $this->rpc('PowerMgmt', 'reboot');
		return $res !== null || ($this->lastError['http'] ?? 0) === 200;
	}

	// ── Test de conexión ─────────────────────────────────────────

	public function testConnection(): array {
		$steps = [];

		$alive = $this->ping();
		$steps[] = ['step' => "TCP :{$this->port}", 'ok' => $alive,
		            'detail' => $alive ? 'Puerto alcanzable' : 'No responde'];
		if (!$alive) return $steps;

		if (!$this->user || !$this->password) {
			$steps[] = ['step' => 'Credenciales', 'ok' => false, 'detail' => 'Faltan usuario y/o contraseña'];
			return $steps;
		}
		$steps[] = ['step' => 'Credenciales', 'ok' => true, 'detail' => "Usuario: {$this->user}"];

		$loginOk = $this->login();
		$steps[] = ['step' => 'Session.login', 'ok' => $loginOk,
		            'detail' => $loginOk
		                ? 'Autenticado — cookie de sesión obtenida'
		                : 'Login fallido (usuario/contraseña incorrectos o /rpc.php inaccesible)'];
		if (!$loginOk) return $steps;

		$info = $this->rpc('System', 'getInformation');
		$d    = $this->lastError;
		$cpuRaw  = null;
		$cpuKey  = 'ninguno';
		foreach (['cpuUsage','cpuUsagePercent','cpu','cpuLoad','loadAverage','processorUsage'] as $k) {
			if (isset($info[$k])) { $cpuRaw = $info[$k]; $cpuKey = $k; break; }
		}
		$steps[] = ['step' => 'System.getInformation', 'ok' => $info !== null,
		            'detail' => $info
		                ? 'Host: ' . ($info['hostname'] ?? '?') . ' — OMV v' . ($info['version'] ?? '?')
		                  . ' | CPU key: ' . $cpuKey . ' = ' . (is_scalar($cpuRaw) ? $cpuRaw : json_encode($cpuRaw))
		                  . ' | Campos: ' . implode(', ', array_keys($info))
		                : "HTTP {$d['http']} — " . substr($d['body'] ?? '', 0, 200),
		            'debug' => $d];
		if (!$info) return $steps;

		$fs = $this->rpc('FileSystemMgmt', 'enumerateFilesystems');
		$steps[] = ['step' => 'FileSystemMgmt.enumerateFilesystems', 'ok' => is_array($fs),
		            'detail' => is_array($fs) ? count($fs) . ' filesystem/s' : 'Sin respuesta'];

		return $steps;
	}
}
