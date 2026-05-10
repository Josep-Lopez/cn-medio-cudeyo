<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

// Debe estar logueado
if (empty($_SESSION['user'])) {
    header('Location: /login');
    exit;
}

// Si debe cambiar contraseña primero
if (!empty($_SESSION['user']['must_change_pwd'])) {
    header('Location: /socio/cambiar-password');
    exit;
}

$user = $_SESSION['user'];

// Si no necesita tutor o ya lo tiene, redirigir al panel
if (!requires_tutor($user['liga'] ?? '') || !empty($user['tutor_email'])) {
    header('Location: /socio/panel');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $tutor_email = trim($_POST['tutor_email'] ?? '');

    if (!filter_var($tutor_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Introduce un email válido del tutor legal.';
    } else {
        $pdo->prepare('UPDATE users SET tutor_email = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$tutor_email, $user['id']]);

        // Actualizar sesión
        $_SESSION['user']['tutor_email'] = $tutor_email;

        flash('Autorización del tutor registrada correctamente.', 'success');
        header('Location: /socio/panel');
        exit;
    }
}

$liga_label = format_liga($user['liga'] ?? '');
$es_menor_14 = in_array($user['liga'], ['benjamin', 'alevin']);

render_header('Autorización tutor legal', 'socio');
?>

<div class="auth-page">
  <div class="auth-card" style="max-width:520px;">
    <div class="auth-logo"><i class="bi bi-person-check-fill"></i></div>
    <h1 class="auth-title">Autorización tutor legal</h1>

    <?php if ($es_menor_14): ?>
      <p class="auth-sub">
        Como nadador de categoría <strong><?= e($liga_label) ?></strong>, es necesario que un padre, madre o tutor legal
        autorice el uso de esta cuenta introduciendo su email de contacto.
      </p>
    <?php else: ?>
      <p class="auth-sub">
        Como nadador de categoría <strong><?= e($liga_label) ?></strong> (menor de 18 años), necesitamos el email
        de tu padre, madre o tutor legal como autorización para el uso de esta plataforma.
      </p>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label" for="tutor_email">Email del tutor legal</label>
        <input type="email" id="tutor_email" name="tutor_email" class="form-control"
               value="<?= e($_POST['tutor_email'] ?? '') ?>"
               placeholder="email@ejemplo.com" required autofocus>
        <div class="form-hint">Introduce el email del padre, madre o tutor legal responsable.</div>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">Confirmar autorización</button>
    </form>
  </div>
</div>

<?php render_footer(); ?>
