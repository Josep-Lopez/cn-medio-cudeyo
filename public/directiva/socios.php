<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_cargo(['presidente', 'secretario', 'tesorero', 'vocal', 'director_tecnico']);

$puedeEditarCuotas = is_admin() || user_tiene_cargo('tesorero');

$temporadaActiva = $pdo->query("SELECT valor FROM config WHERE clave='temporada_activa' LIMIT 1")->fetchColumn() ?: '2025-26';
$temporada       = $_GET['temporada'] ?? $temporadaActiva;

$LIGAS = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','absoluto'=>'Absoluto','master'=>'Master'];

// ── POST: edición de cuotas (solo tesorero/admin) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$puedeEditarCuotas) {
        http_response_code(403);
        die('Solo el tesorero puede modificar cuotas.');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'guardar_cuota') {
        $user_id      = (int)($_POST['user_id'] ?? 0);
        $temporada_in = trim($_POST['temporada'] ?? $temporadaActiva);
        $importe      = (float)str_replace(',', '.', $_POST['importe'] ?? '0');
        $estado       = $_POST['estado'] ?? 'pendiente';
        $fecha_pago   = trim($_POST['fecha_pago'] ?? '');
        $metodo       = $_POST['metodo'] ?? '';
        $notas        = trim($_POST['notas'] ?? '');

        $estadosOk = ['pendiente', 'pagada', 'exenta'];
        $metodosOk = ['transferencia', 'efectivo', 'domiciliacion', 'otro'];

        if (!$user_id || !in_array($estado, $estadosOk, true)) {
            flash('Datos inválidos.', 'danger');
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE id=? AND estado='activo'");
            $check->execute([$user_id]);
            if (!$check->fetch()) {
                flash('Usuario no encontrado.', 'danger');
            } else {
                $fechaP = ($estado === 'pagada' && $fecha_pago) ? $fecha_pago : null;
                if ($fechaP) {
                    $dt = DateTime::createFromFormat('Y-m-d', $fechaP);
                    if (!$dt || $dt->format('Y-m-d') !== $fechaP) $fechaP = null;
                }
                $met = in_array($metodo, $metodosOk, true) ? $metodo : null;
                if ($estado !== 'pagada') $met = null;

                $admin_id = current_user()['id'];

                $stmt = $pdo->prepare(
                    'INSERT INTO cuotas (user_id, temporada, importe, estado, fecha_pago, metodo, notas, creado_por)
                     VALUES (?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        importe=VALUES(importe),
                        estado=VALUES(estado),
                        fecha_pago=VALUES(fecha_pago),
                        metodo=VALUES(metodo),
                        notas=VALUES(notas),
                        creado_por=VALUES(creado_por),
                        updated_at=NOW()'
                );
                $stmt->execute([$user_id, $temporada_in, $importe, $estado, $fechaP, $met, $notas ?: null, $admin_id]);
                flash('Cuota actualizada.', 'success');
            }
        }
    } elseif ($action === 'eliminar_cuota') {
        $cuota_id = (int)($_POST['cuota_id'] ?? 0);
        if ($cuota_id) {
            $pdo->prepare('DELETE FROM cuotas WHERE id=?')->execute([$cuota_id]);
            flash('Cuota eliminada.', 'warning');
        }
    }

    $qs = http_build_query(array_filter([
        'temporada'   => $_GET['temporada']   ?? null,
        'liga'        => $_GET['liga']        ?? null,
        'tipo_socio'  => $_GET['tipo_socio']  ?? null,
        'estado_cuota'=> $_GET['estado_cuota']?? null,
        'q'           => $_GET['q']           ?? null,
    ]));
    header('Location: /directiva/socios' . ($qs ? '?' . $qs : ''));
    exit;
}

// ── Filtros ──────────────────────────────────────────────────────
$fLiga       = $_GET['liga'] ?? 'todos';
$fTipo       = $_GET['tipo_socio'] ?? 'todos';
$fEstadoCuota= $_GET['estado_cuota'] ?? 'todos';
$fQ          = trim($_GET['q'] ?? '');

