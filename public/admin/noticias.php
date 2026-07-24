<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin_area(['director_tecnico']);

$mode    = $_GET['mode'] ?? 'list';
$edit_id = (int)($_GET['id'] ?? 0);
$errors  = [];
$data    = ['titulo' => '', 'resumen' => '', 'contenido' => '', 'imagen_url' => '', 'publicado' => 0];

// --- POST: crear / actualizar / eliminar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['noticia_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM noticias WHERE id=?')->execute([$id]);
            flash('Noticia eliminada.', 'warning');
        }
        header('Location: /admin/noticias');
        exit;
    }

    if (in_array($action, ['create', 'update'])) {
        $data['titulo']     = trim($_POST['titulo'] ?? '');
        $data['resumen']    = trim($_POST['resumen'] ?? '');
        $data['contenido']  = trim($_POST['contenido'] ?? '');
        $data['imagen_url'] = trim($_POST['imagen_url'] ?? ''); // valor actual (para update)
        $data['publicado']  = isset($_POST['publicado']) ? 1 : 0;

        if (!$data['titulo']) $errors[] = 'El título es obligatorio.';

        // Procesar upload de imagen
        $nueva_imagen = null; // null = no cambia
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $info = @getimagesize($_FILES['imagen']['tmp_name']);
            if (!$info) {
                $errors[] = 'El archivo no es una imagen válida.';
            } elseif (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
                $errors[] = 'Formato no permitido. Usa JPG, PNG o WebP.';
            } elseif ($_FILES['imagen']['size'] > 8 * 1024 * 1024) {
                $errors[] = 'La imagen no puede superar los 8 MB.';
            } else {
                $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
                $dir = dirname(__DIR__, 2) . '/public/uploads/noticias/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'noticia_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $dir . $filename)) {
                    $nueva_imagen = '/uploads/noticias/' . $filename;
                } else {
                    $errors[] = 'Error al guardar la imagen.';
                }
            }
        } elseif (isset($_FILES['imagen']) && $_FILES['imagen']['error'] !== UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Error al subir el archivo (código ' . $_FILES['imagen']['error'] . ').';
        }

        if (!$errors) {
            $imagen_final = $nueva_imagen ?? $data['imagen_url'];
            if ($action === 'create') {
                $pdo->prepare('INSERT INTO noticias (titulo, resumen, contenido, imagen_url, publicado) VALUES (?,?,?,?,?)')
                    ->execute([$data['titulo'], $data['resumen'], $data['contenido'], $imagen_final, $data['publicado']]);
                flash('Noticia creada correctamente.', 'success');
            } else {
                $id = (int)($_POST['noticia_id'] ?? 0);
                $pdo->prepare('UPDATE noticias SET titulo=?, resumen=?, contenido=?, imagen_url=?, publicado=?, updated_at=NOW() WHERE id=?')
                    ->execute([$data['titulo'], $data['resumen'], $data['contenido'], $imagen_final, $data['publicado'], $id]);
                flash('Noticia actualizada.', 'success');
            }
            header('Location: /admin/noticias');
            exit;
        }
    }
}

// Cargar noticia para editar
$editNoticia = null;
if ($mode === 'edit' && $edit_id) {
    $stmt = $pdo->prepare('SELECT * FROM noticias WHERE id=?');
    $stmt->execute([$edit_id]);
    $editNoticia = $stmt->fetch();
    if ($editNoticia) $data = $editNoticia;
}

// Lista de noticias
$noticias = $pdo->query('SELECT * FROM noticias ORDER BY created_at DESC')->fetchAll();

$extraHead = ($mode === 'new' || $mode === 'edit')
    ? '<link href="/assets/css/quill.snow.css" rel="stylesheet">'
    : '';
