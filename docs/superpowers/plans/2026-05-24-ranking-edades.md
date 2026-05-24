# Ranking por edades — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir un toggle "Por liga / Por edad" en el ranking de socio y admin que muestra récords por edad deportiva (10–18 años) en dos vistas: top-10 por edad cuando hay prueba seleccionada, o matriz 9×18 cuando no.

**Architecture:** Dos páginas hermanas (`ranking-edades.php` en `public/socio/` y `public/admin/`) reusan layout y helpers existentes. Edad calculada en SQL con `YEAR(fecha_marca) - YEAR(fecha_nacimiento)`. Window functions (`ROW_NUMBER() OVER`) de MySQL 8 para particionar por edad. Toggle visual en cabecera del ranking original sin tocar su SQL.

**Tech Stack:** PHP 8.4 + PDO, MySQL 8 (CTE + window functions), Apache, Docker Compose. Sin framework de tests automáticos: verificación manual via Docker en `http://localhost:8080`.

**Testing approach:** Este proyecto no usa PHPUnit. Cada tarea incluye una verificación manual ejecutando Docker y comprobando salida HTTP / DOM. Para helpers PHP puros se escribe un script PHP de verificación que se ejecuta con `docker compose exec` y se elimina al final.

**Spec:** [docs/superpowers/specs/2026-05-24-ranking-edades-design.md](../specs/2026-05-24-ranking-edades-design.md)

---

## File Structure

| Ruta | Acción | Responsabilidad |
|------|--------|-----------------|
| `includes/auth.php` | Modificar | Añadir helper `edad_deportiva()` puro |
| `public/assets/css/main.css` | Modificar | Añadir clases `.ranking-tabs`, `.edad-block`, `.matriz-edades` |
| `public/socio/ranking.php` | Modificar | Insertar toggle "Por liga / Por edad" arriba del bloque filtros |
| `public/admin/ranking.php` | Modificar | Insertar toggle equivalente |
| `public/socio/ranking-edades.php` | Crear | Página completa: filtros, Vista A (con prueba), Vista B (matriz) |
| `public/admin/ranking-edades.php` | Crear | Equivalente para admin (usa `render_admin_layout`) |
| `CLAUDE.md` | Modificar | Añadir filas en tabla "Estado de páginas" |

---

## Task 1: Helper `edad_deportiva()` en includes/auth.php

**Files:**
- Modify: `includes/auth.php` (añadir función al final del archivo, antes del `?>` si existe o al final)

- [ ] **Step 1: Localizar el bloque de helpers de tiempo en `includes/auth.php`**

Hay funciones `tiempo_a_segundos`, `segundos_a_tiempo`, `format_prueba`, `format_liga` en este archivo (líneas ~238 a ~290). Añadir la nueva función `edad_deportiva` inmediatamente después de `format_liga` para agrupar helpers de dominio.

- [ ] **Step 2: Añadir la función `edad_deportiva()`**

Editar `includes/auth.php`, justo después de la función `format_liga`:

```php
/**
 * Edad deportiva FINA/RFEN: año(fecha_marca) - año(fecha_nacimiento).
 * No depende de día/mes. Devuelve null si falta algún dato.
 *
 * @param string|null $fecha_marca       Fecha de la marca (YYYY-MM-DD).
 * @param string|null $fecha_nacimiento  Fecha de nacimiento del nadador.
 * @return int|null  Edad deportiva o null si datos inválidos.
 */
function edad_deportiva(?string $fecha_marca, ?string $fecha_nacimiento): ?int
{
    if (!$fecha_marca || !$fecha_nacimiento) return null;
    $y_m = (int)substr($fecha_marca, 0, 4);
    $y_n = (int)substr($fecha_nacimiento, 0, 4);
    if ($y_m <= 0 || $y_n <= 0) return null;
    return $y_m - $y_n;
}
```

- [ ] **Step 3: Verificar manualmente con script temporal**

Crear `tmp_verify_edad.php` en la raíz del repo:

```php
<?php
require_once __DIR__ . '/includes/auth.php';

$cases = [
    ['2024-06-10', '2010-01-15', 14],
    ['2024-06-10', '2010-12-31', 14],
    ['2024-01-05', '2010-12-31', 14],
    ['2024-06-10', null, null],
    [null, '2010-01-15', null],
    ['', '2010-01-15', null],
    ['2024-06-10', '', null],
    ['2024-06-10', '0000-00-00', null],
];

$fails = 0;
foreach ($cases as [$fm, $fn, $exp]) {
    $got = edad_deportiva($fm, $fn);
    $ok = $got === $exp;
    if (!$ok) $fails++;
    printf("[%s] edad(%s, %s) = %s (expected %s)\n",
        $ok ? 'PASS' : 'FAIL',
        var_export($fm, true), var_export($fn, true),
        var_export($got, true), var_export($exp, true)
    );
}
echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAIL\n";
exit($fails === 0 ? 0 : 1);
```

Ejecutar:

```bash
docker compose up -d
docker compose exec -T web php /var/www/html/../tmp_verify_edad.php
# (Si el bind mount monta el repo en /var/www/html, ajustar:)
docker compose exec -T web bash -c "cd / && php /tmp_no_existe.php" 2>/dev/null || \
docker compose exec -T web php /var/www/tmp_verify_edad.php 2>/dev/null || \
php tmp_verify_edad.php
```

Expected output (última línea): `ALL PASS` y exit code 0.

> Nota: el contenedor monta el repo en `/var/www/html`. Si el script vive en la raíz del repo, dentro del contenedor está en `/var/www/html/tmp_verify_edad.php`. Comando real:
> ```bash
> docker compose exec -T web php /var/www/html/tmp_verify_edad.php
> ```

- [ ] **Step 4: Eliminar script temporal**

```bash
rm tmp_verify_edad.php
```

- [ ] **Step 5: Commit**

```bash
git add includes/auth.php
git commit -m "feat(auth): add edad_deportiva() helper

Helper FINA-style edad = year(fecha_marca) - year(fecha_nacimiento).
Devuelve null cuando faltan datos. Base para ranking por edades."
```