$where  = ["u.estado='activo'"];
$params = [];
if ($fLiga !== 'todos' && isset($LIGAS[$fLiga]))     { $where[] = 'u.liga=?'; $params[] = $fLiga; }
if (in_array($fTipo, ['numerario','deportivo'], true)) { $where[] = 'u.tipo_socio=?'; $params[] = $fTipo; }
if ($fQ !== '')                                       { $where[] = '(u.nombre LIKE ? OR u.email LIKE ?)'; $params[] = "%$fQ%"; $params[] = "%$fQ%"; }

$sqlW = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT u.id, u.nombre, u.email, u.tipo_socio, u.liga, u.sexo, u.fecha_nacimiento,
           c.id AS cuota_id, c.importe, c.estado AS cuota_estado, c.fecha_pago, c.metodo, c.notas AS cuota_notas
    FROM users u
    LEFT JOIN cuotas c ON c.user_id = u.id AND c.temporada = ?
    $sqlW
    ORDER BY u.nombre
";
$qparams = array_merge([$temporada], $params);
$stmt = $pdo->prepare($sql);
$stmt->execute($qparams);
$rows = $stmt->fetchAll();

if ($fEstadoCuota !== 'todos') {
    $rows = array_values(array_filter($rows, function($r) use ($fEstadoCuota) {
        if ($fEstadoCuota === 'sin_cuota') return empty($r['cuota_estado']);
        return ($r['cuota_estado'] ?? '') === $fEstadoCuota;
    }));
}

// Totales
$total = count($rows);
$totalPagada    = 0;
$totalPendiente = 0;
$totalExenta    = 0;
$importeTotal   = 0.0;
$importeCobrado = 0.0;
foreach ($rows as $r) {
    $e = $r['cuota_estado'] ?? null;
    if ($e === 'pagada')     { $totalPagada++; $importeCobrado += (float)$r['importe']; }
    elseif ($e === 'exenta') { $totalExenta++; }
    else                     { $totalPendiente++; }
    $importeTotal += (float)($r['importe'] ?? 0);
}

