<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';

require_login();
$user = current_user();
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'comentar') {
    csrf_verify();
    $t = obtener_tiempo_entrenador($pdo, $id);
    if ($t && puede_ver_tiempo_socio($t, $user)) {
        try {
            agregar_comentario_tiempo($pdo, $id, (int)$user['id'], $_POST['contenido'] ?? '');
            flash('Comentario añadido.', 'success');
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
        }
    }
    header('Location: /socio/competicion_tiempo?id=' . $id);
    exit;
}

$tiempo = obtener_tiempo_entrenador($pdo, $id);
$autorizado = $tiempo && puede_ver_tiempo_socio($tiempo, $user);

render_header('Tiempo de competición', 'socio-competiciones');
?>
<main class="container" style="padding:24px 16px;">
  <?php if (!$autorizado): ?>
    <div class="card" style="padding:32px;text-align:center;">
      <h2 style="margin-top:0;">No tienes acceso a este tiempo</h2>
      <a href="/socio/competiciones" class="btn btn-primary btn-sm">Volver a mis competiciones</a>
    </div>
  <?php else:
      $comentarios = listar_comentarios_tiempo($pdo, $id);
  ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h1 style="margin:0;"><?= e(format_prueba($tiempo['prueba'])) ?></h1>
      <a href="/socio/competiciones" class="btn btn-gray btn-sm">← Volver</a>
    </div>
    <?php render_flash(); ?>

    <div class="card mb-6" style="padding:24px;">
      <div class="text-muted text-sm" style="margin-bottom:8px;">
        <?= e($tiempo['competicion_nombre']) ?> · <?= date('d/m/Y', strtotime($tiempo['competicion_fecha'])) ?>
      </div>
      <div style="font-size:28px;font-weight:800;color:var(--blue);"><?= e($tiempo['tiempo']) ?></div>
      <div class="text-muted text-sm">Piscina <?= e($tiempo['piscina']) ?></div>
      <?php if ($tiempo['parciales']): ?>
        <div style="margin-top:12px;">
          <strong>Parciales:</strong>
          <div style="white-space:pre-wrap;"><?= e($tiempo['parciales']) ?></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="padding:24px;">
      <h3>Comentarios (<?= count($comentarios) ?>)</h3>
      <?php if ($comentarios): ?>
        <?php foreach ($comentarios as $c): ?>
          <div style="border-left:3px solid var(--blue);padding:8px 12px;margin-bottom:12px;background:#f9fafb;">
            <div class="text-sm">
              <strong><?= e($c['autor_nombre']) ?></strong>
              <span class="text-muted">· <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></span>
            </div>
            <div style="white-space:pre-wrap;margin-top:6px;"><?= e($c['contenido']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="text-muted">Sin comentarios.</p>
      <?php endif; ?>

      <form method="POST" action="/socio/competicion_tiempo?id=<?= (int)$tiempo['id'] ?>" style="margin-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="comentar">
        <div class="form-group">
          <textarea name="contenido" class="form-control" rows="3" placeholder="Escribe un comentario…" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Comentar</button>
      </form>
    </div>
  <?php endif; ?>
</main>
<?php render_footer(); ?>
