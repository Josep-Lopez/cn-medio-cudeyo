<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

$LIGAS = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','absoluto'=>'Absoluto','master'=>'Master'];

// ── Filtros ──────────────────────────────────────────────────────────────────
$filtroLiga = $_GET['liga'] ?? '';
if ($filtroLiga && !array_key_exists($filtroLiga, $LIGAS)) $filtroLiga = '';

$mes = $_GET['mes'] ?? date('Y-m');
// Validar formato YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');

$fecha_inicio = $mes . '-01';
$fecha_fin = date('Y-m-t', strtotime($fecha_inicio));

// Nadadores
$nadadores = [];
$dias_mes = [];
$asistencia_data = [];

if ($filtroLiga) {
    $stmt = $pdo->prepare("SELECT id, nombre FROM users WHERE estado='activo' AND rol='socio' AND nadador_activo=1 AND liga=? ORDER BY nombre");
    $stmt->execute([$filtroLiga]);
    $nadadores = $stmt->fetchAll();

    // Días del mes
    $num_dias = (int)date('t', strtotime($fecha_inicio));
    for ($d = 1; $d <= $num_dias; $d++) {
        $dias_mes[] = sprintf('%s-%02d', $mes, $d);
    }

    // Cargar asistencia del mes
    $user_ids = array_column($nadadores, 'id');
    if ($user_ids) {
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $stmt = $pdo->prepare("SELECT user_id, fecha, presente FROM asistencia WHERE user_id IN ($placeholders) AND fecha BETWEEN ? AND ?");
        $stmt->execute(array_merge($user_ids, [$fecha_inicio, $fecha_fin]));
        foreach ($stmt->fetchAll() as $r) {
            $asistencia_data[$r['user_id']][$r['fecha']] = (int)$r['presente'];
        }
    }
}

// Navegación meses
$mes_anterior = date('Y-m', strtotime($fecha_inicio . ' -1 month'));
$mes_siguiente = date('Y-m', strtotime($fecha_inicio . ' +1 month'));
$meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$nombre_mes = ucfirst($meses_es[(int)date('m', strtotime($fecha_inicio)) - 1]) . ' ' . date('Y', strtotime($fecha_inicio));

render_header('Historial de asistencia', 'admin-asistencia_historial');
render_admin_layout('asistencia_historial', function() use ($LIGAS, $filtroLiga, $mes, $mes_anterior, $mes_siguiente, $nombre_mes, $nadadores, $dias_mes, $asistencia_data) {
?>

<h1>Historial de asistencia</h1>
<?php render_flash(); ?>

<div class="card mb-6">
  <div class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;">
      <label class="form-label">Categoría *</label>
      <select class="form-control" style="width:auto;min-width:160px;"
              onchange="window.location='?liga='+this.value+'&mes=<?= e($mes) ?>'">
        <option value="">— Seleccionar —</option>
        <?php foreach ($LIGAS as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroLiga === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Mes</label>
      <div class="d-flex gap-2 align-center">
        <a href="?liga=<?= e($filtroLiga) ?>&mes=<?= $mes_anterior ?>" class="btn btn-gray btn-sm"><i class="bi bi-chevron-left"></i></a>
        <span style="font-weight:600;min-width:140px;text-align:center;"><?= e($nombre_mes) ?></span>
        <a href="?liga=<?= e($filtroLiga) ?>&mes=<?= $mes_siguiente ?>" class="btn btn-gray btn-sm"><i class="bi bi-chevron-right"></i></a>
      </div>
    </div>
    <div style="margin-left:auto;">
      <a href="/admin/asistencia?liga=<?= e($filtroLiga) ?>" class="btn btn-primary btn-sm"><i class="bi bi-clipboard-check"></i> Pasar lista</a>
    </div>
  </div>
</div>

<?php if (!$filtroLiga): ?>
  <div class="card text-center" style="padding:32px;">
    <div style="font-size:32px;color:var(--blue);margin-bottom:12px;"><i class="bi bi-arrow-up-circle"></i></div>
    <p class="text-muted">Selecciona una categoría para ver el historial.</p>
  </div>
<?php elseif (!$nadadores): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay nadadores activos en esta categoría.</p>
  </div>
<?php else: ?>

<div class="card">
  <div class="table-wrapper" style="overflow-x:auto;">
    <table style="font-size:12px;">
      <thead>
        <tr>
          <th style="position:sticky;left:0;background:white;z-index:1;min-width:140px;">Nadador</th>
          <?php foreach ($dias_mes as $dia): ?>
            <th style="text-align:center;min-width:30px;padding:6px 4px;"><?= (int)date('d', strtotime($dia)) ?></th>
          <?php endforeach; ?>
          <th style="text-align:center;min-width:50px;">Total</th>
          <th style="text-align:center;min-width:50px;">%</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($nadadores as $n):
          $total_presente = 0;
          $total_registrado = 0;
        ?>
          <tr>
            <td style="position:sticky;left:0;background:white;z-index:1;font-weight:600;white-space:nowrap;"><?= e($n['nombre']) ?></td>
            <?php foreach ($dias_mes as $dia):
              $registro = $asistencia_data[$n['id']][$dia] ?? null;
              if ($registro !== null) {
                  $total_registrado++;
                  if ($registro) $total_presente++;
              }
            ?>
              <td style="text-align:center;padding:6px 4px;">
                <?php if ($registro === null): ?>
                  <span style="color:#ddd;">·</span>
                <?php elseif ($registro): ?>
                  <span style="color:var(--green);">✓</span>
                <?php else: ?>
                  <span style="color:var(--red);">✗</span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td style="text-align:center;font-weight:700;"><?= $total_presente ?>/<?= $total_registrado ?></td>
            <td style="text-align:center;font-weight:600;color:<?= $total_registrado > 0 && ($total_presente / $total_registrado) >= 0.8 ? 'var(--green)' : (($total_registrado > 0 && ($total_presente / $total_registrado) < 0.5) ? 'var(--red)' : 'var(--text)') ?>;">
              <?= $total_registrado > 0 ? round(($total_presente / $total_registrado) * 100) . '%' : '—' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php
});
render_footer();
