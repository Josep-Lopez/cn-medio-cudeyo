# Entrenador: grupos de entrenamiento + competiciones con foro — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `entrenador` cargo two new capabilities inside `/admin/*`: (1) organize swimmers into training groups and list them, (2) log competition times with free-text splits and a private comment thread (entrenador↔swimmer) that swimmers can read/reply to from `/socio/*`.

**Architecture:** Two independent domain-helper files (`includes/grupos.php`, `includes/competicion_entrenador.php`) following the exact pattern already used by `includes/incidencias.php`. Pages live under `public/admin/*` (gated by the existing `require_admin_area(['director_tecnico','entrenador'])`) and `public/socio/*` (gated by `require_login()` + ownership check). The existing `render_admin_layout()` sidebar and `$isEntrenador` flag are extended, not replaced. Five new tables added via one migration file.

**Tech Stack:** PHP 8.4, PDO/MySQL 8, no framework, no automated test suite — this project verifies changes with `php -l` (syntax) plus manual browser/curl checks against the local Docker stack (`docker compose up -d`, app at `http://localhost:8080`).

## Global Constraints

- No automated test framework exists in this repo. Every task's "test" step is `php -l <file>` (must print "No syntax errors detected") followed by a manual verification against the running Docker stack. Do not introduce a test framework as part of this plan.
- Never use `confirm()`/`alert()` (browser dialogs) — this codebase uses custom HTML modals (see `public/admin/incidencias.php` delete-confirmation modal for the exact pattern). Every destructive action (delete group, delete competition) must use that modal pattern.
- Always use PDO prepared statements — no string-interpolated SQL with user input.
- Table names for this feature use the `competicion_entrenador*` prefix (`competicion_entrenador`, `competicion_entrenador_tiempos`, `competicion_entrenador_comentarios`) — **not** `competiciones`/`competicion_tiempos`/`competicion_comentarios`. The unmerged branch `feature/competiciones` already created tables named `competiciones` and `competicion_resultados` in the same dev database for an unrelated swimrankings-import feature; reusing those names would collide.
- Page URLs (`/admin/competiciones`, `/admin/competicion_tiempo`, `/socio/competiciones`, `/socio/competicion_tiempo`) are fine as-is — they live under `public/` in the main app, a different directory tree from the other feature's `competiciones/public/`.
- `require_admin_area(['director_tecnico', 'entrenador'])` gates all `/admin/grupos.php`, `/admin/competiciones.php`, `/admin/competicion_tiempo.php` pages — any of these three (or plain `admin`) can view and manage all groups/competitions. The "privado entrenador↔nadador" restriction from the spec applies **only** to the socio-side pages (`/socio/competicion_tiempo.php`), which check `puede_ver_tiempo_socio()`. This mirrors how `admin/incidencias.php` is unrestricted for staff while `socio/incidencias.php` is restricted to the owner.
- Reuse existing helpers, don't reinvent them: `e()`, `flash()`/`render_flash()`, `csrf_field()`/`csrf_verify()`, `tiempo_a_segundos()`, `format_prueba()`, `format_liga()`, `render_header()`/`render_footer()`, `render_admin_layout()` — all in `includes/auth.php` / `includes/layout.php`.
- Reference spec: `docs/superpowers/specs/2026-07-26-entrenador-grupos-competiciones-design.md`.

---

## Task 1: Migration — 5 new tables

**Files:**
- Create: `migrations/021_entrenador_grupos_competiciones.sql`

**Interfaces:**
- Produces: tables `grupos_entrenamiento(id, nombre, descripcion, creado_por, created_at)`, `grupo_nadadores(grupo_id, user_id, added_at)`, `competicion_entrenador(id, nombre, lugar, fecha, creado_por, created_at)`, `competicion_entrenador_tiempos(id, competicion_id, user_id, prueba, piscina, tiempo, tiempo_seg, parciales, registrado_por, created_at)`, `competicion_entrenador_comentarios(id, tiempo_id, user_id, contenido, created_at)`. All later tasks depend on these existing in the dev DB.

- [ ] **Step 1: Write the migration file**

```sql
-- 021: Grupos de entrenamiento + competiciones con parciales y foro (cargo entrenador)

CREATE TABLE IF NOT EXISTS grupos_entrenamiento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    creado_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grupo_nadadores (
    grupo_id INT NOT NULL,
    user_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (grupo_id, user_id),
    FOREIGN KEY (grupo_id) REFERENCES grupos_entrenamiento(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competicion_entrenador (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    lugar VARCHAR(255) DEFAULT NULL,
    fecha DATE NOT NULL,
    creado_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competicion_entrenador_tiempos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    competicion_id INT NOT NULL,
    user_id INT NOT NULL,
    prueba VARCHAR(10) NOT NULL,
    piscina ENUM('25m','50m') NOT NULL DEFAULT '25m',
    tiempo VARCHAR(20) NOT NULL,
    tiempo_seg FLOAT NOT NULL,
    parciales VARCHAR(255) DEFAULT NULL,
    registrado_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (competicion_id) REFERENCES competicion_entrenador(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (registrado_por) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competicion_entrenador_comentarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tiempo_id INT NOT NULL,
    user_id INT NOT NULL,
    contenido TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tiempo_id) REFERENCES competicion_entrenador_tiempos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tiempo (tiempo_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply the migration to the running dev database**

Run: `docker exec -i cn-medio-cudeyo-db-1 mysql -ucnuser -pcnpass123 cn_medio_cudeyo < migrations/021_entrenador_grupos_competiciones.sql`
Expected: no output, exit code 0.

- [ ] **Step 3: Verify the tables exist**

Run: `docker exec cn-medio-cudeyo-db-1 mysql -ucnuser -pcnpass123 cn_medio_cudeyo -e "SHOW TABLES LIKE '%grupo%'; SHOW TABLES LIKE 'competicion_entrenador%';"`
Expected: `grupos_entrenamiento`, `grupo_nadadores`, `competicion_entrenador`, `competicion_entrenador_tiempos`, `competicion_entrenador_comentarios` all listed.

- [ ] **Step 4: Commit**

```bash
git add migrations/021_entrenador_grupos_competiciones.sql
git commit -m "feat(db): tablas grupos de entrenamiento y competicion_entrenador"
```

---

## Task 2: `includes/grupos.php` — domain helpers

**Files:**
- Create: `includes/grupos.php`

**Interfaces:**
- Consumes: `$pdo` (PDO instance from `config/db.php`).
- Produces (consumed by Tasks 3 and 4): `listar_grupos(PDO $pdo): array`, `obtener_grupo(PDO $pdo, int $id): ?array`, `crear_grupo(PDO $pdo, string $nombre, ?string $descripcion, int $creado_por): int`, `actualizar_grupo(PDO $pdo, int $id, string $nombre, ?string $descripcion): void`, `eliminar_grupo(PDO $pdo, int $id): void`, `listar_nadadores_grupo(PDO $pdo, int $grupo_id): array`, `listar_socios_fuera_de_grupo(PDO $pdo, int $grupo_id): array`, `agregar_nadador_a_grupo(PDO $pdo, int $grupo_id, int $user_id): void`, `quitar_nadador_de_grupo(PDO $pdo, int $grupo_id, int $user_id): void`.

- [ ] **Step 1: Write `includes/grupos.php`**

```php
<?php
// Helpers de dominio para grupos de entrenamiento (cargo entrenador).
// Requiere $pdo (config/db.php).