---

## Task 2: CSS — tabs y bloques de edad

**Files:**
- Modify: `public/assets/css/main.css` (añadir bloque al final, antes del `@media` final si lo hay; si no, al final del archivo)

- [ ] **Step 1: Añadir clases al final de `public/assets/css/main.css`**

```css
/* === Ranking edades === */
.ranking-tabs {
  display: inline-flex;
  border: 2px solid var(--blue);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 18px;
}
.ranking-tabs a {
  padding: 8px 18px;
  font-weight: 600;
  color: var(--blue);
  background: #fff;
  text-decoration: none;
  font-size: 14px;
  transition: background .15s, color .15s;
}
.ranking-tabs a.tab--active {
  background: var(--blue);
  color: #fff;
}
.ranking-tabs a:not(.tab--active):hover {
  background: #eef4ff;
}

.edad-block {
  margin-bottom: 28px;
}
.edad-block h2 {
  font-size: 18px;
  margin: 0 0 10px;
  padding-bottom: 6px;
  border-bottom: 2px solid var(--blue);
  color: var(--blue);
}
.edad-block .empty {
  color: #888;
  font-style: italic;
  padding: 12px 0;
}

.matriz-edades {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.matriz-edades th,
.matriz-edades td {
  border: 1px solid #e5e7eb;
  padding: 6px 8px;
  text-align: center;
  vertical-align: middle;
}
.matriz-edades thead th {
  background: var(--blue);
  color: #fff;
  font-weight: 700;
  position: sticky;
  top: 0;
}
.matriz-edades th.row-edad {
  background: #eef4ff;
  font-weight: 700;
  color: var(--blue);
}
.matriz-edades td.cell-record {
  padding: 4px 6px;
}
.matriz-edades td.cell-record a {
  display: block;
  text-decoration: none;
  color: var(--text);
}
.matriz-edades td.cell-record a:hover {
  background: #f0f7ff;
}
.matriz-edades .cell-time {
  font-family: monospace;
  font-weight: 700;
  color: var(--blue);
  font-size: 13px;
}
.matriz-edades .cell-name {
  font-size: 11px;
  color: #555;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 90px;
}
.matriz-edades .cell-empty {
  color: #ccc;
}
```

- [ ] **Step 2: Verificar el CSS se sirve correctamente**

```bash
docker compose up -d
curl -sf http://localhost:8080/assets/css/main.css | grep -c "ranking-tabs"
```

Expected: `1` (o más).

- [ ] **Step 3: Commit**

```bash
git add public/assets/css/main.css
git commit -m "style: add CSS for ranking por edades

Clases .ranking-tabs (toggle Liga/Edad), .edad-block (secciones
por edad) y .matriz-edades (tabla 9x18 récords sin prueba)."
```

---

## Task 3: Toggle en `public/socio/ranking.php`

**Files:**
- Modify: `public/socio/ranking.php` (línea ~168 — justo después del `<h1>` "Ranking — ...")

- [ ] **Step 1: Localizar el `<h1>` del ranking de socio**

Está en `public/socio/ranking.php` cerca de la línea 168:

```php
<h1 style="margin-bottom:6px;">Ranking — <?= $filterLiga ? e(format_liga($filterLiga)) : 'Todas las categorías' ?></h1>
```

- [ ] **Step 2: Insertar el toggle justo debajo del `<h1>`**

```php
<h1 style="margin-bottom:6px;">Ranking — <?= $filterLiga ? e(format_liga($filterLiga)) : 'Todas las categorías' ?></h1>

<div class="ranking-tabs">
  <a href="/socio/ranking" class="tab--active">Por liga</a>
  <a href="/socio/ranking-edades" class="js-loading-link">Por edad</a>
</div>
```

- [ ] **Step 3: Verificar que el toggle aparece en HTML**

```bash
docker compose up -d
# Login como socio activo desde el navegador en http://localhost:8080/login
# O verifica el HTML del archivo:
grep -c "ranking-tabs" public/socio/ranking.php
```

Expected: `1`.

Verificación visual: navegar a `http://localhost:8080/socio/ranking` y ver dos pills "Por liga" (azul) / "Por edad" (blanco).

- [ ] **Step 4: Commit**

```bash
git add public/socio/ranking.php
git commit -m "feat(socio/ranking): add 'Por edad' toggle in header

Tab visual junto al titulo: 'Por liga' (activo) y 'Por edad'
(link a /socio/ranking-edades)."
```

---

## Task 4: Toggle en `public/admin/ranking.php`

**Files:**
- Modify: `public/admin/ranking.php` (línea ~172 — justo después del `<h1>Ranking general</h1>`)

- [ ] **Step 1: Localizar el `<h1>` del ranking admin**

En `public/admin/ranking.php` cerca de la línea 172:

```php
<h1>Ranking general</h1>
```

- [ ] **Step 2: Insertar el toggle justo debajo**

```php
<h1>Ranking general</h1>

<div class="ranking-tabs">
  <a href="/admin/ranking" class="tab--active">Por liga</a>
  <a href="/admin/ranking-edades" class="js-loading-link">Por edad</a>
</div>
```

- [ ] **Step 3: Verificar**

```bash
grep -c "ranking-tabs" public/admin/ranking.php
```

Expected: `1`.

Visual: `http://localhost:8080/admin/ranking` con login admin (`admin@cnmediocudeyo.es` / `Admin1234!`).

- [ ] **Step 4: Commit**

```bash
git add public/admin/ranking.php
git commit -m "feat(admin/ranking): add 'Por edad' toggle in header"
```

---

## Task 5: `public/socio/ranking-edades.php` — esqueleto, filtros, validación

**Files:**
- Create: `public/socio/ranking-edades.php`

- [ ] **Step 1: Crear el archivo con cabecera, validación de parámetros y form de filtros**

Contenido inicial completo del archivo (sin lógica SQL todavía — solo filtros y placeholder de resultados; el SQL se añade en Tasks 6 y 7):

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
require_nadador_activo();
$user = current_user();