render_header('Noticias — Admin', 'admin-noticias', $extraHead);
render_admin_layout('noticias', function() use ($mode, $edit_id, $noticias, $data, $errors, $editNoticia) {
?>

<div class="d-flex justify-between align-center mb-6">
  <h1 style="margin:0;">Noticias</h1>
  <?php if ($mode !== 'new' && $mode !== 'edit'): ?>
    <a href="?mode=new" class="btn btn-primary">+ Nueva noticia</a>
  <?php else: ?>
    <a href="/admin/noticias" class="btn btn-gray">← Volver al listado</a>
  <?php endif; ?>
</div>

<?php render_flash(); ?>

<!-- Formulario crear / editar -->
<?php if ($mode === 'new' || $mode === 'edit'): ?>
<div class="card mb-6">
  <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;">
    <?= $mode === 'edit' ? 'Editar noticia' : 'Nueva noticia' ?>
  </h2>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $mode === 'edit' ? 'update' : 'create' ?>">
    <?php if ($mode === 'edit'): ?>
      <input type="hidden" name="noticia_id" value="<?= $edit_id ?>">
    <?php endif; ?>

    <div class="form-group">
      <label class="form-label">Título *</label>
      <input type="text" name="titulo" class="form-control" value="<?= e($data['titulo']) ?>" required autofocus>
    </div>
    <div class="form-group">
      <label class="form-label">Resumen <span class="text-muted">(aparece en el listado)</span></label>
      <textarea name="resumen" class="form-control" style="min-height:70px;"><?= e($data['resumen']) ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Contenido</label>
      <div id="quill-editor" style="min-height:200px;background:white;border-radius:var(--radius);"></div>
      <input type="hidden" name="contenido" id="contenido-input" value="<?= e($data['contenido']) ?>">
    </div>
    <div class="form-group">
      <label class="form-label">Imagen</label>
      <?php if (!empty($data['imagen_url'])): ?>
        <div style="margin-bottom:10px;">
          <img src="<?= e($data['imagen_url']) ?>" alt="Imagen actual"
               style="max-height:120px;max-width:300px;border-radius:8px;border:1px solid #e8e8e8;object-fit:cover;">
          <div class="text-muted text-sm" style="margin-top:4px;">Imagen actual. Sube una nueva para reemplazarla.</div>
        </div>
      <?php endif; ?>
      <input type="hidden" name="imagen_url" value="<?= e($data['imagen_url']) ?>">
      <input type="file" name="imagen" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif"
             onchange="previewNoticia(this)">
      <div class="text-muted text-sm" style="margin-top:4px;">JPG, PNG o WebP · Máx. 8 MB</div>
      <img id="noticia-preview" src="" alt="" style="display:none;max-height:120px;max-width:300px;border-radius:8px;margin-top:10px;object-fit:cover;">
    </div>
    <div class="form-group">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
        <input type="checkbox" name="publicado" value="1" <?= $data['publicado'] ? 'checked' : '' ?> style="width:18px;height:18px;">
        <span class="form-label" style="margin:0;">Publicada (visible en la web)</span>
      </label>
    </div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary">Guardar</button>
      <a href="/admin/noticias" class="btn btn-gray">Cancelar</a>
    </div>
  </form>
</div>

<script src="/assets/js/quill.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var quill = new Quill('#quill-editor', {
    theme: 'snow',
    modules: {
      toolbar: [
        [{ header: [2, 3, false] }],
        ['bold', 'italic', 'underline'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link', 'blockquote'],
        ['clean']
      ]
    }
  });

  var hiddenInput = document.getElementById('contenido-input');
  if (hiddenInput.value) {
    quill.root.innerHTML = hiddenInput.value;
  }

  document.querySelector('form').addEventListener('submit', function () {
    hiddenInput.value = quill.root.innerHTML;
  });
});

function previewNoticia(input) {
  const preview = document.getElementById('noticia-preview');
  if (!input.files || !input.files[0]) { preview.style.display = 'none'; return; }
  const reader = new FileReader();
  reader.onload = function(e) {
    preview.src = e.target.result;
    preview.style.display = 'block';
  };
  reader.readAsDataURL(input.files[0]);
}
</script>
<style>
.ql-toolbar { border-radius: var(--radius) var(--radius) 0 0 !important; border-color: #e8e8e8 !important; }
.ql-container { border-radius: 0 0 var(--radius) var(--radius) !important; border-color: #e8e8e8 !important; font-family: inherit !important; font-size: 15px !important; }
.ql-editor { min-height: 200px; }
</style>

<?php endif; ?>

<!-- Listado -->
<div class="table-card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Título</th>
          <th>Estado</th>
          <th>Fecha</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$noticias): ?>
          <tr><td colspan="4" class="text-center text-muted" style="padding:32px;">No hay noticias.</td></tr>
        <?php endif; ?>
        <?php foreach ($noticias as $n): ?>
        <tr>
          <td>
            <strong><?= e($n['titulo']) ?></strong>
            <?php if ($n['resumen']): ?>
              <div class="text-muted text-sm" style="margin-top:2px;"><?= e(mb_strimwidth($n['resumen'], 0, 80, '…')) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($n['publicado']): ?>
              <span class="badge badge-success">Publicada</span>
            <?php else: ?>
              <span class="badge badge-gray">Borrador</span>
            <?php endif; ?>
          </td>
          <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($n['created_at'])) ?></td>
          <td>
            <div class="d-flex gap-2">
              <a href="?mode=edit&id=<?= $n['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
              <a href="/noticias/detall?id=<?= $n['id'] ?>" target="_blank" class="btn btn-gray btn-sm">Ver</a>
              <form method="POST" style="display:inline;"
                    data-confirm="¿Eliminar esta noticia? Esta acción no se puede deshacer.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="noticia_id" value="<?= $n['id'] ?>">
                <button class="btn btn-danger btn-sm">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
});
render_footer();
