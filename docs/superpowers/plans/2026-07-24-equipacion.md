# Módulo de Equipación Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a full "pedido de equipación" module — socios browse a catalog of items/tallas with stock, add to a cart, pay online via Stripe Checkout, and directiva/admin manage the catalog and order lifecycle (pendiente_pago → pagado → entregado, or cancelado).

**Architecture:** Four new MySQL tables (`equipacion_items`, `equipacion_variantes`, `equipacion_pedidos`, `equipacion_pedido_lineas`). A shared helper file (`includes/equipacion.php`) centralizes cart-session logic and the atomic stock reserve/release used by checkout, webhook, and both cancel flows. Stripe Checkout Sessions handle payment; a webhook endpoint reconciles state (`pagado` / `cancelado` on expiry) idempotently under `SELECT ... FOR UPDATE`. Pages follow the existing `directiva/` + `socio/` split already used by `cuotas`/`cuestiones`.

**Tech Stack:** PHP 8.4 + PDO (existing), MySQL 8 (existing), Stripe Checkout via `stripe/stripe-php` SDK (new dependency, added via Composer for the first time in this project), vanilla JS/CSS matching `public/assets/css/main.css` (existing design system).

## Global Constraints

- No automated test suite exists in this project — every task's "test" step is a manual verification: `php -l` syntax check, a direct `mysql` query against the running `db` container, or a `curl`/browser check against `http://localhost:8080`.
- Follow `dirname(__DIR__, 2)` include convention for files under `public/socio/*.php` and `public/directiva/*.php`; `dirname(__DIR__)` for files directly under `public/*.php`.
- All new pages must call `csrf_field()`/`csrf_verify()` on any POST form, use `e()` for HTML-escaping, and use `render_flash()` for one-shot messages — matching every existing page in this codebase.
- Only these badge CSS classes exist in `public/assets/css/main.css`: `badge-success`, `badge-danger`, `badge-warning`, `badge-info`, `badge-gray`, `badge-blue`. (Note: `badge-green`/`badge-red` are used in two older pages but were never defined in the CSS — a pre-existing bug. Do not copy that pattern; use the real classes above.)
- `require_cargo(['presidente', 'secretario', 'tesorero', 'vocal', 'director_tecnico'])` is the exact array already used by `public/directiva/actas.php` and `public/directiva/socios.php` for "admin + directiva" access — reuse verbatim for consistency.
- `.env` and `vendor/` are already gitignored (confirmed in `.gitignore`) — no changes needed there.
- Migrations in this repo are applied manually to the running dev DB via the `db` Docker service; `migrations/` is gitignored as a whole except a few historically-tracked files, and `schema.sql` is NOT consistently kept in sync with every migration (e.g. `incidencias`/`comunicaciones` tables are missing from `schema.sql` despite being live features) — do not attempt to reconcile `schema.sql`, just add the migration file and apply it directly.
- Docker Compose bind-mounts the whole project (`.:/var/www/html`), so anything installed inside the image at build time under that path gets shadowed by the host directory at runtime. Composer itself (the binary) is installed at image-build time; `composer install` (which writes `vendor/`) must be run against the *running* container so it writes to the host-mounted project directory.

---

### Task 1: Migration — equipación tables

**Files:**
- Create: `migrations/019_equipacion.sql`

**Interfaces:**
- Produces: tables `equipacion_items`, `equipacion_variantes`, `equipacion_pedidos`, `equipacion_pedido_lineas` — column names and types as below, consumed by every later task.

- [ ] **Step 1: Write the migration file**

```sql
-- 019: Módulo de equipación (pedidos + pago Stripe)

CREATE TABLE IF NOT EXISTS equipacion_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    precio DECIMAL(8,2) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipacion_variantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    talla VARCHAR(10) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    FOREIGN KEY (item_id) REFERENCES equipacion_items(id) ON DELETE CASCADE,
    UNIQUE KEY uq_item_talla (item_id, talla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipacion_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    estado ENUM('pendiente_pago','pagado','entregado','cancelado') NOT NULL DEFAULT 'pendiente_pago',
    total DECIMAL(8,2) NOT NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent VARCHAR(255) NULL,
    entregado_por INT NULL,
    entregado_at TIMESTAMP NULL,
    cancelado_por INT NULL,
    cancelado_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (entregado_por) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (cancelado_por) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_estado (estado),
    INDEX idx_user (user_id),
    INDEX idx_stripe_session (stripe_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipacion_pedido_lineas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id INT NOT NULL,
    variante_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(8,2) NOT NULL,
    FOREIGN KEY (pedido_id) REFERENCES equipacion_pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (variante_id) REFERENCES equipacion_variantes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply the migration to the running dev DB**

Run: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo < migrations/019_equipacion.sql`
Expected: no output (success). Credentials come from `.env` (`DB_USER`/`DB_PASS`).

- [ ] **Step 3: Verify tables exist**

Run: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SHOW TABLES LIKE 'equipacion_%';"`
Expected: 4 rows — `equipacion_items`, `equipacion_pedido_lineas`, `equipacion_pedidos`, `equipacion_variantes`.

- [ ] **Step 4: Commit**

```bash
git add migrations/019_equipacion.sql
git commit -m "feat(db): tablas equipacion_items/variantes/pedidos/lineas"
```

---

### Task 2: Composer + Stripe PHP SDK

**Files:**
- Create: `composer.json`
- Modify: `Dockerfile`

**Interfaces:**
- Produces: `vendor/autoload.php` on disk (host-mounted), providing the `Stripe\*` namespace used by `config/stripe.php` (Task 3).

- [ ] **Step 1: Create composer.json**

```json
{
    "require": {
        "php": "^8.4",
        "stripe/stripe-php": "^17.0"
    }
}
```

- [ ] **Step 2: Add Composer to the Dockerfile**

Modify `Dockerfile` — insert after the `a2enmod rewrite` line and before the `# Configure Apache` comment:

```dockerfile
# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

- [ ] **Step 3: Rebuild the app image**

Run: `docker compose build app`
Expected: build succeeds, last step shows Composer installed without errors.

- [ ] **Step 4: Recreate the container and install dependencies into the bind-mounted project**

Run: `docker compose up -d app && docker compose exec app composer install --no-interaction`
Expected: Composer downloads `stripe/stripe-php` and writes `vendor/` + `composer.lock` under the project root on the host (visible via `ls vendor/stripe`).

- [ ] **Step 5: Verify autoload works**

Run: `docker compose exec -T app php -r "require '/var/www/html/vendor/autoload.php'; var_dump(class_exists('Stripe\\StripeClient'));"`
Expected: `bool(true)`.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock Dockerfile
git commit -m "build: añadir Composer + stripe/stripe-php al Dockerfile"
```

