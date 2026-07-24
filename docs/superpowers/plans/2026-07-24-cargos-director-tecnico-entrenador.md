# Cargos "Director técnico" y "Entrenador" — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir dos cargos directiva nuevos — `director_tecnico` (casi-admin, todo `/admin/*` excepto `usuarios.php`, más todo `/directiva/*` a nivel `vocal`) y `entrenador` (solo `/admin/asistencia.php` + `/admin/asistencia_historial.php`) — sin tocar el acceso admin existente.

**Architecture:** Nueva función `require_admin_area(array $cargos_extra = [])` en `includes/auth.php` (calco de `require_admin()`/`require_cargo()`, admite `is_admin() || cargo en lista`). Las 16 páginas `admin/*.php` (excepto `usuarios.php`) sustituyen `require_admin()` por esta función. `directiva/actas.php` y `directiva/socios.php` añaden `director_tecnico` a su `require_cargo([...])`; `directiva/cuestiones.php` añade el chequeo a `$esDirectiva`. `includes/layout.php` gana enlaces de navegación condicionales y sidebar admin filtrado por cargo.

**Tech Stack:** PHP 8.4 + PDO, MySQL 8, Apache, Docker Compose. Sin framework de tests automáticos: verificación manual vía Docker en `http://localhost:8080` + `php -l` para sintaxis + queries SQL directas para comprobar accesos.

**Testing approach:** Cada tarea de código PHP se verifica con `docker compose exec -T web php -l <archivo>` (sintaxis) y, cuando aplica, una petición `curl` autenticada o navegación manual para confirmar 200/403 según el cargo. La Task 6 hace el recorrido end-to-end con usuarios de prueba reales insertados en BD.

**Spec:** [docs/superpowers/specs/2026-07-24-cargos-director-tecnico-entrenador-design.md](../specs/2026-07-24-cargos-director-tecnico-entrenador-design.md)

## Global Constraints

- Cargos existentes (`presidente`, `secretario`, `tesorero`, `vocal`, `responsable_menores`, `encargado_redes`) y su comportamiento actual: **sin cambios**.
- `usuarios.php` (altas/bajas/aprobación de cuentas): **admin-only**, ningún cargo nuevo debe tocarlo.
- `es_directiva()` sigue significando junta directiva real (presidente/secretario/tesorero/vocal): **sin cambios** en su definición.
- `$puedeDecidir` en `cuestiones.php` (solo presidente decide propuestas): **sin cambios**.
- Límites: `director_tecnico` → 1 titular activo. `entrenador` → 3 titulares activos.

---

## File Structure

| Ruta | Acción | Responsabilidad |
|------|--------|-----------------|
| `migrations/018_cargos_director_entrenador.sql` | Crear | `ALTER TABLE cargos MODIFY` ENUM ampliado |
| `schema.sql` | Modificar | ENUM `cargos.cargo` ampliado (paridad con migración para `docker compose up` limpio) |
| `includes/auth.php` | Modificar | Nueva función `require_admin_area()`; `cargos_limites()` y `cargo_label()` ganan 2 entradas |
| `public/admin/*.php` (16 archivos) | Modificar | `require_admin()` → `require_admin_area([...])` |
| `public/directiva/actas.php` | Modificar | `require_cargo([...])` gana `director_tecnico` |
| `public/directiva/socios.php` | Modificar | `require_cargo([...])` gana `director_tecnico` |
| `public/directiva/cuestiones.php` | Modificar | `$esDirectiva` gana chequeo `director_tecnico` |
| `includes/layout.php` | Modificar | Navbar (desktop + mobile) enlaces condicionales; sidebar admin filtrado por cargo |

---

## Task 1: Migración BD — ENUM `cargos.cargo` ampliado

**Files:**
- Create: `migrations/018_cargos_director_entrenador.sql`
- Modify: `schema.sql:33-40`

**Interfaces:**
- Produces: valores ENUM `'director_tecnico'` y `'entrenador'` disponibles en columna `cargos.cargo`, consumidos por Task 2 (`cargos_limites()`, `cargo_label()`) y todas las tareas posteriores.

- [ ] **Step 1: Crear la migración**

Crear `migrations/018_cargos_director_entrenador.sql`:

```sql
-- 018: Cargos "Director técnico" y "Entrenador"

ALTER TABLE cargos MODIFY COLUMN cargo ENUM(
    'presidente',
    'secretario',
    'tesorero',
    'vocal',
    'responsable_menores',
    'encargado_redes',
    'director_tecnico',
    'entrenador'
) NOT NULL;
```

