<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();
$admin_user = current_user();

$N_OPCIONES = [1, 2, 3, 4, 8, 16, 18];

// --- Filtros ---
$filterN       = (int)($_GET['n'] ?? 4);
if (!in_array($filterN, $N_OPCIONES, true)) $filterN = 4;
$filterSexo = $_GET['sexo'] ?? '';
if (!in_array($filterSexo, ['M', 'F', ''], true)) $filterSexo = '';

// --- Datos: todas las marcas (piscina 25m) de todos los socios, activos e inactivos ---
$where  = "WHERE u.estado = 'activo' AND m.piscina = '25m' AND m.es_parcial = 0";
$params = [];
if ($filterSexo !== '') { $where .= ' AND u.sexo = ?'; $params[] = $filterSexo; }

$sql = "
    SELECT m.user_id, m.prueba, m.piscina, m.tiempo_seg,
           u.nombre, u.sexo, u.liga, u.nadador_activo
    FROM marcas m
    JOIN users u ON u.id = m.user_id
    $where
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// Mejor AQUA por (nadador, prueba): se elige la marca con MÁS puntos (mezcla 25/50).
$nadadores = [];
foreach ($stmt->fetchAll() as $row) {
    $pts = calcular_aqua((float)$row['tiempo_seg'], $row['prueba'], $row['piscina'], $row['sexo']);
    if ($pts === null) continue;
    $uid = (int)$row['user_id'];
    if (!isset($nadadores[$uid])) {
        $nadadores[$uid] = [
            'uid'            => $uid,
            'nombre'         => $row['nombre'],
            'sexo'           => $row['sexo'],
            'liga'           => $row['liga'],
            'nadador_activo' => (int)$row['nadador_activo'],
            'pruebas'        => [],
        ];
    }
    $prev = $nadadores[$uid]['pruebas'][$row['prueba']] ?? -1;
    if ($pts > $prev) $nadadores[$uid]['pruebas'][$row['prueba']] = $pts;
}

// Cálculo de sumas por nadador
$filas = [];
foreach ($nadadores as $nad) {
    arsort($nad['pruebas']);
    $valores = array_values($nad['pruebas']);
    $sumaTop = static fn(int $k): int => array_sum(array_slice($valores, 0, $k));
    $nad['suma_n'] = $sumaTop($filterN);
    $nad['suma4']  = $sumaTop(4);
    $nad['suma8']  = $sumaTop(8);
    $nad['suma16'] = $sumaTop(16);
    $nad['top']    = array_slice($nad['pruebas'], 0, $filterN, true);
    $filas[] = $nad;
}

usort($filas, static function ($a, $b) {
    return [$b['suma_n'], $b['suma8'], $a['nombre']] <=> [$a['suma_n'], $a['suma8'], $b['nombre']];
});

render_header('Puntos AQUA', 'admin-ranking');
render_admin_layout('ranking', function () use ($filas, $filterN, $filterSexo, $N_OPCIONES) {
?>

<h1 style="margin-bottom:6px;">Puntos AQUA</h1>

<div class="ranking-tabs">
  <a href="/admin/ranking" class="js-loading-link">Ranking</a>
  <a href="/admin/ranking-edades" class="js-loading-link">Marcas de Edad</a>
  <a href="/admin/records" class="js-loading-link">Récords del Club</a>
  <a href="/admin/puntos-aqua" class="tab--active">Puntos AQUA</a>
</div>

<?php $base_filters = ['n' => $filterN, 'sexo' => $filterSexo]; ?>
<div class="filters-bar" style="flex-direction:column;gap:16px;">
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
    <div class="form-group" style="margin:0;">
      <label class="form-label">Nº de pruebas (suma)</label>
      <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
        <?php foreach ($N_OPCIONES as $nv):
          $np = $base_filters; $np['n'] = $nv;
          $active = $filterN === $nv;
        ?>
          <a href="?<?= http_build_query($np) ?>"
            class="js-loading-link"
            style="padding:5px 13px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;"><?= $nv === 18 ? 'Todas' : $nv ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php $sexo_opts = ['M' => 'Masc.', 'F' => 'Fem.', '' => 'Todos']; ?>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Sexo</label>
      <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
        <?php foreach ($sexo_opts as $sv => $sl):
          $sp = $base_filters; $sp['sexo'] = $sv;
          $active = $filterSexo === $sv;
        ?>
          <a href="?<?= http_build_query($sp) ?>"
            class="js-loading-link"
            style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;"><?= $sl ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<p class="text-muted text-sm" style="margin:16px 0;">
  Ranking de equipo por puntos <strong>World Aquatics</strong>. Cada nadador suma sus
  <strong><?= $filterN === 18 ? 'mejores' : $filterN ?></strong> mejores pruebas (mejor marca histórica por prueba).
  SUMA 4 y SUMA 8 mostradas como referencia.
</p>

<div class="table-card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th style="width:46px;">#</th>
          <th>Nadador</th>
          <th>Cat.</th>
          <th style="color:var(--blue);">SUMA <?= $filterN === 18 ? 'Todas' : $filterN ?></th>
          <th>SUMA 4</th>
          <th>SUMA 8</th>
          <th>SUMA 16</th>
          <th>Mejores pruebas (puntos)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$filas): ?>
          <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">No hay marcas con puntos AQUA para esta selección.</td></tr>
        <?php else: foreach ($filas as $i => $f): ?>
          <tr>
            <td>
              <span class="rank-pos <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>">
                <?= $i + 1 ?><?= $i === 0 ? ' 🥇' : ($i === 1 ? ' 🥈' : ($i === 2 ? ' 🥉' : '')) ?>
              </span>
            </td>
            <td><strong><?= e($f['nombre']) ?></strong></td>
            <td><span class="badge badge-gray"><?= e(format_liga($f['liga'] ?? '')) ?></span></td>
            <td><strong style="color:var(--blue);font-size:15px;"><?= $f['suma_n'] ?></strong></td>
            <td class="text-sm"><?= $f['suma4'] ?></td>
            <td class="text-sm"><?= $f['suma8'] ?></td>
            <td class="text-sm"><?= $f['suma16'] ?></td>
            <td style="white-space:normal;">
              <?php foreach ($f['top'] as $p => $pts): ?>
                <span style="display:inline-block;background:#f1f5ff;border:1px solid #dbe4ff;border-radius:6px;padding:1px 7px;margin:2px 3px 2px 0;font-size:12px;white-space:nowrap;">
                  <?= e($p) ?> <strong><?= $pts ?></strong>
                </span>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
});
render_footer();
