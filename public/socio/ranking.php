<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
require_nadador_activo();
$user = current_user();

$PRUEBAS = ['50L', '100L', '200L', '400L', '800L', '1500L', '50E', '100E', '200E', '50B', '100B', '200B', '50M', '100M', '200M', '100X', '200X', '400X'];

// Primera visita (sin ningún GET param): usar la categoría y sexo del usuario por defecto
$filterLiga    = array_key_exists('liga', $_GET) ? $_GET['liga'] : ($user['liga'] ?? '');
$filterPrueba  = $_GET['prueba']  ?? '';
$filterPiscina = $_GET['piscina'] ?? '25m';
$filterSexo    = array_key_exists('sexo', $_GET) ? $_GET['sexo'] : ($user['sexo'] ?? '');
if (!in_array($filterSexo, ['M', 'F', ''], true)) $filterSexo = '';
$filterNadador = $_GET['nadador'] ?? '1';
if (!in_array($filterNadador, ['1', '0', ''], true)) $filterNadador = '1';
$filterMejores = isset($_GET['mejores']);
$filterTop10   = isset($_GET['top10']);
$sort         = $_GET['sort']    ?? 'tiempo';
$dir          = strtolower($_GET['dir'] ?? 'asc');

// Temporadas disponibles (últimas 4), sin "Todas"
$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporadas_disp = [];
for ($y = $current_year; $y >= 2012; $y--)
  $temporadas_disp[] = $y . '-' . substr((string)($y + 1), 2);
$filterTemporada = $_GET['temporada'] ?? $temporadas_disp[0];
if ($filterTemporada !== 'todas' && !in_array($filterTemporada, $temporadas_disp)) $filterTemporada = $temporadas_disp[0];

if (!in_array($filterPrueba, $PRUEBAS)) $filterPrueba = '';
if (!in_array($filterPiscina, ['25m', '50m'])) $filterPiscina = '25m';
if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'desc';

$ligas_validas = ['benjamin', 'alevin', 'infantil', 'junior', 'absoluto', 'master'];
if ($filterLiga !== '' && !in_array($filterLiga, $ligas_validas, true)) {
  $filterLiga = $user['liga'] ?? '';
}

$sortable = [
  'nombre' => 'u.nombre',
  'prueba' => 'm.prueba',
  'liga'   => 'u.liga',
  'sexo'   => 'u.sexo',
  'tiempo' => 'm.tiempo_seg',
  'lugar'  => 'm.lugar',
  'fecha'  => 'm.fecha_marca',
];
if ($filterMejores) {
  $sortable['temporada'] = 'm.temporada';
}
if (!isset($sortable[$sort])) $sort = 'tiempo';
$orderSql = $sortable[$sort] . ' ' . strtoupper($dir) . ', m.prueba ASC, m.tiempo_seg ASC, u.nombre ASC';

