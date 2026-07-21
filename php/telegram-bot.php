<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Helpers ───────────────────────────────────────────────────

function setting(string $key, string $default = ''): string {
    global $pdo;
    try {
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
        $s->execute([$key]);
        $r = $s->fetch();
        return $r ? (string)$r['value'] : $default;
    } catch (Throwable) { return $default; }
}

function saveSetting(string $key, string $value): void {
    global $pdo;
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
        ->execute([$key, $value]);
}

function tgPost(string $method, array $params): ?array {
    $token = wlDecrypt(setting('telegram_token'));
    if (!$token) return null;
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 55,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function tgSend(int $chatId, string $text): void {
    tgPost('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']);
}

// ── Tools ─────────────────────────────────────────────────────

function pingHost(string $ip, int $port, int $timeout = 2): bool {
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if ($sock) { fclose($sock); return true; }
    return false;
}

function toolGetStatus(): array {
    global $pdo;
    $rows = $pdo->query(
        "SELECT s.id, s.hostname, s.ip, s.port, s.hypervisor_type, s.last_seen
         FROM servers s ORDER BY s.hostname"
    )->fetchAll();
    return array_map(function($s) {
        $ip   = $s['ip'];
        $port = (int)($s['port'] ?? 0);
        // Ping TCP en los puertos más comunes — respuesta inmediata sin depender del browser
        $alive = $ip && (
            ($port > 0 && pingHost($ip, $port))
            || pingHost($ip, 22)
            || pingHost($ip, 80)
            || pingHost($ip, 443)
        );
        return [
            'id'        => (int)$s['id'],
            'hostname'  => $s['hostname'],
            'ip'        => $ip,
            'type'      => $s['hypervisor_type'],
            'status'    => $alive ? 'online' : 'offline',
            'last_seen' => $s['last_seen'] ?? 'unknown',
        ];
    }, $rows);
}

function botSetPending(int $srvId, string $action): void {
    global $pdo;
    $val = json_encode(['action' => $action, 'ts' => time()]);
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
        ->execute(['pa_' . $srvId, $val]);
}

function toolWakeServer(string $hostname): string {
    global $pdo;
    $srv = $pdo->prepare("SELECT id, mac, ip FROM servers WHERE hostname = ?");
    $srv->execute([$hostname]);
    $s = $srv->fetch();
    if (!$s) return "Server '$hostname' not found.";
    if (!$s['mac']) return "Server '$hostname' has no MAC address configured.";
    $mac = str_replace([':', '-'], '', $s['mac']);
    if (strlen($mac) !== 12) return "Invalid MAC address.";
    $packet = str_repeat(chr(255), 6) . str_repeat(pack('H*', $mac), 16);
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
    socket_sendto($sock, $packet, strlen($packet), 0, '255.255.255.255', 9);
    socket_close($sock);
    $pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (?,?,?,?)")
        ->execute([$s['id'], 'ok', "[Telegram Bot] WoL sent", date('Y-m-d H:i:s')]);
    botSetPending((int)$s['id'], 'manual_wol');

    // Esperar hasta 3 min a que el server responda y confirmar por Telegram
    global $_botChatId;
    if ($_botChatId) {
        $ip      = $s['ip'];
        $srvId   = (int)$s['id'];
        $deadline = time() + 180;
        $online  = false;
        while (time() < $deadline) {
            sleep(10);
            $sock = @fsockopen($ip, 22, $errno, $errstr, 3);
            if (!$sock) $sock = @fsockopen($ip, 80, $errno, $errstr, 3);
            if (!$sock) $sock = @fsockopen($ip, 443, $errno, $errstr, 3);
            if ($sock) { fclose($sock); $online = true; break; }
        }
        if ($online) {
            $pdo->prepare("UPDATE servers SET last_seen=NOW() WHERE id=?")
                ->execute([$srvId]);
            tgSend($_botChatId, "✅ <b>{$hostname}</b> está online.");
        } else {
            tgSend($_botChatId, "⚠️ WoL enviado a <b>{$hostname}</b>, pero no respondió en 3 minutos. Verificá manualmente.");
        }
    }
    return "✓ WoL enviado a {$hostname}. Confirmación en camino…";
}