function listar_grupos(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT g.*, COUNT(gn.user_id) AS num_nadadores
        FROM grupos_entrenamiento g
        LEFT JOIN grupo_nadadores gn ON gn.grupo_id = g.id
        GROUP BY g.id
        ORDER BY g.nombre
    ');
    return $stmt->fetchAll();
}

function obtener_grupo(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM grupos_entrenamiento WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function crear_grupo(PDO $pdo, string $nombre, ?string $descripcion, int $creado_por): int
{
    $nombre = trim($nombre);
    if ($nombre === '' || mb_strlen($nombre) > 100) {
        throw new InvalidArgumentException('Nombre de grupo inválido');
    }
    $desc = $descripcion !== null && trim($descripcion) !== '' ? trim($descripcion) : null;
    $stmt = $pdo->prepare('INSERT INTO grupos_entrenamiento (nombre, descripcion, creado_por) VALUES (?, ?, ?)');
    $stmt->execute([$nombre, $desc, $creado_por]);
    return (int)$pdo->lastInsertId();
}

function actualizar_grupo(PDO $pdo, int $id, string $nombre, ?string $descripcion): void
{
    $nombre = trim($nombre);
    if ($nombre === '' || mb_strlen($nombre) > 100) {
        throw new InvalidArgumentException('Nombre de grupo inválido');
    }
    $desc = $descripcion !== null && trim($descripcion) !== '' ? trim($descripcion) : null;
    $stmt = $pdo->prepare('UPDATE grupos_entrenamiento SET nombre = ?, descripcion = ? WHERE id = ?');
    $stmt->execute([$nombre, $desc, $id]);
}

function eliminar_grupo(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM grupos_entrenamiento WHERE id = ?')->execute([$id]);
}

function listar_nadadores_grupo(PDO $pdo, int $grupo_id): array
{
    $stmt = $pdo->prepare('
        SELECT u.id, u.nombre, u.liga, u.sexo
        FROM grupo_nadadores gn
        JOIN users u ON u.id = gn.user_id
        WHERE gn.grupo_id = ?
        ORDER BY u.nombre
    ');
    $stmt->execute([$grupo_id]);
    return $stmt->fetchAll();
}

function listar_socios_fuera_de_grupo(PDO $pdo, int $grupo_id): array
{
    $stmt = $pdo->prepare("
        SELECT id, nombre, liga
        FROM users
        WHERE estado = 'activo' AND rol = 'socio'
          AND id NOT IN (SELECT user_id FROM grupo_nadadores WHERE grupo_id = ?)
        ORDER BY nombre
    ");
    $stmt->execute([$grupo_id]);
    return $stmt->fetchAll();
}

function agregar_nadador_a_grupo(PDO $pdo, int $grupo_id, int $user_id): void
{
    $pdo->prepare('INSERT IGNORE INTO grupo_nadadores (grupo_id, user_id) VALUES (?, ?)')
        ->execute([$grupo_id, $user_id]);
}

function quitar_nadador_de_grupo(PDO $pdo, int $grupo_id, int $user_id): void
{
    $pdo->prepare('DELETE FROM grupo_nadadores WHERE grupo_id = ? AND user_id = ?')
        ->execute([$grupo_id, $user_id]);
}
```

- [ ] **Step 2: Lint**

Run: `docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/includes/grupos.php`
Expected: `No syntax errors detected in /var/www/html/includes/grupos.php`

- [ ] **Step 3: Commit**

```bash
git add includes/grupos.php
git commit -m "feat(grupos): helpers de dominio para grupos de entrenamiento"
```

---

## Task 3: `public/admin/grupos.php` + sidebar entry

**Files:**
- Create: `public/admin/grupos.php`
- Modify: `includes/layout.php` (`render_admin_layout()`, inside the existing `if ($isEntrenador): ... endif;` block, right after the "Historial asistencia" link — see lines ~364-371)

**Interfaces:**
- Consumes: everything from Task 2 (`includes/grupos.php`), plus `require_admin_area()`, `csrf_field()`, `csrf_verify()`, `flash()`, `render_flash()`, `format_liga()`, `render_admin_layout()`, `render_header()`, `render_footer()`, `e()` from `includes/auth.php`/`includes/layout.php`.
- Produces: route `/admin/grupos` — list, create (`?accion=nuevo`), view+manage (`?ver=ID`).

- [ ] **Step 1: Write `public/admin/grupos.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/grupos.php';

require_admin_area(['director_tecnico', 'entrenador']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        try {
            $id = crear_grupo($pdo, $_POST['nombre'] ?? '', $_POST['descripcion'] ?? null, (int)current_user()['id']);
            flash('Grupo creado.', 'success');
            header('Location: /admin/grupos?ver=' . $id);
            exit;
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
            header('Location: /admin/grupos?accion=nuevo');
            exit;
        }
    }

    if ($accion === 'editar') {
        $id = (int)$_POST['id'];
        try {
            actualizar_grupo($pdo, $id, $_POST['nombre'] ?? '', $_POST['descripcion'] ?? null);
            flash('Grupo actualizado.', 'success');
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
        }
        header('Location: /admin/grupos?ver=' . $id);
        exit;
    }

    if ($accion === 'eliminar') {
        eliminar_grupo($pdo, (int)$_POST['id']);
        flash('Grupo eliminado.', 'success');
        header('Location: /admin/grupos');
        exit;
    }

    if ($accion === 'agregar_nadador') {
        $id = (int)$_POST['id'];
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) agregar_nadador_a_grupo($pdo, $id, $uid);
        header('Location: /admin/grupos?ver=' . $id);
        exit;
    }

    if ($accion === 'quitar_nadador') {
        $id = (int)$_POST['id'];
        quitar_nadador_de_grupo($pdo, $id, (int)$_POST['user_id']);
        header('Location: /admin/grupos?ver=' . $id);
        exit;
    }
}

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$grupos = listar_grupos($pdo);

render_header('Grupos de entrenamiento', 'admin-grupos');
render_admin_layout('grupos', function() use ($accion, $verId, $grupos, $pdo) {

    if ($accion === 'nuevo') {
?>
<h1>Nuevo grupo de entrenamiento</h1>
<?php render_flash(); ?>
<form method="POST" action="/admin/grupos" class="card" style="padding:24px;max-width:560px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="crear">
  <div class="form-group">
    <label class="form-label">Nombre *</label>
    <input type="text" name="nombre" class="form-control" required maxlength="100">
  </div>
  <div class="form-group">
    <label class="form-label">Descripción</label>
    <textarea name="descripcion" class="form-control" rows="3"></textarea>
  </div>
  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary">Crear</button>
    <a href="/admin/grupos" class="btn btn-gray">Cancelar</a>
  </div>
</form>
<?php
        return;
    }

    if ($verId > 0) {
        $grupo = obtener_grupo($pdo, $verId);
        if (!$grupo) {
            echo '<div class="card" style="padding:24px;">Grupo no encontrado. <a href="/admin/grupos">Volver</a></div>';
            return;
        }
        $nadadores = listar_nadadores_grupo($pdo, $verId);
        $disponibles = listar_socios_fuera_de_grupo($pdo, $verId);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;"><?= e($grupo['nombre']) ?></h1>
  <a href="/admin/grupos" class="btn btn-gray btn-sm">← Volver al listado</a>
</div>
<?php render_flash(); ?>

<div class="card mb-6" style="padding:24px;">
  <form method="POST" action="/admin/grupos">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="editar">
    <input type="hidden" name="id" value="<?= (int)$grupo['id'] ?>">
    <div class="form-group">
      <label class="form-label">Nombre</label>
      <input type="text" name="nombre" class="form-control" value="<?= e($grupo['nombre']) ?>" required maxlength="100">
    </div>
    <div class="form-group">
      <label class="form-label">Descripción</label>
      <textarea name="descripcion" class="form-control" rows="3"><?= e($grupo['descripcion'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Guardar cambios</button>
  </form>
</div>

<div class="card mb-6" style="padding:24px;">
  <h3>Nadadores (<?= count($nadadores) ?>)</h3>
  <?php if ($nadadores): ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Nombre</th><th>Categoría</th><th>Sexo</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($nadadores as $n): ?>
            <tr>
              <td><?= e($n['nombre']) ?></td>
              <td><?= e(format_liga($n['liga'] ?? '')) ?></td>
              <td><?= $n['sexo'] === 'F' ? 'Chica' : 'Chico' ?></td>
              <td>
                <form method="POST" action="/admin/grupos">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="quitar_nadador">
                  <input type="hidden" name="id" value="<?= (int)$grupo['id'] ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$n['id'] ?>">
                  <button type="submit" class="btn btn-gray btn-sm">Quitar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted">Sin nadadores asignados todavía.</p>
  <?php endif; ?>

  <?php if ($disponibles): ?>
    <form method="POST" action="/admin/grupos" style="margin-top:16px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="agregar_nadador">
      <input type="hidden" name="id" value="<?= (int)$grupo['id'] ?>">
      <div class="form-group" style="margin:0;flex:1;min-width:220px;">
        <label class="form-label">Añadir nadador</label>
        <select name="user_id" class="form-control" required>
          <option value="">— Selecciona —</option>
          <?php foreach ($disponibles as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= e($d['nombre']) ?> (<?= e(format_liga($d['liga'] ?? '')) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Añadir</button>
    </form>
  <?php endif; ?>
</div>

<div style="text-align:right;">
  <button type="button" class="btn btn-sm" onclick="document.getElementById('modal-eliminar-grupo').style.display='flex'" style="background:#dc2626;color:white;">
    <i class="bi bi-trash"></i> Eliminar grupo
  </button>
</div>

<div id="modal-eliminar-grupo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:8px;max-width:420px;width:90%;">
    <h3 style="margin:0 0 12px;">¿Eliminar grupo?</h3>
    <p>Se quitará la asignación de todos los nadadores. Esta acción no se puede deshacer.</p>
    <form method="POST" action="/admin/grupos">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" value="<?= (int)$grupo['id'] ?>">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modal-eliminar-grupo').style.display='none'">Cancelar</button>
        <button type="submit" class="btn" style="background:#dc2626;color:white;">Eliminar</button>
      </div>
    </form>
  </div>
</div>
<?php
        return;
    }
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;">Grupos de entrenamiento</h1>
  <a href="/admin/grupos?accion=nuevo" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo grupo</a>
</div>
<?php render_flash(); ?>

<?php if (!$grupos): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay grupos de entrenamiento todavía.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Nombre</th><th>Descripción</th><th>Nadadores</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($grupos as $g): ?>
            <tr>
              <td style="font-weight:600;"><?= e($g['nombre']) ?></td>
              <td class="text-muted"><?= e($g['descripcion'] ?? '—') ?></td>
              <td><?= (int)$g['num_nadadores'] ?></td>
              <td><a href="/admin/grupos?ver=<?= (int)$g['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
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
```

- [ ] **Step 2: Add sidebar entry in `includes/layout.php`**

Find this existing block inside `render_admin_layout()` (around line 364):

```php
        <?php if ($isEntrenador): ?>
        <a href="/admin/asistencia" class="<?= $activePage === 'asistencia' ? 'active' : '' ?>">
          <i class="bi bi-clipboard-check-fill"></i> Pasar lista
        </a>
        <a href="/admin/asistencia_historial" class="<?= $activePage === 'asistencia_historial' ? 'active' : '' ?>">
          <i class="bi bi-calendar-check"></i> Historial asistencia
        </a>
        <?php endif; ?>
```

Replace it with:

```php
        <?php if ($isEntrenador): ?>
        <a href="/admin/asistencia" class="<?= $activePage === 'asistencia' ? 'active' : '' ?>">
          <i class="bi bi-clipboard-check-fill"></i> Pasar lista
        </a>
        <a href="/admin/asistencia_historial" class="<?= $activePage === 'asistencia_historial' ? 'active' : '' ?>">
          <i class="bi bi-calendar-check"></i> Historial asistencia
        </a>
        <a href="/admin/grupos" class="<?= $activePage === 'grupos' ? 'active' : '' ?>">
          <i class="bi bi-diagram-3-fill"></i> Grupos
        </a>
        <a href="/admin/competiciones" class="<?= $activePage === 'competiciones' ? 'active' : '' ?>">
          <i class="bi bi-list-ol"></i> Competiciones
        </a>
        <?php endif; ?>
```

- [ ] **Step 3: Lint both files**

Run: `docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/public/admin/grupos.php && docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/includes/layout.php`
Expected: both print "No syntax errors detected".

- [ ] **Step 4: Manual verification**

Prerequisite (one-time, if not already done): log in as admin (`admin@cnmediocudeyo.es`), go to `/admin/cargos`, assign the `entrenador` cargo to a test socio.

1. Log in as that entrenador. Confirm the admin sidebar shows "Grupos" and "Competiciones" under the same section as "Pasar lista".
2. Visit `/admin/grupos` — should show "No hay grupos de entrenamiento todavía."
3. Click "Nuevo grupo", create one (e.g. "Junior A"). Should redirect to its detail page.
4. Add a nadador from the dropdown, confirm it appears in the table. Click "Quitar", confirm it disappears and reappears in the dropdown.
5. Click "Eliminar grupo" → confirm the custom modal appears (not a browser `confirm()`), confirm deletion redirects to `/admin/grupos` with the group gone.

- [ ] **Step 5: Commit**

```bash
git add public/admin/grupos.php includes/layout.php
git commit -m "feat(entrenador): página de gestión de grupos de entrenamiento"
```

---

## Task 4: Filtro por grupo en `/admin/asistencia.php`

**Files:**
- Modify: `public/admin/asistencia.php`

**Interfaces:**
- Consumes: `listar_grupos(PDO $pdo): array` from Task 2 (`includes/grupos.php`).

- [ ] **Step 1: Add the `includes/grupos.php` require and load groups**

In `public/admin/asistencia.php`, after the existing requires (line 4), add:

```php
require_once dirname(__DIR__, 2) . '/includes/grupos.php';
```

- [ ] **Step 2: Add the grupo filter to the query**

Replace the filters block:

```php
// ── Filtros ──────────────────────────────────────────────────────────────────
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$filtroLiga = $_GET['liga'] ?? 'todos';
if ($filtroLiga !== 'todos' && !array_key_exists($filtroLiga, $LIGAS)) $filtroLiga = 'todos';

// Cargar nadadores activos (solo si hay categoría seleccionada o "todos")
$where = "estado='activo' AND rol='socio' AND nadador_activo=1";
$params = [];
if ($filtroLiga && $filtroLiga !== 'todos') {
    $where .= ' AND liga=?';
    $params[] = $filtroLiga;
}
$stmt = $pdo->prepare("SELECT id, nombre, liga, sexo FROM users WHERE $where ORDER BY nombre");
$stmt->execute($params);
$nadadores = $stmt->fetchAll();
```

with:

```php
// ── Filtros ──────────────────────────────────────────────────────────────────
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$filtroLiga = $_GET['liga'] ?? 'todos';
if ($filtroLiga !== 'todos' && !array_key_exists($filtroLiga, $LIGAS)) $filtroLiga = 'todos';
$filtroGrupo = isset($_GET['grupo']) ? (int)$_GET['grupo'] : 0;
$grupos = listar_grupos($pdo);

// Cargar nadadores activos (solo si hay categoría seleccionada o "todos")
$where = "estado='activo' AND rol='socio' AND nadador_activo=1";
$params = [];
if ($filtroLiga && $filtroLiga !== 'todos') {
    $where .= ' AND liga=?';
    $params[] = $filtroLiga;
}
if ($filtroGrupo > 0) {
    $where .= ' AND id IN (SELECT user_id FROM grupo_nadadores WHERE grupo_id=?)';
    $params[] = $filtroGrupo;
}
$stmt = $pdo->prepare("SELECT id, nombre, liga, sexo FROM users WHERE $where ORDER BY nombre");
$stmt->execute($params);
$nadadores = $stmt->fetchAll();
```

- [ ] **Step 3: Pass `$grupos`/`$filtroGrupo` into the render closure and add the dropdown**

Replace:

```php
render_admin_layout('asistencia', function() use ($LIGAS, $nadadores, $registros, $fecha, $filtroLiga, $total, $presentes) {
```

with:

```php
render_admin_layout('asistencia', function() use ($LIGAS, $grupos, $nadadores, $registros, $fecha, $filtroLiga, $filtroGrupo, $total, $presentes) {
```

Then, right after the "Categoría" filter block:

```php
    <div class="form-group" style="margin:0;">
      <label class="form-label">Categoría</label>
      <select class="form-control" style="width:auto;min-width:160px;"
              onchange="window.location='?fecha=<?= e($fecha) ?>&liga='+this.value+'&grupo=<?= (int)$filtroGrupo ?>'">
        <option value="todos" <?= $filtroLiga === 'todos' ? 'selected' : '' ?>>Todos</option>
        <?php foreach ($LIGAS as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroLiga === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
```

add:

```php
    <div class="form-group" style="margin:0;">
      <label class="form-label">Grupo</label>
      <select class="form-control" style="width:auto;min-width:160px;"
              onchange="window.location='?fecha=<?= e($fecha) ?>&liga=<?= e($filtroLiga) ?>&grupo='+this.value">
        <option value="0">Todos</option>
        <?php foreach ($grupos as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $filtroGrupo === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
```

Finally, update the two `liga=` URL builders that need to also carry `grupo`: the date input's `onchange` and the POST redirect. Replace:

```php
    <input type="date" class="form-control" value="<?= e($fecha) ?>"
             onchange="window.location='?fecha='+this.value+'&liga=<?= e($filtroLiga) ?>'"
             style="width:auto;">
```

with:

```php
    <input type="date" class="form-control" value="<?= e($fecha) ?>"
             onchange="window.location='?fecha='+this.value+'&liga=<?= e($filtroLiga) ?>&grupo=<?= (int)$filtroGrupo ?>'"
             style="width:auto;">
```

And the redirect after saving attendance — replace:

```php
    flash('Asistencia guardada correctamente.', 'success');
    header('Location: /admin/asistencia?fecha=' . urlencode($fecha) . '&liga=' . urlencode($_POST['liga_back'] ?? ''));
    exit;
```

with:

```php
    flash('Asistencia guardada correctamente.', 'success');
    header('Location: /admin/asistencia?fecha=' . urlencode($fecha) . '&liga=' . urlencode($_POST['liga_back'] ?? '') . '&grupo=' . (int)($_POST['grupo_back'] ?? 0));
    exit;
```

and add a matching hidden field next to the existing `liga_back` one in the form:

```php
  <input type="hidden" name="liga_back" value="<?= e($filtroLiga) ?>">
```

becomes:

```php
  <input type="hidden" name="liga_back" value="<?= e($filtroLiga) ?>">
  <input type="hidden" name="grupo_back" value="<?= (int)$filtroGrupo ?>">
```

- [ ] **Step 4: Lint**

Run: `docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/public/admin/asistencia.php`
Expected: "No syntax errors detected".

- [ ] **Step 5: Manual verification**

1. As the entrenador test user, create a group with 2 swimmers (Task 3), leave a 3rd swimmer unassigned.
2. Visit `/admin/asistencia`, select that group in the new "Grupo" dropdown — only the 2 assigned swimmers should appear.
3. Mark attendance, save — confirm the grupo filter is preserved after redirect (dropdown still shows the selected group).

- [ ] **Step 6: Commit**

```bash
git add public/admin/asistencia.php
git commit -m "feat(asistencia): filtro por grupo de entrenamiento"
```

---

## Task 5: `includes/competicion_entrenador.php` — domain helpers

**Files:**
- Create: `includes/competicion_entrenador.php`

**Interfaces:**
- Consumes: `$pdo`, `tiempo_a_segundos(string $tiempo): float` (from `includes/auth.php`).
- Produces (consumed by Tasks 6, 7, 8, 9): constants `CE_PRUEBAS` (array of 18 event codes), `CE_PISCINAS` (`['25m','50m']`); functions `listar_competiciones_entrenador(PDO $pdo): array`, `obtener_competicion_entrenador(PDO $pdo, int $id): ?array`, `crear_competicion_entrenador(PDO $pdo, string $nombre, ?string $lugar, string $fecha, int $creado_por): int`, `eliminar_competicion_entrenador(PDO $pdo, int $id): void`, `listar_tiempos_competicion(PDO $pdo, int $competicion_id): array`, `obtener_tiempo_entrenador(PDO $pdo, int $id): ?array`, `agregar_tiempo_entrenador(PDO $pdo, array $data): int`, `listar_tiempos_socio(PDO $pdo, int $user_id): array`, `puede_ver_tiempo_socio(array $tiempo, array $user): bool`, `listar_comentarios_tiempo(PDO $pdo, int $tiempo_id): array`, `agregar_comentario_tiempo(PDO $pdo, int $tiempo_id, int $user_id, string $contenido): void`.

- [ ] **Step 1: Write `includes/competicion_entrenador.php`**

```php
<?php
// Helpers de dominio para competiciones registradas por el entrenador
// (independiente del ranking oficial en la tabla marcas).
// Requiere $pdo (config/db.php) y tiempo_a_segundos() de includes/auth.php.

const CE_PRUEBAS = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];
const CE_PISCINAS = ['25m','50m'];

function listar_competiciones_entrenador(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT c.*, COUNT(t.id) AS num_tiempos
        FROM competicion_entrenador c
        LEFT JOIN competicion_entrenador_tiempos t ON t.competicion_id = c.id
        GROUP BY c.id
        ORDER BY c.fecha DESC, c.id DESC
    ');
    return $stmt->fetchAll();
}

function obtener_competicion_entrenador(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM competicion_entrenador WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function crear_competicion_entrenador(PDO $pdo, string $nombre, ?string $lugar, string $fecha, int $creado_por): int
{
    $nombre = trim($nombre);
    if ($nombre === '' || mb_strlen($nombre) > 150) {
        throw new InvalidArgumentException('Nombre de competición inválido');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        throw new InvalidArgumentException('Fecha inválida');
    }
    $lugarVal = $lugar !== null && trim($lugar) !== '' ? trim($lugar) : null;
    $stmt = $pdo->prepare('INSERT INTO competicion_entrenador (nombre, lugar, fecha, creado_por) VALUES (?, ?, ?, ?)');
    $stmt->execute([$nombre, $lugarVal, $fecha, $creado_por]);
    return (int)$pdo->lastInsertId();
}

function eliminar_competicion_entrenador(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM competicion_entrenador WHERE id = ?')->execute([$id]);
}

function listar_tiempos_competicion(PDO $pdo, int $competicion_id): array
{
    $stmt = $pdo->prepare('
        SELECT t.*, u.nombre AS nadador_nombre
        FROM competicion_entrenador_tiempos t
        JOIN users u ON u.id = t.user_id
        WHERE t.competicion_id = ?
        ORDER BY u.nombre, t.prueba
    ');
    $stmt->execute([$competicion_id]);
    return $stmt->fetchAll();
}

function obtener_tiempo_entrenador(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT t.*, u.nombre AS nadador_nombre, c.nombre AS competicion_nombre, c.fecha AS competicion_fecha
        FROM competicion_entrenador_tiempos t
        JOIN users u ON u.id = t.user_id
        JOIN competicion_entrenador c ON c.id = t.competicion_id
        WHERE t.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function agregar_tiempo_entrenador(PDO $pdo, array $data): int
{
    if (empty($data['competicion_id']) || !obtener_competicion_entrenador($pdo, (int)$data['competicion_id'])) {
        throw new InvalidArgumentException('Competición no válida');
    }
    if (empty($data['user_id'])) {
        throw new InvalidArgumentException('Nadador no válido');
    }
    if (!in_array($data['prueba'] ?? '', CE_PRUEBAS, true)) {
        throw new InvalidArgumentException('Prueba no válida');
    }
    if (!in_array($data['piscina'] ?? '', CE_PISCINAS, true)) {
        throw new InvalidArgumentException('Piscina no válida');
    }
    $tiempoStr = trim($data['tiempo'] ?? '');
    $secs = tiempo_a_segundos($tiempoStr);
    if ($secs <= 0) {
        throw new InvalidArgumentException('Formato de tiempo incorrecto. Usa mm:ss.cc o ss.cc');
    }
    $parciales = trim($data['parciales'] ?? '');
    $stmt = $pdo->prepare('
        INSERT INTO competicion_entrenador_tiempos
            (competicion_id, user_id, prueba, piscina, tiempo, tiempo_seg, parciales, registrado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        (int)$data['competicion_id'],
        (int)$data['user_id'],
        $data['prueba'],
        $data['piscina'],
        $tiempoStr,
        $secs,
        $parciales !== '' ? $parciales : null,
        (int)$data['registrado_por'],
    ]);
    return (int)$pdo->lastInsertId();
}

function listar_tiempos_socio(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare('
        SELECT t.*, c.nombre AS competicion_nombre, c.fecha AS competicion_fecha
        FROM competicion_entrenador_tiempos t
        JOIN competicion_entrenador c ON c.id = t.competicion_id
        WHERE t.user_id = ?
        ORDER BY c.fecha DESC, t.id DESC
    ');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function puede_ver_tiempo_socio(array $tiempo, array $user): bool
{
    if (($user['rol'] ?? '') === 'admin') return true;
    return (int)$tiempo['user_id'] === (int)$user['id'];
}

function listar_comentarios_tiempo(PDO $pdo, int $tiempo_id): array
{
    $stmt = $pdo->prepare('
        SELECT c.*, u.nombre AS autor_nombre, u.rol AS autor_rol
        FROM competicion_entrenador_comentarios c
        JOIN users u ON u.id = c.user_id
        WHERE c.tiempo_id = ?
        ORDER BY c.created_at ASC
    ');
    $stmt->execute([$tiempo_id]);
    return $stmt->fetchAll();
}

function agregar_comentario_tiempo(PDO $pdo, int $tiempo_id, int $user_id, string $contenido): void
{
    $contenido = trim($contenido);
    if ($contenido === '') throw new InvalidArgumentException('Comentario vacío');
    $pdo->prepare('INSERT INTO competicion_entrenador_comentarios (tiempo_id, user_id, contenido) VALUES (?, ?, ?)')
        ->execute([$tiempo_id, $user_id, $contenido]);
}
```

- [ ] **Step 2: Lint**

Run: `docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/includes/competicion_entrenador.php`
Expected: "No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add includes/competicion_entrenador.php
git commit -m "feat(competiciones): helpers de dominio para tiempos de competición del entrenador"
```

---

## Task 6: `public/admin/competiciones.php`

**Files:**
- Create: `public/admin/competiciones.php`

**Interfaces:**
- Consumes: everything from Task 5, plus `format_prueba()` from `includes/auth.php`.
- Produces: route `/admin/competiciones` — list, create (`?accion=nueva`), view+add-time (`?ver=ID`). Links to `/admin/competicion_tiempo?id=X` (built in Task 7).

- [ ] **Step 1: Write `public/admin/competiciones.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';

require_admin_area(['director_tecnico', 'entrenador']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        try {
            $id = crear_competicion_entrenador($pdo, $_POST['nombre'] ?? '', $_POST['lugar'] ?? null, $_POST['fecha'] ?? '', (int)current_user()['id']);
            flash('Competición creada.', 'success');
            header('Location: /admin/competiciones?ver=' . $id);
            exit;
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
            header('Location: /admin/competiciones?accion=nueva');
            exit;
        }
    }

    if ($accion === 'eliminar') {
        eliminar_competicion_entrenador($pdo, (int)$_POST['id']);
        flash('Competición eliminada.', 'success');
        header('Location: /admin/competiciones');
        exit;
    }

    if ($accion === 'agregar_tiempo') {
        $competicionId = (int)$_POST['competicion_id'];
        try {
            agregar_tiempo_entrenador($pdo, [
                'competicion_id' => $competicionId,
                'user_id' => $_POST['user_id'] ?? '',
                'prueba' => $_POST['prueba'] ?? '',
                'piscina' => $_POST['piscina'] ?? '',
                'tiempo' => $_POST['tiempo'] ?? '',
                'parciales' => $_POST['parciales'] ?? '',
                'registrado_por' => current_user()['id'],
            ]);
            flash('Tiempo añadido.', 'success');
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
        }
        header('Location: /admin/competiciones?ver=' . $competicionId);
        exit;
    }
}

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$competiciones = listar_competiciones_entrenador($pdo);

render_header('Competiciones', 'admin-competiciones');
render_admin_layout('competiciones', function() use ($accion, $verId, $competiciones, $pdo) {

    if ($accion === 'nueva') {
        $hoy = date('Y-m-d');
?>
<h1>Nueva competición</h1>
<?php render_flash(); ?>
<form method="POST" action="/admin/competiciones" class="card" style="padding:24px;max-width:560px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="crear">
  <div class="form-group">
    <label class="form-label">Nombre *</label>
    <input type="text" name="nombre" class="form-control" required maxlength="150">
  </div>
  <div class="form-group">
    <label class="form-label">Lugar</label>
    <input type="text" name="lugar" class="form-control" maxlength="255">
  </div>
  <div class="form-group">
    <label class="form-label">Fecha *</label>
    <input type="date" name="fecha" class="form-control" required value="<?= $hoy ?>">
  </div>
  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary">Crear</button>
    <a href="/admin/competiciones" class="btn btn-gray">Cancelar</a>
  </div>
</form>
<?php
        return;
    }

    if ($verId > 0) {
        $comp = obtener_competicion_entrenador($pdo, $verId);
        if (!$comp) {
            echo '<div class="card" style="padding:24px;">Competición no encontrada. <a href="/admin/competiciones">Volver</a></div>';
            return;
        }
        $tiempos = listar_tiempos_competicion($pdo, $verId);
        $sociosStmt = $pdo->query("SELECT id, nombre FROM users WHERE rol='socio' AND estado='activo' ORDER BY nombre");
        $socios = $sociosStmt->fetchAll();
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;"><?= e($comp['nombre']) ?></h1>
  <a href="/admin/competiciones" class="btn btn-gray btn-sm">← Volver al listado</a>
</div>
<?php render_flash(); ?>

<div class="card mb-6" style="padding:24px;">
  <div class="text-muted text-sm">
    <?= date('d/m/Y', strtotime($comp['fecha'])) ?>
    <?php if ($comp['lugar']): ?> · <?= e($comp['lugar']) ?><?php endif; ?>
  </div>
</div>

<div class="card mb-6" style="padding:24px;">
  <h3>Tiempos registrados (<?= count($tiempos) ?>)</h3>
  <?php if ($tiempos): ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Nadador</th><th>Prueba</th><th>Piscina</th><th>Tiempo</th><th>Parciales</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($tiempos as $t): ?>
            <tr>
              <td><?= e($t['nadador_nombre']) ?></td>
              <td><?= e(format_prueba($t['prueba'])) ?></td>
              <td><?= e($t['piscina']) ?></td>
              <td style="font-weight:600;"><?= e($t['tiempo']) ?></td>
              <td class="text-muted text-sm"><?= e($t['parciales'] ?? '—') ?></td>
              <td><a href="/admin/competicion_tiempo?id=<?= (int)$t['id'] ?>" class="btn btn-gray btn-sm">Ver / comentar</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted">Sin tiempos registrados todavía.</p>
  <?php endif; ?>

  <form method="POST" action="/admin/competiciones" style="margin-top:20px;border-top:1px solid #eee;padding-top:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="agregar_tiempo">
    <input type="hidden" name="competicion_id" value="<?= (int)$comp['id'] ?>">
    <div class="d-flex gap-3 flex-wrap">
      <div class="form-group" style="flex:2;min-width:200px;">
        <label class="form-label">Nadador *</label>
        <select name="user_id" class="form-control" required>
          <option value="">— Selecciona —</option>
          <?php foreach ($socios as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:140px;">
        <label class="form-label">Prueba *</label>
        <select name="prueba" class="form-control" required>
          <?php foreach (CE_PRUEBAS as $p): ?>
            <option value="<?= $p ?>"><?= format_prueba($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:100px;">
        <label class="form-label">Piscina *</label>
        <select name="piscina" class="form-control" required>
          <?php foreach (CE_PISCINAS as $p): ?>
            <option value="<?= $p ?>"><?= $p ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:120px;">
        <label class="form-label">Tiempo *</label>
        <input type="text" name="tiempo" class="form-control" placeholder="1:05.43" required>
      </div>
      <div class="form-group" style="flex:2;min-width:200px;">
        <label class="form-label">Parciales</label>
        <input type="text" name="parciales" class="form-control" placeholder="28.5 / 1:01.2 / ...">
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Añadir tiempo</button>
  </form>
</div>

<div style="text-align:right;">
  <button type="button" class="btn btn-sm" onclick="document.getElementById('modal-eliminar-comp').style.display='flex'" style="background:#dc2626;color:white;">
    <i class="bi bi-trash"></i> Eliminar competición
  </button>
</div>

<div id="modal-eliminar-comp" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:8px;max-width:420px;width:90%;">
    <h3 style="margin:0 0 12px;">¿Eliminar competición?</h3>
    <p>Se eliminarán también todos los tiempos y comentarios asociados. Esta acción no se puede deshacer.</p>
    <form method="POST" action="/admin/competiciones">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" value="<?= (int)$comp['id'] ?>">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modal-eliminar-comp').style.display='none'">Cancelar</button>
        <button type="submit" class="btn" style="background:#dc2626;color:white;">Eliminar</button>
      </div>
    </form>
  </div>
</div>
<?php
        return;
    }
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;">Competiciones</h1>
  <a href="/admin/competiciones?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva competición</a>
</div>
<?php render_flash(); ?>

<?php if (!$competiciones): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay competiciones registradas todavía.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Fecha</th><th>Nombre</th><th>Lugar</th><th>Tiempos</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($competiciones as $c): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
              <td style="font-weight:600;"><?= e($c['nombre']) ?></td>
              <td class="text-muted"><?= e($c['lugar'] ?? '—') ?></td>
              <td><?= (int)$c['num_tiempos'] ?></td>
              <td><a href="/admin/competiciones?ver=<?= (int)$c['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
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
```

- [ ] **Step 2: Lint**

Run: `docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/public/admin/competiciones.php`
Expected: "No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add public/admin/competiciones.php
git commit -m "feat(entrenador): página de gestión de competiciones y tiempos"
```

---

## Task 7: `public/admin/competicion_tiempo.php` — detail + comment thread (staff side)

**Files:**
- Create: `public/admin/competicion_tiempo.php`

**Interfaces:**
- Consumes: `obtener_tiempo_entrenador()`, `listar_comentarios_tiempo()`, `agregar_comentario_tiempo()`, `format_prueba()` from Task 5.
- Produces: route `/admin/competicion_tiempo?id=X`, linked from Task 6.

- [ ] **Step 1: Write `public/admin/competicion_tiempo.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';

require_admin_area(['director_tecnico', 'entrenador']);

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'comentar') {
    csrf_verify();
    try {
        agregar_comentario_tiempo($pdo, $id, (int)current_user()['id'], $_POST['contenido'] ?? '');
        flash('Comentario añadido.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/competicion_tiempo?id=' . $id);
    exit;
}

$tiempo = obtener_tiempo_entrenador($pdo, $id);

render_header('Tiempo de competición', 'admin-competiciones');
render_admin_layout('competiciones', function() use ($tiempo, $id, $pdo) {
    if (!$tiempo) {
        echo '<div class="card" style="padding:24px;">Tiempo no encontrado. <a href="/admin/competiciones">Volver</a></div>';
        return;
    }
    $comentarios = listar_comentarios_tiempo($pdo, $id);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;"><?= e($tiempo['nadador_nombre']) ?> — <?= e(format_prueba($tiempo['prueba'])) ?></h1>
  <a href="/admin/competiciones?ver=<?= (int)$tiempo['competicion_id'] ?>" class="btn btn-gray btn-sm">← Volver a la competición</a>
</div>
<?php render_flash(); ?>

<div class="card mb-6" style="padding:24px;">
  <div class="text-muted text-sm" style="margin-bottom:8px;">
    <?= e($tiempo['competicion_nombre']) ?> · <?= date('d/m/Y', strtotime($tiempo['competicion_fecha'])) ?>
  </div>
  <div style="font-size:28px;font-weight:800;color:var(--blue);"><?= e($tiempo['tiempo']) ?></div>
  <div class="text-muted text-sm">Piscina <?= e($tiempo['piscina']) ?></div>
  <?php if ($tiempo['parciales']): ?>
    <div style="margin-top:12px;">
      <strong>Parciales:</strong>
      <div style="white-space:pre-wrap;"><?= e($tiempo['parciales']) ?></div>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="padding:24px;">
  <h3>Comentarios (<?= count($comentarios) ?>)</h3>
  <?php if ($comentarios): ?>
    <?php foreach ($comentarios as $c): ?>
      <div style="border-left:3px solid var(--blue);padding:8px 12px;margin-bottom:12px;background:#f9fafb;">
        <div class="text-sm">
          <strong><?= e($c['autor_nombre']) ?></strong>
          <span class="text-muted">· <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></span>
        </div>
        <div style="white-space:pre-wrap;margin-top:6px;"><?= e($c['contenido']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="text-muted">Sin comentarios.</p>
  <?php endif; ?>

  <form method="POST" action="/admin/competicion_tiempo?id=<?= (int)$tiempo['id'] ?>" style="margin-top:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="comentar">
    <div class="form-group">
      <textarea name="contenido" class="form-control" rows="3" placeholder="Escribe un comentario…" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Comentar</button>
  </form>
</div>
<?php
});
render_footer();
```

- [ ] **Step 2: Lint**

Run: `docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/public/admin/competicion_tiempo.php`
Expected: "No syntax errors detected".

- [ ] **Step 3: Manual verification (Tasks 6+7 together)**

1. As entrenador, `/admin/competiciones?accion=nueva`, create "Campeonato Regional" dated today.
2. Inside it, add a time for a swimmer: prueba `100L`, piscina `25m`, tiempo `1:05.43`, parciales `28.5 / 1:01.2`.
3. Confirm the row appears in the table with the formatted prueba name ("100 Libre") and the parciales text.
4. Click "Ver / comentar", confirm the detail page shows the time, piscina, parciales, and an empty comment thread.
5. Post a comment, confirm it appears with your name and timestamp.
6. Try submitting the "Añadir tiempo" form with an invalid time (e.g. `abc`) — confirm you get a flash error "Formato de tiempo incorrecto..." and no row is inserted.

- [ ] **Step 4: Commit**

```bash
git add public/admin/competicion_tiempo.php
git commit -m "feat(entrenador): detalle de tiempo de competición con hilo de comentarios"
```

---

## Task 8: `public/socio/competiciones.php` — swimmer's own list

**Files:**
- Create: `public/socio/competiciones.php`
- Modify: `includes/layout.php` (navbar user dropdown, around line 110, and `render_footer()`'s "Socios" footer column is untouched — only the dropdown menu gets the new link)

**Interfaces:**
- Consumes: `listar_tiempos_socio(PDO $pdo, int $user_id): array`, `format_prueba()` from Task 5.
- Produces: route `/socio/competiciones`, linked from the navbar dropdown and from Task 9.

- [ ] **Step 1: Write `public/socio/competiciones.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';

require_login();
$user = current_user();
$tiempos = listar_tiempos_socio($pdo, (int)$user['id']);

render_header('Mis competiciones', 'socio-competiciones');
?>
<main class="container" style="padding:24px 16px;">
  <h1>Mis tiempos de competición</h1>

  <?php if (!$tiempos): ?>
    <div class="card text-center" style="padding:32px;">
      <p class="text-muted">Aún no tienes tiempos de competición registrados.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Fecha</th><th>Competición</th><th>Prueba</th><th>Tiempo</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($tiempos as $t): ?>
              <tr>
                <td><?= date('d/m/Y', strtotime($t['competicion_fecha'])) ?></td>
                <td><?= e($t['competicion_nombre']) ?></td>
                <td><?= e(format_prueba($t['prueba'])) ?></td>
                <td style="font-weight:600;"><?= e($t['tiempo']) ?></td>
                <td><a href="/socio/competicion_tiempo?id=<?= (int)$t['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</main>
<?php render_footer(); ?>
```

- [ ] **Step 2: Add navbar dropdown link in `includes/layout.php`**

Find this line (around line 110):

```php
                    <a href="/socio/incidencias" <?= $activePage === 'socio-incidencias' ? 'class="active"' : '' ?>><i class="bi bi-exclamation-triangle"></i> Incidencias</a>
```

Add right after it:

```php
                    <a href="/socio/competiciones" <?= $activePage === 'socio-competiciones' ? 'class="active"' : '' ?>><i class="bi bi-list-ol"></i> Competiciones</a>
```

- [ ] **Step 3: Lint**

Run: `docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/public/socio/competiciones.php && docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/includes/layout.php`
Expected: both "No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
git add public/socio/competiciones.php includes/layout.php
git commit -m "feat(socio): listado de mis tiempos de competición"
```

---

## Task 9: `public/socio/competicion_tiempo.php` — detail + comment thread (swimmer side)

**Files:**
- Create: `public/socio/competicion_tiempo.php`

**Interfaces:**
- Consumes: `obtener_tiempo_entrenador()`, `puede_ver_tiempo_socio()`, `listar_comentarios_tiempo()`, `agregar_comentario_tiempo()`, `format_prueba()` from Task 5.
- Produces: route `/socio/competicion_tiempo?id=X`, linked from Task 8.

- [ ] **Step 1: Write `public/socio/competicion_tiempo.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';

require_login();
$user = current_user();
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'comentar') {
    csrf_verify();
    $t = obtener_tiempo_entrenador($pdo, $id);
    if ($t && puede_ver_tiempo_socio($t, $user)) {
        try {
            agregar_comentario_tiempo($pdo, $id, (int)$user['id'], $_POST['contenido'] ?? '');
            flash('Comentario añadido.', 'success');
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
        }
    }
    header('Location: /socio/competicion_tiempo?id=' . $id);
    exit;
}

$tiempo = obtener_tiempo_entrenador($pdo, $id);
$autorizado = $tiempo && puede_ver_tiempo_socio($tiempo, $user);

render_header('Tiempo de competición', 'socio-competiciones');
?>
<main class="container" style="padding:24px 16px;">
  <?php if (!$autorizado): ?>
    <div class="card" style="padding:32px;text-align:center;">
      <h2 style="margin-top:0;">No tienes acceso a este tiempo</h2>
      <a href="/socio/competiciones" class="btn btn-primary btn-sm">Volver a mis competiciones</a>
    </div>
  <?php else:
      $comentarios = listar_comentarios_tiempo($pdo, $id);
  ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h1 style="margin:0;"><?= e(format_prueba($tiempo['prueba'])) ?></h1>
      <a href="/socio/competiciones" class="btn btn-gray btn-sm">← Volver</a>
    </div>
    <?php render_flash(); ?>

    <div class="card mb-6" style="padding:24px;">
      <div class="text-muted text-sm" style="margin-bottom:8px;">
        <?= e($tiempo['competicion_nombre']) ?> · <?= date('d/m/Y', strtotime($tiempo['competicion_fecha'])) ?>
      </div>
      <div style="font-size:28px;font-weight:800;color:var(--blue);"><?= e($tiempo['tiempo']) ?></div>
      <div class="text-muted text-sm">Piscina <?= e($tiempo['piscina']) ?></div>
      <?php if ($tiempo['parciales']): ?>
        <div style="margin-top:12px;">
          <strong>Parciales:</strong>
          <div style="white-space:pre-wrap;"><?= e($tiempo['parciales']) ?></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="padding:24px;">
      <h3>Comentarios (<?= count($comentarios) ?>)</h3>
      <?php if ($comentarios): ?>
        <?php foreach ($comentarios as $c): ?>
          <div style="border-left:3px solid var(--blue);padding:8px 12px;margin-bottom:12px;background:#f9fafb;">
            <div class="text-sm">
              <strong><?= e($c['autor_nombre']) ?></strong>
              <span class="text-muted">· <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></span>
            </div>
            <div style="white-space:pre-wrap;margin-top:6px;"><?= e($c['contenido']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="text-muted">Sin comentarios.</p>
      <?php endif; ?>

      <form method="POST" action="/socio/competicion_tiempo?id=<?= (int)$tiempo['id'] ?>" style="margin-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="comentar">
        <div class="form-group">
          <textarea name="contenido" class="form-control" rows="3" placeholder="Escribe un comentario…" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Comentar</button>
      </form>
    </div>
  <?php endif; ?>
</main>
<?php render_footer(); ?>
```

- [ ] **Step 2: Lint**

Run: `docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/public/socio/competicion_tiempo.php`
Expected: "No syntax errors detected".

- [ ] **Step 3: Manual verification (Tasks 8+9 together — this is the core "foro" flow)**

1. Log in as the swimmer who owns the time created in Task 7's verification. Visit `/socio/competiciones` — the time should be listed.
2. Click "Ver →", confirm the detail shows tiempo/piscina/parciales and the comment the entrenador posted in Task 7.
3. Post a reply as the swimmer, confirm it appears in the thread.
4. Log back in as the entrenador, revisit `/admin/competicion_tiempo?id=X` for that same time, confirm the swimmer's reply is now visible there too (same table, two views).
5. Log in as a **different** swimmer (not the owner) and try `/socio/competicion_tiempo?id=X` with that same ID directly in the URL — confirm you get "No tienes acceso a este tiempo", not the thread.
6. Visit `/socio/competiciones` while logged in as a swimmer with zero competition times — confirm the "Aún no tienes tiempos..." empty state, and that the navbar dropdown still shows "Competiciones".

- [ ] **Step 4: Commit**

```bash
git add public/socio/competicion_tiempo.php
git commit -m "feat(socio): detalle de tiempo de competición con hilo de comentarios propio"
```

---

## Task 10: Full end-to-end smoke pass

**Files:** none (verification-only task).

**Interfaces:**
- Consumes: everything from Tasks 1-9.

- [ ] **Step 1: Lint every new/modified file at once**

```bash
for f in includes/grupos.php includes/competicion_entrenador.php includes/layout.php \
         public/admin/grupos.php public/admin/competiciones.php public/admin/competicion_tiempo.php \
         public/admin/asistencia.php public/socio/competiciones.php public/socio/competicion_tiempo.php; do
  docker exec cn-medio-cudeyo-app-1 php -l /var/www/html/$f
done
```
Expected: nine lines, each "No syntax errors detected in ...".

- [ ] **Step 2: Re-run the full manual flow once, end to end, as a sanity check**

Entrenador: create a group → add swimmers → filter attendance by group → create a competition → log a time with splits → comment on it.
Swimmer: see the time in `/socio/competiciones` → open it → see the entrenador's comment → reply.
Entrenador: see the swimmer's reply in the admin view.
Another swimmer: confirm no access to that thread.

- [ ] **Step 3: Confirm `git status` is clean and `git log` shows one commit per task**

Run: `git status && git log --oneline -10`
Expected: clean working tree, 9 feature commits since the spec commits (Tasks 1-9 minus Task 10 which has no commit).