- [ ] **Step 2: Actualizar `schema.sql` con el mismo ENUM**

En `schema.sql`, el bloque actual (líneas 33-40):

```sql
    cargo ENUM(
        'presidente',
        'secretario',
        'tesorero',
        'vocal',
        'responsable_menores',
        'encargado_redes'
    ) NOT NULL,
```

Reemplazar por:

```sql
    cargo ENUM(
        'presidente',
        'secretario',
        'tesorero',
        'vocal',
        'responsable_menores',
        'encargado_redes',
        'director_tecnico',
        'entrenador'
    ) NOT NULL,
```

- [ ] **Step 3: Aplicar la migración en el contenedor Docker en marcha**

```bash
docker compose up -d
docker compose exec -T db mysql -uroot -proot cn_medio_cudeyo < migrations/018_cargos_director_entrenador.sql
```

- [ ] **Step 4: Verificar el ENUM en BD**

```bash
docker compose exec -T db mysql -uroot -proot cn_medio_cudeyo -e "SHOW COLUMNS FROM cargos LIKE 'cargo';"
```

Expected: columna `Type` = `enum('presidente','secretario','tesorero','vocal','responsable_menores','encargado_redes','director_tecnico','entrenador')`.

- [ ] **Step 5: Commit**

```bash
git add migrations/018_cargos_director_entrenador.sql schema.sql
git commit -m "feat(db): add director_tecnico y entrenador al ENUM cargos"
```

---

## Task 2: `includes/auth.php` — `require_admin_area()` + límites + labels

**Files:**
- Modify: `includes/auth.php:192-225`

**Interfaces:**
- Consumes: `require_login()`, `is_admin()`, `cargos_activos()` (ya existentes en el mismo archivo).
- Produces: `require_admin_area(array $cargos_extra = []): void` — usado por Task 3. `cargos_limites()` y `cargo_label()` con las 2 entradas nuevas — usados por `admin/cargos.php` (sin cambios de código ahí, ya es dinámico) y Task 5 (sidebar/navbar).

- [ ] **Step 1: Añadir `require_admin_area()` después de `require_cargo()`**

En `includes/auth.php`, justo después del cierre de `require_cargo()` (línea 192, `}`) y antes del comentario `// Límite máximo de titulares activos por cargo` (línea 194):

Bloque actual:
```php
function require_cargo(array $cargos_validos): void
{
    require_login();
    if (is_admin()) return;
    $cargos = cargos_activos();
    foreach ($cargos_validos as $c) {
        if (in_array($c, $cargos, true)) return;
    }
    http_response_code(403);
    render_header('Acceso denegado');
    echo '<div class="container page-content"><div class="alert alert-danger">No tienes permiso para acceder a esta página.</div></div>';
    render_footer();
    exit;
}

// Límite máximo de titulares activos por cargo
function cargos_limites(): array
```

Insertar entre ambas funciones:

```php
function require_cargo(array $cargos_validos): void
{
    require_login();
    if (is_admin()) return;
    $cargos = cargos_activos();
    foreach ($cargos_validos as $c) {
        if (in_array($c, $cargos, true)) return;
    }
    http_response_code(403);
    render_header('Acceso denegado');
    echo '<div class="container page-content"><div class="alert alert-danger">No tienes permiso para acceder a esta página.</div></div>';
    render_footer();
    exit;
}

// Restringe al área admin: admin siempre pasa, o algún cargo de $cargos_extra
// (p.ej. director_tecnico, entrenador). Usado en public/admin/*.php en vez
// de require_admin() cuando la página debe ser accesible a esos cargos.
function require_admin_area(array $cargos_extra = []): void
{
    require_login();
    if (is_admin()) return;
    $cargos = cargos_activos();
    foreach ($cargos_extra as $c) {
        if (in_array($c, $cargos, true)) return;
    }
    http_response_code(403);
    render_header('Acceso denegado');
    echo '<div class="container page-content"><div class="alert alert-danger">No tienes permiso para acceder a esta página.</div></div>';
    render_footer();
    exit;
}

// Límite máximo de titulares activos por cargo
function cargos_limites(): array
```

- [ ] **Step 2: Añadir límites en `cargos_limites()`**

Bloque actual (líneas ~198-205, ahora desplazadas ~18 líneas por el Step 1):

```php
    return [
        'presidente'          => 1,
        'secretario'          => 1,
        'tesorero'            => 1,
        'responsable_menores' => 1,
        'vocal'               => 5,
        'encargado_redes'     => 3,
    ];
```