$PRUEBAS = ['50L', '100L', '200L', '400L', '800L', '1500L', '50E', '100E', '200E', '50B', '100B', '200B', '50M', '100M', '200M', '100X', '200X', '400X'];

// --- Filtros (defaults igual que ranking actual donde aplica) ---
$filterPrueba    = $_GET['prueba']   ?? '';
$filterPiscina   = $_GET['piscina']  ?? '25m';
$filterSexo      = array_key_exists('sexo', $_GET) ? $_GET['sexo'] : ($user['sexo'] ?? '');
$filterNadador   = $_GET['nadador']  ?? '1';

// Temporadas (igual ranking actual)
$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporadas_disp = [];
for ($y = $current_year; $y >= 2012; $y--) {
    $temporadas_disp[] = $y . '-' . substr((string)($y + 1), 2);
}
$filterTemporada = $_GET['temporada'] ?? 'todas';  // Default histórico

// Validación
if (!in_array($filterPrueba, $PRUEBAS, true)) $filterPrueba = '';
if (!in_array($filterPiscina, ['25m', '50m'], true)) $filterPiscina = '25m';
if (!in_array($filterSexo, ['M', 'F', ''], true)) $filterSexo = '';
if (!in_array($filterNadador, ['1', '0', ''], true)) $filterNadador = '1';
if ($filterTemporada !== 'todas' && !in_array($filterTemporada, $temporadas_disp, true)) {
    $filterTemporada = 'todas';
}

// Datos: se rellenan en Tasks 6 (Vista A) y 7 (Vista B)
$vista_a_grupos = [];   // [10 => [filas...], 11 => [...], ...]
$vista_b_matriz = [];   // [10 => ['50L' => fila, ...], 11 => [...], ...]

render_header('Ranking por edad', 'socio-ranking');
?>

<div class="container page-content">
  <h1 style="margin-bottom:6px;">Ranking por edad</h1>

  <div class="ranking-tabs">
    <a href="/socio/ranking" class="js-loading-link">Por liga</a>
    <a href="/socio/ranking-edades" class="tab--active">Por edad</a>
  </div>

  <!-- Filtros -->
  <div class="filters-bar" style="flex-direction:column;gap:16px;">
    <form method="GET" class="filters-form js-loading-form">
      <input type="hidden" name="sexo" value="<?= e($filterSexo) ?>">
      <input type="hidden" name="nadador" value="<?= e($filterNadador) ?>">

      <div class="form-group">
        <label class="form-label">Prueba</label>
        <select name="prueba" class="form-control">
          <option value="">Todas las pruebas</option>
          <?php foreach ($PRUEBAS as $p): ?>
            <option value="<?= e($p) ?>" <?= $filterPrueba === $p ? 'selected' : '' ?>><?= e(format_prueba($p)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Piscina</label>
        <select name="piscina" class="form-control">
          <option value="25m" <?= $filterPiscina === '25m' ? 'selected' : '' ?>>25m</option>
          <option value="50m" <?= $filterPiscina === '50m' ? 'selected' : '' ?>>50m</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Temporada</label>
        <select name="temporada" class="form-control">
          <option value="todas" <?= $filterTemporada === 'todas' ? 'selected' : '' ?>>Todas</option>
          <?php foreach ($temporadas_disp as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterTemporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="align-self:flex-end;display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary">Filtrar</button>
      </div>
    </form>

    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;border-top:1px solid #eee;padding-top:14px;">
      <?php
      $base_filters = [
        'prueba'    => $filterPrueba,
        'piscina'   => $filterPiscina,
        'sexo'      => $filterSexo,
        'nadador'   => $filterNadador,
        'temporada' => $filterTemporada,
      ];
      $sexo_opts = ['M' => 'Masc.', 'F' => 'Fem.', '' => 'Todos'];
      ?>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Sexo</label>
        <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
          <?php foreach ($sexo_opts as $sv => $sl):
            $sp = $base_filters;
            $sp['sexo'] = $sv;
            $active = $filterSexo === $sv;
          ?>
            <a href="?<?= http_build_query($sp) ?>"
              class="js-loading-link"
              style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;">
              <?= $sl ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php $nadador_opts = ['1' => 'Activos', '0' => 'No activos', '' => 'Todos']; ?>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Nadador</label>
        <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
          <?php foreach ($nadador_opts as $nv => $nl):
            $np = $base_filters;
            $np['nadador'] = $nv;
            $active = $filterNadador === $nv;
          ?>
            <a href="?<?= http_build_query($np) ?>"
              class="js-loading-link"
              style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;">
              <?= $nl ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Aquí se inserta Vista A o Vista B en Tasks 6 y 7 -->
  <div id="ranking-edades-resultados" style="margin-top:24px;">
    <p class="text-muted">Pendiente de implementar.</p>
  </div>

  <script>
  document.querySelectorAll('.js-loading-form select').forEach(select => {
    select.addEventListener('change', function () { this.form.requestSubmit(); });
  });
  </script>
</div>

<?php render_footer(); ?>
```

- [ ] **Step 2: Verificar que la página carga sin errores PHP**

```bash
docker compose up -d
# Login como socio activo en /login (sustituir por credenciales reales) y luego:
curl -sf -c /tmp/cookies.txt -b /tmp/cookies.txt \
  -d "email=<user@ejemplo>&password=<pw>" http://localhost:8080/login.php
curl -sf -b /tmp/cookies.txt http://localhost:8080/socio/ranking-edades.php | head -40
```

Expected: HTML que contiene `<h1>Ranking por edad</h1>` y el form. Sin "Fatal error" ni warnings.

Alternativa rápida: revisar logs Apache:
```bash
docker compose logs --tail=20 web | grep -i "error\|warning"
```

Expected: ninguna línea con PHP errors.

- [ ] **Step 3: Commit**

```bash
git add public/socio/ranking-edades.php
git commit -m "feat(socio): scaffold ranking-edades page

Filtros, validacion y form. Sin SQL todavia. Toggle marca
'Por edad' como activo. Vistas A/B se anaden en siguientes tasks."
```

---

## Task 6: Vista A — top-10 por edad (con prueba seleccionada)

**Files:**
- Modify: `public/socio/ranking-edades.php` (añadir bloque SQL antes de `render_header`, y reemplazar placeholder de resultados)

- [ ] **Step 1: Añadir bloque SQL antes de `render_header`**

Insertar después de la validación de filtros y antes de `$vista_a_grupos = [];`:

```php
// --- Consulta Vista A: solo si hay prueba seleccionada ---
if ($filterPrueba !== '') {
    $where_extra = '';
    $params = [$filterPiscina, $filterPrueba];

    if ($filterSexo !== '') {
        $where_extra .= ' AND u.sexo = ?';
        $params[] = $filterSexo;
    }
    if ($filterTemporada !== 'todas') {
        $where_extra .= ' AND m.temporada = ?';
        $params[] = $filterTemporada;
    }
    if ($filterNadador !== '') {
        $where_extra .= ' AND u.nadador_activo = ?';
        $params[] = (int)$filterNadador;
    }

    $sql_a = "
        WITH ranked AS (
            SELECT
                m.id, m.tiempo, m.tiempo_seg, m.fecha_marca, m.lugar, m.piscina,
                u.id AS uid, u.nombre, u.sexo, u.fecha_nacimiento,
                YEAR(u.fecha_nacimiento) AS anio_nac,
                (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) AS edad,
                ROW_NUMBER() OVER (
                    PARTITION BY (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento))
                    ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC, u.nombre ASC
                ) AS rn
            FROM marcas m
            JOIN users u ON u.id = m.user_id
            WHERE u.estado = 'activo'
              AND u.fecha_nacimiento IS NOT NULL
              AND m.piscina = ?
              AND m.prueba  = ?
              $where_extra
              AND (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) BETWEEN 10 AND 18
        )
        SELECT * FROM ranked WHERE rn <= 10
        ORDER BY edad ASC, rn ASC
    ";
    $stmt = $pdo->prepare($sql_a);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $vista_a_grupos[(int)$row['edad']][] = $row;
    }
}
```

- [ ] **Step 2: Reemplazar el placeholder de resultados**

Sustituir el bloque:

```php
<div id="ranking-edades-resultados" style="margin-top:24px;">
    <p class="text-muted">Pendiente de implementar.</p>
