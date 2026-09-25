<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/grupos.php';

require_admin_area(['director_tecnico', 'entrenador']);

$LIGAS = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','absoluto'=>'Absoluto','master'=>'Master'];

$filtroLiga = $_GET['liga'] ?? 'todos';
if ($filtroLiga !== 'todos' && !array_key_exists($filtroLiga, $LIGAS)) $filtroLiga = 'todos';
$filtroGrupo = isset($_GET['grupo']) ? (int)$_GET['grupo'] : 0;
$grupos = listar_grupos($pdo);

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

render_header('Deportistas', 'admin-deportistas');
render_admin_layout('deportistas', function() use ($LIGAS, $grupos, $nadadores, $filtroLiga, $filtroGrupo) {
?>

<h1>Deportistas</h1>
<?php render_flash(); ?>

<div class="card mb-6">
  <div class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;">
      <label class="form-label">Categoría</label>
      <select class="form-control" style="width:auto;min-width:160px;"
              onchange="window.location='?liga='+this.value+'&grupo=<?= (int)$filtroGrupo ?>'">
        <option value="todos" <?= $filtroLiga === 'todos' ? 'selected' : '' ?>>Todos</option>
        <?php foreach ($LIGAS as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroLiga === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Grupo</label>
      <select class="form-control" style="width:auto;min-width:160px;"
              onchange="window.location='?liga=<?= e($filtroLiga) ?>&grupo='+this.value">
        <option value="0">Todos</option>
        <?php foreach ($grupos as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $filtroGrupo === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;flex:1;min-width:180px;">
      <label class="form-label">Buscar nadador</label>
      <input type="text" id="buscador" class="form-control" placeholder="Nombre…"
             oninput="filtrarNadadores(this.value)">
    </div>
  </div>
</div>

<?php if (!$nadadores): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay nadadores activos con estos filtros.</p>
  </div>
<?php else: ?>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead><tr><th>Nadador/a</th><th>Categoría</th><th>Sexo</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($nadadores as $n): ?>
          <tr data-nombre="<?= e(mb_strtolower($n['nombre'])) ?>">
            <td style="font-weight:600;"><?= e($n['nombre']) ?></td>
            <td><?= e(format_liga($n['liga'] ?? '')) ?></td>
            <td><?= e($n['sexo'] ?? '') ?></td>
            <td><a href="/admin/deportista?id=<?= (int)$n['id'] ?>" class="btn btn-gray btn-sm">Ver perfil →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<script>
function filtrarNadadores(q) {
  var term = q.toLowerCase().trim();
  document.querySelectorAll('tbody tr').forEach(function(tr) {
    var nombre = tr.getAttribute('data-nombre') || '';
    tr.hidden = term && nombre.indexOf(term) === -1;
  });
}
</script>

<?php
});
render_footer();