Reemplazar por:

```php
    return [
        'presidente'          => 1,
        'secretario'          => 1,
        'tesorero'            => 1,
        'responsable_menores' => 1,
        'vocal'               => 5,
        'encargado_redes'     => 3,
        'director_tecnico'    => 1,
        'entrenador'          => 3,
    ];
```

- [ ] **Step 3: Añadir labels en `cargo_label()`**

Bloque actual:

```php
    return match ($cargo) {
        'presidente'          => 'Presidente',
        'secretario'          => 'Secretario',
        'tesorero'            => 'Tesorero',
        'vocal'               => 'Vocal',
        'responsable_menores' => 'Responsable de protección del menor',
        'encargado_redes'     => 'Encargado de redes sociales',
        default               => ucfirst($cargo),
    };
```

Reemplazar por:

```php
    return match ($cargo) {
        'presidente'          => 'Presidente',
        'secretario'          => 'Secretario',
        'tesorero'            => 'Tesorero',
        'vocal'               => 'Vocal',
        'responsable_menores' => 'Responsable de protección del menor',
        'encargado_redes'     => 'Encargado de redes sociales',
        'director_tecnico'    => 'Director técnico',
        'entrenador'          => 'Entrenador',
        default               => ucfirst($cargo),
    };
```

- [ ] **Step 4: Verificar sintaxis**

```bash
docker compose exec -T web php -l /var/www/html/includes/auth.php
```

Expected: `No syntax errors detected in /var/www/html/includes/auth.php`.

- [ ] **Step 5: Verificar en runtime con script temporal**

Crear `tmp_verify_cargos.php` en la raíz del repo:

```php
<?php
require_once __DIR__ . '/includes/auth.php';

$limites = cargos_limites();
$labels_ok = cargo_label('director_tecnico') === 'Director técnico'
    && cargo_label('entrenador') === 'Entrenador';
$limites_ok = ($limites['director_tecnico'] ?? null) === 1
    && ($limites['entrenador'] ?? null) === 3;
$disponibles_ok = in_array('director_tecnico', cargos_disponibles(), true)
    && in_array('entrenador', cargos_disponibles(), true);

$ok = $labels_ok && $limites_ok && $disponibles_ok;
echo $ok ? "ALL PASS\n" : "FAIL: labels=$labels_ok limites=$limites_ok disponibles=$disponibles_ok\n";
exit($ok ? 0 : 1);
```

```bash
docker compose exec -T web php /var/www/html/tmp_verify_cargos.php
```

Expected: `ALL PASS`.

- [ ] **Step 6: Eliminar script temporal**

```bash
rm tmp_verify_cargos.php
```

- [ ] **Step 7: Commit**

```bash
git add includes/auth.php
git commit -m "feat(auth): require_admin_area() + cargos director_tecnico/entrenador"
```

---

## Task 3: Gating de páginas `admin/*.php`

**Files:**
- Modify: `public/admin/biblioteca.php:3`
- Modify: `public/admin/asistencia.php:6`
- Modify: `public/admin/asistencia_historial.php:6`
- Modify: `public/admin/cargos.php:6`
- Modify: `public/admin/marcas.php:7`
- Modify: `public/admin/rfen_importar.php:7`
- Modify: `public/admin/rfen_buscar.php:5`
- Modify: `public/admin/noticias.php:6`
- Modify: `public/admin/comunicaciones.php:6`
- Modify: `public/admin/contacto.php:6`
- Modify: `public/admin/puntos-aqua.php:6`
- Modify: `public/admin/ranking.php:6`
- Modify: `public/admin/ranking-edades.php:6`
- Modify: `public/admin/records.php:6`
- Modify: `public/admin/incidencias.php:7`
- Modify: `public/admin/incidencia_descargar.php:6`
- Modify: `public/admin/config.php:6`
- No tocar: `public/admin/usuarios.php` (sigue `require_admin()`), `public/admin/volver-admin.php` (no tiene gate)

**Interfaces:**
- Consumes: `require_admin_area(array $cargos_extra = [])` de Task 2.

- [ ] **Step 1: Reemplazar `require_admin();` por `require_admin_area(['director_tecnico', 'entrenador']);` en las 2 páginas de asistencia**

En `public/admin/asistencia.php` línea 6 y `public/admin/asistencia_historial.php` línea 6, la línea:

```php
require_admin();
```

pasa a:

```php
require_admin_area(['director_tecnico', 'entrenador']);
```

- [ ] **Step 2: Reemplazar `require_admin();` por `require_admin_area(['director_tecnico']);` en las 14 páginas restantes**

