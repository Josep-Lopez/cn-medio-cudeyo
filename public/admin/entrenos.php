<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/grupos.php';
require_once dirname(__DIR__, 2) . '/includes/entrenos.php';

require_admin_area(['director_tecnico', 'entrenador']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si el POST supera post_max_size PHP vacía $_POST y $_FILES (y el token CSRF con ellos)
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('Los archivos superan el tamaño máximo permitido. Sube menos archivos a la vez.', 'danger');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin/entrenos'));
        exit;
    }
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        try {
            $id = crear_entreno($pdo, $_POST['titulo'] ?? '', $_POST['fecha'] ?? '', $_POST['descripcion'] ?? null,
                (array)($_POST['grupos'] ?? []), (int)current_user()['id']);
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
            header('Location: /admin/entrenos?accion=nuevo');
            exit;
        }
        $errores = subir_archivos_entreno($pdo, $id, $_FILES['archivos'] ?? []);
        if ($errores) {
            flash('Entreno publicado, pero algunos archivos no se subieron: ' . implode(' · ', $errores), 'warning');
        } else {
            flash('Entreno publicado.', 'success');
        }
        header('Location: /admin/entrenos?ver=' . $id);
        exit;
    }

    if ($accion === 'editar') {
        $id = (int)$_POST['id'];
        try {
            actualizar_entreno($pdo, $id, $_POST['titulo'] ?? '', $_POST['fecha'] ?? '', $_POST['descripcion'] ?? null,
                (array)($_POST['grupos'] ?? []));
            $errores = subir_archivos_entreno($pdo, $id, $_FILES['archivos'] ?? []);
            if ($errores) {
                flash('Cambios guardados, pero algunos archivos no se subieron: ' . implode(' · ', $errores), 'warning');
            } else {
                flash('Entreno actualizado.', 'success');
            }
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
        }
        header('Location: /admin/entrenos?ver=' . $id);
        exit;
    }

    if ($accion === 'eliminar_archivo') {
        $id = (int)$_POST['id'];
        $a = obtener_archivo_entreno($pdo, (int)$_POST['archivo_id']);
        if ($a && (int)$a['entreno_id'] === $id) {
            eliminar_archivo_entreno($pdo, (int)$a['id']);
            flash('Archivo eliminado.', 'success');
        }
        header('Location: /admin/entrenos?ver=' . $id);
        exit;
    }

    if ($accion === 'eliminar') {
        eliminar_entreno($pdo, (int)$_POST['id']);
        flash('Entreno eliminado.', 'success');
        header('Location: /admin/entrenos');
        exit;
    }
}

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$filtroGrupo = (int)($_GET['grupo'] ?? 0);
$grupos = listar_grupos($pdo);

