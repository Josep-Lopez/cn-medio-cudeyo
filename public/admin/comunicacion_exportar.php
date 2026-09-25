<?php
// Exporta las respuestas de una encuesta a CSV (se abre directamente en Excel).
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/encuestas.php';

require_admin_area(['director_tecnico']);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, titulo FROM comunicaciones WHERE id = ? AND tipo = 'encuesta'");
$stmt->execute([$id]);
$com = $stmt->fetch();
if (!$com) {
    http_response_code(404);
    die('Encuesta no encontrada.');
}

[$cabeceras, $filas] = enc_tabla_export($pdo, $id);

// Evita que Excel interprete celdas como fórmulas (CSV injection)
function csv_celda(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $com['titulo']) ?: '')), '-');
$filename = 'encuesta-' . $id . ($slug !== '' ? '-' . substr($slug, 0, 50) : '') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel detecta UTF-8 (tildes, ñ)
// Separador ';' → Excel en configuración regional española lo abre en columnas
fputcsv($out, array_map('csv_celda', $cabeceras), ';', '"', '');
foreach ($filas as $fila) {
    fputcsv($out, array_map('csv_celda', $fila), ';', '"', '');
}
fclose($out);
exit;