Aplicar el mismo reemplazo (`require_admin();` → `require_admin_area(['director_tecnico']);`) en cada uno de estos 14 archivos, en la línea indicada arriba:

`biblioteca.php`, `cargos.php`, `marcas.php`, `rfen_importar.php`, `rfen_buscar.php`, `noticias.php`, `comunicaciones.php`, `contacto.php`, `puntos-aqua.php`, `ranking.php`, `ranking-edades.php`, `records.php`, `incidencias.php`, `incidencia_descargar.php`, `config.php`.

En cada archivo la línea es exactamente `require_admin();` (única ocurrencia en el archivo) — sustituir por `require_admin_area(['director_tecnico']);`.

- [ ] **Step 3: Verificar sintaxis de las 16 páginas modificadas**

```bash
for f in biblioteca asistencia asistencia_historial cargos marcas rfen_importar rfen_buscar noticias comunicaciones contacto puntos-aqua ranking ranking-edades records incidencias incidencia_descargar config; do
  docker compose exec -T web php -l /var/www/html/public/admin/$f.php
done
```

Expected: 16 líneas `No syntax errors detected in ...`.

- [ ] **Step 4: Verificar que `usuarios.php` sigue intacto**

```bash
grep -n "require_admin" public/admin/usuarios.php
```

Expected: `require_admin();` (sin cambios).

- [ ] **Step 5: Verificar que ningún archivo quedó con `require_admin();` fuera de `usuarios.php`**

```bash
grep -rln "require_admin();" public/admin/
```

Expected: solo `public/admin/usuarios.php`.

- [ ] **Step 6: Commit**

```bash
git add public/admin/
git commit -m "feat(admin): abrir admin/* a director_tecnico y entrenador

usuarios.php sigue admin-only. asistencia + asistencia_historial
tambien accesibles a entrenador. Resto de admin/* accesible a
director_tecnico."
```

---

## Task 4: Acceso `director_tecnico` a `directiva/*.php`

**Files:**
- Modify: `public/directiva/actas.php:6`
- Modify: `public/directiva/socios.php:6`
- Modify: `public/directiva/cuestiones.php:10`

**Interfaces:**
- Consumes: `require_cargo(array $cargos_validos)`, `is_admin()`, `es_directiva()`, `user_tiene_cargo(string $cargo)` — todas ya existentes.

- [ ] **Step 1: `actas.php` — añadir `director_tecnico` a `require_cargo`**

Línea 6 de `public/directiva/actas.php`, actual:

```php
require_cargo(['presidente', 'secretario', 'tesorero', 'vocal']);
```

Reemplazar por:

```php
require_cargo(['presidente', 'secretario', 'tesorero', 'vocal', 'director_tecnico']);
```

- [ ] **Step 2: `socios.php` — añadir `director_tecnico` a `require_cargo`**

Línea 6 de `public/directiva/socios.php`, actual:

```php
require_cargo(['presidente', 'secretario', 'tesorero', 'vocal']);
```

Reemplazar por:

```php
require_cargo(['presidente', 'secretario', 'tesorero', 'vocal', 'director_tecnico']);
```

> Nota: `$puedeEditarCuotas = is_admin() || user_tiene_cargo('tesorero');` (línea 8) NO se toca — `director_tecnico` ve la página pero no edita cuotas, igual que `vocal` hoy.

- [ ] **Step 3: `cuestiones.php` — `$esDirectiva` incluye `director_tecnico`**

Línea 10 de `public/directiva/cuestiones.php`, actual:

```php
$esDirectiva  = is_admin() || es_directiva();
```

Reemplazar por:

```php
$esDirectiva  = is_admin() || es_directiva() || user_tiene_cargo('director_tecnico');
```

> Nota: `$puedeDecidir = is_admin() || user_tiene_cargo('presidente');` (línea 11) NO se toca — `director_tecnico` no decide propuestas.

- [ ] **Step 4: Verificar sintaxis**

```bash
docker compose exec -T web php -l /var/www/html/public/directiva/actas.php
docker compose exec -T web php -l /var/www/html/public/directiva/socios.php
docker compose exec -T web php -l /var/www/html/public/directiva/cuestiones.php
```

Expected: 3 líneas `No syntax errors detected in ...`.

- [ ] **Step 5: Commit**

```bash
git add public/directiva/actas.php public/directiva/socios.php public/directiva/cuestiones.php
git commit -m "feat(directiva): director_tecnico ve actas/socios/cuestiones

Mismo nivel que vocal: ve, no edita cuotas/actas, no decide
cuestiones (eso sigue siendo solo presidente/tesorero/secretario)."
```

