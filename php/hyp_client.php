<?php

abstract class HypClient {
	protected string $host;
	protected int    $port;
	public    array  $lastError  = [];
	public    ?int   $lastPingMs = null;

	public function __construct(string $host, int $port) {
		$this->host = $host;
		$this->port = $port;
	}

	protected function curl(string $url, array $headers = [], int $timeout = 8): ?array {
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
		]);
		$response  = curl_exec($ch);
		$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr   = curl_error($ch);
		$curlErrNo = curl_errno($ch);
		curl_close($ch);

		$this->lastError = [
			'url'      => $url,
			'http'     => $httpCode,
			'curl_err' => $curlErr  ?: null,
			'curl_no'  => $curlErrNo ?: null,
			'body'     => $response  ?: null,
		];

		if ($curlErrNo || $httpCode !== 200) return null;
		$decoded = json_decode($response, true);
		return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
	}

	public function ping(): bool {
		$t    = microtime(true);
		$sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3);
		if ($sock) {
			$this->lastPingMs = (int)round((microtime(true) - $t) * 1000);
			fclose($sock);
			return true;
		}
		$this->lastPingMs = null;
		return false;
	}

	abstract public function getNodes(): ?array;
	abstract public function getGuests(string $node = ''): array;
	abstract public function getNodeStats(string $node = ''): ?array;
	abstract public function testConnection(): array;
}

// ── Factory ──────────────────────────────────────────────────
// hypervisor_type: 'pve' | 'pbs' | 'truenas' | 'omv' | 'windows' | 'linux' | 'generic'
class HypFactory {
	public static function make(array $srv, ?array $tok): HypClient {
		$host   = $srv['ip']              ?? '127.0.0.1';
		$port   = intval($srv['port']     ?? 8006);
		$type   = $srv['hypervisor_type'] ?? 'pve';
		$user   = trim($tok['api_user']    ?? 'root@pam');
		$tokId  = trim($tok['token_id']    ?? '');
		$secret = trim($tok['token_secret'] ?? '');

		// PC types: SSH credentials injected via tok['pc_ssh_*']
		if ($type === 'windows' || $type === 'linux') {
			require_once __DIR__ . '/pc.php';
			$sshUser = $tok['pc_ssh_user'] ?? ($type === 'windows' ? 'Administrator' : 'root');
			$sshPass = $tok['pc_ssh_pass'] ?? '';
			return new PCClient($host, $port ?: 22, $type, $sshUser, $sshPass);
		}

		if ($type === 'pbs') require_once __DIR__ . '/pbs.php';

		return match($type) {
			'pve'     => new PVEClient($host, $port, $user, $tokId, $secret),
			'pbs'     => new PBSClient($host, $port, $user, $tokId, $secret),
			'truenas' => new TrueNASClient($host, $port, $secret),
			'omv'     => new OMVClient($host, $port, $user, $secret),
			default   => new PVEClient($host, $port ?: 8006, $user, $tokId, $secret),
		};
	}
}