</div>
```

Por:

```php
<div id="ranking-edades-resultados" style="margin-top:24px;">
  <?php if ($filterPrueba !== ''): ?>
    <h2 style="margin-bottom:18px;">
      <?= e(format_prueba($filterPrueba)) ?> · Piscina <?= e($filterPiscina) ?>
      <?php if ($filterSexo === 'M') echo ' · Masculino'; ?>
      <?php if ($filterSexo === 'F') echo ' · Femenino'; ?>
    </h2>

    <?php for ($edad = 10; $edad <= 18; $edad++): ?>
      <section class="edad-block">
        <h2>Edad <?= $edad ?></h2>
        <?php $filas = $vista_a_grupos[$edad] ?? []; ?>
        <?php if (!$filas): ?>
          <div class="empty">Sin marcas registradas a esta edad.</div>
        <?php else: ?>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th style="width:50px;">Pos.</th>
                  <th>Tiempo</th>
                  <th>Nadador</th>
                  <th>Año nac.</th>
                  <th>Fecha marca</th>
                  <th>Lugar</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($filas as $i => $row): ?>
                  <tr <?= $row['uid'] == $user['id'] ? 'style="background:#eef2ff;"' : '' ?>>
                    <td>
                      <span class="rank-pos <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>">
                        <?= $i + 1 ?>
                        <?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : '')) ?>
                      </span>
                    </td>
                    <td><span class="mark-time"><?= e($row['tiempo']) ?></span></td>
                    <td>
                      <strong><?= e($row['nombre']) ?></strong>
                      <?= $row['uid'] == $user['id'] ? '<span class="badge badge-blue" style="margin-left:6px;">Tú</span>' : '' ?>
                    </td>
                    <td><?= (int)$row['anio_nac'] ?></td>
                    <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($row['fecha_marca'])) ?></td>
                    <td class="text-sm text-muted"><?= e($row['lugar'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endfor; ?>

  <?php else: ?>
    <p class="text-muted">Vista matriz pendiente (Task 7).</p>
  <?php endif; ?>
</div>
```

- [ ] **Step 3: Verificar Vista A en navegador**

```bash
docker compose up -d
```

Abrir `http://localhost:8080/socio/ranking-edades.php?prueba=50L&piscina=25m&temporada=todas`.

Verificar:
- 9 secciones (Edad 10 a Edad 18).
- Bloques sin datos muestran "Sin marcas registradas a esta edad."
- Bloques con datos muestran tabla con tiempo, nombre, año nac, fecha, lugar.
- Ordenación por tiempo ascendente dentro de cada edad.

Verificar logs sin errores:
```bash
docker compose logs --tail=30 web | grep -iE "error|warning|notice"
```

Expected: vacío.

- [ ] **Step 4: Verificación de SQL — query directa**

```bash
docker compose exec -T db mysql -uroot -proot cn_medio_cudeyo -e "
WITH ranked AS (
  SELECT u.nombre, m.tiempo, m.fecha_marca,
    (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) AS edad,
    ROW_NUMBER() OVER (
      PARTITION BY (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento))
      ORDER BY m.tiempo_seg ASC
    ) AS rn
  FROM marcas m JOIN users u ON u.id=m.user_id
  WHERE u.estado='activo' AND u.fecha_nacimiento IS NOT NULL
    AND m.piscina='25m' AND m.prueba='50L'
    AND (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) BETWEEN 10 AND 18
)
SELECT edad, rn, nombre, tiempo, fecha_marca FROM ranked WHERE rn<=10 ORDER BY edad, rn LIMIT 20;
"
```

Expected: filas agrupadas por edad ascendente, rn 1..N por edad. Tiempos consistentes con BD.

> Si la BD no tiene marcas con `fecha_nacimiento` rellenado, el resultado puede ser vacío. Eso es esperado — el plan es estructural; los datos pueden faltar.

- [ ] **Step 5: Commit**

```bash
git add public/socio/ranking-edades.php
git commit -m "feat(socio): ranking-edades Vista A — top-10 por edad

Cuando hay prueba seleccionada renderiza 9 bloques (edad 10-18)
con top-10 marcas absolutas en cada uno. Window function
ROW_NUMBER() para particionar. Excluye nadadores sin fecha_nacimiento."
```

---

## Task 7: Vista B — matriz récords (sin prueba)

**Files:**
- Modify: `public/socio/ranking-edades.php` (extender bloque SQL y bloque render)

- [ ] **Step 1: Añadir consulta Vista B después del bloque Vista A**

Insertar inmediatamente después del bloque `if ($filterPrueba !== '') { ... }`:

```php
// --- Consulta Vista B: matriz cuando NO hay prueba seleccionada ---
if ($filterPrueba === '') {
    $where_extra_b = '';
    $params_b = [$filterPiscina];

    if ($filterSexo !== '') {
        $where_extra_b .= ' AND u.sexo = ?';
        $params_b[] = $filterSexo;
    }
    if ($filterTemporada !== 'todas') {
        $where_extra_b .= ' AND m.temporada = ?';
        $params_b[] = $filterTemporada;
    }
    if ($filterNadador !== '') {
        $where_extra_b .= ' AND u.nadador_activo = ?';
        $params_b[] = (int)$filterNadador;
    }

    $sql_b = "
        WITH ranked AS (
            SELECT
                m.prueba, m.tiempo, m.tiempo_seg, m.fecha_marca,
                u.id AS uid, u.nombre, YEAR(u.fecha_nacimiento) AS anio_nac,
                (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) AS edad,
                ROW_NUMBER() OVER (
                    PARTITION BY
                        (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)),
                        m.prueba
                    ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC, u.nombre ASC
                ) AS rn
            FROM marcas m
            JOIN users u ON u.id = m.user_id
            WHERE u.estado = 'activo'
              AND u.fecha_nacimiento IS NOT NULL
              AND m.piscina = ?
              $where_extra_b
              AND (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) BETWEEN 10 AND 18
        )
        SELECT * FROM ranked WHERE rn = 1
    ";
    $stmt = $pdo->prepare($sql_b);
    $stmt->execute($params_b);
    foreach ($stmt->fetchAll() as $row) {
        $vista_b_matriz[(int)$row['edad']][$row['prueba']] = $row;
    }
}
```

- [ ] **Step 2: Reemplazar el placeholder de la matriz (rama `else`)**

Sustituir:

```php
<?php else: ?>
    <p class="text-muted">Vista matriz pendiente (Task 7).</p>
<?php endif; ?>
```

Por:

```php
<?php else: ?>
  <h2 style="margin-bottom:12px;">
    Récords por edad y prueba · Piscina <?= e($filterPiscina) ?>
    <?php if ($filterSexo === 'M') echo ' · Masculino'; ?>
    <?php if ($filterSexo === 'F') echo ' · Femenino'; ?>
  </h2>
  <p class="text-muted text-sm" style="margin-bottom:12px;">
    Cada celda es la mejor marca a esa edad y prueba. Click para ver el top-10 completo.
  </p>

  <div class="table-wrapper">
    <table class="matriz-edades">
      <thead>
        <tr>
          <th>Edad</th>
          <?php foreach ($PRUEBAS as $p): ?>
            <th><?= e($p) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php for ($edad = 10; $edad <= 18; $edad++): ?>
          <tr>
            <th class="row-edad"><?= $edad ?></th>
            <?php foreach ($PRUEBAS as $p):
              $row = $vista_b_matriz[$edad][$p] ?? null;
              if (!$row):
            ?>
              <td class="cell-empty">—</td>
            <?php else:
              $link = '?' . http_build_query(array_filter([
                'prueba'    => $p,
                'piscina'   => $filterPiscina,
                'sexo'      => $filterSexo,
                'nadador'   => $filterNadador,
                'temporada' => $filterTemporada,
              ], static fn($v) => $v !== '' && $v !== null));
            ?>
              <td class="cell-record">
                <a href="<?= e($link) ?>" class="js-loading-link" title="Top-10 edad <?= $edad ?> · <?= e(format_prueba($p)) ?>">
                  <span class="cell-time"><?= e($row['tiempo']) ?></span><br>
                  <span class="cell-name"><?= e($row['nombre']) ?> '<?= str_pad((string)((int)$row['anio_nac'] % 100), 2, '0', STR_PAD_LEFT) ?></span>
                </a>
              </td>
            <?php endif; endforeach; ?>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
```

- [ ] **Step 3: Verificar matriz en navegador**

Abrir `http://localhost:8080/socio/ranking-edades.php?piscina=25m&temporada=todas` (sin `prueba`).

Verificar:
- Tabla 9 filas × 19 columnas (Edad + 18 pruebas).
- Celdas con `—` cuando no hay marca.
- Celdas con datos: tiempo en monoespacio, nombre + año '14 abajo.
- Click en celda → carga Vista A con esa prueba filtrada.
- Filtros sticky: cambiar sexo → matriz se filtra.

Logs sin errores:
```bash
docker compose logs --tail=30 web | grep -iE "error|warning"
```

- [ ] **Step 4: Commit**

```bash
git add public/socio/ranking-edades.php
git commit -m "feat(socio): ranking-edades Vista B — matriz 9x18

Sin prueba seleccionada renderiza tabla con filas=edad (10-18)
y columnas=18 pruebas. Cada celda = mejor marca + nadador +
ano nacimiento. Click navega a Vista A con la prueba fijada."
```

---

## Task 8: `public/admin/ranking-edades.php` (espejo admin)

**Files:**
- Create: `public/admin/ranking-edades.php`

- [ ] **Step 1: Crear el archivo admin**

Contenido completo:

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();
$admin_user = current_user();

$PRUEBAS = ['50L', '100L', '200L', '400L', '800L', '1500L', '50E', '100E', '200E', '50B', '100B', '200B', '50M', '100M', '200M', '100X', '200X', '400X'];

$filterPrueba    = $_GET['prueba']   ?? '';
$filterPiscina   = $_GET['piscina']  ?? '25m';
$filterSexo      = array_key_exists('sexo', $_GET) ? $_GET['sexo'] : ($admin_user['sexo'] ?? '');
$filterNadador   = $_GET['nadador']  ?? '1';

$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporadas_disp = [];
for ($y = $current_year; $y >= 2012; $y--) {
    $temporadas_disp[] = $y . '-' . substr((string)($y + 1), 2);
}
$filterTemporada = $_GET['temporada'] ?? 'todas';

if (!in_array($filterPrueba, $PRUEBAS, true)) $filterPrueba = '';
if (!in_array($filterPiscina, ['25m', '50m'], true)) $filterPiscina = '25m';
if (!in_array($filterSexo, ['M', 'F', ''], true)) $filterSexo = '';
if (!in_array($filterNadador, ['1', '0', ''], true)) $filterNadador = '1';
if ($filterTemporada !== 'todas' && !in_array($filterTemporada, $temporadas_disp, true)) {
    $filterTemporada = 'todas';
}

$vista_a_grupos = [];
$vista_b_matriz = [];

if ($filterPrueba !== '') {
    $where_extra = '';
    $params = [$filterPiscina, $filterPrueba];
    if ($filterSexo !== '')        { $where_extra .= ' AND u.sexo = ?';            $params[] = $filterSexo; }
    if ($filterTemporada !== 'todas') { $where_extra .= ' AND m.temporada = ?';   $params[] = $filterTemporada; }
    if ($filterNadador !== '')     { $where_extra .= ' AND u.nadador_activo = ?'; $params[] = (int)$filterNadador; }

    $sql_a = "
        WITH ranked AS (
            SELECT
                m.id, m.tiempo, m.tiempo_seg, m.fecha_marca, m.lugar, m.piscina,
                u.id AS uid, u.nombre, u.sexo, u.fecha_nacimiento,
                YEAR(u.fecha_nacimiento) AS anio_nac,
                (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) AS edad,
                ROW_NUMBER() OVER (
                    PARTITION BY (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento))
                    ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC, u.nombre ASC
                ) AS rn
            FROM marcas m
            JOIN users u ON u.id = m.user_id
            WHERE u.estado = 'activo'
              AND u.fecha_nacimiento IS NOT NULL
              AND m.piscina = ?
              AND m.prueba  = ?
              $where_extra
              AND (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) BETWEEN 10 AND 18
        )
        SELECT * FROM ranked WHERE rn <= 10
        ORDER BY edad ASC, rn ASC
    ";
    $stmt = $pdo->prepare($sql_a);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $vista_a_grupos[(int)$row['edad']][] = $row;
    }
} else {
    $where_extra_b = '';
    $params_b = [$filterPiscina];
    if ($filterSexo !== '')         { $where_extra_b .= ' AND u.sexo = ?';            $params_b[] = $filterSexo; }
    if ($filterTemporada !== 'todas') { $where_extra_b .= ' AND m.temporada = ?';   $params_b[] = $filterTemporada; }
    if ($filterNadador !== '')      { $where_extra_b .= ' AND u.nadador_activo = ?'; $params_b[] = (int)$filterNadador; }

    $sql_b = "
        WITH ranked AS (
            SELECT
                m.prueba, m.tiempo, m.tiempo_seg, m.fecha_marca,
                u.id AS uid, u.nombre, YEAR(u.fecha_nacimiento) AS anio_nac,
                (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) AS edad,
                ROW_NUMBER() OVER (
                    PARTITION BY
                        (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)),
                        m.prueba
                    ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC, u.nombre ASC
                ) AS rn
            FROM marcas m
            JOIN users u ON u.id = m.user_id
            WHERE u.estado = 'activo'
              AND u.fecha_nacimiento IS NOT NULL
              AND m.piscina = ?
              $where_extra_b
              AND (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) BETWEEN 10 AND 18
        )
        SELECT * FROM ranked WHERE rn = 1
    ";
    $stmt = $pdo->prepare($sql_b);
    $stmt->execute($params_b);
    foreach ($stmt->fetchAll() as $row) {
        $vista_b_matriz[(int)$row['edad']][$row['prueba']] = $row;
    }
}

