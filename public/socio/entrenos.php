<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/entrenos.php';

require_login();
$user = current_user();
$entrenos = listar_entrenos_nadador($pdo, (int)$user['id']);
$archivos = archivos_de_entrenos($pdo, array_column($entrenos, 'id'));
// Se marcan como vistos después de leer el listado para poder resaltar los nuevos en esta visita
marcar_entrenos_vistos($pdo, (int)$user['id']);

render_header('Mis entrenos', 'socio-entrenos');
?>
<main class="container" style="padding:24px 16px;">
  <h1>Mis entrenos</h1>

  <?php if (!$entrenos): ?>
    <div class="card text-center" style="padding:32px;">
      <p class="text-muted">Todavía no hay entrenos publicados para tus grupos de entrenamiento.</p>
    </div>
  <?php else: ?>
    <?php foreach ($entrenos as $en): ?>
      <article class="card mb-4 entreno-card<?= $en['nuevo'] ? ' entreno-nuevo' : '' ?>">
        <div class="d-flex justify-between align-center flex-wrap gap-2">
          <h2 style="font-size:17px;font-weight:700;margin:0;">
            <?= e($en['titulo']) ?>
            <?php if ($en['nuevo']): ?><span class="badge badge-blue" style="font-size:11px;margin-left:6px;">Nuevo</span><?php endif; ?>
          </h2>
          <span class="text-muted text-sm"><i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($en['fecha'])) ?></span>
        </div>
        <p class="text-muted text-sm" style="margin:4px 0 0;"><i class="bi bi-diagram-3"></i> <?= e($en['grupos'] ?? '') ?></p>
        <?php if ($en['descripcion']): ?>
          <div style="margin-top:12px;white-space:pre-line;"><?= e($en['descripcion']) ?></div>
        <?php endif; ?>
        <?php render_archivos_entreno($archivos[(int)$en['id']] ?? []); ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
<?php render_visor_fotos_entreno(); ?>
<?php render_footer(); ?>
