<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
$user = current_user();

$PROVES = ['50L', '100L', '200L', '400L', '800L', '1500L', '50E', '100E', '200E', '50B', '100B', '200B', '50M', '100M', '200M', '100X', '200X', '400X'];

// Primera visita (sense cap GET param): usar la categoria de l'usuari per defecte
$filterLliga   = array_key_exists('lliga', $_GET) ? $_GET['lliga'] : ($user['lliga'] ?? '');
$filterProva   = $_GET['prova']   ?? '';
$filterPiscina = $_GET['piscina'] ?? '25m';
$filterMillors = isset($_GET['millors']);
$sort         = $_GET['sort']    ?? 'temps';
$dir          = strtolower($_GET['dir'] ?? 'asc');

// Temporades disponibles (últimes 4), sense "Totes"
$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporades_disp = [];
for ($y = $current_year; $y >= 2012; $y--)
  $temporades_disp[] = $y . '-' . substr((string)($y + 1), 2);
$filterTemporada = $_GET['temporada'] ?? $temporades_disp[0];
if ($filterTemporada !== 'todas' && !in_array($filterTemporada, $temporades_disp)) $filterTemporada = $temporades_disp[0];

if (!in_array($filterProva, $PROVES)) $filterProva = '';
if (!in_array($filterPiscina, ['25m', '50m'])) $filterPiscina = '25m';
if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'desc';

$lligues_valides = ['benjamin', 'alevin', 'infantil', 'junior', 'absoluto', 'master'];
if ($filterLliga !== '' && !in_array($filterLliga, $lligues_valides, true)) {
  $filterLliga = $user['lliga'] ?? '';
}

$sortable = [
  'nom'   => 'u.nom',
  'prova' => 'm.prova',
  'lliga' => 'u.lliga',
  'sexe'  => 'u.sexe',
  'temps' => 'm.temps_seg',
  'lugar' => 'm.lugar',
  'data'  => 'm.data_marca',
];
if ($filterMillors) {
  $sortable['temporada'] = 'm.temporada';
}
if (!isset($sortable[$sort])) $sort = 'temps';
$orderSql = $sortable[$sort] . ' ' . strtoupper($dir) . ', m.prova ASC, m.temps_seg ASC, u.nom ASC';

