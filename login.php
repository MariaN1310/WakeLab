<?php
session_start();
require 'php/auth.php';

if (tryRestoreSession()) {
	header('Location: index.php');
	exit;
}

// Sin usuarios → ir a registro
$userCount = (int)getAuthDB()->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
if ($userCount === 0) {
	header('Location: registro.php');
	exit;
}

$error = '';

// ── Rate limiting por IP ────────────────────────────────────
// Máx 10 intentos fallidos en 10 minutos. Bloqueo de 10 min tras alcanzar el límite.
function getRateKey(): string {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	return 'wakelab_login_' . preg_replace('/[^a-f0-9:.]/', '', explode(',', $ip)[0]);
}
function checkRateLimit(): ?string {
	$key  = getRateKey();
	$file = sys_get_temp_dir() . '/' . $key . '.json';
	$now  = time();
	$data = ['count' => 0, 'window_start' => $now, 'blocked_until' => 0];

	if (file_exists($file)) {
		$raw = json_decode(file_get_contents($file), true);
		if (is_array($raw)) $data = $raw;
	}
	if ($data['blocked_until'] > $now) {
		$wait = ceil(($data['blocked_until'] - $now) / 60);
		return "Too many failed attempts. Try again in {$wait} min.";
	}
	if ($now - $data['window_start'] > 600) {
		$data = ['count' => 0, 'window_start' => $now, 'blocked_until' => 0];
	}
	return null;
}
function recordFailedAttempt(): void {
	$key  = getRateKey();
	$file = sys_get_temp_dir() . '/' . $key . '.json';
	$now  = time();
	$data = ['count' => 0, 'window_start' => $now, 'blocked_until' => 0];

	if (file_exists($file)) {
		$raw = json_decode(file_get_contents($file), true);
		if (is_array($raw)) $data = $raw;
	}
	if ($now - $data['window_start'] > 600) {
		$data = ['count' => 0, 'window_start' => $now, 'blocked_until' => 0];
	}
	$data['count']++;
	if ($data['count'] >= 10) {
		$data['blocked_until'] = $now + 600;
	}
	file_put_contents($file, json_encode($data), LOCK_EX);
}
function clearRateLimit(): void {
	$file = sys_get_temp_dir() . '/' . getRateKey() . '.json';
	if (file_exists($file)) @unlink($file);
}
// ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	verifyCsrf();

	$rateLimitMsg = checkRateLimit();
	if ($rateLimitMsg !== null) {
		$error = $rateLimitMsg;
	} else {
		$usuario  = trim($_POST['usuario']   ?? '');
		$password = $_POST['contrasena'] ?? '';

		if ($usuario !== '' && $password !== '') {
			$s = getAuthDB()->prepare(
				"SELECT id, usuario, email, contrasena, role FROM usuarios WHERE usuario = ?"
			);
			$s->execute([$usuario]);
			$user = $s->fetch();

			if ($user && password_verify($password, $user['contrasena'])) {
				clearRateLimit();
				loginUser((int)$user['id'], $user['usuario'], $user['email'], $user['role']);
				header('Location: index.php');
				exit;
			}
		}
		recordFailedAttempt();
		$error = 'Incorrect username and/or password';
	}
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>WakeLab — Sign In</title>
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
		<h1 class="auth-title">Sign In</h1>

		<?php if ($error): ?>
		<div class="auth-error"><?= htmlspecialchars($error) ?></div>
		<?php endif; ?>

		<form method="post" novalidate>
			<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

			<div class="mb-3">
				<label class="form-label" for="lg_usuario">Username</label>
				<div class="input-group">
					<span class="input-group-text"><i class="bi bi-person"></i></span>
					<input type="text" class="form-control" id="lg_usuario" name="usuario"
					       placeholder="username" required autofocus autocomplete="username">
				</div>
			</div>

			<div class="mb-4">
				<label class="form-label" for="lg_password">Password</label>
				<div class="input-group">
					<span class="input-group-text"><i class="bi bi-lock"></i></span>
					<input type="password" class="form-control" id="lg_password" name="contrasena"
					       placeholder="••••••••" required autocomplete="current-password">
					<button type="button" class="input-group-text" onclick="togglePass('lg_password',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
				</div>
			</div>

			<button type="submit" class="btn btn-primary w-100">Sign In</button>
		</form>

		<div class="auth-footer">
			<a href="forgot_password.php">Forgot your password?</a>
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