---

## Task 5: Navegación — `includes/layout.php`

**Files:**
- Modify: `includes/layout.php:60-69` (navbar desktop)
- Modify: `includes/layout.php:125-136` (navbar mobile — offsets cambian tras Step 1, verificar por contenido no por número exacto)
- Modify: `includes/layout.php:333-394` (sidebar admin, offsets cambian tras Steps previos)

**Interfaces:**
- Consumes: `user_tiene_cargo(string $cargo): bool`, `is_admin(): bool` — ya existentes.

- [ ] **Step 1: Navbar desktop — enlace condicional para director_tecnico/entrenador**

En `includes/layout.php`, bloque actual (dentro de `render_header`, cerca de la línea 60-69):

```php
            <?php if ($user): ?>
              <?php if ($isAdmin): ?>
                <a href="/admin/usuarios" <?= str_starts_with($activePage, 'admin') ? 'class="active"' : '' ?>>Administración</a>
              <?php else: ?>
                <a href="/socio/panel" <?= str_starts_with($activePage, 'socio') ? 'class="active"' : '' ?>>Mi panel</a>
              <?php endif; ?>
              <?php if (!empty(cargos_activos())): ?>
                <a href="/directiva/socios" <?= str_starts_with($activePage, 'directiva') ? 'class="active"' : '' ?>>Directiva</a>
              <?php endif; ?>
            <?php endif; ?>
```

Reemplazar por:

```php
            <?php if ($user): ?>
              <?php if ($isAdmin): ?>
                <a href="/admin/usuarios" <?= str_starts_with($activePage, 'admin') ? 'class="active"' : '' ?>>Administración</a>
              <?php else: ?>
                <a href="/socio/panel" <?= str_starts_with($activePage, 'socio') ? 'class="active"' : '' ?>>Mi panel</a>
                <?php if (user_tiene_cargo('director_tecnico')): ?>
                  <a href="/admin/marcas" <?= str_starts_with($activePage, 'admin') ? 'class="active"' : '' ?>>Administración</a>
                <?php elseif (user_tiene_cargo('entrenador')): ?>
                  <a href="/admin/asistencia" <?= str_starts_with($activePage, 'admin') ? 'class="active"' : '' ?>>Asistencia</a>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!empty(cargos_activos())): ?>
                <a href="/directiva/socios" <?= str_starts_with($activePage, 'directiva') ? 'class="active"' : '' ?>>Directiva</a>
              <?php endif; ?>
            <?php endif; ?>
```

- [ ] **Step 2: Navbar mobile — mismo enlace condicional**

Bloque actual (dentro del mismo `render_header`, sección `<!-- Mobile menu -->`):

```php
        <?php if ($user): ?>
          <?php if ($isAdmin): ?>
            <a href="/admin/usuarios">Administración</a>
          <?php else: ?>
            <a href="/socio/panel">Mi panel</a>
            <?php if (is_nadador_activo()): ?>
              <a href="/socio/ranking">Ranking mi liga</a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty(cargos_activos())): ?>
            <a href="/directiva/socios">Directiva</a>
          <?php endif; ?>
        <?php endif; ?>
```

Reemplazar por:

```php
        <?php if ($user): ?>
          <?php if ($isAdmin): ?>
            <a href="/admin/usuarios">Administración</a>
          <?php else: ?>
            <a href="/socio/panel">Mi panel</a>
            <?php if (is_nadador_activo()): ?>
              <a href="/socio/ranking">Ranking mi liga</a>
            <?php endif; ?>
            <?php if (user_tiene_cargo('director_tecnico')): ?>
              <a href="/admin/marcas">Administración</a>
            <?php elseif (user_tiene_cargo('entrenador')): ?>
              <a href="/admin/asistencia">Asistencia</a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty(cargos_activos())): ?>
            <a href="/directiva/socios">Directiva</a>
          <?php endif; ?>
        <?php endif; ?>
```

- [ ] **Step 3: Sidebar admin — filtrar enlaces por cargo**

En `includes/layout.php`, dentro de `render_admin_layout(string $activePage, callable $content)`, justo después de `{` (antes de `?>`):

Bloque actual:

