<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/grupos.php';

require_admin_area(['director_tecnico', 'entrenador']);

$LIGAS = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','absoluto'=>'Absoluto','master'=>'Master'];

// ── POST: guardar asistencia ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verificar', 'rechazar'], true)) {
    csrf_verify();
    $asis_id = (int)($_POST['asistencia_id'] ?? 0);
    if ($_POST['action'] === 'verificar') {
        $pdo->prepare('UPDATE asistencia SET verificada=1, registrado_por=? WHERE id=? AND verificada=0')
            ->execute([current_user()['id'], $asis_id]);
        flash('Entrenamiento verificado.', 'success');
    } else {
        $pdo->prepare('DELETE FROM asistencia WHERE id=? AND verificada=0')->execute([$asis_id]);
        flash('Entrenamiento rechazado.', 'success');
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $asistencias = $_POST['asistencia'] ?? [];
    $observaciones = $_POST['observaciones'] ?? [];
    $admin_id = current_user()['id'];

    $stmt = $pdo->prepare('
        INSERT INTO asistencia (user_id, fecha, presente, verificada, observaciones, registrado_por)
        VALUES (?, ?, ?, 1, ?, ?)
        ON DUPLICATE KEY UPDATE presente=VALUES(presente), verificada=1, observaciones=VALUES(observaciones), registrado_por=VALUES(registrado_por)
    ');

    $users_ids = $_POST['user_ids'] ?? [];
    foreach ($users_ids as $uid) {
        $uid = (int)$uid;
        $presente = isset($asistencias[$uid]) ? 1 : 0;
        $obs = trim($observaciones[$uid] ?? '');
        $stmt->execute([$uid, $fecha, $presente, $obs ?: null, $admin_id]);
    }

    flash('Asistencia guardada correctamente.', 'success');
    header('Location: /admin/asistencia?fecha=' . urlencode($fecha) . '&liga=' . urlencode($_POST['liga_back'] ?? '') . '&grupo=' . (int)($_POST['grupo_back'] ?? 0));
    exit;
}

// ── Filtros ──────────────────────────────────────────────────────────────────
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$filtroLiga = $_GET['liga'] ?? 'todos';
if ($filtroLiga !== 'todos' && !array_key_exists($filtroLiga, $LIGAS)) $filtroLiga = 'todos';
$filtroGrupo = isset($_GET['grupo']) ? (int)$_GET['grupo'] : 0;
$grupos = listar_grupos($pdo);

// Cargar nadadores activos (solo si hay categoría seleccionada o "todos")
$where = "estado='activo' AND rol='socio' AND nadador_activo=1";
$params = [];
if ($filtroLiga && $filtroLiga !== 'todos') {
    $where .= ' AND liga=?';
    $params[] = $filtroLiga;
}
if ($filtroGrupo > 0) {
    $where .= ' AND id IN (SELECT user_id FROM grupo_nadadores WHERE grupo_id=?)';
    $params[] = $filtroGrupo;
}
$stmt = $pdo->prepare("SELECT id, nombre, liga, sexo FROM users WHERE $where ORDER BY nombre");
$stmt->execute($params);
$nadadores = $stmt->fetchAll();

// Cargar asistencia existente para esa fecha
$stmt = $pdo->prepare('SELECT user_id, presente, verificada, observaciones FROM asistencia WHERE fecha=?');
$stmt->execute([$fecha]);
$registros = [];
foreach ($stmt->fetchAll() as $r) {
    $registros[$r['user_id']] = $r;
}

// Entrenamientos apuntados por los deportistas pendientes de verificar
$pendientes = $pdo->query("
    SELECT a.id, a.fecha, a.observaciones, u.id AS user_id, u.nombre, u.liga
    FROM asistencia a JOIN users u ON u.id = a.user_id
    WHERE a.verificada = 0
    ORDER BY a.fecha DESC, u.nombre
")->fetchAll();

// Estadísticas del día
$total = count($nadadores);
$presentes = 0;
foreach ($nadadores as $n) {
    if (!empty($registros[$n['id']]['presente']) && !empty($registros[$n['id']]['verificada'])) $presentes++;
}

render_header('Control de asistencia', 'admin-asistencia');
render_admin_layout('asistencia', function() use ($LIGAS, $grupos, $nadadores, $registros, $fecha, $filtroLiga, $filtroGrupo, $total, $presentes, $pendientes) {
?>

<h1>Control de asistencia</h1>
<?php render_flash(); ?>

<?php if ($pendientes): ?>
<div class="card mb-6" style="border-left:4px solid #f59e0b;">
  <h2 style="font-size:15px;font-weight:700;margin-bottom:14px;">
    <i class="bi bi-hourglass-split" style="color:#b45309;"></i>
    Pendientes de verificar (<?= count($pendientes) ?>)
  </h2>
  <div class="table-wrapper">
    <table>
      <thead><tr><th>Fecha</th><th>Nadador</th><th>Categoría</th><th>Comentario</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendientes as $p): ?>
          <tr>
            <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($p['fecha'])) ?> <span class="text-muted text-sm"><?= ['','Lun','Mar','Mié','Jue','Vie','Sáb','Dom'][(int)date('N', strtotime($p['fecha']))] ?></span></td>
            <td style="font-weight:600;"><a href="/admin/deportista?id=<?= (int)$p['user_id'] ?>&mes=<?= substr($p['fecha'], 0, 7) ?>#asistencia"><?= e($p['nombre']) ?></a></td>
            <td><span class="text-sm"><?= e(format_liga($p['liga'] ?? '')) ?></span></td>
            <td class="text-sm"><?= e($p['observaciones'] ?: '—') ?></td>
            <td style="white-space:nowrap;text-align:right;">
              <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="asistencia_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" name="action" value="verificar" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Verificar</button>
                <button type="submit" name="action" value="rechazar" class="btn btn-gray btn-sm" style="color:var(--red);"><i class="bi bi-x-lg"></i> Rechazar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Filtros -->
<div class="card mb-6">
  <div class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;">
      <label class="form-label">Fecha</label>
      <input type="date" class="form-control" value="<?= e($fecha) ?>"
             onchange="window.location='?fecha='+this.value+'&liga=<?= e($filtroLiga) ?>&grupo=<?= (int)$filtroGrupo ?>'"
             style="width:auto;">
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Categoría</label>
      <select class="form-control" style="width:auto;min-width:160px;"
              onchange="window.location='?fecha=<?= e($fecha) ?>&liga='+this.value+'&grupo=<?= (int)$filtroGrupo ?>'">
        <option value="todos" <?= $filtroLiga === 'todos' ? 'selected' : '' ?>>Todos</option>
        <?php foreach ($LIGAS as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroLiga === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Grupo</label>
      <select class="form-control" style="width:auto;min-width:160px;"
              onchange="window.location='?fecha=<?= e($fecha) ?>&liga=<?= e($filtroLiga) ?>&grupo='+this.value">
        <option value="0">Todos</option>
        <?php foreach ($grupos as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $filtroGrupo === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;flex:1;min-width:200px;">
      <label class="form-label">Buscar nadador</label>
      <input type="text" id="buscador" class="form-control" placeholder="Nombre…"
             oninput="filtrarNadadores(this.value)">
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:16px;">
      <div style="text-align:center;">
        <div style="font-size:24px;font-weight:800;color:var(--blue);"><?= $presentes ?>/<?= $total ?></div>
        <div class="text-muted text-sm">Presentes</div>
      </div>
    </div>
  </div>
</div>

<?php if (!$nadadores): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay nadadores activos en esta categoría.</p>
  </div>
<?php else: ?>
<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
  <input type="hidden" name="liga_back" value="<?= e($filtroLiga) ?>">
  <input type="hidden" name="grupo_back" value="<?= (int)$filtroGrupo ?>">

  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><?= date('d/m/Y', strtotime($fecha)) ?></h2>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-gray btn-sm" onclick="toggleAll(true)">Marcar todos</button>
        <button type="button" class="btn btn-gray btn-sm" onclick="toggleAll(false)">Desmarcar todos</button>
      </div>
    </div>

    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th style="width:40px;">
              <input type="checkbox" id="checkAll" onchange="toggleAll(this.checked)">
            </th>
            <th>Nadador</th>
            <th>Categoría</th>
            <th>Observaciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($nadadores as $n):
            $reg = $registros[$n['id']] ?? null;
            $checked = $reg && (int)$reg['presente'] === 1;
            $obs = $reg['observaciones'] ?? '';
          ?>
            <tr data-nombre="<?= e(mb_strtolower($n['nombre'])) ?>">
              <td>
                <input type="hidden" name="user_ids[]" value="<?= $n['id'] ?>">
                <input type="checkbox" name="asistencia[<?= $n['id'] ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
              </td>
              <td style="font-weight:600;"><?= e($n['nombre']) ?>
                <?php if ($reg && !(int)$reg['verificada']): ?>
                  <span class="badge" style="background:#fef3c7;color:#b45309;font-size:11px;" title="Apuntado por el deportista; se verifica al guardar">pendiente</span>
                <?php endif; ?>
              </td>
              <td><span class="text-sm"><?= e(format_liga($n['liga'] ?? '')) ?></span></td>
              <td>
                <input type="text" name="observaciones[<?= $n['id'] ?>]" class="form-control"
                       value="<?= e($obs) ?>" placeholder="—"
                       style="padding:5px 8px;font-size:13px;min-width:150px;">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="guardar-bar" style="padding:16px;border-top:1px solid #eee;">
      <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar asistencia</button>
    </div>
  </div>
</form>

<button type="button" id="fab-guardar" onclick="irAGuardar()"
        title="Ir a guardar"
        style="position:fixed;bottom:24px;right:24px;width:52px;height:52px;border-radius:50%;
               background:var(--blue);color:#fff;border:none;box-shadow:0 4px 12px rgba(0,0,0,.25);
               cursor:pointer;font-size:22px;display:none;align-items:center;justify-content:center;z-index:1000;">
  <i class="bi bi-arrow-down"></i>
</button>
<?php endif; ?>

<script>
function toggleAll(checked) {
  document.querySelectorAll('tbody tr:not([hidden]) input[name^="asistencia["]').forEach(function(cb) {
    cb.checked = checked;
  });
  document.getElementById('checkAll').checked = checked;
}
function filtrarNadadores(q) {
  var term = q.toLowerCase().trim();
  document.querySelectorAll('tbody tr').forEach(function(tr) {
    var nombre = tr.getAttribute('data-nombre') || '';
    tr.hidden = term && nombre.indexOf(term) === -1;
  });
}
function irAGuardar() {
  var bar = document.getElementById('guardar-bar');
  if (bar) bar.scrollIntoView({behavior:'smooth', block:'center'});
}
(function() {
  var fab = document.getElementById('fab-guardar');
  var bar = document.getElementById('guardar-bar');
  if (!fab || !bar) return;
  function toggle() {
    var rect = bar.getBoundingClientRect();
    var visible = rect.top < window.innerHeight - 40;
    fab.style.display = visible ? 'none' : 'flex';
  }
  window.addEventListener('scroll', toggle, {passive:true});
  window.addEventListener('resize', toggle);
  toggle();
})();
</script>

<?php
});
render_footer();
