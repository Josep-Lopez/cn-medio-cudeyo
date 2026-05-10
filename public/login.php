<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

// Ya logueado → redirigir
if ($u = current_user()) {
    header('Location: ' . ($u['rol'] === 'admin' ? '/admin/usuarios' : '/socio/panel'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!rate_limit_check($ip)) {
        $error = 'Demasiados intentos fallidos. Espera 15 minutos e inténtalo de nuevo.';
    } elseif (!$email || !$password) {
        $error = 'Introduce tu email y contraseña.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Email o contraseña incorrectos.';
        } elseif ($user['estado'] === 'pendiente') {
            $error = 'Tu cuenta está pendiente de aprobación por el administrador.';
        } elseif ($user['estado'] === 'rechazado') {
            $error = 'Tu cuenta ha sido rechazada. Contacta con el administrador.';
        } else {
            // Login OK — regenerar ID de sesión para evitar session fixation
            session_regenerate_id(true);
            rate_limit_reset($ip);
            $_SESSION['user'] = [
                'id'    => $user['id'],
                'nombre'   => $user['nombre'],
                'email' => $user['email'],
                'rol'   => $user['rol'],
                'liga' => $user['liga'],
                'sexo'  => $user['sexo'],
                'nadador_activo' => (int)$user['nadador_activo'],
                'avatar_url' => $user['avatar_url'] ?? null,
                'must_change_pwd' => (int)($user['must_change_pwd'] ?? 0),
                'tutor_email' => $user['tutor_email'] ?? null,
            ];
            flash('Bienvenido, ' . $user['nombre'] . '!');
            // Primer login: redirigir a cambio de contraseña
            if ($user['must_change_pwd']) {
                header('Location: /socio/cambiar-password');
            } else {
                header('Location: ' . ($user['rol'] === 'admin' ? '/admin/usuarios' : '/socio/panel'));
            }
            exit;
        }
    }
}

render_header('Acceso', 'login');
?>

<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo"><i class="bi bi-water"></i></div>
    <h1 class="auth-title">Acceso socios</h1>
    <p class="auth-sub">CN Medio Cudeyo</p>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label" for="email">Email</label>
        <input type="email" id="email" name="email" class="form-control"
               value="<?= e($_POST['email'] ?? '') ?>"
               placeholder="tu@email.com" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Contraseña</label>
        <div class="input-password-wrapper">
          <input type="password" id="password" name="password" class="form-control"
                 placeholder="••••••••" required>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1" aria-label="Mostrar contraseña">
            <i class="bi bi-eye"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">Entrar</button>
    </form>

    <hr class="auth-divider">
    <p class="auth-footer">
      ¿No tienes cuenta? <a href="/register">Regístrate</a>
    </p>
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