// Temporadas disponibles
$temporadasDisp = $pdo->query("SELECT DISTINCT temporada FROM cuotas ORDER BY temporada DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($temporadaActiva, $temporadasDisp, true)) array_unshift($temporadasDisp, $temporadaActiva);

function edad_de($fecha): ?int
{
    if (!$fecha) return null;
    try {
        return (int)(new DateTime($fecha))->diff(new DateTime('today'))->y;
    } catch (Exception $e) {
        return null;
    }
}

render_header('Socios y cuotas', 'directiva-socios');
render_directiva_layout('socios', function() use (
    $rows, $LIGAS, $temporada, $temporadasDisp, $puedeEditarCuotas,
    $fLiga, $fTipo, $fEstadoCuota, $fQ,
    $total, $totalPagada, $totalPendiente, $totalExenta, $importeTotal, $importeCobrado
) {
?>

<div class="d-flex justify-between align-center mb-4" style="gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;">Socios y cuotas</h1>
  <span style="color:var(--gray);font-size:14px;">Temporada <strong><?= e($temporada) ?></strong></span>
</div>

<?php render_flash(); ?>

<!-- Resumen -->
<div class="card mb-4">
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;">
      <div>
        <div style="color:var(--gray);font-size:12px;text-transform:uppercase;">Socios</div>
        <div style="font-size:24px;font-weight:700;"><?= $total ?></div>
      </div>
      <div>
        <div style="color:var(--gray);font-size:12px;text-transform:uppercase;">Pagadas</div>
        <div style="font-size:24px;font-weight:700;color:#16a34a;"><?= $totalPagada ?></div>
      </div>
      <div>
        <div style="color:var(--gray);font-size:12px;text-transform:uppercase;">Pendientes</div>
        <div style="font-size:24px;font-weight:700;color:#BF4646;"><?= $totalPendiente ?></div>
      </div>
      <div>
        <div style="color:var(--gray);font-size:12px;text-transform:uppercase;">Exentas</div>
        <div style="font-size:24px;font-weight:700;color:#888;"><?= $totalExenta ?></div>
      </div>
      <div>
        <div style="color:var(--gray);font-size:12px;text-transform:uppercase;">Cobrado / Total</div>
        <div style="font-size:18px;font-weight:700;">
          <?= number_format($importeCobrado, 2, ',', '.') ?> € / <?= number_format($importeTotal, 2, ',', '.') ?> €
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filtros -->
<form method="GET" class="card mb-4">
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
      <div>
        <label class="form-label">Temporada</label>
        <select name="temporada" class="form-control">
          <?php foreach ($temporadasDisp as $t): ?>
            <option value="<?= e($t) ?>" <?= $temporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Liga</label>
        <select name="liga" class="form-control">
          <option value="todos">Todas</option>
          <?php foreach ($LIGAS as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $fLiga === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Tipo de socio</label>
        <select name="tipo_socio" class="form-control">
          <option value="todos">Todos</option>
          <option value="numerario" <?= $fTipo === 'numerario' ? 'selected' : '' ?>>Numerario</option>
          <option value="deportivo" <?= $fTipo === 'deportivo' ? 'selected' : '' ?>>Deportivo</option>
        </select>
      </div>
      <div>
        <label class="form-label">Estado cuota</label>
        <select name="estado_cuota" class="form-control">
          <option value="todos">Todas</option>
          <option value="pagada"    <?= $fEstadoCuota === 'pagada'    ? 'selected' : '' ?>>Pagada</option>
          <option value="pendiente" <?= $fEstadoCuota === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
          <option value="exenta"    <?= $fEstadoCuota === 'exenta'    ? 'selected' : '' ?>>Exenta</option>
          <option value="sin_cuota" <?= $fEstadoCuota === 'sin_cuota' ? 'selected' : '' ?>>Sin registrar</option>
        </select>
      </div>
      <div>
        <label class="form-label">Buscar</label>
        <input type="text" name="q" value="<?= e($fQ) ?>" class="form-control" placeholder="Nombre o email">
      </div>
      <div style="display:flex;align-items:flex-end;">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filtrar</button>
      </div>
    </div>
  </div>
</form>

<!-- Tabla socios -->
<div class="card">
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="table" style="margin:0;">
      <thead>
        <tr>
          <th>Socio</th>
          <th>Tipo</th>
          <th>Liga</th>
          <th>Edad</th>
          <th>Cuota <?= e($temporada) ?></th>
          <th>Importe</th>
          <th>Pago</th>
          <?php if ($puedeEditarCuotas): ?><th style="width:120px;text-align:right;">Acciones</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= $puedeEditarCuotas ? 8 : 7 ?>" style="text-align:center;color:var(--gray);padding:24px;">Sin resultados.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $estado = $r['cuota_estado'] ?? null;
              $badgeClass = match($estado) {
                  'pagada'    => 'badge-green',
                  'pendiente' => 'badge-red',
                  'exenta'    => 'badge-gray',
                  default     => 'badge-gray',
              };
              $estadoLabel = $estado ? ucfirst($estado) : 'Sin registrar';
              $edad = edad_de($r['fecha_nacimiento']);
              $cuotaJson = json_encode([
                  'user_id'    => (int)$r['id'],
                  'nombre'     => $r['nombre'],
                  'cuota_id'   => $r['cuota_id'] ? (int)$r['cuota_id'] : null,
                  'importe'    => $r['importe'] !== null ? (float)$r['importe'] : 0,
                  'estado'     => $r['cuota_estado'] ?? 'pendiente',
                  'fecha_pago' => $r['fecha_pago'],
                  'metodo'     => $r['metodo'],
                  'notas'      => $r['cuota_notas'],
              ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
            ?>
            <tr>
              <td>
                <strong><?= e($r['nombre']) ?></strong><br>
                <span style="color:var(--gray);font-size:12px;"><?= e($r['email']) ?></span>
              </td>
              <td><?= e($r['tipo_socio'] ? ucfirst($r['tipo_socio']) : '—') ?></td>
              <td><?= e($r['liga'] ? ($LIGAS[$r['liga']] ?? $r['liga']) : '—') ?></td>
              <td><?= $edad !== null ? $edad : '—' ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= e($estadoLabel) ?></span></td>
              <td><?= $r['importe'] !== null ? number_format((float)$r['importe'], 2, ',', '.') . ' €' : '—' ?></td>
              <td><?= e($r['fecha_pago'] ?? '—') ?></td>
              <?php if ($puedeEditarCuotas): ?>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-sm btn-secondary"
                          data-cuota='<?= e($cuotaJson) ?>'
                          onclick="abrirEditarCuota(JSON.parse(this.dataset.cuota))">
                    <i class="bi bi-pencil"></i> Editar
                  </button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($puedeEditarCuotas): ?>
<!-- Modal editar cuota -->
<div id="modalCuota" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:24px;max-width:520px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="margin-top:0;margin-bottom:4px;"><i class="bi bi-cash-stack"></i> Cuota de <span id="cuotaNombre"></span></h3>
    <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Temporada <?= e($temporada) ?></p>

    <form method="POST" id="formCuota">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="guardar_cuota">
      <input type="hidden" name="user_id" id="cuotaUserId">
      <input type="hidden" name="temporada" value="<?= e($temporada) ?>">

      <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
          <label class="form-label">Estado</label>
          <select name="estado" id="cuotaEstado" class="form-control" required onchange="toggleCuotaCampos()">
            <option value="pendiente">Pendiente</option>
            <option value="pagada">Pagada</option>
            <option value="exenta">Exenta</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Importe (€)</label>
          <input type="number" step="0.01" min="0" name="importe" id="cuotaImporte" class="form-control">
        </div>
      </div>

      <div id="cuotaPagoCampos">
        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label class="form-label">Fecha de pago</label>
            <input type="date" name="fecha_pago" id="cuotaFechaPago" class="form-control" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Método</label>
            <select name="metodo" id="cuotaMetodo" class="form-control">
              <option value="">—</option>
              <option value="transferencia">Transferencia</option>
              <option value="efectivo">Efectivo</option>
              <option value="domiciliacion">Domiciliación</option>
              <option value="otro">Otro</option>
            </select>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notas</label>
        <input type="text" name="notas" id="cuotaNotas" class="form-control" maxlength="255">
      </div>

      <div class="d-flex" style="justify-content:space-between;gap:8px;align-items:center;">
        <div>
          <button type="button" class="btn btn-danger btn-sm" id="cuotaBtnEliminar" style="display:none;"
                  onclick="enviarEliminarCuota()">
            <i class="bi bi-trash"></i> Eliminar
          </button>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-gray" onclick="document.getElementById('modalCuota').style.display='none'">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
      </div>
    </form>

    <!-- Form oculto para eliminar (submit programático) -->
    <form method="POST" id="formEliminarCuota" style="display:none;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="eliminar_cuota">
      <input type="hidden" name="cuota_id" id="cuotaIdDelete">
    </form>
  </div>
</div>

<script>
let cuotaActualId = null;

function abrirEditarCuota(d) {
  cuotaActualId = d.cuota_id;
  document.getElementById('cuotaNombre').textContent  = d.nombre;
  document.getElementById('cuotaUserId').value        = d.user_id;
  document.getElementById('cuotaEstado').value        = d.estado || 'pendiente';
  document.getElementById('cuotaImporte').value       = d.importe || 0;
  document.getElementById('cuotaFechaPago').value     = d.fecha_pago || '';
  document.getElementById('cuotaMetodo').value        = d.metodo || '';
  document.getElementById('cuotaNotas').value         = d.notas || '';
  document.getElementById('cuotaBtnEliminar').style.display = d.cuota_id ? '' : 'none';
  toggleCuotaCampos();
  document.getElementById('modalCuota').style.display = 'flex';
}

function toggleCuotaCampos() {
  const estado = document.getElementById('cuotaEstado').value;
  document.getElementById('cuotaPagoCampos').style.display = estado === 'pagada' ? '' : 'none';
}

function enviarEliminarCuota() {
  if (!cuotaActualId) return;
  showConfirm('¿Eliminar la cuota de este socio para esta temporada?', function() {
    document.getElementById('cuotaIdDelete').value = String(cuotaActualId);
    document.getElementById('formEliminarCuota').submit();
  });
}
</script>
<?php endif; ?>

<?php
});
render_footer();