function toolShutdownServer(string $hostname): string {
    global $pdo;
    $srv = $pdo->prepare(
        "SELECT s.id, s.ip, s.port, s.hypervisor_type, t.token_secret, t.api_user, t.token_id
         FROM servers s LEFT JOIN api_tokens t ON t.server_id = s.id WHERE s.hostname = ?"
    );
    $srv->execute([$hostname]);
    $s = $srv->fetch();
    if (!$s) return "Server '$hostname' not found.";

    $type   = $s['hypervisor_type'] ?? 'generic';
    $secret = $s['token_secret'] ? wlDecrypt($s['token_secret']) : '';

    if (in_array($type, ['pve', 'pbs', 'truenas', 'omv']) && $secret) {
        require_once __DIR__ . '/hyp_client.php';
        try {
            $client = HypFactory::make($s, [
                'token_secret' => $secret,
                'api_user'     => $s['api_user']  ?? 'root@pam',
                'token_id'     => $s['token_id']  ?? '',
            ]);
            $client->shutdown();
            $pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (?,?,?,?)")
                ->execute([$s['id'], 'warn', "[Telegram Bot] Shutdown requested", date('Y-m-d H:i:s')]);
            botSetPending((int)$s['id'], 'manual_shutdown');
            return "✓ Shutdown sent to {$hostname}.";
        } catch (Throwable $e) {
            return "Error shutting down {$hostname}: " . $e->getMessage();
        }
    }

    $sshUser = setting("srv_{$s['id']}_ssh_user", 'root');
    $sshPort = (int)setting("srv_{$s['id']}_ssh_port", '22');
    $sshPass = wlDecrypt(setting("srv_{$s['id']}_ssh_pass", ''));
    if (!$sshPass) return "No SSH credentials for {$hostname}.";
    $cmd = sprintf(
        "sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s 'shutdown -h now' 2>&1",
        escapeshellarg($sshPass), $sshPort,
        escapeshellarg("{$sshUser}@{$s['ip']}")
    );
    exec($cmd, $out, $code);
    if ($code === 0) {
        $pdo->prepare("INSERT INTO events (server_id,level,message,timestamp) VALUES (?,?,?,?)")
            ->execute([$s['id'], 'warn', "[Telegram Bot] SSH shutdown requested", date('Y-m-d H:i:s')]);
        botSetPending((int)$s['id'], 'manual_shutdown');
        return "✓ SSH shutdown sent to {$hostname}.";
    }
    return "SSH error shutting down {$hostname}: " . implode(' ', $out);
}

