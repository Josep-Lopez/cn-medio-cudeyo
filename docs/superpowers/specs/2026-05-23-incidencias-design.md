# Spec — Gestión de incidencias

**Fecha:** 2026-05-23
**Estado:** Diseño aprobado, pendiente de plan de implementación
**Stack objetivo:** PHP 8.4 puro + MySQL 8 + PDO (mismo stack del resto del proyecto)

## Resumen

Sistema para registrar y hacer seguimiento de incidencias del club. Cubre cuatro tipos: **lesiones**, **conducta**, **operativas/club** y **justificantes de ausencia**. Las incidencias tienen estados (`abierta` → `en_curso` → `cerrada`), pueden llevar adjuntos, hilo de comentarios bidireccional y notifican al socio implicado a través del sistema de comunicaciones existente.

Admin y socios pueden crear incidencias. El socio solo ve las suyas, y solo cuando el admin las marca como visibles para él. Las operativas pueden no tener socio asociado (afectan a instalación/club).

## Decisiones tomadas

| Tema | Decisión |
|---|---|
| Tipos | Cuatro tipos en un único enum (`lesion`, `conducta`, `operativa`, `justificante`). |
| Campos | Genéricos para todos los tipos (título, descripción, fecha). Sin estructura específica por tipo. |
| Quién crea | Admin y socio. El socio solo ve sus propias incidencias. |
| Asociación a socio | Opcional (nullable). Las operativas pueden no tener socio. |
| Workflow | Estados: `abierta`, `en_curso`, `cerrada`. Admin puede cambiar a cualquier estado (incluida reapertura `cerrada` → `abierta`). Solo admin cambia estado. |
| Visibilidad | Flag `visible_socio` que admin controla. Default: `1` salvo `conducta` (default `0`). |
| Adjuntos | Sí. PDF/JPG/PNG, máx. 5 MB, máx. 5 por incidencia. Servidos vía PHP con check de permisos. |
| Comentarios | Hilo bidireccional admin + socio asociado. Sin edición/borrado. Bloqueados si `cerrada`. |
| Notificaciones | Integradas con `comunicaciones`. Se notifica al crear (si visible), al cambiar estado (si visible) y al pasar de oculta a visible. NO se notifican comentarios nuevos. |
| Arquitectura | Tres tablas relacionales: `incidencias` + `incidencia_adjuntos` + `incidencia_comentarios`. |

## Schema (migración `012_incidencias.sql`)

```sql
-- 012: Sistema de gestión de incidencias

CREATE TABLE IF NOT EXISTS incidencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('lesion','conducta','operativa','justificante') NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    descripcion TEXT NOT NULL,
    fecha_suceso DATE NOT NULL,
    user_id INT DEFAULT NULL,
    creado_por INT NOT NULL,
    estado ENUM('abierta','en_curso','cerrada') NOT NULL DEFAULT 'abierta',
    visible_socio TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_estado_tipo (estado, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidencia_adjuntos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incidencia_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    tamano INT NOT NULL,
    subido_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incidencia_id) REFERENCES incidencias(id) ON DELETE CASCADE,
    FOREIGN KEY (subido_por) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidencia_comentarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incidencia_id INT NOT NULL,
    user_id INT NOT NULL,
    contenido TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incidencia_id) REFERENCES incidencias(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_incidencia (incidencia_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Añadir link_url a comunicaciones para botón "Ver" en notificaciones
ALTER TABLE comunicaciones ADD COLUMN link_url VARCHAR(500) DEFAULT NULL;
```

### Almacenamiento de adjuntos

- Directorio: `public/uploads/incidencias/`
- `.htaccess` con `Deny from all` (servir solo vía script PHP con check de permisos).
- Nombre en disco: `inc_<incidencia_id>_<hash8>.<ext>` (random, evita colisiones y enumeración).
- `.gitignore`: añadir `public/uploads/incidencias/*` con excepción `!public/uploads/incidencias/.htaccess`.

## Páginas y rutas

### Admin

| Ruta | Función |
|---|---|
| `public/admin/incidencias.php` | Listado con filtros (tipo, estado, socio, fecha desde/hasta, buscador título). Paginación 25/página. |
| `public/admin/incidencias.php?accion=nueva` | Form crear. Selecciona socio (autocompletar) o "sin socio". |
| `public/admin/incidencias.php?ver=<id>` | Detalle: campos editables + adjuntos + hilo comentarios + cambio estado + toggle `visible_socio`. |
| `public/admin/incidencias.php?accion=editar&id=<id>` | Edita campos. |
| POST `accion=eliminar` desde detalle | Borrado (CASCADE limpia adjuntos y comentarios; bucle PHP previo `unlink()` ficheros). |
| `public/admin/incidencia_descargar.php?id=<adjunto_id>` | Sirve adjunto tras verificar permisos. |

