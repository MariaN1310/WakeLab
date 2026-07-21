<?php
/**
 * WakeAI — generador de mensajes de notificación via LLM
 *
 * Soporta:
 *   - OpenAI   (gpt-4o-mini por defecto)
 *   - Anthropic (claude-haiku-4-5 por defecto)
 *
 * Uso:
 *   require_once __DIR__ . '/ai.php';
 *   $msg = WakeAI::generate($pdo, [
 *       'event'          => 'server_down',
 *       'hostname'       => 'pve01',
 *       'ip'             => '192.168.1.10',
 *       'pending_action' => 'manual_shutdown',  // o null
 *       'body'           => 'detalle extra opcional',
 *   ]);
 *   // $msg → string HTML de Telegram listo para enviar, o '' si falla/deshabilitado
 */
require_once __DIR__ . '/config.php';

class WakeAI
{
    // ─────────────────────────────────────────────────────────────
    // API pública
    // ─────────────────────────────────────────────────────────────

    /**
     * Genera un mensaje de notificación usando el LLM configurado.
     * Retorna '' si la IA está deshabilitada, sin API key, o si hay un error.
     */
    /** Lee toda la config de IA de la DB de una sola vez */
    public static function loadConfig(PDO $pdo): array
    {
        $keys = ['ai_enabled','ai_provider','ai_model','ai_api_key',
                 'ai_use_emojis','ai_highlight','ai_tone','ai_no_repeat','ai_extra_context','ai_language'];
        $in   = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ($in)");
        $stmt->execute($keys);
        $map  = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value', 'key');
        return [
            'ai_enabled'       => $map['ai_enabled']       ?? '0',
            'ai_provider'      => $map['ai_provider']      ?? 'openai',
            'ai_model'         => $map['ai_model']         ?? '',
            'ai_api_key'       => isset($map['ai_api_key']) ? wlDecrypt($map['ai_api_key']) : '',
            'ai_use_emojis'    => $map['ai_use_emojis']    ?? '1',
            'ai_highlight'     => $map['ai_highlight']     ?? '1',
            'ai_tone'          => $map['ai_tone']          ?? 'informal',
            'ai_no_repeat'     => $map['ai_no_repeat']     ?? '1',
            'ai_extra_context' => $map['ai_extra_context'] ?? '',
            'ai_language'      => $map['ai_language']      ?? 'en',
        ];
    }

