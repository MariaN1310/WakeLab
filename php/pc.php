<?php
class PCClient extends HypClient {
    private string $osType;   // 'linux' | 'windows'
    private string $sshUser;
    private string $sshPass;  // '' = usar clave SSH de www-data

    public function __construct(string $host, int $port, string $osType, string $sshUser, string $sshPass) {
        parent::__construct($host, $port ?: 22);
        $this->osType  = $osType;
        $this->sshUser = $sshUser ?: ($osType === 'windows' ? 'Administrator' : 'root');
        $this->sshPass = $sshPass;
    }

    // ── Abstract requeridos ──────────────────────────────────────

    public function getNodes(): ?array {
        return [['node' => $this->host]];
    }

    public function getGuests(string $node = ''): array { return []; }

    // ── Métricas via SSH ─────────────────────────────────────────

    public function getNodeStats(string $node = ''): ?array {
        $out = $this->sshExec($this->metricsCmd());
        if ($out === '') return null;

        // Parsear KEY=valor de salida
        preg_match_all('/(\w+)=([\d.]+)/', $out, $m);
        if (empty($m[1])) return null;
        $metrics = array_combine($m[1], $m[2]);

        $memTotal = (float)($metrics['MEM_TOTAL'] ?? 0);
        $memUsed  = (float)($metrics['MEM_USED']  ?? 0);

        return [
            'node'       => $this->host,
            'cpu'        => round((float)($metrics['CPU'] ?? 0), 1),
            'mem'        => $memTotal > 0 ? round($memUsed  / 1073741824, 2) : null,
            'mem_total'  => $memTotal > 0 ? round($memTotal / 1073741824, 2) : null,
            'uptime'     => (int)($metrics['UPTIME'] ?? 0),
            'disk'       => isset($metrics['DISK_USED'])  ? round((float)$metrics['DISK_USED']  / 1073741824, 2) : null,
            'disk_total' => isset($metrics['DISK_TOTAL']) ? round((float)$metrics['DISK_TOTAL'] / 1073741824, 2) : null,
        ];
    }

    private function metricsCmd(): string {
        if ($this->osType === 'windows') {
            // PowerShell via SSH — CIM para CPU/RAM/Disco C:
            return '$c=[math]::Round((Get-CimInstance Win32_Processor|Measure-Object LoadPercentage -Average).Average,1);'
                 . '$o=Get-CimInstance Win32_OperatingSystem;'
                 . '$mt=$o.TotalVisibleMemorySize*1024;'
                 . '$mu=($o.TotalVisibleMemorySize-$o.FreePhysicalMemory)*1024;'
                 . '$up=[int]((Get-Date)-$o.LastBootUpTime).TotalSeconds;'
                 . '$d=Get-CimInstance Win32_LogicalDisk -Filter "DeviceID=\'C:\'";'
                 . 'Write-Output "CPU=$c MEM_TOTAL=$mt MEM_USED=$mu UPTIME=$up DISK_TOTAL=$($d.Size) DISK_USED=$($d.Size-$d.FreeSpace)"';
        }
        // Linux: /proc + vmstat + df /
        return 'CPU=$(vmstat 1 1 2>/dev/null|awk \'NR==4{printf "%.1f",100-$15}\');'
             . 'MT=$(awk \'/MemTotal/{print $2*1024}\' /proc/meminfo);'
             . 'MA=$(awk \'/MemAvailable/{print $2*1024}\' /proc/meminfo);'
             . 'UP=$(awk \'{print int($1)}\' /proc/uptime);'
             . 'DT=$(df -B1 / 2>/dev/null|awk \'NR==2{print $2}\');'
             . 'DU=$(df -B1 / 2>/dev/null|awk \'NR==2{print $3}\');'
             . 'echo "CPU=$CPU MEM_TOTAL=$MT MEM_USED=$((MT-MA)) UPTIME=$UP DISK_TOTAL=$DT DISK_USED=$DU"';
    }

    // ── Test de conexión ─────────────────────────────────────────

    public function testConnection(): array {
        $steps = [];

        $alive = $this->ping();
        $steps[] = ['step' => "TCP :22 (SSH)", 'ok' => $alive,
                    'detail' => $alive ? 'Puerto SSH alcanzable' : 'No responde en puerto 22'];
        if (!$alive) return $steps;

        if (!$this->sshUser) {
            $steps[] = ['step' => 'SSH user', 'ok' => false, 'detail' => 'Usuario SSH no configurado'];
            return $steps;
        }

        $echoOut = $this->sshExec('echo WAKELAB_OK');
        $sshOk   = str_contains($echoOut, 'WAKELAB_OK');
        $steps[] = ['step' => 'SSH auth', 'ok' => $sshOk,
                    'detail' => $sshOk
                        ? "Conectado como {$this->sshUser}" . ($this->sshPass ? ' (contraseña)' : ' (clave SSH)')
                        : 'Auth fallida — ' . ($this->sshPass ? 'contraseña incorrecta' : 'clave no autorizada') . ": {$echoOut}"];
        if (!$sshOk) return $steps;

        $metrics = $this->getNodeStats();
        $steps[] = ['step' => 'Métricas SSH', 'ok' => $metrics !== null,
                    'detail' => $metrics
                        ? "CPU {$metrics['cpu']}% · RAM {$metrics['mem']}GB/{$metrics['mem_total']}GB"
                        : 'No se pudo obtener métricas — verificar comandos disponibles'];

        return $steps;
    }

    // ── SSH helper ───────────────────────────────────────────────

    public function sshExec(string $cmd): string {
        if (!trim((string)shell_exec('which ssh 2>/dev/null'))) return '';

        $usePass = ($this->sshPass !== '');
        if ($usePass && !trim((string)shell_exec('which sshpass 2>/dev/null'))) return '';

        $portOpt  = ($this->port !== 22) ? "-p {$this->port}" : '';
        $batchMode = $usePass ? 'no' : 'yes';
        $sshOpts  = "-o StrictHostKeyChecking=no -o ConnectTimeout=8 -o BatchMode={$batchMode} {$portOpt}";
        $target   = escapeshellarg("{$this->sshUser}@{$this->host}");
        $prefix   = $usePass ? 'sshpass -p ' . escapeshellarg($this->sshPass) . ' ' : '';

        // Windows: correr via PowerShell
        $fullCmd = ($this->osType === 'windows')
            ? 'powershell -NoProfile -NonInteractive -Command ' . escapeshellarg($cmd)
            : $cmd;

        $out = (string)shell_exec("{$prefix}ssh {$sshOpts} {$target} " . escapeshellarg($fullCmd) . " 2>/dev/null");
        return trim($out);
    }
}
