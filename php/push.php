<?php
/**
 * WakeLab — Web Push / VAPID
 * Standalone PHP 8.1+ — no Composer required.
 * Implements aes128gcm content encoding (RFC 8291 / RFC 8292).
 */

class WakePush {

    // ─── Key generation ────────────────────────────────────────────────────

    /** Generate VAPID key pair: ['public'=>base64url(65b), 'private'=>base64url(32b)] */
    public static function generateKeys(): array {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $det = openssl_pkey_get_details($key);
        $pub = "\x04"
             . str_pad($det['ec']['x'], 32, "\x00", STR_PAD_LEFT)
             . str_pad($det['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $prv = str_pad($det['ec']['d'], 32, "\x00", STR_PAD_LEFT);
        return ['public' => self::b64u($pub), 'private' => self::b64u($prv)];
    }

    // ─── Send to one subscription ───────────────────────────────────────────

    /** Returns HTTP status code: 201=ok, 410/404=expired */
    public static function send(
        array  $sub,
        array  $payload,
        string $vapidPub,
        string $vapidPriv,
        string $subject
    ): int {
        $body = self::encrypt(
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $sub['p256dh'],
            $sub['auth']
        );
        $jwt = self::buildJwt($sub['endpoint'], $vapidPub, $vapidPriv, $subject);

        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                "Authorization: vapid t=$jwt,k=$vapidPub",
                'TTL: 86400',
            ],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    /** Send to all subscriptions. Removes expired. Pass event='test' to bypass settings check. */
    public static function notifyAll(PDO $pdo, array $payload, string $event): void {
        if ($event !== 'test') {
            if (self::dbGet($pdo, 'push_enabled',        '1') !== '1') return;
            if (self::dbGet($pdo, "notify_event_$event", '1') !== '1') return;
        }
        $vapidPub  = self::dbGet($pdo, 'vapid_public',  '');
        $vapidPriv = self::dbGet($pdo, 'vapid_private', '');
        $subject   = self::dbGet($pdo, 'vapid_subject', 'mailto:admin@localhost');
        if (!$vapidPub || !$vapidPriv) return;

        try {
            $subs = $pdo->query("SELECT * FROM push_subscriptions")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { return; }

        $expired = [];
        foreach ($subs as $sub) {
            try {
                $code = self::send($sub, $payload, $vapidPub, $vapidPriv, $subject);
                if (in_array($code, [404, 410])) $expired[] = (int) $sub['id'];
            } catch (Throwable) {}
        }
        if ($expired) {
            $in = implode(',', $expired);
            try { $pdo->exec("DELETE FROM push_subscriptions WHERE id IN ($in)"); } catch (Throwable) {}
        }
    }

    // ─── VAPID JWT (ES256) ─────────────────────────────────────────────────

    private static function buildJwt(
        string $endpoint, string $pub, string $priv, string $sub
    ): string {
        $aud = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $hdr = self::b64u('{"typ":"JWT","alg":"ES256"}');
        $pay = self::b64u(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $sub]));
        $msg = "$hdr.$pay";

        $key = openssl_pkey_get_private(self::privToPem(self::b64uDec($priv)));
        openssl_sign($msg, $der, $key, OPENSSL_ALGO_SHA256);

        return "$msg." . self::b64u(self::derToRaw($der));
    }

    // ─── aes128gcm payload encryption (RFC 8291) ───────────────────────────

    private static function encrypt(string $plain, string $p256dh, string $auth): string {
        $subPub  = self::b64uDec($p256dh);  // 65 bytes
        $authRaw = self::b64uDec($auth);    // 16 bytes

        // Ephemeral ECDH key pair
        $eph    = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $det    = openssl_pkey_get_details($eph);
        $ephPub = "\x04"
                . str_pad($det['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                . str_pad($det['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // ECDH shared secret (PHP 8.1+)
        $shared = openssl_pkey_derive(self::pubToKey($subPub), $eph, 32);
        if ($shared === false) throw new \RuntimeException('ECDH: ' . openssl_error_string());

        // HKDF-Extract(salt=auth_secret, IKM=ecdh_secret)
        $prk = hash_hmac('sha256', $shared, $authRaw, true);

        // HKDF-Expand(PRK, "WebPush: info\x00" || subscriber_pub || ephemeral_pub, 32)
        $ikm = substr(
            hash_hmac('sha256', "WebPush: info\x00" . $subPub . $ephPub . "\x01", $prk, true),
            0, 32
        );

        // Random salt
        $salt = random_bytes(16);

        // HKDF-Extract(salt=salt, IKM=ikm)
        $prk2 = hash_hmac('sha256', $ikm, $salt, true);

        // CEK (16 bytes) and nonce (12 bytes)
        $cek   = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk2, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01",     $prk2, true), 0, 12);

        // AES-128-GCM: plaintext + \x02 (single-record pad delimiter)
        $tag    = '';
        $cipher = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) throw new \RuntimeException('AES-GCM: ' . openssl_error_string());

        // Record header: salt (16) + record_size uint32be (4) + keyid_len (1) + ephemeral_pub (65)
        return $salt . pack('N', 4096) . chr(65) . $ephPub . $cipher . $tag;
    }

    // ─── Key format helpers ────────────────────────────────────────────────

    private static function privToPem(string $d): string {
        $der = "\x30\x31"
             . "\x02\x01\x01"
             . "\x04\x20" . $d
             . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        return "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
    }

    private static function pubToKey(string $pub): \OpenSSLAsymmetricKey {
        $der = "\x30\x59"
             . "\x30\x13"
             . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
             . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
             . "\x03\x42\x00" . $pub;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (!$key) throw new \RuntimeException('pubToKey: ' . openssl_error_string());
        return $key;
    }

    private static function derToRaw(string $der): string {
        $p = 0;
        if (ord($der[$p++]) !== 0x30) throw new \RuntimeException('DER: not SEQUENCE');
        $l = ord($der[$p++]);
        if ($l >= 0x80) $p += $l & 0x7f;
        $p++; $rLen = ord($der[$p++]);
        $r = substr($der, $p, $rLen); $p += $rLen;
        $p++; $sLen = ord($der[$p++]);
        $s = substr($der, $p, $sLen);
        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
             . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    // ─── Base64url ─────────────────────────────────────────────────────────

    public static function b64u(string $d): string {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    public static function b64uDec(string $d): string {
        $pad = strlen($d) % 4;
        if ($pad) $d .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($d, '-_', '+/'));
    }

    private static function dbGet(PDO $pdo, string $key, string $default = ''): string {
        try {
            $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
            $s->execute([$key]);
            $r = $s->fetch();
            return $r ? (string) $r['value'] : $default;
        } catch (Throwable) { return $default; }
    }
}