---

### Task 3: Stripe config + env vars

**Files:**
- Create: `config/stripe.php`
- Modify: `.env` (not committed — gitignored)
- Modify: `.env.example`
- Modify: `docker-compose.yml`

**Interfaces:**
- Consumes: `vendor/autoload.php` (Task 2), `$_ENV`/`getenv()` pattern from `config/env.php` and `config/db.php`.
- Produces: `stripe_client(): \Stripe\StripeClient` and `stripe_public_url(): string`, consumed by Tasks 6, 7, 8.

- [ ] **Step 1: Add Stripe/app-url vars to .env and .env.example**

Append to `.env` (local, not committed):
```
STRIPE_SECRET_KEY=sk_test_REPLACE_ME
STRIPE_PUBLISHABLE_KEY=pk_test_REPLACE_ME
STRIPE_WEBHOOK_SECRET=whsec_REPLACE_ME
APP_URL=http://localhost:8080
```

Append to `.env.example`:
```
STRIPE_SECRET_KEY=STRIPE_SECRET_KEY
STRIPE_PUBLISHABLE_KEY=STRIPE_PUBLISHABLE_KEY
STRIPE_WEBHOOK_SECRET=STRIPE_WEBHOOK_SECRET
APP_URL=http://localhost:8080
```

- [ ] **Step 2: Pass the new vars through docker-compose.yml**

Modify `docker-compose.yml` — in the `app` service `environment:` block, after `DRIVE_FOLDER_ID: ${DRIVE_FOLDER_ID}`:

```yaml
      STRIPE_SECRET_KEY: ${STRIPE_SECRET_KEY}
      STRIPE_PUBLISHABLE_KEY: ${STRIPE_PUBLISHABLE_KEY}
      STRIPE_WEBHOOK_SECRET: ${STRIPE_WEBHOOK_SECRET}
      APP_URL: ${APP_URL}
```

- [ ] **Step 3: Create config/stripe.php**

```php
<?php
require_once __DIR__ . '/env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

function stripe_client(): \Stripe\StripeClient
{
    static $client = null;
    if ($client === null) {
        $secret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: '';
        $client = new \Stripe\StripeClient($secret);
    }
    return $client;
}

function stripe_public_url(): string
{
    $url = $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080';
    return rtrim($url, '/');
}
```

- [ ] **Step 4: Recreate the app container so it picks up the new env vars**

Run: `docker compose up -d app`
Expected: container recreated (compose detects env changes).

- [ ] **Step 5: Verify config loads without errors**

Run: `docker compose exec -T app php -r "require '/var/www/html/config/stripe.php'; var_dump(stripe_public_url());"`
Expected: `string(21) "http://localhost:8080"` (or your configured `APP_URL`).

- [ ] **Step 6: Commit**

```bash
git add .env.example docker-compose.yml config/stripe.php
git commit -m "feat: configuración Stripe (secret/publishable/webhook keys + APP_URL)"
```

---

### Task 4: Shared equipación helpers

**Files:**
- Create: `includes/equipacion.php`

**Interfaces:**
- Consumes: global `$pdo` (from `config/db.php`), `$_SESSION` (session already started by `includes/auth.php`).
- Produces (consumed by Tasks 5–10):
  - `carrito_equipacion(): array` — `[variante_id => cantidad]` from session.
  - `carrito_equipacion_add(int $variante_id, int $cantidad): void`
  - `carrito_equipacion_set(int $variante_id, int $cantidad): void` — `$cantidad <= 0` removes the line.
  - `carrito_equipacion_clear(): void`
  - `equipacion_carrito_detalle(PDO $pdo, array $carrito): array` — list of `['variante_id','nombre','talla','precio','cantidad','subtotal','stock']`.
  - `equipacion_reservar_stock(PDO $pdo, array $carrito): int` — returns `0` on full success, or the failing `variante_id` on insufficient stock.
  - `equipacion_reponer_stock(PDO $pdo, int $pedido_id): void` — restores stock from a pedido's lines.
  - `equipacion_variante_label(PDO $pdo, int $variante_id): string` — `"Camiseta (talla M)"` style label for error messages.

- [ ] **Step 1: Write includes/equipacion.php**

```php
<?php
// Helpers del módulo de equipación: carrito en sesión + stock atómico.

function carrito_equipacion(): array
{
    return $_SESSION['carrito_equipacion'] ?? [];
}

function carrito_equipacion_add(int $variante_id, int $cantidad): void
{
    if (!isset($_SESSION['carrito_equipacion'])) $_SESSION['carrito_equipacion'] = [];
    $actual = $_SESSION['carrito_equipacion'][$variante_id] ?? 0;
    carrito_equipacion_set($variante_id, $actual + $cantidad);
}

function carrito_equipacion_set(int $variante_id, int $cantidad): void
{
    if ($cantidad <= 0) {
        unset($_SESSION['carrito_equipacion'][$variante_id]);
    } else {
        $_SESSION['carrito_equipacion'][$variante_id] = $cantidad;
    }
}

function carrito_equipacion_clear(): void
{
    unset($_SESSION['carrito_equipacion']);
}

function equipacion_carrito_detalle(PDO $pdo, array $carrito): array
{
    if (!$carrito) return [];
    $ids = array_map('intval', array_keys($carrito));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT v.id AS variante_id, v.talla, v.stock, i.nombre, i.precio
         FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id
         WHERE v.id IN ($in)"
    );
    $stmt->execute($ids);

    $detalle = [];
    foreach ($stmt->fetchAll() as $row) {
        $vid = (int)$row['variante_id'];
        $cantidad = $carrito[$vid] ?? 0;
        if ($cantidad <= 0) continue;
        $detalle[] = [
            'variante_id' => $vid,
            'nombre'      => $row['nombre'],
            'talla'       => $row['talla'],
            'precio'      => (float)$row['precio'],
            'cantidad'    => $cantidad,
            'subtotal'    => (float)$row['precio'] * $cantidad,
            'stock'       => (int)$row['stock'],
        ];
    }
    return $detalle;
}

// Reserva stock atómicamente. Devuelve 0 si todo OK, o el variante_id que
// falló por falta de stock (el caller debe hacer ROLLBACK de la transacción).
function equipacion_reservar_stock(PDO $pdo, array $carrito): int
{
    foreach ($carrito as $variante_id => $cantidad) {
        $stmt = $pdo->prepare(
            'UPDATE equipacion_variantes SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([$cantidad, $variante_id, $cantidad]);
        if ($stmt->rowCount() === 0) return (int)$variante_id;
    }
    return 0;
}

function equipacion_reponer_stock(PDO $pdo, int $pedido_id): void
{
    $stmt = $pdo->prepare('SELECT variante_id, cantidad FROM equipacion_pedido_lineas WHERE pedido_id = ?');
    $stmt->execute([$pedido_id]);
    foreach ($stmt->fetchAll() as $linea) {
        $pdo->prepare('UPDATE equipacion_variantes SET stock = stock + ? WHERE id = ?')
            ->execute([$linea['cantidad'], $linea['variante_id']]);
    }
}

function equipacion_variante_label(PDO $pdo, int $variante_id): string
{
    $stmt = $pdo->prepare(
        'SELECT i.nombre, v.talla FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id WHERE v.id = ?'
    );
    $stmt->execute([$variante_id]);
    $r = $stmt->fetch();
    return $r ? ($r['nombre'] . ' (talla ' . $r['talla'] . ')') : 'artículo';
}

function equipacion_badge_estado(string $estado): string
{
    return match ($estado) {
        'pagado'    => 'badge-blue',
        'entregado' => 'badge-success',
        'cancelado' => 'badge-gray',
        default     => 'badge-danger', // pendiente_pago
    };
}
```

