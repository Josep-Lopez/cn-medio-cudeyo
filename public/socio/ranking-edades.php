<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
require_nadador_activo();
$user = current_user();

$PRUEBAS = ['50L', '100L', '200L', '400L', '800L', '1500L', '50E', '100E', '200E', '50B', '100B', '200B', '50M', '100M', '200M', '100X', '200X', '400X'];

// --- Filtros ---
$filterPrueba    = $_GET['prueba']   ?? '';
$filterPiscina   = $_GET['piscina']  ?? '25m';
$filterSexo      = $_GET['sexo'] ?? '';
$filterNadador   = $_GET['nadador']  ?? '1';

$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporadas_disp = [];
for ($y = $current_year; $y >= 2012; $y--) {
    $temporadas_disp[] = $y . '-' . substr((string)($y + 1), 2);
}
$filterTemporada = $_GET['temporada'] ?? 'todas';

// Validación
if (!in_array($filterPrueba, $PRUEBAS, true)) $filterPrueba = '';
if (!in_array($filterPiscina, ['25m', '50m'], true)) $filterPiscina = '25m';
if (!in_array($filterSexo, ['M', 'F', ''], true)) $filterSexo = '';
if (!in_array($filterNadador, ['1', '0', ''], true)) $filterNadador = '1';
if ($filterTemporada !== 'todas' && !in_array($filterTemporada, $temporadas_disp, true)) {
    $filterTemporada = 'todas';
}

$vista_a_grupos = [];
$vista_b_matriz = [];

if ($filterPrueba !== '') {
    // --- Vista A: top-10 por edad ---
    $where_extra = '';
    $params = [$filterPiscina, $filterPrueba];

    if ($filterSexo !== '')           { $where_extra .= ' AND u.sexo = ?';            $params[] = $filterSexo; }
    if ($filterTemporada !== 'todas') { $where_extra .= ' AND m.temporada = ?';       $params[] = $filterTemporada; }
    if ($filterNadador !== '')        { $where_extra .= ' AND u.nadador_activo = ?';  $params[] = (int)$filterNadador; }

    $sql_a = "
        WITH ranked AS (
            SELECT
                m.id, m.tiempo, m.tiempo_seg, m.fecha_marca, m.lugar, m.piscina,
                u.id AS uid, u.nombre, u.sexo, u.fecha_nacimiento,
                YEAR(u.fecha_nacimiento) AS anio_nac,
                (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) AS edad,
                ROW_NUMBER() OVER (
                    PARTITION BY (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento))
                    ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC, u.nombre ASC
                ) AS rn
            FROM marcas m
            JOIN users u ON u.id = m.user_id
            WHERE u.estado = 'activo'
              AND u.fecha_nacimiento IS NOT NULL
              AND m.piscina = ?
              AND m.prueba  = ?
              $where_extra
              AND (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) BETWEEN 10 AND 18
        )
        SELECT * FROM ranked WHERE rn <= 10
        ORDER BY edad ASC, rn ASC
    ";
    $stmt = $pdo->prepare($sql_a);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $vista_a_grupos[(int)$row['edad']][] = $row;
    }
} else {
    // --- Vista B: matriz 9x18 ---
    $where_extra_b = '';
    $params_b = [$filterPiscina];

    if ($filterSexo !== '')           { $where_extra_b .= ' AND u.sexo = ?';            $params_b[] = $filterSexo; }
    if ($filterTemporada !== 'todas') { $where_extra_b .= ' AND m.temporada = ?';       $params_b[] = $filterTemporada; }
    if ($filterNadador !== '')        { $where_extra_b .= ' AND u.nadador_activo = ?';  $params_b[] = (int)$filterNadador; }

    $sql_b = "
        WITH ranked AS (
            SELECT
                m.prueba, m.tiempo, m.tiempo_seg, m.fecha_marca,
                u.id AS uid, u.nombre, u.sexo, YEAR(u.fecha_nacimiento) AS anio_nac,
                (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) AS edad,
                ROW_NUMBER() OVER (
                    PARTITION BY
                        (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)),
                        m.prueba,
                        u.sexo
                    ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC, u.nombre ASC
                ) AS rn
            FROM marcas m
            JOIN users u ON u.id = m.user_id
            WHERE u.estado = 'activo'
              AND u.fecha_nacimiento IS NOT NULL
              AND m.piscina = ?
              $where_extra_b
              AND (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) BETWEEN 10 AND 18
        )
        SELECT * FROM ranked WHERE rn = 1
    ";
    $stmt = $pdo->prepare($sql_b);
    $stmt->execute($params_b);
    foreach ($stmt->fetchAll() as $row) {
        $vista_b_matriz[(int)$row['edad']][$row['prueba']][$row['sexo']] = $row;
    }
}

