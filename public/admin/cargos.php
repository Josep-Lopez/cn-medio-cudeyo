<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin_area(['director_tecnico']);

$CARGOS    = cargos_disponibles();
$LIMITES   = cargos_limites();

// ── POST handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'asignar') {
        $user_id      = (int)($_POST['user_id'] ?? 0);
        $cargo        = $_POST['cargo'] ?? '';
        $fecha_inicio = trim($_POST['fecha_inicio'] ?? '');
        $notas        = trim($_POST['notas'] ?? '');

        if (!$user_id || !in_array($cargo, $CARGOS, true)) {
            flash('Datos inválidos.', 'danger');
        } else {
            // Validar fecha
            $dt = DateTime::createFromFormat('Y-m-d', $fecha_inicio);
            if (!$dt || $dt->format('Y-m-d') !== $fecha_inicio) {
                flash('Fecha de inicio inválida.', 'danger');
            } else {
                // Validar usuario existe y está activo
                $check = $pdo->prepare("SELECT id, nombre FROM users WHERE id=? AND estado='activo'");
                $check->execute([$user_id]);
                $userOk = $check->fetch();
                if (!$userOk) {
                    flash('El usuario no existe o no está activo.', 'danger');
                } else {
                    // No duplicar: ¿ya tiene ese cargo activo?
                    $dup = $pdo->prepare(
                        "SELECT id FROM cargos
                         WHERE user_id=? AND cargo=?
                           AND (fecha_fin IS NULL OR fecha_fin > CURDATE())"
                    );
                    $dup->execute([$user_id, $cargo]);
                    if ($dup->fetch()) {
                        flash('Este socio ya tiene ese cargo activo.', 'warning');
                    } else {
                        // Validar límite de titulares activos
                        $cnt = $pdo->prepare(
                            "SELECT COUNT(*) FROM cargos
                             WHERE cargo=?
                               AND (fecha_fin IS NULL OR fecha_fin > CURDATE())"
                        );
                        $cnt->execute([$cargo]);
                        $actuales = (int)$cnt->fetchColumn();
                        $limite   = $LIMITES[$cargo];
                        if ($actuales >= $limite) {
                            flash(
                                sprintf(
                                    'No se puede asignar: el cargo "%s" ya tiene %d titular(es) activo(s) (máximo %d). Revoca uno primero.',
                                    cargo_label($cargo), $actuales, $limite
                                ),
                                'danger'
                            );
                        } else {
                            $ins = $pdo->prepare(
                                'INSERT INTO cargos (user_id, cargo, fecha_inicio, notas) VALUES (?,?,?,?)'
                            );
                            $ins->execute([$user_id, $cargo, $fecha_inicio, $notas ?: null]);
                            flash(sprintf('Cargo "%s" asignado a %s.', cargo_label($cargo), $userOk['nombre']), 'success');
                        }
                    }
                }
            }
        }
    } elseif ($action === 'revocar') {
        $cargo_id = (int)($_POST['cargo_id'] ?? 0);
        if ($cargo_id) {
            $pdo->prepare('UPDATE cargos SET fecha_fin=CURDATE() WHERE id=? AND fecha_fin IS NULL')
                ->execute([$cargo_id]);
            flash('Cargo revocado.', 'warning');
        }
    } elseif ($action === 'eliminar') {
        $cargo_id = (int)($_POST['cargo_id'] ?? 0);
        if ($cargo_id) {
            $pdo->prepare('DELETE FROM cargos WHERE id=?')->execute([$cargo_id]);
            flash('Asignación eliminada del historial.', 'danger');
        }
    }

    header('Location: /admin/cargos');
    exit;
}

// ── Datos ────────────────────────────────────────────────────────
// Cargos activos agrupados por cargo
$sqlActivos = "
    SELECT c.id, c.user_id, c.cargo, c.fecha_inicio, c.notas, u.nombre, u.email
    FROM cargos c
    JOIN users u ON u.id = c.user_id
    WHERE c.fecha_fin IS NULL OR c.fecha_fin > CURDATE()
    ORDER BY c.cargo, c.fecha_inicio
";
$activos = $pdo->query($sqlActivos)->fetchAll();
$activosPorCargo = [];
foreach ($CARGOS as $c) $activosPorCargo[$c] = [];
foreach ($activos as $a) $activosPorCargo[$a['cargo']][] = $a;