    public static function generate(PDO $pdo, array $ctx, ?array $cfg = null): string
    {
        if ($cfg === null) $cfg = self::loadConfig($pdo);

        if ($cfg['ai_enabled'] !== '1') { error_log('[WakeAI] skipped: ai_enabled=' . $cfg['ai_enabled']); return ''; }
        if ($cfg['ai_api_key'] === '')  { error_log('[WakeAI] skipped: api_key empty (decrypt failed?)'); return ''; }

        $provider   = $cfg['ai_provider'];
        $model      = $cfg['ai_model'];
        $sysPompt   = self::systemPrompt($cfg);
        $userPrompt = self::buildUserPrompt($ctx);

        $event = $ctx['event'] ?? '?';
        $pa    = $ctx['pending_action'] ?? 'null';
        error_log("[WakeAI] calling provider={$provider} model={$model} event={$event} pa={$pa} lang={$cfg['ai_language']}");
        try {
            if ($provider === 'anthropic') {
                $result = self::callAnthropic($cfg['ai_api_key'], $model ?: 'claude-haiku-4-5', $sysPompt, $userPrompt);
            } elseif ($provider === 'gemini') {
                $result = self::callGemini($cfg['ai_api_key'], $model ?: 'gemini-2.0-flash', $sysPompt, $userPrompt);
            } else {
                $result = self::callOpenAI($cfg['ai_api_key'], $model ?: 'gpt-4o-mini', $sysPompt, $userPrompt);
            }
            error_log('[WakeAI] success, length=' . strlen($result));
            return $result;
        } catch (Throwable $e) {
            error_log('[WakeAI] error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Test rápido: genera un mensaje de ejemplo con contexto ficticio.
     * Útil para el botón "Probar" en Settings.
     */
    public static function test(PDO $pdo): array
    {
        $cfg = self::loadConfig($pdo);
        if ($cfg['ai_api_key'] === '') return ['ok' => false, 'error' => 'No API key configured'];

        // Forzar enabled para el test (el toggle puede estar en off)
        $cfg['ai_enabled'] = '1';

        $ctx = [
            'event'          => 'server_down',
            'hostname'       => 'pve01',
            'ip'             => '192.168.1.10',
            'pending_action' => 'manual_shutdown',
            'body'           => '',
        ];
        $model = $cfg['ai_model'] ?: match($cfg['ai_provider']) {
            'anthropic' => 'claude-haiku-4-5',
            'gemini'    => 'gemini-2.0-flash',
            default     => 'gpt-4o-mini',
        };

        // Capturar error real para mostrarlo en UI
        $lastError = '';
        try {
            $sysPrompt  = self::systemPrompt($cfg);
            $userPrompt = self::buildUserPrompt($ctx);
            $key        = $cfg['ai_api_key'];
            $provider   = $cfg['ai_provider'];

            $msg = match($provider) {
                'anthropic' => self::callAnthropic($key, $model, $sysPrompt, $userPrompt),
                'gemini'    => self::callGemini($key, $model, $sysPrompt, $userPrompt),
                default     => self::callOpenAI($key, $model, $sysPrompt, $userPrompt),
            };
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            $msg = '';
        }

        if ($msg === '') return ['ok' => false, 'error' => $lastError ?: 'Empty response from provider'];
        return ['ok' => true, 'message' => $msg, 'provider' => $cfg['ai_provider'], 'model' => $model];
    }

    // ─────────────────────────────────────────────────────────────
    // Construcción del prompt
    // ─────────────────────────────────────────────────────────────

    private static function buildUserPrompt(array $ctx): string
    {
        $event    = $ctx['event']    ?? 'unknown';
        $hostname = $ctx['hostname'] ?? '';
        $ip       = $ctx['ip']       ?? '';
        $pa       = $ctx['pending_action'] ?? null;
        $extra    = $ctx['body']     ?? '';

        $tz = getenv('TZ') ?: 'America/Argentina/Buenos_Aires';
        date_default_timezone_set($tz);

        $eventDesc = match($event) {
            'server_down' => 'Server went OFFLINE (stopped responding)',
            'server_up'   => 'Server came ONLINE (started responding again)',
            'schedule'    => 'A schedule was executed (scheduled action)',
            'idle'        => 'Automatic shutdown due to inactivity',
            'error'       => 'Error detected',
            default       => $event,
        };

        $triggerDesc = match($pa) {
            'manual_shutdown'    => 'User manually shut it down from WakeLab',
            'manual_wol'         => 'User powered it on via Wake-on-LAN from WakeLab',
            'manual_reboot'      => 'User rebooted it from WakeLab',
            'schedule_shutdown'  => 'Shutdown by scheduled task in WakeLab',
            'schedule_wol'       => 'Boot by scheduled task in WakeLab',
            'idle_shutdown'      => 'Automatic shutdown due to detected inactivity',
            'ups_shutdown'       => 'Shutdown due to power outage — UPS battery insufficient',
            'ups_shutdown_timer' => 'UPS shutdown — wait timer expired without power returning',
            'host_shutdown'      => 'Its Proxmox host server was shut down — this guest went offline with it',
            default              => 'Unexpected event — no prior action recorded',
        };

        // Emoji determinístico según evento + causa
        // El sistema prompt le dice que DEBE usar este emoji al inicio
        $emoji = match(true) {
            $event === 'server_up'   && $pa === 'manual_wol'        => '🟢',
            $event === 'server_up'   && $pa === 'manual_reboot'     => '🔄',
            $event === 'server_up'   && $pa === 'schedule_wol'      => '🟢',
            $event === 'server_up'                                  => '🟢',
            $event === 'server_down' && $pa === 'manual_shutdown'   => '🔴',
            $event === 'server_down' && $pa === 'schedule_shutdown' => '🔴',
            $event === 'server_down' && $pa === 'idle_shutdown'      => '💤',
            $event === 'server_down' && $pa === 'ups_shutdown'      => '🔋',
            $event === 'server_down' && $pa === 'ups_shutdown_timer' => '🔋',
            $event === 'server_down' && $pa === 'host_shutdown'     => '🔴',
            $event === 'server_down'                                => '🚨',
            $event === 'schedule'                                   => '🕐',
            $event === 'idle'                                       => '💤',
            $event === 'error'                                      => '❌',
            default                                                 => '🔔',
        };

        $h = (int)date('G');
        $momento = match(true) {
            $h >= 6  && $h < 12 => 'morning',
            $h >= 12 && $h < 15 => 'midday',
            $h >= 15 && $h < 20 => 'afternoon',
            $h >= 20            => 'night',
            default             => 'early morning',
        };

        $lines = [
            "Mandatory emoji for the first line: $emoji",
            "Event: $eventDesc",
            "Server: " . ($hostname ?: '(unknown)') . ($ip ? " — IP $ip" : ''),
            "Cause: $triggerDesc",
            "Time: " . date('H:i') . " ($momento of " . date('d/m/Y') . ")",
        ];
        if ($extra !== '') $lines[] = "Additional detail: $extra";

        return implode("\n", $lines);
    }

    private static function systemPrompt(array $cfg = []): string
    {
        $useEmojis  = ($cfg['ai_use_emojis']  ?? '1') === '1';
        $highlight  = ($cfg['ai_highlight']   ?? '1') === '1';
        $tone       =  $cfg['ai_tone']        ?? 'informal';
        $noRepeat   = ($cfg['ai_no_repeat']   ?? '1') === '1';
        $extraCtx   = trim($cfg['ai_extra_context'] ?? '');
        $lang       =  $cfg['ai_language']    ?? 'en';

        $langNames  = ['en' => 'English', 'es' => 'Spanish', 'pt' => 'Portuguese',
                       'fr' => 'French',  'de' => 'German',  'it' => 'Italian'];
        $langName   = $langNames[$lang] ?? 'English';

        $rules   = [];
        $rules[] = "- CRITICAL: Always respond in {$langName}. Every single word must be in {$langName}. Never mix languages.";
        $rules[] = '- Format: Telegram HTML only — use <b>bold</b>, <i>italic</i>, <code>code</code>. Do NOT use Markdown.';
        $rules[] = '- Length: 2 to 4 lines maximum. No introduction or closing.';
        $rules[] = '- Respond ONLY with the message text. No quotes, no prior explanations.';

        $rules[] = $highlight
            ? '- Hostname always in <b>bold</b>. IP and time also in bold when they appear.'
            : '- You may use bold sparingly.';

        $rules[] = $useEmojis
            ? '- The context includes "Mandatory emoji for the first line". You MUST use THAT exact emoji at the start of the message, before any text. Do not change it or add others before it.'
            : '- Do NOT use any emojis. Ignore the "Mandatory emoji" field in the context.';

        $rules[] = $tone === 'formal'
            ? '- Formal and professional tone. Avoid colloquial expressions.'
            : '- Casual and informal tone, like a message between homelab colleagues.';

        if ($noRepeat) {
            $rules[] = '- Vary the style, vocabulary, and structure in each message. Never repeat the same exact phrase.';
        }

        $tono = implode("\n", [
            'TONE based on the event cause (always indicated in the context):',
            '• "user manually shut it down / turned it on / rebooted it" → confirmatory, calm.',
            '• "scheduled task" or "inactivity" → informative, casual.',
            '• "unexpected event / no prior action recorded" → urgent, alarming. Suggest checking the device.',
        ]);

        $body = implode("\n", [
            'You are the notification assistant for WakeLab, a personal homelab dashboard.',
            'Your task: generate ONE short Telegram message based on the homelab event.',
            '',
            'RULES:',
            implode("\n", $rules),
            '',
            $tono,
        ]);

        if ($extraCtx !== '') {
            $body .= "\n\nADDITIONAL HOMELAB CONTEXT (use it to personalize the message):\n{$extraCtx}";
        }

        return $body;
    }

    // ─────────────────────────────────────────────────────────────
    // Proveedores
    // ─────────────────────────────────────────────────────────────

    private static function callOpenAI(string $key, string $model, string $sys, string $userMsg): string
    {
        $body = json_encode([
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $userMsg],
            ],
            'max_tokens'  => 400,
            'temperature' => 0.85,
        ]);

        $raw = self::curlPost(
            'https://api.openai.com/v1/chat/completions',
            $body,
            ["Authorization: Bearer $key", 'Content-Type: application/json']
        );
        $d = json_decode($raw, true);
        if (!empty($d['error'])) throw new RuntimeException('OpenAI: ' . ($d['error']['message'] ?? $raw));
        return trim($d['choices'][0]['message']['content'] ?? '');
    }

    private static function callAnthropic(string $key, string $model, string $sys, string $userMsg): string
    {
        $body = json_encode([
            'model'      => $model,
            'max_tokens' => 250,
            'system'     => $sys,
            'messages'   => [['role' => 'user', 'content' => $userMsg]],
        ]);

        $raw = self::curlPost(
            'https://api.anthropic.com/v1/messages',
            $body,
            ["x-api-key: $key", 'anthropic-version: 2023-06-01', 'Content-Type: application/json']
        );
        $d = json_decode($raw, true);
        if (!empty($d['error'])) throw new RuntimeException('Anthropic: ' . ($d['error']['message'] ?? $raw));
        return trim($d['content'][0]['text'] ?? '');
    }

    private static function callGemini(string $key, string $model, string $sys, string $userMsg): string
    {
        $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => $sys]]],
            'contents'           => [['role' => 'user', 'parts' => [['text' => $userMsg]]]],
            'generationConfig'   => ['maxOutputTokens' => 1024, 'temperature' => 0.85],
        ]);

        $raw = self::curlPost($url, $body, ['Content-Type: application/json']);
        $d   = json_decode($raw, true);
        if (!empty($d['error'])) throw new RuntimeException('Gemini: ' . ($d['error']['message'] ?? $raw));
        return trim($d['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private static function curlPost(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true, // APIs externas siempre con SSL verificado
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException("curl error: $err");
        return $resp;
    }

    private static function setting(PDO $pdo, string $key, string $default): string
    {
        if (function_exists('getSetting')) return getSetting($pdo, $key, $default);
        try {
            $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
            $s->execute([$key]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ? (string)$row['value'] : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