render_header('Ranking por edad', 'admin-ranking');
render_admin_layout('ranking', function() use ($PRUEBAS, $filterPrueba, $filterPiscina, $filterSexo, $filterNadador, $filterTemporada, $temporadas_disp, $vista_a_grupos, $vista_b_matriz, $admin_user) {
?>

<h1 style="margin-bottom:6px;">Ranking por edad</h1>

<div class="ranking-tabs">
  <a href="/admin/ranking" class="js-loading-link">Por liga</a>
  <a href="/admin/ranking-edades" class="tab--active">Por edad</a>
</div>

<div class="filters-bar" style="flex-direction:column;gap:16px;">
  <form method="GET" class="filters-form js-loading-form">
    <input type="hidden" name="sexo" value="<?= e($filterSexo) ?>">
    <input type="hidden" name="nadador" value="<?= e($filterNadador) ?>">

    <div class="form-group">
      <label class="form-label">Prueba</label>
      <select name="prueba" class="form-control">
        <option value="">Todas las pruebas</option>
        <?php foreach ($PRUEBAS as $p): ?>
          <option value="<?= e($p) ?>" <?= $filterPrueba === $p ? 'selected' : '' ?>><?= e(format_prueba($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Piscina</label>
      <select name="piscina" class="form-control">
        <option value="25m" <?= $filterPiscina === '25m' ? 'selected' : '' ?>>25m</option>
        <option value="50m" <?= $filterPiscina === '50m' ? 'selected' : '' ?>>50m</option>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Temporada</label>
      <select name="temporada" class="form-control">
        <option value="todas" <?= $filterTemporada === 'todas' ? 'selected' : '' ?>>Todas</option>
        <?php foreach ($temporadas_disp as $t): ?>
          <option value="<?= e($t) ?>" <?= $filterTemporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group" style="align-self:flex-end;display:flex;gap:8px;">
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </div>
  </form>

  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;border-top:1px solid #eee;padding-top:14px;">
    <?php
    $base_filters = [
      'prueba'    => $filterPrueba,
      'piscina'   => $filterPiscina,
      'sexo'      => $filterSexo,
      'nadador'   => $filterNadador,
      'temporada' => $filterTemporada,
    ];
    $sexo_opts = ['M' => 'Masc.', 'F' => 'Fem.', '' => 'Todos'];
    ?>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Sexo</label>
      <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
        <?php foreach ($sexo_opts as $sv => $sl):
          $sp = $base_filters; $sp['sexo'] = $sv;
          $active = $filterSexo === $sv;
        ?>
          <a href="?<?= http_build_query($sp) ?>"
            class="js-loading-link"
            style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;"><?= $sl ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php $nadador_opts = ['1' => 'Activos', '0' => 'No activos', '' => 'Todos']; ?>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Nadador</label>
      <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
        <?php foreach ($nadador_opts as $nv => $nl):
          $np = $base_filters; $np['nadador'] = $nv;
          $active = $filterNadador === $nv;
        ?>
          <a href="?<?= http_build_query($np) ?>"
            class="js-loading-link"
            style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;"><?= $nl ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div id="ranking-edades-resultados" style="margin-top:24px;">
  <?php if ($filterPrueba !== ''): ?>
    <h2 style="margin-bottom:18px;">
      <?= e(format_prueba($filterPrueba)) ?> · Piscina <?= e($filterPiscina) ?>
      <?php if ($filterSexo === 'M') echo ' · Masculino'; ?>
      <?php if ($filterSexo === 'F') echo ' · Femenino'; ?>
    </h2>

    <?php for ($edad = 10; $edad <= 18; $edad++): ?>
      <section class="edad-block">
        <h2>Edad <?= $edad ?></h2>
        <?php $filas = $vista_a_grupos[$edad] ?? []; ?>
        <?php if (!$filas): ?>
          <div class="empty">Sin marcas registradas a esta edad.</div>
        <?php else: ?>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th style="width:50px;">Pos.</th>
                  <th>Tiempo</th>
                  <th>Nadador</th>
                  <th>Año nac.</th>
                  <th>Fecha marca</th>
                  <th>Lugar</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($filas as $i => $row): ?>
                  <tr>
                    <td>
                      <span class="rank-pos <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>">
                        <?= $i + 1 ?>
                        <?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : '')) ?>
                      </span>
                    </td>
                    <td><span class="mark-time"><?= e($row['tiempo']) ?></span></td>
                    <td><strong><?= e($row['nombre']) ?></strong></td>
                    <td><?= (int)$row['anio_nac'] ?></td>
                    <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($row['fecha_marca'])) ?></td>
                    <td class="text-sm text-muted"><?= e($row['lugar'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endfor; ?>

  <?php else: ?>
    <h2 style="margin-bottom:12px;">
      Récords por edad y prueba · Piscina <?= e($filterPiscina) ?>
      <?php if ($filterSexo === 'M') echo ' · Masculino'; ?>
      <?php if ($filterSexo === 'F') echo ' · Femenino'; ?>
    </h2>
    <p class="text-muted text-sm" style="margin-bottom:12px;">
      Cada celda es la mejor marca a esa edad y prueba. Click para ver el top-10 completo.
    </p>

    <div class="table-wrapper">
      <table class="matriz-edades">
        <thead>
          <tr>
            <th>Edad</th>
            <?php foreach ($PRUEBAS as $p): ?>
              <th><?= e($p) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php for ($edad = 10; $edad <= 18; $edad++): ?>
            <tr>
              <th class="row-edad"><?= $edad ?></th>
              <?php foreach ($PRUEBAS as $p):
                $row = $vista_b_matriz[$edad][$p] ?? null;
                if (!$row):
              ?>
                <td class="cell-empty">—</td>
              <?php else:
                $link = '?' . http_build_query(array_filter([
                  'prueba'    => $p,
                  'piscina'   => $filterPiscina,
                  'sexo'      => $filterSexo,
                  'nadador'   => $filterNadador,
                  'temporada' => $filterTemporada,
                ], static fn($v) => $v !== '' && $v !== null));
              ?>
                <td class="cell-record">
                  <a href="<?= e($link) ?>" class="js-loading-link" title="Top-10 edad <?= $edad ?> · <?= e(format_prueba($p)) ?>">
                    <span class="cell-time"><?= e($row['tiempo']) ?></span><br>
                    <span class="cell-name"><?= e($row['nombre']) ?> '<?= str_pad((string)((int)$row['anio_nac'] % 100), 2, '0', STR_PAD_LEFT) ?></span>
                  </a>
                </td>
              <?php endif; endforeach; ?>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.js-loading-form select').forEach(select => {
  select.addEventListener('change', function () { this.form.requestSubmit(); });
});
</script>

<?php
});
render_footer();
```

- [ ] **Step 2: Verificar la página admin carga**

```bash
docker compose up -d
# Login admin en /login (admin@cnmediocudeyo.es / Admin1234!)
```

Abrir:
- `http://localhost:8080/admin/ranking-edades` (matriz)
- `http://localhost:8080/admin/ranking-edades?prueba=100L&piscina=25m` (Vista A)