- [ ] **Step 2: Syntax-check the file**

Run: `docker compose exec -T app php -l /var/www/html/includes/equipacion.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual smoke test of stock reservation logic**

Run:
```bash
docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "
INSERT INTO equipacion_items (nombre, precio, creado_por) VALUES ('Camiseta test', 15.00, 1);
SET @item_id = LAST_INSERT_ID();
INSERT INTO equipacion_variantes (item_id, talla, stock) VALUES (@item_id, 'M', 2);
SELECT @item_id;
"
```
Then, using the printed item id (say `N`), fetch its variant id and confirm the reserve function decrements correctly:
```bash
docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SELECT id, stock FROM equipacion_variantes WHERE item_id=N;"
```
Expected: one row, `stock=2`. This confirms the seed data Task 5+ will render against is in place (this row is reused as manual test fixture in later tasks — do not delete it).

- [ ] **Step 4: Commit**

```bash
git add includes/equipacion.php
git commit -m "feat(equipacion): helpers de carrito en sesión y reserva/reposición de stock"
```

---

### Task 5: Socio — catálogo + carrito

**Files:**
- Create: `public/socio/equipacion.php`

**Interfaces:**
- Consumes: `carrito_equipacion()`, `carrito_equipacion_add()`, `carrito_equipacion_set()`, `carrito_equipacion_clear()`, `equipacion_carrito_detalle()` (Task 4); `require_login()`, `csrf_field()`, `csrf_verify()`, `flash()`, `render_flash()`, `e()` (existing `includes/auth.php`); `render_header()`/`render_footer()` (existing `includes/layout.php`).
- Produces: the `/socio/equipacion` page, POST actions `add`/`quitar`/`vaciar`; links to `/stripe_checkout` (Task 6) and `/socio/equipacion_pedidos` (Task 8).

- [ ] **Step 1: Write public/socio/equipacion.php**

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/equipacion.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $variante_id = (int)($_POST['variante_id'] ?? 0);
        $cantidad    = max(1, (int)($_POST['cantidad'] ?? 1));
        $chk = $pdo->prepare(
            'SELECT v.stock FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id
             WHERE v.id = ? AND i.activo = 1'
        );
        $chk->execute([$variante_id]);
        $stock = $chk->fetchColumn();
        if ($stock === false) {
            flash('Artículo no disponible.', 'danger');
        } else {
            carrito_equipacion_add($variante_id, $cantidad);
            flash('Añadido al carrito.', 'success');
        }
    } elseif ($action === 'quitar') {
        carrito_equipacion_set((int)($_POST['variante_id'] ?? 0), 0);
        flash('Artículo quitado del carrito.', 'warning');
    } elseif ($action === 'vaciar') {
        carrito_equipacion_clear();
        flash('Carrito vaciado.', 'warning');
    }

    header('Location: /socio/equipacion');
    exit;
}

$catalogoRows = $pdo->query(
    "SELECT i.id AS item_id, i.nombre, i.descripcion, i.precio,
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
            'variantes'   => [],
        ];
    }
    $catalogo[$iid]['variantes'][] = [
        'variante_id' => (int)$r['variante_id'],
        'talla'       => $r['talla'],
        'stock'       => (int)$r['stock'],
    ];
}

$carritoDetalle = equipacion_carrito_detalle($pdo, carrito_equipacion());
$carritoTotal    = array_sum(array_column($carritoDetalle, 'subtotal'));

render_header('Equipación', 'socio-equipacion');
?>
<div class="container page-content">
  <h1>Equipación del club</h1>
  <?php render_flash(); ?>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:start;">
    <div>
      <?php if (!$catalogo): ?>
        <p class="text-muted">No hay artículos disponibles ahora mismo.</p>
      <?php endif; ?>
      <?php foreach ($catalogo as $item): ?>
        <div class="card mb-4">
          <div class="card-body">
            <div class="d-flex justify-between align-center" style="gap:12px;flex-wrap:wrap;">
              <div>
                <h3 style="margin:0;"><?= e($item['nombre']) ?></h3>
                <?php if ($item['descripcion']): ?>
                  <p style="color:var(--gray);margin:4px 0;"><?= e($item['descripcion']) ?></p>
                <?php endif; ?>
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
                    <option value="<?= $v['variante_id'] ?>" <?= $v['stock'] <= 0 ? 'disabled' : '' ?>>
                      <?= e($v['talla']) ?><?= $v['stock'] <= 0 ? ' (sin stock)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="margin:0;width:90px;">
                <label class="form-label">Cant.</label>
                <input type="number" name="cantidad" class="form-control" value="1" min="1" max="20">
              </div>
              <button type="submit" class="btn btn-primary"><i class="bi bi-cart-plus"></i> Añadir</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header"><h3 style="margin:0;font-size:16px;"><i class="bi bi-cart"></i> Tu carrito</h3></div>
      <div class="card-body">
        <?php if (!$carritoDetalle): ?>
          <p class="text-muted" style="margin:0;">Carrito vacío.</p>
        <?php else: ?>
          <?php foreach ($carritoDetalle as $l): ?>
            <div class="d-flex justify-between align-center" style="padding:8px 0;border-bottom:1px solid #eee;gap:8px;">
              <div>
                <strong><?= e($l['nombre']) ?></strong> — talla <?= e($l['talla']) ?><br>
                <span style="color:var(--gray);font-size:13px;"><?= $l['cantidad'] ?> × <?= number_format($l['precio'], 2, ',', '.') ?> €</span>
              </div>
              <div class="d-flex align-center gap-2">
                <strong><?= number_format($l['subtotal'], 2, ',', '.') ?> €</strong>
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="quitar">
                  <input type="hidden" name="variante_id" value="<?= $l['variante_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-gray" title="Quitar"><i class="bi bi-x"></i></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="d-flex justify-between" style="margin-top:12px;font-size:18px;font-weight:700;">
            <span>Total</span><span><?= number_format($carritoTotal, 2, ',', '.') ?> €</span>
          </div>
          <form method="POST" action="/stripe_checkout" style="margin-top:16px;" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-credit-card"></i> Pagar con Stripe</button>
          </form>
          <form method="POST" style="margin-top:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="vaciar">
            <button type="submit" class="btn btn-gray btn-sm w-100">Vaciar carrito</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <p style="margin-top:16px;"><a href="/socio/equipacion_pedidos"><i class="bi bi-receipt"></i> Ver mis pedidos</a></p>
</div>
<?php render_footer(); ?>
```

