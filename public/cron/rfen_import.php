<?php
/**
 * Punto de entrada web para el cron de importación RFEN.
 * DonDominio llama a esta URL cada semana.
 *
 * URL: https://tudominio.com/cron/rfen_import.php?token=TU_TOKEN
 */

// Verificar token
require_once dirname(__DIR__, 2) . '/config/env.php';
$expected_token = $_ENV['CRON_TOKEN'] ?? '';
$given_token = $_GET['token'] ?? '';
if (!$expected_token || $given_token !== $expected_token) {
    http_response_code(403);
    exit('Acceso denegado.');
}

header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/rfen.php';

// Temporada actual
$year = (int)date('Y');
$month = (int)date('n');
$season_start = $month >= 9 ? $year : $year - 1;
$temporada = $season_start . '-' . substr((string)($season_start + 1), -2);

// Todos los usuarios activos con RFEN
$stmt = $pdo->query("SELECT id, nombre, rfen_id FROM users WHERE estado='activo' AND nadador_activo=1 AND rfen_id IS NOT NULL AND rfen_id != '' ORDER BY nombre");
$users = $stmt->fetchAll();

if (empty($users)) {
    echo "No hay usuarios activos con RFEN vinculado.\n";
    exit;
}

echo "[" . date('Y-m-d H:i:s') . "] Importando temporada {$temporada} para " . count($users) . " usuario(s)...\n";

foreach ($users as $u) {
    $r = rfen_import_marks($pdo, (int)$u['id'], $u['rfen_id'], $temporada);
    $ts = date('Y-m-d H:i:s');

    if ($r['error']) {
        echo "[{$ts}] ERROR {$u['nombre']} (rfen:{$u['rfen_id']}): {$r['error']}\n";
    } else {
        echo "[{$ts}] {$u['nombre']} (rfen:{$u['rfen_id']}): {$r['procesadas']} procesadas, {$r['insertadas']} insertadas, {$r['actualizadas']} actualizadas, {$r['sin_cambios']} sin cambios\n";
    }

    usleep(500_000);
}

echo "[" . date('Y-m-d H:i:s') . "] Importacion completada.\n";
