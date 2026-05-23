<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/incidencias.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
$adj = obtener_adjunto($pdo, $id);
if (!$adj) {
    http_response_code(404);
    die('Adjunto no encontrado.');
}

$path = INCIDENCIA_UPLOAD_DIR . $adj['archivo'];
if (!is_file($path)) {
    http_response_code(404);
    die('Fichero no encontrado en disco.');
}

$disposition = str_starts_with($adj['mime'], 'image/') || $adj['mime'] === 'application/pdf'
    ? 'inline'
    : 'attachment';

header('Content-Type: ' . $adj['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $adj['nombre_original']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
