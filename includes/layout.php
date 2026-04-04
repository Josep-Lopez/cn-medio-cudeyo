<?php
function render_header(string $title, string $activePage = '', string $extraHead = '', string $description = ''): void
{
    $user = current_user();
    $isAdmin = $user && $user['rol'] === 'admin';
    $metaDesc = $description ?: 'Club de Natación Medio Cudeyo — Cantabria. Marcas personales, ranking de liga, noticias y más.';
    $pageTitle = e($title) . ' — CN Medio Cudeyo';
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?></title>
  <meta name="description" content="<?= e($metaDesc) ?>">
  <meta property="og:title" content="<?= $pageTitle ?>">
  <meta property="og:description" content="<?= e($metaDesc) ?>">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="es_ES">
  <link rel="canonical" href="https://www.mediocudeyonatacion.es<?= strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="icon" type="image/png" href="/assets/images/favicon.png">
  <?= $extraHead ?>
</head>
<body>

<nav class="navbar">
  <div class="container">
    <div class="navbar-inner">
      <a href="/" class="navbar-brand">
        <img class="brand-icon" src="/assets/images/icon.png"/>
        CN Medio Cudeyo
      </a>

      <div class="navbar-links">
        <a href="/" <?= $activePage === 'inicio' ? 'class="active"' : '' ?>>Inicio</a>
        <?php if ($user): ?>
          <?php if ($isAdmin): ?>
            <a href="/admin/usuarios" <?= str_starts_with($activePage, 'admin') ? 'class="active"' : '' ?>>Administración</a>
          <?php else: ?>
            <a href="/soci/panel" <?= str_starts_with($activePage, 'soci') ? 'class="active"' : '' ?>>Mi panel</a>
          <?php endif; ?>
        <?php endif; ?>
        <a href="/noticias/" <?= $activePage === 'noticias' ? 'class="active"' : '' ?>>Noticias</a>
        <?php if ($user): ?>
          <a href="/calculadoras" <?= $activePage === 'calculadoras' ? 'class="active"' : '' ?>>Calculadoras</a>
          <a href="/biblioteca"   <?= $activePage === 'biblioteca'   ? 'class="active"' : '' ?>>Biblioteca</a>
        <?php else: ?>
          <a href="/sobre-nosotros" <?= $activePage === 'sobre' ? 'class="active"' : '' ?>>Sobre nosotros</a>
        <?php endif; ?>
        <a href="/contacto" <?= $activePage === 'contacto' ? 'class="active"' : '' ?>>Contacto</a>
      </div>

      <div class="navbar-auth">
        <?php if ($user): ?>
          <?php if (!$isAdmin): ?>
            <a href="/soci/perfil" class="navbar-user" style="text-decoration:none;color:white;">
          <?php else: ?>
            <div class="navbar-user">
          <?php endif; ?>
              <div class="navbar-user-avatar"><?= strtoupper(mb_substr($user['nom'], 0, 1)) ?></div>
              <span><?= e($user['nom']) ?></span>
          <?php if (!$isAdmin): ?>
            </a>
          <?php else: ?>
            </div>
          <?php endif; ?>
          <a href="/logout" class="navbar-logout">
            <i class="bi bi-box-arrow-right"></i> Cerrar sesión
          </a>
        <?php else: ?>
          <a href="/login"    class="btn btn-secondary btn-sm">Acceso</a>
          <a href="/register" class="btn btn-primary btn-sm">Registro</a>
        <?php endif; ?>
      </div>

      <button class="navbar-hamburger" onclick="toggleMenu()" aria-label="Menú">
        <i class="bi bi-list"></i>
      </button>
    </div>
  </div>

  <!-- Mobile menu -->
  <div class="navbar-mobile" id="mobileMenu">
    <a href="/">Inicio</a>
    <?php if ($user): ?>
      <?php if ($isAdmin): ?>
        <a href="/admin/usuarios">Administración</a>
      <?php else: ?>
        <a href="/soci/panel">Mi panel</a>
        <a href="/soci/ranking">Ranking mi liga</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="/noticias/">Noticias</a>
    <?php if ($user): ?>
      <a href="/calculadoras">Calculadoras</a>
      <a href="/biblioteca">Biblioteca</a>
    <?php else: ?>
      <a href="/sobre-nosotros">Sobre nosotros</a>
    <?php endif; ?>
    <a href="/contacto">Contacto</a>
    <div class="mobile-auth">
      <?php if ($user): ?>
        <span style="font-size:14px;color:#888;">Hola, <strong><?= e($user['nom']) ?></strong></span>
        <a href="/logout" class="btn btn-secondary btn-sm"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
      <?php else: ?>
        <a href="/login"    class="btn btn-secondary btn-sm">Acceso</a>
        <a href="/register" class="btn btn-primary btn-sm">Registro</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<main class="site-main">
<?php
}

