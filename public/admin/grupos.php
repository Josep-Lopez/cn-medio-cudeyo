<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/grupos.php';

require_admin_area(['director_tecnico', 'entrenador']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        try {
            $id = crear_grupo($pdo, $_POST['nombre'] ?? '', $_POST['descripcion'] ?? null, (int)current_user()['id']);
            flash('Grupo creado.', 'success');
            header('Location: /admin/grupos?ver=' . $id);
            exit;
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
            header('Location: /admin/grupos?accion=nuevo');
            exit;
        }
    }

    if ($accion === 'editar') {
        $id = (int)$_POST['id'];
        try {
            actualizar_grupo($pdo, $id, $_POST['nombre'] ?? '', $_POST['descripcion'] ?? null);
            flash('Grupo actualizado.', 'success');
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
        }
        header('Location: /admin/grupos?ver=' . $id);
        exit;
    }

    if ($accion === 'eliminar') {
        eliminar_grupo($pdo, (int)$_POST['id']);
        flash('Grupo eliminado.', 'success');
        header('Location: /admin/grupos');
        exit;
    }

    if ($accion === 'agregar_nadador') {
        $id = (int)$_POST['id'];
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) agregar_nadador_a_grupo($pdo, $id, $uid);
        header('Location: /admin/grupos?ver=' . $id);
        exit;
    }

    if ($accion === 'quitar_nadador') {
        $id = (int)$_POST['id'];
        quitar_nadador_de_grupo($pdo, $id, (int)$_POST['user_id']);
        header('Location: /admin/grupos?ver=' . $id);
        exit;
    }
}

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$grupos = listar_grupos($pdo);

render_header('Grupos de entrenamiento', 'admin-grupos');
render_admin_layout('grupos', function() use ($accion, $verId, $grupos, $pdo) {

    if ($accion === 'nuevo') {
?>
<h1>Nuevo grupo de entrenamiento</h1>
<?php render_flash(); ?>
<form method="POST" action="/admin/grupos" class="card" style="padding:24px;max-width:560px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="crear">
  <div class="form-group">
    <label class="form-label">Nombre *</label>
    <input type="text" name="nombre" class="form-control" required maxlength="100">
  </div>
  <div class="form-group">
    <label class="form-label">Descripción</label>
    <textarea name="descripcion" class="form-control" rows="3"></textarea>
  </div>
  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary">Crear</button>
    <a href="/admin/grupos" class="btn btn-gray">Cancelar</a>
  </div>
</form>
<?php
        return;
    }

    if ($verId > 0) {
        $grupo = obtener_grupo($pdo, $verId);
        if (!$grupo) {
            echo '<div class="card" style="padding:24px;">Grupo no encontrado. <a href="/admin/grupos">Volver</a></div>';
            return;
        }
        $nadadores = listar_nadadores_grupo($pdo, $verId);
        $disponibles = listar_socios_fuera_de_grupo($pdo, $verId);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;"><?= e($grupo['nombre']) ?></h1>
  <a href="/admin/grupos" class="btn btn-gray btn-sm">← Volver al listado</a>
</div>
<?php render_flash(); ?>

<div class="card mb-6" style="padding:24px;">
  <form method="POST" action="/admin/grupos">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="editar">
    <input type="hidden" name="id" value="<?= (int)$grupo['id'] ?>">
    <div class="form-group">
      <label class="form-label">Nombre</label>
      <input type="text" name="nombre" class="form-control" value="<?= e($grupo['nombre']) ?>" required maxlength="100">
    </div>
    <div class="form-group">
      <label class="form-label">Descripción</label>
      <textarea name="descripcion" class="form-control" rows="3"><?= e($grupo['descripcion'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Guardar cambios</button>
  </form>
</div>

<div class="card mb-6" style="padding:24px;">
  <h3>Nadadores (<?= count($nadadores) ?>)</h3>
  <?php if ($nadadores): ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Nombre</th><th>Categoría</th><th>Sexo</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($nadadores as $n): ?>
            <tr>
              <td><?= e($n['nombre']) ?></td>
              <td><?= e(format_liga($n['liga'] ?? '')) ?></td>
              <td><?= $n['sexo'] === 'F' ? 'Chica' : 'Chico' ?></td>
              <td>
                <form method="POST" action="/admin/grupos">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="quitar_nadador">
                  <input type="hidden" name="id" value="<?= (int)$grupo['id'] ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$n['id'] ?>">
                  <button type="submit" class="btn btn-gray btn-sm">Quitar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted">Sin nadadores asignados todavía.</p>
  <?php endif; ?>

  <?php if ($disponibles): ?>
    <form method="POST" action="/admin/grupos" style="margin-top:16px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="agregar_nadador">
      <input type="hidden" name="id" value="<?= (int)$grupo['id'] ?>">
      <div class="form-group" style="margin:0;flex:1;min-width:220px;">
        <label class="form-label">Añadir nadador</label>
        <select name="user_id" class="form-control" required>
          <option value="">— Selecciona —</option>
          <?php foreach ($disponibles as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= e($d['nombre']) ?> (<?= e(format_liga($d['liga'] ?? '')) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Añadir</button>
    </form>
  <?php endif; ?>
</div>

<div style="text-align:right;">
  <button type="button" class="btn btn-sm" onclick="document.getElementById('modal-eliminar-grupo').style.display='flex'" style="background:#dc2626;color:white;">
    <i class="bi bi-trash"></i> Eliminar grupo
  </button>
</div>

<div id="modal-eliminar-grupo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:8px;max-width:420px;width:90%;">
    <h3 style="margin:0 0 12px;">¿Eliminar grupo?</h3>
    <p>Se quitará la asignación de todos los nadadores. Esta acción no se puede deshacer.</p>
    <form method="POST" action="/admin/grupos">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" value="<?= (int)$grupo['id'] ?>">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modal-eliminar-grupo').style.display='none'">Cancelar</button>
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
  <h1 style="margin:0;">Grupos de entrenamiento</h1>
  <a href="/admin/grupos?accion=nuevo" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo grupo</a>
</div>
<?php render_flash(); ?>

<?php if (!$grupos): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay grupos de entrenamiento todavía.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Nombre</th><th>Descripción</th><th>Nadadores</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($grupos as $g): ?>
            <tr>
              <td style="font-weight:600;"><?= e($g['nombre']) ?></td>
              <td class="text-muted"><?= e($g['descripcion'] ?? '—') ?></td>
              <td><?= (int)$g['num_nadadores'] ?></td>
              <td><a href="/admin/grupos?ver=<?= (int)$g['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
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
