<?php
require_once __DIR__ . '/config.php';

function getAuthDB(): PDO {
	static $pdo = null;
	if ($pdo) return $pdo;
	$pdo = new PDO(
		'mysql:host=' . WAKELAB_DB_HOST . ';port=' . WAKELAB_DB_PORT . ';dbname=' . WAKELAB_DB_NAME . ';charset=utf8mb4',
		WAKELAB_DB_USER, WAKELAB_DB_PASS,
		[
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		]
	);
	return $pdo;
}

// ── CSRF ─────────────────────────────────────────────────────

function csrfToken(): string {
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
	$tok = $_POST['csrf_token'] ?? '';
	if (!hash_equals($_SESSION['csrf_token'] ?? '', $tok)) {
		http_response_code(403);
		die('Error de seguridad: token inválido. <a href="">Reintentar</a>');
	}
}

// ── Cookie helpers ────────────────────────────────────────────

function _isHttps(): bool {
	return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
	    || intval($_SERVER['SERVER_PORT'] ?? 80) === 443;
}

function _setSessionCookie(string $token): void {
	setcookie('wl_session', $token, [
		'expires'  => time() + 60 * 60 * 24 * 365,
		'path'     => '/',
		'secure'   => _isHttps(),
		'httponly' => true,
		'samesite' => 'Lax',
	]);
}

function _clearSessionCookie(): void {
	setcookie('wl_session', '', [
		'expires'  => time() - 3600,
		'path'     => '/',
		'httponly' => true,
	]);
}

// ── Auth core ─────────────────────────────────────────────────

function loginUser(int $userId, string $usuario, string $email, string $role): void {
	session_regenerate_id(true);
	$token = bin2hex(random_bytes(32));

	// Guardar hash del token — la cookie tiene el valor original, la DB solo el hash
	getAuthDB()->prepare("UPDATE usuarios SET session_token = ? WHERE id = ?")
		->execute([hash('sha256', $token), $userId]);

	$_SESSION['logged']  = true;
	$_SESSION['id']      = $userId;
	$_SESSION['usuario'] = $usuario;
	$_SESSION['email']   = $email;
	$_SESSION['role']    = $role;

	_setSessionCookie($token);
}

function logoutUser(): void {
	if (!empty($_SESSION['id'])) {
		getAuthDB()->prepare("UPDATE usuarios SET session_token = NULL WHERE id = ?")
			->execute([$_SESSION['id']]);
	}
	session_destroy();
	_clearSessionCookie();
}

function tryRestoreSession(): bool {
	if (!empty($_SESSION['logged'])) return true;

	$token = $_COOKIE['wl_session'] ?? '';
	if (!$token || strlen($token) !== 64) return false;

	$s = getAuthDB()->prepare(
		"SELECT id, usuario, email, role FROM usuarios WHERE session_token = ?"
	);
	$s->execute([hash('sha256', $token)]);
	$user = $s->fetch();
	if (!$user) return false;

	// Rotar token — invalida usos anteriores de la cookie
	session_regenerate_id(true);
	$newToken = bin2hex(random_bytes(32));
	getAuthDB()->prepare("UPDATE usuarios SET session_token = ? WHERE id = ?")
		->execute([hash('sha256', $newToken), $user['id']]);

	$_SESSION['logged']  = true;
	$_SESSION['id']      = (int)$user['id'];
	$_SESSION['usuario'] = $user['usuario'];
	$_SESSION['email']   = $user['email'];
	$_SESSION['role']    = $user['role'];

	_setSessionCookie($newToken);
	return true;
}

function requireLogin(string $loginUrl = 'login.php'): void {
	if (!tryRestoreSession()) {
		header("Location: $loginUrl");
		exit;
	}
}
