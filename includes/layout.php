<?php
function render_header(string $title, string $activePage = '', string $extraHead = '', string $description = ''): void
{
  global $pdo;
  $user = current_user();
  $isAdmin = $user && $user['rol'] === 'admin';
  $notif_count = 0;
  if ($user && !$isAdmin && $pdo) {
    $stmtN = $pdo->prepare("
            SELECT COUNT(*) FROM comunicaciones c
            WHERE (c.destinatario_tipo='todos' OR (c.destinatario_tipo='liga' AND c.destinatario_valor=?) OR (c.destinatario_tipo='individual' AND c.destinatario_valor=?))
            AND c.id NOT IN (SELECT comunicacion_id FROM comunicaciones_leidas WHERE user_id=?)
        ");
    $stmtN->execute([$user['liga'] ?? '', $user['id'], $user['id']]);
    $notif_count = (int)$stmtN->fetchColumn();
  }
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.min.css">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/main.css') ?>">
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <?= $extraHead ?>
  </head>

  <body>

    <?php if (!empty($_SESSION['admin_original'])): ?>
      <div style="background:#1e40af;color:#fff;padding:8px 16px;text-align:center;font-size:13px;font-weight:600;position:sticky;top:0;z-index:9999;display:flex;align-items:center;justify-content:center;gap:12px;">
        <span><i class="bi bi-person-fill-gear"></i> Sesión como: <?= e($_SESSION['user']['nombre'] ?? '') ?></span>
        <a href="/admin/volver-admin" style="background:#fff;color:#1e40af;padding:4px 12px;border-radius:6px;text-decoration:none;font-weight:700;font-size:12px;">Volver a admin</a>
      </div>
    <?php endif; ?>

    <nav class="navbar">
      <div class="container">
        <div class="navbar-inner">
          <a href="/" class="navbar-brand">
            <img class="brand-icon" src="/assets/images/icon.png" />
            CN Medio Cudeyo
          </a>

          <div class="navbar-links">
            <a href="/" <?= $activePage === 'inicio' ? 'class="active"' : '' ?>>Inicio</a>
            <?php if ($user): ?>
              <?php if ($isAdmin): ?>
                <a href="/admin/usuarios" <?= str_starts_with($activePage, 'admin') ? 'class="active"' : '' ?>>Administración</a>
              <?php else: ?>
                <a href="/socio/panel" <?= str_starts_with($activePage, 'socio') ? 'class="active"' : '' ?>>Mi panel</a>
                <?php if (user_tiene_cargo('director_tecnico')): ?>
                  <a href="/admin/marcas" <?= str_starts_with($activePage, 'admin') ? 'class="active"' : '' ?>>Administración</a>
                <?php elseif (user_tiene_cargo('entrenador')): ?>
                  <a href="/admin/asistencia" <?= str_starts_with($activePage, 'admin') ? 'class="active"' : '' ?>>Asistencia</a>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!empty(cargos_activos())): ?>
                <a href="/directiva/socios" <?= str_starts_with($activePage, 'directiva') ? 'class="active"' : '' ?>>Directiva</a>
              <?php endif; ?>
            <?php endif; ?>
            <a href="/noticias/" <?= $activePage === 'noticias' ? 'class="active"' : '' ?>>Noticias</a>
            <?php if ($user && is_nadador_activo()): ?>
              <a href="/calculadoras" <?= $activePage === 'calculadoras' ? 'class="active"' : '' ?>>Calculadoras</a>
              <a href="/biblioteca" <?= $activePage === 'biblioteca'   ? 'class="active"' : '' ?>>Biblioteca</a>
            <?php elseif (!$user): ?>
              <a href="/sobre-nosotros" <?= $activePage === 'sobre' ? 'class="active"' : '' ?>>Sobre nosotros</a>
            <?php endif; ?>
            <?php if (!$user): ?>
              <a href="/contacto" <?= $activePage === 'contacto' ? 'class="active"' : '' ?>>Contacto</a>
            <?php endif; ?>
          </div>

          <div class="navbar-auth">
            <?php if ($user): ?>
              <?php if (!$isAdmin && $notif_count > 0): ?>
                <a href="/socio/comunicaciones" class="navbar-notif" title="<?= $notif_count ?> sin leer">
                  <i class="bi bi-bell-fill"></i>
                  <span class="navbar-notif-badge"><?= $notif_count ?></span>
                </a>
              <?php endif; ?>
              <div class="navbar-user-dropdown">
                <button class="navbar-user" onclick="document.querySelector('.navbar-user-dropdown').classList.toggle('open')" type="button">
                  <?php if (!empty($user['avatar_url'])): ?>
                    <img src="<?= e($user['avatar_url']) ?>" alt="" class="navbar-user-avatar" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
                  <?php else: ?>
                    <div class="navbar-user-avatar"><?= strtoupper(mb_substr($user['nombre'], 0, 1)) ?></div>
                  <?php endif; ?>
                  <span><?= e($user['nombre']) ?></span>
                  <i class="bi bi-chevron-down" style="font-size:12px;opacity:0.7;"></i>
                </button>
                <div class="navbar-dropdown-menu">
                  <a href="/socio/perfil"><i class="bi bi-person"></i> Mi perfil</a>
                  <?php if (!$isAdmin): ?>
                    <a href="/socio/comunicaciones"><i class="bi bi-bell"></i> Comunicaciones<?= $notif_count > 0 ? ' <span class="badge badge-blue" style="font-size:11px;padding:2px 6px;margin-left:4px;">' . $notif_count . '</span>' : '' ?></a>
                    <a href="/socio/incidencias" <?= $activePage === 'socio-incidencias' ? 'class="active"' : '' ?>><i class="bi bi-exclamation-triangle"></i> Incidencias</a>
                    <a href="/socio/equipacion" <?= $activePage === 'socio-equipacion' ? 'class="active"' : '' ?>><i class="bi bi-bag-check"></i> Equipación</a>
                  <?php endif; ?>
                  <a href="/directiva/cuestiones" <?= $activePage === 'directiva-cuestiones' ? 'class="active"' : '' ?>><i class="bi bi-question-circle"></i> Cuestiones</a>
                  <a href="/logout"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
                </div>
              </div>
            <?php else: ?>
              <a href="/login" class="btn btn-secondary btn-sm">Acceso</a>
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
            <a href="/socio/panel">Mi panel</a>
            <?php if (is_nadador_activo()): ?>
              <a href="/socio/ranking">Ranking mi liga</a>
            <?php endif; ?>
            <?php if (user_tiene_cargo('director_tecnico')): ?>
              <a href="/admin/marcas">Administración</a>
            <?php elseif (user_tiene_cargo('entrenador')): ?>
              <a href="/admin/asistencia">Asistencia</a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty(cargos_activos())): ?>
            <a href="/directiva/socios">Directiva</a>
          <?php endif; ?>
        <?php endif; ?>
        <a href="/noticias/">Noticias</a>
        <?php if ($user && is_nadador_activo()): ?>
          <a href="/calculadoras">Calculadoras</a>
          <a href="/biblioteca">Biblioteca</a>
        <?php elseif (!$user): ?>
          <a href="/sobre-nosotros">Sobre nosotros</a>
        <?php endif; ?>
        <?php if (!$user): ?>
          <a href="/contacto">Contacto</a>
        <?php endif; ?>
        <div class="mobile-auth">
          <?php if ($user): ?>
            <span style="font-size:14px;color:#888;">Hola, <strong><?= e($user['nombre']) ?></strong></span>
            <a href="/logout" class="btn btn-secondary btn-sm"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
          <?php else: ?>
            <a href="/login" class="btn btn-secondary btn-sm">Acceso</a>
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
            <?php if ($user): ?>
              <a href="/biblioteca">Biblioteca</a>
            <?php endif; ?>
            <a href="/sobre-nosotros">Sobre nosotros</a>
          </div>
          <div class="footer-col">
            <h4>Socios</h4>
            <?php if (!$user): ?>
              <a href="/login">Acceso socios</a>
              <a href="/register">Registro</a>
            <?php else: ?>
              <a href="/socio/panel">Mi panel</a>
              <a href="/socio/ranking">Ranking liga</a>
            <?php endif; ?>
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

    <script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
    <script>
      function initSearchable(el) {
        if (el.tomselect) return;
        new TomSelect(el, {
          plugins: ['dropdown_input'],
          allowEmptyOption: true,
          render: {
            no_results: function() {
              return '<div class="no-results" style="padding:10px;text-align:center;color:var(--gray);">Sin resultados</div>';
            }
          }
        });
      }
      document.querySelectorAll('select.searchable').forEach(function(el) {
        if (el.offsetParent !== null) initSearchable(el);
      });
    </script>

    <!-- Modal confirmación global -->
    <div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)closeConfirm()">
      <div style="background:white;border-radius:12px;padding:32px;max-width:380px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);text-align:center;">
        <div style="font-size:36px;margin-bottom:12px;color:var(--red);"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <h3 id="confirmTitle" style="margin-bottom:8px;">¿Estás seguro?</h3>
        <p id="confirmMsg" style="color:var(--gray);margin-bottom:20px;"></p>
        <div class="d-flex gap-2" style="justify-content:center;">
          <button id="confirmOk" class="btn btn-danger">Eliminar</button>
          <button class="btn btn-gray" onclick="closeConfirm()">Cancelar</button>
        </div>
      </div>
    </div>

    <script>
      let confirmCallback = null;

      function showConfirm(message, onConfirm) {
        document.getElementById('confirmMsg').textContent = message;
        confirmCallback = onConfirm;
        document.getElementById('confirmModal').style.display = 'flex';
      }

      function closeConfirm() {
        document.getElementById('confirmModal').style.display = 'none';
        confirmCallback = null;
      }
      document.getElementById('confirmOk').addEventListener('click', function() {
        if (confirmCallback) confirmCallback();
        closeConfirm();
      });

      // Auto-bind forms with data-confirm attribute
      document.addEventListener('submit', function(e) {
        const form = e.target;
        const msg = form.dataset.confirm;
        if (msg && !form._confirmed) {
          e.preventDefault();
          showConfirm(msg, function() {
            form._confirmed = true;
            form.submit();
          });
        }
      });

      function toggleMenu() {
        const m = document.getElementById('mobileMenu');
        m.classList.toggle('open');
      }
      document.addEventListener('click', function(e) {
        const menu = document.getElementById('mobileMenu');
        const btn = document.querySelector('.navbar-hamburger');
        if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
          menu.classList.remove('open');
        }
        const dropdown = document.querySelector('.navbar-user-dropdown');
        if (dropdown && !dropdown.contains(e.target)) {
          dropdown.classList.remove('open');
        }
      });
    </script>
  </body>

  </html>
