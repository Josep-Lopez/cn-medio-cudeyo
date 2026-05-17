# Competiciones + Fichas de nadador — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir una app web aparte (subdominio) que muestre competiciones de natación y fichas públicas de nadadores del club, con datos extraídos de swimrankings.net.

**Architecture:** Subdominio con su propia carpeta `competiciones/` (paralela a `public/`), su propio layout, CSS y admin. Comparte BD MySQL y tabla `users` con el sitio principal. Sesión independiente. Scraping HTML de swimrankings.net (curl + DOMXPath).

**Tech Stack:** PHP 8.4, MySQL 8, Apache, Docker Compose. Sin frameworks. Sin tests automatizados (consistente con el resto del proyecto — validación manual).

**Spec:** [docs/superpowers/specs/2026-05-17-competiciones-fichas-nadador-design.md](../specs/2026-05-17-competiciones-fichas-nadador-design.md)

## Notas previas

- **Nombre placeholder:** Se usa `competiciones` como nombre de carpeta y servicio Docker en todo el plan. Si el usuario decide otro nombre final, hacer find/replace global de `competiciones` antes de empezar.
- **Puerto local:** http://localhost:8082 para el subdominio (el sitio principal sigue en 8080).
- **Validación manual:** Sin tests automatizados — cada tarea termina con pasos concretos en navegador o CLI.

## Estructura de ficheros

```
cn-medio-cudeyo/
├── competiciones/                          ← NUEVO
│   ├── public/                             ← DocumentRoot del subdominio
│   │   ├── index.php                       ← landing
│   │   ├── competicion.php                 ← detalle ?id=X
│   │   ├── nadador.php                     ← ficha pública ?slug=X
│   │   ├── admin/
│   │   │   ├── index.php
│   │   │   ├── login.php
│   │   │   ├── logout.php
│   │   │   ├── buscar.php
│   │   │   ├── vincular.php
│   │   │   ├── importar.php
│   │   │   └── competiciones.php
│   │   └── assets/css/main.css
│   └── includes/
│       ├── auth.php
│       ├── layout.php
│       └── swimrankings.php
├── migrations/012_competiciones.sql        ← NUEVO
├── public/socio/perfil.php                 ← MODIFICAR
├── scripts/swimrankings_import_all.php     ← NUEVO
└── docker-compose.yml                      ← MODIFICAR
```

---

## Task 1: Migración de BD

**Files:**
- Create: `migrations/012_competiciones.sql`
- Modify: `schema.sql`

- [ ] **Step 1: Crear `migrations/012_competiciones.sql`**

