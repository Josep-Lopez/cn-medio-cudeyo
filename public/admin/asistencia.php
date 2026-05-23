<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

$LIGAS = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','absoluto'=>'Absoluto','master'=>'Master'];

// ── POST: guardar asistencia ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $asistencias = $_POST['asistencia'] ?? [];
    $observaciones = $_POST['observaciones'] ?? [];
    $admin_id = current_user()['id'];

    $stmt = $pdo->prepare('
        INSERT INTO asistencia (user_id, fecha, presente, observaciones, registrado_por)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE presente=VALUES(presente), observaciones=VALUES(observaciones), registrado_por=VALUES(registrado_por)
    ');

    $users_ids = $_POST['user_ids'] ?? [];
    foreach ($users_ids as $uid) {
        $uid = (int)$uid;
        $presente = isset($asistencias[$uid]) ? 1 : 0;
        $obs = trim($observaciones[$uid] ?? '');
        $stmt->execute([$uid, $fecha, $presente, $obs ?: null, $admin_id]);
    }

    flash('Asistencia guardada correctamente.', 'success');
    header('Location: /admin/asistencia?fecha=' . urlencode($fecha) . '&liga=' . urlencode($_POST['liga_back'] ?? ''));
    exit;
}

// ── Filtros ──────────────────────────────────────────────────────────────────
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$filtroLiga = $_GET['liga'] ?? 'todos';
if ($filtroLiga !== 'todos' && !array_key_exists($filtroLiga, $LIGAS)) $filtroLiga = 'todos';

// Cargar nadadores activos (solo si hay categoría seleccionada o "todos")
$where = "estado='activo' AND rol='socio' AND nadador_activo=1";
$params = [];
if ($filtroLiga && $filtroLiga !== 'todos') {
    $where .= ' AND liga=?';
    $params[] = $filtroLiga;
}
$stmt = $pdo->prepare("SELECT id, nombre, liga, sexo FROM users WHERE $where ORDER BY nombre");
$stmt->execute($params);
$nadadores = $stmt->fetchAll();

// Cargar asistencia existente para esa fecha
$stmt = $pdo->prepare('SELECT user_id, presente, observaciones FROM asistencia WHERE fecha=?');
$stmt->execute([$fecha]);
$registros = [];
foreach ($stmt->fetchAll() as $r) {
    $registros[$r['user_id']] = $r;
}

// Estadísticas del día
$total = count($nadadores);
$presentes = 0;
foreach ($nadadores as $n) {
    if (!empty($registros[$n['id']]['presente'])) $presentes++;
}

render_header('Control de asistencia', 'admin-asistencia');
render_admin_layout('asistencia', function() use ($LIGAS, $nadadores, $registros, $fecha, $filtroLiga, $total, $presentes) {
?>

<h1>Control de asistencia</h1>
<?php render_flash(); ?>

<!-- Filtros -->
<div class="card mb-6">
  <div class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;">
      <label class="form-label">Fecha</label>
      <input type="date" class="form-control" value="<?= e($fecha) ?>"
             onchange="window.location='?fecha='+this.value+'&liga=<?= e($filtroLiga) ?>'"
             style="width:auto;">
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Categoría</label>
      <select class="form-control" style="width:auto;min-width:160px;"
              onchange="window.location='?fecha=<?= e($fecha) ?>&liga='+this.value">
        <option value="todos" <?= $filtroLiga === 'todos' ? 'selected' : '' ?>>Todos</option>
        <?php foreach ($LIGAS as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroLiga === $k ? 'selected' : '' ?>><?= $v ?></option>
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
              <td style="font-weight:600;"><?= e($n['nombre']) ?></td>
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
