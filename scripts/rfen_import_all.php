#!/usr/bin/env php
<?php
/**
 * rfen_import_all.php — Importa marcas RFEN para todos los usuarios activos con RFEN vinculado.
 *
 * Usage:
 *   php scripts/rfen_import_all.php
 *   php scripts/rfen_import_all.php --temporada=2024-25
 *   php scripts/rfen_import_all.php --user_id=42
 *   php scripts/rfen_import_all.php --temporada=2025-26 --user_id=42
 *
 * Crontab (weekly, Wednesday 6am):
 * 0 6 * * 3  php /path/to/scripts/rfen_import_all.php >> /var/log/rfen_import.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the CLI.' . PHP_EOL);
}

$root = dirname(__DIR__);

require_once $root . '/config/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/rfen.php';

// ── Parse CLI args ────────────────────────────────────────────────────────────

$opts = getopt('', ['temporada:', 'user_id:']);

// Default temporada: current season
$year = (int)date('Y');
$month = (int)date('n');
$season_start = $month >= 9 ? $year : $year - 1;
$season_end   = substr((string)($season_start + 1), -2);
$default_temporada = $season_start . '-' . $season_end;

$temporada = isset($opts['temporada']) ? trim($opts['temporada']) : $default_temporada;
$filter_user_id = isset($opts['user_id']) ? (int)$opts['user_id'] : null;

// ── Query active users with RFEN linked ───────────────────────────────────────

$sql = "SELECT id, nom, rfen_id FROM users WHERE estado='activo' AND rfen_id IS NOT NULL AND rfen_id != ''";
$params = [];

if ($filter_user_id !== null) {
    $sql .= ' AND id = :user_id';
    $params[':user_id'] = $filter_user_id;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

if (empty($users)) {
    echo 'No hay usuarios activos con RFEN vinculado.' . PHP_EOL;
    exit(0);
}

// ── Run import ────────────────────────────────────────────────────────────────

$now = date('Y-m-d H:i:s');
echo "[{$now}] Importando temporada {$temporada} para " . count($users) . " usuario(s)..." . PHP_EOL;

$has_errors = false;

foreach ($users as $user) {
    $user_id  = (int)$user['id'];
    $nom      = $user['nom'];
    $rfen_id  = $user['rfen_id'];

    $result = rfen_import_marks($pdo, $user_id, $rfen_id, $temporada);

    $ts = date('Y-m-d H:i:s');

    if (!empty($result['error'])) {
        echo "[{$ts}] ERROR {$nom} (rfen:{$rfen_id}): " . $result['error'] . PHP_EOL;
        $has_errors = true;
    } else {
        $procesadas  = $result['procesadas']  ?? 0;
        $insertadas  = $result['insertadas']  ?? 0;
        $actualizadas = $result['actualizadas'] ?? 0;
        $sin_cambios = $result['sin_cambios'] ?? 0;
        echo "[{$ts}] {$nom} (rfen:{$rfen_id}): {$procesadas} procesadas, {$insertadas} insertadas, {$actualizadas} actualizadas, {$sin_cambios} sin cambios" . PHP_EOL;
    }

    usleep(500_000);
}

$ts = date('Y-m-d H:i:s');
echo "[{$ts}] Importacion completada." . PHP_EOL;

exit($has_errors ? 1 : 0);
