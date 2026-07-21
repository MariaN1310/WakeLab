<?php
session_start();
require 'php/auth.php';

if (tryRestoreSession()) { header('Location: index.php'); exit; }

$pdo     = getAuthDB();
$token   = trim($_GET['token'] ?? '');
$message = '';
$isError = false;
$done    = false;

// Validar token
$user = null;
if ($token && strlen($token) === 64) {
    $s = $pdo->prepare(
        "SELECT id, usuario, email FROM usuarios
         WHERE reset_token = ? AND reset_expires > NOW()"
    );
    $s->execute([hash('sha256', $token)]);
    $user = $s->fetch();
}

if (!$user) {
    $message = 'This link is invalid or has expired. Please request a new one.';
    $isError = true;
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pass1 = $_POST['contrasena']        ?? '';
    $pass2 = $_POST['contrasenaConfirmar'] ?? '';

    if (strlen($pass1) < 8) {
        $message = 'Password must be at least 8 characters.';
        $isError = true;
    } elseif ($pass1 !== $pass2) {
        $message = 'Passwords do not match.';
        $isError = true;
    } else {
        $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            "UPDATE usuarios SET contrasena = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?"
        )->execute([$hash, $user['id']]);

        $done    = true;
        $message = 'Password updated. You can now sign in.';
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WakeLab — Set New Password</title>
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
        <h1 class="auth-title">Set New Password</h1>

        <?php if ($message): ?>
        <div class="<?= $isError ? 'auth-error' : 'auth-success' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($user && !$done): ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-3">
                <label class="form-label" for="rp_pass">New password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="rp_pass" name="contrasena"
                           placeholder="••••••••" required minlength="8" autofocus autocomplete="new-password">
                    <button type="button" class="input-group-text" onclick="togglePass('rp_pass',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
                </div>
                <div class="form-hint">Minimum 8 characters</div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="rp_pass2">Confirm password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" id="rp_pass2" name="contrasenaConfirmar"
                           placeholder="••••••••" required autocomplete="new-password">
                    <button type="button" class="input-group-text" onclick="togglePass('rp_pass2',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Save password</button>
        </form>
        <?php endif; ?>

        <div class="auth-footer">
            <?php if ($done): ?>
            <a href="login.php">Go to sign in →</a>
            <?php else: ?>
            <a href="forgot_password.php">Request a new link</a>
            <?php endif; ?>
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
            window.togglePass = function(id, btn) {
                const inp = document.getElementById(id);
                const show = inp.type === 'password';
                inp.type = show ? 'text' : 'password';
                btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            };
        })();
    </script>
</body>
</html>
