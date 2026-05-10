<?php
require_once __DIR__ . '/env.php';

// Errores: loguear en /logs/php_errors.log, no mostrar en pantalla en producción
$log_dir = dirname(__DIR__) . '/logs';
ini_set('log_errors', '1');
ini_set('error_log', $log_dir . '/php_errors.log');
if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') === 'production') {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
}
error_reporting(E_ALL);

$host   = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$dbname = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'cn_medio_cudeyo';
$user   = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '';
$pass   = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('<h1>Error de conexión a la base de datos</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>');
}