Verificar:
- Sidebar admin aparece (mismo layout que /admin/ranking).
- Toggle marca "Por edad" como activo.
- Click "Por liga" lleva a /admin/ranking sin perder layout.

Logs sin errores:
```bash
docker compose logs --tail=30 web | grep -iE "error|warning"
```

- [ ] **Step 3: Commit**

```bash
git add public/admin/ranking-edades.php
git commit -m "feat(admin): add ranking por edades (espejo socio)

Misma logica (Vista A top-10 / Vista B matriz 9x18) pero
envuelto en render_admin_layout para sidebar admin."
```

---

## Task 9: Actualizar `CLAUDE.md` — tabla "Estado de páginas"

**Files:**
- Modify: `CLAUDE.md` (tabla "Estado de páginas" cerca del final)

- [ ] **Step 1: Añadir dos filas a la tabla**

En la tabla "Estado de páginas", añadir después de la fila "Socio — Incidencias":

```markdown
| Socio — Ranking por edad | `public/socio/ranking-edades.php` | ✅ |
| Admin — Ranking por edad | `public/admin/ranking-edades.php` | ✅ |
```

También añadir las rutas a la sección "Estructura de directorios" (subárbol `socio/` y `admin/`):

En `public/admin/`:
```
│   ├── ranking-edades.php ← Ranking por edad (Vista A top-10 + Vista B matriz)
```

