<?php

class PVEClient extends HypClient {
	private ?string $authHeader;

	public function __construct(string $host, int $port, string $user, string $tokenId, string $secret) {
		parent::__construct($host, $port);
		$user = trim($user); $tokenId = trim($tokenId); $secret = trim($secret);
		$this->authHeader = ($user && $tokenId && $secret)
			? "Authorization: PVEAPIToken={$user}!{$tokenId}={$secret}"
			: null;
	}

	private function get(string $path): ?array {
		$resp = $this->curl(
			"https://{$this->host}:{$this->port}/api2/json{$path}",
			$this->authHeader ? [$this->authHeader] : []
		);
		return $resp['data'] ?? null;
	}

	private function post(string $path, array $fields = []): ?array {
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => "https://{$this->host}:{$this->port}/api2/json{$path}",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query($fields),
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_HTTPHEADER     => array_filter([
				'Content-Type: application/x-www-form-urlencoded',
				$this->authHeader,
			]),
		]);
		$response  = curl_exec($ch);
		$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr   = curl_error($ch);
		$curlErrNo = curl_errno($ch);
		curl_close($ch);
		$this->lastError = ['url'=>"POST $path",'http'=>$httpCode,'curl_err'=>$curlErr?:null,'curl_no'=>$curlErrNo?:null];
		if ($curlErrNo || !in_array($httpCode, [200, 201])) return null;
		return json_decode($response, true) ?: null;
	}

	/** Busca nodo y tipo (qemu|lxc) de un VMID via /cluster/resources */
	public function findGuestInfo(int $vmid): array {
		$resources = $this->get("/cluster/resources?type=vm");
		if ($resources) {
			foreach ($resources as $r) {
				if (intval($r['vmid'] ?? 0) === $vmid) {
					return [
						'node' => $r['node'] ?? '',
						'type' => $r['type'] ?? 'qemu',   // 'qemu' o 'lxc'
					];
				}
			}
		}
		return ['node' => $this->getFirstNode(), 'type' => 'qemu'];
	}

	/** Busca en qué nodo vive un VMID (soporta multi-nodo via /cluster/resources) */
	public function findNodeForVmid(int $vmid): string {
		return $this->findGuestInfo($vmid)['node'];
	}

	public function getGuestStatus(int $vmid): string {
		$resources = $this->get("/cluster/resources?type=vm");
		if ($resources) {
			foreach ($resources as $r) {
				if (intval($r['vmid'] ?? 0) === $vmid) return $r['status'] ?? 'unknown';
			}
		}
		return 'unknown';
	}

	/** Inicia un VM (qemu) o contenedor (lxc); auto-detecta nodo y tipo si no se especifican */
	public function startGuest(int $vmid, string $vmtype = '', string $node = ''): bool {
		if (!$node || !$vmtype) {
			$info   = $this->findGuestInfo($vmid);
			$node   = $node   ?: $info['node'];
			$vmtype = $vmtype ?: $info['type'];
		}
		$type = ($vmtype === 'lxc') ? 'lxc' : 'qemu';
		$resp = $this->post("/nodes/{$node}/{$type}/{$vmid}/status/start");
		return $resp !== null;
	}

	/** Apaga ordenadamente un VM o contenedor; auto-detecta nodo y tipo si no se especifican.
	 *  $timeout: segundos de espera por shutdown graceful (0 = default de Proxmox ~60s)
	 *  $forceStop: si true, fuerza stop después del timeout (garantiza que el VM se apague)
	 */
	public function shutdownGuest(int $vmid, string $vmtype = '', string $node = '', int $timeout = 0, bool $forceStop = false): bool {
		if (!$node || !$vmtype) {
			$info   = $this->findGuestInfo($vmid);
			$node   = $node   ?: $info['node'];
			$vmtype = $vmtype ?: $info['type'];
		}
		$type   = ($vmtype === 'lxc') ? 'lxc' : 'qemu';
		$fields = [];
		if ($timeout > 0) $fields['timeout']   = $timeout;
		if ($forceStop)   $fields['forceStop'] = 1;
		$resp = $this->post("/nodes/{$node}/{$type}/{$vmid}/status/shutdown", $fields);
		return $resp !== null;
	}

	public function rebootGuest(int $vmid, string $vmtype = '', string $node = ''): bool {
		if (!$node || !$vmtype) {
			$info   = $this->findGuestInfo($vmid);
			$node   = $node   ?: $info['node'];
			$vmtype = $vmtype ?: $info['type'];
		}
		$type = ($vmtype === 'lxc') ? 'lxc' : 'qemu';
		$resp = $this->post("/nodes/{$node}/{$type}/{$vmid}/status/reboot");
		return $resp !== null;
	}

	public function getNodes(): ?array { return $this->get('/nodes'); }

	public function getFirstNode(): string {
		$nodes = $this->getNodes();
		return $nodes[0]['node'] ?? $this->host;
	}

	public function getGuests(string $node = ''): array {
		if ($node && $node !== 'cluster') {
			$qemu = $this->get("/nodes/{$node}/qemu") ?? [];
			$lxc  = $this->get("/nodes/{$node}/lxc")  ?? [];
			foreach ($qemu as &$v) { $v['type'] = 'vm'; }  unset($v);
			foreach ($lxc  as &$v) { $v['type'] = 'lxc'; } unset($v);
			$all = array_merge($qemu, $lxc);
		} else {
			$resources = $this->get("/cluster/resources?type=vm") ?? [];
			$all = [];
			foreach ($resources as $r) {
				$r['type'] = ($r['type'] ?? '') === 'lxc' ? 'lxc' : 'vm';
				if (isset($r['maxcpu']) && !isset($r['cpus'])) $r['cpus'] = $r['maxcpu'];
				$all[] = $r;
			}
		}
		usort($all, fn($a,$b) => ($a['vmid']??0) <=> ($b['vmid']??0));
		return $all;
	}

	public function getNodeStats(string $node = ''): ?array {
		$nodes = $this->getNodes();
		if (!$nodes) return null;

		if ($node && $node !== 'cluster') {
			$n = array_values(array_filter($nodes, fn($x) => $x['node']===$node))[0] ?? $nodes[0];
			return [
				'node'       => $n['node']   ?? '?',
				'cpu'        => isset($n['cpu'])     ? round($n['cpu']*100, 1)            : null,
				'mem'        => isset($n['mem'])     ? round($n['mem']/1073741824, 2)     : null,
				'mem_total'  => isset($n['maxmem'])  ? round($n['maxmem']/1073741824, 2)  : null,
				'disk'       => isset($n['disk'])    ? round($n['disk']/1073741824, 2)    : null,
				'disk_total' => isset($n['maxdisk']) ? round($n['maxdisk']/1073741824, 2) : null,
				'uptime'     => $n['uptime'] ?? null,
			];
		}

		$cpuSum = 0; $cpuNodes = 0;
		$memUsed = 0; $memTotal = 0;
		$diskUsed = 0; $diskTotal = 0;
		$maxUptime = null;
		$count = count($nodes);
		foreach ($nodes as $n) {
			if (isset($n['cpu']))    { $cpuSum += $n['cpu']; $cpuNodes++; }
			if (isset($n['mem']))     $memUsed   += $n['mem'];
			if (isset($n['maxmem']))  $memTotal  += $n['maxmem'];
			if (isset($n['disk']))    $diskUsed  += $n['disk'];
			if (isset($n['maxdisk'])) $diskTotal += $n['maxdisk'];
			if (isset($n['uptime']))  $maxUptime  = max($maxUptime ?? 0, $n['uptime']);
		}
		$nodeLabel = $count === 1 ? ($nodes[0]['node'] ?? '?') : 'cluster';
		return [
			'node'       => $nodeLabel,
			'cpu'        => $cpuNodes > 0 ? round(($cpuSum / $cpuNodes) * 100, 1) : null,
			'mem'        => $memTotal  > 0 ? round($memUsed  / 1073741824, 2) : null,
			'mem_total'  => $memTotal  > 0 ? round($memTotal / 1073741824, 2) : null,
			'disk'       => $diskTotal > 0 ? round($diskUsed  / 1073741824, 2) : null,
			'disk_total' => $diskTotal > 0 ? round($diskTotal / 1073741824, 2) : null,
			'uptime'     => $maxUptime,
		];
	}

	/**
	 * Datos RRD del nodo. timeframe: 'hour' | 'day' | 'week' | 'month' | 'year'
	 * Retorna array de puntos con: time, cpu (0-1), mem, maxmem, netin, netout...
	 */
	public function getRrdData(string $node, string $timeframe = 'hour'): ?array {
		return $this->get("/nodes/{$node}/rrddata?timeframe={$timeframe}&cf=AVERAGE");
	}

	public function testConnection(): array {
		$steps = [];
		$alive = $this->ping();
		$steps[] = ['step'=>"TCP :{$this->port}",'ok'=>$alive,'detail'=>$alive?'Puerto alcanzable':'No responde en '.$this->port];
		if (!$alive) return $steps;

		if (!$this->authHeader) {
			$steps[] = ['step'=>'Token configurado','ok'=>false,'detail'=>'Falta usuario, token_id o secret'];
			return $steps;
		}
		$steps[] = ['step'=>'Token configurado','ok'=>true,'detail'=>substr($this->authHeader,0,70).'…'];

		$nodes = $this->getNodes();
		$d = $this->lastError;
		$steps[] = ['step'=>'GET /nodes','ok'=>$nodes!==null,
			'detail'=>$nodes ? 'Nodo: '.($nodes[0]['node']??'?').' — HTTP 200' : "HTTP {$d['http']} ".($d['curl_err']??'sin respuesta'),
			'debug'=>$d];
		if (!$nodes) return $steps;

		$node   = $nodes[0]['node'] ?? '';
		$guests = $this->getGuests($node);
		$steps[] = ['step'=>"Guests en $node",'ok'=>true,'detail'=>count($guests).' VMs/LXC'];
		return $steps;
	}
}