if ($filterMejores) {
  // Mejores marcas: mejor tiempo por nadador y prueba
  $where  = "WHERE m.piscina=? AND u.estado='activo'";
  $params = [$filterPiscina];
  $sub_where = "WHERE m2.piscina=?";
  $sub_params = [$filterPiscina];
  if ($filterTemporada !== 'todas') {
    $where .= ' AND m.temporada=?'; $params[] = $filterTemporada;
    $sub_where .= ' AND m2.temporada=?'; $sub_params[] = $filterTemporada;
  }
  if ($filterPrueba) {
    $where .= ' AND m.prueba=?'; $params[] = $filterPrueba;
    $sub_where .= ' AND m2.prueba=?'; $sub_params[] = $filterPrueba;
  }
  if ($filterLiga && in_array($filterLiga, $ligas_validas)) {
    $where .= ' AND u.liga=?'; $params[] = $filterLiga;
  }
  if ($filterSexo) {
    $where .= ' AND u.sexo=?'; $params[] = $filterSexo;
  }
  if ($filterNadador !== '') {
    $where .= ' AND u.nadador_activo=?'; $params[] = (int)$filterNadador;
  }
  $sql = "
        SELECT m.*, u.nombre, u.sexo, u.liga, u.id as uid
        FROM marcas m
        JOIN users u ON u.id = m.user_id
        INNER JOIN (
            SELECT m2.user_id, m2.prueba, MIN(m2.tiempo_seg) AS best_seg
            FROM marcas m2
            $sub_where
            GROUP BY m2.user_id, m2.prueba
        ) best ON best.user_id = m.user_id AND best.prueba = m.prueba AND best.best_seg = m.tiempo_seg
        $where
        ORDER BY $orderSql
    ";
  $params = array_merge($sub_params, $params);
} else {
  $where  = "WHERE m.piscina=? AND u.estado='activo'";
  $params = [$filterPiscina];
  if ($filterTemporada !== 'todas') { $where .= ' AND m.temporada=?'; $params[] = $filterTemporada; }
  if ($filterPrueba) {
    $where .= ' AND m.prueba=?';
    $params[] = $filterPrueba;
  }
  if ($filterLiga && in_array($filterLiga, $ligas_validas)) {
    $where .= ' AND u.liga=?';
    $params[] = $filterLiga;
  }
  if ($filterSexo) {
    $where .= ' AND u.sexo=?';
    $params[] = $filterSexo;
  }
  if ($filterNadador !== '') {
    $where .= ' AND u.nadador_activo=?';
    $params[] = (int)$filterNadador;
  }
  $sql = "
        SELECT m.*, u.nombre, u.sexo, u.liga, u.id as uid
        FROM marcas m
        JOIN users u ON u.id = m.user_id
        $where
        ORDER BY $orderSql
    ";
}

if ($filterTop10) $sql .= ' LIMIT 10';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ranking = $stmt->fetchAll();