function toolGetLogs(int $limit = 10): array {
    global $pdo;
    $limit = min($limit, 20);
    $stmt  = $pdo->prepare(
        "SELECT e.level, e.message, e.timestamp, s.hostname
         FROM events e LEFT JOIN servers s ON s.id = e.server_id
         ORDER BY e.id DESC LIMIT ?"
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    return array_map(fn($r) => [
        'time'    => $r['timestamp'],
        'host'    => $r['hostname'] ?? 'system',
        'level'   => $r['level'],
        'message' => $r['message'],
    ], $rows);
}

// ── Tool definitions ──────────────────────────────────────────

const AI_TOOLS_OAI = [
    ['type' => 'function', 'function' => [
        'name'        => 'get_servers_status',
        'description' => 'Gets the list of servers registered in WakeLab with their IP, type and last known status.',
        'parameters'  => ['type' => 'object', 'properties' => new stdClass, 'required' => []],
    ]],
    ['type' => 'function', 'function' => [
        'name'        => 'wake_server',
        'description' => 'Sends Wake-on-LAN packet to power on a server.',
        'parameters'  => ['type' => 'object', 'properties' => ['hostname' => ['type' => 'string']], 'required' => ['hostname']],
    ]],
    ['type' => 'function', 'function' => [
        'name'        => 'shutdown_server',
        'description' => 'Shuts down a server via API or SSH.',
        'parameters'  => ['type' => 'object', 'properties' => ['hostname' => ['type' => 'string']], 'required' => ['hostname']],
    ]],
    ['type' => 'function', 'function' => [
        'name'        => 'get_recent_logs',
        'description' => 'Gets the latest events from the WakeLab event log.',
        'parameters'  => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']], 'required' => []],
    ]],
];

const AI_TOOLS_ANTHROPIC = [
    ['name' => 'get_servers_status', 'description' => 'Gets the list of servers registered in WakeLab with their IP, type and last known status.', 'input_schema' => ['type' => 'object', 'properties' => new stdClass]],
    ['name' => 'wake_server',        'description' => 'Sends Wake-on-LAN packet to power on a server.',                                             'input_schema' => ['type' => 'object', 'properties' => ['hostname' => ['type' => 'string']], 'required' => ['hostname']]],
    ['name' => 'shutdown_server',    'description' => 'Shuts down a server via API or SSH.',                                                         'input_schema' => ['type' => 'object', 'properties' => ['hostname' => ['type' => 'string']], 'required' => ['hostname']]],
    ['name' => 'get_recent_logs',    'description' => 'Gets the latest events from the WakeLab event log.',                                          'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]]],
];

// ── AI call ───────────────────────────────────────────────────

function dispatchTool(string $name, array $args): string {
    return match($name) {
        'get_servers_status' => json_encode(toolGetStatus()),
        'wake_server'        => toolWakeServer($args['hostname'] ?? ''),
        'shutdown_server'    => toolShutdownServer($args['hostname'] ?? ''),
        'get_recent_logs'    => json_encode(toolGetLogs((int)($args['limit'] ?? 10))),
        default              => "Unknown tool: {$name}",
    };
}

function callOpenAI(string $key, string $model, array $messages): ?array {
    $payload = ['model' => $model, 'messages' => $messages, 'tools' => AI_TOOLS_OAI, 'tool_choice' => 'auto', 'max_tokens' => 512];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $key", 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $res = curl_exec($ch); curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function callGemini(string $key, string $model, array $messages): ?array {
    // Gemini OpenAI-compat endpoint
    $url = "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions";
    $payload = ['model' => $model, 'messages' => $messages, 'tools' => AI_TOOLS_OAI, 'tool_choice' => 'auto', 'max_tokens' => 512];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $key", 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 55]);
    $res = curl_exec($ch); curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function callAnthropic(string $key, string $model, array $messages): string {
    // Anthropic has different request/response format — handle agentic loop internally
    $sysMsg = '';
    $anthMsgs = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $sysMsg = $m['content']; continue; }
        $anthMsgs[] = $m;
    }

    for ($i = 0; $i < 5; $i++) {
        $payload = ['model' => $model, 'max_tokens' => 512, 'system' => $sysMsg,
                    'messages' => $anthMsgs, 'tools' => AI_TOOLS_ANTHROPIC];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ["x-api-key: $key", 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $res = curl_exec($ch); curl_close($ch);
        $d = $res ? json_decode($res, true) : null;
        if (!$d || !empty($d['error'])) return 'AI error: ' . ($d['error']['message'] ?? 'unknown');

        $content   = $d['content'] ?? [];
        $stopReason = $d['stop_reason'] ?? '';

        if ($stopReason !== 'tool_use') {
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') return trim($block['text'] ?? '');
            }
            return '(no response)';
        }

        $anthMsgs[] = ['role' => 'assistant', 'content' => $content];
        $toolResults = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') continue;
            $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'],
                              'content' => dispatchTool($block['name'], $block['input'] ?? [])];
        }
        $anthMsgs[] = ['role' => 'user', 'content' => $toolResults];
    }
    return 'Iteration limit reached.';
}

