# Cargos "Director técnico" y "Entrenador" — Diseño

**Fecha:** 2026-07-24
**Estado:** Aprobado (pendiente de plan de implementación)

## Objetivo

Añadir dos cargos directiva nuevos:

- **`director_tecnico`** — casi-admin. Todo `/admin/*` excepto `usuarios.php`. Todo `/directiva/*` (nivel `vocal`: ve, no decide). Límite: 1 titular activo.
- **`entrenador`** — solo asistencia. `/admin/asistencia.php` (pasar lista) + `/admin/asistencia_historial.php` (historial). Límite: 3 titulares activos.

Ninguno de los dos puede crear/aprobar/editar/borrar cuentas de usuario (`usuarios.php` sigue admin-only).

## Modelo de datos

`migrations/018_cargos_director_entrenador.sql`:

```sql
ALTER TABLE cargos MODIFY COLUMN cargo ENUM(
    'presidente','secretario','tesorero','vocal',
    'responsable_menores','encargado_redes',
    'director_tecnico','entrenador'
) NOT NULL;
```

`schema.sql` se actualiza en paralelo (mismo ENUM) para que un `docker compose up` limpio quede consistente.

## Control de acceso

### Nueva función `require_admin_area()` (`includes/auth.php`)

Calco de `require_admin()`, pero admite cargos extra (patrón de `require_cargo()`):

```php
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
```

### Páginas `admin/*.php`

| Archivo | Antes | Después |
|---|---|---|
| `usuarios.php` | `require_admin()` | sin cambio |
| `asistencia.php`, `asistencia_historial.php` | `require_admin()` | `require_admin_area(['director_tecnico','entrenador'])` |
| resto (`biblioteca`, `cargos`, `marcas`, `rfen_importar`, `rfen_buscar`, `noticias`, `comunicaciones`, `contacto`, `puntos-aqua`, `ranking`, `ranking-edades`, `records`, `incidencias`, `incidencia_descargar`, `config`) | `require_admin()` | `require_admin_area(['director_tecnico'])` |

`volver-admin.php` no tiene gate (solo restaura sesión admin original) — sin cambio.

### Páginas `directiva/*.php`

- `actas.php`, `socios.php`: `require_cargo([...])` gana `'director_tecnico'`. Mismo nivel que `vocal` — `$puedeEditar*` (secretario/tesorero) NO cambia, director_tecnico ve pero no edita salvo que además tenga ese cargo.
- `cuestiones.php`: `$esDirectiva = is_admin() || es_directiva() || user_tiene_cargo('director_tecnico');`. `$puedeDecidir` (solo presidente) sin cambio — director_tecnico no decide cuestiones.

`es_directiva()` NO se toca (sigue significando junta directiva real: presidente/secretario/tesorero/vocal).

### `cargos_limites()` / `cargo_label()` (`includes/auth.php`)

```php
'director_tecnico' => 1,
'entrenador'        => 3,
```
```php
'director_tecnico' => 'Director técnico',
'entrenador'        => 'Entrenador',
```

`admin/cargos.php` no requiere cambios — dropdown y listados ya usan `cargos_disponibles()`/`cargos_limites()`/`cargo_label()` dinámicamente.

## Navegación (`includes/layout.php`)

**Navbar** (`render_header`): usuario no-admin con cargo `director_tecnico` o `entrenador` gana enlace extra junto a "Mi panel":
- `director_tecnico` → enlace "Administración" → `/admin/marcas` (primera página con sentido; `usuarios.php` no aplica).
- `entrenador` → enlace "Asistencia" → `/admin/asistencia`.

**Sidebar admin** (`render_admin_layout`): variables `$isAdmin`, `$isDirTec = $isAdmin || user_tiene_cargo('director_tecnico')`, `$isEntrenador = $isDirTec || user_tiene_cargo('entrenador')`. Envolver cada enlace:
- "Gestión de usuarios" → solo `$isAdmin`.
- "Cargos directiva", "Incidencias", sección "Marcas & Ranking", sección "Contenido", sección "Directiva", sección "Sistema" → `$isDirTec`.
- "Pasar lista", "Historial asistencia" → `$isEntrenador`.

Así un `entrenador` que entra en `/admin/asistencia` ve sidebar con una sola sección (2 enlaces), sin líneas muertas que den 403.

## Riesgo aceptado

`director_tecnico` entra en `cargos.php` y puede asignar cualquier cargo (incluido a sí mismo otros cargos, o a otros usuarios el propio `director_tecnico`/`vocal`/etc.), pero nunca puede tocar `usuarios.php` (altas/bajas/aprobación de cuentas). Decisión explícita del usuario.

## Fuera de alcance

- No se toca el flag pre-existente por el que cualquier cargo (incluidos `responsable_menores`/`encargado_redes` hoy) muestra el enlace navbar "Directiva" aunque no tenga acceso a `socios`/`actas` — comportamiento ya existente, `entrenador` hereda la misma inconsistencia menor, no se corrige aquí.
- No se añade UI de "quién es el entrenador de qué grupo" ni vínculo con `asistencia` por grupo/liga — la tabla `asistencia` ya es plana (user_id + fecha), sin cambios de esquema ahí.