Note: the `onsubmit` handler disables the submit button so a double-click can't fire two checkout requests (spec edge case — server-side the stock reservation itself is also atomic per Task 6, this is a belt-and-suspenders UX guard).

- [ ] **Step 2: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/public/socio/equipacion.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual browser test**

Log in as a socio at `http://localhost:8080/login`, visit `http://localhost:8080/socio/equipacion`. Confirm: the "Camiseta test" seeded in Task 4 Step 3 appears with talla M, add 1 to cart, confirm it shows in the cart panel with correct subtotal, remove it, confirm cart empties.

- [ ] **Step 4: Commit**

```bash
git add public/socio/equipacion.php
git commit -m "feat(equipacion): página socio catálogo + carrito"
```

---

### Task 6: Stripe Checkout creation endpoint

**Files:**
- Create: `public/stripe_checkout.php`

**Interfaces:**
- Consumes: `carrito_equipacion()`, `carrito_equipacion_clear()`, `equipacion_reservar_stock()`, `equipacion_variante_label()` (Task 4); `stripe_client()`, `stripe_public_url()` (Task 3); `require_login()`, `csrf_verify()`, `flash()` (existing).
- Produces: redirect to Stripe-hosted checkout URL; writes `equipacion_pedidos`/`equipacion_pedido_lineas` rows with `stripe_session_id` set, consumed by the webhook (Task 7) via `client_reference_id`/`stripe_session_id` lookup.

- [ ] **Step 1: Write public/stripe_checkout.php**

```php
<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/equipacion.php';
require_once dirname(__DIR__) . '/config/stripe.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /socio/equipacion');
    exit;
}
csrf_verify();

$carrito = carrito_equipacion();
if (!$carrito) {
    flash('Tu carrito está vacío.', 'danger');
    header('Location: /socio/equipacion');
    exit;
}

$uid = (int)current_user()['id'];

$ids = array_map('intval', array_keys($carrito));
$in  = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT v.id AS variante_id, v.talla, i.nombre, i.precio
     FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id
     WHERE v.id IN ($in) AND i.activo = 1"
);
$stmt->execute($ids);
$variantes = [];
foreach ($stmt->fetchAll() as $v) $variantes[(int)$v['variante_id']] = $v;

if (count($variantes) !== count($ids)) {
    flash('Algún artículo del carrito ya no está disponible.', 'danger');
    carrito_equipacion_clear();
    header('Location: /socio/equipacion');
    exit;
}

$pdo->beginTransaction();
try {
    $fallo = equipacion_reservar_stock($pdo, $carrito);
    if ($fallo !== 0) {
        $pdo->rollBack();
        flash('Sin stock suficiente de ' . equipacion_variante_label($pdo, $fallo) . '.', 'danger');
        header('Location: /socio/equipacion');
        exit;
    }

    $total = 0.0;
    foreach ($carrito as $variante_id => $cantidad) {
        $total += $variantes[$variante_id]['precio'] * $cantidad;
    }

    $pdo->prepare('INSERT INTO equipacion_pedidos (user_id, estado, total) VALUES (?,?,?)')
        ->execute([$uid, 'pendiente_pago', $total]);
    $pedido_id = (int)$pdo->lastInsertId();

    $insLinea = $pdo->prepare(
        'INSERT INTO equipacion_pedido_lineas (pedido_id, variante_id, cantidad, precio_unitario) VALUES (?,?,?,?)'
    );
    $lineItems = [];
    foreach ($carrito as $variante_id => $cantidad) {
        $v = $variantes[$variante_id];
        $insLinea->execute([$pedido_id, $variante_id, $cantidad, $v['precio']]);
        $lineItems[] = [
            'price_data' => [
                'currency'     => 'eur',
                'unit_amount'  => (int)round($v['precio'] * 100),
                'product_data' => ['name' => $v['nombre'] . ' — talla ' . $v['talla']],
            ],
            'quantity' => $cantidad,
        ];
    }

    $session = stripe_client()->checkout->sessions->create([
        'mode'                => 'payment',
        'line_items'          => $lineItems,
        'client_reference_id' => (string)$pedido_id,
        'success_url'         => stripe_public_url() . '/socio/equipacion_pedidos?pago=ok',
        'cancel_url'          => stripe_public_url() . '/socio/equipacion_pedidos?pago=cancelado',
    ]);

    $pdo->prepare('UPDATE equipacion_pedidos SET stripe_session_id = ? WHERE id = ?')
        ->execute([$session->id, $pedido_id]);

    $pdo->commit();
    carrito_equipacion_clear();
    header('Location: ' . $session->url);
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Stripe checkout error: ' . $e->getMessage());
    flash('No se ha podido iniciar el pago. Inténtalo de nuevo.', 'danger');
    header('Location: /socio/equipacion');
    exit;
}
```

- [ ] **Step 2: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/public/stripe_checkout.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual test — insufficient stock path**

