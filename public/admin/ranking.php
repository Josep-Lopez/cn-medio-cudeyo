<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

$PROVES = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];

$filterLliga    = $_GET['lliga']    ?? '';
$filterProva    = $_GET['prova']    ?? '50L';
$filterPiscina  = $_GET['piscina']  ?? '25m';
$filterTemporada = $_GET['temporada'] ?? '2025-26';

// Validar
if (!in_array($filterProva, $PROVES)) $filterProva = '50L';
if (!in_array($filterPiscina, ['25m','50m'])) $filterPiscina = '25m';

// Construir query
$params = [$filterProva, $filterPiscina, $filterTemporada];
$where  = 'WHERE m.prova=? AND m.piscina=? AND m.temporada=? AND u.estado=\'activo\'';
if ($filterLliga && in_array($filterLliga, ['benjamin','alevin','infantil','junior','master'])) {
    $where  .= ' AND u.lliga=?';
    $params[] = $filterLliga;
}

$sql = "
    SELECT m.*, u.nom, u.lliga, u.sexe
    FROM marques m
    JOIN users u ON u.id = m.user_id
    $where
    ORDER BY m.temps_seg ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ranking = $stmt->fetchAll();

render_header('Ranking general', 'admin-ranking');
render_admin_layout('ranking', function() use ($PROVES, $ranking, $filterLliga, $filterProva, $filterPiscina, $filterTemporada) {
?>

<h1>Ranking general</h1>

<!-- Filtros -->
<div class="filters-bar">
  <form method="GET" class="filters-form">
    <div class="form-group">
      <label class="form-label">Prueba</label>
      <select name="prova" class="form-control">
        <?php render_prova_options($filterProva); ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Piscina</label>
      <select name="piscina" class="form-control">
        <option value="25m" <?= $filterPiscina === '25m' ? 'selected' : '' ?>>25m</option>
        <option value="50m" <?= $filterPiscina === '50m' ? 'selected' : '' ?>>50m</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Categoría</label>
      <select name="lliga" class="form-control">
        <option value="">Todas</option>
        <?php foreach (['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','master'=>'Master'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $filterLliga === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Temporada</label>
      <select name="temporada" class="form-control">
        <option value="2025-26" <?= $filterTemporada === '2025-26' ? 'selected' : '' ?>>2025-26</option>
        <option value="2024-25" <?= $filterTemporada === '2024-25' ? 'selected' : '' ?>>2024-25</option>
      </select>
    </div>
    <div class="form-group" style="align-self:flex-end;">
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </div>
  </form>
</div>

<!-- Cabecera del ranking -->
<?php if ($ranking): ?>
<div style="margin-bottom:12px;" class="d-flex justify-between align-center">
  <span class="text-muted text-sm"><?= count($ranking) ?> resultado<?= count($ranking) !== 1 ? 's' : '' ?> · <?= e(format_prova($filterProva)) ?> · <?= e($filterPiscina) ?></span>
</div>
<?php endif; ?>

<div class="table-card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th style="width:50px;">Pos.</th>
          <th>Nombre</th>
          <th>Categoría</th>
          <th>Sexo</th>
          <th>Tiempo</th>
          <th>Fecha</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$ranking): ?>
          <tr>
            <td colspan="6" class="text-center text-muted" style="padding:40px;">
              No hay marcas para los filtros seleccionados.
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($ranking as $i => $row): ?>
        <tr>
          <td>
            <span class="rank-pos <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>">
              <?= $i + 1 ?>
              <?= $i === 0 ? ' 🥇' : ($i === 1 ? ' 🥈' : ($i === 2 ? ' 🥉' : '')) ?>
            </span>
          </td>
          <td><strong><?= e($row['nom']) ?></strong></td>
          <td><span class="badge badge-blue"><?= e(format_lliga($row['lliga'] ?? '')) ?></span></td>
          <td><?= $row['sexe'] === 'M' ? 'Masc.' : 'Fem.' ?></td>
          <td><span class="mark-time"><?= e($row['temps']) ?></span></td>
          <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($row['data_marca'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
});
render_footer();