Sidebar admin (`includes/layout.php`, `render_admin_layout`): nueva entrada "Incidencias" junto a Asistencia.

### Socio

| Ruta | Función |
|---|---|
| `public/socio/incidencias.php` | Lista solo `user_id = sesión AND visible_socio = 1`. Filtros: tipo, estado. |
| `public/socio/incidencias.php?accion=nueva` | Form crear: tipo, título, descripción, fecha (default hoy), adjuntos. `user_id` forzado a sesión. `visible_socio=1`. |
| `public/socio/incidencias.php?ver=<id>` | Detalle propio read-only. Puede añadir comentarios y adjuntos si no `cerrada`. |
| `public/socio/incidencia_descargar.php?id=<adjunto_id>` | Sirve adjunto tras verificar permisos. |

Navbar socio: nueva entrada "Incidencias".

## Lógica de dominio (`includes/incidencias.php`)

```
crear_incidencia($data, $adjuntos_files): int          // id nueva. Inserta + sube adjuntos + notifica.
actualizar_estado($id, $nuevo_estado, $admin_id)       // Cambia estado. Notifica si visible_socio=1.
toggle_visible_socio($id, $visible, $admin_id)         // 0→1 dispara notif diferida; 1→0 silencioso.
agregar_comentario($incidencia_id, $user_id, $texto)   // Inserta comentario. Sin notificación.
puede_ver_incidencia($incidencia, $user): bool         // Admin siempre; socio si user_id=sesión y visible_socio=1.
puede_comentar($incidencia, $user): bool               // Admin si no cerrada; socio si puede_ver + no cerrada.
listar_incidencias_admin($filtros, $limit, $offset)    // Para listado admin con filtros.
listar_incidencias_socio($user_id, $filtros)           // Para listado socio.
subir_adjunto($incidencia_id, $file, $user_id): int    // Valida mime/tamaño/tope, mueve fichero, inserta fila.
eliminar_adjunto($adjunto_id, $user)                   // Verifica permisos, unlink + DELETE.
notificar_incidencia($incidencia_id, $evento, $admin_id) // $evento ∈ ['creada','estado_cambiado','hecha_visible'].
```

### Reglas de visibilidad

- **Admin:** ve todas, en cualquier estado, con cualquier `visible_socio`.
- **Socio:** ve solo `user_id = sesión AND visible_socio = 1`.

### Defaults `visible_socio` al crear

- Socio crea su propia: `1`.
- Admin crea: por tipo → `conducta = 0`, resto `1`. Admin puede tocar el check en el form.

### Validación al crear/editar

- `tipo` ∈ enum.
- `titulo`: 1-200 chars.
- `descripcion`: no vacío.
- `fecha_suceso`: válida, no futura.
- `user_id`: opcional para admin; forzado a sesión para socio.
- Adjuntos: mime real (`finfo_file`) ∈ {application/pdf, image/jpeg, image/png}; tamaño ≤ 5 MB; máx. 5 por incidencia.

### Adjuntos — servido y borrado

- Servido por script PHP con check `puede_ver_incidencia()`. `Content-Type` según mime real. `Content-Disposition: inline` para PDF e imagen; `attachment` para otros.
- Borrado de fila: admin siempre; socio solo `subido_por = sesión` y solo si incidencia abierta.
- Al eliminar incidencia: bucle PHP previo `unlink()` de todos los ficheros antes del DELETE (CASCADE solo limpia filas).

### Comentarios

- Listado cronológico ascendente. Muestra autor + fecha + rol (admin/socio) con badge visual.
- Sin edición ni borrado por simplicidad (audit trail).
- Bloqueados si incidencia `cerrada`. Admin puede reabrir cambiando estado a `abierta` o `en_curso`.

### Comportamiento al ocultar (`visible_socio` 1 → 0)

Si el admin oculta una incidencia que antes era visible, las comunicaciones ya generadas en `comunicaciones` **no se borran** (preservar audit trail). El link de esas comunicaciones apuntará a una incidencia que el socio ya no puede ver: el endpoint `socio/incidencias.php?ver=<id>` debe responder con un mensaje "No tienes acceso a esta incidencia" en lugar de 403, para que la UX sea coherente con notificaciones antiguas.

## Integración con `comunicaciones`

### Eventos que generan comunicación automática

| Evento | Genera comunicación |
|---|---|
| Crear incidencia con `visible_socio=1` y `user_id` no null | Sí |
| Cambio de estado con `visible_socio=1` | Sí |
| Toggle `visible_socio` 0→1 | Sí (notif inicial diferida) |
| Toggle `visible_socio` 1→0 | No |
| Nuevo comentario | No |
| Editar campos | No |
| Crear con `visible_socio=0` o sin `user_id` | No |

### Formato de la comunicación insertada