En `public/socio/`:
```
│   ├── ranking-edades.php ← Ranking por edad (Vista A top-10 + Vista B matriz)
```

- [ ] **Step 2: Verificar el archivo lee correctamente**

```bash
grep "ranking-edades" CLAUDE.md
```

Expected: 4 líneas (2 en estructura + 2 en tabla estado).

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: add ranking-edades pages to CLAUDE.md inventory"
```

---

## Task 10: Verificación manual end-to-end

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Levantar Docker fresco**

```bash
docker compose up -d
docker compose ps
```

Expected: 3 servicios `web`, `db`, `phpmyadmin` arriba.

- [ ] **Step 2: Sembrar datos de prueba si la BD no tiene marcas con `fecha_nacimiento`**

Comprobar si hay datos suficientes:
```bash
docker compose exec -T db mysql -uroot -proot cn_medio_cudeyo -e "
SELECT COUNT(*) AS marcas_con_fnac
FROM marcas m JOIN users u ON u.id=m.user_id
WHERE u.fecha_nacimiento IS NOT NULL
  AND (YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)) BETWEEN 10 AND 18;
"
```

Si el conteo es 0, sembrar manualmente:
```bash
docker compose exec -T db mysql -uroot -proot cn_medio_cudeyo <<'SQL'
UPDATE users SET fecha_nacimiento='2010-05-12' WHERE id IN (SELECT user_id FROM marcas LIMIT 1);
SQL
```

(Esto es solo para verificación local; en producción los datos vienen de la importación RFEN.)

- [ ] **Step 3: Recorrer la golden path**

Para cada usuario (socio activo + admin), comprobar:

1. **Login** → `/socio/ranking` o `/admin/ranking` muestra toggle.
2. **Click "Por edad"** → carga `/socio/ranking-edades` o `/admin/ranking-edades`.
3. **Sin prueba** → tabla matriz 9×18.
4. **Click celda de la matriz** → recarga con Vista A para esa prueba.
5. **Vista A** → 9 secciones (edad 10..18), top-10 por sección.
6. **Cambiar piscina 25m→50m** → resultados cambian.
7. **Cambiar sexo M↔F↔Todos** → resultados cambian.
8. **Cambiar temporada Todas→<año>** → resultados cambian.
9. **Click "Por liga"** → vuelve al ranking original sin perder login.

- [ ] **Step 4: Verificar exclusiones**

- Nadador sin `fecha_nacimiento` (p.ej. el usuario admin) → no debe aparecer en modo edad.
- Marca con edad <10 o >18 → no aparece.

Query rápida para corroborar:
```bash
docker compose exec -T db mysql -uroot -proot cn_medio_cudeyo -e "
SELECT u.nombre, m.fecha_marca, u.fecha_nacimiento,
       YEAR(m.fecha_marca)-YEAR(u.fecha_nacimiento) AS edad
