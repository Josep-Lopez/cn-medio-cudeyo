<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

// Marcar como leído
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_id'])) {
    $pdo->prepare('UPDATE contactos SET leido=1 WHERE id=?')->execute([(int)$_POST['mark_id']]);
    header('Location: /admin/contacto');
    exit;
}

// Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $pdo->prepare('DELETE FROM contactos WHERE id=?')->execute([(int)$_POST['delete_id']]);
    flash('Mensaje eliminado.', 'warning');
    header('Location: /admin/contacto');
    exit;
}

$filtro = $_GET['filtro'] ?? 'todos';
if ($filtro === 'nuevos') {
    $mensajes = $pdo->query('SELECT * FROM contactos WHERE leido=0 ORDER BY created_at DESC')->fetchAll();
} else {
    $mensajes = $pdo->query('SELECT * FROM contactos ORDER BY created_at DESC')->fetchAll();
}

$total_nuevos = $pdo->query('SELECT COUNT(*) FROM contactos WHERE leido=0')->fetchColumn();

render_header('Contacto — Admin', 'admin-contacto');
render_admin_layout('contacto', function() use ($mensajes, $filtro, $total_nuevos) {
?>

<div class="d-flex justify-between align-center mb-6">
  <h1 style="margin:0;">
    Mensajes de contacto
    <?php if ($total_nuevos): ?>
      <span class="badge badge-danger" style="font-size:14px;margin-left:8px;"><?= $total_nuevos ?> nuevos</span>
    <?php endif; ?>
  </h1>
</div>

<?php render_flash(); ?>

<!-- Filtros -->
<div class="d-flex gap-2 mb-6">
  <a href="?filtro=todos"  class="btn btn-sm <?= $filtro === 'todos'  ? 'btn-primary' : 'btn-gray' ?>">Todos (<?= count($mensajes) + ($filtro === 'nuevos' ? $total_nuevos - count($mensajes) : 0) ?>)</a>
  <a href="?filtro=nuevos" class="btn btn-sm <?= $filtro === 'nuevos' ? 'btn-primary' : 'btn-gray' ?>">Sin leer (<?= $total_nuevos ?>)</a>
</div>

<?php if (!$mensajes): ?>
  <div class="card text-center" style="padding:48px;">
    <div style="font-size:40px;margin-bottom:12px;">📭</div>
    <p class="text-muted">No hay mensajes<?= $filtro === 'nuevos' ? ' sin leer' : '' ?>.</p>
  </div>
<?php else: ?>
  <div style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($mensajes as $m): ?>
    <div class="card" style="<?= !$m['leido'] ? 'border-left:4px solid var(--blue);' : 'opacity:0.75;' ?>">
      <div class="d-flex justify-between align-center" style="margin-bottom:12px;">
        <div>
          <strong><?= e($m['nombre']) ?></strong>
          <span class="text-muted text-sm" style="margin-left:8px;">&lt;<?= e($m['email']) ?>&gt;</span>
          <?php if (!$m['leido']): ?>
            <span class="badge badge-info" style="margin-left:8px;">Nuevo</span>
          <?php endif; ?>
        </div>
        <span class="text-muted text-sm"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></span>
      </div>

      <?php if ($m['asunto']): ?>
        <div style="font-weight:600;margin-bottom:8px;font-size:15px;"><?= e($m['asunto']) ?></div>
      <?php endif; ?>

      <div style="color:#444;font-size:14px;line-height:1.6;white-space:pre-wrap;"><?= e($m['mensaje']) ?></div>

      <div class="d-flex gap-2 mt-4">
        <a href="mailto:<?= e($m['email']) ?>?subject=Re:+<?= rawurlencode($m['asunto'] ?: 'Tu mensaje') ?>"
           class="btn btn-primary btn-sm">Responder por email</a>
        <?php if (!$m['leido']): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="mark_id" value="<?= $m['id'] ?>">
            <button class="btn btn-secondary btn-sm">Marcar como leído</button>
          </form>
        <?php endif; ?>
        <form method="POST" style="display:inline;"
              onsubmit="return confirm('¿Eliminar este mensaje?')">
          <input type="hidden" name="delete_id" value="<?= $m['id'] ?>">
          <button class="btn btn-danger btn-sm">🗑</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
});
render_footer();
