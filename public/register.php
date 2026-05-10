<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

if (current_user()) {
    header('Location: /socio/panel');
    exit;
}

$errors = [];
$data   = ['nombre' => '', 'email' => '', 'sexo' => '', 'liga' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data['nombre']   = trim($_POST['nombre'] ?? '');
    $data['email'] = trim($_POST['email'] ?? '');
    $data['sexo']  = $_POST['sexo'] ?? '';
    $data['liga'] = $_POST['liga'] ?? '';
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!$data['nombre'])                     $errors[] = 'El nombre es obligatorio.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El email no es válido.';
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $pass1))
        $errors[] = 'La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.';
    if ($pass1 !== $pass2)                 $errors[] = 'Las contraseñas no coinciden.';
    if (!in_array($data['sexo'], ['M','F'])) $errors[] = 'Selecciona el sexo.';
    if (!in_array($data['liga'], ['benjamin','alevin','infantil','junior','absoluto','master']))
        $errors[] = 'Selecciona una categoría.';

    if (!$errors) {
        // Verificar email único
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$data['email']]);
        if ($check->fetch()) {
            $errors[] = 'Ya existe una cuenta con ese email.';
        }
    }

    if (!$errors) {
        $hash = password_hash($pass1, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (nombre, email, password, rol, estado, liga, sexo) VALUES (?,?,?,\'socio\',\'pendiente\',?,?)'
        );
        $stmt->execute([$data['nombre'], $data['email'], $hash, $data['liga'], $data['sexo']]);

        flash('Registro completado. Tu cuenta está pendiente de aprobación por el administrador. Te avisaremos cuando esté activa.', 'info');
        header('Location: /login');
        exit;
    }
}

render_header('Registro', 'registro');
?>

<div class="auth-page" style="align-items:flex-start;padding-top:48px;">
  <div class="auth-card" style="max-width:500px;">
    <div class="auth-logo"><i class="bi bi-water"></i></div>
    <h1 class="auth-title">Crear cuenta</h1>
    <p class="auth-sub">CN Medio Cudeyo — Solicitud de alta</p>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?>
          <div><?= e($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label" for="nombre">Nombre completo</label>
        <input type="text" id="nombre" name="nombre" class="form-control"
               value="<?= e($data['nombre']) ?>" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label" for="email">Email</label>
        <input type="email" id="email" name="email" class="form-control"
               value="<?= e($data['email']) ?>" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="sexo">Sexo</label>
          <select id="sexo" name="sexo" class="form-control" required>
            <option value="">— Seleccionar —</option>
            <option value="M" <?= $data['sexo'] === 'M' ? 'selected' : '' ?>>Masculino</option>
            <option value="F" <?= $data['sexo'] === 'F' ? 'selected' : '' ?>>Femenino</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="liga">Categoría</label>
          <select id="liga" name="liga" class="form-control" required>
            <option value="">— Seleccionar —</option>
            <option value="benjamin" <?= $data['liga'] === 'benjamin' ? 'selected' : '' ?>>Benjamín</option>
            <option value="alevin"   <?= $data['liga'] === 'alevin'   ? 'selected' : '' ?>>Alevín</option>
            <option value="infantil" <?= $data['liga'] === 'infantil' ? 'selected' : '' ?>>Infantil</option>
            <option value="junior"   <?= $data['liga'] === 'junior'   ? 'selected' : '' ?>>Junior</option>
            <option value="absoluto" <?= $data['liga'] === 'absoluto' ? 'selected' : '' ?>>Absoluto</option>
            <option value="master"   <?= $data['liga'] === 'master'   ? 'selected' : '' ?>>Master</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Contraseña</label>
        <input type="password" id="password" name="password" class="form-control"
               placeholder="Mín. 8 caract., mayúscula, minúscula y número" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="password2">Repetir contraseña</label>
        <input type="password" id="password2" name="password2" class="form-control" required>
      </div>
      <p class="form-hint mb-4">Tras registrarte, el administrador revisará tu solicitud antes de activar la cuenta.</p>
      <button type="submit" class="btn btn-primary w-100 btn-lg">Enviar solicitud</button>
    </form>

    <hr class="auth-divider">
    <p class="auth-footer">
      ¿Ya tienes cuenta? <a href="/login">Accede aquí</a>
    </p>
  </div>
</div>

<?php render_footer(); ?>
