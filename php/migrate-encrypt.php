#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Validaciones previas ──────────────────────────────────────────────────────

if (WAKELAB_SECRET === '') {
    echo "ERROR: WAKELAB_SECRET no está definido. Definilo como variable de entorno antes de ejecutar.\n";
    echo "  export WAKELAB_SECRET='tu-clave-secreta'\n";
    exit(1);
}

$dryRun = in_array('--dry-run', $argv);

echo "========================================\n";
echo "WakeLab — Migración de cifrado en DB\n";
echo "========================================\n";
if ($dryRun) {
    echo "[DRY RUN] No se escribirá nada en la DB.\n";
}
echo "\n";

$total   = 0;
$skipped = 0;
$errors  = 0;

// ── Helper ────────────────────────────────────────────────────────────────────

function processValue(string $label, string $val, bool $dryRun, callable $save): void {
    global $total, $skipped, $errors;

    if ($val === '') {
        echo "  SKIP  $label (vacío)\n";
        $skipped++;
        return;
    }
    if (str_starts_with($val, 'enc:')) {
        echo "  SKIP  $label (ya cifrado)\n";
        $skipped++;
        return;
    }

    $encrypted = wlEncrypt($val);
    if (!str_starts_with($encrypted, 'enc:')) {
        echo "  ERROR $label — wlEncrypt devolvió sin prefijo enc: (¿secret vacío?)\n";
        $errors++;
        return;
    }

    if ($dryRun) {
        echo "  [DRY] $label — se cifraría (" . strlen($val) . " chars → " . strlen($encrypted) . " chars)\n";
    } else {
        try {
            $save($encrypted);
            echo "  OK    $label\n";
        } catch (Throwable $e) {
            echo "  ERROR $label — " . $e->getMessage() . "\n";
            $errors++;
            return;
        }
    }
    $total++;
}

// ── 1. settings: claves sensibles ────────────────────────────────────────────

echo "--- settings ---\n";

$sensitiveKeys = ['email_smtp_pass', 'telegram_token', 'vapid_private'];

$st = $pdo->query("SELECT `key`, `value` FROM settings ORDER BY `key`");
$upd = $pdo->prepare("UPDATE settings SET `value` = ? WHERE `key` = ?");

foreach ($st->fetchAll() as $row) {
    $key = $row['key'];
    $val = (string)$row['value'];

    $isSensitive = in_array($key, $sensitiveKeys, true)
        || preg_match('/^srv_\d+_ssh_pass$/', $key);

    if (!$isSensitive) continue;

    processValue("settings.$key", $val, $dryRun, function (string $enc) use ($upd, $key) {
        $upd->execute([$enc, $key]);
    });
}

// ── 2. api_tokens: token_secret ───────────────────────────────────────────────

echo "\n--- api_tokens ---\n";

$tokens = $pdo->query("SELECT server_id, token_secret FROM api_tokens ORDER BY server_id")->fetchAll();
$updTok = $pdo->prepare("UPDATE api_tokens SET token_secret = ? WHERE server_id = ?");

foreach ($tokens as $tok) {
    $sid = (int)$tok['server_id'];
    $val = (string)($tok['token_secret'] ?? '');

    processValue("api_tokens[server_id=$sid].token_secret", $val, $dryRun, function (string $enc) use ($updTok, $sid) {
        $updTok->execute([$enc, $sid]);
    });
}

// ── Resumen ───────────────────────────────────────────────────────────────────

echo "\n========================================\n";
if ($dryRun) {
    echo "[DRY RUN] $total valor(es) para cifrar, $skipped omitido(s), $errors error(es).\n";
    echo "Ejecutá sin --dry-run para aplicar los cambios.\n";
} else {
    echo "$total valor(es) cifrados, $skipped omitido(s), $errors error(es).\n";
}
echo "========================================\n";

exit($errors > 0 ? 1 : 0);
