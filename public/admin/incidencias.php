<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/incidencias.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_verify();
    try {
        $data = [
            'tipo' => $_POST['tipo'] ?? '',
            'titulo' => $_POST['titulo'] ?? '',
            'descripcion' => $_POST['descripcion'] ?? '',
            'fecha_suceso' => $_POST['fecha_suceso'] ?? '',
            'user_id' => $_POST['user_id'] ?? null,
            'visible_socio' => isset($_POST['visible_socio']) ? 1 : 0,
            'creado_por' => current_user()['id'],
        ];
        $files = [];
        if (!empty($_FILES['adjuntos']) && is_array($_FILES['adjuntos']['name'])) {
            foreach ($_FILES['adjuntos']['name'] as $idx => $name) {
                if (($_FILES['adjuntos']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $files[] = [
                    'name' => $name,
                    'tmp_name' => $_FILES['adjuntos']['tmp_name'][$idx],
                    'error' => $_FILES['adjuntos']['error'][$idx],
                    'size' => $_FILES['adjuntos']['size'][$idx],
                    'type' => $_FILES['adjuntos']['type'][$idx],
                ];
            }
        }
        $id = crear_incidencia($pdo, $data, $files);
        flash('Incidencia creada (#' . $id . ').', 'success');
        header('Location: /admin/incidencias?ver=' . $id);
        exit;
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
        header('Location: /admin/incidencias?accion=nueva');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'actualizar_estado') {
    csrf_verify();
    try {
        actualizar_estado($pdo, (int)$_POST['id'], $_POST['estado'] ?? '', (int)current_user()['id']);
        flash('Estado actualizado.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . (int)$_POST['id']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'toggle_visible') {
    csrf_verify();
    try {
        $visible = isset($_POST['visible_socio']);
        toggle_visible_socio($pdo, (int)$_POST['id'], $visible, (int)current_user()['id']);
        flash('Visibilidad actualizada.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . (int)$_POST['id']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'comentar') {
    csrf_verify();
    try {
        $inc = obtener_incidencia($pdo, (int)$_POST['id']);
        if (!$inc) throw new RuntimeException('Incidencia no encontrada');
        if (!puede_comentar_incidencia($inc, current_user())) throw new RuntimeException('No puedes comentar');
        agregar_comentario($pdo, (int)$_POST['id'], (int)current_user()['id'], $_POST['contenido'] ?? '');
        flash('Comentario añadido.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . (int)$_POST['id']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'subir_adjunto') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        $inc = obtener_incidencia($pdo, $id);
        if (!$inc) throw new RuntimeException('Incidencia no encontrada');
        if (!puede_subir_adjunto($inc, current_user())) throw new RuntimeException('No puedes subir adjuntos');
        if (empty($_FILES['adjunto']) || ($_FILES['adjunto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No se seleccionó fichero');
        }
        subir_adjunto($pdo, $id, $_FILES['adjunto'], (int)current_user()['id']);
        flash('Adjunto subido.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_adjunto') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        eliminar_adjunto($pdo, (int)$_POST['adjunto_id'], current_user());
        flash('Adjunto eliminado.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_campos') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        actualizar_campos($pdo, $id, [
            'tipo' => $_POST['tipo'] ?? '',
            'titulo' => $_POST['titulo'] ?? '',
            'descripcion' => $_POST['descripcion'] ?? '',
            'fecha_suceso' => $_POST['fecha_suceso'] ?? '',
            'user_id' => $_POST['user_id'] ?? null,
        ]);
        flash('Cambios guardados.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . $id);
    exit;
}

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;

$filtros = [
    'tipo' => $_GET['tipo'] ?? '',
    'estado' => $_GET['estado'] ?? '',
    'user_id' => $_GET['user_id'] ?? '',
    'desde' => $_GET['desde'] ?? '',
    'hasta' => $_GET['hasta'] ?? '',
    'q' => trim($_GET['q'] ?? ''),
];
$pagina = max(1, (int)($_GET['p'] ?? 1));
$limit = 25;
$offset = ($pagina - 1) * $limit;

$res = listar_incidencias_admin($pdo, $filtros, $limit, $offset);
$incidencias = $res['rows'];
$total = $res['total'];
$totalPaginas = max(1, (int)ceil($total / $limit));

$sociosStmt = $pdo->query("SELECT id, nombre FROM users WHERE rol='socio' AND estado='activo' ORDER BY nombre");
$socios = $sociosStmt->fetchAll();

render_header('Incidencias', 'admin-incidencias');
render_admin_layout('incidencias', function() use ($accion, $verId, $filtros, $incidencias, $total, $pagina, $totalPaginas, $socios, $pdo) {

    if ($accion === 'nueva') {
        $hoy = date('Y-m-d');
?>
<h1>Nueva incidencia</h1>
<?php render_flash(); ?>
<form method="POST" action="/admin/incidencias" enctype="multipart/form-data" class="card" style="padding:24px;max-width:760px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="crear">

  <div class="form-group">
    <label class="form-label">Tipo *</label>
    <select name="tipo" class="form-control" required onchange="ajustarVisibleDefault(this.value)">
      <option value="">— Selecciona —</option>
      <?php foreach (INCIDENCIA_TIPOS as $t): ?>
        <option value="<?= $t ?>"><?= format_incidencia_tipo($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-group">
    <label class="form-label">Título *</label>
    <input type="text" name="titulo" class="form-control" required maxlength="200">
  </div>

  <div class="form-group">
    <label class="form-label">Descripción *</label>
    <textarea name="descripcion" class="form-control" rows="5" required></textarea>
  </div>

  <div class="d-flex gap-3 flex-wrap">
    <div class="form-group" style="flex:1;min-width:200px;">
      <label class="form-label">Fecha del suceso *</label>
      <input type="date" name="fecha_suceso" class="form-control" required max="<?= $hoy ?>" value="<?= $hoy ?>">
    </div>
    <div class="form-group" style="flex:2;min-width:240px;">
      <label class="form-label">Socio (opcional para operativas)</label>
      <select name="user_id" class="form-control">
        <option value="">— Sin socio —</option>
        <?php foreach ($socios as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-group">
    <label class="form-label">
      <input type="checkbox" name="visible_socio" id="visible_socio" checked> Visible para el socio
    </label>
    <div class="text-muted text-sm">Si se desmarca, el socio no verá la incidencia ni recibirá notificación.</div>
  </div>

  <div class="form-group">
    <label class="form-label">Adjuntos (PDF, JPG, PNG — máx 5 MB, hasta 5 ficheros)</label>
    <input type="file" name="adjuntos[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png">
  </div>

  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Crear incidencia</button>
    <a href="/admin/incidencias" class="btn btn-gray">Cancelar</a>
  </div>
</form>

<script>
function ajustarVisibleDefault(tipo) {
  var cb = document.getElementById('visible_socio');
  if (tipo === 'conducta') cb.checked = false;
  else cb.checked = true;
}
</script>
<?php
        return;
    }

    if ($verId > 0) {
        $inc = obtener_incidencia($pdo, $verId);
        if (!$inc) {
            echo '<div class="card" style="padding:24px;">Incidencia no encontrada. <a href="/admin/incidencias">Volver</a></div>';
            return;
        }
        $adjuntos = listar_adjuntos($pdo, $verId);
        $comentarios = listar_comentarios($pdo, $verId);
        $socio = null;
        if (!empty($inc['user_id'])) {
            $s = $pdo->prepare('SELECT id, nombre FROM users WHERE id=?');
            $s->execute([$inc['user_id']]);
            $socio = $s->fetch();
        }
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;">Incidencia #<?= (int)$inc['id'] ?></h1>
  <a href="/admin/incidencias" class="btn btn-gray btn-sm">← Volver al listado</a>
</div>
<?php render_flash(); ?>

<div class="card mb-6" style="padding:24px;">
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
    <span class="badge <?= badge_clase_tipo($inc['tipo']) ?>"><?= format_incidencia_tipo($inc['tipo']) ?></span>

    <form method="POST" action="/admin/incidencias" style="display:inline-flex;gap:6px;align-items:center;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="actualizar_estado">
      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
      <select name="estado" class="form-control" style="padding:4px 8px;font-size:13px;">
        <?php foreach (INCIDENCIA_ESTADOS as $e): ?>
          <option value="<?= $e ?>" <?= $inc['estado'] === $e ? 'selected' : '' ?>><?= format_incidencia_estado($e) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-gray btn-sm">Cambiar estado</button>
    </form>

    <form method="POST" action="/admin/incidencias" style="display:inline-flex;gap:6px;align-items:center;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="toggle_visible">
      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
      <label style="font-size:13px;display:inline-flex;gap:6px;align-items:center;">
        <input type="checkbox" name="visible_socio" <?= (int)$inc['visible_socio'] === 1 ? 'checked' : '' ?>>
        Visible socio
      </label>
      <button type="submit" class="btn btn-gray btn-sm">Aplicar</button>
    </form>
  </div>

  <div class="text-muted text-sm" style="margin-bottom:16px;">
    Creada: <?= date('d/m/Y H:i', strtotime($inc['created_at'])) ?>
    · Actualizada: <?= date('d/m/Y H:i', strtotime($inc['updated_at'])) ?>
    <?php if ($socio): ?> · Socio actual: <?= e($socio['nombre']) ?><?php endif; ?>
  </div>

  <form method="POST" action="/admin/incidencias">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="editar_campos">
    <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
    <div class="form-group">
      <label class="form-label">Tipo</label>
      <select name="tipo" class="form-control" style="max-width:240px;">
        <?php foreach (INCIDENCIA_TIPOS as $t): ?>
          <option value="<?= $t ?>" <?= $inc['tipo'] === $t ? 'selected' : '' ?>><?= format_incidencia_tipo($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Título</label>
      <input type="text" name="titulo" value="<?= e($inc['titulo']) ?>" class="form-control" maxlength="200">
    </div>
    <div class="form-group">
      <label class="form-label">Descripción</label>
      <textarea name="descripcion" class="form-control" rows="5"><?= e($inc['descripcion']) ?></textarea>
    </div>
    <div class="d-flex gap-3 flex-wrap">
      <div class="form-group" style="flex:1;min-width:200px;">
        <label class="form-label">Fecha del suceso</label>
        <input type="date" name="fecha_suceso" value="<?= e($inc['fecha_suceso']) ?>" max="<?= date('Y-m-d') ?>" class="form-control">
      </div>
      <div class="form-group" style="flex:2;min-width:240px;">
        <label class="form-label">Socio</label>
        <select name="user_id" class="form-control">
          <option value="">— Sin socio —</option>
          <?php foreach ($socios as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$inc['user_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Guardar cambios</button>
  </form>
</div>

<div class="card mb-6" style="padding:24px;">
  <h3>Adjuntos (<?= count($adjuntos) ?>)</h3>
  <?php if ($adjuntos): ?>
    <ul style="list-style:none;padding:0;">
      <?php foreach ($adjuntos as $a): ?>
        <li style="padding:8px 0;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;gap:8px;">
          <div>
            <a href="/admin/incidencia_descargar.php?id=<?= (int)$a['id'] ?>"><?= e($a['nombre_original']) ?></a>
            <span class="text-muted text-sm">— <?= number_format($a['tamano'] / 1024, 0) ?> KB · <?= e($a['mime']) ?></span>
          </div>
          <form method="POST" action="/admin/incidencias">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="eliminar_adjunto">
            <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
            <input type="hidden" name="adjunto_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="btn btn-gray btn-sm">Eliminar</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="text-muted">Sin adjuntos.</p>
  <?php endif; ?>

  <?php if ($inc['estado'] !== 'cerrada' && count($adjuntos) < INCIDENCIA_MAX_ADJUNTOS): ?>
    <form method="POST" action="/admin/incidencias" enctype="multipart/form-data" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="subir_adjunto">
      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
      <div class="form-group">
        <label class="form-label">Añadir adjunto</label>
        <input type="file" name="adjunto" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Subir</button>
    </form>
  <?php endif; ?>
</div>

<div class="card" style="padding:24px;">
  <h3>Comentarios (<?= count($comentarios) ?>)</h3>
  <?php if ($comentarios): ?>
    <?php foreach ($comentarios as $c): ?>
      <div style="border-left:3px solid var(--blue);padding:8px 12px;margin-bottom:12px;background:#f9fafb;">
        <div class="text-sm">
          <strong><?= e($c['autor_nombre']) ?></strong>
          <span class="badge <?= $c['autor_rol'] === 'admin' ? 'badge-operativa' : 'badge-gray' ?>" style="font-size:10px;"><?= e($c['autor_rol']) ?></span>
          <span class="text-muted">· <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></span>
        </div>
        <div style="white-space:pre-wrap;margin-top:6px;"><?= e($c['contenido']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="text-muted">Sin comentarios.</p>
  <?php endif; ?>

  <?php if (puede_comentar_incidencia($inc, current_user())): ?>
    <form method="POST" action="/admin/incidencias" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="comentar">
      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
      <div class="form-group">
        <textarea name="contenido" class="form-control" rows="3" placeholder="Escribe un comentario…" required></textarea>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Comentar</button>
    </form>
  <?php else: ?>
    <p class="text-muted text-sm">Comentarios bloqueados (incidencia cerrada).</p>
  <?php endif; ?>
</div>

<?php
        return;
    }
?>

<h1>Incidencias</h1>
<?php render_flash(); ?>

<div class="card mb-6">
  <form method="GET" class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;">
      <label class="form-label">Tipo</label>
      <select name="tipo" class="form-control" style="width:auto;">
        <option value="">Todos</option>
        <?php foreach (INCIDENCIA_TIPOS as $t): ?>
          <option value="<?= $t ?>" <?= $filtros['tipo'] === $t ? 'selected' : '' ?>><?= format_incidencia_tipo($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Estado</label>
      <select name="estado" class="form-control" style="width:auto;">
        <option value="">Todos</option>
        <?php foreach (INCIDENCIA_ESTADOS as $e): ?>
          <option value="<?= $e ?>" <?= $filtros['estado'] === $e ? 'selected' : '' ?>><?= format_incidencia_estado($e) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Socio</label>
      <select name="user_id" class="form-control" style="width:auto;min-width:160px;">
        <option value="">Todos</option>
        <?php foreach ($socios as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$filtros['user_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Desde</label>
      <input type="date" name="desde" value="<?= e($filtros['desde']) ?>" class="form-control" style="width:auto;">
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Hasta</label>
      <input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>" class="form-control" style="width:auto;">
    </div>
    <div class="form-group" style="margin:0;flex:1;min-width:160px;">
      <label class="form-label">Buscar título</label>
      <input type="text" name="q" value="<?= e($filtros['q']) ?>" class="form-control" placeholder="Texto…">
    </div>
    <div style="display:flex;gap:8px;align-items:flex-end;">
      <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
      <a href="/admin/incidencias" class="btn btn-gray">Limpiar</a>
    </div>
    <div style="margin-left:auto;">
      <a href="/admin/incidencias?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva incidencia</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <h2 class="card-title"><?= (int)$total ?> incidencia<?= $total === 1 ? '' : 's' ?></h2>
  </div>
  <?php if (!$incidencias): ?>
    <div style="padding:32px;text-align:center;color:var(--gray);">No hay incidencias con esos filtros.</div>
  <?php else: ?>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>Título</th>
          <th>Socio</th>
          <th>Estado</th>
          <th>Visible</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incidencias as $i): ?>
          <tr>
            <td>#<?= (int)$i['id'] ?></td>
            <td><?= date('d/m/Y', strtotime($i['fecha_suceso'])) ?></td>
            <td><span class="badge <?= badge_clase_tipo($i['tipo']) ?>"><?= format_incidencia_tipo($i['tipo']) ?></span></td>
            <td><?= e($i['titulo']) ?></td>
            <td><?= $i['socio_nombre'] ? e($i['socio_nombre']) : '<span class="text-muted">—</span>' ?></td>
            <td><span class="badge <?= badge_clase_estado($i['estado']) ?>"><?= format_incidencia_estado($i['estado']) ?></span></td>
            <td><?= (int)$i['visible_socio'] === 1 ? '✓' : '✗' ?></td>
            <td><a href="/admin/incidencias?ver=<?= (int)$i['id'] ?>" class="btn btn-gray btn-sm">Ver</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($totalPaginas > 1):
    $qs = $_GET; ?>
  <div style="padding:16px;display:flex;gap:8px;justify-content:center;align-items:center;border-top:1px solid #eee;">
    <?php for ($p = 1; $p <= $totalPaginas; $p++):
      $qs['p'] = $p; ?>
      <a href="?<?= http_build_query($qs) ?>" class="btn btn-sm <?= $p === $pagina ? 'btn-primary' : 'btn-gray' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php
});
render_footer();