```sql
INSERT INTO comunicaciones (tipo, titulo, contenido, destinatario_tipo, destinatario_valor, admin_id, link_url)
VALUES ('mensaje',
        '<según evento>',
        '<resumen breve>',
        'individual',
        '<user_id del socio>',
        '<admin_id que disparó el evento>',
        '/socio/incidencias?ver=<id>');
```

- `titulo`: `"Nueva incidencia: <titulo>"`, `"Incidencia actualizada: <titulo> (<estado>)"`, etc.
- `contenido`: una o dos frases con tipo, fecha, estado, descripción truncada (≈200 chars).
- `link_url`: enlace al detalle en panel socio. Nueva columna en `comunicaciones`.
- `admin_id`: id del admin que disparó. Si fue el propio socio (creando su incidencia), no se genera notificación (no autonotif).

### Cambio en `public/socio/comunicaciones.php`

En el detalle, debajo del contenido, si `link_url` no es null renderizar:

```html
<a href="<?= e($detalle['link_url']) ?>" class="btn btn-primary btn-sm">Ver detalle →</a>
```

## UI

### Convención visual

- Reutiliza clases existentes de `assets/css/main.css`: `.card`, `.btn-primary`, `.btn-gray`, `.form-control`, `.badge`.
- CSS nuevo: solo colores de badges por tipo si no existen.
  - `lesion` = rojo, `conducta` = naranja, `operativa` = azul, `justificante` = gris.
  - Estado: `abierta` = rojo, `en_curso` = amarillo, `cerrada` = verde.
- Modales custom (memoria del usuario: no `confirm()`/`alert()` del navegador).

### Admin — listado

- Filtros card arriba: tipo, estado, socio (autocompletar nombre), rango fechas, buscador título.
- Tabla: ID · Fecha suceso · Tipo (badge) · Título · Socio (o "—") · Estado (badge) · Visible socio (✓/✗) · Acciones.
- Paginación 25/página.
- Botón "+ Nueva incidencia" arriba derecha.

### Admin — detalle/edición (`?ver=<id>`)

- Cabecera: badges + título + estado (select inline; POST recarga).
- Toggle "Visible para socio".
- Bloque "Datos": tipo, socio, fecha suceso, creador, created/updated.
- Bloque "Descripción": textarea editable.
- Bloque "Adjuntos": chips con icono mime + nombre + descargar/eliminar. Input file múltiple para añadir.
- Bloque "Comentarios": hilo cronológico + textarea + botón.
- Botón "Eliminar incidencia" abajo con modal de confirmación.

### Socio — listado

- Filtros simples: tipo + estado.
- Tabla compacta: fecha · tipo (badge) · título · estado (badge) · "Ver →".
- Botón "+ Nueva incidencia" arriba.
- Empty state si no hay.

### Socio — detalle

- Mismo layout que admin pero campos read-only, sin toggle visibilidad, sin botones admin.
- Puede añadir comentarios y adjuntos si no `cerrada`.

### Socio — nueva

- Form: tipo (4 opciones), título, descripción, fecha (default hoy), adjuntos opcionales.
- `user_id` y `visible_socio=1` forzados en servidor.

## Seguridad

- Todas las acciones POST con `csrf_verify()`.
- `require_admin()` o `require_login()` según ruta.
- Verificación de propiedad/visibilidad en cada endpoint del socio (no fiarse de la URL).
- Adjuntos servidos vía PHP, nunca acceso directo (.htaccess deny + script con check de permisos).
- Validación mime real con `finfo_file`, no por extensión.
- Prepared statements PDO en todas las queries.
- Escape de salida con `e()` para todo lo proveniente de BD o usuario.

## Fuera de alcance (no incluido en esta versión)

- Prioridad (alta/media/baja) — descartado en brainstorming.
- Campos específicos por tipo — descartado, todo genérico.
- Múltiples socios por incidencia — descartado.
- Notificación por email — solo in-app vía `comunicaciones`.
- Edición/borrado de comentarios — descartado por simplicidad y audit trail.
- Historial de cambios de estado (cuándo pasó a en_curso, cuándo a cerrada) — se puede inferir de `updated_at` y notificaciones generadas, no se tabula aparte.
- Exportar a CSV/PDF.

## Archivos a tocar/crear (resumen)

**Nuevos:**
- `migrations/012_incidencias.sql`
- `includes/incidencias.php`
- `public/admin/incidencias.php`
- `public/admin/incidencia_descargar.php`
- `public/socio/incidencias.php`
- `public/socio/incidencia_descargar.php`
- `public/uploads/incidencias/.htaccess`

**Modificados:**
- `includes/layout.php` — nueva entrada sidebar admin + nav socio.
- `public/socio/comunicaciones.php` — renderizar botón `link_url` cuando exista.
- `.gitignore` — excluir `public/uploads/incidencias/*` con excepción para `.htaccess`.
- `CLAUDE.md` — añadir tabla incidencias y rutas al inventario.
