<?php
$host   = getenv('DB_HOST') ?: 'db';
$dbname = getenv('DB_NAME') ?: 'cn_medio_cudeyo';
$user   = getenv('DB_USER') ?: 'cnuser';
$pass   = getenv('DB_PASS') ?: 'cnpass123';

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