render_header('Entrenos', 'admin-entrenos');
render_admin_layout('entrenos', function() use ($accion, $verId, $filtroGrupo, $grupos, $pdo) {

    // Formulario común a nuevo/editar
    $form = function(?array $entreno, array $seleccionados) use ($grupos) {
?>
  <?= csrf_field() ?>
  <div class="form-group">
    <label class="form-label">Título *</label>
    <input type="text" name="titulo" class="form-control" required maxlength="150"
           value="<?= e($entreno['titulo'] ?? '') ?>" placeholder="Ej.: Series aeróbicas 4x400">
  </div>
  <div class="form-group">
    <label class="form-label">Fecha del entreno *</label>
    <input type="date" name="fecha" class="form-control" required style="max-width:200px;"
           value="<?= e($entreno['fecha'] ?? date('Y-m-d')) ?>">
  </div>
  <div class="form-group">
    <label class="form-label">Grupos *</label>
    <?php if (!$grupos): ?>
      <p class="text-muted">No hay grupos de entrenamiento. <a href="/admin/grupos?accion=nuevo">Crea uno primero</a>.</p>
    <?php else: ?>
      <div class="entreno-grupos">
        <?php foreach ($grupos as $g): ?>
          <label class="entreno-grupo">
            <input type="checkbox" name="grupos[]" value="<?= (int)$g['id'] ?>" <?= in_array((int)$g['id'], $seleccionados, true) ? 'checked' : '' ?>>
            <span><?= e($g['nombre']) ?></span>
            <span class="entreno-grupo-num"><?= (int)$g['num_nadadores'] ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="form-group">
    <label class="form-label">Descripción</label>
    <textarea name="descripcion" class="form-control" rows="6" placeholder="Calentamiento, series, vuelta a la calma…"><?= e($entreno['descripcion'] ?? '') ?></textarea>
  </div>
  <div class="form-group">
    <label class="form-label">Añadir fotos o PDF</label>
    <input type="file" name="archivos[]" id="entreno-archivos" class="form-control" multiple
           accept="image/jpeg,image/png,image/webp,application/pdf">
    <p class="text-muted text-sm" style="margin-top:6px;">
      JPG, PNG, WEBP o PDF · máx. <?= ENTRENO_MAX_ARCHIVOS ?> archivos por entreno · las fotos se reducen automáticamente antes de subir.
    </p>
    <p id="entreno-archivos-estado" class="text-sm" style="display:none;"></p>
  </div>
<?php
    };

    if ($accion === 'nuevo') {
?>
<h1>Nuevo entreno</h1>
<?php render_flash(); ?>
<form method="POST" action="/admin/entrenos" enctype="multipart/form-data" class="card entreno-form" style="padding:24px;max-width:720px;">
  <input type="hidden" name="accion" value="crear">
  <?php $form(null, $filtroGrupo ? [$filtroGrupo] : []); ?>
  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary">Publicar</button>
    <a href="/admin/entrenos" class="btn btn-gray">Cancelar</a>
  </div>
</form>
<?php render_script_reducir_fotos(); ?>
<?php
        return;
    }

    if ($verId > 0) {
        $entreno = obtener_entreno($pdo, $verId);
        if (!$entreno) {
            echo '<div class="card" style="padding:24px;">Entreno no encontrado. <a href="/admin/entrenos">Volver</a></div>';
            return;
        }
        $archivos = archivos_de_entrenos($pdo, [$verId])[$verId] ?? [];
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;"><?= e($entreno['titulo']) ?></h1>
  <a href="/admin/entrenos" class="btn btn-gray btn-sm">← Volver al listado</a>
</div>
<?php render_flash(); ?>

<?php if ($archivos): ?>
<div class="card mb-6" style="padding:24px;">
  <h3>Archivos (<?= count($archivos) ?>)</h3>
  <?php render_archivos_entreno($archivos, true, $verId); ?>
</div>
<?php endif; ?>

<form method="POST" action="/admin/entrenos" enctype="multipart/form-data" class="card mb-6 entreno-form" style="padding:24px;">
  <input type="hidden" name="accion" value="editar">
  <input type="hidden" name="id" value="<?= (int)$entreno['id'] ?>">
  <?php $form($entreno, grupos_de_entreno($pdo, $verId)); ?>
  <button type="submit" class="btn btn-primary btn-sm">Guardar cambios</button>
</form>

<div style="text-align:right;">
  <button type="button" class="btn btn-sm" onclick="document.getElementById('modal-eliminar-entreno').style.display='flex'" style="background:#dc2626;color:white;">
    <i class="bi bi-trash"></i> Eliminar entreno
  </button>
</div>

<div id="modal-eliminar-entreno" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:8px;max-width:420px;width:90%;">
    <h3 style="margin:0 0 12px;">¿Eliminar entreno?</h3>
    <p>Se borrarán también sus fotos y PDF. Esta acción no se puede deshacer.</p>
    <form method="POST" action="/admin/entrenos">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" value="<?= (int)$entreno['id'] ?>">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modal-eliminar-entreno').style.display='none'">Cancelar</button>
        <button type="submit" class="btn" style="background:#dc2626;color:white;">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<div id="modal-eliminar-archivo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:8px;max-width:420px;width:90%;">
    <h3 style="margin:0 0 12px;">¿Eliminar archivo?</h3>
    <p id="modal-eliminar-archivo-nombre" style="word-break:break-all;"></p>
    <form method="POST" action="/admin/entrenos">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar_archivo">
      <input type="hidden" name="id" value="<?= (int)$entreno['id'] ?>">
      <input type="hidden" name="archivo_id" id="modal-eliminar-archivo-id">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modal-eliminar-archivo').style.display='none'">Cancelar</button>
        <button type="submit" class="btn" style="background:#dc2626;color:white;">Eliminar</button>
      </div>
    </form>
  </div>
</div>
<script>
function confirmarBorrarArchivo(id, nombre) {
  document.getElementById('modal-eliminar-archivo-id').value = id;
  document.getElementById('modal-eliminar-archivo-nombre').textContent = nombre;
  document.getElementById('modal-eliminar-archivo').style.display = 'flex';
}
</script>
<?php render_visor_fotos_entreno(); ?>
<?php render_script_reducir_fotos(); ?>
<?php
        return;
    }

    $entrenos = listar_entrenos($pdo, $filtroGrupo);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;">Entrenos</h1>
  <a href="/admin/entrenos?accion=nuevo<?= $filtroGrupo ? '&grupo=' . $filtroGrupo : '' ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo entreno</a>
</div>
<?php render_flash(); ?>

<?php if ($grupos): ?>
<form method="GET" class="mb-4">
  <select name="grupo" class="form-control" style="width:auto;" onchange="this.form.submit()">
    <option value="0">Todos los grupos</option>
    <?php foreach ($grupos as $g): ?>
      <option value="<?= (int)$g['id'] ?>" <?= $filtroGrupo === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
</form>
<?php endif; ?>

<?php if (!$entrenos): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay entrenos publicados todavía.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Fecha</th><th>Título</th><th>Grupos</th><th>Archivos</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($entrenos as $en): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($en['fecha'])) ?></td>
              <td style="font-weight:600;"><?= e($en['titulo']) ?></td>
              <td class="text-muted"><?= e($en['grupos'] ?? '— sin grupo —') ?></td>
              <td><?= (int)$en['num_archivos'] ?: '—' ?></td>
              <td><a href="/admin/entrenos?ver=<?= (int)$en['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
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

// Reduce las fotos en el navegador (máx. 1920 px, JPEG) antes de enviarlas: las del móvil pesan mucho.
function render_script_reducir_fotos(): void
{
?>
<script>
(function () {
  var input = document.getElementById('entreno-archivos');
  if (!input || typeof DataTransfer === 'undefined') return;
  var form = input.form;
  var estado = document.getElementById('entreno-archivos-estado');
  var procesando = null;
  var MAX = 1920;

  function reducir(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024) return Promise.resolve(file);
    return new Promise(function (resolve) {
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var esc = Math.min(1, MAX / Math.max(img.width, img.height));
        var c = document.createElement('canvas');
        c.width = Math.round(img.width * esc);
        c.height = Math.round(img.height * esc);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) return resolve(file);
          resolve(new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(input.files);
    if (!files.length) { estado.style.display = 'none'; return; }
    estado.style.display = '';
    estado.textContent = 'Preparando ' + files.length + ' archivo(s)…';
    procesando = Promise.all(files.map(reducir)).then(function (res) {
      var dt = new DataTransfer();
      var total = 0;
      res.forEach(function (f) { dt.items.add(f); total += f.size; });
      input.files = dt.files;
      estado.textContent = res.length + ' archivo(s) listos · ' + (total / 1048576).toFixed(1) + ' MB';
      procesando = null;
    });
  });

  form.addEventListener('submit', function (e) {
    if (!procesando) return;
    e.preventDefault();
    procesando.then(function () { form.submit(); });
  });
})();
</script>
<?php
}