render_header('Ranking por edad', 'socio-ranking');
?>

<div class="container page-content">
  <h1 style="margin-bottom:6px;">Ranking por edad</h1>

  <div class="ranking-tabs">
    <a href="/socio/ranking" class="js-loading-link">Ranking</a>
    <a href="/socio/ranking-edades" class="tab--active">Marcas de Edad</a>
  </div>

  <!-- Filtros -->
  <div class="filters-bar" style="flex-direction:column;gap:16px;">
    <form method="GET" class="filters-form js-loading-form">
      <input type="hidden" name="sexo" value="<?= e($filterSexo) ?>">
      <input type="hidden" name="nadador" value="<?= e($filterNadador) ?>">

      <div class="form-group">
        <label class="form-label">Prueba</label>
        <select name="prueba" class="form-control">
          <option value="">Todas las pruebas</option>
          <?php foreach ($PRUEBAS as $p): ?>
            <option value="<?= e($p) ?>" <?= $filterPrueba === $p ? 'selected' : '' ?>><?= e(format_prueba($p)) ?></option>
          <?php endforeach; ?>
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
        <label class="form-label">Temporada</label>
        <select name="temporada" class="form-control">
          <option value="todas" <?= $filterTemporada === 'todas' ? 'selected' : '' ?>>Todas</option>
          <?php foreach ($temporadas_disp as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterTemporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="align-self:flex-end;display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary">Filtrar</button>
      </div>
    </form>

    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;border-top:1px solid #eee;padding-top:14px;">
      <?php
      $base_filters = [
        'prueba'    => $filterPrueba,
        'piscina'   => $filterPiscina,
        'sexo'      => $filterSexo,
        'nadador'   => $filterNadador,
        'temporada' => $filterTemporada,
      ];
      $sexo_opts = ['M' => 'Masc.', 'F' => 'Fem.', '' => 'Todos'];
      ?>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Sexo</label>
        <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
          <?php foreach ($sexo_opts as $sv => $sl):
            $sp = $base_filters;
            $sp['sexo'] = $sv;
            $active = $filterSexo === $sv;
          ?>
            <a href="?<?= http_build_query($sp) ?>"
              class="js-loading-link"
              style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;"><?= $sl ?></a>
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
              style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;"><?= $nl ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Resultados -->
  <div id="ranking-edades-resultados" style="margin-top:24px;">
    <?php if ($filterPrueba !== ''): ?>
      <h2 style="margin-bottom:18px;">
        <?= e(format_prueba($filterPrueba)) ?> · Piscina <?= e($filterPiscina) ?>
        <?php if ($filterSexo === 'M') echo ' · Masculino'; ?>
        <?php if ($filterSexo === 'F') echo ' · Femenino'; ?>
      </h2>

      <?php for ($edad = 10; $edad <= 18; $edad++): ?>
        <section class="edad-block" id="edad-<?= $edad ?>">
          <h2>Edad <?= $edad ?> · <?= $current_year - $edad ?></h2>
          <?php $filas = $vista_a_grupos[$edad] ?? []; ?>
          <?php if (!$filas): ?>
            <div class="empty">Sin marcas registradas a esta edad.</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th style="width:50px;">Pos.</th>
                    <th>Tiempo</th>
                    <th>Nadador</th>
                    <th>Año nac.</th>
                    <th>Fecha marca</th>
                    <th>Lugar</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($filas as $i => $row): ?>
                    <tr <?= $row['uid'] == $user['id'] ? 'style="background:#eef2ff;"' : '' ?>>
                      <td>
                        <span class="rank-pos <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>">
                          <?= $i + 1 ?>
                          <?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : '')) ?>
                        </span>
                      </td>
                      <td><span class="mark-time"><?= e($row['tiempo']) ?></span></td>
                      <td>
                        <strong><?= e($row['nombre']) ?></strong>
                        <?= $row['uid'] == $user['id'] ? '<span class="badge badge-blue" style="margin-left:6px;">Tú</span>' : '' ?>
                      </td>
                      <td><?= (int)$row['anio_nac'] ?></td>
                      <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($row['fecha_marca'])) ?></td>
                      <td class="text-sm text-muted"><?= e($row['lugar'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endfor; ?>

    <?php else: ?>
      <h2 style="margin-bottom:12px;">
        Récords por edad y prueba · Piscina <?= e($filterPiscina) ?>
        <?php if ($filterSexo === 'M') echo ' · Masculino'; ?>
        <?php if ($filterSexo === 'F') echo ' · Femenino'; ?>
      </h2>
      <p class="text-muted text-sm" style="margin-bottom:12px;">
        Cada celda es la mejor marca a esa edad y prueba. Click para ver el top-10 completo.
      </p>

      <div id="matriz-scroll-ctrls" style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:8px;">
        <button type="button" class="btn btn-sm btn-gray" onclick="scrollMatriz(-300)" title="Desplazar a la izquierda"><i class="bi bi-chevron-left"></i></button>
        <button type="button" class="btn btn-sm btn-gray" onclick="scrollMatriz(300)" title="Desplazar a la derecha"><i class="bi bi-chevron-right"></i></button>
      </div>

      <div class="table-wrapper" id="matriz-wrapper">
        <table class="matriz-edades">
          <thead>
            <tr>
              <th>Prueba</th>
              <?php for ($edad = 10; $edad <= 18; $edad++):
                $anio_nac_col = $current_year - $edad;
              ?>
                <th>
                  <div style="font-size:11px;font-weight:500;opacity:.85;"><?= $anio_nac_col ?></div>
                  <div><?= $edad ?> años</div>
                </th>
              <?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($PRUEBAS as $p): ?>
              <tr>
                <th class="row-edad"><?= e($p) ?></th>
                <?php for ($edad = 10; $edad <= 18; $edad++):
                  $cell = $vista_b_matriz[$edad][$p] ?? [];
                  if ($filterSexo === 'M')      $sexos_show = ['M'];
                  elseif ($filterSexo === 'F')  $sexos_show = ['F'];
                  else                          $sexos_show = ['M', 'F'];

                  $rows_in_cell = [];
                  foreach ($sexos_show as $sx) {
                      if (isset($cell[$sx])) $rows_in_cell[$sx] = $cell[$sx];
                  }

                  if (!$rows_in_cell):
                ?>
                  <td class="cell-empty">—</td>
                <?php else: ?>
                  <td class="cell-record">
                    <?php foreach ($rows_in_cell as $sx => $r):
                      $link = '?' . http_build_query(array_filter([
                        'prueba'    => $p,
                        'piscina'   => $filterPiscina,
                        'sexo'      => $sx,
                        'nadador'   => $filterNadador,
                        'temporada' => $filterTemporada,
                      ], static fn($v) => $v !== '' && $v !== null)) . '#edad-' . $edad;
                    ?>
                      <a href="<?= e($link) ?>" class="cell-line cell-line-<?= strtolower($sx) ?> js-loading-link" title="<?= e($r['nombre']) ?> · Top-10 edad <?= $edad ?> · <?= e(format_prueba($p)) ?> · <?= $sx === 'M' ? 'Masculino' : 'Femenino' ?>">
                        <span class="cell-time"><?= e($r['tiempo']) ?></span>
                        <span class="cell-name"><?= e(nombre_corto($r['nombre'])) ?></span>
                      </a>
                    <?php endforeach; ?>
                  </td>
                <?php endif; endfor; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <script>
  document.querySelectorAll('.js-loading-form select').forEach(select => {
    select.addEventListener('change', function () { this.form.requestSubmit(); });
  });
  function scrollMatriz(delta) {
    const w = document.getElementById('matriz-wrapper');
    if (w) w.scrollBy({ left: delta, behavior: 'smooth' });
  }
  function toggleMatrizScrollCtrls() {
    const w = document.getElementById('matriz-wrapper');
    const c = document.getElementById('matriz-scroll-ctrls');
    if (!w || !c) return;
    c.style.display = w.scrollWidth > w.clientWidth + 1 ? 'flex' : 'none';
  }
  window.addEventListener('load', toggleMatrizScrollCtrls);
  window.addEventListener('resize', toggleMatrizScrollCtrls);
  </script>
</div>

<?php render_footer(); ?>
