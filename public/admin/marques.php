<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

$PROVES = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];

// --- POST: gestión de marcas ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $marca_id  = (int)($_POST['marca_id'] ?? 0);
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $prova     = $_POST['prova'] ?? '';
        $piscina   = $_POST['piscina'] ?? '25m';
        $temps     = trim($_POST['temps'] ?? '');
        $lugar     = trim($_POST['lugar'] ?? '');
        $data_m    = $_POST['data_marca'] ?? date('Y-m-d');

        if ($user_id && in_array($prova, $PROVES) && in_array($piscina, ['25m','50m']) && $temps) {
            $secs = temps_a_segons($temps);
            if ($secs > 0) {
                if ($marca_id > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE marques
                        SET prova=?, piscina=?, temps=?, temps_seg=?, data_marca=?, lugar=?, updated_at=NOW()
                        WHERE id=? AND user_id=?
                    ');
                    $stmt->execute([$prova, $piscina, $temps, $secs, $data_m, $lugar, $marca_id, $user_id]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO marques (user_id, prova, piscina, temps, temps_seg, data_marca, lugar)
                        VALUES (?,?,?,?,?,?,?)
                    ');
                    $stmt->execute([$user_id, $prova, $piscina, $temps, $secs, $data_m, $lugar]);
                }
                flash('Marca guardada correctamente.', 'success');
            } else {
                flash('Formato de tiempo incorrecto. Usa mm:ss.cc o ss.cc', 'danger');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['marca_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM marques WHERE id=?')->execute([$id]);
            flash('Marca eliminada.', 'warning');
        }
    }
    $back = http_build_query(array_filter([
        'lliga' => $_POST['lliga_back'] ?? '',
        'user_id' => $_POST['user_id_back'] ?? '',
        'temporada' => $_POST['temporada_back'] ?? '',
        'piscina' => $_POST['piscina_back'] ?? '',
        'prova' => $_POST['prova_back'] ?? '',
    ]));
    header('Location: /admin/marques?' . $back);
    exit;
}

// --- Parámetros GET ---
$selectedLliga   = $_GET['lliga']   ?? '';
$selectedUserId  = (int)($_GET['user_id'] ?? 0);
$selectedPiscina = $_GET['piscina'] ?? '25m';
$selectedProva   = $_GET['prova'] ?? '';

$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporades_disp = [];
for ($y = $current_year; $y >= $current_year - 4; $y--)
    $temporades_disp[] = $y . '-' . substr((string)($y + 1), 2);
$temporada = $_GET['temporada'] ?? $temporades_disp[0];
if (!in_array($temporada, $temporades_disp)) $temporada = $temporades_disp[0];
if (!in_array($selectedProva, $PROVES, true)) $selectedProva = '';

// Carga nadadores de la liga seleccionada
$nadadors = [];
if ($selectedLliga && in_array($selectedLliga, ['benjamin','alevin','infantil','junior','master'])) {
    $stmt = $pdo->prepare('SELECT id, nom FROM users WHERE lliga=? AND estado=\'activo\' AND rol=\'soci\' ORDER BY nom');
    $stmt->execute([$selectedLliga]);
    $nadadors = $stmt->fetchAll();
}

// Carga marcas del nadador seleccionado
$marcas_usuario = [];
$all_marks = [];
$selected_user = null;
if ($selectedUserId) {
    $stmt = $pdo->prepare('SELECT nom, lliga, rfen_id FROM users WHERE id=?');
    $stmt->execute([$selectedUserId]);
    $selected_user = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT * FROM marques WHERE user_id=? AND temporada=? ORDER BY prova, piscina');
    $stmt->execute([$selectedUserId, $temporada]);
    $all_marks = $stmt->fetchAll();
    // Indexar por prova+piscina conservando la mejor marca para la cuadrícula
    foreach ($all_marks as $m) {
        $key = $m['prova'] . '_' . $m['piscina'];
        if (!isset($marcas_usuario[$key]) || (float)$m['temps_seg'] < (float)$marcas_usuario[$key]['temps_seg']) {
            $marcas_usuario[$key] = $m;
        }
    }
}