FROM marcas m JOIN users u ON u.id=m.user_id
WHERE u.fecha_nacimiento IS NULL
   OR (YEAR(m.fecha_marca)-YEAR(u.fecha_nacimiento)) NOT BETWEEN 10 AND 18
LIMIT 5;
"
```

Estas filas NO deben aparecer en /socio/ranking-edades ni /admin/ranking-edades.

- [ ] **Step 5: Logs limpios**

```bash
docker compose logs --tail=100 web | grep -iE "error|warning|notice"
```

Expected: ninguna línea relacionada con `ranking-edades`.

- [ ] **Step 6: Commit final si hubo ajustes**

Si los pasos anteriores requirieron correcciones, agruparlas en un commit:

```bash
git add -A
git commit -m "fix(ranking-edades): ajustes verificacion manual"
```

Si no hay cambios, saltar este paso.

---

## Self-review

**Spec coverage:**
- Toggle Liga/Edad → Tasks 3, 4 ✅
- Vista A (con prueba) — 9 bloques edad × top-10 → Task 6 ✅
- Vista B (sin prueba) — matriz 9×18 → Task 7 ✅
- Filtros (prueba, piscina, sexo, temporada, nadador) → Tasks 5–8 ✅
- Edad = YEAR(fecha_marca) - YEAR(fecha_nacimiento) → Task 1 helper + Tasks 6, 7 SQL ✅
- Exclusión nadadores sin fecha_nacimiento → SQL con `IS NOT NULL` ✅
- Exclusión edad fuera 10–18 → SQL con `BETWEEN 10 AND 18` ✅
- Versión socio + admin → Tasks 5–8 ✅
- CSS clases → Task 2 ✅
- Inventario CLAUDE.md → Task 9 ✅

**Placeholder scan:** sin TBDs ni "implement later".

**Type consistency:** `$vista_a_grupos[edad][] = row`, `$vista_b_matriz[edad][prueba] = row` consistente entre Tasks 5, 6, 7, 8. Función `edad_deportiva()` con misma firma en Task 1 y referenciada nominalmente en el plan (aunque la SQL hace el cálculo inline en `marcas`; el helper se conserva para uso futuro y consistencia con el spec).

> Nota sobre uso del helper: la SQL hace el cómputo de edad inline (más eficiente que pasar a PHP). El helper `edad_deportiva()` queda disponible si en el futuro hace falta calcular edad en otro contexto (p.ej. mostrar edad del nadador en la página de detalle de una marca). No es ocioso: simplifica futuros usos y centraliza la regla de cómputo.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-24-ranking-edades.md`. Two execution options:

1. **Subagent-Driven (recommended)** — dispatch fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
