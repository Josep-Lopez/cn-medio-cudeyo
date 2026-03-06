<?php
// Parámetros de la aplicación
// ───────────────────────────

// ID de la carpeta de Google Drive — definido en .env como DRIVE_FOLDER_ID
define('DRIVE_FOLDER_ID', getenv('DRIVE_FOLDER_ID') ?: '');

// Clave API de Google Drive — definida en .env como DRIVE_API_KEY
define('DRIVE_API_KEY', getenv('DRIVE_API_KEY') ?: '');