With the seeded "Camiseta test" (stock=2) from Task 4, add 5 to the cart on `/socio/equipacion`, click "Pagar con Stripe". Expected: redirected back to `/socio/equipacion` with flash "Sin stock suficiente de Camiseta test (talla M)." and stock unchanged at 2 (verify: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SELECT stock FROM equipacion_variantes WHERE talla='M';"`).

- [ ] **Step 4: Manual test — happy path (requires a real Stripe test secret key in .env from Task 3)**

Add 1 "Camiseta test" to cart, click "Pagar con Stripe". Expected: redirected to a real `checkout.stripe.com` URL. Confirm in DB: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SELECT id, estado, stripe_session_id FROM equipacion_pedidos ORDER BY id DESC LIMIT 1;"` shows `estado=pendiente_pago` and a non-null `stripe_session_id`. Confirm stock decremented to 1.

- [ ] **Step 5: Commit**

```bash
git add public/stripe_checkout.php
git commit -m "feat(equipacion): endpoint de creación de Stripe Checkout Session"
```

---

### Task 7: Stripe webhook

**Files:**
- Create: `public/stripe_webhook.php`

**Interfaces:**
- Consumes: `equipacion_reponer_stock()` (Task 4); `stripe_client()` is not needed here, but `\Stripe\Webhook::constructEvent()` from the SDK (Task 2) and `$_ENV['STRIPE_WEBHOOK_SECRET']`.
- Produces: transitions `equipacion_pedidos.estado` from `pendiente_pago` to `pagado` or `cancelado`. No other task depends on this file's internals, only on the DB state it writes.

- [ ] **Step 1: Write public/stripe_webhook.php**

```php
<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/stripe.php';
require_once dirname(__DIR__) . '/includes/equipacion.php';

$payload       = @file_get_contents('php://input');
$sigHeader     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET') ?: '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
} catch (\Throwable $e) {
    http_response_code(400);
    error_log('Stripe webhook: firma inválida — ' . $e->getMessage());
    exit;
}

$type = $event->type;

if ($type === 'checkout.session.completed') {
    $session = $event->data->object;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, estado FROM equipacion_pedidos WHERE stripe_session_id = ? FOR UPDATE');
    $stmt->execute([$session->id]);
    $pedido = $stmt->fetch();
    if ($pedido && $pedido['estado'] === 'pendiente_pago') {
        $pdo->prepare('UPDATE equipacion_pedidos SET estado = ?, stripe_payment_intent = ? WHERE id = ?')
            ->execute(['pagado', $session->payment_intent, $pedido['id']]);
    }
    $pdo->commit();
} elseif ($type === 'checkout.session.expired') {
    $session = $event->data->object;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, estado FROM equipacion_pedidos WHERE stripe_session_id = ? FOR UPDATE');
    $stmt->execute([$session->id]);
    $pedido = $stmt->fetch();
    if ($pedido && $pedido['estado'] === 'pendiente_pago') {
        equipacion_reponer_stock($pdo, (int)$pedido['id']);
        $pdo->prepare('UPDATE equipacion_pedidos SET estado = ? WHERE id = ?')
            ->execute(['cancelado', $pedido['id']]);
    }
    $pdo->commit();
}

http_response_code(200);
echo json_encode(['received' => true]);
```

- [ ] **Step 2: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/public/stripe_webhook.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify signature rejection**

Run: `curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/stripe_webhook -d '{"type":"checkout.session.completed"}'`
Expected: `400` (no valid `Stripe-Signature` header sent).

- [ ] **Step 4: Manual end-to-end test with Stripe CLI**

Install/use the Stripe CLI locally: `stripe listen --forward-to http://localhost:8080/stripe_webhook`. Copy the `whsec_...` it prints into `.env` as `STRIPE_WEBHOOK_SECRET`, then `docker compose up -d app` to reload env. Complete a real test-mode checkout from Task 6 Step 4 using Stripe's test card `4242 4242 4242 4242`. Expected: the CLI shows `checkout.session.completed` forwarded with a `200` response, and `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SELECT estado, stripe_payment_intent FROM equipacion_pedidos ORDER BY id DESC LIMIT 1;"` shows `estado=pagado` with a non-null `stripe_payment_intent`.

- [ ] **Step 5: Manual test — idempotent duplicate webhook**

In the Stripe CLI dashboard/logs, resend the same `checkout.session.completed` event (or run `stripe events resend <event_id>`). Expected: second delivery also returns `200`, and the pedido's `estado` stays `pagado` (no error, no duplicate side effect) — confirmed by rerunning the same `SELECT` and seeing identical values.

- [ ] **Step 6: Commit**

```bash
git add public/stripe_webhook.php
git commit -m "feat(equipacion): webhook Stripe (pagado/expirado, idempotente)"
```

---

### Task 8: Socio — historial de pedidos + cancelación propia

**Files:**
- Create: `public/socio/equipacion_pedidos.php`

**Interfaces:**
- Consumes: `equipacion_reponer_stock()`, `equipacion_badge_estado()` (Task 4).
- Produces: the `/socio/equipacion_pedidos` page linked from Task 5 and used as Stripe `success_url`/`cancel_url` target (Task 6).

- [ ] **Step 1: Write public/socio/equipacion_pedidos.php**

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/equipacion.php';

require_login();
$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'cancelar') {
        $pedido_id = (int)($_POST['pedido_id'] ?? 0);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, estado FROM equipacion_pedidos WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$pedido_id, $uid]);
        $pedido = $stmt->fetch();
        if ($pedido && $pedido['estado'] === 'pendiente_pago') {
            equipacion_reponer_stock($pdo, $pedido_id);
            $pdo->prepare(
                "UPDATE equipacion_pedidos SET estado='cancelado', cancelado_por=?, cancelado_at=NOW() WHERE id=?"
            )->execute([$uid, $pedido_id]);
            $pdo->commit();
            flash('Pedido cancelado.', 'warning');
        } else {
            $pdo->rollBack();
            flash('Este pedido ya no se puede cancelar.', 'danger');
        }
    }
    header('Location: /socio/equipacion_pedidos');
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.*, GROUP_CONCAT(CONCAT(i.nombre,' (',v.talla,') x',l.cantidad) SEPARATOR ', ') AS resumen
    FROM equipacion_pedidos p
    JOIN equipacion_pedido_lineas l ON l.pedido_id = p.id
    JOIN equipacion_variantes v ON v.id = l.variante_id
    JOIN equipacion_items i ON i.id = v.item_id
    WHERE p.user_id = ?
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$stmt->execute([$uid]);
$pedidos = $stmt->fetchAll();

