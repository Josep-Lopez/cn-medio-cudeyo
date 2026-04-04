<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$errors  = [];
$success = false;
$data    = ['nombre' => '', 'email' => '', 'asunto' => '', 'mensaje' => ''];

// Pre-rellenar con datos del usuario si está logado
$user = current_user();
if ($user) {
    $data['nombre'] = $user['nom'];
    $data['email']  = $user['email'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['nombre']  = trim($_POST['nombre']  ?? '');
    $data['email']   = trim($_POST['email']   ?? '');
    $data['asunto']  = trim($_POST['asunto']  ?? '');
    $data['mensaje'] = trim($_POST['mensaje'] ?? '');

    if (!$data['nombre'])                                      $errors[] = 'El nombre es obligatorio.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL))   $errors[] = 'El email no es válido.';
    if (strlen($data['mensaje']) < 10)                         $errors[] = 'El mensaje debe tener al menos 10 caracteres.';

    if (!$errors && empty($_POST['website'])) {
        $pdo->prepare('INSERT INTO contactos (nombre, email, asunto, mensaje) VALUES (?,?,?,?)')
            ->execute([$data['nombre'], $data['email'], $data['asunto'], $data['mensaje']]);
        $success = true;
        $data = ['nombre' => '', 'email' => '', 'asunto' => '', 'mensaje' => ''];
    }
}

render_header('Contacto', 'contacto', '', 'Contacta con el Club de Natación Medio Cudeyo. Estamos en Cantabria y respondemos a todas tus consultas sobre el club y cómo hacerte socio.');
?>

<!-- Hero compacto -->
<section class="hero" style="padding:48px 0;">
  <div class="container">
    <div class="hero-eyebrow">Escríbenos</div>
    <h1>Contacto</h1>
    <p>¿Tienes alguna pregunta? Estamos aquí para ayudarte.</p>
  </div>
</section>

<div class="container page-content">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:32px;align-items:start;">

    <!-- Info de contacto -->
    <div>
      <div class="card mb-4">
        <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;">Información del club</h2>
        <div style="display:flex;flex-direction:column;gap:16px;">
          <div class="d-flex gap-3 align-center">
            <span style="font-size:22px;color:var(--blue);"><i class="bi bi-geo-alt-fill"></i></span>
            <div>
              <div style="font-weight:600;font-size:14px;">Localización</div>
              <div class="text-muted text-sm">Medio Cudeyo, Cantabria</div>
            </div>
          </div>
          <div class="d-flex gap-3 align-center">
            <span style="font-size:22px;color:var(--blue);"><i class="bi bi-envelope-fill"></i></span>
            <div>
              <div style="font-weight:600;font-size:14px;">Email</div>
              <div class="text-muted text-sm">info@cnmediocudeyo.es</div>
            </div>
          </div>
          <div class="d-flex gap-3 align-center">
            <span style="font-size:22px;color:var(--blue);"><i class="bi bi-water"></i></span>
            <div>
              <div style="font-weight:600;font-size:14px;">Instalaciones</div>
              <div class="text-muted text-sm">Piscina municipal de Medio Cudeyo</div>
            </div>
          </div>
        </div>
      </div>

      <?php if (!$user): ?>
      <div class="card" style="background:#eef2ff;border-color:#c7d2fe;">
        <div style="font-size:22px;margin-bottom:10px;color:var(--blue);"><i class="bi bi-person-fill"></i></div>
        <div style="font-weight:700;margin-bottom:6px;">¿Eres socio?</div>
        <p class="text-sm text-muted" style="margin-bottom:14px;">Si ya eres socio, accede a tu cuenta para rellenar el formulario con tus datos automáticamente.</p>
        <a href="/login" class="btn btn-primary btn-sm">Acceder</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Formulario -->
    <div class="card">
      <?php if ($success): ?>
        <div style="text-align:center;padding:20px 0;">
          <div style="font-size:48px;margin-bottom:16px;color:var(--green);"><i class="bi bi-check-circle-fill"></i></div>
          <h2 style="font-size:20px;font-weight:700;margin-bottom:8px;">Mensaje enviado</h2>
          <p class="text-muted" style="margin-bottom:24px;">Hemos recibido tu mensaje. Te responderemos lo antes posible.</p>
          <a href="/" class="btn btn-primary">Volver al inicio</a>
        </div>
      <?php else: ?>

        <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;">Envíanos un mensaje</h2>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="nombre">Nombre *</label>
              <input type="text" id="nombre" name="nombre" class="form-control"
                     value="<?= e($data['nombre']) ?>" required autofocus>
            </div>
            <div class="form-group">
              <label class="form-label" for="email">Email *</label>
              <input type="email" id="email" name="email" class="form-control"
                     value="<?= e($data['email']) ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="asunto">Asunto</label>
            <input type="text" id="asunto" name="asunto" class="form-control"
                   value="<?= e($data['asunto']) ?>" placeholder="¿En qué podemos ayudarte?">
          </div>
          <div class="form-group">
            <label class="form-label" for="mensaje">Mensaje *</label>
            <textarea id="mensaje" name="mensaje" class="form-control"
                      style="min-height:150px;" required><?= e($data['mensaje']) ?></textarea>
          </div>
          <input type="text" name="website" style="display:none;" tabindex="-1" autocomplete="off">
          <button type="submit" class="btn btn-primary w-100">Enviar mensaje</button>
        </form>

      <?php endif; ?>
    </div>

  </div>
</div>

<?php render_footer(); ?>
