<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin_area(['director_tecnico']);
header('Location: /admin/usuarios');
exit;
