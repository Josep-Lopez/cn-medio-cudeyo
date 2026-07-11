<?php
require_once dirname(__DIR__, 2) . '/config/env.php';
$expected_token = $_ENV['CRON_TOKEN'] ?? '';
$given_token = $_GET['token'] ?? '';
if (!$expected_token || $given_token !== $expected_token) {
    http_response_code(403);
    exit('Acceso denegado.');
}

header('Content-Type: text/plain; charset=utf-8');
$log_file = dirname(__DIR__, 2) . '/storage/rfen_import.log';
if (!file_exists($log_file)) {
    echo "Sin log todavía.\n";
    exit;
}
echo file_get_contents($log_file);
