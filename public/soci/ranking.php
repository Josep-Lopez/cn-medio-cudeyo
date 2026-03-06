<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
$user = current_user();

$PROVES = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];

// Filtros
$filterLliga   = $_GET['lliga']    ?? ($user['lliga'] ?? '');
$filterProva   = $_GET['prova']    ?? '50L';
$filterPiscina = $_GET['piscina']  ?? '25m';
$filterTemporada = '2025-26';

if (!in_array($filterProva, $PROVES)) $filterProva = '50L';
if (!in_array($filterPiscina, ['25m','50m'])) $filterPiscina = '25m';

// Query ranking
$params = [$filterProva, $filterPiscina, $filterTemporada];
$where  = 'WHERE m.prova=? AND m.piscina=? AND m.temporada=? AND u.estado=\'activo\'';
if ($filterLliga && in_array($filterLliga, ['benjamin','alevin','infantil','junior','master'])) {
    $where  .= ' AND u.lliga=?';
    $params[] = $filterLliga;
}
$sql = "
    SELECT m.*, u.nom, u.lliga, u.sexe, u.id as uid
    FROM marques m
    JOIN users u ON u.id = m.user_id
    $where
    ORDER BY m.temps_seg ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ranking = $stmt->fetchAll();

render_header('Ranking liga', 'soci-ranking');
?>

<div class="container page-content">
  <h1 style="margin-bottom:6px;">Ranking — <?= $filterLliga ? e(format_lliga($filterLliga)) : 'Todos' ?></h1>
  <p class="text-muted mb-6"><?= e(format_prova($filterProva)) ?> · Piscina <?= e($filterPiscina) ?> · Temporada <?= $filterTemporada ?></p>

  <!-- Filtros -->
  <div class="filters-bar">
    <form method="GET" class="d-flex gap-3 align-center flex-wrap" style="width:100%;">
      <div class="form-group" style="margin:0;min-width:150px;">
        <label class="form-label">Categoría</label>
        <select name="lliga" class="form-control">
          <option value="">Todas las ligas</option>
          <?php foreach (['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','master'=>'Master'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $filterLliga === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:160px;">
        <label class="form-label">Prueba</label>
        <select name="prova" class="form-control">
          <?php render_prova_options($filterProva); ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Piscina</label>
        <select name="piscina" class="form-control">
          <option value="25m" <?= $filterPiscina === '25m' ? 'selected' : '' ?>>25m</option>
          <option value="50m" <?= $filterPiscina === '50m' ? 'selected' : '' ?>>50m</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;align-self:flex-end;">
        <button type="submit" class="btn btn-primary">Filtrar</button>
      </div>
      <?php if ($user['lliga']): ?>
        <div class="form-group" style="margin:0;align-self:flex-end;">
          <a href="?lliga=<?= e($user['lliga']) ?>&prova=<?= e($filterProva) ?>&piscina=<?= e($filterPiscina) ?>" class="btn btn-secondary">
            Mi liga (<?= e(format_lliga($user['lliga'])) ?>)
          </a>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- Ranking -->
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
                No hay marcas registradas para esta selección.
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($ranking as $i => $row): ?>
          <tr <?= $row['uid'] == $user['id'] ? 'style="background:#eef2ff;"' : '' ?>>
            <td>
              <span class="rank-pos <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>">
                <?= $i + 1 ?>
                <?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : '')) ?>
              </span>
            </td>
            <td>
              <strong><?= e($row['nom']) ?></strong>
              <?= $row['uid'] == $user['id'] ? '<span class="badge badge-blue" style="margin-left:6px;">Tú</span>' : '' ?>
            </td>
            <td><span class="badge badge-gray"><?= e(format_lliga($row['lliga'] ?? '')) ?></span></td>
            <td><?= $row['sexe'] === 'M' ? 'Masc.' : 'Fem.' ?></td>
            <td><span class="mark-time"><?= e($row['temps']) ?></span></td>
            <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($row['data_marca'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php render_footer(); ?>
