<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';
require_once dirname(__DIR__, 2) . '/includes/asistencia.php';

require_admin_area(['director_tecnico', 'entrenador']);

$PRUEBAS = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, nombre, liga, sexo, nadador_activo FROM users WHERE id=? AND estado='activo' AND rol='socio'");
$stmt->execute([$id]);
$nadador = $stmt->fetch();
if (!$nadador) {
    flash('Deportista no encontrado.', 'danger');
    header('Location: /admin/deportistas');
    exit;
}

// --- Marcas oficiales ---
$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporadas_disp = [];
for ($y = $current_year; $y >= 2012; $y--)
    $temporadas_disp[] = $y . '-' . substr((string)($y + 1), 2);
$temporada = $_GET['temporada'] ?? $temporadas_disp[0];
if ($temporada !== 'todas' && !in_array($temporada, $temporadas_disp, true)) $temporada = $temporadas_disp[0];
$filtroPrueba = $_GET['prueba'] ?? '';
if (!in_array($filtroPrueba, $PRUEBAS, true)) $filtroPrueba = '';
$filtroPiscina = $_GET['piscina'] ?? '';
if (!in_array($filtroPiscina, ['25m', '50m'], true)) $filtroPiscina = '';

$where  = ['user_id = ?'];
$params = [$id];
if ($temporada !== 'todas') { $where[] = 'temporada = ?'; $params[] = $temporada; }
if ($filtroPrueba !== '')   { $where[] = 'prueba = ?';    $params[] = $filtroPrueba; }
if ($filtroPiscina !== '')  { $where[] = 'piscina = ?';   $params[] = $filtroPiscina; }
$orden_pruebas = implode(',', array_map(fn($p) => $pdo->quote($p), $PRUEBAS));
$stmt = $pdo->prepare('SELECT * FROM marcas WHERE ' . implode(' AND ', $where)
    . " ORDER BY FIELD(prueba, $orden_pruebas), piscina, tiempo_seg");
$stmt->execute($params);
$marcas = $stmt->fetchAll();

// --- Tiempos de competición ---
$tiempos = listar_tiempos_socio($pdo, $id);

// --- Asistencia (mes) ---
$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
$fecha_inicio = $mes . '-01';
$fecha_fin = date('Y-m-t', strtotime($fecha_inicio));

$dias_mes = [];
$num_dias = (int)date('t', strtotime($fecha_inicio));
for ($d = 1; $d <= $num_dias; $d++) {
    $dias_mes[] = sprintf('%s-%02d', $mes, $d);
}
$asistencia_data = [];
$stmt = $pdo->prepare("SELECT fecha, presente, observaciones FROM asistencia WHERE user_id = ? AND fecha BETWEEN ? AND ?");
$stmt->execute([$id, $fecha_inicio, $fecha_fin]);
foreach ($stmt->fetchAll() as $r) {
    $asistencia_data[$r['fecha']] = $r;
}
$mes_anterior = date('Y-m', strtotime($fecha_inicio . ' -1 month'));
$mes_siguiente = date('Y-m', strtotime($fecha_inicio . ' +1 month'));
$meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$nombre_mes = ucfirst($meses_es[(int)date('m', strtotime($fecha_inicio)) - 1]) . ' ' . date('Y', strtotime($fecha_inicio));

[$total_presente, $total_registrado, $pct] = resumen_asistencia(array_map(fn($r) => (int)$r['presente'], $asistencia_data));

