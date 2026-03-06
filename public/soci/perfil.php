<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
$user = current_user();

$errors_perfil = [];
$errors_pass   = [];

// ── Formulario A: Datos personales + foto ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'perfil') {
    csrf_verify();
    $nom   = trim($_POST['nom']   ?? '');
    $email = trim($_POST['email'] ?? '');

    if (!$nom)                                      $errors_perfil[] = 'El nombre es obligatorio.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors_perfil[] = 'El email no es válido.';

    // Procesar upload de foto
    $avatar = null; // null = no canvia
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $info = @getimagesize($_FILES['avatar']['tmp_name']);
        if (!$info) {
            $errors_perfil[] = 'El archivo no es una imagen válida.';
        } elseif (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
            $errors_perfil[] = 'Formato no permitido. Usa JPG, PNG o WebP.';
        } elseif ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            $errors_perfil[] = 'La imagen no puede superar los 2 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $errors_perfil[] = 'Extensión no permitida. Usa JPG, PNG, WebP o GIF.';
            } else {
            $filename = 'avatar_' . $user['id'] . '.' . $ext;
            $dir      = dirname(__DIR__, 2) . '/public/uploads/avatars/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dir . $filename)) {
                $avatar = '/uploads/avatars/' . $filename . '?v=' . time();
            } else {
                $errors_perfil[] = 'Error al guardar la imagen.';
            }
            } // end ext whitelist
        }
    } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors_perfil[] = 'Error al subir el archivo (código ' . $_FILES['avatar']['error'] . ').';
    }

    if (!$errors_perfil) {
        $dup = $pdo->prepare('SELECT id FROM users WHERE email=? AND id!=?');
        $dup->execute([$email, $user['id']]);
        if ($dup->fetch()) {
            $errors_perfil[] = 'Este email ya está en uso por otra cuenta.';
        } else {
            if ($avatar !== null) {
                $pdo->prepare('UPDATE users SET nom=?, email=?, avatar_url=?, updated_at=NOW() WHERE id=?')
                    ->execute([$nom, $email, $avatar, $user['id']]);
            } else {
                $pdo->prepare('UPDATE users SET nom=?, email=?, updated_at=NOW() WHERE id=?')
                    ->execute([$nom, $email, $user['id']]);
            }
            $_SESSION['user']['nom']   = $nom;
            $_SESSION['user']['email'] = $email;
            $user = current_user();
            flash('Datos actualizados correctamente.', 'success');
            header('Location: /soci/perfil');
            exit;
        }
    }
}

// ── Formulario B: Cambiar contraseña ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    csrf_verify();
    $pass_actual  = $_POST['pass_actual']  ?? '';
    $pass_nova    = $_POST['pass_nova']    ?? '';
    $pass_confirm = $_POST['pass_confirm'] ?? '';

    if (!$pass_actual)               $errors_pass[] = 'Introduce tu contraseña actual.';
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $pass_nova))
        $errors_pass[] = 'La nueva contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.';
    if ($pass_nova !== $pass_confirm) $errors_pass[] = 'Las contraseñas nuevas no coinciden.';

    if (!$errors_pass) {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id=?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($pass_actual, $hash)) {
            $errors_pass[] = 'La contraseña actual es incorrecta.';
        } else {
            $pdo->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?')
                ->execute([password_hash($pass_nova, PASSWORD_DEFAULT), $user['id']]);
            flash('Contraseña cambiada correctamente.', 'success');
            header('Location: /soci/perfil');
            exit;
        }
    }
}

