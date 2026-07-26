<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_cargo(['presidente', 'secretario', 'tesorero', 'vocal', 'director_tecnico']);

$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear_item') {
        $nombre      = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $precio      = (float)str_replace(',', '.', $_POST['precio'] ?? '0');
        $bajoPedido  = isset($_POST['bajo_pedido']) ? 1 : 0;
        $errores     = [];

        if (!$nombre || $precio <= 0) $errores[] = 'Nombre y precio son obligatorios.';

        $imagenUrl = null;
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $info = @getimagesize($_FILES['imagen']['tmp_name']);
            if (!$info) {
                $errores[] = 'El archivo no es una imagen válida.';
            } elseif (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
                $errores[] = 'Formato no permitido. Usa JPG, PNG, WebP o GIF.';
            } elseif ($_FILES['imagen']['size'] > 8 * 1024 * 1024) {
                $errores[] = 'La imagen no puede superar los 8 MB.';
            } else {
                $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
                $dir = dirname(__DIR__, 2) . '/public/uploads/equipacion/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'equipacion_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $dir . $filename)) {
                    $imagenUrl = '/uploads/equipacion/' . $filename;
                } else {
                    $errores[] = 'Error al guardar la imagen.';
                }
            }
        }

        if ($errores) {
            flash(implode(' ', $errores), 'danger');
        } else {
            $pdo->prepare('INSERT INTO equipacion_items (nombre, descripcion, precio, imagen_url, bajo_pedido, creado_por) VALUES (?,?,?,?,?,?)')
                ->execute([$nombre, $descripcion ?: null, $precio, $imagenUrl, $bajoPedido, $uid]);
            flash('Artículo creado.', 'success');
        }
    } elseif ($action === 'editar_item') {
        $item_id      = (int)($_POST['item_id'] ?? 0);
        $nombre       = trim($_POST['nombre'] ?? '');
        $descripcion  = trim($_POST['descripcion'] ?? '');
        $precio       = (float)str_replace(',', '.', $_POST['precio'] ?? '0');
        $bajoPedido   = isset($_POST['bajo_pedido']) ? 1 : 0;
        $imagenActual = trim($_POST['imagen_url_actual'] ?? '');
        $errores      = [];

        if (!$item_id || !$nombre || $precio <= 0) $errores[] = 'Nombre y precio son obligatorios.';

        $imagenUrl = $imagenActual ?: null;
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $info = @getimagesize($_FILES['imagen']['tmp_name']);
            if (!$info) {
                $errores[] = 'El archivo no es una imagen válida.';
            } elseif (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
                $errores[] = 'Formato no permitido. Usa JPG, PNG, WebP o GIF.';
            } elseif ($_FILES['imagen']['size'] > 8 * 1024 * 1024) {
                $errores[] = 'La imagen no puede superar los 8 MB.';
            } else {
                $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
                $dir = dirname(__DIR__, 2) . '/public/uploads/equipacion/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'equipacion_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $dir . $filename)) {
                    $imagenUrl = '/uploads/equipacion/' . $filename;
                } else {
                    $errores[] = 'Error al guardar la imagen.';
                }
            }
        }

        if ($errores) {
            flash(implode(' ', $errores), 'danger');
        } else {
            $pdo->prepare('UPDATE equipacion_items SET nombre=?, descripcion=?, precio=?, imagen_url=?, bajo_pedido=? WHERE id=?')
                ->execute([$nombre, $descripcion ?: null, $precio, $imagenUrl, $bajoPedido, $item_id]);
            flash('Artículo actualizado.', 'success');
        }
    } elseif ($action === 'toggle_activo') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($item_id) {
            $pdo->prepare('UPDATE equipacion_items SET activo = NOT activo WHERE id=?')->execute([$item_id]);
            flash('Estado del artículo actualizado.', 'success');
        }
    } elseif ($action === 'crear_variante') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $talla   = trim($_POST['talla'] ?? '');
        $stock   = max(0, (int)($_POST['stock'] ?? 0));
        if ($item_id && $talla !== '') {
            try {
                $pdo->prepare('INSERT INTO equipacion_variantes (item_id, talla, stock) VALUES (?,?,?)')
                    ->execute([$item_id, $talla, $stock]);
                flash('Talla añadida.', 'success');
            } catch (PDOException $e) {
                flash('Esa talla ya existe para este artículo.', 'danger');
            }
        }
    } elseif ($action === 'editar_stock') {
        $variante_id = (int)($_POST['variante_id'] ?? 0);
        $stock       = max(0, (int)($_POST['stock'] ?? 0));
        if ($variante_id) {
            $pdo->prepare('UPDATE equipacion_variantes SET stock=? WHERE id=?')->execute([$stock, $variante_id]);
            flash('Stock actualizado.', 'success');
        }
    } elseif ($action === 'eliminar_variante') {
        $variante_id = (int)($_POST['variante_id'] ?? 0);
        if ($variante_id) {
            try {
                $pdo->prepare('DELETE FROM equipacion_variantes WHERE id=?')->execute([$variante_id]);
                flash('Talla eliminada.', 'success');
            } catch (PDOException $e) {
                flash('No se puede eliminar: hay pedidos con esta talla. Desactiva el artículo en su lugar.', 'danger');
            }
        }
    }

    header('Location: /directiva/equipacion');
    exit;
}

