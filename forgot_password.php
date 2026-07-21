<?php
session_start();
require 'php/auth.php';

if (tryRestoreSession()) { header('Location: index.php'); exit; }

$pdo     = getAuthDB();
$message = '';
$isError = false;
$sent    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email address.';
        $isError = true;
    } else {
        // Buscar usuario — respuesta genérica para no revelar si existe
        $s = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $s->execute([$email]);
        $user = $s->fetch();

        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);

            // Guardar hash — el link lleva el token original, la DB solo el hash
            $pdo->prepare("UPDATE usuarios SET reset_token = ?, reset_expires = ? WHERE id = ?")
                ->execute([hash('sha256', $token), $expires, $user['id']]);

            $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base    = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $script  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
            $resetUrl = $base . $script . '/reset_password.php?token=' . $token;

            // Enviar email via SMTP configurado en WakeLab
            try {
                require_once 'php/db.php'; // DB principal (settings)
                require_once 'php/lib/SmtpMailer.php';

                $smtpHost   = getSetting($pdo, 'email_smtp_host',   '');
                $smtpPort   = (int)getSetting($pdo, 'email_smtp_port', '587');
                $smtpSecure = getSetting($pdo, 'email_smtp_secure', 'tls');
                $smtpUser   = getSetting($pdo, 'email_smtp_user',   '');
                $smtpPass   = getSetting($pdo, 'email_smtp_pass',   '');
                $fromEmail  = getSetting($pdo, 'email_from',        $smtpUser);
                $fromName   = getSetting($pdo, 'email_from_name',   'WakeLab');

                if (!$smtpHost) throw new RuntimeException('SMTP no configurado');

                $html = "
                <div style='font-family:sans-serif;max-width:480px;margin:0 auto'>
                  <h2 style='color:#58a6ff'>Password Reset — WakeLab</h2>
                  <p>We received a request to reset your password.</p>
                  <p>
                    <a href='{$resetUrl}' style='display:inline-block;background:#238636;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600'>
                      Reset password
                    </a>
                  </p>
                  <p style='color:#888;font-size:12px'>This link expires in 1 hour. If you did not request this, you can safely ignore this email.</p>
                </div>";

                $mailer = new SmtpMailer($smtpHost, $smtpPort, $smtpSecure);
                if ($smtpUser) $mailer->auth($smtpUser, $smtpPass);
                $mailer->send($fromEmail, $fromName, $email, 'Reset your password — WakeLab', $html);

            } catch (Throwable $e) {
                // Si el email falla, limpiar el token para no dejar basura
                $pdo->prepare("UPDATE usuarios SET reset_token = NULL, reset_expires = NULL WHERE id = ?")
                    ->execute([$user['id']]);
                $message = 'Failed to send email. Please check the SMTP settings in WakeLab.';
                $isError = true;
            }
        }

        if (!$isError) {
            $sent    = true;
            $message = 'If that email is registered, you will receive a reset link shortly.';
        }
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WakeLab — Forgot Password</title>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <link rel="icon" type="image/png" href="favicon.ico">
    <link rel="stylesheet" href="assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-body">

    <button class="auth-theme-toggle" id="theme-btn" onclick="toggleTheme()" title="toggle theme">☀︎</button>

    <div class="auth-card">
        <div class="auth-logo">
            <img src="assets/icons/web-app-manifest-192x192.png" alt="WakeLab">
            <div class="brand">Wake<span>Lab</span></div>
        </div>
        <h1 class="auth-title">Forgot Password</h1>

        <?php if ($message): ?>
        <div class="<?= $isError ? 'auth-error' : 'auth-success' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!$sent): ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-4">
                <label class="form-label" for="fp_email">Account email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="fp_email" name="email"
                           placeholder="email@example.com" required autofocus autocomplete="email">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Send reset link</button>
        </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="login.php">← Back to sign in</a>
        </div>
    </div>

    <script>
        (function () {
            const btn = document.getElementById('theme-btn');
            if (document.documentElement.classList.contains('light')) btn.textContent = '☾';
            window.toggleTheme = function () {
                const isLight = document.documentElement.classList.toggle('light');
                localStorage.setItem('theme', isLight ? 'light' : 'dark');
                btn.textContent = isLight ? '☾' : '☀︎';
            };
        })();
    </script>
</body>
</html>