// Historial: revocados (fecha_fin pasada)
$historial = $pdo->query("
    SELECT c.id, c.cargo, c.fecha_inicio, c.fecha_fin, c.notas, u.nombre, u.email
    FROM cargos c
    JOIN users u ON u.id = c.user_id
    WHERE c.fecha_fin IS NOT NULL AND c.fecha_fin <= CURDATE()
    ORDER BY c.fecha_fin DESC
    LIMIT 100
")->fetchAll();

// Usuarios activos para el select de asignación
$usuariosActivos = $pdo->query(
    "SELECT id, nombre, email FROM users WHERE estado='activo' ORDER BY nombre"
)->fetchAll();

render_header('Cargos directiva', 'admin-cargos');
render_admin_layout('cargos', function() use ($CARGOS, $LIMITES, $activosPorCargo, $historial, $usuariosActivos) {
?>

<div class="d-flex justify-between align-center mb-6" style="gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;">Cargos de la directiva</h1>
  <button class="btn btn-primary" onclick="document.getElementById('modalAsignar').style.display='flex'">
    <i class="bi bi-plus-circle-fill"></i> Asignar cargo
  </button>
</div>

<?php render_flash(); ?>

<div class="card mb-6">
  <div class="card-body">
    <p style="margin:0;color:var(--gray);font-size:14px;">
      <i class="bi bi-info-circle"></i>
      Cada cargo tiene un máximo de titulares activos. Un mismo socio puede acumular varios cargos.
    </p>
  </div>
</div>

<!-- ACTIVOS POR CARGO -->
<?php foreach ($CARGOS as $cargo): ?>
  <?php
    $titulares = $activosPorCargo[$cargo];
    $limite    = $LIMITES[$cargo];
    $actuales  = count($titulares);
  ?>
  <div class="card mb-4">
    <div class="card-header d-flex justify-between align-center" style="gap:12px;">
      <div>
        <h3 style="margin:0;font-size:18px;">
          <i class="bi bi-person-badge"></i> <?= e(cargo_label($cargo)) ?>
        </h3>
      </div>
      <div>
        <span class="badge <?= $actuales >= $limite ? 'badge-red' : 'badge-blue' ?>">
          <?= $actuales ?> / <?= $limite ?>
        </span>
      </div>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if (!$titulares): ?>
        <p style="padding:16px;margin:0;color:var(--gray);font-style:italic;">Vacante.</p>
      <?php else: ?>
        <table class="table" style="margin:0;">
          <thead>
            <tr>
              <th>Socio</th>
              <th>Email</th>
              <th>Desde</th>
              <th>Notas</th>
              <th style="width:120px;text-align:right;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($titulares as $t): ?>
              <tr>
                <td><strong><?= e($t['nombre']) ?></strong></td>
                <td><?= e($t['email']) ?></td>
                <td><?= e($t['fecha_inicio']) ?></td>
                <td><?= e($t['notas'] ?? '') ?></td>
                <td style="text-align:right;">
                  <form method="POST" style="display:inline;" data-confirm="¿Revocar este cargo? Se marcará con fecha de fin hoy.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="revocar">
                    <input type="hidden" name="cargo_id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">
                      <i class="bi bi-x-circle"></i> Revocar
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<!-- HISTORIAL -->
<div class="card mt-6">
  <div class="card-header">
    <h3 style="margin:0;font-size:18px;"><i class="bi bi-clock-history"></i> Historial</h3>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (!$historial): ?>
      <p style="padding:16px;margin:0;color:var(--gray);font-style:italic;">Sin historial.</p>
    <?php else: ?>
      <table class="table" style="margin:0;">
        <thead>
          <tr>
            <th>Cargo</th>
            <th>Socio</th>
            <th>Periodo</th>
            <th>Notas</th>
            <th style="width:120px;text-align:right;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historial as $h): ?>
            <tr>
              <td><?= e(cargo_label($h['cargo'])) ?></td>
              <td><strong><?= e($h['nombre']) ?></strong> · <span style="color:var(--gray);font-size:13px;"><?= e($h['email']) ?></span></td>
              <td><?= e($h['fecha_inicio']) ?> → <?= e($h['fecha_fin']) ?></td>
              <td><?= e($h['notas'] ?? '') ?></td>
              <td style="text-align:right;">
                <form method="POST" style="display:inline;" data-confirm="¿Eliminar definitivamente del historial?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="eliminar">
                  <input type="hidden" name="cargo_id" value="<?= (int)$h['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-gray">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- MODAL: Asignar cargo -->
<div id="modalAsignar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:24px;max-width:520px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="margin-top:0;margin-bottom:16px;"><i class="bi bi-plus-circle-fill"></i> Asignar cargo</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="asignar">

      <div class="form-group">
        <label class="form-label">Socio</label>
        <select name="user_id" class="form-control searchable" required>
          <option value="">— Seleccionar socio —</option>
          <?php foreach ($usuariosActivos as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?> · <?= e($u['email']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Cargo</label>
        <select name="cargo" class="form-control" required>
          <option value="">— Seleccionar cargo —</option>
          <?php foreach ($CARGOS as $c): ?>
            <option value="<?= e($c) ?>"><?= e(cargo_label($c)) ?> (máx <?= (int)$LIMITES[$c] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Fecha de inicio</label>
        <input type="date" name="fecha_inicio" class="form-control"
               value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">Notas (opcional)</label>
        <input type="text" name="notas" class="form-control" maxlength="255" placeholder="Ej: elegido en Asamblea 2026">
      </div>

      <div class="d-flex gap-2" style="justify-content:flex-end;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modalAsignar').style.display='none'">Cancelar</button>
        <button type="submit" class="btn btn-primary">Asignar</button>
      </div>
    </form>
  </div>
</div>

<?php
}); // render_admin_layout
render_footer();
