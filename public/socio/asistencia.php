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

$stmt = $pdo->prepare('SELECT liga FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$liga = $stmt->fetchColumn() ?: null;

// --- POST: apuntarse / quitarse un día (queda pendiente de verificar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fecha  = $_POST['fecha'] ?? '';
    $action = $_POST['action'] ?? '';
    $obs    = trim($_POST['observaciones'] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha || !puede_autoregistrar_dia($fecha, $liga)) {
        flash('No puedes apuntarte ese día.', 'danger');
    } elseif ($action === 'apuntar') {
        // Solo crea o edita si no hay un registro ya verificado por el entrenador.
        $pdo->prepare('
            INSERT INTO asistencia (user_id, fecha, presente, verificada, observaciones, registrado_por)
            VALUES (?, ?, 1, 0, ?, ?)
            ON DUPLICATE KEY UPDATE observaciones = IF(verificada = 0, VALUES(observaciones), observaciones)
        ')->execute([$user['id'], $fecha, mb_substr($obs, 0, 500) ?: null, $user['id']]);
        flash('Entrenamiento del ' . $d->format('d/m/Y') . ' apuntado. El entrenador lo verificará.', 'success');
    } elseif ($action === 'quitar') {
        $pdo->prepare('DELETE FROM asistencia WHERE user_id=? AND fecha=? AND verificada=0')->execute([$user['id'], $fecha]);
        flash('Entrenamiento del ' . $d->format('d/m/Y') . ' quitado.', 'success');
    }
    header('Location: /socio/asistencia?mes=' . substr($fecha, 0, 7));
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
$stmt = $pdo->prepare("SELECT fecha, presente, verificada, observaciones FROM asistencia WHERE user_id = ? AND fecha BETWEEN ? AND ?");
$stmt->execute([$user['id'], $fecha_inicio, $fecha_fin]);
foreach ($stmt->fetchAll() as $r) {
    $asistencia_data[$r['fecha']] = $r;
}

$mes_anterior = date('Y-m', strtotime($fecha_inicio . ' -1 month'));
$mes_siguiente = date('Y-m', strtotime($fecha_inicio . ' +1 month'));
$meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$nombre_mes = ucfirst($meses_es[(int)date('m', strtotime($fecha_inicio)) - 1]) . ' ' . date('Y', strtotime($fecha_inicio));

[$total_presente, $total_registrado, $pct] = resumen_asistencia(presencias_verificadas($asistencia_data));

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
    <?php render_calendario_asistencia($dias_mes, $asistencia_data,
        fn($dia, $reg) => puede_autoregistrar_dia($dia, $liga) && ($reg === null || !$reg['verificada'])); ?>
    <p class="text-muted text-sm" style="margin-top:10px;">
      <?= $liga === 'master'
          ? 'Haz clic en un día para apuntar que fuiste a entrenar.'
          : 'Haz clic en un sábado o domingo para apuntar que fuiste a entrenar.' ?>
      Queda <span style="color:#b45309;font-weight:600;">pendiente</span> hasta que el entrenador lo verifique.
    </p>
  </div>
</main>

<div class="modal-overlay" id="modal-asistencia" style="display:none;" onclick="if(event.target===this)cerrarModalAsistencia()">
  <div class="modal-box" style="max-width:400px;">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="fecha" id="asis-fecha">
      <div class="modal-header">
        <h3 id="asis-titulo">Entrenamiento</h3>
        <button type="button" class="modal-close" onclick="cerrarModalAsistencia()">&times;</button>
      </div>
      <div class="modal-body">
        <p id="asis-texto" class="mb-4"></p>
        <label class="form-label" for="asis-obs">Comentario (opcional)</label>
        <input type="text" name="observaciones" id="asis-obs" class="form-control" maxlength="500" placeholder="Ej.: entrené por libre en la piscina municipal">
      </div>
      <div class="modal-footer">
        <button type="submit" name="action" value="quitar" id="asis-quitar" class="btn btn-gray" style="margin-right:auto;color:var(--red);">Quitar</button>
        <button type="button" class="btn btn-gray" onclick="cerrarModalAsistencia()">Cancelar</button>
        <button type="submit" name="action" value="apuntar" id="asis-apuntar" class="btn btn-primary">Apuntar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalAsistencia(cell) {
  var fecha = cell.dataset.fecha;
  var p = fecha.split('-');
  var pendiente = cell.dataset.estado === 'pendiente';
  document.getElementById('asis-fecha').value = fecha;
  document.getElementById('asis-titulo').textContent = 'Entrenamiento del ' + p[2] + '/' + p[1] + '/' + p[0];
  document.getElementById('asis-texto').textContent = pendiente
    ? 'Ya lo apuntaste. Está pendiente de que el entrenador lo verifique.'
    : '¿Fuiste a entrenar este día?';
  document.getElementById('asis-obs').value = cell.dataset.obs || '';
  document.getElementById('asis-quitar').style.display = pendiente ? '' : 'none';
  document.getElementById('asis-apuntar').textContent = pendiente ? 'Guardar' : 'Apuntar';
  document.getElementById('modal-asistencia').style.display = 'flex';
}
function cerrarModalAsistencia() {
  document.getElementById('modal-asistencia').style.display = 'none';
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') cerrarModalAsistencia();
});
</script>
<?php render_footer(); ?>