// Avatar actual de la BD
$stmt = $pdo->prepare('SELECT avatar_url FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$avatar_url = $stmt->fetchColumn();

render_header('Mi perfil', 'soci-perfil');
?>

<style>
.profile-hero {
  background: linear-gradient(135deg, #093FB4 0%, #1565e8 100%);
  border-radius: 16px;
  padding: 48px 24px 36px;
  text-align: center;
  color: white;
  margin-bottom: 24px;
  position: relative;
}
.profile-hero h1 { color: white; font-size: 28px; margin: 16px 0 4px; }
.profile-hero p  { color: rgba(255,255,255,0.75); margin: 0; font-size: 15px; }
.profile-avatar-wrap {
  position: relative;
  display: inline-block;
  cursor: pointer;
}
.profile-avatar {
  width: 110px; height: 110px;
  border-radius: 50%;
  border: 4px solid rgba(255,255,255,0.85);
  overflow: hidden;
  background: rgba(255,255,255,0.18);
  display: flex; align-items: center; justify-content: center;
  font-size: 44px; font-weight: 700; color: white;
  margin: 0 auto;
}
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-avatar-overlay {
  position: absolute; inset: 0;
  border-radius: 50%;
  background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity 0.2s;
  font-size: 22px; color: white;
}
.profile-avatar-wrap:hover .profile-avatar-overlay { opacity: 1; }
.profile-back {
  position: absolute; top: 16px; left: 20px;
  color: rgba(255,255,255,0.75); text-decoration: none; font-size: 14px;
  display: flex; align-items: center; gap: 6px;
}
.profile-back:hover { color: white; }
</style>

<div class="container page-content" style="max-width:680px;">

  <?php render_flash(); ?>

  <!-- ── Hero ──────────────────────────────────────────────────────────────── -->
  <div class="profile-hero">
    <a href="/soci/panel" class="profile-back"><i class="bi bi-arrow-left"></i> Mi panel</a>

    <label for="avatar-input" class="profile-avatar-wrap" title="Cambiar foto">
      <div class="profile-avatar" id="hero-avatar">
        <?php if ($avatar_url): ?>
          <img src="<?= e($avatar_url) ?>" alt="<?= e($user['nom']) ?>">
        <?php else: ?>
          <?= strtoupper(mb_substr($user['nom'], 0, 1)) ?>
        <?php endif; ?>
      </div>
      <div class="profile-avatar-overlay"><i class="bi bi-camera-fill"></i></div>
    </label>

    <h1><?= e($user['nom']) ?></h1>
    <p><?= e(format_lliga($user['lliga'] ?? '')) ?> · <?= $user['sexe'] === 'M' ? 'Masculino' : 'Femenino' ?></p>
  </div>

  <!-- ── Datos personales ─────────────────────────────────────────────────── -->
  <div class="card mb-6">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;">Datos personales</h2>

    <?php if ($errors_perfil): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors_perfil as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="perfil">
      <input type="file" id="avatar-input" name="avatar"
             accept="image/jpeg,image/png,image/webp,image/gif"
             style="display:none;" onchange="previewAvatar(this)">

      <div class="form-group">
        <label class="form-label">Nombre *</label>
        <input type="text" name="nom" class="form-control"
               value="<?= e($_POST['nom'] ?? $user['nom']) ?>" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Email *</label>
        <input type="email" name="email" class="form-control"
               value="<?= e($_POST['email'] ?? $user['email']) ?>" required>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <a href="/soci/panel" class="btn btn-gray">Cancelar</a>
      </div>
    </form>
  </div>

  <!-- ── Cambiar contraseña ───────────────────────────────────────────────── -->
  <div class="card">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;">Cambiar contraseña</h2>

    <?php if ($errors_pass): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors_pass as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <div class="form-group">
        <label class="form-label">Contraseña actual *</label>
        <div class="input-password-wrapper">
          <input type="password" name="pass_actual" class="form-control" placeholder="••••••••" required>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Nueva contraseña *</label>
        <div class="input-password-wrapper">
          <input type="password" name="pass_nova" class="form-control" placeholder="Mínimo 6 caracteres" required>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Confirmar nueva contraseña *</label>
        <div class="input-password-wrapper">
          <input type="password" name="pass_confirm" class="form-control" placeholder="Repite la nueva contraseña" required>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Cambiar contraseña</button>
    </form>
  </div>

</div>

<script>
function previewAvatar(input) {
  if (!input.files || !input.files[0]) return;
  document.getElementById('file-name').textContent = input.files[0].name;
  const reader = new FileReader();
  reader.onload = function(e) {
    document.getElementById('hero-avatar').innerHTML =
      '<img src="' + e.target.result + '" alt="preview" style="width:100%;height:100%;object-fit:cover;">';
  };
  reader.readAsDataURL(input.files[0]);
}
function togglePwd(btn) {
  const input = btn.previousElementSibling;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>

<?php render_footer(); ?>
