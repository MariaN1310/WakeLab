<?php
/**
 * WakeNotify — dispatcher unificado de notificaciones
 *
 * Canales:
 *   - Push web (via WakePush / push.php)
 *   - Telegram Bot API
 *   - Email vía SMTP (SmtpMailer)
 *
 * Uso:
 *   WakeNotify::notifyAll($pdo, [
 *       'title' => '🟢 Servidor online',
 *       'body'  => 'pve01 volvió online',
 *       'tag'   => 'server-1',
 *       'url'   => './',
 *   ], 'server_up');
 */
class WakeNotify
{
    private const EVT_KEYS = ['server_down', 'server_up', 'schedule', 'idle', 'error', 'guest_unknown', 'wake_timeout'];

    // ─────────────────────────────────────────────────────────────
    // API pública
    // ─────────────────────────────────────────────────────────────

    /**
     * Dispara notificaciones en todos los canales activos para el evento dado.
     * Fallos individuales son silenciosos (no lanzan excepción).
     *
     * @param array  $payload  ['title', 'body', 'tag', 'url']
     * @param string $event    Ej: server_up | server_down | schedule | idle | error | test
     */
    public static function notifyAll(PDO $pdo, array $payload, string $event): void
    {
        // ── Toggle global ─────────────────────────────────────────
        if ($event !== 'test' && self::setting($pdo, 'notify_global_enabled', '1') === '0') return;

        // ── Push web ──────────────────────────────────────────────
        if (file_exists(__DIR__ . '/push.php')) {
            try {
                require_once __DIR__ . '/push.php';
                WakePush::notifyAll($pdo, $payload, $event);
            } catch (Throwable) {}
        }

        // ── Telegram ──────────────────────────────────────────────
        if (self::channelEnabled($pdo, 'telegram', $event)) {
            $token  = self::setting($pdo, 'telegram_token',   '');
            $chatId = self::setting($pdo, 'telegram_chat_id', '');
            if ($token !== '' && $chatId !== '') {
                try {
                    $enhanced = self::enhanceTelegramMessage($pdo, $payload, $event);
                    if ($enhanced !== '') {
                        self::sendTelegram($token, $chatId, $enhanced, true);
                    } else {
                        $title = $payload['title'] ?? 'WakeLab';
                        $body  = $payload['body']  ?? '';
                        $text  = "<b>" . htmlspecialchars($title) . "</b>\n" . htmlspecialchars($body);
                        self::sendTelegram($token, $chatId, $text, true);
                    }
                } catch (Throwable) {}
            }
        }

        // ── Email ─────────────────────────────────────────────────
        if (self::channelEnabled($pdo, 'email', $event)) {
            $cfg = self::emailConfig($pdo);
            if ($cfg['host'] !== '' && $cfg['to'] !== '') {
                try {
                    $subject = ($payload['title'] ?? 'WakeLab') . ' — ' . ($payload['body'] ?? '');
                    $html    = self::emailHtml($payload, $cfg['wakelab_url'] ?? '');
                    self::sendEmail($cfg, $subject, $html);
                } catch (Throwable) {}
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Telegram
    // ─────────────────────────────────────────────────────────────

    /**
     * Envía un mensaje Telegram.
     * @param bool $html  true = parse_mode HTML, false = texto plano (para mensajes mejorados por IA)
     * @return array ['code' => int, 'body' => string]
     * @throws RuntimeException si curl falla
     */
    public static function sendTelegram(string $token, string $chatId, string $text, bool $html = true): array
    {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($html) $params['parse_mode'] = 'HTML';

        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new RuntimeException("Telegram curl error: {$err}");

        $decoded = json_decode($resp, true);
        if (!($decoded['ok'] ?? false)) {
            throw new RuntimeException("Telegram API error: " . ($decoded['description'] ?? $resp));
        }
        return ['code' => $code, 'body' => $resp];
    }

    /**
     * Genera el mensaje Telegram para el evento dado.
     * Intenta IA primero (si está habilitada); si falla o está off, usa plantillas.
     * Returns '' → caller envía mensaje plano original.
     */
    public static function enhanceTelegramMessage(PDO $pdo, array $payload, string $event = ''): string
    {
        if ($event === '') return '';

        // ── Intentar IA ───────────────────────────────────────────
        if (self::setting($pdo, 'ai_enabled', '0') === '1') {
            try {
                require_once __DIR__ . '/ai.php';
                $aiMsg = WakeAI::generate($pdo, array_merge($payload, ['event' => $event]));
                if ($aiMsg !== '') return $aiMsg;
            } catch (Throwable $e) {
                error_log('[WakeLab notify] AI failed: ' . $e->getMessage());
            }
        }

        // ── Fallback: plantillas desde DB ────────────────────────
        // Para server_down con acción intencional, el template genérico ("No activity") no aplica —
        // el JS ya construyó un título correcto ("apagado por schedule", etc.)
        $intentionalShutdowns = ['manual_shutdown','schedule_shutdown','idle_shutdown',
                                 'ups_shutdown','ups_shutdown_timer','host_shutdown'];
        $pa = $payload['pending_action'] ?? null;
        if ($event === 'server_down' && in_array($pa, $intentionalShutdowns)) return '';

        $raw = self::setting($pdo, "tpl_{$event}", '');
        if ($raw === '') return '';
        $variants = array_values(array_filter(array_map('trim', explode("\n---\n", $raw))));
        if (empty($variants)) return '';
        return self::fillTemplate($variants[array_rand($variants)], $payload);
    }

    /**
     * Replace all supported placeholders in a template string.
     * Available: {title} {body} {hostname} {ip} {datetime} {date} {time}
     *            {saludo} {momento} {diasemana}
     */
    public static function fillTemplate(string $tpl, array $payload): string
    {
        $tz = getenv('TZ') ?: 'America/Argentina/Buenos_Aires';
        date_default_timezone_set($tz);

        $hostname = $payload['hostname'] ?? self::extractHostname($payload['title'] ?? '');
        $ip       = $payload['ip']       ?? '';

        $h = (int)date('G');
        if ($h >= 6 && $h < 12)      { $saludo = 'Buenos días';   $momento = 'a la mañana'; }
        elseif ($h >= 12 && $h < 15) { $saludo = 'Buenas tardes'; $momento = 'al mediodía'; }
        elseif ($h >= 15 && $h < 20) { $saludo = 'Buenas tardes'; $momento = 'a la tarde'; }
        elseif ($h >= 20)             { $saludo = 'Buenas noches'; $momento = 'a la noche'; }
        else                          { $saludo = 'Buenas noches'; $momento = 'de madrugada'; }

        $dias      = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
        $diasemana = $dias[(int)date('w')];

        return str_replace(
            ['{title}','{body}','{hostname}','{ip}','{datetime}','{date}','{time}','{saludo}','{momento}','{diasemana}'],
            [
                $payload['title'] ?? '',
                $payload['body']  ?? '',
                $hostname, $ip,
                date('d/m/Y H:i'), date('d/m/Y'), date('H:i'),
                $saludo, $momento, $diasemana,
            ],
            $tpl
        );
    }

    /** Extrae hostname de un título como "🔴 pve01 offline" → "pve01" */
    private static function extractHostname(string $title): string
    {
        $stripped = trim(preg_replace('/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27FF}\s]+/u', '', $title));
        if (preg_match('/^([a-zA-Z0-9][\w.\-]*)/u', $stripped, $m)) return $m[1];
        return '';
    }


    // ─────────────────────────────────────────────────────────────
    // Email
    // ─────────────────────────────────────────────────────────────

    /**
     * Envía un email usando SmtpMailer.
     * @throws RuntimeException si falla la conexión o autenticación SMTP
     */
    public static function sendEmail(array $cfg, string $subject, string $html): void
    {
        require_once __DIR__ . '/lib/SmtpMailer.php';
        $m = new SmtpMailer(
            $cfg['host'],
            (int) ($cfg['port'] ?? 587),
            $cfg['secure'] ?? 'tls'
        );
        if (($cfg['user'] ?? '') !== '') {
            $m->auth($cfg['user'], $cfg['pass'] ?? '');
        }
        $m->send(
            $cfg['from']      ?? $cfg['user'] ?? '',
            $cfg['from_name'] ?? 'WakeLab',
            $cfg['to'],
            $subject,
            $html
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers internos
    // ─────────────────────────────────────────────────────────────

    /** Verifica si un canal está habilitado globalmente Y para el evento dado */
    private static function channelEnabled(PDO $pdo, string $channel, string $event): bool
    {
        if (self::setting($pdo, "{$channel}_enabled", '0') !== '1') return false;
        // 'test' siempre pasa (omite toggle de evento)
        if ($event === 'test') return true;
        return self::setting($pdo, "notify_event_{$event}", '1') === '1';
    }

    /** Lee un setting de la DB (sin cache — notify.php puede cargarse en CLI) */
    private static function setting(PDO $pdo, string $key, string $default): string
    {
        // Si getSetting() global existe (api.php), úsala para cache
        if (function_exists('getSetting')) {
            return getSetting($pdo, $key, $default);
        }
        try {
            $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
            $s->execute([$key]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ? (string)$row['value'] : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    /** Lee la configuración SMTP desde settings */
    private static function emailConfig(PDO $pdo): array
    {
        $keys = ['host','port','secure','user','pass'];
        $cfg  = [];
        foreach ($keys as $k) {
            $cfg[$k] = self::setting($pdo, "email_smtp_{$k}", $k === 'port' ? '587' : ($k === 'secure' ? 'tls' : ''));
        }
        $cfg['to']        = self::setting($pdo, 'email_to',          '');
        $cfg['from']      = self::setting($pdo, 'email_from',         $cfg['user'] ?? '');
        $cfg['from_name'] = self::setting($pdo, 'email_from_name',    'WakeLab');
        $cfg['wakelab_url'] = rtrim(self::setting($pdo, 'wakelab_url', ''), '/');
        return $cfg;
    }


    /** HTML de test para el botón "Probar" de la UI (requiere $pdo para leer wakelab_url) */
    public static function testEmailHtml(string $wakeLabUrl = ''): string
    {
        return self::emailHtml([
            'title' => '🔔 WakeLab — test',
            'body'  => 'Las notificaciones por email funcionan correctamente.',
            'tag'   => 'test',
        ], $wakeLabUrl);
    }

    /** Construye el cuerpo HTML del email */
    private static function emailHtml(array $payload, string $wakeLabUrl = ''): string
    {
        $title     = htmlspecialchars($payload['title'] ?? 'WakeLab');
        $body      = htmlspecialchars($payload['body']  ?? '');
        $timestamp = date('d/m/Y H:i:s');

        $year  = date('Y');

        // Link solo si hay una URL base configurada
        $link = '';
        if ($wakeLabUrl !== '') {
            $href = htmlspecialchars($wakeLabUrl);
            $link = "<tr><td style='padding:10px 0 0'>
                       <a href='{$href}'
                          style='display:inline-block;padding:8px 18px;background:#238636;color:#fff;
                                 border-radius:6px;font-size:12px;font-weight:600;text-decoration:none'>
                         Abrir WakeLab →
                       </a>
                     </td></tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#0d1117;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0d1117;padding:20px 16px">
    <tr><td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:480px">

        <!-- Header -->
        <tr>
          <td style="padding:0 0 10px">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#484f58">
                  WakeLab
                </td>
                <td align="right" style="font-size:11px;color:#484f58">{$timestamp}</td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Card -->
        <tr>
          <td style="background:#161b22;border:1px solid #30363d;border-radius:10px;padding:18px 20px">
            <table width="100%" cellpadding="0" cellspacing="0">

              <!-- Title -->
              <tr>
                <td style="font-size:17px;font-weight:700;color:#e6edf3;padding-bottom:8px;
                           border-bottom:1px solid #21262d">
                  {$title}
                </td>
              </tr>

              <!-- Body -->
              <tr>
                <td style="padding:10px 0 0;font-size:13px;color:#8b949e;line-height:1.6">
                  {$body}
                </td>
              </tr>

              {$link}

            </table>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="padding:10px 0 0;text-align:center;font-size:11px;color:#484f58">
            WakeLab v1.0 &middot; Mariano Blanco &middot; {$year}
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