```sql
-- Migración 012: Subdominio competiciones + fichas públicas

ALTER TABLE users
  ADD COLUMN swimrankings_id INT NULL UNIQUE AFTER rfen_id,
  ADD COLUMN slug VARCHAR(100) NULL UNIQUE AFTER nombre,
  ADD COLUMN perfil_publico TINYINT(1) NOT NULL DEFAULT 1 AFTER swimrankings_id;

CREATE TABLE IF NOT EXISTS competiciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  swimrankings_meet_id INT NULL UNIQUE,
  nombre VARCHAR(255) NOT NULL,
  lugar VARCHAR(255),
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE,
  piscina ENUM('25m','50m','open') DEFAULT '25m',
  organizador VARCHAR(100),
  url_origen VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_fecha (fecha_inicio DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competicion_resultados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  competicion_id INT NOT NULL,
  user_id INT NULL,
  nombre_nadador VARCHAR(150) NOT NULL,
  prueba VARCHAR(10) NOT NULL,
  tiempo VARCHAR(10) NOT NULL,
  tiempo_seg DECIMAL(8,2) NOT NULL,
  fase ENUM('final','semifinal','serie') DEFAULT 'serie',
  puesto INT NULL,
  fecha_marca DATE NOT NULL,
  FOREIGN KEY (competicion_id) REFERENCES competiciones(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user (user_id),
  INDEX idx_competicion (competicion_id),
  UNIQUE KEY uniq_resultado (competicion_id, user_id, prueba, fase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Aplicar la migración**

```bash
docker compose exec -T db mysql -uroot -p${DB_ROOT_PASS} cn_medio_cudeyo < migrations/012_competiciones.sql
```

Expected: sin output. Si hay error "Duplicate column", ya estaba aplicada.

- [ ] **Step 3: Verificar en phpMyAdmin**

Abrir http://localhost:8081, base `cn_medio_cudeyo`. Verificar:
- `users` tiene `swimrankings_id`, `slug`, `perfil_publico`
- Existen `competiciones` y `competicion_resultados`

- [ ] **Step 4: Backfill slug de usuarios existentes**

Crear `scripts/backfill_slugs.php` con:

```php
<?php
require __DIR__ . '/../config/db.php';
$users = $pdo->query('SELECT id, nombre FROM users WHERE slug IS NULL')->fetchAll();
$slugify = function($s) {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($s));
    return trim($s, '-');
};
$upd = $pdo->prepare('UPDATE users SET slug = ? WHERE id = ?');
foreach ($users as $u) {
    $base = $slugify($u['nombre']);
    $slug = $base;
    $n = 2;
    while ($pdo->query('SELECT 1 FROM users WHERE slug = ' . $pdo->quote($slug))->fetch()) {
        $slug = $base . '-' . $n++;
    }
    $upd->execute([$slug, $u['id']]);
}
echo "OK\n";
```

Ejecutar:
```bash
docker compose exec app php scripts/backfill_slugs.php
```
Expected: `OK`.

- [ ] **Step 5: Replicar en `schema.sql`**

Editar `schema.sql`: añadir las 3 columnas al CREATE TABLE users; añadir los CREATE de `competiciones` y `competicion_resultados`.

- [ ] **Step 6: Commit**

```bash
git add migrations/012_competiciones.sql schema.sql scripts/backfill_slugs.php
git commit -m "feat(db): tablas competiciones + columnas users.swimrankings_id/slug/perfil_publico"
```

---

## Task 2: Servicio Docker para el subdominio

**Files:**
- Modify: `docker-compose.yml`
- Create: `competiciones/public/index.php` (placeholder)

- [ ] **Step 1: Añadir servicio `app-comp` en `docker-compose.yml`**

Tras el servicio `app`:

```yaml
  app-comp:
    build: .
    ports:
      - "8082:80"
    volumes:
      - .:/var/www/html
    environment:
      DB_HOST: db
      DB_NAME: ${DB_NAME}
      DB_USER: ${DB_USER}
      DB_PASS: ${DB_PASS}
    command: >
      bash -c "
      sed -i 's|/var/www/html/public|/var/www/html/competiciones/public|g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf &&
      apache2-foreground
      "
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped
```

- [ ] **Step 2: Crear placeholder de DocumentRoot**

```bash
mkdir -p competiciones/public
```

Crear `competiciones/public/index.php`:

```php
<?php echo 'Subdominio competiciones — placeholder';
```

- [ ] **Step 3: Levantar el servicio**

```bash
docker compose up -d app-comp
docker compose logs app-comp --tail 20
```

Expected: Apache arrancado sin errores.

- [ ] **Step 4: Verificar en navegador**

Abrir http://localhost:8082. Expected: "Subdominio competiciones — placeholder".

- [ ] **Step 5: Commit**

```bash
git add docker-compose.yml competiciones/public/index.php
git commit -m "feat(docker): servicio app-comp para subdominio en puerto 8082"
```

---

## Task 3: Skeleton del subdominio (auth, layout, CSS)

**Files:**
- Create: `competiciones/includes/auth.php`
- Create: `competiciones/includes/layout.php`
- Create: `competiciones/public/assets/css/main.css`
- Modify: `competiciones/public/index.php` (landing vacía)

- [ ] **Step 1: Crear `competiciones/includes/auth.php`**

```php
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('COMP_SESS');
    session_start();
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}
function csrf_verify(): void {
    if (!hash_equals(csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Petición inválida (CSRF).');
    }
}

function comp_current_admin(): ?array { return $_SESSION['comp_admin'] ?? null; }
function comp_is_admin(): bool { return !empty($_SESSION['comp_admin']); }
function comp_require_admin(): void {
    if (!comp_is_admin()) { header('Location: /admin/login.php'); exit; }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function flash(string $msg, string $type = 'info'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
function get_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
```

- [ ] **Step 2: Crear `competiciones/includes/layout.php`**

```php
<?php
function comp_render_header(string $title = 'Competiciones', string $active = ''): void {
    $appName = 'Competiciones CN Medio Cudeyo';
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= e($title) ?> — <?= e($appName) ?></title>
      <link rel="stylesheet" href="/assets/css/main.css">
    </head>
    <body>
      <header class="topbar">
        <a class="brand" href="/"><?= e($appName) ?></a>
        <nav class="topnav">
          <a href="/" class="<?= $active === 'home' ? 'active' : '' ?>">Competiciones</a>
          <?php if (comp_is_admin()): ?>
            <a href="/admin/">Admin</a>
            <a href="/admin/logout.php">Salir</a>
          <?php endif; ?>
        </nav>
      </header>
      <main class="container">
    <?php
    $f = get_flash();
    if ($f) echo '<div class="flash flash-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
}

function comp_render_footer(): void {
    ?>
      </main>
      <footer class="footer">
        <p>Datos extraídos de <a href="https://www.swimrankings.net" target="_blank" rel="noopener">swimrankings.net</a>.</p>
        <p><a href="https://cnmediocudeyo.es">CN Medio Cudeyo</a></p>
      </footer>
    </body>
    </html>
    <?php
}
```

- [ ] **Step 3: Crear `competiciones/public/assets/css/main.css`**

```css
:root { --blue:#093FB4; --red:#BF4646; --green:#16a34a; --bg:#f5f5f5; --text:#111; --gray:#888; }
* { box-sizing: border-box; }
body { margin:0; font-family: Inter, Arial, sans-serif; background: var(--bg); color: var(--text); }
.topbar { display:flex; justify-content:space-between; align-items:center; padding:1rem 2rem; background:#fff; border-bottom:3px solid var(--blue); }
.brand { font-weight:700; text-decoration:none; color:var(--blue); font-size:1.2rem; }
.topnav a { margin-left:1rem; text-decoration:none; color:var(--text); }
.topnav a.active { color:var(--blue); font-weight:600; }
.container { max-width:1100px; margin:0 auto; padding:2rem; }
h1 { font-size:1.75rem; color:var(--blue); margin-bottom:0.5rem; }
h2 { font-size:1.25rem; margin-top:1rem; }
.meta { color:var(--gray); margin:0.5rem 0 1rem; }
.flash { padding:1rem; border-radius:6px; margin-bottom:1rem; }
.flash-success { background:#dcfce7; color:#166534; }
.flash-danger  { background:#fee2e2; color:#991b1b; }
.flash-info    { background:#dbeafe; color:#1e40af; }
.flash-warning { background:#fef3c7; color:#92400e; }
.empty-state { text-align:center; padding:3rem; color:var(--gray); }
.card { background:#fff; padding:1.5rem; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.08); margin-bottom:1rem; }
.btn { display:inline-block; padding:0.5rem 1rem; background:var(--blue); color:#fff; text-decoration:none; border:none; border-radius:6px; cursor:pointer; }
.btn:hover { opacity:0.9; }
.btn-danger { background:var(--red); }
table { width:100%; border-collapse: collapse; background:#fff; }
th, td { padding:0.75rem; text-align:left; border-bottom:1px solid #eee; }
th { background:#f9fafb; font-weight:600; }
.footer { text-align:center; padding:2rem; color:var(--gray); font-size:0.9rem; }
.footer a { color:var(--gray); }
@media (max-width: 768px) {
  .topbar { flex-direction:column; gap:0.5rem; padding:1rem; }
  .container { padding:1rem; }
  table { font-size:0.85rem; }
  th, td { padding:0.5rem; }
}
```

- [ ] **Step 4: Reescribir `competiciones/public/index.php`**

```php
<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$count = (int)$pdo->query('SELECT COUNT(*) FROM competiciones')->fetchColumn();

comp_render_header('Competiciones', 'home');
?>
<h1>Competiciones del club</h1>
<?php if ($count === 0): ?>
  <div class="empty-state">
    <p>Aún no hay competiciones importadas.</p>
    <?php if (comp_is_admin()): ?>
      <p><a class="btn" href="/admin/">Ir al panel admin</a></p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <p>Listado en construcción (Task 9).</p>
<?php endif; ?>
<?php comp_render_footer(); ?>
```

- [ ] **Step 5: Verificar**

Abrir http://localhost:8082. Expected: header con marca + nav, "Aún no hay competiciones importadas", CSS aplicado.

- [ ] **Step 6: Commit**

```bash
git add competiciones/
git commit -m "feat(comp): skeleton subdominio — auth, layout, CSS base, landing vacía"
```

---

## Task 4: Admin login/logout del subdominio

**Files:**
- Create: `competiciones/public/admin/login.php`
- Create: `competiciones/public/admin/logout.php`
- Create: `competiciones/public/admin/index.php`

- [ ] **Step 1: Crear `competiciones/public/admin/login.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 3) . '/config/db.php';

if (comp_is_admin()) { header('Location: /admin/'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT id, nombre, email, password, rol FROM users WHERE email = ? AND rol = 'admin' AND estado = 'activo' LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if ($u && password_verify($pass, $u['password'])) {
        $_SESSION['comp_admin'] = ['id' => $u['id'], 'nombre' => $u['nombre'], 'email' => $u['email']];
        header('Location: /admin/'); exit;
    }
    $error = 'Credenciales inválidas.';
}

comp_render_header('Login admin');
?>
<div class="card" style="max-width:400px; margin:2rem auto;">
  <h1>Acceso administrador</h1>
  <?php if ($error): ?><div class="flash flash-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <p><label>Email<br><input type="email" name="email" required style="width:100%;padding:0.5rem;"></label></p>
    <p><label>Contraseña<br><input type="password" name="password" required style="width:100%;padding:0.5rem;"></label></p>
    <button type="submit" class="btn">Entrar</button>
  </form>
</div>
<?php comp_render_footer(); ?>
```

- [ ] **Step 2: Crear `competiciones/public/admin/logout.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
unset($_SESSION['comp_admin']);
header('Location: /');
exit;
```

- [ ] **Step 3: Crear `competiciones/public/admin/index.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
comp_require_admin();

$vinculados = $pdo->query('
    SELECT id, nombre, swimrankings_id
    FROM users
    WHERE swimrankings_id IS NOT NULL
    ORDER BY nombre
')->fetchAll();

comp_render_header('Admin');
?>
<h1>Panel admin</h1>
<p>Hola, <?= e(comp_current_admin()['nombre']) ?>.</p>

<div class="card">
  <h2>Acciones</h2>
  <p><a class="btn" href="/admin/buscar.php">Buscar nadador en swimrankings</a></p>
  <p><a class="btn" href="/admin/competiciones.php">Ver competiciones importadas</a></p>
</div>

<div class="card">
  <h2>Nadadores vinculados (<?= count($vinculados) ?>)</h2>
  <?php if (!$vinculados): ?>
    <p>Aún no hay nadadores vinculados. Empieza buscando uno.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Nombre</th><th>swimrankings_id</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($vinculados as $v): ?>
          <tr>
            <td><?= e($v['nombre']) ?></td>
            <td><?= (int)$v['swimrankings_id'] ?></td>
            <td><a href="/admin/importar.php?user_id=<?= (int)$v['id'] ?>">Importar meets</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php comp_render_footer(); ?>
```

- [ ] **Step 4: Verificar login**

http://localhost:8082/admin/ → redirige a `/admin/login.php`. Login con credenciales admin del club (`admin@cnmediocudeyo.es / Admin1234!`). Expected: panel admin con tabla vacía.

- [ ] **Step 5: Verificar sesión independiente**

En otra pestaña abrir http://localhost:8080 y hacer login/logout allí. Verificar que el subdominio mantiene su sesión propia.

- [ ] **Step 6: Commit**

```bash
git add competiciones/public/admin/
git commit -m "feat(comp): admin login/logout/home con sesión independiente"
```

---

## Task 5: Scraper swimrankings — fetch + búsqueda

**Files:**
- Create: `competiciones/includes/swimrankings.php`
- Create: `competiciones/public/admin/buscar.php`

> **Importante:** Las URLs y selectores XPath son aproximaciones. Validar con HTML real (`curl -s 'URL' | less`) y ajustar los XPath si el patrón difiere.

- [ ] **Step 1: Crear `competiciones/includes/swimrankings.php`**

```php
<?php
const SWR_BASE = 'https://www.swimrankings.net/index.php';

function swr_fetch_html(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux x86_64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept-Language: es-ES,es;q=0.9,en;q=0.8'],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return '';
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }
    return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
}

function swr_load_xpath(string $html): ?DOMXPath {
    if ($html === '') return null;
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

/**
 * Busca athletes por apellido.
 * @return array<array{id:int, nombre:string, nacimiento:string, club:string, sexo:string}>
 */
function swr_buscar_athlete(string $apellido, ?int $club_id = null): array {
    $params = ['page' => 'athleteSearch', 'athleteLastname' => $apellido];
    if ($club_id) $params['athleteClubId'] = $club_id;
    $url = SWR_BASE . '?' . http_build_query($params);

    $xpath = swr_load_xpath(swr_fetch_html($url));
    if (!$xpath) return [];

    $rows = $xpath->query('//table//tr[.//a[contains(@href,"athleteId=")]]');
    $out = [];
    foreach ($rows as $tr) {
        if (!$tr instanceof DOMElement) continue;
        $link = $xpath->query('.//a[contains(@href,"athleteId=")]', $tr)->item(0);
        if (!$link instanceof DOMElement) continue;
        $href = $link->getAttribute('href');
        if (!preg_match('/athleteId=(\d+)/', $href, $m)) continue;
        $cells = $xpath->query('./td', $tr);
        $out[] = [
            'id'         => (int)$m[1],
            'nombre'     => trim($link->textContent),
            'nacimiento' => trim($cells->item(1)->textContent ?? ''),
            'sexo'       => trim($cells->item(2)->textContent ?? ''),
            'club'       => trim($cells->item(3)->textContent ?? ''),
        ];
    }
    return $out;
}
```

- [ ] **Step 2: Crear `competiciones/public/admin/buscar.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/swimrankings.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
comp_require_admin();

$apellido = trim($_GET['apellido'] ?? '');
$resultados = $apellido !== '' ? swr_buscar_athlete($apellido) : [];

$socios = $pdo->query('
    SELECT id, nombre, swimrankings_id
    FROM users
    WHERE rol = "socio" AND estado = "activo"
    ORDER BY nombre
')->fetchAll();

comp_render_header('Buscar nadador');
?>
<h1>Buscar nadador en swimrankings.net</h1>

<form method="get" class="card">
  <label>Apellido<br>
    <input type="text" name="apellido" value="<?= e($apellido) ?>" required style="padding:0.5rem;">
  </label>
  <button type="submit" class="btn">Buscar</button>
</form>

<?php if ($apellido !== ''): ?>
  <h2>Resultados (<?= count($resultados) ?>)</h2>
  <?php if (!$resultados): ?>
    <p>Sin resultados.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Nombre</th><th>Nac.</th><th>Sexo</th><th>Club</th><th>Vincular a socio</th></tr></thead>
      <tbody>
        <?php foreach ($resultados as $r): ?>
          <tr>
            <td>
              <a href="https://www.swimrankings.net/index.php?page=athleteDetail&athleteId=<?= (int)$r['id'] ?>" target="_blank">
                <?= e($r['nombre']) ?>
              </a>
              <small>(ID <?= (int)$r['id'] ?>)</small>
            </td>
            <td><?= e($r['nacimiento']) ?></td>
            <td><?= e($r['sexo']) ?></td>
            <td><?= e($r['club']) ?></td>
            <td>
              <form method="post" action="/admin/vincular.php" style="display:flex;gap:0.5rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="swimrankings_id" value="<?= (int)$r['id'] ?>">
                <select name="user_id" required>
                  <option value="">— socio —</option>
                  <?php foreach ($socios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $u['swimrankings_id'] == $r['id'] ? 'selected disabled' : '' ?>>
                      <?= e($u['nombre']) ?><?= $u['swimrankings_id'] ? ' (vinculado)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Vincular</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>
<?php comp_render_footer(); ?>
```

- [ ] **Step 3: Verificar búsqueda**

Login admin, ir a http://localhost:8082/admin/buscar.php. Buscar un apellido conocido. Expected: tabla con candidatos.

Si no aparecen resultados o el HTML es inesperado, debuggear así:

```bash
docker compose exec app-comp bash -c "curl -s 'https://www.swimrankings.net/index.php?page=athleteSearch&athleteLastname=Garcia' > /tmp/test.html && head -200 /tmp/test.html"
```

Ajustar los XPath en `swr_buscar_athlete()` según la estructura real.

- [ ] **Step 4: Commit**

```bash
git add competiciones/includes/swimrankings.php competiciones/public/admin/buscar.php
git commit -m "feat(comp): scraper swimrankings (fetch + search) + UI buscador admin"
```

---

## Task 6: Vincular athlete a usuario

**Files:**
- Create: `competiciones/public/admin/vincular.php`

- [ ] **Step 1: Crear `competiciones/public/admin/vincular.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
comp_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify();

$user_id         = (int)($_POST['user_id'] ?? 0);
$swimrankings_id = (int)($_POST['swimrankings_id'] ?? 0);

if ($user_id <= 0 || $swimrankings_id <= 0) {
    flash('Datos inválidos.', 'danger');
    header('Location: /admin/buscar.php'); exit;
}

$check = $pdo->prepare('SELECT id, nombre FROM users WHERE swimrankings_id = ? AND id != ?');
$check->execute([$swimrankings_id, $user_id]);
if ($otro = $check->fetch()) {
    flash('Ese swimrankings_id ya está vinculado a "' . $otro['nombre'] . '".', 'danger');
    header('Location: /admin/buscar.php'); exit;
}

$pdo->prepare('UPDATE users SET swimrankings_id = ? WHERE id = ?')
    ->execute([$swimrankings_id, $user_id]);

flash('Nadador vinculado correctamente.', 'success');
header('Location: /admin/'); exit;
```

- [ ] **Step 2: Verificar vincular**

Desde `/admin/buscar.php`, seleccionar un socio y un candidato. Click "Vincular". Expected: redirige a `/admin/` con flash de éxito y el socio aparece en "Nadadores vinculados".

- [ ] **Step 3: Verificar en BD**

```bash
docker compose exec -T db mysql -uroot -p${DB_ROOT_PASS} cn_medio_cudeyo -e "SELECT id, nombre, swimrankings_id FROM users WHERE swimrankings_id IS NOT NULL"
```

Expected: el usuario con su swimrankings_id correcto.

- [ ] **Step 4: Commit**

```bash
git add competiciones/public/admin/vincular.php
git commit -m "feat(comp): vincular athlete swimrankings a usuario del club"
```

---

## Task 7: Scraper — meets + parse results + import

**Files:**
- Modify: `competiciones/includes/swimrankings.php` (añadir funciones al final)

- [ ] **Step 1: Añadir helpers de fecha y tiempo**

Anexar a `competiciones/includes/swimrankings.php`:

```php
function swr_parse_date(string $f): string {
    $f = trim($f);
    if (preg_match('#^(\d{2})[/.](\d{2})[/.](\d{4})#', $f, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $f, $m)) return $m[0];
    return date('Y-m-d');
}

function swr_tiempo_a_segundos(string $t): float {
    $t = trim($t);
    if (preg_match('/^(?:(\d+):)?(\d{1,2})[.,](\d{1,2})$/', $t, $m)) {
        $min = (int)($m[1] ?? 0);
        $sec = (int)$m[2];
        $cs  = (int)str_pad($m[3], 2, '0');
        return $min * 60 + $sec + $cs / 100;
    }
    return 0.0;
}

function swr_parse_prueba(string $label): ?string {
    $label = strtolower($label);
    if (!preg_match('/(\d+)/', $label, $dm)) return null;
    $dist = (int)$dm[1];
    $map = [
        'free' => 'L', 'libre' => 'L', 'crol' => 'L',
        'back' => 'E', 'espalda' => 'E',
        'breast' => 'B', 'braza' => 'B',
        'fly' => 'M', 'mariposa' => 'M', 'butterfly' => 'M',
        'medley' => 'X', 'estilos' => 'X',
    ];
    $estilo = null;
    foreach ($map as $k => $v) {
        if (str_contains($label, $k)) { $estilo = $v; break; }
    }
    if (!$estilo) return null;
    $valid = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];
    $p = $dist . $estilo;
    return in_array($p, $valid) ? $p : null;
}
```

- [ ] **Step 2: Añadir `swr_get_athlete_meets()`**

```php
/**
 * @return array<array{meet_id:int, nombre:string, fecha:string, url:string}>
 */
function swr_get_athlete_meets(int $swimrankings_id, ?string $since_date = null): array {
    $url = SWR_BASE . '?' . http_build_query([
        'page'      => 'athleteDetail',
        'athleteId' => $swimrankings_id,
    ]);
    $xpath = swr_load_xpath(swr_fetch_html($url));
    if (!$xpath) return [];

    $meets = [];
    $seen = [];
    $links = $xpath->query('//a[contains(@href,"meetId=")]');
    foreach ($links as $a) {
        if (!$a instanceof DOMElement) continue;
        $href = $a->getAttribute('href');
        if (!preg_match('/meetId=(\d+)/', $href, $m)) continue;
        $meet_id = (int)$m[1];
        if (isset($seen[$meet_id])) continue;
        $seen[$meet_id] = true;

        $tr = $a;
        while ($tr && $tr->nodeName !== 'tr') $tr = $tr->parentNode;
        $fecha = '';
        if ($tr instanceof DOMElement) {
            $first_td = $xpath->query('./td[1]', $tr)->item(0);
            if ($first_td) $fecha = trim($first_td->textContent);
        }
        $meets[] = [
            'meet_id' => $meet_id,
            'nombre'  => trim($a->textContent),
            'fecha'   => $fecha,
            'url'     => SWR_BASE . '?page=meetDetail&meetId=' . $meet_id,
        ];
    }

    if ($since_date) {
        $meets = array_filter($meets, fn($m) => swr_parse_date($m['fecha']) >= $since_date);
    }
    return array_values($meets);
}
```

- [ ] **Step 3: Añadir `swr_parse_meet_results()`**

```php
/**
 * @return array{meta:array, resultados:array<array>}
 */
function swr_parse_meet_results(int $meet_id): array {
    $url = SWR_BASE . '?page=meetDetail&meetId=' . $meet_id;
    $xpath = swr_load_xpath(swr_fetch_html($url));
    if (!$xpath) return ['meta' => [], 'resultados' => []];

    $titulo = $xpath->query('//h2')->item(0)?->textContent ?? '';
    $info   = $xpath->query('//div[@id="content"]//p')->item(0)?->textContent ?? '';

    $meta = [
        'nombre'  => trim($titulo),
        'lugar'   => '',
        'fecha_inicio' => date('Y-m-d'),
        'fecha_fin'    => null,
        'piscina' => '25m',
        'url'     => $url,
    ];
    if (preg_match('#(\d{2}[./]\d{2}[./]\d{4})\s*-?\s*(\d{2}[./]\d{2}[./]\d{4})?#', $info, $m)) {
        $meta['fecha_inicio'] = swr_parse_date($m[1]);
        $meta['fecha_fin']    = !empty($m[2]) ? swr_parse_date($m[2]) : null;
    }
    if (stripos($info, '50m') !== false) $meta['piscina'] = '50m';

    $resultados = [];
    $rows = $xpath->query('//table//tr[.//a[contains(@href,"athleteId=")]]');
    foreach ($rows as $tr) {
        if (!$tr instanceof DOMElement) continue;
        $a = $xpath->query('.//a[contains(@href,"athleteId=")]', $tr)->item(0);
        if (!$a instanceof DOMElement) continue;
        if (!preg_match('/athleteId=(\d+)/', $a->getAttribute('href'), $m)) continue;
        $athlete_id = (int)$m[1];
        $nombre = trim($a->textContent);

        $cells = $xpath->query('./td', $tr);
        $celltext = [];
        foreach ($cells as $c) $celltext[] = trim($c->textContent);
        if (count($celltext) < 5) continue;
        $puesto = (int)preg_replace('/\D/', '', $celltext[0]) ?: null;
        $tiempo = $celltext[4];
        if (!preg_match('/^\d/', $tiempo)) continue;
        $tiempo_seg = swr_tiempo_a_segundos($tiempo);
        if ($tiempo_seg <= 0) continue;

        // Detectar prueba desde el contexto (h3 previo en el DOM)
        $prev = $tr;
        $prueba_label = '';
        while (($prev = $prev->previousSibling) !== null) {
            if ($prev->nodeName === 'h3' || $prev->nodeName === 'tr') {
                $txt = trim($prev->textContent);
                if (preg_match('/(\d+)\s*m?\s*(Free|Back|Breast|Fly|Medley|Libre|Espalda|Braza|Mariposa|Estilos)/i', $txt, $pm)) {
                    $prueba_label = $pm[0]; break;
                }
            }
        }
        $prueba = swr_parse_prueba($prueba_label);
        if (!$prueba) continue;

        $resultados[] = [
            'athlete_id'     => $athlete_id,
            'nombre_nadador' => $nombre,
            'prueba'         => $prueba,
            'tiempo'         => $tiempo,
            'tiempo_seg'     => $tiempo_seg,
            'fase'           => 'serie',
            'puesto'         => $puesto,
            'fecha_marca'    => $meta['fecha_inicio'],
        ];
    }

    return ['meta' => $meta, 'resultados' => $resultados];
}
```

- [ ] **Step 4: Añadir `swr_import_meet()`**

```php
/**
 * Importa un meet a la BD. Solo guarda resultados de athletes vinculados a users.
 * @return array{insertados:int, actualizados:int, descartados:int, error:?string}
 */
function swr_import_meet(PDO $pdo, int $meet_id): array {
    $data = swr_parse_meet_results($meet_id);
    if (!$data['meta'] || !$data['resultados']) {
        return ['insertados'=>0,'actualizados'=>0,'descartados'=>0,'error'=>'Meet sin datos.'];
    }
    $meta = $data['meta'];

    $pdo->prepare('
        INSERT INTO competiciones (swimrankings_meet_id, nombre, lugar, fecha_inicio, fecha_fin, piscina, url_origen)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            lugar  = VALUES(lugar),
            fecha_inicio = VALUES(fecha_inicio),
            fecha_fin    = VALUES(fecha_fin),
            piscina      = VALUES(piscina),
            url_origen   = VALUES(url_origen)
    ')->execute([
        $meet_id, $meta['nombre'], $meta['lugar'],
        $meta['fecha_inicio'], $meta['fecha_fin'], $meta['piscina'], $meta['url'],
    ]);
    $comp_id = (int)$pdo->query('SELECT id FROM competiciones WHERE swimrankings_meet_id = ' . $meet_id)->fetchColumn();

    $idMap = [];
    foreach ($pdo->query('SELECT id, swimrankings_id FROM users WHERE swimrankings_id IS NOT NULL') as $r) {
        $idMap[(int)$r['swimrankings_id']] = (int)$r['id'];
    }

    $ins = $pdo->prepare('
        INSERT INTO competicion_resultados
            (competicion_id, user_id, nombre_nadador, prueba, tiempo, tiempo_seg, fase, puesto, fecha_marca)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            tiempo     = VALUES(tiempo),
            tiempo_seg = VALUES(tiempo_seg),
            puesto     = VALUES(puesto)
    ');

    $insertados = $actualizados = $descartados = 0;
    foreach ($data['resultados'] as $r) {
        $user_id = $idMap[$r['athlete_id']] ?? null;
        if (!$user_id) { $descartados++; continue; }
        $ins->execute([
            $comp_id, $user_id, $r['nombre_nadador'], $r['prueba'],
            $r['tiempo'], $r['tiempo_seg'], $r['fase'], $r['puesto'], $r['fecha_marca'],
        ]);
        $rc = $ins->rowCount();
        if ($rc === 1) $insertados++;
        elseif ($rc === 2) $actualizados++;
    }

    return ['insertados'=>$insertados,'actualizados'=>$actualizados,'descartados'=>$descartados,'error'=>null];
}
```

- [ ] **Step 5: Verificar parser con un meet real**

Con un usuario vinculado, sustituir `999999` por un meet_id real de swimrankings:

```bash
docker compose exec app-comp php -r '
require "/var/www/html/config/db.php";
require "/var/www/html/competiciones/includes/swimrankings.php";
$data = swr_parse_meet_results(999999);
print_r($data["meta"]);
echo "Resultados parseados: " . count($data["resultados"]) . "\n";
'
```

Expected: meta con nombre/fecha, recuento > 0. Si vacío, ajustar XPath.

- [ ] **Step 6: Commit**

```bash
git add competiciones/includes/swimrankings.php
git commit -m "feat(comp): scraper get_athlete_meets + parse_meet_results + import_meet"
```

---

## Task 8: Admin — importar meets + listar competiciones

**Files:**
- Create: `competiciones/public/admin/importar.php`
- Create: `competiciones/public/admin/competiciones.php`

- [ ] **Step 1: Crear `competiciones/public/admin/importar.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/swimrankings.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
comp_require_admin();

$user_id = (int)($_GET['user_id'] ?? 0);
$user = null;
if ($user_id > 0) {
    $stmt = $pdo->prepare('SELECT id, nombre, swimrankings_id FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
}
if (!$user || !$user['swimrankings_id']) {
    flash('Usuario inválido o sin swimrankings_id.', 'danger');
    header('Location: /admin/'); exit;
}

$import_log = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    set_time_limit(120);
    $meets = swr_get_athlete_meets((int)$user['swimrankings_id']);
    $stats = ['meets'=>count($meets),'insertados'=>0,'actualizados'=>0,'descartados'=>0,'errores'=>[]];
    foreach ($meets as $m) {
        $res = swr_import_meet($pdo, $m['meet_id']);
        if ($res['error']) { $stats['errores'][] = $m['nombre'] . ': ' . $res['error']; continue; }
        $stats['insertados']   += $res['insertados'];
        $stats['actualizados'] += $res['actualizados'];
        $stats['descartados']  += $res['descartados'];
    }
    $import_log = $stats;
    flash("Import completo: {$stats['meets']} meets, +{$stats['insertados']} / ~{$stats['actualizados']}.", 'success');
}

$meets_preview = swr_get_athlete_meets((int)$user['swimrankings_id']);

comp_render_header('Importar meets');
?>
<h1>Importar meets de <?= e($user['nombre']) ?></h1>
<p>swimrankings_id: <strong><?= (int)$user['swimrankings_id'] ?></strong></p>

<div class="card">
  <h2>Meets disponibles (<?= count($meets_preview) ?>)</h2>
  <?php if (!$meets_preview): ?>
    <p>Sin meets encontrados.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Nombre</th><th>meet_id</th></tr></thead>
      <tbody>
        <?php foreach ($meets_preview as $m): ?>
          <tr>
            <td><?= e($m['fecha']) ?></td>
            <td><a href="<?= e($m['url']) ?>" target="_blank"><?= e($m['nombre']) ?></a></td>
            <td><?= (int)$m['meet_id'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post" style="margin-top:1rem;">
    <?= csrf_field() ?>
    <button type="submit" class="btn">Importar todos</button>
  </form>
</div>

<?php if ($import_log): ?>
  <div class="card">
    <h2>Log</h2>
    <p>Meets procesados: <?= (int)$import_log['meets'] ?></p>
    <p>Nuevos: <?= (int)$import_log['insertados'] ?></p>
    <p>Actualizados: <?= (int)$import_log['actualizados'] ?></p>
    <p>Descartados (no socios): <?= (int)$import_log['descartados'] ?></p>
    <?php if ($import_log['errores']): ?>
      <h3>Errores</h3>
      <ul><?php foreach ($import_log['errores'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php comp_render_footer(); ?>
```

- [ ] **Step 2: Crear `competiciones/public/admin/competiciones.php`**

```php
<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/swimrankings.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
comp_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reimport') {
    csrf_verify();
    $meet_id = (int)$_POST['meet_id'];
    $res = swr_import_meet($pdo, $meet_id);
    flash($res['error'] ?: "Re-importado: +{$res['insertados']} / ~{$res['actualizados']}.", $res['error'] ? 'danger' : 'success');
    header('Location: /admin/competiciones.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $pdo->prepare('DELETE FROM competiciones WHERE id = ?')->execute([(int)$_POST['id']]);
    flash('Competición eliminada.', 'success');
    header('Location: /admin/competiciones.php'); exit;
}

$comps = $pdo->query('
    SELECT c.id, c.nombre, c.lugar, c.fecha_inicio, c.swimrankings_meet_id, c.url_origen,
           (SELECT COUNT(*) FROM competicion_resultados r WHERE r.competicion_id = c.id) AS n_resultados
    FROM competiciones c
    ORDER BY c.fecha_inicio DESC
')->fetchAll();

comp_render_header('Competiciones importadas');
?>
<h1>Competiciones importadas (<?= count($comps) ?>)</h1>
<table>
  <thead><tr><th>Fecha</th><th>Nombre</th><th>Lugar</th><th>Resultados</th><th>Acciones</th></tr></thead>
  <tbody>
    <?php foreach ($comps as $c): ?>
      <tr>
        <td><?= e($c['fecha_inicio']) ?></td>
        <td>
          <a href="/competicion.php?id=<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></a>
          <?php if ($c['url_origen']): ?><a href="<?= e($c['url_origen']) ?>" target="_blank"><small>↗</small></a><?php endif; ?>
        </td>
        <td><?= e($c['lugar']) ?></td>
        <td><?= (int)$c['n_resultados'] ?></td>
        <td style="display:flex;gap:0.5rem;">
          <?php if ($c['swimrankings_meet_id']): ?>
            <form method="post" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reimport">
              <input type="hidden" name="meet_id" value="<?= (int)$c['swimrankings_meet_id'] ?>">
              <button type="submit" class="btn">Re-importar</button>
            </form>
          <?php endif; ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn btn-danger">Eliminar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php comp_render_footer(); ?>
```

- [ ] **Step 3: Verificar import end-to-end**

1. http://localhost:8082/admin/ → click "Importar meets" en un usuario vinculado
2. Ver listado de meets, click "Importar todos"
3. Esperar (30-60s)
4. Ver log con N meets, N insertados
5. http://localhost:8082/admin/competiciones.php — competiciones listadas con resultados > 0

- [ ] **Step 4: Commit**

```bash
git add competiciones/public/admin/importar.php competiciones/public/admin/competiciones.php
git commit -m "feat(comp): admin importar meets + listado/re-import/eliminar competiciones"
```

---

## Task 9: Public — landing con listado paginado

**Files:**
- Modify: `competiciones/public/index.php`
- Modify: `competiciones/public/assets/css/main.css`

- [ ] **Step 1: Sustituir `competiciones/public/index.php`**

```php
<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

$total = (int)$pdo->query('
    SELECT COUNT(*) FROM competiciones c
    WHERE EXISTS (SELECT 1 FROM competicion_resultados r WHERE r.competicion_id = c.id)
')->fetchColumn();

$stmt = $pdo->prepare('
    SELECT c.id, c.nombre, c.lugar, c.fecha_inicio, c.fecha_fin, c.piscina,
           (SELECT COUNT(DISTINCT user_id) FROM competicion_resultados r WHERE r.competicion_id = c.id) AS n_socios
    FROM competiciones c
    WHERE EXISTS (SELECT 1 FROM competicion_resultados r WHERE r.competicion_id = c.id)
    ORDER BY c.fecha_inicio DESC
    LIMIT ? OFFSET ?
');
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$comps = $stmt->fetchAll();

$totalPages = max(1, (int)ceil($total / $perPage));

comp_render_header('Competiciones', 'home');
?>
<h1>Competiciones</h1>

<?php if (!$comps): ?>
  <div class="empty-state"><p>Aún no hay competiciones importadas.</p></div>
<?php else: ?>
  <div class="comp-grid">
    <?php foreach ($comps as $c): ?>
      <a class="comp-card card" href="/competicion.php?id=<?= (int)$c['id'] ?>">
        <h2><?= e($c['nombre']) ?></h2>
        <p class="meta">
          <?= e(date('d/m/Y', strtotime($c['fecha_inicio']))) ?>
          <?php if ($c['fecha_fin'] && $c['fecha_fin'] !== $c['fecha_inicio']): ?>
            — <?= e(date('d/m/Y', strtotime($c['fecha_fin']))) ?>
          <?php endif; ?>
          · <?= e($c['lugar'] ?: 'Sin lugar') ?> · <?= e($c['piscina']) ?>
        </p>
        <p class="badge"><?= (int)$c['n_socios'] ?> nadador<?= $c['n_socios'] == 1 ? '' : 'es' ?> del club</p>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?p=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php comp_render_footer(); ?>
```

- [ ] **Step 2: Anexar estilos al CSS**

Añadir al final de `competiciones/public/assets/css/main.css`:

```css
.comp-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem; }
.comp-card { display:block; color:inherit; text-decoration:none; transition: transform 0.15s; }
.comp-card:hover { transform: translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.1); }
.comp-card h2 { margin:0 0 0.5rem; font-size:1.1rem; color:var(--blue); }
.comp-card .meta { margin:0.25rem 0; color:var(--gray); font-size:0.9rem; }
.comp-card .badge { display:inline-block; padding:0.25rem 0.6rem; background:var(--blue); color:#fff; border-radius:12px; font-size:0.8rem; }
.pagination { display:flex; gap:0.5rem; justify-content:center; margin-top:2rem; }
.pagination a { padding:0.5rem 0.75rem; background:#fff; border:1px solid #ddd; border-radius:4px; text-decoration:none; color:var(--text); }
.pagination a.active { background:var(--blue); color:#fff; border-color:var(--blue); }
@media (max-width: 768px) {
  .comp-grid { grid-template-columns: 1fr; }
}
```

- [ ] **Step 3: Verificar**

http://localhost:8082/ — tarjetas en grid, paginación si N > 12. Click en una tarjeta lleva a `/competicion.php?id=X` (404 hasta Task 10).

- [ ] **Step 4: Commit**

```bash
git add competiciones/public/index.php competiciones/public/assets/css/main.css
git commit -m "feat(comp): landing pública con grid de competiciones paginado"
```

---

## Task 10: Public — detalle de competición

**Files:**
- Create: `competiciones/public/competicion.php`

- [ ] **Step 1: Crear `competiciones/public/competicion.php`**

```php
<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM competiciones WHERE id = ?');
$stmt->execute([$id]);
$comp = $stmt->fetch();
if (!$comp) { http_response_code(404); die('Competición no encontrada.'); }

$res = $pdo->prepare('
    SELECT r.prueba, r.tiempo, r.tiempo_seg, r.fase, r.puesto, r.nombre_nadador,
           u.slug, u.perfil_publico
    FROM competicion_resultados r
    LEFT JOIN users u ON u.id = r.user_id
    WHERE r.competicion_id = ?
    ORDER BY r.prueba, r.tiempo_seg ASC
');
$res->execute([$id]);
$resultados = $res->fetchAll();

$por_prueba = [];
foreach ($resultados as $r) $por_prueba[$r['prueba']][] = $r;

$labelPrueba = function(string $p): string {
    $dist = (int)preg_replace('/\D/', '', $p);
    $estilo = substr($p, -1);
    $est_map = ['L'=>'Libre','E'=>'Espalda','B'=>'Braza','M'=>'Mariposa','X'=>'Estilos'];
    return $dist . ' ' . ($est_map[$estilo] ?? '');
};

comp_render_header($comp['nombre']);
?>
<h1><?= e($comp['nombre']) ?></h1>
<p class="meta">
  <?= e(date('d/m/Y', strtotime($comp['fecha_inicio']))) ?>
  <?php if ($comp['fecha_fin'] && $comp['fecha_fin'] !== $comp['fecha_inicio']): ?>
    — <?= e(date('d/m/Y', strtotime($comp['fecha_fin']))) ?>
  <?php endif; ?>
  · <?= e($comp['lugar'] ?: 'Sin lugar') ?>
  · Piscina <?= e($comp['piscina']) ?>
</p>
<?php if ($comp['url_origen']): ?>
  <p><a href="<?= e($comp['url_origen']) ?>" target="_blank" rel="noopener">Ver en swimrankings.net ↗</a></p>
<?php endif; ?>

<?php if (!$por_prueba): ?>
  <div class="empty-state"><p>Sin resultados.</p></div>
<?php else: ?>
  <?php foreach ($por_prueba as $prueba => $rows): ?>
    <div class="card">
      <h2><?= e($labelPrueba($prueba)) ?></h2>
      <table>
        <thead><tr><th>Pos</th><th>Nadador</th><th>Tiempo</th><th>Fase</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= $r['puesto'] ? (int)$r['puesto'] : '—' ?></td>
              <td>
                <?php if ($r['slug'] && (int)$r['perfil_publico'] === 1): ?>
                  <a href="/nadador.php?slug=<?= e($r['slug']) ?>"><?= e($r['nombre_nadador']) ?></a>
                <?php else: ?>
                  <?= e($r['nombre_nadador']) ?>
                <?php endif; ?>
              </td>
              <td><?= e($r['tiempo']) ?></td>
              <td><?= e($r['fase']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php comp_render_footer(); ?>
```

- [ ] **Step 2: Verificar**

Click en una tarjeta de la landing. Expected: detalle con tablas agrupadas por prueba. Nombres como link si `perfil_publico=1`.

- [ ] **Step 3: Verificar 404**

http://localhost:8082/competicion.php?id=99999 → 404.

- [ ] **Step 4: Commit**

```bash
git add competiciones/public/competicion.php
git commit -m "feat(comp): página detalle de competición con resultados agrupados"
```

---

## Task 11: Public — ficha de nadador

**Files:**
- Create: `competiciones/public/nadador.php`

- [ ] **Step 1: Crear `competiciones/public/nadador.php`**

```php
<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') { http_response_code(404); die('Nadador no encontrado.'); }

$stmt = $pdo->prepare('
    SELECT id, nombre, liga, sexo, perfil_publico
    FROM users
    WHERE slug = ? AND rol = "socio" AND estado = "activo"
');
$stmt->execute([$slug]);
$nadador = $stmt->fetch();
if (!$nadador || (int)$nadador['perfil_publico'] !== 1) {
    http_response_code(404); die('Nadador no encontrado.');
}

$mejores = $pdo->prepare('
    SELECT prueba, piscina, MIN(tiempo_seg) AS best_seg, tiempo, fecha_marca, lugar
    FROM marcas
    WHERE user_id = ?
    GROUP BY prueba, piscina
    ORDER BY prueba, piscina
');
$mejores->execute([$nadador['id']]);
$marcas_por_prueba = [];
foreach ($mejores as $m) $marcas_por_prueba[$m['prueba']][$m['piscina']] = $m;

$ult = $pdo->prepare('
    SELECT c.id, c.nombre, c.fecha_inicio, r.prueba, r.tiempo, r.puesto
    FROM competicion_resultados r
    JOIN competiciones c ON c.id = r.competicion_id
    WHERE r.user_id = ?
    ORDER BY c.fecha_inicio DESC, r.tiempo_seg ASC
    LIMIT 20
');
$ult->execute([$nadador['id']]);
$ultimas = $ult->fetchAll();

$liga_map = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Júnior','absoluto'=>'Absoluto','master'=>'Máster'];

comp_render_header($nadador['nombre']);
?>
<h1><?= e($nadador['nombre']) ?></h1>
<p class="meta">
  <?= e($liga_map[$nadador['liga']] ?? $nadador['liga'] ?? '—') ?>
  · <?= $nadador['sexo'] === 'M' ? 'Masculino' : 'Femenino' ?>
</p>

<div class="card">
  <h2>Mejores marcas</h2>
  <?php if (!$marcas_por_prueba): ?>
    <p>Sin marcas registradas aún.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Prueba</th><th>25m</th><th>50m</th></tr></thead>
      <tbody>
        <?php foreach ($marcas_por_prueba as $prueba => $piscinas): ?>
          <tr>
            <td><?= e($prueba) ?></td>
            <td><?= isset($piscinas['25m']) ? e($piscinas['25m']['tiempo']) : '—' ?></td>
            <td><?= isset($piscinas['50m']) ? e($piscinas['50m']['tiempo']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Últimas competiciones</h2>
  <?php if (!$ultimas): ?>
    <p>Sin participaciones registradas.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Competición</th><th>Prueba</th><th>Tiempo</th><th>Pos</th></tr></thead>
      <tbody>
        <?php foreach ($ultimas as $u): ?>
          <tr>
            <td><?= e(date('d/m/Y', strtotime($u['fecha_inicio']))) ?></td>
            <td><a href="/competicion.php?id=<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?></a></td>
            <td><?= e($u['prueba']) ?></td>
            <td><?= e($u['tiempo']) ?></td>
            <td><?= $u['puesto'] ? (int)$u['puesto'] : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php comp_render_footer(); ?>
```

- [ ] **Step 2: Verificar**

Desde el detalle de una competición, click en un nombre. Expected: ficha con mejores marcas + últimas competiciones.

- [ ] **Step 3: Verificar 404**

http://localhost:8082/nadador.php?slug=no-existe → 404.

- [ ] **Step 4: Commit**

```bash
git add competiciones/public/nadador.php
git commit -m "feat(comp): ficha pública de nadador con mejores marcas y últimas competiciones"
```

---

## Task 12: Toggle perfil_publico en /socio/perfil

**Files:**
- Modify: `public/socio/perfil.php`

- [ ] **Step 1: Localizar el fichero**

Abrir `public/socio/perfil.php`. Localizar el `<form>` que actualiza datos del socio y el handler POST asociado.

- [ ] **Step 2: Asegurar que el SELECT carga `perfil_publico`**

La query que carga `$user` al inicio debe incluir `perfil_publico`. Si usa `SELECT *` ya cubre. Si lista columnas, añadir `perfil_publico`.

- [ ] **Step 3: Añadir el checkbox al formulario**

Dentro del `<form>`, antes del botón submit, añadir:

```php
<p>
  <label>
    <input type="checkbox" name="perfil_publico" value="1"
      <?= ((int)($user['perfil_publico'] ?? 1) === 1) ? 'checked' : '' ?>>
    Mostrar mi ficha pública en el subdominio de competiciones
  </label>
  <br><small>Si desmarcas, tu nombre aparecerá en los listados pero no será clicable, y tu ficha personal estará oculta.</small>
</p>
```

- [ ] **Step 4: Procesar el toggle en el handler POST**

En el bloque del UPDATE, añadir el binding:

```php
$perfil_publico = isset($_POST['perfil_publico']) ? 1 : 0;
```

Modificar el `UPDATE users SET ...` para incluir `, perfil_publico = ?` y añadir `$perfil_publico` al array de parámetros del `execute()` en su posición correspondiente.

- [ ] **Step 5: Verificar**

Login como socio en http://localhost:8080 → `/socio/perfil`. Desmarcar la casilla → guardar. Ir a http://localhost:8082/nadador.php?slug=<tu-slug> → 404. Volver a marcar → ficha visible.

- [ ] **Step 6: Commit**

```bash
git add public/socio/perfil.php
git commit -m "feat(socio): toggle perfil_publico para subdominio competiciones"
```

---

## Task 13: CLI mass import

**Files:**
- Create: `scripts/swimrankings_import_all.php`

- [ ] **Step 1: Crear el script CLI**

```php
<?php
/**
 * CLI: recorre todos los users con swimrankings_id y reimporta sus meets.
 * Uso: docker compose exec app-comp php scripts/swimrankings_import_all.php [YYYY-MM-DD]
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../competiciones/includes/swimrankings.php';

if (PHP_SAPI !== 'cli') { die("Solo CLI\n"); }

$since = $argv[1] ?? null;

$users = $pdo->query('SELECT id, nombre, swimrankings_id FROM users WHERE swimrankings_id IS NOT NULL')->fetchAll();
echo "Procesando " . count($users) . " usuarios...\n";

$total = ['meets'=>0, 'insertados'=>0, 'actualizados'=>0, 'descartados'=>0];
foreach ($users as $u) {
    echo "- {$u['nombre']} (swr_id={$u['swimrankings_id']})... ";
    $meets = swr_get_athlete_meets((int)$u['swimrankings_id'], $since);
    $stats = ['meets'=>count($meets), 'ins'=>0, 'upd'=>0, 'des'=>0];
    foreach ($meets as $m) {
        $r = swr_import_meet($pdo, $m['meet_id']);
        if ($r['error']) continue;
        $stats['ins'] += $r['insertados'];
        $stats['upd'] += $r['actualizados'];
        $stats['des'] += $r['descartados'];
    }
    echo "{$stats['meets']} meets, +{$stats['ins']}/~{$stats['upd']}\n";
    $total['meets']        += $stats['meets'];
    $total['insertados']   += $stats['ins'];
    $total['actualizados'] += $stats['upd'];
    $total['descartados']  += $stats['des'];
    sleep(1); // throttle suave
}

echo "\nTotal: {$total['meets']} meets, {$total['insertados']} nuevos, {$total['actualizados']} actualizados, {$total['descartados']} descartados.\n";
```

- [ ] **Step 2: Ejecutar y verificar**

```bash
docker compose exec app-comp php scripts/swimrankings_import_all.php
```

Expected: línea por usuario, totales al final.

Opcional con filtro de fecha:
```bash
docker compose exec app-comp php scripts/swimrankings_import_all.php 2026-01-01
```

- [ ] **Step 3: Commit**

```bash
git add scripts/swimrankings_import_all.php
git commit -m "feat(comp): CLI mass import swimrankings"
```

---

## Validación final

- [ ] **Smoke test completo**

1. http://localhost:8082/ — landing con competiciones
2. Click en una competición — detalle con resultados agrupados
3. Click en un nadador — ficha pública
4. http://localhost:8082/admin/ → login → ver panel
5. http://localhost:8080/socio/perfil — toggle perfil_publico funciona

- [ ] **No hay regresiones en sitio principal**

Probar http://localhost:8080/ y verificar que todas las páginas (login, socio panel, admin) siguen funcionando.

- [ ] **Commit final si queda algo**

```bash
git status
```

## Decisiones diferidas (post-MVP)

- Renombrar carpeta `competiciones/` si el usuario decide otro nombre final (find/replace global)
- Configurar el subdominio real en producción (Apache vhost + DNS)
- Mockup visual propio si se quiere diferenciar más visualmente
- Filtros por temporada/piscina en landing si N > 30 competiciones
- Importar resultados de no-socios si interesa para rankings provinciales
- Gráfico de evolución de tiempos en ficha de nadador