<?php
  }

  // Layout para el área de directiva. Sidebar paralelo al de admin.
  // Admin también ve estos enlaces (le sirve para auditar).
  function render_directiva_layout(string $activePage, callable $content): void
  {
?>
  <div class="admin-layout">
    <aside class="admin-sidebar">
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Directiva</div>
        <a href="/directiva/socios" class="<?= $activePage === 'socios' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i> Socios y cuotas
        </a>
        <a href="/directiva/actas" class="<?= $activePage === 'actas' ? 'active' : '' ?>">
          <i class="bi bi-journal-text"></i> Actas
        </a>
        <a href="/directiva/cuestiones" class="<?= $activePage === 'cuestiones' ? 'active' : '' ?>">
          <i class="bi bi-question-circle-fill"></i> Cuestiones
        </a>
        <a href="/directiva/equipacion" class="<?= $activePage === 'equipacion' ? 'active' : '' ?>">
          <i class="bi bi-bag-check-fill"></i> Equipación
        </a>
      </div>
      <?php if (is_admin()): ?>
        <div class="admin-sidebar-section">
          <div class="admin-sidebar-title">Volver</div>
          <a href="/admin/usuarios"><i class="bi bi-arrow-left"></i> Panel admin</a>
        </div>
      <?php else: ?>
        <div class="admin-sidebar-section">
          <div class="admin-sidebar-title">Mi cuenta</div>
          <a href="/socio/panel"><i class="bi bi-house-fill"></i> Mi panel</a>
        </div>
      <?php endif; ?>
    </aside>
    <main class="admin-main">
      <?php $content(); ?>
    </main>
  </div>
<?php
  }

  function render_admin_layout(string $activePage, callable $content): void
  {
    $isAdmin      = is_admin();
    $isDirTec     = $isAdmin || user_tiene_cargo('director_tecnico');
    $isEntrenador = $isDirTec || user_tiene_cargo('entrenador');
?>
  <div class="admin-layout">
    <aside class="admin-sidebar">
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Usuarios</div>
        <?php if ($isAdmin): ?>
        <a href="/admin/usuarios" class="<?= $activePage === 'usuarios' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i> Gestión de usuarios
        </a>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <a href="/admin/cargos" class="<?= $activePage === 'cargos' ? 'active' : '' ?>">
          <i class="bi bi-person-badge-fill"></i> Gestión Club
        </a>
        <?php endif; ?>
        <?php if ($isEntrenador): ?>
        <a href="/admin/asistencia" class="<?= $activePage === 'asistencia' ? 'active' : '' ?>">
          <i class="bi bi-clipboard-check-fill"></i> Pasar lista
        </a>
        <a href="/admin/asistencia_historial" class="<?= $activePage === 'asistencia_historial' ? 'active' : '' ?>">
          <i class="bi bi-calendar-check"></i> Historial asistencia
        </a>
        <?php endif; ?>
        <?php if ($isDirTec): ?>
        <a href="/admin/incidencias" class="<?= $activePage === 'incidencias' ? 'active' : '' ?>">
          <i class="bi bi-exclamation-triangle"></i> Incidencias
        </a>
        <?php endif; ?>
      </div>
      <?php if ($isDirTec): ?>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Marcas &amp; Ranking</div>
        <a href="/admin/marcas" class="<?= $activePage === 'marcas' ? 'active' : '' ?>">
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
        <a href="/admin/comunicaciones" class="<?= $activePage === 'comunicaciones' ? 'active' : '' ?>">
          <i class="bi bi-megaphone-fill"></i> Comunicaciones
        </a>
        <a href="/admin/contacto" class="<?= $activePage === 'contacto' ? 'active' : '' ?>">
          <i class="bi bi-envelope-fill"></i> Mensajes de contacto
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Directiva</div>
        <a href="/directiva/socios" class="<?= $activePage === 'directiva-socios' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i> Socios y cuotas
        </a>
        <a href="/directiva/actas" class="<?= $activePage === 'directiva-actas' ? 'active' : '' ?>">
          <i class="bi bi-journal-text"></i> Actas
        </a>
        <a href="/directiva/cuestiones" class="<?= $activePage === 'directiva-cuestiones' ? 'active' : '' ?>">
          <i class="bi bi-question-circle-fill"></i> Cuestiones
        </a>
        <a href="/directiva/equipacion" class="<?= $activePage === 'directiva-equipacion' ? 'active' : '' ?>">
          <i class="bi bi-bag-check-fill"></i> Equipación
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Sistema</div>
        <a href="/admin/config" class="<?= $activePage === 'config' ? 'active' : '' ?>">
          <i class="bi bi-sliders"></i> Configuración
        </a>
      </div>
      <?php endif; ?>
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
