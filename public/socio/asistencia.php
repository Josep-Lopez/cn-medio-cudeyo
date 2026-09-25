<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/asistencia.php';

require_login();
$user = current_user();
if (!is_nadador_activo()) {
    flash('Esta sección solo está disponible para nadadores activos.', 'warning');
    header('Location: /socio/panel');
    exit;
}

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
$stmt->execute([$user['id'], $fecha_inicio, $fecha_fin]);
foreach ($stmt->fetchAll() as $r) {
    $asistencia_data[$r['fecha']] = $r;
}

$mes_anterior = date('Y-m', strtotime($fecha_inicio . ' -1 month'));
$mes_siguiente = date('Y-m', strtotime($fecha_inicio . ' +1 month'));
$meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$nombre_mes = ucfirst($meses_es[(int)date('m', strtotime($fecha_inicio)) - 1]) . ' ' . date('Y', strtotime($fecha_inicio));

[$total_presente, $total_registrado, $pct] = resumen_asistencia(array_map(fn($r) => (int)$r['presente'], $asistencia_data));

render_header('Mi asistencia', 'socio-asistencia');
?>
<main class="container" style="padding:24px 16px;">
  <h1>Mi asistencia</h1>
  <?php render_flash(); ?>

  <div class="card mb-6">
    <div class="d-flex gap-3 align-center flex-wrap">
      <div class="d-flex gap-2 align-center">
        <a href="?mes=<?= $mes_anterior ?>" class="btn btn-gray btn-sm"><i class="bi bi-chevron-left"></i></a>
        <span style="font-weight:600;min-width:140px;text-align:center;"><?= e($nombre_mes) ?></span>
        <a href="?mes=<?= $mes_siguiente ?>" class="btn btn-gray btn-sm"><i class="bi bi-chevron-right"></i></a>
      </div>
      <div style="margin-left:auto;font-weight:700;">
        Asistencia del mes: <?= $total_presente ?>/<?= $total_registrado ?>
        <?php if ($pct !== null): ?>
          <span style="color:<?= $pct >= 80 ? 'var(--green)' : ($pct < 50 ? 'var(--red)' : 'var(--text)') ?>;">(<?= $pct ?>%)</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <?php render_calendario_asistencia($dias_mes, $asistencia_data); ?>
  </div>
</main>
<?php render_footer(); ?>
