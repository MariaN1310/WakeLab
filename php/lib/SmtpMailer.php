<?php
/**
 * SmtpMailer — cliente SMTP minimal sin dependencias externas
 *
 * Soporta:
 *   - STARTTLS (port 587, secure='tls')
 *   - SSL implícito (port 465, secure='ssl')
 *   - Sin cifrado (port 25, secure='none')
 *   - AUTH LOGIN
 *   - Cuerpo multipart/alternative (text + HTML)
 */
class SmtpMailer
{
    private mixed  $socket = null;
    private string $user   = '';
    private string $pass   = '';

    public function __construct(
        private string $host,
        private int    $port   = 587,
        private string $secure = 'tls'   // tls | ssl | none
    ) {}

    /** Almacena credenciales; se envían tras TLS si corresponde */
    public function auth(string $user, string $pass): void
    {
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * Envía un email HTML (+ texto plano alternativo automático).
     *
     * @throws RuntimeException ante cualquier fallo SMTP
     */
    public function send(
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $htmlBody
    ): void {
        $this->connect();
        $this->ehlo();

        if ($this->secure === 'tls') {
            $this->cmd("STARTTLS", 220);
            if (!stream_socket_enable_crypto(
                $this->socket, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) throw new RuntimeException("STARTTLS: no se pudo activar cifrado");
            $this->ehlo();   // re-EHLO tras TLS
        }

        if ($this->user !== '') $this->doAuth();

        $this->cmd("MAIL FROM:<{$from}>", 250);
        $this->cmd("RCPT TO:<{$to}>",     250);
        $this->cmd("DATA",                354);
        $this->sendBody($this->buildMime($from, $fromName, $to, $subject, $htmlBody));
        $this->cmd("QUIT", 221);
        fclose($this->socket);
        $this->socket = null;
    }

    // ── privados ──────────────────────────────────────────────────

    private function connect(): void
    {
        $prefix = ($this->secure === 'ssl') ? 'ssl://' : 'tcp://';
        $errno  = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            "{$prefix}{$this->host}:{$this->port}",
            $errno, $errstr, 15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ]])
        );
        if (!$this->socket) {
            throw new RuntimeException("SMTP: no se pudo conectar a {$this->host}:{$this->port} — {$errstr} ({$errno})");
        }
        stream_set_timeout($this->socket, 15);
        $this->expect(220);
    }

    private function ehlo(): void
    {
        $this->cmd("EHLO " . (gethostname() ?: 'localhost'), 250);
    }

    private function doAuth(): void
    {
        $this->cmd("AUTH LOGIN",                      334);
        $this->cmd(base64_encode($this->user),        334);
        $this->cmd(base64_encode($this->pass),        235);
    }

    private function cmd(string $cmd, int $expectCode): string
    {
        fwrite($this->socket, "{$cmd}\r\n");
        return $this->expect($expectCode);
    }

    private function expect(int $code): string
    {
        $response = '';
        while ($line = fgets($this->socket, 512)) {
            $response .= $line;
            // Último line de respuesta multi-línea: 4º char es espacio
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $got = (int) substr($response, 0, 3);
        if ($got !== $code) {
            throw new RuntimeException("SMTP esperaba {$code}, recibió {$got}: " . trim($response));
        }
        return $response;
    }

    private function sendBody(string $message): void
    {
        // Escapar líneas que empiezan con punto (SMTP transparency)
        $message = preg_replace('/^\.$/m', '..', $message);
        fwrite($this->socket, $message . "\r\n.\r\n");
        $this->expect(250);
    }

    private function buildMime(
        string $from, string $fromName,
        string $to, string $subject, string $htmlBody
    ): string {
        $boundary = 'WL-' . bin2hex(random_bytes(8));
        $date     = date('r');
        $msgId    = '<' . time() . '.' . bin2hex(random_bytes(4)) . '@wakelab>';
        $fromEnc  = $this->mimeHdr($fromName);
        $subjEnc  = $this->mimeHdr($subject);

        // Texto plano: quitar tags, convertir saltos comunes
        $plain = strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h1>', '</h2>', '</h3>'],
                        "\n", $htmlBody)
        );
        $plain = html_entity_decode(preg_replace('/\n{3,}/', "\n\n", $plain), ENT_QUOTES, 'UTF-8');

        $lines = [
            "Date: {$date}",
            "From: {$fromEnc} <{$from}>",
            "To: {$to}",
            "Subject: {$subjEnc}",
            "Message-ID: {$msgId}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            "",
            "--{$boundary}",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: quoted-printable",
            "",
            quoted_printable_encode($plain),
            "",
            "--{$boundary}",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: quoted-printable",
            "",
            quoted_printable_encode($htmlBody),
            "",
            "--{$boundary}--",
        ];

        return implode("\r\n", $lines);
    }

    private function mimeHdr(string $str): string
    {
        // RFC 2047 Base64 encoding para headers
        if (preg_match('/[^\x20-\x7E]/', $str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return $str;
    }
}