function render_footer(): void
{
    $user = current_user();
    ?>
</main>
<footer class="footer">
  <div class="container">
    <div class="footer-inner">
      <div>
        <div class="footer-brand-name"><i class="bi bi-water"></i> CN Medio Cudeyo</div>
        <p>Club de Natación Medio Cudeyo.<br>Nadamos juntos desde hace años.</p>
      </div>
      <div class="footer-col">
        <h4>Navegación</h4>
        <a href="/">Inicio</a>
        <a href="/noticias/">Noticias</a>
        <a href="/calculadoras">Calculadoras</a>
        <a href="/biblioteca">Biblioteca</a>
        <a href="/sobre-nosotros">Sobre nosotros</a>
      </div>
      <div class="footer-col">
        <h4>Socios</h4>
        <?php if (!$user): ?>
          <a href="/login">Acceso socios</a>
          <a href="/register">Registro</a>
        <?php endif; ?>
        <a href="/soci/panel">Mi panel</a>
        <a href="/soci/ranking">Ranking liga</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> CN Medio Cudeyo. Todos los derechos reservados.</span>
      <div class="footer-links">
        <a href="/privacidad">Privacidad</a>
        <a href="/cookies">Cookies</a>
      </div>
    </div>
  </div>
</footer>

<script>
function toggleMenu() {
  const m = document.getElementById('mobileMenu');
  m.classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const menu = document.getElementById('mobileMenu');
  const btn  = document.querySelector('.navbar-hamburger');
  if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
    menu.classList.remove('open');
  }
});
</script>
</body>
</html>
<?php
}

function render_admin_layout(string $activePage, callable $content): void
{
    ?>
<div class="admin-layout">
  <aside class="admin-sidebar">
    <div class="admin-sidebar-section">
      <div class="admin-sidebar-title">Usuarios</div>
      <a href="/admin/usuarios" class="<?= $activePage === 'usuarios' ? 'active' : '' ?>">
        <i class="bi bi-people-fill"></i> Gestión de usuarios
      </a>
    </div>
    <div class="admin-sidebar-section">
      <div class="admin-sidebar-title">Marcas &amp; Ranking</div>
      <a href="/admin/marques" class="<?= $activePage === 'marques' ? 'active' : '' ?>">
        <i class="bi bi-stopwatch-fill"></i> Gestión de marcas
      </a>
      <a href="/admin/ranking" class="<?= $activePage === 'ranking' ? 'active' : '' ?>">
        <i class="bi bi-trophy-fill"></i> Ranking general
      </a>
    </div>
    <div class="admin-sidebar-section">
      <div class="admin-sidebar-title">Contenido</div>
      <a href="/admin/noticias" class="<?= $activePage === 'noticias' ? 'active' : '' ?>">
        <i class="bi bi-newspaper"></i> Noticias
      </a>
      <a href="/admin/contacto" class="<?= $activePage === 'contacto' ? 'active' : '' ?>">
        <i class="bi bi-envelope-fill"></i> Mensajes de contacto
      </a>
    </div>
    <div class="admin-sidebar-section">
      <div class="admin-sidebar-title">Sistema</div>
      <a href="/admin/config" class="<?= $activePage === 'config' ? 'active' : '' ?>">
        <i class="bi bi-sliders"></i> Configuración
      </a>
    </div>
    <div class="admin-sidebar-section">
      <div class="admin-sidebar-title">Web pública</div>
      <a href="/" target="_blank"><i class="bi bi-globe"></i> Ver web</a>
    </div>
  </aside>
  <main class="admin-main">
    <?php $content(); ?>
  </main>
</div>
<?php
}