render_header('Gestión de marcas', 'admin-marques');
render_admin_layout('marques', function() use ($PROVES, $selectedLliga, $selectedUserId, $selectedPiscina, $selectedProva, $nadadors, $marcas_usuario, $selected_user, $temporada, $temporades_disp, $all_marks) {
?>

<h1>Gestión de marcas</h1>
<?php render_flash(); ?>

<!-- Paso 1: Seleccionar liga -->
<div class="card mb-6">
  <h2 style="font-size:15px;font-weight:700;margin-bottom:14px;">1. Seleccionar categoría</h2>
  <form method="GET" class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;min-width:180px;">
      <label class="form-label">Categoría (liga)</label>
      <select name="lliga" class="form-control" onchange="this.form.submit()">
        <option value="">— Seleccionar —</option>
        <?php foreach (['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior/Absoluto','master'=>'Master'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $selectedLliga === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<!-- Paso 2: Seleccionar nadador -->
<?php if ($selectedLliga && $nadadors): ?>
<div class="card mb-6">
  <h2 style="font-size:15px;font-weight:700;margin-bottom:14px;">2. Seleccionar nadador/a</h2>
  <form method="GET" class="d-flex gap-3 align-center flex-wrap">
    <input type="hidden" name="lliga" value="<?= e($selectedLliga) ?>">
    <div class="form-group" style="margin:0;min-width:220px;">
      <label class="form-label">Nadador/a</label>
      <select name="user_id" class="form-control" onchange="this.form.submit()">
        <option value="">— Seleccionar —</option>
        <?php foreach ($nadadors as $n): ?>
          <option value="<?= $n['id'] ?>" <?= $selectedUserId === (int)$n['id'] ? 'selected' : '' ?>><?= e($n['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Temporada</label>
      <select name="temporada" class="form-control" onchange="this.form.submit()">
        <?php foreach ($temporades_disp as $t): ?>
          <option value="<?= e($t) ?>" <?= $temporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:220px;">
      <label class="form-label">Prueba</label>
      <select name="prova" class="form-control" onchange="this.form.submit()">
        <option value="">Todas las pruebas</option>
        <?php foreach ($PROVES as $prova): ?>
          <option value="<?= e($prova) ?>" <?= $selectedProva === $prova ? 'selected' : '' ?>><?= e(format_prova($prova)) ?></option>
        <?php endforeach; ?>
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
<?php elseif ($selectedLliga): ?>
<div class="alert alert-info">No hay nadadores activos en esta categoría.</div>
<?php endif; ?>

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
      <h2 class="card-title"><?= e($selected_user['nom']) ?></h2>
      <span class="text-muted text-sm"><?= e(format_lliga($selected_user['lliga'] ?? '')) ?> · Temporada <?= e($temporada) ?> · Piscina <?= e($selectedPiscina) ?></span>
    </div>
    <div class="d-flex gap-2">
      <?php if (!empty($selected_user['rfen_id'])): ?>
        <a href="/admin/rfen_importar?user_id=<?= $selectedUserId ?>&temporada=<?= e($temporada) ?>" class="btn btn-primary btn-sm js-loading-link">
          <i class="bi bi-cloud-download-fill"></i> Importar desde RFEN
        </a>
      <?php endif; ?>
      <a href="/admin/ranking?lliga=<?= e($selectedLliga) ?>" class="btn btn-secondary btn-sm">Ver ranking</a>
    </div>
  </div>

  <?php
  $marks_historial = array_values(array_filter(
    $all_marks,
    fn(array $m): bool =>
      ($m['piscina'] ?? '') === $selectedPiscina &&
      ($selectedProva === '' || ($m['prova'] ?? '') === $selectedProva)
  ));
  usort($marks_historial, fn($a, $b) => [$b['data_marca'], $a['temps_seg'], $a['prova'], $a['id']] <=> [$a['data_marca'], $b['temps_seg'], $b['prova'], $b['id']]);
  ?>

  <div class="card" style="margin-top:24px;">
    <div class="card-header">
      <div>
        <h3 class="card-title">Histórico completo</h3>
        <span class="text-muted text-sm"><?= count($marks_historial) ?> marca<?= count($marks_historial) !== 1 ? 's' : '' ?> en <?= e($selectedPiscina) ?> durante <?= e($temporada) ?></span>
      </div>
      <button class="btn btn-primary btn-sm"
              type="button"
              onclick="openCreateForm()">
        <i class="bi bi-plus-lg"></i> Añadir marca
      </button>
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
                <td><strong><?= e(format_prova($marca['prova'])) ?></strong></td>
                <td><span class="mark-time"><?= e($marca['temps']) ?></span></td>
                <td class="text-sm text-muted"><?= e($marca['lugar'] ?? '') ?></td>
                <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($marca['data_marca'])) ?></td>
                <td>
                  <button class="btn btn-secondary btn-sm"
                          onclick="openForm('<?= e($marca['prova']) ?>', '<?= e($marca['temps']) ?>', '<?= e($marca['data_marca']) ?>', <?= (int)$marca['id'] ?>, <?= htmlspecialchars(json_encode($marca['lugar'] ?? ''), ENT_QUOTES) ?>)">
                    Editar
                  </button>
                  <form method="POST" style="display:inline;"
                        onsubmit="return confirm('¿Eliminar esta marca?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="marca_id" value="<?= (int)$marca['id'] ?>">
                    <input type="hidden" name="lliga_back" value="<?= e($selectedLliga) ?>">
                    <input type="hidden" name="user_id_back" value="<?= $selectedUserId ?>">
                    <input type="hidden" name="temporada_back" value="<?= e($temporada) ?>">
                    <input type="hidden" name="piscina_back" value="<?= e($selectedPiscina) ?>">
                    <input type="hidden" name="prova_back" value="<?= e($selectedProva) ?>">
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
      <input type="hidden" name="lliga_back"    value="<?= e($selectedLliga) ?>">
      <input type="hidden" name="user_id_back"  value="<?= $selectedUserId ?>">
      <input type="hidden" name="temporada_back" value="<?= e($temporada) ?>">
      <input type="hidden" name="piscina_back" value="<?= e($selectedPiscina) ?>">
      <input type="hidden" name="prova_back" value="<?= e($selectedProva) ?>">
      <input type="hidden" name="marca_id" id="modalMarcaId" value="0">
      <input type="hidden" name="prova" id="modalProva">

      <div class="form-group">
        <label class="form-label">Prueba</label>
        <input type="text" id="modalProvaDisplay" class="form-control" readonly style="background:#f5f5f5;display:none;">
        <select id="modalProvaSelect" class="form-control" onchange="syncModalProva(this.value)">
          <option value="">— Seleccionar —</option>
          <?php foreach ($PROVES as $prova): ?>
            <option value="<?= e($prova) ?>"><?= e(format_prova($prova)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Tiempo (mm:ss.cc o ss.cc)</label>
        <input type="text" name="temps" id="modalTemps" class="form-control"
               placeholder="1:23.45" required pattern="(\d+:)?\d{1,2}\.\d{2}">
        <div class="form-hint">Ejemplos: 28.50 · 1:05.43 · 4:12.09</div>
      </div>
      <div class="form-group">
        <label class="form-label">Lugar (opcional)</label>
        <input type="text" name="lugar" id="modalLugar" class="form-control" placeholder="Maliaño, Santander...">
      </div>
      <div class="form-group">
        <label class="form-label">Fecha de la marca</label>
        <input type="date" name="data_marca" id="modalData" class="form-control" required>
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

function openForm(prova, temps, data, marcaId, lugar) {
  const isEdit = !!marcaId;
  const provaInput = document.getElementById('modalProva');
  const provaDisplay = document.getElementById('modalProvaDisplay');
  const provaSelect = document.getElementById('modalProvaSelect');
  document.getElementById('modalMarcaId').value = marcaId || 0;
  provaInput.value = prova;
  provaDisplay.value = prova ? formatProvaLabel(prova) : '';
  provaSelect.value = prova || '';
  provaDisplay.style.display = isEdit ? '' : 'none';
  provaSelect.style.display = isEdit ? 'none' : '';
  document.getElementById('modalTemps').value = temps;
  document.getElementById('modalLugar').value = lugar || '';
  document.getElementById('modalData').value = data;
  document.getElementById('modalTitle').textContent = isEdit ? ('Editar marca — ' + prova) : 'Añadir marca';
  const modal = document.getElementById('marcaModal');
  modal.style.display = 'flex';
}
function syncModalProva(value) {
  document.getElementById('modalProva').value = value;
}
function formatProvaLabel(prova) {
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
  return labels[prova] || prova;
}
function openCreateForm() {
  openForm('', '', '<?= date('Y-m-d') ?>', 0, '');
}
function closeForm() {
  document.getElementById('modalMarcaId').value = 0;
  document.getElementById('modalProva').value = '';
  document.getElementById('marcaModal').style.display = 'none';
}
document.getElementById('marcaModal').addEventListener('click', function(e) {
  if (e.target === this) closeForm();
});
</script>
<?php endif; ?>

<?php
});
render_footer();
