<?php

class PBSClient extends HypClient {
	private ?string $authHeader;

	public function __construct(string $host, int $port, string $user, string $tokenId, string $secret) {
		parent::__construct($host, $port);
		$user = trim($user); $tokenId = trim($tokenId); $secret = trim($secret);
		$this->authHeader = ($user && $tokenId && $secret)
			? "Authorization: PBSAPIToken={$user}!{$tokenId}:{$secret}"
			: null;
	}

	private function get(string $path): ?array {
		$resp = $this->curl(
			"https://{$this->host}:{$this->port}/api2/json{$path}",
			$this->authHeader ? [$this->authHeader] : []
		);
		return $resp['data'] ?? null;
	}

	private function post(string $path, array $body = []): ?array {
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => "https://{$this->host}:{$this->port}/api2/json{$path}",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($body),
			CURLOPT_HTTPHEADER     => array_filter([
				'Content-Type: application/json',
				$this->authHeader,
			]),
		]);
		$resp = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($code < 200 || $code >= 300) return null;
		$d = json_decode((string)$resp, true);
		return (json_last_error() === JSON_ERROR_NONE) ? $d : null;
	}

	public function getNodes(): ?array {
		$status = $this->get('/nodes/localhost/status');
		return $status ? [$status] : null;
	}

	public function getGuests(string $n = ''): array { return []; }

	public function getDatastores(): ?array {
		$list = $this->get('/admin/datastore');
		if (!$list) return null;
		// Enriquecer con espacio usando curl_multi para hacerlo en paralelo
		$handles = [];
		$headers = $this->authHeader ? [$this->authHeader] : [];
		foreach ($list as $i => $ds) {
			$store = $ds['store'] ?? $ds['name'] ?? '';
			if (!$store) continue;
			$ch = curl_init();
			curl_setopt_array($ch, [
				CURLOPT_URL            => "https://{$this->host}:{$this->port}/api2/json/admin/datastore/" . rawurlencode($store) . '/status',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_TIMEOUT        => 5,
				CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
			]);
			$handles[$i] = $ch;
		}
		if ($handles) {
			$mh = curl_multi_init();
			foreach ($handles as $ch) curl_multi_add_handle($mh, $ch);
			do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
			foreach ($handles as $i => $ch) {
				$body = curl_multi_getcontent($ch);
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);
				if (!$body) continue;
				$d = json_decode($body, true);
				$status = $d['data'] ?? null;
				if ($status) {
					$list[$i]['used']  = $status['used']  ?? null;
					$list[$i]['avail'] = $status['avail'] ?? null;
					$list[$i]['total'] = isset($status['avail'], $status['used'])
						? $status['avail'] + $status['used'] : null;
				}
			}
			curl_multi_close($mh);
		}
		return $list;
	}

	public function getTasks(int $limit = 20): ?array {
		return $this->get("/nodes/localhost/tasks?limit={$limit}");
	}

	public function getSnapshots(string $datastore, ?int $vmid = null): ?array {
		$path = '/admin/datastore/' . rawurlencode($datastore) . '/snapshots';
		if ($vmid !== null) $path .= '?backup-type=vm&backup-id=' . $vmid;
		return $this->get($path);
	}

	/** @deprecated — ya no se llama desde el ciclo de polling */
	public function getBackupStatusByVm(): array {
		return [];
	}

	public function shutdown(): bool {
		return $this->post('/nodes/localhost/status', ['command' => 'shutdown']) !== null;
	}

	public function reboot(): bool {
		return $this->post('/nodes/localhost/status', ['command' => 'reboot']) !== null;
	}

	public function getNodeStats(string $node = ''): ?array {
		$nodes = $this->getNodes();
		if (!$nodes) return null;
		$n = $nodes[0] ?? [];
		return [
			'node'      => $n['id'] ?? 'pbs',
			'cpu'       => isset($n['cpu'])               ? round($n['cpu']*100,1)              : null,
			'mem'       => isset($n['memory']['used'])    ? round($n['memory']['used']/1073741824,2)  : null,
			'mem_total' => isset($n['memory']['total'])   ? round($n['memory']['total']/1073741824,2) : null,
			'uptime'    => $n['uptime'] ?? null,
		];
	}

	public function testConnection(): array {
		$steps = [];
		$alive = $this->ping();
		$steps[] = ['step'=>"TCP :{$this->port}",'ok'=>$alive,'detail'=>$alive?'Puerto alcanzable':'No responde'];
		if (!$alive) return $steps;

		$ver = $this->get('/version');
		$dv  = $this->lastError;
		$steps[] = ['step'=>'GET /version (sin auth)','ok'=>$ver!==null,
			'detail'=>$ver ? ('PBS v'.($ver['version']??'?').'-'.($ver['release']??'?')) : "HTTP {$dv['http']} — ".substr($dv['body']??'',0,120),
			'debug'=>$dv];

		if (!$this->authHeader) {
			$steps[] = ['step'=>'Token configurado','ok'=>false,'detail'=>'Falta usuario, token_id o secret'];
			return $steps;
		}
		$steps[] = ['step'=>'Token configurado','ok'=>true,'detail'=>substr($this->authHeader,0,90).'…'];

		$nodeStatus = $this->get('/nodes/localhost/status');
		$d = $this->lastError;
		$steps[] = ['step'=>'GET /nodes/localhost/status','ok'=>$nodeStatus!==null,
			'detail'=>$nodeStatus ? 'OK — uptime: '.($nodeStatus['uptime']??'?').'s'
								  : "HTTP {$d['http']} — ".substr($d['body']??'',0,200),
			'debug'=>$d];
		if (!$nodeStatus) return $steps;

		$ds = $this->getDatastores();
		$d2 = $this->lastError;
		$steps[] = ['step'=>'GET /admin/datastore','ok'=>$ds!==null,
			'detail'=>$ds ? count($ds).' datastore/s' : "HTTP {$d2['http']} — ".substr($d2['body']??'',0,120),
			'debug'=>$d2];
		return $steps;
	}
}
