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
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $prova     = $_POST['prova'] ?? '';
        $piscina   = $_POST['piscina'] ?? '25m';
        $temps     = trim($_POST['temps'] ?? '');
        $data_m    = $_POST['data_marca'] ?? date('Y-m-d');
        $temporada = $_POST['temporada'] ?? '2025-26';

        if ($user_id && in_array($prova, $PROVES) && in_array($piscina, ['25m','50m']) && $temps) {
            $secs = temps_a_segons($temps);
            if ($secs > 0) {
                $stmt = $pdo->prepare('
                    INSERT INTO marques (user_id, prova, piscina, temps, temps_seg, data_marca, temporada)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE temps=VALUES(temps), temps_seg=VALUES(temps_seg), data_marca=VALUES(data_marca), updated_at=NOW()
                ');
                $stmt->execute([$user_id, $prova, $piscina, $temps, $secs, $data_m, $temporada]);
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
    $back = http_build_query(array_filter(['lliga' => $_POST['lliga_back'] ?? '', 'user_id' => $_POST['user_id_back'] ?? '']));
    header('Location: /admin/marques?' . $back);
    exit;
}

// --- Parámetros GET ---
$selectedLliga  = $_GET['lliga']   ?? '';
$selectedUserId = (int)($_GET['user_id'] ?? 0);
$selectedPiscina = $_GET['piscina'] ?? '25m';
$temporada = '2025-26';

// Carga nadadores de la liga seleccionada
$nadadors = [];
if ($selectedLliga && in_array($selectedLliga, ['benjamin','alevin','infantil','junior','master'])) {
    $stmt = $pdo->prepare('SELECT id, nom FROM users WHERE lliga=? AND estado=\'activo\' AND rol=\'soci\' ORDER BY nom');
    $stmt->execute([$selectedLliga]);
    $nadadors = $stmt->fetchAll();
}

// Carga marcas del nadador seleccionado
$marcas_usuario = [];
$selected_user = null;
if ($selectedUserId) {
    $stmt = $pdo->prepare('SELECT nom, lliga FROM users WHERE id=?');
    $stmt->execute([$selectedUserId]);
    $selected_user = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT * FROM marques WHERE user_id=? AND temporada=? ORDER BY prova, piscina');
    $stmt->execute([$selectedUserId, $temporada]);
    $all_marks = $stmt->fetchAll();
    // Indexar por prova+piscina para acceso rápido
    foreach ($all_marks as $m) {
        $marcas_usuario[$m['prova'] . '_' . $m['piscina']] = $m;
    }
}

render_header('Gestión de marcas', 'admin-marques');
render_admin_layout('marques', function() use ($PROVES, $selectedLliga, $selectedUserId, $selectedPiscina, $nadadors, $marcas_usuario, $selected_user, $temporada) {
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

<!-- Paso 3: Editar marcas del nadador -->
<?php if ($selectedUserId && $selected_user): ?>
<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title"><?= e($selected_user['nom']) ?></h2>
      <span class="text-muted text-sm"><?= e(format_lliga($selected_user['lliga'] ?? '')) ?> · Temporada <?= e($temporada) ?> · Piscina <?= e($selectedPiscina) ?></span>
    </div>
    <a href="/admin/ranking?lliga=<?= e($selectedLliga) ?>" class="btn btn-secondary btn-sm">Ver ranking</a>
  </div>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Prueba</th>
          <th>Tiempo (<?= e($selectedPiscina) ?>)</th>
          <th>Fecha</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($PROVES as $prova):
          $key   = $prova . '_' . $selectedPiscina;
          $marca = $marcas_usuario[$key] ?? null;
        ?>
        <tr>
          <td><strong><?= e(format_prova($prova)) ?></strong></td>
          <td>
            <?php if ($marca): ?>
              <span class="mark-time"><?= e($marca['temps']) ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-sm text-muted">
            <?= $marca ? date('d/m/Y', strtotime($marca['data_marca'])) : '—' ?>
          </td>
          <td>
            <button class="btn btn-secondary btn-sm"
                    onclick="openForm('<?= e($prova) ?>', '<?= $marca ? e($marca['temps']) : '' ?>', '<?= $marca ? e($marca['data_marca']) : date('Y-m-d') ?>', <?= $marca ? $marca['id'] : 0 ?>)">
              <?= $marca ? 'Editar' : 'Añadir' ?>
            </button>
            <?php if ($marca): ?>
              <form method="POST" style="display:inline;"
                    onsubmit="return confirm('¿Eliminar esta marca?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action"    value="delete">
                <input type="hidden" name="marca_id"  value="<?= $marca['id'] ?>">
                <input type="hidden" name="lliga_back" value="<?= e($selectedLliga) ?>">
                <input type="hidden" name="user_id_back" value="<?= $selectedUserId ?>">
                <button class="btn btn-danger btn-sm">🗑</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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
      <input type="hidden" name="temporada"   value="<?= e($temporada) ?>">
      <input type="hidden" name="lliga_back"  value="<?= e($selectedLliga) ?>">
      <input type="hidden" name="user_id_back" value="<?= $selectedUserId ?>">
      <input type="hidden" name="prova" id="modalProva">

      <div class="form-group">
        <label class="form-label">Prueba</label>
        <input type="text" id="modalProvaDisplay" class="form-control" readonly style="background:#f5f5f5;">
      </div>
      <div class="form-group">
        <label class="form-label">Tiempo (mm:ss.cc o ss.cc)</label>
        <input type="text" name="temps" id="modalTemps" class="form-control"
               placeholder="1:23.45" required pattern="(\d+:)?\d{1,2}\.\d{2}">
        <div class="form-hint">Ejemplos: 28.50 · 1:05.43 · 4:12.09</div>
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
function openForm(prova, temps, data, marcaId) {
  document.getElementById('modalProva').value = prova;
  document.getElementById('modalProvaDisplay').value = prova;
  document.getElementById('modalTemps').value = temps;
  document.getElementById('modalData').value = data;
  document.getElementById('modalTitle').textContent = (marcaId ? 'Editar' : 'Añadir') + ' marca — ' + prova;
  const modal = document.getElementById('marcaModal');
  modal.style.display = 'flex';
}
function closeForm() {
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