$items = $pdo->query('SELECT * FROM equipacion_items ORDER BY nombre')->fetchAll();
$variantesRows = $pdo->query('SELECT * FROM equipacion_variantes ORDER BY talla')->fetchAll();
$variantesPorItem = [];
foreach ($variantesRows as $v) {
    $variantesPorItem[(int)$v['item_id']][] = $v;
}

render_header('Equipación — Catálogo', 'directiva-equipacion');
render_directiva_layout('equipacion', function () use ($items, $variantesPorItem) {
?>
<div class="d-flex justify-between align-center mb-4" style="gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;">Catálogo de equipación</h1>
  <div class="d-flex gap-2">
    <a href="/directiva/equipacion_pedidos" class="btn btn-secondary btn-sm"><i class="bi bi-receipt"></i> Pedidos</a>
    <button class="btn btn-primary" onclick="abrirNuevoItem()">
      <i class="bi bi-plus-circle-fill"></i> Nuevo artículo
    </button>
  </div>
</div>

<?php render_flash(); ?>

<?php foreach ($items as $item): ?>
  <?php $itemJson = json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex justify-between align-center" style="gap:12px;flex-wrap:wrap;">
        <div class="d-flex gap-3" style="align-items:flex-start;">
          <?php if ($item['imagen_url']): ?>
            <img src="<?= e($item['imagen_url']) ?>" alt="<?= e($item['nombre']) ?>" style="width:64px;height:64px;object-fit:cover;border-radius:8px;flex-shrink:0;">
          <?php else: ?>
            <div style="width:64px;height:64px;border-radius:8px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <i class="bi bi-image" style="font-size:22px;color:var(--gray);"></i>
            </div>
          <?php endif; ?>
          <div>
            <h3 style="margin:0;">
              <?= e($item['nombre']) ?>
              <span class="badge <?= $item['activo'] ? 'badge-success' : 'badge-gray' ?>"><?= $item['activo'] ? 'Activo' : 'Inactivo' ?></span>
              <?php if ($item['bajo_pedido']): ?><span class="badge badge-info">Bajo pedido</span><?php endif; ?>
            </h3>
            <?php if ($item['descripcion']): ?><p style="color:var(--gray);margin:4px 0;"><?= e($item['descripcion']) ?></p><?php endif; ?>
          </div>
        </div>
        <div class="d-flex gap-2 align-center">
          <strong><?= number_format((float)$item['precio'], 2, ',', '.') ?> €</strong>
          <button type="button" class="btn btn-sm btn-secondary" data-item='<?= e($itemJson) ?>' onclick="abrirEditarItem(JSON.parse(this.dataset.item))">
            <i class="bi bi-pencil"></i>
          </button>
          <form method="POST" data-confirm="<?= $item['activo'] ? '¿Desactivar este artículo?' : '¿Activar este artículo?' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_activo">
            <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
            <button type="submit" class="btn btn-sm btn-gray"><i class="bi bi-power"></i></button>
          </form>
        </div>
      </div>

      <table class="table" style="margin-top:12px;">
        <thead><tr><th>Talla</th><th>Stock</th><th style="width:160px;text-align:right;">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($variantesPorItem[(int)$item['id']] ?? [] as $v): ?>
            <tr>
              <td><?= e($v['talla']) ?></td>
              <td>
                <?php if ($item['bajo_pedido']): ?>
                  <span style="color:var(--gray);font-style:italic;">Sin límite</span>
                <?php else: ?>
                  <form method="POST" class="d-flex gap-2 align-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="editar_stock">
                    <input type="hidden" name="variante_id" value="<?= (int)$v['id'] ?>">
                    <input type="number" name="stock" value="<?= (int)$v['stock'] ?>" min="0" class="form-control" style="width:90px;">
                    <button type="submit" class="btn btn-sm btn-secondary">Guardar</button>
                  </form>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <form method="POST" data-confirm="¿Eliminar esta talla?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="eliminar_variante">
                  <input type="hidden" name="variante_id" value="<?= (int)$v['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <form method="POST" class="d-flex gap-2" style="margin-top:8px;flex-wrap:wrap;align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="crear_variante">
        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Nueva talla</label>
          <input type="text" name="talla" class="form-control" style="width:100px;" required>
        </div>
        <?php if (!$item['bajo_pedido']): ?>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Stock inicial</label>
            <input type="number" name="stock" class="form-control" style="width:100px;" value="0" min="0">
          </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus"></i> Añadir talla</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<!-- Modal artículo (nuevo / editar) -->
<div id="modalItem" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:24px;max-width:480px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 id="tituloModalItem" style="margin-top:0;">Nuevo artículo</h3>
    <form method="POST" id="formItem" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="itemAction" value="crear_item">
      <input type="hidden" name="item_id" id="itemId">
      <input type="hidden" name="imagen_url_actual" id="itemImagenActual">
      <div class="form-group">
        <label class="form-label">Nombre</label>
        <input type="text" name="nombre" id="itemNombre" class="form-control" maxlength="150" required>
      </div>
      <div class="form-group">
        <label class="form-label">Descripción</label>
        <textarea name="descripcion" id="itemDescripcion" class="form-control" rows="3"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Precio (€)</label>
        <input type="number" step="0.01" min="0.01" name="precio" id="itemPrecio" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">Foto</label>
        <img id="itemImagenPreview" src="" alt="" style="display:none;max-width:120px;border-radius:8px;margin-bottom:8px;">
        <input type="file" name="imagen" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
      </div>
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px;font-weight:400;">
          <input type="checkbox" name="bajo_pedido" id="itemBajoPedido" value="1" style="width:auto;">
          Bajo pedido (sin límite de stock)
        </label>
      </div>
      <div class="d-flex gap-2" style="justify-content:flex-end;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modalItem').style.display='none'">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirNuevoItem() {
  document.getElementById('formItem').reset();
  document.getElementById('tituloModalItem').textContent = 'Nuevo artículo';
  document.getElementById('itemAction').value = 'crear_item';
  document.getElementById('itemId').value = '';
  document.getElementById('itemImagenActual').value = '';
  document.getElementById('itemImagenPreview').style.display = 'none';
  document.getElementById('modalItem').style.display = 'flex';
}

function abrirEditarItem(item) {
  document.getElementById('formItem').reset();
  document.getElementById('tituloModalItem').textContent = 'Editar artículo';
  document.getElementById('itemAction').value = 'editar_item';
  document.getElementById('itemId').value = item.id;
  document.getElementById('itemNombre').value = item.nombre;
  document.getElementById('itemDescripcion').value = item.descripcion || '';
  document.getElementById('itemPrecio').value = item.precio;
  document.getElementById('itemImagenActual').value = item.imagen_url || '';
  document.getElementById('itemBajoPedido').checked = !!parseInt(item.bajo_pedido, 10);
  const preview = document.getElementById('itemImagenPreview');
  if (item.imagen_url) {
    preview.src = item.imagen_url;
    preview.style.display = 'block';
  } else {
    preview.style.display = 'none';
  }
  document.getElementById('modalItem').style.display = 'flex';
}
</script>
<?php
});
render_footer();
