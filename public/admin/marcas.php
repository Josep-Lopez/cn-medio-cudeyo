<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/rfen.php';

require_admin();

$PRUEBAS = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];

// --- POST: gestión de marcas ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $marca_id  = (int)($_POST['marca_id'] ?? 0);
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $prueba    = $_POST['prueba'] ?? '';
        $piscina   = $_POST['piscina'] ?? '25m';
        $tiempo    = trim($_POST['tiempo'] ?? '');
        $lugar     = trim($_POST['lugar'] ?? '');
        $fecha_m   = $_POST['fecha_marca'] ?? date('Y-m-d');

        if ($user_id && in_array($prueba, $PRUEBAS) && in_array($piscina, ['25m','50m']) && $tiempo) {
            $secs = tiempo_a_segundos($tiempo);
            $es_parcial = isset($_POST['es_parcial']) ? 1 : 0;
            if ($secs > 0) {
                if ($marca_id > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE marcas
                        SET prueba=?, piscina=?, tiempo=?, tiempo_seg=?, fecha_marca=?, lugar=?, es_parcial=?, updated_at=NOW()
                        WHERE id=? AND user_id=?
                    ');
                    $stmt->execute([$prueba, $piscina, $tiempo, $secs, $fecha_m, $lugar, $es_parcial, $marca_id, $user_id]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO marcas (user_id, prueba, piscina, tiempo, tiempo_seg, fecha_marca, lugar, es_parcial)
                        VALUES (?,?,?,?,?,?,?,?)
                    ');
                    $stmt->execute([$user_id, $prueba, $piscina, $tiempo, $secs, $fecha_m, $lugar, $es_parcial]);
                }
                flash('Marca guardada correctamente.', 'success');
            } else {
                flash('Formato de tiempo incorrecto. Usa mm:ss.cc o ss.cc', 'danger');
            }
        }
    } elseif ($action === 'import_all') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $stmtU = $pdo->prepare('SELECT rfen_id FROM users WHERE id=?');
        $stmtU->execute([$uid]);
        $rfen_id = $stmtU->fetchColumn();
        if ($uid && $rfen_id) {
            $r = rfen_import_marks($pdo, $uid, $rfen_id, null);
            if ($r['error']) {
                flash('Error RFEN: ' . $r['error'], 'danger');
            } else {
                flash("RFEN (todas): {$r['procesadas']} procesadas · {$r['insertadas']} insertadas · {$r['actualizadas']} actualizadas · {$r['sin_cambios']} sin cambios.", 'success');
            }
        } else {
            flash('Este usuario no tiene vinculación RFEN.', 'danger');
        }
    } elseif ($action === 'delete_temporada') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $temp = $_POST['temporada'] ?? '';
        if ($uid && $temp) {
            $stmt = $pdo->prepare('DELETE FROM marcas WHERE user_id=? AND temporada=?');
            $stmt->execute([$uid, $temp]);
            $deleted = $stmt->rowCount();
            flash("Se han eliminado $deleted marca(s) de la temporada $temp.", 'warning');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['marca_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM marcas WHERE id=?')->execute([$id]);
            flash('Marca eliminada.', 'warning');
        }
    }
    $back = http_build_query(array_filter([
        'user_id' => $_POST['user_id_back'] ?? '',
        'temporada' => $_POST['temporada_back'] ?? '',
        'piscina' => $_POST['piscina_back'] ?? '',
        'prueba' => $_POST['prueba_back'] ?? '',
    ]));
    header('Location: /admin/marcas?' . $back);
    exit;
}

// --- Parámetros GET ---
$selectedUserId  = (int)($_GET['user_id'] ?? 0);
$selectedPiscina = $_GET['piscina'] ?? '25m';
$selectedPrueba  = $_GET['prueba'] ?? '';

$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporadas_disp = [];
for ($y = $current_year; $y >= 2012; $y--)
    $temporadas_disp[] = $y . '-' . substr((string)($y + 1), 2);