// ── OpenAI-compatible agentic loop (OpenAI + Gemini) ──────────

function agentLoopOAI(callable $callFn, string $key, string $model, array $messages): string {
    for ($i = 0; $i < 5; $i++) {
        $resp   = $callFn($key, $model, $messages);
        $choice = $resp['choices'][0] ?? null;
        if (!$choice) {
            $err = $resp['error']['message'] ?? json_encode($resp);
            return "AI error: {$err}";
        }

        $msg       = $choice['message'] ?? [];
        $toolCalls = $msg['tool_calls'] ?? [];

        if (empty($toolCalls)) return $msg['content'] ?? '(no response)';

        $messages[] = $msg;
        foreach ($toolCalls as $tc) {
            $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'],
                           'content' => dispatchTool($tc['function']['name'] ?? '', $args)];
        }
    }
    return 'Iteration limit reached.';
}

// ── Process user message ──────────────────────────────────────

function processMessage(string $text, int $chatId): string {
    global $pdo;

    try {
        $_tzRow = $pdo->query("SELECT `value` FROM settings WHERE `key`='timezone' LIMIT 1")->fetch();
        $tz = ($_tzRow && $_tzRow['value']) ? $_tzRow['value'] : (getenv('TZ') ?: 'UTC');
    } catch (Throwable) {
        $tz = getenv('TZ') ?: 'UTC';
    }
    date_default_timezone_set($tz);

    $servers    = $pdo->query("SELECT hostname FROM servers ORDER BY hostname")->fetchAll(PDO::FETCH_COLUMN);
    $serverList = implode(', ', $servers) ?: 'none';
    $now        = date('Y-m-d H:i:s');

    $systemPrompt = "You are the WakeLab homelab assistant. Current date/time: {$now}.
Registered servers: {$serverList}.
Reply concisely in the same language the user writes in. Use the available tools to answer questions or execute actions.
Only execute destructive actions (shutdown) if the user explicitly requests it.";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $text],
    ];

    $provider = setting('ai_provider', 'openai');
    $key      = wlDecrypt(setting('ai_api_key'));
    $model    = setting('ai_model', '');

    if (!$key) return 'AI not configured — set the API key in Settings.';

    return match($provider) {
        'anthropic' => callAnthropic($key, $model ?: 'claude-haiku-4-5', $messages),
        'gemini'    => agentLoopOAI('callGemini', $key, $model ?: 'gemini-2.0-flash', $messages),
        default     => agentLoopOAI('callOpenAI', $key, $model ?: 'gpt-4o-mini', $messages),
    };
}

// ── Main ──────────────────────────────────────────────────────

if (setting('ai_enabled', '0') !== '1') exit(0);
if (setting('telegram_enabled', '0') !== '1') exit(0);

$botToken  = wlDecrypt(setting('telegram_token'));
$allowedId = setting('telegram_chat_id');
if (!$botToken || !$allowedId) exit(0);

$offset  = (int)setting('telegram_poll_offset', '0');
$updates = tgPost('getUpdates', ['offset' => $offset, 'timeout' => 50, 'allowed_updates' => ['message']]);
if (empty($updates['result'])) exit(0);

foreach ($updates['result'] as $upd) {
    saveSetting('telegram_poll_offset', (string)($upd['update_id'] + 1));

    $msg    = $upd['message'] ?? null;
    if (!$msg) continue;

    $chatId = (int)($msg['chat']['id'] ?? 0);
    $text   = trim($msg['text'] ?? '');

    if ((string)$chatId !== (string)$allowedId) continue;
    if (!$text) continue;

    global $_botChatId;
    $_botChatId = $chatId;
    $reply = processMessage($text, $chatId);
    tgSend($chatId, $reply);
}
