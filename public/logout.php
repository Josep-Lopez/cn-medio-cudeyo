<?php
require_once dirname(__DIR__) . '/includes/auth.php';

session_destroy();
header('Location: /login');
exit;