render_header('Mis pedidos de equipación', 'socio-equipacion');
?>
<div class="container page-content">
  <div class="d-flex justify-between align-center mb-4" style="gap:12px;flex-wrap:wrap;">
    <h1 style="margin:0;">Mis pedidos de equipación</h1>
    <a href="/socio/equipacion" class="btn btn-primary btn-sm"><i class="bi bi-shop"></i> Ir a la tienda</a>
  </div>
  <?php render_flash(); ?>

  <?php if (isset($_GET['pago']) && $_GET['pago'] === 'ok'): ?>
    <div class="alert alert-success">Pago recibido. En unos segundos verás el pedido como pagado.</div>
  <?php elseif (isset($_GET['pago']) && $_GET['pago'] === 'cancelado'): ?>
    <div class="alert alert-warning">Pago cancelado. Puedes reintentarlo desde tus pedidos pendientes.</div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body" style="padding:0;overflow-x:auto;">
      <?php if (!$pedidos): ?>
        <p style="padding:24px;text-align:center;color:var(--gray);margin:0;">Todavía no has hecho ningún pedido.</p>
      <?php else: ?>
        <table class="table" style="margin:0;">
          <thead>
            <tr><th>Fecha</th><th>Artículos</th><th>Total</th><th>Estado</th><th style="width:100px;"></th></tr>
          </thead>
          <tbody>
            <?php foreach ($pedidos as $p): ?>
              <tr>
                <td><?= e(substr((string)$p['created_at'], 0, 16)) ?></td>
                <td><?= e($p['resumen']) ?></td>
                <td><?= number_format((float)$p['total'], 2, ',', '.') ?> €</td>
                <td><span class="badge <?= equipacion_badge_estado($p['estado']) ?>"><?= e(ucfirst(str_replace('_', ' ', $p['estado']))) ?></span></td>
                <td style="text-align:right;">
                  <?php if ($p['estado'] === 'pendiente_pago'): ?>
                    <form method="POST" data-confirm="¿Cancelar este pedido?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="cancelar">
                      <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> Cancelar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php render_footer(); ?>
