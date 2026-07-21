<?php
session_start();
require 'php/auth.php';

if (tryRestoreSession()) {
	header('Location: index.php');
	exit;
}

// Solo disponible si no existe ningún usuario todavía.
// Devuelve 404 (no redirect) para no revelar que la página existe.
$userCount = (int)getAuthDB()->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
if ($userCount > 0) {
	http_response_code(404);
	exit;
}

$error    = '';
$formData = ['email' => '', 'usuario' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	verifyCsrf();

	$email  = trim($_POST['email']              ?? '');
	$usu    = trim($_POST['usuario']             ?? '');
	$pass1  = $_POST['contrasena']              ?? '';
	$pass2  = $_POST['contrasenaConfirmar']     ?? '';

	$formData = ['email' => $email, 'usuario' => $usu];

	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$error = 'Invalid email address';
	} elseif (!preg_match('/^[A-Za-z0-9]{5,40}$/', $usu)) {
		$error = 'Username: letters and numbers only, 5–40 characters';
	} elseif (strlen($pass1) < 8) {
		$error = 'Password must be at least 8 characters';
	} elseif ($pass1 !== $pass2) {
		$error = 'Passwords do not match';
	} else {
		$pdo   = getAuthDB();
		$check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ? OR email = ?");
		$check->execute([$usu, $email]);

		if ($check->fetch()) {
			$error = 'That username or email is already registered';
		} else {
			$hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
			$ins  = $pdo->prepare(
				"INSERT INTO usuarios (usuario, email, contrasena, role) VALUES (?, ?, ?, 'admin')"
			);
			$ins->execute([$usu, $email, $hash]);
			loginUser((int)$pdo->lastInsertId(), $usu, $email, 'admin');
			header('Location: index.php');
			exit;
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
	<title>WakeLab — Create Account</title>
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
		<h1 class="auth-title">Create Account</h1>

		<?php if ($error): ?>
		<div class="auth-error"><?= htmlspecialchars($error) ?></div>
		<?php endif; ?>

		<form method="post" novalidate>
			<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

			<div class="mb-3">
				<label class="form-label" for="reg_email">Email</label>
				<div class="input-group">
					<span class="input-group-text"><i class="bi bi-envelope"></i></span>
					<input type="email" class="form-control" id="reg_email" name="email"
					       placeholder="email@example.com" required autocomplete="email"
					       value="<?= htmlspecialchars($formData['email']) ?>">
				</div>
			</div>

			<div class="mb-3">
				<label class="form-label" for="reg_usuario">Username</label>
				<div class="input-group">
					<span class="input-group-text"><i class="bi bi-person"></i></span>
					<input type="text" class="form-control" id="reg_usuario" name="usuario"
					       placeholder="username" required minlength="5" maxlength="40"
					       pattern="[A-Za-z0-9]+" autocomplete="username"
					       value="<?= htmlspecialchars($formData['usuario']) ?>">
				</div>
				<div class="form-hint">Letters and numbers only · 5–40 characters</div>
			</div>

			<div class="mb-3">
				<label class="form-label" for="reg_pass">Password</label>
				<div class="input-group">
					<span class="input-group-text"><i class="bi bi-lock"></i></span>
					<input type="password" class="form-control" id="reg_pass" name="contrasena"
					       placeholder="••••••••" required minlength="8" autocomplete="new-password">
					<button type="button" class="input-group-text" onclick="togglePass('reg_pass',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
				</div>
				<div class="form-hint">Minimum 8 characters</div>
			</div>

			<div class="mb-4">
				<label class="form-label" for="reg_pass2">Confirm Password</label>
				<div class="input-group">
					<span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
					<input type="password" class="form-control" id="reg_pass2" name="contrasenaConfirmar"
					       placeholder="••••••••" required autocomplete="new-password">
					<button type="button" class="input-group-text" onclick="togglePass('reg_pass2',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
				</div>
			</div>

			<div id="pass-mismatch" style="display:none;color:var(--bs-danger);font-size:12px;margin-top:-12px;margin-bottom:12px">
				Passwords do not match
			</div>
			<button type="submit" class="btn btn-primary w-100">Create Account</button>
		</form>
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

			const pass1 = document.getElementById('reg_pass');
			const pass2 = document.getElementById('reg_pass2');
			const mismatch = document.getElementById('pass-mismatch');

			function checkMatch() {
				const bad = pass2.value && pass1.value !== pass2.value;
				mismatch.style.display = bad ? '' : 'none';
				pass2.setCustomValidity(bad ? 'Passwords do not match' : '');
			}
			pass1.addEventListener('input', checkMatch);
			pass2.addEventListener('input', checkMatch);

			document.querySelector('form').addEventListener('submit', function(e) {
				if (pass1.value !== pass2.value) {
					e.preventDefault();
					mismatch.style.display = '';
					pass2.focus();
				}
			});
		})();
	</script>
</body>
</html>
