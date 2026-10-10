<?php
// Sirve fotos/PDF de entrenos: staff (admin, director técnico, entrenador) o nadador del grupo.
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/entrenos.php';

require_login();
$user = current_user();

$a = obtener_archivo_entreno($pdo, (int)($_GET['id'] ?? 0));
if (!$a) {
    http_response_code(404);
    die('Archivo no encontrado.');
}

if (!es_staff_entrenos() && !puede_ver_entreno($pdo, (int)$a['entreno_id'], (int)$user['id'])) {
    http_response_code(403);
    die('No tienes acceso a este archivo.');
}

$path = ENTRENO_UPLOAD_DIR . $a['archivo'];
if (!is_file($path)) {
    http_response_code(404);
    die('Fichero no encontrado en disco.');
}

header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $a['nombre_original']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