```php
  function render_admin_layout(string $activePage, callable $content): void
  {
?>
  <div class="admin-layout">
    <aside class="admin-sidebar">
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Usuarios</div>
        <a href="/admin/usuarios" class="<?= $activePage === 'usuarios' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i> Gestión de usuarios
        </a>
        <a href="/admin/cargos" class="<?= $activePage === 'cargos' ? 'active' : '' ?>">
          <i class="bi bi-person-badge-fill"></i> Cargos directiva
        </a>
        <a href="/admin/asistencia" class="<?= $activePage === 'asistencia' ? 'active' : '' ?>">
          <i class="bi bi-clipboard-check-fill"></i> Pasar lista
        </a>
        <a href="/admin/asistencia_historial" class="<?= $activePage === 'asistencia_historial' ? 'active' : '' ?>">
          <i class="bi bi-calendar-check"></i> Historial asistencia
        </a>
        <a href="/admin/incidencias" class="<?= $activePage === 'incidencias' ? 'active' : '' ?>">
          <i class="bi bi-exclamation-triangle"></i> Incidencias
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Marcas &amp; Ranking</div>
        <a href="/admin/marcas" class="<?= $activePage === 'marcas' ? 'active' : '' ?>">
          <i class="bi bi-stopwatch-fill"></i> Gestión de marcas
        </a>
        <a href="/admin/ranking" class="<?= $activePage === 'ranking' ? 'active' : '' ?>">
          <i class="bi bi-trophy-fill"></i> Ranking general
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Contenido</div>
        <a href="/admin/noticias" class="<?= $activePage === 'noticias' ? 'active' : '' ?>">
          <i class="bi bi-newspaper"></i> Noticias
        </a>
        <a href="/admin/comunicaciones" class="<?= $activePage === 'comunicaciones' ? 'active' : '' ?>">
          <i class="bi bi-megaphone-fill"></i> Comunicaciones
        </a>
        <a href="/admin/contacto" class="<?= $activePage === 'contacto' ? 'active' : '' ?>">
          <i class="bi bi-envelope-fill"></i> Mensajes de contacto
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Directiva</div>
        <a href="/directiva/socios" class="<?= $activePage === 'directiva-socios' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i> Socios y cuotas
        </a>
        <a href="/directiva/actas" class="<?= $activePage === 'directiva-actas' ? 'active' : '' ?>">
          <i class="bi bi-journal-text"></i> Actas
        </a>
        <a href="/directiva/cuestiones" class="<?= $activePage === 'directiva-cuestiones' ? 'active' : '' ?>">
          <i class="bi bi-question-circle-fill"></i> Cuestiones
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Sistema</div>
        <a href="/admin/config" class="<?= $activePage === 'config' ? 'active' : '' ?>">
          <i class="bi bi-sliders"></i> Configuración
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Web pública</div>
        <a href="/" target="_blank"><i class="bi bi-globe"></i> Ver web</a>
      </div>
    </aside>
    <main class="admin-main">
      <?php $content(); ?>
    </main>
  </div>
<?php
  }
```

Reemplazar por (añade `$isAdmin`/`$isDirTec`/`$isEntrenador` y envuelve cada bloque):