```

- [ ] **Step 2: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/public/socio/equipacion_pedidos.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual test — cancel own pending order**

Create a pending order (Task 6 Step 3's insufficient-stock test won't create one; instead add 1 unit to cart and checkout without completing Stripe payment, or directly insert a test row). Visit `/socio/equipacion_pedidos`, click "Cancelar" on a `pendiente_pago` row. Expected: flash "Pedido cancelado.", row shows `Cancelado` badge, and the reserved stock is restored (verify via the same `SELECT stock FROM equipacion_variantes` query as before).

- [ ] **Step 4: Manual test — cannot cancel a paid order**

Using the paid order from Task 7 Step 4, confirm no "Cancelar" button is rendered next to it (only `pendiente_pago` rows show the button).

- [ ] **Step 5: Commit**

```bash
git add public/socio/equipacion_pedidos.php
git commit -m "feat(equipacion): historial de pedidos del socio + cancelación propia"
```

---

### Task 9: Directiva — CRUD de catálogo

**Files:**
- Create: `public/directiva/equipacion.php`

**Interfaces:**
- Consumes: `require_cargo()` (existing, exact array per Global Constraints), `render_directiva_layout()` (existing).
- Produces: the `/directiva/equipacion` page, linked from Task 10 and from the sidebar update in Task 11.

- [ ] **Step 1: Write public/directiva/equipacion.php**

```php
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
        if (!$nombre || $precio <= 0) {
            flash('Nombre y precio son obligatorios.', 'danger');
        } else {
            $pdo->prepare('INSERT INTO equipacion_items (nombre, descripcion, precio, creado_por) VALUES (?,?,?,?)')
                ->execute([$nombre, $descripcion ?: null, $precio, $uid]);
            flash('Artículo creado.', 'success');
        }
    } elseif ($action === 'editar_item') {
        $item_id     = (int)($_POST['item_id'] ?? 0);
        $nombre      = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $precio      = (float)str_replace(',', '.', $_POST['precio'] ?? '0');
        if ($item_id && $nombre && $precio > 0) {
            $pdo->prepare('UPDATE equipacion_items SET nombre=?, descripcion=?, precio=? WHERE id=?')
                ->execute([$nombre, $descripcion ?: null, $precio, $item_id]);
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
        <div>
          <h3 style="margin:0;">
            <?= e($item['nombre']) ?>
            <span class="badge <?= $item['activo'] ? 'badge-success' : 'badge-gray' ?>"><?= $item['activo'] ? 'Activo' : 'Inactivo' ?></span>
          </h3>
          <?php if ($item['descripcion']): ?><p style="color:var(--gray);margin:4px 0;"><?= e($item['descripcion']) ?></p><?php endif; ?>
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
                <form method="POST" class="d-flex gap-2 align-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="editar_stock">
                  <input type="hidden" name="variante_id" value="<?= (int)$v['id'] ?>">
                  <input type="number" name="stock" value="<?= (int)$v['stock'] ?>" min="0" class="form-control" style="width:90px;">
                  <button type="submit" class="btn btn-sm btn-secondary">Guardar</button>
                </form>
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
        <div class="form-group" style="margin:0;">
          <label class="form-label">Stock inicial</label>
          <input type="number" name="stock" class="form-control" style="width:100px;" value="0" min="0">
        </div>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus"></i> Añadir talla</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<!-- Modal artículo (nuevo / editar) -->
<div id="modalItem" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:24px;max-width:480px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 id="tituloModalItem" style="margin-top:0;">Nuevo artículo</h3>
    <form method="POST" id="formItem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="itemAction" value="crear_item">
      <input type="hidden" name="item_id" id="itemId">
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
      <div class="d-flex gap-2" style="justify-content:flex-end;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modalItem').style.display='none'">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirNuevoItem() {
  document.getElementById('tituloModalItem').textContent = 'Nuevo artículo';
  document.getElementById('itemAction').value = 'crear_item';
  document.getElementById('itemId').value = '';
  document.getElementById('formItem').reset();
  document.getElementById('modalItem').style.display = 'flex';
}

function abrirEditarItem(item) {
  document.getElementById('tituloModalItem').textContent = 'Editar artículo';
  document.getElementById('itemAction').value = 'editar_item';
  document.getElementById('itemId').value = item.id;
  document.getElementById('itemNombre').value = item.nombre;
  document.getElementById('itemDescripcion').value = item.descripcion || '';
  document.getElementById('itemPrecio').value = item.precio;
  document.getElementById('modalItem').style.display = 'flex';
}
</script>
<?php
});
render_footer();
```

- [ ] **Step 2: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/public/directiva/equipacion.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual test as tesorero/vocal/presidente/secretario/director_tecnico or admin**

Visit `/directiva/equipacion`. Confirm the seeded "Camiseta test" item appears with its "M" variant and current stock. Create a new item, add a new talla with stock, edit stock inline, toggle the item inactive and confirm it disappears from `/socio/equipacion` (Task 5) catalog, toggle it back active.

- [ ] **Step 4: Manual test — RESTRICT on variant delete**

Try to delete the "M" talla of "Camiseta test" (it has order lines from Task 6/7 tests). Expected: flash "No se puede eliminar: hay pedidos con esta talla..." and the row remains.

- [ ] **Step 5: Commit**

```bash
git add public/directiva/equipacion.php
git commit -m "feat(equipacion): CRUD de catálogo (items + tallas + stock) para directiva"
```

---

### Task 10: Directiva — gestión de pedidos

**Files:**
- Create: `public/directiva/equipacion_pedidos.php`

**Interfaces:**
- Consumes: `equipacion_reponer_stock()`, `equipacion_badge_estado()` (Task 4).
- Produces: the `/directiva/equipacion_pedidos` page, linked from Task 9's header button and Task 11's sidebar.

- [ ] **Step 1: Write public/directiva/equipacion_pedidos.php**

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/equipacion.php';

require_cargo(['presidente', 'secretario', 'tesorero', 'vocal', 'director_tecnico']);

$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action    = $_POST['action'] ?? '';
    $pedido_id = (int)($_POST['pedido_id'] ?? 0);

    if ($action === 'entregar') {
        $pdo->prepare(
            "UPDATE equipacion_pedidos SET estado='entregado', entregado_por=?, entregado_at=NOW()
             WHERE id=? AND estado='pagado'"
        )->execute([$uid, $pedido_id]);
        flash('Pedido marcado como entregado.', 'success');
    } elseif ($action === 'cancelar') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, estado FROM equipacion_pedidos WHERE id=? FOR UPDATE');
        $stmt->execute([$pedido_id]);
        $pedido = $stmt->fetch();
        if ($pedido && $pedido['estado'] === 'pendiente_pago') {
            equipacion_reponer_stock($pdo, $pedido_id);
            $pdo->prepare(
                "UPDATE equipacion_pedidos SET estado='cancelado', cancelado_por=?, cancelado_at=NOW() WHERE id=?"
            )->execute([$uid, $pedido_id]);
            $pdo->commit();
            flash('Pedido cancelado.', 'warning');
        } else {
            $pdo->rollBack();
            flash('Este pedido ya no se puede cancelar.', 'danger');
        }
    }

    $qs = isset($_GET['estado']) ? '?estado=' . urlencode($_GET['estado']) : '';
    header('Location: /directiva/equipacion_pedidos' . $qs);
    exit;
}

$fEstado = $_GET['estado'] ?? 'todos';
$estadosOk = ['pendiente_pago', 'pagado', 'entregado', 'cancelado'];
$where = [];
$params = [];
if (in_array($fEstado, $estadosOk, true)) { $where[] = 'p.estado=?'; $params[] = $fEstado; }
$sqlW = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT p.*, u.nombre AS socio_nombre,
           GROUP_CONCAT(CONCAT(i.nombre,' (',v.talla,') x',l.cantidad) SEPARATOR ', ') AS resumen
    FROM equipacion_pedidos p
    JOIN users u ON u.id = p.user_id
    JOIN equipacion_pedido_lineas l ON l.pedido_id = p.id
    JOIN equipacion_variantes v ON v.id = l.variante_id
    JOIN equipacion_items i ON i.id = v.item_id
    $sqlW
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$pedidos = $stmt->fetchAll();

$counts = $pdo->query('SELECT estado, COUNT(*) AS n FROM equipacion_pedidos GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);

render_header('Equipación — Pedidos', 'directiva-equipacion');
render_directiva_layout('equipacion', function () use ($pedidos, $fEstado, $counts) {
?>
<div class="d-flex justify-between align-center mb-4" style="gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;">Pedidos de equipación</h1>
  <a href="/directiva/equipacion" class="btn btn-secondary btn-sm"><i class="bi bi-box-seam"></i> Catálogo</a>
</div>

<?php render_flash(); ?>

<div class="card mb-4">
  <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="color:var(--gray);font-size:13px;margin-right:8px;">Filtrar:</span>
    <a href="?estado=todos" class="btn btn-sm <?= $fEstado === 'todos' ? 'btn-primary' : 'btn-gray' ?>">Todos</a>
    <a href="?estado=pendiente_pago" class="btn btn-sm <?= $fEstado === 'pendiente_pago' ? 'btn-primary' : 'btn-gray' ?>">
      Pendientes <?= isset($counts['pendiente_pago']) ? '(' . (int)$counts['pendiente_pago'] . ')' : '' ?>
    </a>
    <a href="?estado=pagado" class="btn btn-sm <?= $fEstado === 'pagado' ? 'btn-primary' : 'btn-gray' ?>">
      Pagados <?= isset($counts['pagado']) ? '(' . (int)$counts['pagado'] . ')' : '' ?>
    </a>
    <a href="?estado=entregado" class="btn btn-sm <?= $fEstado === 'entregado' ? 'btn-primary' : 'btn-gray' ?>">
      Entregados <?= isset($counts['entregado']) ? '(' . (int)$counts['entregado'] . ')' : '' ?>
    </a>
    <a href="?estado=cancelado" class="btn btn-sm <?= $fEstado === 'cancelado' ? 'btn-primary' : 'btn-gray' ?>">
      Cancelados <?= isset($counts['cancelado']) ? '(' . (int)$counts['cancelado'] . ')' : '' ?>
    </a>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <?php if (!$pedidos): ?>
      <p style="padding:24px;text-align:center;color:var(--gray);margin:0;">Sin pedidos.</p>
    <?php else: ?>
      <table class="table" style="margin:0;">
        <thead><tr><th>Fecha</th><th>Socio</th><th>Artículos</th><th>Total</th><th>Estado</th><th style="width:180px;text-align:right;">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($pedidos as $p): ?>
            <tr>
              <td><?= e(substr((string)$p['created_at'], 0, 16)) ?></td>
              <td><?= e($p['socio_nombre']) ?></td>
              <td><?= e($p['resumen']) ?></td>
              <td><?= number_format((float)$p['total'], 2, ',', '.') ?> €</td>
              <td><span class="badge <?= equipacion_badge_estado($p['estado']) ?>"><?= e(ucfirst(str_replace('_', ' ', $p['estado']))) ?></span></td>
              <td style="text-align:right;">
                <?php if ($p['estado'] === 'pagado'): ?>
                  <form method="POST" style="display:inline;" data-confirm="¿Marcar como entregado?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="entregar">
                    <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Entregar</button>
                  </form>
                <?php endif; ?>
                <?php if ($p['estado'] === 'pendiente_pago'): ?>
                  <form method="POST" style="display:inline;" data-confirm="¿Cancelar este pedido?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancelar">
                    <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> Cancelar</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php
});
render_footer();
```

