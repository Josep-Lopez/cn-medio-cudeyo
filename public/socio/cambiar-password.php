<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

// Debe estar logueado pero NO redirigir aquí mismo (evitar bucle)
if (empty($_SESSION['user'])) {
    header('Location: /login');
    exit;
}

// Si no necesita cambiar contraseña, redirigir al panel
if (empty($_SESSION['user']['must_change_pwd'])) {
    header('Location: /socio/panel');
    exit;
}

$user = $_SESSION['user'];
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $nueva    = $_POST['password'] ?? '';
    $confirma = $_POST['password_confirm'] ?? '';

    if (strlen($nueva) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $confirma) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password = ?, must_change_pwd = 0, updated_at = NOW() WHERE id = ?')
            ->execute([$hash, $user['id']]);

        // Actualizar sesión
        $_SESSION['user']['must_change_pwd'] = 0;

        // Comprobar si necesita autorización tutor
        if (requires_tutor($user['liga'] ?? '') && empty($user['tutor_email'])) {
            flash('Contraseña actualizada. Ahora necesitas completar la autorización del tutor legal.', 'success');
            header('Location: /socio/autorizacion-tutor');
        } else {
            flash('Contraseña actualizada correctamente.', 'success');
            header('Location: /socio/panel');
        }
        exit;
    }
}

render_header('Cambiar contraseña', 'socio');
?>

<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo"><i class="bi bi-shield-lock-fill"></i></div>
    <h1 class="auth-title">Cambiar contraseña</h1>
    <p class="auth-sub">Por seguridad, debes establecer una nueva contraseña para continuar.</p>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label" for="password">Nueva contraseña</label>
        <div class="input-password-wrapper">
          <input type="password" id="password" name="password" class="form-control"
                 placeholder="Mínimo 8 caracteres" required autofocus>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1" aria-label="Mostrar contraseña">
            <i class="bi bi-eye"></i>
          </button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="password_confirm">Repetir contraseña</label>
        <div class="input-password-wrapper">
          <input type="password" id="password_confirm" name="password_confirm" class="form-control"
                 placeholder="Repite la nueva contraseña" required>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1" aria-label="Mostrar contraseña">
            <i class="bi bi-eye"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">Guardar nueva contraseña</button>
    </form>
  </div>
</div>

<script>
function togglePwd(btn) {
  const input = btn.previousElementSibling;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
<?php render_footer(); ?>
