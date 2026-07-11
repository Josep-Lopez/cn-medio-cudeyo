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

ignore_user_abort(true);
set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/rfen.php';

$log_file = dirname(__DIR__, 2) . '/storage/rfen_import.log';

function log_line(string $line): void {
    global $log_file;
    $msg = $line . "\n";
    echo $msg;
    if (ob_get_level()) { ob_flush(); }
    flush();
    file_put_contents($log_file, $msg, FILE_APPEND);
}

// Temporada: ?temporada=todas|2024-25|... — por defecto la actual
$temporada_param = trim($_GET['temporada'] ?? '');
if ($temporada_param === 'todas') {
    $temporada = 'todas';
} elseif (preg_match('/^\d{4}-\d{2}$/', $temporada_param)) {
    $temporada = $temporada_param;
} else {
    $year = (int)date('Y');
    $month = (int)date('n');
    $season_start = $month >= 9 ? $year : $year - 1;
    $temporada = $season_start . '-' . substr((string)($season_start + 1), -2);
}

// Filtro opcional por user_id: ?user_id=42
$filter_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

// Chunk: ?offset=0&limit=15 (por defecto limit=15, offset=0)
$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit  = max(1, min(50, (int)($_GET['limit'] ?? 15)));

$incluir_inactivos = ($_GET['incluir_inactivos'] ?? '0') === '1';
$sql = "SELECT id, nombre, rfen_id FROM users WHERE estado='activo' AND rfen_id IS NOT NULL AND rfen_id != ''"
    . ($incluir_inactivos ? '' : " AND nadador_activo=1")
    . " ORDER BY nombre";
$stmt = $pdo->query($sql);
$all_users = $stmt->fetchAll();

if ($filter_user_id !== null) {
    $all_users = array_values(array_filter($all_users, fn($u) => (int)$u['id'] === $filter_user_id));
}

$total = count($all_users);
$users = array_slice($all_users, $offset, $limit);

if (empty($users)) {
    log_line("No hay usuarios activos con RFEN vinculado (offset={$offset}).");
    exit;
}

$next_offset = $offset + $limit;
$has_more = $next_offset < $total;

log_line("[" . date('Y-m-d H:i:s') . "] Importando temporada {$temporada} — usuarios " . ($offset + 1) . "-" . ($offset + count($users)) . " de {$total}...");

foreach ($users as $u) {
    $r = rfen_import_marks($pdo, (int)$u['id'], $u['rfen_id'], $temporada);
    $ts = date('Y-m-d H:i:s');

    if ($r['error']) {
        log_line("[{$ts}] ERROR {$u['nombre']} (rfen:{$u['rfen_id']}): {$r['error']}");
    } else {
        log_line("[{$ts}] {$u['nombre']} (rfen:{$u['rfen_id']}): {$r['procesadas']} procesadas, {$r['insertadas']} insertadas, {$r['actualizadas']} actualizadas, {$r['sin_cambios']} sin cambios");
    }

    usleep(500_000);
}

if ($has_more) {
    log_line("[" . date('Y-m-d H:i:s') . "] Chunk completado. Siguiente: ?offset={$next_offset}&limit={$limit}&temporada={$temporada}");
    echo "\n--- SIGUIENTE LLAMADA ---\n";
    echo "?token=...&temporada={$temporada}&offset={$next_offset}&limit={$limit}\n";
} else {
    log_line("[" . date('Y-m-d H:i:s') . "] Importacion completada. Total usuarios: {$total}.");
}