- [ ] **Step 2: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/public/directiva/equipacion_pedidos.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual test — mark delivered**

Visit `/directiva/equipacion_pedidos`, filter by "Pagados", find the order paid in Task 7 Step 4, click "Entregar". Expected: flash "Pedido marcado como entregado.", row moves to `Entregado` badge, `entregado_por`/`entregado_at` populated (verify via `SELECT estado, entregado_por, entregado_at FROM equipacion_pedidos WHERE id=<id>;`).

- [ ] **Step 4: Manual test — directiva cancels a pending order**

Create another pending order (repeat Task 6 Step 4's flow without paying), then from `/directiva/equipacion_pedidos` cancel it. Expected: stock restored, `cancelado_por` set to the directiva user's id.

- [ ] **Step 5: Commit**

```bash
git add public/directiva/equipacion_pedidos.php
git commit -m "feat(equipacion): gestión de pedidos para directiva (entregar/cancelar)"
```

---

### Task 11: Navegación — enlaces en sidebar y menú

**Files:**
- Modify: `includes/layout.php:303-337` (`render_directiva_layout`)
- Modify: `includes/layout.php:395-406` (admin sidebar "Directiva" section inside `render_admin_layout`)
- Modify: `includes/layout.php:105-113` (navbar user dropdown)

**Interfaces:**
- Consumes: nothing new. Produces: visible navigation to `/directiva/equipacion`, `/directiva/equipacion_pedidos`, and `/socio/equipacion` from every page that renders the layout.

- [ ] **Step 1: Add sidebar links in render_directiva_layout**

In `includes/layout.php`, inside `render_directiva_layout()`, after the "Cuestiones" link (currently ending the `admin-sidebar-section` block at line 319 with `</a>` then `</div>`), add:

```php
        <a href="/directiva/equipacion" class="<?= $activePage === 'equipacion' ? 'active' : '' ?>">
          <i class="bi bi-bag-check-fill"></i> Equipación
        </a>
```

placed directly after the existing `cuestiones` `<a>` tag and before the closing `</div>` of that sidebar section, so the section reads: Socios y cuotas → Actas → Cuestiones → Equipación.

- [ ] **Step 2: Add the same section to render_admin_layout's "Directiva" block**

In the `render_admin_layout()` function's `<div class="admin-sidebar-title">Directiva</div>` section (currently listing socios/actas/cuestiones), add after the cuestiones link:

```php
        <a href="/directiva/equipacion" class="<?= $activePage === 'directiva-equipacion' ? 'active' : '' ?>">
          <i class="bi bi-bag-check-fill"></i> Equipación
        </a>
```

- [ ] **Step 3: Add a socio dropdown link**

In the navbar user dropdown (inside `render_header()`, the block with `<a href="/directiva/cuestiones">...Cuestiones</a>`), add a link for non-admin users right after the existing `/socio/incidencias` link:

```php
                    <a href="/socio/equipacion" <?= $activePage === 'socio-equipacion' ? 'class="active"' : '' ?>><i class="bi bi-bag-check"></i> Equipación</a>
```

placed inside the `<?php if (!$isAdmin): ?> ... <?php endif; ?>` block that already contains the comunicaciones/incidencias links.

- [ ] **Step 4: Syntax-check**

Run: `docker compose exec -T app php -l /var/www/html/includes/layout.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Manual browser test**

As a socio, open the user dropdown menu (top-right avatar) and confirm "Equipación" appears and links to `/socio/equipacion`. As a directiva member or admin, visit any `/directiva/*` page and confirm "Equipación" appears in the sidebar and links to `/directiva/equipacion` with the active state highlighted when on that page.

- [ ] **Step 6: Commit**

```bash
git add includes/layout.php
git commit -m "feat(equipacion): enlaces de navegación en sidebar directiva/admin y menú socio"
```

---

### Task 12: Limpieza de datos de prueba y verificación final

**Files:**
- None (data-only, no code changes)

**Interfaces:**
- None — this task only removes the manual-test fixture seeded in Task 4 Step 3 and confirms the full flow works end-to-end once more with clean data.

- [ ] **Step 1: Remove the "Camiseta test" fixture and its test orders**

Run:
```bash
docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "
DELETE FROM equipacion_pedidos WHERE id IN (
  SELECT pedido_id FROM (
    SELECT l.pedido_id FROM equipacion_pedido_lineas l
    JOIN equipacion_variantes v ON v.id = l.variante_id
    JOIN equipacion_items i ON i.id = v.item_id
    WHERE i.nombre = 'Camiseta test'
  ) t
);
DELETE FROM equipacion_items WHERE nombre = 'Camiseta test';
"
```
Expected: no errors; cascading deletes remove the item's variants and any leftover order lines.

- [ ] **Step 2: Confirm clean state**

Run: `docker compose exec -T db mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SELECT COUNT(*) FROM equipacion_items; SELECT COUNT(*) FROM equipacion_pedidos;"`
Expected: both counts reflect only real catalog data added by directiva during testing (or `0` if none was added outside the fixture).

- [ ] **Step 3: Full end-to-end smoke test with real data**

As a directiva member: create one real item + talla with stock in `/directiva/equipacion`. As a socio: add it to cart at `/socio/equipacion`, pay via Stripe test card, confirm webhook flips it to `pagado` (Task 7's Stripe CLI listener should still be running), then as directiva mark it `entregado` in `/directiva/equipacion_pedidos`. Confirm the socio sees `Entregado` at `/socio/equipacion_pedidos`.

- [ ] **Step 4: Final check — no leftover PHP warnings/errors**

Run: `docker compose exec -T app tail -n 50 /var/www/html/logs/php_errors.log`
Expected: no new warnings/errors from the equipación pages during the smoke test above (pre-existing unrelated log lines are fine).