if ($filterMillors) {
  // Millors marques: totes les marques, amb filtre opcional de temporada
  $where  = "WHERE m.piscina=? AND u.estado='activo'";
  $params = [$filterPiscina];
  if ($filterTemporada !== 'todas') { $where .= ' AND m.temporada=?'; $params[] = $filterTemporada; }
  if ($filterProva) {
    $where .= ' AND m.prova=?';
    $params[] = $filterProva;
  }
  if ($filterLliga && in_array($filterLliga, $lligues_valides)) {
    $where .= ' AND u.lliga=?';
    $params[] = $filterLliga;
  }
  $sql = "
        SELECT m.*, u.nom, u.sexe, u.lliga, u.id as uid
        FROM marques m
        JOIN users u ON u.id = m.user_id
        $where
        ORDER BY $orderSql
    ";
} else {
  $where  = "WHERE m.piscina=? AND u.estado='activo'";
  $params = [$filterPiscina];
  if ($filterTemporada !== 'todas') { $where .= ' AND m.temporada=?'; $params[] = $filterTemporada; }
  if ($filterProva) {
    $where .= ' AND m.prova=?';
    $params[] = $filterProva;
  }
  if ($filterLliga && in_array($filterLliga, $lligues_valides)) {
    $where .= ' AND u.lliga=?';
    $params[] = $filterLliga;
  }
  $sql = "
        SELECT m.*, u.nom, u.sexe, u.lliga, u.id as uid
        FROM marques m
        JOIN users u ON u.id = m.user_id
        $where
        ORDER BY $orderSql
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ranking = $stmt->fetchAll();

render_header('Ranking liga', 'soci-ranking');
?>

<div class="container page-content">
  <h1 style="margin-bottom:6px;">Ranking — <?= $filterLliga ? e(format_lliga($filterLliga)) : 'Todas las categorías' ?></h1>
  <style>
    @keyframes loading-spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    @keyframes loading-float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-4px); }
    }
  </style>
  <div id="pageLoadingOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);backdrop-filter:blur(2px);z-index:2000;align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:16px;padding:24px 28px;box-shadow:0 24px 80px rgba(15,23,42,0.22);min-width:260px;text-align:center;animation:loading-float 1.8s ease-in-out infinite;">
      <div style="font-size:28px;color:var(--blue);margin-bottom:10px;display:inline-flex;animation:loading-spin 1s linear infinite;"><i class="bi bi-arrow-repeat"></i></div>
      <div style="font-weight:700;margin-bottom:6px;">Cargando ranking</div>
      <div class="text-muted text-sm">Espera un momento, estamos aplicando los filtros.</div>
    </div>
  </div>
  <?php
  $sortUrl = function (string $column) use ($filterLliga, $filterProva, $filterPiscina, $filterTemporada, $filterMillors, $sort, $dir): string {
    $params = [
      'lliga' => $filterLliga,
      'prova' => $filterProva,
      'piscina' => $filterPiscina,
      'temporada' => $filterTemporada,
      'sort' => $column,
      'dir' => ($sort === $column && $dir === 'asc') ? 'desc' : 'asc',
    ];
    if ($filterMillors) $params['millors'] = '1';
    return '?' . http_build_query(array_filter($params, static fn($v) => $v !== '' && $v !== null));
  };
  $sortIcon = function (string $column) use ($sort, $dir): string {
    if ($sort !== $column) return ' ↕';
    return $dir === 'asc' ? ' ↑' : ' ↓';
  };
  ?>
  <!-- Filtros -->
  <div class="filters-bar">
    <form method="GET" class="filters-form js-loading-form">
      <?php if ($filterMillors): ?>
        <input type="hidden" name="millors" value="1">
      <?php endif; ?>
      <div class="form-group">
        <label class="form-label">Temporada</label>
        <select name="temporada" class="form-control">
          <option value="todas" <?= $filterTemporada === 'todas' ? 'selected' : '' ?>>Todas</option>
          <?php foreach ($temporades_disp as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterTemporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Categoría</label>
        <select name="lliga" class="form-control">
          <option value="">Todas</option>
          <?php foreach (['benjamin' => 'Benjamín', 'alevin' => 'Alevín', 'infantil' => 'Infantil', 'junior' => 'Junior', 'absoluto' => 'Absoluto', 'master' => 'Master'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $filterLliga === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Prueba</label>
        <select name="prova" class="form-control">
          <?php render_prova_options($filterProva, true); ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Piscina</label>
        <select name="piscina" class="form-control">
          <option value="25m" <?= $filterPiscina === '25m' ? 'selected' : '' ?>>25m</option>
          <option value="50m" <?= $filterPiscina === '50m' ? 'selected' : '' ?>>50m</option>
        </select>
      </div>
      <div class="form-group" style="align-self:flex-end;">
        <button type="submit" class="btn btn-primary">Filtrar</button>
      </div>
    </form>
    <?php
    $millors_params = http_build_query(array_filter([
      'temporada' => $filterTemporada,
      'prova'     => $filterProva,
      'piscina'   => $filterPiscina,
      'lliga'     => $filterLliga,
      'sort'      => $sort,
      'dir'       => $dir,
      'millors'   => $filterMillors ? null : '1',
    ]));
    ?>
    <a href="?<?= $millors_params ?>"
      class="btn btn-sm <?= $filterMillors ? 'btn-primary' : 'btn-gray' ?>"
      style="align-self:flex-end;">
      <i class="bi bi-trophy-fill"></i> Mejores marcas <?= $filterMillors ? '(actiu)' : '' ?>
    </a>
  </div>

  <script>
  function showPageLoading(message) {
    const overlay = document.getElementById('pageLoadingOverlay');
    if (!overlay) return;
    const text = overlay.querySelector('.text-muted');
    if (text && message) text.textContent = message;
    overlay.style.display = 'flex';
  }

  document.querySelectorAll('.js-loading-form').forEach(form => {
    form.addEventListener('submit', () => {
      showPageLoading('Espera un momento, estamos aplicando los filtros.');
    });
  });

  document.querySelectorAll('.js-loading-form select').forEach(select => {
    select.addEventListener('change', function () {
      showPageLoading('Espera un momento, estamos aplicando los filtros.');
      this.form.requestSubmit();
    });
  });

  window.addEventListener('pageshow', () => {
    const overlay = document.getElementById('pageLoadingOverlay');
    if (overlay) overlay.style.display = 'none';
  });
  </script>

  <!-- Ranking -->
  <div class="table-card">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th style="width:50px;">Pos.</th>
            <th><a href="<?= e($sortUrl('nom')) ?>">Nombre<?= $sortIcon('nom') ?></a></th>
            <?php if (!$filterProva): ?><th><a href="<?= e($sortUrl('prova')) ?>">Prueba<?= $sortIcon('prova') ?></a></th><?php endif; ?>
            <th><a href="<?= e($sortUrl('lliga')) ?>">Categoría<?= $sortIcon('lliga') ?></a></th>
            <th><a href="<?= e($sortUrl('sexe')) ?>">Sexo<?= $sortIcon('sexe') ?></a></th>
            <th><a href="<?= e($sortUrl('temps')) ?>">Tiempo<?= $sortIcon('temps') ?></a></th>
            <th><a href="<?= e($sortUrl('lugar')) ?>">Lugar<?= $sortIcon('lugar') ?></a></th>
            <th><a href="<?= e($sortUrl('data')) ?>">Fecha<?= $sortIcon('data') ?></a></th>
            <?php if ($filterMillors): ?><th><a href="<?= e($sortUrl('temporada')) ?>">Temporada<?= $sortIcon('temporada') ?></a></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          $colspan = 7 + (!$filterProva ? 1 : 0) + ($filterMillors ? 1 : 0);
          if (!$ranking): ?>
            <tr>
              <td colspan="<?= $colspan ?>" class="text-center text-muted" style="padding:40px;">
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
              <?php if (!$filterProva): ?>
                <td class="text-sm"><?= e(format_prova($row['prova'])) ?></td>
              <?php endif; ?>
              <td><span class="badge badge-gray"><?= e(format_lliga($row['lliga'] ?? '')) ?></span></td>
              <td><?= $row['sexe'] === 'M' ? 'Masc.' : 'Fem.' ?></td>
              <td><span class="mark-time"><?= e($row['temps']) ?></span></td>
              <td class="text-sm text-muted"><?= e($row['lugar'] ?? '') ?></td>
              <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($row['data_marca'])) ?></td>
              <?php if ($filterMillors): ?>
                <td><span class="badge badge-gray"><?= e($row['temporada']) ?></span></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php render_footer(); ?>
