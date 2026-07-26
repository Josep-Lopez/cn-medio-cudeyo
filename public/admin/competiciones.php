<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';

require_admin_area(['director_tecnico', 'entrenador']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        try {
            $id = crear_competicion_entrenador($pdo, $_POST['nombre'] ?? '', $_POST['lugar'] ?? null, $_POST['fecha'] ?? '', (int)current_user()['id']);
            flash('Competición creada.', 'success');
            header('Location: /admin/competiciones?ver=' . $id);
            exit;
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
            header('Location: /admin/competiciones?accion=nueva');
            exit;
        }
    }

    if ($accion === 'eliminar') {
        eliminar_competicion_entrenador($pdo, (int)$_POST['id']);
        flash('Competición eliminada.', 'success');
        header('Location: /admin/competiciones');
        exit;
    }

    if ($accion === 'agregar_tiempo') {
        $competicionId = (int)$_POST['competicion_id'];
        try {
            agregar_tiempo_entrenador($pdo, [
                'competicion_id' => $competicionId,
                'user_id' => $_POST['user_id'] ?? '',
                'prueba' => $_POST['prueba'] ?? '',
                'piscina' => $_POST['piscina'] ?? '',
                'tiempo' => $_POST['tiempo'] ?? '',
                'parciales' => $_POST['parciales'] ?? '',
                'registrado_por' => current_user()['id'],
            ]);
            flash('Tiempo añadido.', 'success');
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
        }
        header('Location: /admin/competiciones?ver=' . $competicionId);
        exit;
    }
}

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$competiciones = listar_competiciones_entrenador($pdo);

render_header('Competiciones', 'admin-competiciones');
render_admin_layout('competiciones', function() use ($accion, $verId, $competiciones, $pdo) {

    if ($accion === 'nueva') {
        $hoy = date('Y-m-d');
?>
<h1>Nueva competición</h1>
<?php render_flash(); ?>
<form method="POST" action="/admin/competiciones" class="card" style="padding:24px;max-width:560px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="crear">
  <div class="form-group">
    <label class="form-label">Nombre *</label>
    <input type="text" name="nombre" class="form-control" required maxlength="150">
  </div>
  <div class="form-group">
    <label class="form-label">Lugar</label>
    <input type="text" name="lugar" class="form-control" maxlength="255">
  </div>
  <div class="form-group">
    <label class="form-label">Fecha *</label>
    <input type="date" name="fecha" class="form-control" required value="<?= $hoy ?>">
  </div>
  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary">Crear</button>
    <a href="/admin/competiciones" class="btn btn-gray">Cancelar</a>
  </div>
</form>
<?php
        return;
    }

    if ($verId > 0) {
        $comp = obtener_competicion_entrenador($pdo, $verId);
        if (!$comp) {
            echo '<div class="card" style="padding:24px;">Competición no encontrada. <a href="/admin/competiciones">Volver</a></div>';
            return;
        }
        $tiempos = listar_tiempos_competicion($pdo, $verId);
        $sociosStmt = $pdo->query("SELECT id, nombre FROM users WHERE rol='socio' AND estado='activo' ORDER BY nombre");
        $socios = $sociosStmt->fetchAll();
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;"><?= e($comp['nombre']) ?></h1>
  <a href="/admin/competiciones" class="btn btn-gray btn-sm">← Volver al listado</a>
</div>
<?php render_flash(); ?>

<div class="card mb-6" style="padding:24px;">
  <div class="text-muted text-sm">
    <?= date('d/m/Y', strtotime($comp['fecha'])) ?>
    <?php if ($comp['lugar']): ?> · <?= e($comp['lugar']) ?><?php endif; ?>
  </div>
</div>

<div class="card mb-6" style="padding:24px;">
  <h3>Tiempos registrados (<?= count($tiempos) ?>)</h3>
  <?php if ($tiempos): ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Nadador</th><th>Prueba</th><th>Piscina</th><th>Tiempo</th><th>Parciales</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($tiempos as $t): ?>
            <tr>
              <td><?= e($t['nadador_nombre']) ?></td>
              <td><?= e(format_prueba($t['prueba'])) ?></td>
              <td><?= e($t['piscina']) ?></td>
              <td style="font-weight:600;"><?= e($t['tiempo']) ?></td>
              <td class="text-muted text-sm"><?= e($t['parciales'] ?? '—') ?></td>
              <td><a href="/admin/competicion_tiempo?id=<?= (int)$t['id'] ?>" class="btn btn-gray btn-sm">Ver / comentar</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted">Sin tiempos registrados todavía.</p>
  <?php endif; ?>

  <form method="POST" action="/admin/competiciones" style="margin-top:20px;border-top:1px solid #eee;padding-top:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="agregar_tiempo">
    <input type="hidden" name="competicion_id" value="<?= (int)$comp['id'] ?>">
    <div class="d-flex gap-3 flex-wrap">
      <div class="form-group" style="flex:2;min-width:200px;">
        <label class="form-label">Nadador *</label>
        <select name="user_id" class="form-control" required>
          <option value="">— Selecciona —</option>
          <?php foreach ($socios as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:140px;">
        <label class="form-label">Prueba *</label>
        <select name="prueba" class="form-control" required>
          <?php foreach (CE_PRUEBAS as $p): ?>
            <option value="<?= $p ?>"><?= format_prueba($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:100px;">
        <label class="form-label">Piscina *</label>
        <select name="piscina" class="form-control" required>
          <?php foreach (CE_PISCINAS as $p): ?>
            <option value="<?= $p ?>"><?= $p ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:120px;">
        <label class="form-label">Tiempo *</label>
        <input type="text" name="tiempo" class="form-control" placeholder="1:05.43" required>
      </div>
      <div class="form-group" style="flex:2;min-width:200px;">
        <label class="form-label">Parciales</label>
        <input type="text" name="parciales" class="form-control" placeholder="28.5 / 1:01.2 / ...">
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Añadir tiempo</button>
  </form>
</div>

<div style="text-align:right;">
  <button type="button" class="btn btn-sm" onclick="document.getElementById('modal-eliminar-comp').style.display='flex'" style="background:#dc2626;color:white;">
    <i class="bi bi-trash"></i> Eliminar competición
  </button>
</div>

<div id="modal-eliminar-comp" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:8px;max-width:420px;width:90%;">
    <h3 style="margin:0 0 12px;">¿Eliminar competición?</h3>
    <p>Se eliminarán también todos los tiempos y comentarios asociados. Esta acción no se puede deshacer.</p>
    <form method="POST" action="/admin/competiciones">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" value="<?= (int)$comp['id'] ?>">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modal-eliminar-comp').style.display='none'">Cancelar</button>
        <button type="submit" class="btn" style="background:#dc2626;color:white;">Eliminar</button>
      </div>
    </form>
  </div>
</div>
<?php
        return;
    }
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;">Competiciones</h1>
  <a href="/admin/competiciones?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva competición</a>
</div>
<?php render_flash(); ?>

<?php if (!$competiciones): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay competiciones registradas todavía.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Fecha</th><th>Nombre</th><th>Lugar</th><th>Tiempos</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($competiciones as $c): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
              <td style="font-weight:600;"><?= e($c['nombre']) ?></td>
              <td class="text-muted"><?= e($c['lugar'] ?? '—') ?></td>
              <td><?= (int)$c['num_tiempos'] ?></td>
              <td><a href="/admin/competiciones?ver=<?= (int)$c['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php
});
render_footer();