// Récords del club: marcas que fueron la mejor del club en el momento de conseguirse
$records_stmt = $pdo->query("
    SELECT m.id, m.prueba, m.piscina, u.sexo, m.tiempo_seg
    FROM marcas m
    JOIN users u ON u.id = m.user_id
    WHERE u.estado = 'activo'
      AND NOT EXISTS (
        SELECT 1 FROM marcas m2
        JOIN users u2 ON u2.id = m2.user_id
        WHERE m2.prueba = m.prueba
          AND m2.piscina = m.piscina
          AND u2.sexo = u.sexo
          AND u2.estado = 'activo'
          AND m2.fecha_marca < m.fecha_marca
          AND m2.tiempo_seg <= m.tiempo_seg
      )
");
$current_best_stmt = $pdo->query("
    SELECT m.prueba, m.piscina, u.sexo, MIN(m.tiempo_seg) AS best_seg
    FROM marcas m
    JOIN users u ON u.id = m.user_id
    WHERE u.estado = 'activo'
    GROUP BY m.prueba, m.piscina, u.sexo
");
$current_bests = [];
foreach ($current_best_stmt->fetchAll() as $cb) {
    $current_bests[$cb['prueba'] . '_' . $cb['piscina'] . '_' . $cb['sexo']] = (float)$cb['best_seg'];
}
$club_records = [];
foreach ($records_stmt->fetchAll() as $r) {
    $key = $r['prueba'] . '_' . $r['piscina'] . '_' . $r['sexo'];
    $is_current = isset($current_bests[$key]) && (float)$r['tiempo_seg'] <= $current_bests[$key];
    $club_records[(int)$r['id']] = $is_current ? 'actual' : 'historico';
}

render_header('Ranking liga', 'socio-ranking');
?>

<div class="container page-content">
  <?php $hasFilters = $filterPrueba || $filterLiga || $filterMejores || $filterTop10 || $filterTemporada !== $temporadas_disp[0] || $filterPiscina !== '25m'; ?>
  <h1 style="margin-bottom:6px;">Ranking — <?= $filterLiga ? e(format_liga($filterLiga)) : 'Todas las categorías' ?></h1>
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
  $sortUrl = function (string $column) use ($filterLiga, $filterPrueba, $filterPiscina, $filterTemporada, $filterSexo, $filterNadador, $filterMejores, $filterTop10, $sort, $dir): string {
    $params = [
      'liga' => $filterLiga,
      'prueba' => $filterPrueba,
      'piscina' => $filterPiscina,
      'temporada' => $filterTemporada,
      'sexo' => $filterSexo,
      'nadador' => $filterNadador,
      'sort' => $column,
      'dir' => ($sort === $column && $dir === 'asc') ? 'desc' : 'asc',
    ];
    if ($filterMejores) $params['mejores'] = '1';
    if ($filterTop10) $params['top10'] = '1';
    return '?' . http_build_query(array_filter($params, static fn($v) => $v !== '' && $v !== null));
  };
  $sortIcon = function (string $column) use ($sort, $dir): string {
    if ($sort !== $column) return ' ↕';
    return $dir === 'asc' ? ' ↑' : ' ↓';
  };
  ?>
  <!-- Filtros -->
  <?php
  $base_filters = [
    'temporada' => $filterTemporada,
    'prueba'    => $filterPrueba,
    'piscina'   => $filterPiscina,
    'liga'      => $filterLiga,
    'sexo'      => $filterSexo,
    'nadador'   => $filterNadador,
    'sort'      => $sort,
    'dir'       => $dir,
  ];
  if ($filterMejores) $base_filters['mejores'] = '1';
  if ($filterTop10) $base_filters['top10'] = '1';
  ?>
  <div class="filters-bar" style="flex-direction:column;gap:16px;">
    <form method="GET" class="filters-form js-loading-form">
      <?php if ($filterMejores): ?><input type="hidden" name="mejores" value="1"><?php endif; ?>
      <?php if ($filterTop10): ?><input type="hidden" name="top10" value="1"><?php endif; ?>
      <input type="hidden" name="sexo" value="<?= e($filterSexo) ?>">
      <input type="hidden" name="nadador" value="<?= e($filterNadador) ?>">
      <div class="form-group">
        <label class="form-label">Temporada</label>
        <select name="temporada" class="form-control">
          <option value="todas" <?= $filterTemporada === 'todas' ? 'selected' : '' ?>>Todas</option>
          <?php foreach ($temporadas_disp as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterTemporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Categoría</label>
        <select name="liga" class="form-control">
          <option value="">Todas</option>
          <?php foreach (['benjamin' => 'Benjamín', 'alevin' => 'Alevín', 'infantil' => 'Infantil', 'junior' => 'Junior', 'absoluto' => 'Absoluto', 'master' => 'Master'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $filterLiga === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Prueba</label>
        <select name="prueba" class="form-control">
          <?php render_prueba_options($filterPrueba, true); ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Piscina</label>
        <select name="piscina" class="form-control">
          <option value="25m" <?= $filterPiscina === '25m' ? 'selected' : '' ?>>25m</option>
          <option value="50m" <?= $filterPiscina === '50m' ? 'selected' : '' ?>>50m</option>
        </select>
      </div>
      <div class="form-group" style="align-self:flex-end;display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <?php if ($hasFilters): ?>
          <a href="/socio/ranking" class="btn btn-gray js-loading-link"><i class="bi bi-arrow-counterclockwise"></i> Resetear</a>
        <?php endif; ?>
      </div>
    </form>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;border-top:1px solid #eee;padding-top:14px;">
      <?php
      $mejores_toggle = $base_filters;
      unset($mejores_toggle['top10']);
      if ($filterMejores) unset($mejores_toggle['mejores']); else $mejores_toggle['mejores'] = '1';
      $top10_toggle = $base_filters;
      unset($top10_toggle['mejores']);
      $top10_toggle['mejores'] = '1';
      if ($filterTop10) unset($top10_toggle['top10']); else $top10_toggle['top10'] = '1';
      ?>
      <a href="?<?= http_build_query($mejores_toggle) ?>"
         class="btn btn-sm <?= $filterMejores && !$filterTop10 ? 'btn-primary' : 'btn-gray' ?> js-loading-link" style="white-space:nowrap;">
        <i class="bi bi-trophy-fill"></i> Mejores marcas
      </a>
      <a href="?<?= http_build_query($top10_toggle) ?>"
         class="btn btn-sm <?= $filterTop10 ? 'btn-primary' : 'btn-gray' ?> js-loading-link" style="white-space:nowrap;">
        <i class="bi bi-star-fill"></i> Top 10
      </a>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Sexo</label>
        <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
          <?php
          $sexo_opts = ['M' => 'Masc.', 'F' => 'Fem.', '' => 'Todos'];
          foreach ($sexo_opts as $sv => $sl):
            $sp = $base_filters;
            $sp['sexo'] = $sv;
            $active = $filterSexo === $sv;
          ?>
            <a href="?<?= http_build_query($sp) ?>"
              class="js-loading-link"
              style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;transition:all .15s;">
              <?= $sl ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php $nadador_opts = ['1' => 'Activos', '0' => 'No activos', '' => 'Todos']; ?>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Nadador</label>
        <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
          <?php foreach ($nadador_opts as $nv => $nl):
            $nv = (string)$nv;
            $np = $base_filters;
            $np['nadador'] = $nv;
            $active = $filterNadador === $nv;
          ?>
            <a href="?<?= http_build_query($np) ?>"
              class="js-loading-link"
              style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;transition:all .15s;">
              <?= $nl ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
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

  document.querySelectorAll('.js-loading-link').forEach(a => {
    a.addEventListener('click', () => showPageLoading('Espera un momento, estamos aplicando los filtros.'));
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
            <th><a href="<?= e($sortUrl('nombre')) ?>">Nombre<?= $sortIcon('nombre') ?></a></th>
            <?php if (!$filterPrueba): ?><th><a href="<?= e($sortUrl('prueba')) ?>">Prueba<?= $sortIcon('prueba') ?></a></th><?php endif; ?>
            <th><a href="<?= e($sortUrl('liga')) ?>">Categoría<?= $sortIcon('liga') ?></a></th>
            <th><a href="<?= e($sortUrl('sexo')) ?>">Sexo<?= $sortIcon('sexo') ?></a></th>
            <th><a href="<?= e($sortUrl('tiempo')) ?>">Tiempo<?= $sortIcon('tiempo') ?></a></th>
            <th><a href="<?= e($sortUrl('lugar')) ?>">Lugar<?= $sortIcon('lugar') ?></a></th>
            <th><a href="<?= e($sortUrl('fecha')) ?>">Fecha<?= $sortIcon('fecha') ?></a></th>
            <?php if ($filterMejores): ?><th><a href="<?= e($sortUrl('temporada')) ?>">Temporada<?= $sortIcon('temporada') ?></a></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          $colspan = 7 + (!$filterPrueba ? 1 : 0) + ($filterMejores ? 1 : 0);
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
                <strong><?= e($row['nombre']) ?></strong>
                <?= $row['uid'] == $user['id'] ? '<span class="badge badge-blue" style="margin-left:6px;">Tú</span>' : '' ?>
              </td>
              <?php if (!$filterPrueba): ?>
                <td class="text-sm"><?= e(format_prueba($row['prueba'])) ?></td>
              <?php endif; ?>
              <td><span class="badge badge-gray"><?= e(format_liga($row['liga'] ?? '')) ?></span></td>
              <td><?= $row['sexo'] === 'M' ? 'Masc.' : 'Fem.' ?></td>
              <td>
                <span class="mark-time"><?= e($row['tiempo']) ?></span>
                <?php
                if (isset($club_records[(int)$row['id']])):
                  $is_actual = $club_records[(int)$row['id']] === 'actual';
                  $rec_bg    = $is_actual ? '#dcfce7' : '#fef9c3';
                  $rec_color = $is_actual ? '#15803d' : '#a16207';
                  $rec_border = $is_actual ? '#86efac' : '#fde047';
                  $rec_label = $is_actual ? 'Récord del club' : 'Récord histórico';
                ?>
                  <div style="margin-top:4px;">
                    <span style="display:inline-flex;align-items:center;gap:4px;background:<?= $rec_bg ?>;color:<?= $rec_color ?>;font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;border:1px solid <?= $rec_border ?>;">
                      <i class="bi bi-trophy-fill"></i> <?= $rec_label ?>
                    </span>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-sm text-muted"><?= e($row['lugar'] ?? '') ?></td>
              <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($row['fecha_marca'])) ?></td>
              <?php if ($filterMejores): ?>
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