```php
  function render_admin_layout(string $activePage, callable $content): void
  {
    $isAdmin      = is_admin();
    $isDirTec     = $isAdmin || user_tiene_cargo('director_tecnico');
    $isEntrenador = $isDirTec || user_tiene_cargo('entrenador');
?>
  <div class="admin-layout">
    <aside class="admin-sidebar">
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Usuarios</div>
        <?php if ($isAdmin): ?>
        <a href="/admin/usuarios" class="<?= $activePage === 'usuarios' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i> Gestión de usuarios
        </a>
        <?php endif; ?>
        <?php if ($isDirTec): ?>
        <a href="/admin/cargos" class="<?= $activePage === 'cargos' ? 'active' : '' ?>">
          <i class="bi bi-person-badge-fill"></i> Cargos directiva
        </a>
        <?php endif; ?>
        <?php if ($isEntrenador): ?>
        <a href="/admin/asistencia" class="<?= $activePage === 'asistencia' ? 'active' : '' ?>">
          <i class="bi bi-clipboard-check-fill"></i> Pasar lista
        </a>
        <a href="/admin/asistencia_historial" class="<?= $activePage === 'asistencia_historial' ? 'active' : '' ?>">
          <i class="bi bi-calendar-check"></i> Historial asistencia
        </a>
        <?php endif; ?>
        <?php if ($isDirTec): ?>
        <a href="/admin/incidencias" class="<?= $activePage === 'incidencias' ? 'active' : '' ?>">
          <i class="bi bi-exclamation-triangle"></i> Incidencias
        </a>
        <?php endif; ?>
      </div>
      <?php if ($isDirTec): ?>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Marcas &amp; Ranking</div>
        <a href="/admin/marcas" class="<?= $activePage === 'marcas' ? 'active' : '' ?>">
          <i class="bi bi-stopwatch-fill"></i> Gestión de marcas
        </a>
        <a href="/admin/ranking" class="<?= $activePage === 'ranking' ? 'active' : '' ?>">
          <i class="bi bi-trophy-fill"></i> Ranking general
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Contenido</div>
        <a href="/admin/noticias" class="<?= $activePage === 'noticias' ? 'active' : '' ?>">
          <i class="bi bi-newspaper"></i> Noticias
        </a>
        <a href="/admin/comunicaciones" class="<?= $activePage === 'comunicaciones' ? 'active' : '' ?>">
          <i class="bi bi-megaphone-fill"></i> Comunicaciones
        </a>
        <a href="/admin/contacto" class="<?= $activePage === 'contacto' ? 'active' : '' ?>">
          <i class="bi bi-envelope-fill"></i> Mensajes de contacto
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Directiva</div>
        <a href="/directiva/socios" class="<?= $activePage === 'directiva-socios' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i> Socios y cuotas
        </a>
        <a href="/directiva/actas" class="<?= $activePage === 'directiva-actas' ? 'active' : '' ?>">
          <i class="bi bi-journal-text"></i> Actas
        </a>
        <a href="/directiva/cuestiones" class="<?= $activePage === 'directiva-cuestiones' ? 'active' : '' ?>">
          <i class="bi bi-question-circle-fill"></i> Cuestiones
        </a>
      </div>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Sistema</div>
        <a href="/admin/config" class="<?= $activePage === 'config' ? 'active' : '' ?>">
          <i class="bi bi-sliders"></i> Configuración
        </a>
      </div>
      <?php endif; ?>
      <div class="admin-sidebar-section">
        <div class="admin-sidebar-title">Web pública</div>
        <a href="/" target="_blank"><i class="bi bi-globe"></i> Ver web</a>
      </div>
    </aside>
    <main class="admin-main">
      <?php $content(); ?>
    </main>
  </div>
<?php
  }
```

- [ ] **Step 4: Verificar sintaxis**

```bash
docker compose exec -T web php -l /var/www/html/includes/layout.php
```

Expected: `No syntax errors detected in /var/www/html/includes/layout.php`.

- [ ] **Step 5: Commit**

```bash
git add includes/layout.php
git commit -m "feat(layout): navbar + sidebar admin filtrados por cargo

director_tecnico/entrenador ganan enlace de navegacion al area
admin. Sidebar admin oculta secciones/enlaces segun cargo."
```

---

## Task 6: Verificación manual end-to-end

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Levantar Docker fresco**

```bash
docker compose up -d
docker compose ps
```

Expected: servicios `web`, `db`, `phpmyadmin` arriba.

- [ ] **Step 2: Crear 2 usuarios de prueba (uno por cargo nuevo) si no existen**

```bash
docker compose exec -T db mysql -uroot -proot cn_medio_cudeyo <<'SQL'
INSERT INTO users (nombre, email, password_hash, rol, estado, liga, sexo, nadador_activo)
VALUES
  ('Test DirTec', 'dirtec.test@example.com', '$2y$10$abcdefghijklmnopqrstuuJZ8L8L8L8L8L8L8L8L8L8L8L8L8L8L8', 'socio', 'activo', 'absoluto', 'M', 0),
  ('Test Entrenador', 'entrenador.test@example.com', '$2y$10$abcdefghijklmnopqrstuuJZ8L8L8L8L8L8L8L8L8L8L8L8L8L8L8', 'socio', 'activo', 'absoluto', 'M', 0)
ON DUPLICATE KEY UPDATE nombre=nombre;
SQL
```

> El hash de arriba es un placeholder inválido — para login real usa `admin/usuarios.php` en el navegador para poner una contraseña conocida, o genera un hash válido con `docker compose exec -T web php -r "echo password_hash('Test1234!', PASSWORD_DEFAULT);"` y sustitúyelo en el INSERT antes de ejecutarlo.

- [ ] **Step 3: Asignar cargos vía UI**

Login como admin (`admin@cnmediocudeyo.es` / `Admin1234!`) en `http://localhost:8080/login` → `/admin/cargos` → asignar `Director técnico` a "Test DirTec" y `Entrenador` a "Test Entrenador" (fecha inicio hoy).