$temporada = $_GET['temporada'] ?? $temporadas_disp[0];
if (!in_array($temporada, $temporadas_disp)) $temporada = $temporadas_disp[0];
if (!in_array($selectedPrueba, $PRUEBAS, true)) $selectedPrueba = '';

// Carga todos los nadadores activos
$stmt = $pdo->query("SELECT id, nombre, liga FROM users WHERE estado='activo' AND rol='socio' ORDER BY nombre");
$nadadores = $stmt->fetchAll();

// Carga marcas del nadador seleccionado
$marcas_usuario = [];
$all_marks = [];
$selected_user = null;
if ($selectedUserId) {
    $stmt = $pdo->prepare('SELECT nombre, liga, rfen_id FROM users WHERE id=?');
    $stmt->execute([$selectedUserId]);
    $selected_user = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT * FROM marcas WHERE user_id=? AND temporada=? ORDER BY prueba, piscina');
    $stmt->execute([$selectedUserId, $temporada]);
    $all_marks = $stmt->fetchAll();
    // Indexar por prueba+piscina conservando la mejor marca para la cuadrícula
    foreach ($all_marks as $m) {
        $key = $m['prueba'] . '_' . $m['piscina'];
        if (!isset($marcas_usuario[$key]) || (float)$m['tiempo_seg'] < (float)$marcas_usuario[$key]['tiempo_seg']) {
            $marcas_usuario[$key] = $m;
        }
    }
}

