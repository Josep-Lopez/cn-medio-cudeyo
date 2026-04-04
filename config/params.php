<?php
// Parámetros de la aplicación
// ───────────────────────────
require_once __DIR__ . '/env.php';

// ID de la carpeta de Google Drive — definido en .env como DRIVE_FOLDER_ID
define('DRIVE_FOLDER_ID', $_ENV['DRIVE_FOLDER_ID'] ?? '');

// Clave API de Google Drive — definida en .env como DRIVE_API_KEY
define('DRIVE_API_KEY', $_ENV['DRIVE_API_KEY'] ?? '');