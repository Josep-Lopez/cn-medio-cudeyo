<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/incidencias.php';

require_admin();

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
render_admin_layout('incidencias', function() use ($filtros, $incidencias, $total, $pagina, $totalPaginas, $socios) {
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
