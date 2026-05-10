<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!empty($_SESSION['admin_original'])) {
    $_SESSION['user'] = $_SESSION['admin_original'];
    unset($_SESSION['admin_original']);
    flash('Has vuelto a tu sesión de administrador.', 'success');
    header('Location: /admin/usuarios');
} else {
    header('Location: /');
}
exit;
