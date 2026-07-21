<?php
require_once __DIR__ . '/config.php';

try {
	$pdo = new PDO(
		'mysql:host=' . WAKELAB_DB_HOST . ';port=' . WAKELAB_DB_PORT . ';dbname=' . WAKELAB_DB_NAME . ';charset=utf8mb4',
		WAKELAB_DB_USER,
		WAKELAB_DB_PASS,
		[
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		]
	);
} catch (PDOException $e) {
	http_response_code(500);
	die(json_encode(['status' => 'error', 'message' => 'DB connection failed']));
}