render_header('Gestión de marcas', 'admin-marcas');
render_admin_layout('marcas', function() use ($PRUEBAS, $selectedUserId, $selectedPiscina, $selectedPrueba, $nadadores, $marcas_usuario, $selected_user, $temporada, $temporadas_disp, $all_marks) {
?>

<h1>Gestión de marcas</h1>
<?php render_flash(); ?>

<!-- Seleccionar nadador -->
<div class="card mb-6">
  <h2 style="font-size:15px;font-weight:700;margin-bottom:14px;">Seleccionar nadador/a</h2>
  <form method="GET" class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;min-width:220px;">
      <label class="form-label">Nadador/a</label>
      <select name="user_id" class="form-control searchable" onchange="this.form.submit()">
        <option value="">— Seleccionar —</option>
        <?php foreach ($nadadores as $n): ?>
          <option value="<?= $n['id'] ?>" <?= $selectedUserId === (int)$n['id'] ? 'selected' : '' ?>><?= e($n['nombre']) ?><?= !empty($n['liga']) ? ' · ' . e(format_liga($n['liga'])) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Temporada</label>
      <select name="temporada" class="form-control" onchange="this.form.submit()">
        <?php foreach ($temporadas_disp as $t): ?>
          <option value="<?= e($t) ?>" <?= $temporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:220px;">
      <label class="form-label">Prueba</label>
      <select name="prueba" class="form-control" onchange="this.form.submit()">
        <?php render_prueba_options($selectedPrueba, true); ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Piscina</label>
      <select name="piscina" class="form-control" onchange="this.form.submit()">
        <option value="25m" <?= $selectedPiscina === '25m' ? 'selected' : '' ?>>25 metros</option>
        <option value="50m" <?= $selectedPiscina === '50m' ? 'selected' : '' ?>>50 metros</option>
      </select>
    </div>
  </form>
</div>

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
    <div style="font-weight:700;margin-bottom:6px;">Cargando datos</div>
    <div class="text-muted text-sm">Espera un momento, estamos actualizando la página.</div>
  </div>
</div>

<!-- Paso 3: Editar marcas del nadador -->
<?php if ($selectedUserId && $selected_user): ?>
<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title"><?= e($selected_user['nombre']) ?></h2>
      <span class="text-muted text-sm"><?= e(format_liga($selected_user['liga'] ?? '')) ?> · Temporada <?= e($temporada) ?> · Piscina <?= e($selectedPiscina) ?></span>
    </div>
    <div class="d-flex gap-2">
      <?php if (!empty($selected_user['rfen_id'])): ?>
        <a href="/admin/rfen_importar?user_id=<?= $selectedUserId ?>&temporada=<?= e($temporada) ?>" class="btn btn-primary btn-sm js-loading-link">
          <i class="bi bi-cloud-download-fill"></i> Importar desde RFEN
        </a>
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('importAllModal').style.display='flex'">
          <i class="bi bi-cloud-download"></i> Importar todo
        </button>
      <?php endif; ?>
      <a href="/admin/ranking?liga=<?= e($selected_user['liga'] ?? '') ?>" class="btn btn-secondary btn-sm">Ver ranking</a>
    </div>
  </div>

  <?php
  $marks_historial = array_values(array_filter(
    $all_marks,
    fn(array $m): bool =>
      ($m['piscina'] ?? '') === $selectedPiscina &&
      ($selectedPrueba === '' || ($m['prueba'] ?? '') === $selectedPrueba)
  ));
  usort($marks_historial, fn($a, $b) => [$b['fecha_marca'], $a['tiempo_seg'], $a['prueba'], $a['id']] <=> [$a['fecha_marca'], $b['tiempo_seg'], $b['prueba'], $b['id']]);
  ?>

  <div class="card" style="margin-top:24px;">
    <div class="card-header">
      <div>
        <h3 class="card-title">Histórico completo</h3>
        <span class="text-muted text-sm"><?= count($marks_historial) ?> marca<?= count($marks_historial) !== 1 ? 's' : '' ?> en <?= e($selectedPiscina) ?> durante <?= e($temporada) ?></span>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm"
                type="button"
                onclick="openCreateForm()">
          <i class="bi bi-plus-lg"></i> Añadir marca
        </button>
        <button class="btn btn-danger btn-sm"
                type="button"
                onclick="document.getElementById('deleteTemporadaModal').style.display='flex'">
          <i class="bi bi-trash3"></i> Borrar temporada
        </button>
      </div>
    </div>

    <?php if (!$marks_historial): ?>
      <div class="text-muted" style="padding:20px;">No hay marcas registradas para este filtro.</div>
    <?php else: ?>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Prueba</th>
              <th>Tiempo</th>
              <th>Lugar</th>
              <th>Fecha</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($marks_historial as $marca): ?>
              <tr>
                <td>
                  <strong><?= e(format_prueba($marca['prueba'])) ?></strong>
                  <?php if ($marca['es_parcial']): ?>
                    <span style="font-size:11px;background:#f3f4f6;color:#6b7280;border-radius:4px;padding:1px 6px;margin-left:4px;">parcial</span>
                  <?php endif; ?>
                </td>
                <td><span class="mark-time"><?= e($marca['tiempo']) ?></span></td>
                <td class="text-sm text-muted"><?= e($marca['lugar'] ?? '') ?></td>
                <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($marca['fecha_marca'])) ?></td>
                <td>
                  <button class="btn btn-secondary btn-sm"
                          onclick="openForm('<?= e($marca['prueba']) ?>', '<?= e($marca['tiempo']) ?>', '<?= e($marca['fecha_marca']) ?>', <?= (int)$marca['id'] ?>, <?= htmlspecialchars(json_encode($marca['lugar'] ?? ''), ENT_QUOTES) ?>, <?= (int)$marca['es_parcial'] ?>)">
                    Editar
                  </button>
                  <form method="POST" style="display:inline;"
                        data-confirm="¿Eliminar esta marca?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="marca_id" value="<?= (int)$marca['id'] ?>">
                    <input type="hidden" name="user_id_back" value="<?= $selectedUserId ?>">
                    <input type="hidden" name="temporada_back" value="<?= e($temporada) ?>">
                    <input type="hidden" name="piscina_back" value="<?= e($selectedPiscina) ?>">
                    <input type="hidden" name="prueba_back" value="<?= e($selectedPrueba) ?>">
                    <button class="btn btn-danger btn-sm">🗑</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal: editar/añadir marca -->
<div id="marcaModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:200;align-items:center;justify-content:center;">
  <div style="background:white;border-radius:12px;padding:32px;max-width:400px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="margin-bottom:20px;" id="modalTitle">Añadir marca</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action"      value="save">
      <input type="hidden" name="user_id"     value="<?= $selectedUserId ?>">
      <input type="hidden" name="piscina"     value="<?= e($selectedPiscina) ?>">
      <input type="hidden" name="user_id_back"  value="<?= $selectedUserId ?>">
      <input type="hidden" name="temporada_back" value="<?= e($temporada) ?>">
      <input type="hidden" name="piscina_back" value="<?= e($selectedPiscina) ?>">
      <input type="hidden" name="prueba_back" value="<?= e($selectedPrueba) ?>">
      <input type="hidden" name="marca_id" id="modalMarcaId" value="0">
      <input type="hidden" name="prueba" id="modalPrueba">

      <div class="form-group">
        <label class="form-label">Prueba</label>
        <input type="text" id="modalPruebaDisplay" class="form-control" readonly style="background:#f5f5f5;display:none;">
        <select id="modalPruebaSelect" class="form-control" onchange="syncModalPrueba(this.value)">
          <?php render_prueba_options('', false); ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Tiempo (mm:ss.cc o ss.cc)</label>
        <input type="text" name="tiempo" id="modalTiempo" class="form-control"
               placeholder="1:23.45" required pattern="(\d+:)?\d{1,2}\.\d{2}">
        <div class="form-hint">Ejemplos: 28.50 · 1:05.43 · 4:12.09</div>
      </div>
      <div class="form-group">
        <label class="form-label">Lugar (opcional)</label>
        <input type="text" name="lugar" id="modalLugar" class="form-control" placeholder="Maliaño, Santander...">
      </div>
      <div class="form-group">
        <label class="form-label">Fecha de la marca</label>
        <input type="date" name="fecha_marca" id="modalFecha" class="form-control" required>
      </div>
      <div class="form-group" style="margin-bottom:4px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
          <input type="checkbox" name="es_parcial" id="modalEsParcial" value="1">
          Es tiempo parcial (split)
        </label>
        <div class="form-hint">Marcarlo excluye este tiempo de rankings, récords y gráficas.</div>
      </div>
      <div class="d-flex gap-2" style="margin-top:8px;">
        <button type="submit" class="btn btn-primary">Guardar</button>
        <button type="button" class="btn btn-gray" onclick="closeForm()">Cancelar</button>
      </div>
    </form>
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

document.querySelectorAll('form[method="GET"]').forEach(form => {
  form.addEventListener('submit', () => {
    showPageLoading('Espera un momento, estamos aplicando los filtros.');
  });
});

document.querySelectorAll('.js-loading-link').forEach(link => {
  link.addEventListener('click', () => {
    showPageLoading('Consultando RFEN, esto puede tardar unos segundos.');
  });
});

window.addEventListener('pageshow', () => {
  const overlay = document.getElementById('pageLoadingOverlay');
  if (overlay) overlay.style.display = 'none';
});

function openForm(prueba, tiempo, fecha, marcaId, lugar, esParcial) {
  const isEdit = !!marcaId;
  const pruebaInput = document.getElementById('modalPrueba');
  const pruebaDisplay = document.getElementById('modalPruebaDisplay');
  const pruebaSelect = document.getElementById('modalPruebaSelect');
  document.getElementById('modalMarcaId').value = marcaId || 0;
  pruebaInput.value = prueba;
  pruebaDisplay.value = prueba ? formatPruebaLabel(prueba) : '';
  pruebaSelect.value = prueba || '';
  pruebaDisplay.style.display = isEdit ? '' : 'none';
  pruebaSelect.style.display = isEdit ? 'none' : '';
  document.getElementById('modalTiempo').value = tiempo;
  document.getElementById('modalLugar').value = lugar || '';
  document.getElementById('modalFecha').value = fecha;
  document.getElementById('modalEsParcial').checked = !!esParcial;
  document.getElementById('modalTitle').textContent = isEdit ? ('Editar marca — ' + prueba) : 'Añadir marca';
  const modal = document.getElementById('marcaModal');
  modal.style.display = 'flex';
}
function syncModalPrueba(value) {
  document.getElementById('modalPrueba').value = value;
}
function formatPruebaLabel(prueba) {
  const labels = {
    '50L': '50 Libre',
    '100L': '100 Libre',
    '200L': '200 Libre',
    '400L': '400 Libre',
    '800L': '800 Libre',
    '1500L': '1500 Libre',
    '50E': '50 Espalda',
    '100E': '100 Espalda',
    '200E': '200 Espalda',
    '50B': '50 Braza',
    '100B': '100 Braza',
    '200B': '200 Braza',
    '50M': '50 Mariposa',
    '100M': '100 Mariposa',
    '200M': '200 Mariposa',
    '100X': '100 Estilos',
    '200X': '200 Estilos',
    '400X': '400 Estilos'
  };
  return labels[prueba] || prueba;
}
function openCreateForm() {
  openForm('', '', '<?= date('Y-m-d') ?>', 0, '');
}
function closeForm() {
  document.getElementById('modalMarcaId').value = 0;
  document.getElementById('modalPrueba').value = '';
  document.getElementById('marcaModal').style.display = 'none';
}
document.getElementById('marcaModal').addEventListener('click', function(e) {
  if (e.target === this) closeForm();
});
</script>

<!-- Modal: importar todo RFEN -->
<div id="importAllModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:200;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:32px;max-width:420px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="margin-bottom:12px;"><i class="bi bi-cloud-download"></i> Importar todo desde RFEN</h3>
    <p style="margin-bottom:20px;color:var(--gray);">
      Se importarán <strong>todas las marcas históricas</strong> de <?= e($selected_user['nombre']) ?> desde RFEN.
      Las marcas existentes solo se actualizarán si el nuevo tiempo es mejor.
    </p>
    <form method="POST" onsubmit="showPageLoading('Importando todas las marcas de RFEN...');return true;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import_all">
      <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
      <input type="hidden" name="user_id_back" value="<?= $selectedUserId ?>">
      <input type="hidden" name="temporada_back" value="<?= e($temporada) ?>">
      <input type="hidden" name="piscina_back" value="<?= e($selectedPiscina) ?>">
      <input type="hidden" name="prueba_back" value="<?= e($selectedPrueba) ?>">
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Importar</button>
        <button type="button" class="btn btn-gray" onclick="document.getElementById('importAllModal').style.display='none'">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: eliminar todas las marcas de la temporada -->
<div id="deleteTemporadaModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:200;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:32px;max-width:420px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="margin-bottom:12px;color:var(--red);"><i class="bi bi-exclamation-triangle-fill"></i> Eliminar marcas de temporada</h3>
    <p style="margin-bottom:20px;color:var(--gray);">
      Se eliminarán <strong>todas las marcas</strong> de <strong><?= e($selected_user['nombre']) ?></strong>
      en la temporada <strong><?= e($temporada) ?></strong>.<br>
      Esta acción no se puede deshacer.
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_temporada">
      <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
      <input type="hidden" name="temporada" value="<?= e($temporada) ?>">
      <input type="hidden" name="user_id_back" value="<?= $selectedUserId ?>">
      <input type="hidden" name="temporada_back" value="<?= e($temporada) ?>">
      <input type="hidden" name="piscina_back" value="<?= e($selectedPiscina) ?>">
      <input type="hidden" name="prueba_back" value="<?= e($selectedPrueba) ?>">
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-danger">Eliminar todas</button>
        <button type="button" class="btn btn-gray" onclick="document.getElementById('deleteTemporadaModal').style.display='none'">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php
});
render_footer();
