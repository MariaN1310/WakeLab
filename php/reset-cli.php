<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Solo disponible desde CLI.');
}

$pass = $argv[1] ?? '';
if (strlen($pass) < 8) {
    echo "Error: la contraseña debe tener al menos 8 caracteres.\n";
    echo "Uso: php reset-cli.php <nueva_password>\n";
    exit(1);
}

require_once __DIR__ . '/auth.php';
$pdo = getAuthDB();

$user = $pdo->query("SELECT id, usuario FROM usuarios LIMIT 1")->fetch();
if (!$user) {
    echo "Error: no existe ningún usuario en la base de datos.\n";
    exit(1);
}

$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare("UPDATE usuarios SET contrasena = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")
    ->execute([$hash, $user['id']]);

echo "✓ Contraseña actualizada para el usuario: {$user['usuario']}\n";
