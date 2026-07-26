# Equipación — foto de producto + artículos bajo pedido Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a photo field to equipment catalog items, and a "bajo pedido" (on-request, no fixed stock) flag at the item level, extending the already-merged equipación module on `main`.

**Architecture:** Two new nullable/default-0 columns on `equipacion_items` (`imagen_url`, `bajo_pedido`). Photo upload reuses the exact validation/storage pattern already used by `public/admin/noticias.php` (own `public/uploads/equipacion/` directory, PHP-execution-denying `.htaccess`). `bajo_pedido` is read by the two shared stock helpers (`equipacion_reservar_stock()`, `equipacion_reponer_stock()` in `includes/equipacion.php`) so every consumer (checkout, webhook, both cancel flows) automatically skips stock math for those items with no per-page changes needed elsewhere — only the socio catalog page's own stock *check* (before calling the shared reserve function) and the directiva catalog UI need direct edits.

**Tech Stack:** PHP 8.4 + PDO (existing), MySQL 8 (existing), no new dependencies.

## Global Constraints

- No automated test suite exists — every task's "test" step is manual: `php -l`, direct `docker compose exec` SQL queries, and real `curl` HTTP requests with a disposable test user (never the real admin account — its documented password is stale in this environment).
- Follow `dirname(__DIR__, 2)` include convention for `public/directiva/*.php` and `public/socio/*.php`.
- All POST forms use `csrf_field()`/`csrf_verify()`; all dynamic output uses `e()`; one-shot messages use `flash()`/`render_flash()`.
- Only these badge CSS classes exist in `public/assets/css/main.css`: `badge-success`, `badge-danger`, `badge-warning`, `badge-info`, `badge-gray`, `badge-blue`.
- `bajo_pedido` is a per-**item** flag (not per-talla/variant) — every variant of a `bajo_pedido=1` item is unlimited.
- When `bajo_pedido=1`: no stock check blocks add-to-cart or checkout, no stock is decremented on reservation, no stock is incremented on cancel/expiry, no special "bajo pedido" label is shown to the socio (per spec, explicitly out of scope).
- Migrations in this repo are applied manually to the running dev DB via the `db` Docker service and `migrations/` is gitignored except a few historically-tracked files — new migration files must be force-added (`git add -f`) to be tracked, matching the existing `migrations/019_equipacion.sql` precedent.
- Image upload validation must match `public/admin/noticias.php`'s exact pattern: `getimagesize()` check, mime whitelist `['image/jpeg','image/png','image/webp','image/gif']`, 8 MB max, random filename via `uniqid()`, stored under `public/uploads/<feature>/`, directory created on first use via `mkdir($dir, 0755, true)` if missing.

---

### Task 1: Migración — columnas imagen_url y bajo_pedido

**Files:**
- Create: `migrations/020_equipacion_foto_bajo_pedido.sql`

**Interfaces:**
- Produces: `equipacion_items.imagen_url` (VARCHAR(255) NULL), `equipacion_items.bajo_pedido` (TINYINT(1) NOT NULL DEFAULT 0) — consumed by Tasks 2-5.

- [ ] **Step 1: Write the migration file**

```sql
-- 020: Equipación — foto de producto + artículos bajo pedido

ALTER TABLE equipacion_items
  ADD COLUMN imagen_url VARCHAR(255) NULL AFTER descripcion,
  ADD COLUMN bajo_pedido TINYINT(1) NOT NULL DEFAULT 0 AFTER precio;
```

- [ ] **Step 2: Apply the migration to the running dev DB**

Run: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo < migrations/020_equipacion_foto_bajo_pedido.sql`
Expected: no output (success).

- [ ] **Step 3: Verify the columns exist with correct types/defaults**

Run: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "DESCRIBE equipacion_items;"`
Expected: rows for `imagen_url` (`varchar(255)`, `YES` nullable, default `NULL`) and `bajo_pedido` (`tinyint(1)`, `NO` not nullable, default `0`), positioned after `descripcion` and `precio` respectively.

- [ ] **Step 4: Verify existing rows default correctly**