render_header('Perfil de ' . $nadador['nombre'], 'admin-deportistas');
render_admin_layout('deportistas', function() use (
    $nadador, $marcas, $temporada, $temporadas_disp, $filtroPrueba, $filtroPiscina, $tiempos, $mes,
    $dias_mes, $asistencia_data, $mes_anterior, $mes_siguiente, $nombre_mes,
    $total_presente, $total_registrado, $pct
) {
?>

<a href="/admin/deportistas" class="btn btn-gray btn-sm mb-4"><i class="bi bi-arrow-left"></i> Volver al listado</a>

<h1><?= e($nadador['nombre']) ?></h1>
<p class="text-muted"><?= e(format_liga($nadador['liga'] ?? '')) ?> · <?= e($nadador['sexo'] ?? '') ?></p>
<?php render_flash(); ?>

<!-- Marcas oficiales -->
<div class="card mb-6">
  <div class="d-flex align-center justify-between flex-wrap gap-2 mb-4">
    <h2 style="font-size:15px;font-weight:700;margin:0;">Marcas oficiales</h2>
    <form method="GET" class="d-flex gap-2 flex-wrap" style="margin:0;">
      <input type="hidden" name="id" value="<?= (int)$nadador['id'] ?>">
      <input type="hidden" name="mes" value="<?= e($mes) ?>">
      <select name="temporada" class="form-control" style="width:auto;" onchange="this.form.submit()">
        <?php foreach ($temporadas_disp as $t): ?>
          <option value="<?= e($t) ?>" <?= $temporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
        <option value="todas" <?= $temporada === 'todas' ? 'selected' : '' ?>>Todas las temporadas</option>
      </select>
      <select name="prueba" class="form-control" style="width:auto;" onchange="this.form.submit()">
        <?php render_prueba_options($filtroPrueba, true); ?>
      </select>
      <select name="piscina" class="form-control" style="width:auto;" onchange="this.form.submit()">
        <option value="" <?= $filtroPiscina === '' ? 'selected' : '' ?>>Todas las piscinas</option>
        <option value="25m" <?= $filtroPiscina === '25m' ? 'selected' : '' ?>>25 metros</option>
        <option value="50m" <?= $filtroPiscina === '50m' ? 'selected' : '' ?>>50 metros</option>
      </select>
    </form>
  </div>
  <?php if (!$marcas): ?>
    <p class="text-muted">Sin marcas registradas con estos filtros.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Prueba</th><th>Piscina</th><th>Tiempo</th><?php if ($temporada === 'todas'): ?><th>Temporada</th><?php endif; ?><th>Fecha</th><th>Lugar</th></tr></thead>
        <tbody>
          <?php foreach ($marcas as $m): ?>
            <tr>
              <td><?= e(format_prueba($m['prueba'])) ?><?= $m['es_parcial'] ? ' <span class="text-muted text-sm">(parcial)</span>' : '' ?></td>
              <td><?= e($m['piscina']) ?></td>
              <td style="font-weight:600;"><?= e($m['tiempo']) ?></td>
              <?php if ($temporada === 'todas'): ?><td><?= e($m['temporada']) ?></td><?php endif; ?>
              <td><?= date('d/m/Y', strtotime($m['fecha_marca'])) ?></td>
              <td><?= e($m['lugar'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Tiempos de competición -->
<div class="card mb-6">
  <h2 style="font-size:15px;font-weight:700;margin-bottom:14px;">Tiempos de competición</h2>
  <?php if (!$tiempos): ?>
    <p class="text-muted">Sin tiempos de competición registrados.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Fecha</th><th>Competición</th><th>Prueba</th><th>Tiempo</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($tiempos as $t): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($t['competicion_fecha'])) ?></td>
              <td><?= e($t['competicion_nombre']) ?></td>
              <td><?= e(format_prueba($t['prueba'])) ?></td>
              <td style="font-weight:600;"><?= e($t['tiempo']) ?></td>
              <td><a href="/admin/competicion_tiempo?id=<?= (int)$t['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Asistencia -->
<?php $filtros = array_filter(['temporada' => $temporada, 'prueba' => $filtroPrueba, 'piscina' => $filtroPiscina]); ?>
<div class="card" id="asistencia">
  <div class="d-flex gap-3 align-center flex-wrap mb-4">
    <h2 style="font-size:15px;font-weight:700;margin:0;">Asistencia</h2>
    <div class="d-flex gap-2 align-center" style="margin-left:auto;">
      <a href="?<?= e(http_build_query(['id' => $nadador['id'], 'mes' => $mes_anterior] + $filtros)) ?>#asistencia" class="btn btn-gray btn-sm"><i class="bi bi-chevron-left"></i></a>
      <span style="font-weight:600;min-width:140px;text-align:center;"><?= e($nombre_mes) ?></span>
      <a href="?<?= e(http_build_query(['id' => $nadador['id'], 'mes' => $mes_siguiente] + $filtros)) ?>#asistencia" class="btn btn-gray btn-sm"><i class="bi bi-chevron-right"></i></a>
    </div>
    <div style="font-weight:700;">
      <?= $total_presente ?>/<?= $total_registrado ?>
      <?php if ($pct !== null): ?>
        <span style="color:<?= $pct >= 80 ? 'var(--green)' : ($pct < 50 ? 'var(--red)' : 'var(--text)') ?>;">(<?= $pct ?>%)</span>
      <?php endif; ?>
    </div>
  </div>
  <?php render_calendario_asistencia($dias_mes, $asistencia_data); ?>
</div>

<?php
});
render_footer();