Verificar:
- El desplegable del formulario incluye "Director técnico (máx 1)" y "Entrenador (máx 3)".
- Tras asignar, aparecen en la tabla de titulares activos con el label correcto.

- [ ] **Step 4: Login como "Test DirTec" y comprobar accesos**

Login en `http://localhost:8080/login` con ese usuario.

Verificar:
- Navbar muestra "Mi panel" + "Administración" (no "Directiva" si `cargos_activos()` solo tiene `director_tecnico`... en realidad sí aparece "Directiva" porque `cargos_activos()` no está vacío — confirmar que aparece).
- `/admin/marcas`, `/admin/ranking`, `/admin/noticias`, `/admin/config`, `/admin/cargos`, `/admin/incidencias`, `/admin/asistencia` → 200 OK, sidebar visible con todas las secciones excepto "Gestión de usuarios".
- `/admin/usuarios` → 403 "No tienes permiso para acceder a esta página."
- `/directiva/socios`, `/directiva/actas`, `/directiva/cuestiones` → 200 OK. En `socios.php` el botón de editar cuota NO aparece (solo tesorero/admin). En `cuestiones.php` no aparece la opción de decidir/aprobar propuestas (solo presidente/admin).

- [ ] **Step 5: Login como "Test Entrenador" y comprobar accesos**

Verificar:
- Navbar muestra "Mi panel" + "Asistencia" (no "Administración").
- `/admin/asistencia`, `/admin/asistencia_historial` → 200 OK, sidebar solo con "Pasar lista" + "Historial asistencia".
- `/admin/marcas`, `/admin/usuarios`, `/admin/cargos`, `/admin/config` → 403.
- `/directiva/socios` → 403 (entrenador no está en `require_cargo` de esa página — comportamiento esperado, igual que `responsable_menores`/`encargado_redes` hoy).

- [ ] **Step 6: Verificar admin real sigue sin cambios**

Login como `admin@cnmediocudeyo.es`.

Verificar: acceso 200 a las 17 páginas `admin/*.php` + `directiva/*.php`, sidebar completo, sin regresiones.

- [ ] **Step 7: Logs limpios**

```bash
docker compose logs --tail=100 web | grep -iE "error|warning|notice"
```

Expected: ninguna línea relacionada con los archivos tocados.

- [ ] **Step 8: Limpiar usuarios de prueba**

```bash
docker compose exec -T db mysql -uroot -proot cn_medio_cudeyo -e "
DELETE FROM cargos WHERE user_id IN (SELECT id FROM users WHERE email IN ('dirtec.test@example.com','entrenador.test@example.com'));
DELETE FROM users WHERE email IN ('dirtec.test@example.com','entrenador.test@example.com');
"
```

- [ ] **Step 9: Commit final si hubo ajustes**

```bash
git add -A
git commit -m "fix(cargos): ajustes verificacion manual director_tecnico/entrenador"
```

Si no hubo ajustes, saltar este paso.

---

## Self-review

**Spec coverage:**
- Migración ENUM + schema.sql → Task 1 ✅
- `require_admin_area()` → Task 2 ✅
- 16 páginas `admin/*.php` (excepto `usuarios.php`) → Task 3 ✅
- `asistencia.php`/`asistencia_historial.php` accesibles a `entrenador` → Task 3 Step 1 ✅
- `directiva/*.php` accesible a `director_tecnico` nivel `vocal` (ver, no decidir/editar) → Task 4 ✅
- `cargos_limites()`/`cargo_label()` → Task 2 ✅
- Navbar (desktop + mobile) + sidebar admin filtrados → Task 5 ✅
- Riesgo aceptado (`director_tecnico` en `cargos.php`) → cubierto explícitamente en Task 3 (incluido en la lista de páginas) y verificado en Task 6 Step 4 ✅
- Fuera de alcance (inconsistencia navbar "Directiva" para cargos no-junta, esquema `asistencia` sin cambios) → no se tocan, consistente con spec ✅

**Placeholder scan:** sin TBDs. El hash placeholder en Task 6 Step 2 está marcado explícitamente como inválido con instrucción de sustitución, no es un placeholder de plan sino un valor de prueba real que el ejecutor debe generar.

**Type consistency:** `require_admin_area(array $cargos_extra = []): void` — misma firma en Task 2 (definición) y Task 3 (todas las llamadas). `user_tiene_cargo(string $cargo, ?int $user_id = null): bool` ya existente, usado igual en Tasks 4 y 5.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-24-cargos-director-tecnico-entrenador.md`. Two execution options:

1. **Subagent-Driven (recommended)** — dispatch fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