Run: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SELECT COUNT(*) AS total, SUM(bajo_pedido) AS con_bajo_pedido FROM equipacion_items;"`
Expected: `con_bajo_pedido` is `0` or `NULL` (no existing rows should have `bajo_pedido=1` — the column just landed with `DEFAULT 0`).

- [ ] **Step 5: Commit**

```bash
git add -f migrations/020_equipacion_foto_bajo_pedido.sql
git commit -m "feat(db): añadir imagen_url y bajo_pedido a equipacion_items"
```

---

### Task 2: Directorio de subida de fotos

**Files:**
- Create: `public/uploads/equipacion/.htaccess`
- Modify: `.gitignore`

**Interfaces:**
- Produces: a git-tracked, empty (except `.htaccess`) upload directory at `public/uploads/equipacion/` that Task 4's upload code writes into at runtime — matches the `public/uploads/noticias/` precedent exactly.

- [ ] **Step 1: Create the .htaccess file**

```
php_flag engine off
Options -Indexes
```

Write this exact content to `public/uploads/equipacion/.htaccess` (create the `public/uploads/equipacion/` directory if it doesn't exist yet).

- [ ] **Step 2: Add the gitignore rule pair**

Modify `.gitignore` — in the "Archivos subidos por los usuarios" section, after the `public/uploads/incidencias/*` / `!public/uploads/incidencias/.htaccess` pair, add:

```
public/uploads/equipacion/*
!public/uploads/equipacion/.htaccess
```

- [ ] **Step 3: Verify git tracks only the .htaccess**

Run: `git status --short public/uploads/equipacion/`
Expected: shows `public/uploads/equipacion/.htaccess` as a new untracked file (about to be added), and nothing else — confirming the gitignore pattern is already active before any real upload happens.

- [ ] **Step 4: Verify the directory denies PHP execution (mirrors existing uploads dirs)**

Run: `docker compose exec -T app cat /var/www/html/public/uploads/equipacion/.htaccess`
Expected: prints the two lines from Step 1.

- [ ] **Step 5: Commit**

```bash
git add public/uploads/equipacion/.htaccess .gitignore
git commit -m "feat(equipacion): directorio de subida de fotos de producto"
```

---

### Task 3: Helpers de stock — soporte bajo_pedido

**Files:**
- Modify: `includes/equipacion.php:62-82` (functions `equipacion_reservar_stock` and `equipacion_reponer_stock`)

**Interfaces:**
- Consumes: `equipacion_items.bajo_pedido` column (Task 1).
- Produces: same signatures as before — `equipacion_reservar_stock(PDO $pdo, array $carrito): int` (0 on success, or failing variante_id), `equipacion_reponer_stock(PDO $pdo, int $pedido_id): void` — behavior only changes internally. Every existing caller (`public/stripe_checkout.php`, `public/stripe_webhook.php`, `public/socio/equipacion_pedidos.php`, `public/directiva/equipacion_pedidos.php`) needs no changes and automatically gets bajo_pedido-aware behavior.

- [ ] **Step 1: Replace equipacion_reservar_stock**

In `includes/equipacion.php`, replace the existing `equipacion_reservar_stock` function (currently lines 62-72) with:

```php
// Reserva stock atómicamente. Devuelve 0 si todo OK, o el variante_id que
// falló por falta de stock (el caller debe hacer ROLLBACK de la transacción).
// Los artículos "bajo pedido" (equipacion_items.bajo_pedido=1) no tienen
// límite de stock: sus líneas se consideran siempre reservadas con éxito.
function equipacion_reservar_stock(PDO $pdo, array $carrito): int
{
    foreach ($carrito as $variante_id => $cantidad) {
        $chk = $pdo->prepare(
            'SELECT i.bajo_pedido FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id WHERE v.id = ?'
        );
        $chk->execute([$variante_id]);
        if ((int)$chk->fetchColumn() === 1) continue;

        $stmt = $pdo->prepare(
            'UPDATE equipacion_variantes SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([$cantidad, $variante_id, $cantidad]);
        if ($stmt->rowCount() === 0) return (int)$variante_id;
    }
    return 0;
}
```

- [ ] **Step 2: Replace equipacion_reponer_stock**

In the same file, replace the existing `equipacion_reponer_stock` function (currently lines 74-82) with:

```php
// Repone stock de las líneas de un pedido cancelado/expirado. Las líneas de
// artículos "bajo pedido" no se tocan (no tienen stock limitado que reponer).
function equipacion_reponer_stock(PDO $pdo, int $pedido_id): void
{
    $stmt = $pdo->prepare(
        'SELECT l.variante_id, l.cantidad, i.bajo_pedido
         FROM equipacion_pedido_lineas l
         JOIN equipacion_variantes v ON v.id = l.variante_id
         JOIN equipacion_items i ON i.id = v.item_id
         WHERE l.pedido_id = ?'
    );
    $stmt->execute([$pedido_id]);
    foreach ($stmt->fetchAll() as $linea) {
        if ((int)$linea['bajo_pedido'] === 1) continue;
        $pdo->prepare('UPDATE equipacion_variantes SET stock = stock + ? WHERE id = ?')
            ->execute([$linea['cantidad'], $linea['variante_id']]);
    }
}
```

- [ ] **Step 3: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/includes/equipacion.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Manual test — bajo_pedido item never blocks reservation and never changes stock**

```bash
docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "
INSERT INTO equipacion_items (nombre, precio, bajo_pedido, creado_por) VALUES ('Chándal test BP', 40.00, 1, 1);
SET @item_id = LAST_INSERT_ID();
INSERT INTO equipacion_variantes (item_id, talla, stock) VALUES (@item_id, 'L', 0);
SELECT @item_id AS item_id, LAST_INSERT_ID() AS variante_id;
"
```

Note the printed `variante_id` (call it `N`). Then, from a PHP REPL inside the container, call the helper directly to confirm it treats a huge quantity as reservable despite `stock=0`:

```bash
docker compose exec -T app php -r "
require '/var/www/html/config/db.php';
require '/var/www/html/includes/equipacion.php';
\$resultado = equipacion_reservar_stock(\$pdo, [N => 500]);
var_dump(\$resultado);
\$stock = \$pdo->query('SELECT stock FROM equipacion_variantes WHERE id=N')->fetchColumn();
var_dump(\$stock);
"
```
(Replace both `N` occurrences with the real variante_id before running.)
Expected: `\$resultado` is `int(0)` (success, not the failing variante_id — proving the huge quantity request didn't fail despite `stock=0`), and `\$stock` is still `"0"` (unchanged — no decrement happened).

- [ ] **Step 5: Manual test — normal (non-bajo_pedido) item is unaffected**

Using the same PHP REPL approach, create a normal item+variant with `stock=2`, call `equipacion_reservar_stock($pdo, [variante_id => 5])` (requesting more than available), confirm it returns the variante_id (failure) and stock stays at `2`. Then call it again with `[variante_id => 1]`, confirm it returns `0` and stock drops to `1`. This confirms the existing non-bajo_pedido behavior (already covered by the original module's tests) still works after this change.

- [ ] **Step 6: Clean up test fixtures**

```bash
docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "
DELETE FROM equipacion_items WHERE nombre IN ('Chándal test BP', '<nombre del item normal usado en Step 5>');
"
```
Confirm via `SELECT COUNT(*) FROM equipacion_items;` that no test rows remain beyond whatever was there before this task.

- [ ] **Step 7: Commit**

```bash
git add includes/equipacion.php
git commit -m "feat(equipacion): helpers de stock ignoran artículos bajo pedido"
```

---

### Task 4: Directiva — subida de foto + checkbox bajo pedido

**Files:**
- Modify: `public/directiva/equipacion.php` (POST handling for `crear_item`/`editar_item`, item card display, modal form + JS)

**Interfaces:**
- Consumes: `equipacion_items.imagen_url`/`bajo_pedido` columns (Task 1), `public/uploads/equipacion/` directory (Task 2).
- Produces: no new functions — this is a self-contained page. The `$items` array (from `SELECT * FROM equipacion_items`) automatically includes the two new columns with no query change needed.

- [ ] **Step 1: Replace the crear_item branch**

In `public/directiva/equipacion.php`, replace the existing `if ($action === 'crear_item') { ... }` block (currently lines 14-24) with:

```php
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
    }
```

- [ ] **Step 2: Replace the editar_item branch**

Replace the existing `elseif ($action === 'editar_item') { ... }` block (currently lines 25-34) with:

```php
    elseif ($action === 'editar_item') {
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
    }
```

- [ ] **Step 3: Update the item card display**

Replace the item card's header block (currently the `<div class="d-flex justify-between align-center" style="gap:12px;flex-wrap:wrap;">` through its closing `</div>` that contains the `<h3>` and price/edit/toggle buttons — lines 103-123 in the original file) with:

```php
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
```

- [ ] **Step 4: Hide stock inputs for bajo_pedido items**

Replace the variant table's stock `<td>` (currently lines 131-139) with:

```php
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
```

And wrap the "Stock inicial" field in the "add talla" form (currently lines 161-164) with a bajo_pedido check:

```php
        <?php if (!$item['bajo_pedido']): ?>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Stock inicial</label>
            <input type="number" name="stock" class="form-control" style="width:100px;" value="0" min="0">
          </div>
        <?php endif; ?>
```

- [ ] **Step 5: Update the modal form**

Replace the entire modal (`<div id="modalItem" ...>` through its closing `</div>`, currently lines 172-197) with:

```php
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
```

- [ ] **Step 6: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/public/directiva/equipacion.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Manual test via real curl — create item with photo + bajo_pedido**

Create a disposable test socio with a `tesorero` cargo row (same approach as prior equipación tasks: check `DESCRIBE users;`/`DESCRIBE cargos;`, bcrypt hash, `estado=activo`, `fecha_fin` NULL). Log in via curl with a cookie jar and CSRF token.

For the multipart upload, use curl's `-F` flag instead of `-d` (this sends `multipart/form-data` automatically):

```bash
curl -s -b <cookiejar> -c <cookiejar> -X POST http://localhost:8080/directiva/equipacion \
  -F "csrf_token=<token>" \
  -F "action=crear_item" \
  -F "nombre=Gorro test foto" \
  -F "descripcion=Gorro de prueba" \
  -F "precio=8.50" \
  -F "bajo_pedido=1" \
  -F "imagen=@/tmp/test.png;type=image/png" \
  -w "%{http_code}\n"
```

You need a real small image file on the host (curl runs on the host, not inside the container) for the `-F "imagen=@..."` upload. Create a minimal valid 1×1 PNG directly with base64 (no PHP/GD dependency):

```bash
echo "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=" | base64 -d > /tmp/test.png
```

Use `-F "imagen=@/tmp/test.png;type=image/png"` in the curl command above (adjust the mime type to `image/png` since that's what this fixture actually is).

Confirm via SQL: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SELECT nombre, imagen_url, bajo_pedido FROM equipacion_items WHERE nombre='Gorro test foto';"` — expect `imagen_url` starting with `/uploads/equipacion/equipacion_` and `bajo_pedido=1`.

Confirm the file landed on disk: `docker compose exec -T app ls -la /var/www/html/public/uploads/equipacion/`.

- [ ] **Step 8: Manual test — GET page renders the photo, badge, and "Sin límite" stock cell**

`curl -s -b <cookiejar> http://localhost:8080/directiva/equipacion | grep -A 3 "Gorro test foto"` — confirm the response contains an `<img src="/uploads/equipacion/...">` tag, a `badge-info` "Bajo pedido" span, and (once you add a talla to this item via `crear_variante`) a "Sin límite" cell instead of a stock input form.

- [ ] **Step 9: Manual test — reject oversized/invalid file**

POST the same `crear_item` action with a `-F "imagen=@<some non-image file>;type=image/jpeg"` (e.g. a `.txt` file renamed with a `.jpg` extension, or any non-image bytes) and confirm the flash message is "El archivo no es una imagen válida." and no new item was created (check `SELECT COUNT(*) FROM equipacion_items` didn't increase).

- [ ] **Step 10: Clean up**

Delete the test item (`DELETE FROM equipacion_items WHERE nombre='Gorro test foto';` — cascades to its variant), delete the uploaded file (`docker compose exec -T app rm -f /var/www/html/public/uploads/equipacion/equipacion_*.png` or the specific filename from Step 7's SQL result), delete the test user/cargo row.

- [ ] **Step 11: Commit**

```bash
git add public/directiva/equipacion.php
git commit -m "feat(equipacion): subida de foto y checkbox bajo pedido en catálogo directiva"
```

---

### Task 5: Socio — mostrar foto y permitir compra sin límite en bajo pedido

**Files:**
- Modify: `public/socio/equipacion.php` (catalog query, `add` action stock check, catalog card display)

**Interfaces:**
- Consumes: `equipacion_items.imagen_url`/`bajo_pedido` columns (Task 1), no change to `includes/equipacion.php` helper signatures (Task 3 already made them bajo_pedido-aware).

- [ ] **Step 1: Update the add action's stock check**

Replace the existing `if ($action === 'add') { ... }` block (currently lines 13-29) with:

```php
    if ($action === 'add') {
        $variante_id = (int)($_POST['variante_id'] ?? 0);
        $cantidad    = max(1, min(20, (int)($_POST['cantidad'] ?? 1)));
        $chk = $pdo->prepare(
            'SELECT v.stock, i.bajo_pedido FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id
             WHERE v.id = ? AND i.activo = 1'
        );
        $chk->execute([$variante_id]);
        $row = $chk->fetch();
        if ($row === false) {
            flash('Artículo no disponible.', 'danger');
        } elseif ((int)$row['bajo_pedido'] === 0 && (int)$row['stock'] < $cantidad) {
            flash('No queda stock suficiente de ese artículo.', 'danger');
        } else {
            carrito_equipacion_add($variante_id, $cantidad);
            flash('Añadido al carrito.', 'success');
        }
    }
```

- [ ] **Step 2: Update the catalog query and grouping**

Replace the `$catalogoRows`/`$catalogo` block (currently lines 42-67) with:

```php
$catalogoRows = $pdo->query(
    "SELECT i.id AS item_id, i.nombre, i.descripcion, i.precio, i.imagen_url, i.bajo_pedido,
            v.id AS variante_id, v.talla, v.stock
     FROM equipacion_items i
     JOIN equipacion_variantes v ON v.item_id = i.id
     WHERE i.activo = 1
     ORDER BY i.nombre, v.talla"
)->fetchAll();

$catalogo = [];
foreach ($catalogoRows as $r) {
    $iid = (int)$r['item_id'];
    if (!isset($catalogo[$iid])) {
        $catalogo[$iid] = [
            'nombre'      => $r['nombre'],
            'descripcion' => $r['descripcion'],
            'precio'      => (float)$r['precio'],
            'imagen_url'  => $r['imagen_url'],
            'bajo_pedido' => (int)$r['bajo_pedido'],
            'variantes'   => [],
        ];
    }
    $catalogo[$iid]['variantes'][] = [
        'variante_id' => (int)$r['variante_id'],
        'talla'       => $r['talla'],
        'stock'       => (int)$r['stock'],
    ];
}
```

- [ ] **Step 3: Update the catalog card display**

Replace the item card's opening block through the talla `<select>` (currently lines 84-107, from `<div class="card mb-4">` through the closing `</select>` of the talla dropdown) with:

```php
        <div class="card mb-4">
          <div class="card-body">
            <div class="d-flex justify-between align-center" style="gap:12px;flex-wrap:wrap;">
              <div class="d-flex gap-3" style="align-items:flex-start;">
                <?php if ($item['imagen_url']): ?>
                  <img src="<?= e($item['imagen_url']) ?>" alt="<?= e($item['nombre']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:8px;flex-shrink:0;">
                <?php else: ?>
                  <div style="width:80px;height:80px;border-radius:8px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-image" style="font-size:28px;color:var(--gray);"></i>
                  </div>
                <?php endif; ?>
                <div>
                  <h3 style="margin:0;"><?= e($item['nombre']) ?></h3>
                  <?php if ($item['descripcion']): ?>
                    <p style="color:var(--gray);margin:4px 0;"><?= e($item['descripcion']) ?></p>
                  <?php endif; ?>
                </div>
              </div>
              <div style="font-size:20px;font-weight:700;"><?= number_format($item['precio'], 2, ',', '.') ?> €</div>
            </div>
            <form method="POST" class="d-flex gap-2" style="margin-top:12px;flex-wrap:wrap;align-items:flex-end;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="add">
              <div class="form-group" style="margin:0;">
                <label class="form-label">Talla</label>
                <select name="variante_id" class="form-control" required>
                  <?php foreach ($item['variantes'] as $v): ?>
                    <?php $sinStock = !$item['bajo_pedido'] && $v['stock'] <= 0; ?>
                    <option value="<?= $v['variante_id'] ?>" <?= $sinStock ? 'disabled' : '' ?>>
                      <?= e($v['talla']) ?><?= $sinStock ? ' (sin stock)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
```

(The rest of the form — cantidad input, submit button, closing tags — stays unchanged.)

- [ ] **Step 4: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/public/socio/equipacion.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Manual test via real curl — bajo_pedido item accepts any quantity**

```bash
docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "
INSERT INTO equipacion_items (nombre, precio, bajo_pedido, creado_por) VALUES ('Chándal test socio BP', 45.00, 1, 1);
SET @item_id = LAST_INSERT_ID();
INSERT INTO equipacion_variantes (item_id, talla, stock) VALUES (@item_id, 'M', 0);
SELECT @item_id AS item_id, LAST_INSERT_ID() AS variante_id;
"
```

Note the variante_id (call it `N`). Create a disposable test socio (no cargo needed — any logged-in user can view `/socio/equipacion`), log in via curl.

`curl -s -b <cookiejar> http://localhost:8080/socio/equipacion | grep -B2 -A5 "Chándal test socio BP"` — confirm the "M" `<option>` has NO `disabled` attribute and NO "(sin stock)" text despite `stock=0` in the DB.

POST `action=add&variante_id=N&cantidad=15&csrf_token=<token>` (quantity far exceeding the 0 stock) and confirm the flash is "Añadido al carrito." (not the "sin stock suficiente" error), and the cart panel on the resulting page shows "Chándal test socio BP" with quantity 15.

- [ ] **Step 6: Manual test — normal item still blocks over-quantity (regression check)**

Using the pre-existing behavior (no new fixture needed — create a quick normal item+variant with `stock=1`, `bajo_pedido=0`), POST `action=add` with `cantidad=5` and confirm the flash is still "No queda stock suficiente de ese artículo." — proving Task 5's changes didn't break the non-bajo_pedido path.

- [ ] **Step 7: Clean up**

Delete both test items (cascades to variants), clear the disposable test users' session cart isn't necessary (session dies with the user), delete the test users.

- [ ] **Step 8: Commit**

```bash
git add public/socio/equipacion.php
git commit -m "feat(equipacion): mostrar foto y permitir compra sin límite en bajo pedido"
```

## Self-Review Notes

- **Spec coverage:** Migration (imagen_url/bajo_pedido columns) → Task 1. Upload directory/htaccess/gitignore → Task 2. Stock-helper bajo_pedido awareness (checkout/webhook/cancel paths all consume these helpers, so no separate task needed for them) → Task 3. Directiva photo upload + bajo_pedido checkbox + stock-field hiding → Task 4. Socio photo display + bajo_pedido-aware add-to-cart + no "sin stock" disabling → Task 5. All spec requirements covered.
- **Placeholder scan:** none found — every step has literal code/commands.
- **Type consistency:** `equipacion_reservar_stock(PDO, array): int` and `equipacion_reponer_stock(PDO, int): void` signatures unchanged from the original module (Task 3 only edits internals), so `public/stripe_checkout.php`, `public/stripe_webhook.php`, and both `equipacion_pedidos.php` cancel flows need zero changes — verified by re-reading their call sites, all pass the same `$carrito`/`$pedido_id` shapes as before.
