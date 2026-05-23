# Sistema de gestión de incidencias — Plan de implementación

> **Para agentes:** SUB-SKILL REQUERIDA: usa `superpowers:subagent-driven-development` (recomendado) o `superpowers:executing-plans` para ejecutar este plan tarea a tarea. Los pasos usan checkboxes `- [ ]` para tracking.

**Goal:** Implementar el sistema completo de gestión de incidencias descrito en el spec `docs/superpowers/specs/2026-05-23-incidencias-design.md`.

**Architecture:** Tres tablas relacionales (`incidencias`, `incidencia_adjuntos`, `incidencia_comentarios`) gestionadas vía PHP puro + PDO. Helpers de dominio centralizados en `includes/incidencias.php`. Adjuntos en `public/uploads/incidencias/` servidos vía script PHP con check de permisos. Notificaciones automáticas mediante inserción en la tabla `comunicaciones` existente, ampliada con columna `link_url`.

**Tech Stack:** PHP 8.4, MySQL 8, PDO, Apache 2.4 (mod_rewrite). Sin framework. Docker Compose para entorno local.

**Nota sobre testing:** el proyecto no tiene infraestructura de tests automatizados (sin PHPUnit/composer/tests). La verificación de cada tarea es manual: arrancar Docker, ejecutar SQL en MySQL, navegar las páginas y comprobar el resultado en navegador / phpMyAdmin. Los pasos de verificación describen exactamente qué ver para confirmar que la tarea funciona.

**Branch:** trabajo en `main` (proyecto pequeño, sin PR flow). Cada tarea termina con un commit independiente.

**Prerequisito:** `docker compose up -d` levantado. App en `http://localhost:8080`, phpMyAdmin en `http://localhost:8081`.

---

## Mapa de archivos

**Nuevos:**
- `migrations/012_incidencias.sql` — schema + ALTER comunicaciones
- `includes/incidencias.php` — helpers de dominio
- `public/admin/incidencias.php` — listado + nueva + detalle + edit admin
- `public/admin/incidencia_descargar.php` — descarga adjunto (admin)
- `public/socio/incidencias.php` — listado + nueva + detalle socio
- `public/socio/incidencia_descargar.php` — descarga adjunto (socio)
- `public/uploads/incidencias/.htaccess` — bloqueo acceso directo

**Modificados:**
- `includes/layout.php` — sidebar admin + nav socio
- `public/socio/comunicaciones.php` — renderizar botón `link_url`
- `.gitignore` — excluir uploads incidencias
- `CLAUDE.md` — inventario actualizado

---

## Task 1: Migración SQL + directorio uploads + .gitignore

**Files:**
- Create: `migrations/012_incidencias.sql`
- Create: `public/uploads/incidencias/.htaccess`
- Modify: `.gitignore`

- [ ] **Step 1: Crear migración SQL**

Crear `migrations/012_incidencias.sql`:

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

ALTER TABLE comunicaciones ADD COLUMN link_url VARCHAR(500) DEFAULT NULL;
```

- [ ] **Step 2: Aplicar migración en MySQL del contenedor**

```bash
docker compose exec -T db mysql -uroot -p"$(grep MYSQL_ROOT_PASSWORD .env | cut -d= -f2)" cn_medio_cudeyo < migrations/012_incidencias.sql
```

Si falla por contraseña, usar phpMyAdmin (`http://localhost:8081`) → seleccionar BD `cn_medio_cudeyo` → pestaña SQL → pegar contenido del fichero → Continuar.

- [ ] **Step 3: Verificar schema**

```bash
docker compose exec -T db mysql -uroot -p"$(grep MYSQL_ROOT_PASSWORD .env | cut -d= -f2)" cn_medio_cudeyo -e "SHOW TABLES LIKE 'incidencia%'; SHOW COLUMNS FROM comunicaciones LIKE 'link_url';"
```

Esperado: tres filas con `incidencias`, `incidencia_adjuntos`, `incidencia_comentarios` + una fila confirmando columna `link_url` en `comunicaciones`.

- [ ] **Step 4: Crear directorio de uploads + .htaccess**

```bash
mkdir -p /home/lou/web-natacion-medio-cudeyo/cn-medio-cudeyo/public/uploads/incidencias
```

Crear `public/uploads/incidencias/.htaccess` con:

```
php_flag engine off
Options -Indexes
Require all denied
```

- [ ] **Step 5: Actualizar .gitignore**

Editar `.gitignore`, añadir bajo el bloque de uploads existente:

```
public/uploads/incidencias/*
!public/uploads/incidencias/.htaccess
```

- [ ] **Step 6: Verificar bloqueo de acceso directo**

Subir un fichero de prueba al directorio:

```bash
echo "test" > public/uploads/incidencias/test.txt
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/uploads/incidencias/test.txt
rm public/uploads/incidencias/test.txt
```

Esperado: `403`.

- [ ] **Step 7: Commit**

```bash
git add migrations/012_incidencias.sql public/uploads/incidencias/.htaccess .gitignore
git commit -m "feat(incidencias): migración SQL + directorio uploads protegido"
```

---

## Task 2: Helpers de dominio (`includes/incidencias.php`)

**Files:**
- Create: `includes/incidencias.php`

- [ ] **Step 1: Crear el fichero de helpers**

Crear `includes/incidencias.php`:

```php
<?php
// Helpers de dominio para gestión de incidencias.
// Requiere $pdo (config/db.php) y helpers de includes/auth.php.

const INCIDENCIA_TIPOS = ['lesion','conducta','operativa','justificante'];
const INCIDENCIA_ESTADOS = ['abierta','en_curso','cerrada'];
const INCIDENCIA_MIMES_PERMITIDOS = ['application/pdf','image/jpeg','image/png'];
const INCIDENCIA_MAX_TAMANO = 5 * 1024 * 1024;  // 5 MB
const INCIDENCIA_MAX_ADJUNTOS = 5;
const INCIDENCIA_UPLOAD_DIR = __DIR__ . '/../public/uploads/incidencias/';

function format_incidencia_tipo(string $t): string
{
    return match ($t) {
        'lesion' => 'Lesión',
        'conducta' => 'Conducta',
        'operativa' => 'Operativa',
        'justificante' => 'Justificante',
        default => $t,
    };
}

function format_incidencia_estado(string $e): string
{
    return match ($e) {
        'abierta' => 'Abierta',
        'en_curso' => 'En curso',
        'cerrada' => 'Cerrada',
        default => $e,
    };
}

function badge_clase_tipo(string $t): string
{
    return match ($t) {
        'lesion' => 'badge-lesion',
        'conducta' => 'badge-conducta',
        'operativa' => 'badge-operativa',
        'justificante' => 'badge-justificante',
        default => 'badge-gray',
    };
}

function badge_clase_estado(string $e): string
{
    return match ($e) {
        'abierta' => 'badge-abierta',
        'en_curso' => 'badge-en-curso',
        'cerrada' => 'badge-cerrada',
        default => 'badge-gray',
    };
}

function puede_ver_incidencia(array $incidencia, array $user): bool
{
    if (($user['rol'] ?? '') === 'admin') return true;
    return ((int)$incidencia['user_id'] === (int)$user['id']) && (int)$incidencia['visible_socio'] === 1;
}

function puede_comentar_incidencia(array $incidencia, array $user): bool
{
    if ($incidencia['estado'] === 'cerrada') return false;
    return puede_ver_incidencia($incidencia, $user);
}

function puede_subir_adjunto(array $incidencia, array $user): bool
{
    if ($incidencia['estado'] === 'cerrada') return false;
    return puede_ver_incidencia($incidencia, $user);
}

function obtener_incidencia(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM incidencias WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listar_adjuntos(PDO $pdo, int $incidencia_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM incidencia_adjuntos WHERE incidencia_id = ? ORDER BY created_at');
    $stmt->execute([$incidencia_id]);
    return $stmt->fetchAll();
}

function obtener_adjunto(PDO $pdo, int $adjunto_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM incidencia_adjuntos WHERE id = ?');
    $stmt->execute([$adjunto_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listar_comentarios(PDO $pdo, int $incidencia_id): array
{
    $stmt = $pdo->prepare('
        SELECT c.*, u.nombre AS autor_nombre, u.rol AS autor_rol
        FROM incidencia_comentarios c
        JOIN users u ON u.id = c.user_id
        WHERE c.incidencia_id = ?
        ORDER BY c.created_at ASC
    ');
    $stmt->execute([$incidencia_id]);
    return $stmt->fetchAll();
}

function crear_incidencia(PDO $pdo, array $data, array $files = []): int
{
    if (!in_array($data['tipo'], INCIDENCIA_TIPOS, true)) {
        throw new InvalidArgumentException('Tipo inválido');
    }
    if (trim($data['titulo']) === '' || mb_strlen($data['titulo']) > 200) {
        throw new InvalidArgumentException('Título inválido');
    }
    if (trim($data['descripcion']) === '') {
        throw new InvalidArgumentException('Descripción vacía');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['fecha_suceso']) || $data['fecha_suceso'] > date('Y-m-d')) {
        throw new InvalidArgumentException('Fecha de suceso inválida');
    }

    $visible = isset($data['visible_socio']) ? (int)(bool)$data['visible_socio'] : 1;

    $stmt = $pdo->prepare('
        INSERT INTO incidencias (tipo, titulo, descripcion, fecha_suceso, user_id, creado_por, estado, visible_socio)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $data['tipo'],
        trim($data['titulo']),
        trim($data['descripcion']),
        $data['fecha_suceso'],
        !empty($data['user_id']) ? (int)$data['user_id'] : null,
        (int)$data['creado_por'],
        'abierta',
        $visible,
    ]);
    $id = (int)$pdo->lastInsertId();

    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        subir_adjunto($pdo, $id, $file, (int)$data['creado_por']);
    }

    if (!empty($data['user_id']) && $visible === 1 && (int)$data['creado_por'] !== (int)$data['user_id']) {
        notificar_incidencia($pdo, $id, 'creada', (int)$data['creado_por']);
    }

    return $id;
}

function actualizar_estado(PDO $pdo, int $id, string $nuevo_estado, int $admin_id): void
{
    if (!in_array($nuevo_estado, INCIDENCIA_ESTADOS, true)) {
        throw new InvalidArgumentException('Estado inválido');
    }
    $inc = obtener_incidencia($pdo, $id);
    if (!$inc) throw new RuntimeException('Incidencia no encontrada');
    if ($inc['estado'] === $nuevo_estado) return;

    $pdo->prepare('UPDATE incidencias SET estado = ? WHERE id = ?')->execute([$nuevo_estado, $id]);

    if (!empty($inc['user_id']) && (int)$inc['visible_socio'] === 1) {
        notificar_incidencia($pdo, $id, 'estado_cambiado', $admin_id);
    }
}

function toggle_visible_socio(PDO $pdo, int $id, bool $visible, int $admin_id): void
{
    $inc = obtener_incidencia($pdo, $id);
    if (!$inc) throw new RuntimeException('Incidencia no encontrada');
    $era_visible = (int)$inc['visible_socio'] === 1;
    if ($era_visible === $visible) return;

    $pdo->prepare('UPDATE incidencias SET visible_socio = ? WHERE id = ?')->execute([$visible ? 1 : 0, $id]);

    if (!$era_visible && $visible && !empty($inc['user_id'])) {
        notificar_incidencia($pdo, $id, 'hecha_visible', $admin_id);
    }
}

function actualizar_campos(PDO $pdo, int $id, array $data): void
{
    if (!in_array($data['tipo'], INCIDENCIA_TIPOS, true)) {
        throw new InvalidArgumentException('Tipo inválido');
    }
    if (trim($data['titulo']) === '' || mb_strlen($data['titulo']) > 200) {
        throw new InvalidArgumentException('Título inválido');
    }
    if (trim($data['descripcion']) === '') {
        throw new InvalidArgumentException('Descripción vacía');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['fecha_suceso']) || $data['fecha_suceso'] > date('Y-m-d')) {
        throw new InvalidArgumentException('Fecha de suceso inválida');
    }
    $stmt = $pdo->prepare('
        UPDATE incidencias
        SET tipo = ?, titulo = ?, descripcion = ?, fecha_suceso = ?, user_id = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $data['tipo'],
        trim($data['titulo']),
        trim($data['descripcion']),
        $data['fecha_suceso'],
        !empty($data['user_id']) ? (int)$data['user_id'] : null,
        $id,
    ]);
}

function agregar_comentario(PDO $pdo, int $incidencia_id, int $user_id, string $contenido): void
{
    $contenido = trim($contenido);
    if ($contenido === '') throw new InvalidArgumentException('Comentario vacío');
    $pdo->prepare('INSERT INTO incidencia_comentarios (incidencia_id, user_id, contenido) VALUES (?, ?, ?)')
        ->execute([$incidencia_id, $user_id, $contenido]);
}

function subir_adjunto(PDO $pdo, int $incidencia_id, array $file, int $user_id): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir fichero (código ' . ($file['error'] ?? '?') . ')');
    }
    if ($file['size'] > INCIDENCIA_MAX_TAMANO) {
        throw new RuntimeException('Fichero demasiado grande (máx 5 MB)');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, INCIDENCIA_MIMES_PERMITIDOS, true)) {
        throw new RuntimeException('Tipo de fichero no permitido: ' . $mime);
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM incidencia_adjuntos WHERE incidencia_id = ?');
    $countStmt->execute([$incidencia_id]);
    if ((int)$countStmt->fetchColumn() >= INCIDENCIA_MAX_ADJUNTOS) {
        throw new RuntimeException('Máximo ' . INCIDENCIA_MAX_ADJUNTOS . ' adjuntos por incidencia');
    }

    $ext = match ($mime) {
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    };
    $nombre = 'inc_' . $incidencia_id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = INCIDENCIA_UPLOAD_DIR . $nombre;

    if (!is_dir(INCIDENCIA_UPLOAD_DIR)) {
        mkdir(INCIDENCIA_UPLOAD_DIR, 0775, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        throw new RuntimeException('No se pudo guardar el fichero');
    }

    $stmt = $pdo->prepare('
        INSERT INTO incidencia_adjuntos (incidencia_id, archivo, nombre_original, mime, tamano, subido_por)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $incidencia_id,
        $nombre,
        substr($file['name'], 0, 255),
        $mime,
        (int)$file['size'],
        $user_id,
    ]);
    return (int)$pdo->lastInsertId();
}

function eliminar_adjunto(PDO $pdo, int $adjunto_id, array $user): void
{
    $adj = obtener_adjunto($pdo, $adjunto_id);
    if (!$adj) return;
    $es_admin = ($user['rol'] ?? '') === 'admin';
    if (!$es_admin) {
        $inc = obtener_incidencia($pdo, (int)$adj['incidencia_id']);
        if (!$inc) return;
        if ((int)$adj['subido_por'] !== (int)$user['id'] || $inc['estado'] !== 'abierta') {
            throw new RuntimeException('Sin permisos para eliminar este adjunto');
        }
    }
    $path = INCIDENCIA_UPLOAD_DIR . $adj['archivo'];
    if (is_file($path)) @unlink($path);
    $pdo->prepare('DELETE FROM incidencia_adjuntos WHERE id = ?')->execute([$adjunto_id]);
}

function eliminar_incidencia(PDO $pdo, int $id): void
{
    $adjuntos = listar_adjuntos($pdo, $id);
    foreach ($adjuntos as $a) {
        $path = INCIDENCIA_UPLOAD_DIR . $a['archivo'];
        if (is_file($path)) @unlink($path);
    }
    $pdo->prepare('DELETE FROM comunicaciones WHERE link_url = ?')->execute(['/socio/incidencias?ver=' . $id]);
    $pdo->prepare('DELETE FROM incidencias WHERE id = ?')->execute([$id]);
}

function listar_incidencias_admin(PDO $pdo, array $filtros, int $limit, int $offset): array
{
    $where = ['1=1'];
    $params = [];
    if (!empty($filtros['tipo']) && in_array($filtros['tipo'], INCIDENCIA_TIPOS, true)) {
        $where[] = 'i.tipo = ?'; $params[] = $filtros['tipo'];
    }
    if (!empty($filtros['estado']) && in_array($filtros['estado'], INCIDENCIA_ESTADOS, true)) {
        $where[] = 'i.estado = ?'; $params[] = $filtros['estado'];
    }
    if (!empty($filtros['user_id'])) {
        $where[] = 'i.user_id = ?'; $params[] = (int)$filtros['user_id'];
    }
    if (!empty($filtros['desde'])) {
        $where[] = 'i.fecha_suceso >= ?'; $params[] = $filtros['desde'];
    }
    if (!empty($filtros['hasta'])) {
        $where[] = 'i.fecha_suceso <= ?'; $params[] = $filtros['hasta'];
    }
    if (!empty($filtros['q'])) {
        $where[] = 'i.titulo LIKE ?'; $params[] = '%' . $filtros['q'] . '%';
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM incidencias i WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT i.*, u.nombre AS socio_nombre
        FROM incidencias i
        LEFT JOIN users u ON u.id = i.user_id
        WHERE $whereSql
        ORDER BY i.fecha_suceso DESC, i.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => $total];
}

function listar_incidencias_socio(PDO $pdo, int $user_id, array $filtros): array
{
    $where = ['i.user_id = ?', 'i.visible_socio = 1'];
    $params = [$user_id];
    if (!empty($filtros['tipo']) && in_array($filtros['tipo'], INCIDENCIA_TIPOS, true)) {
        $where[] = 'i.tipo = ?'; $params[] = $filtros['tipo'];
    }
    if (!empty($filtros['estado']) && in_array($filtros['estado'], INCIDENCIA_ESTADOS, true)) {
        $where[] = 'i.estado = ?'; $params[] = $filtros['estado'];
    }
    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare("SELECT * FROM incidencias i WHERE $whereSql ORDER BY i.fecha_suceso DESC, i.id DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function notificar_incidencia(PDO $pdo, int $incidencia_id, string $evento, int $admin_id): void
{
    $inc = obtener_incidencia($pdo, $incidencia_id);
    if (!$inc || empty($inc['user_id']) || (int)$inc['visible_socio'] !== 1) return;

    $titulo_tipo = format_incidencia_tipo($inc['tipo']);
    $titulo = match ($evento) {
        'creada' => "Nueva incidencia ($titulo_tipo): {$inc['titulo']}",
        'estado_cambiado' => "Incidencia actualizada ({$titulo_tipo}, " . format_incidencia_estado($inc['estado']) . "): {$inc['titulo']}",
        'hecha_visible' => "Incidencia visible ($titulo_tipo): {$inc['titulo']}",
        default => "Incidencia: {$inc['titulo']}",
    };

    $resumen = mb_substr(trim($inc['descripcion']), 0, 200);
    if (mb_strlen($inc['descripcion']) > 200) $resumen .= '…';

    $contenido = "Tipo: $titulo_tipo\nFecha del suceso: " . date('d/m/Y', strtotime($inc['fecha_suceso']))
        . "\nEstado: " . format_incidencia_estado($inc['estado'])
        . "\n\n" . $resumen;

    $stmt = $pdo->prepare('
        INSERT INTO comunicaciones (tipo, titulo, contenido, destinatario_tipo, destinatario_valor, admin_id, link_url)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        'mensaje',
        $titulo,
        $contenido,
        'individual',
        (string)(int)$inc['user_id'],
        $admin_id,
        '/socio/incidencias?ver=' . $incidencia_id,
    ]);
}
```

- [ ] **Step 2: Verificar parseo PHP**

```bash
docker compose exec -T app php -l /var/www/html/../includes/incidencias.php 2>&1 || \
docker compose exec -T app php -l includes/incidencias.php
```

Si la ruta no encuentra: probar desde host:

```bash
php -l includes/incidencias.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 3: Smoke test funciones**

Crear script efímero:

```bash
docker compose exec -T app php -r '
require "/var/www/html/../config/db.php";
require "/var/www/html/../includes/incidencias.php";
echo "Tipos: " . implode(",", INCIDENCIA_TIPOS) . PHP_EOL;
echo "Formato tipo lesion: " . format_incidencia_tipo("lesion") . PHP_EOL;
echo "Badge clase estado abierta: " . badge_clase_estado("abierta") . PHP_EOL;
'
```

Esperado:
```
Tipos: lesion,conducta,operativa,justificante
Formato tipo lesion: Lesión
Badge clase estado abierta: badge-abierta
```

Si la ruta `/var/www/html/../` no funciona, ajustar a la ruta real montada (revisar `docker-compose.yml`).

- [ ] **Step 4: Commit**

```bash
git add includes/incidencias.php
git commit -m "feat(incidencias): helpers de dominio (CRUD + adjuntos + notif)"
```

---

## Task 3: Renderizar botón `link_url` en `comunicaciones` del socio

**Files:**
- Modify: `public/socio/comunicaciones.php:132`

- [ ] **Step 1: Localizar la línea exacta**

```bash
grep -n "nl2br(e(\$detalle\['contenido'\]))" public/socio/comunicaciones.php
```

Esperado: línea ~132 con `<div ...><?= nl2br(e($detalle['contenido'])) ?></div>`.

- [ ] **Step 2: Añadir botón si `link_url` existe**

Reemplazar el bloque que contiene el `nl2br` por:

```php
    <div style="line-height:1.7;font-size:15px;"><?= nl2br(e($detalle['contenido'])) ?></div>
    <?php if (!empty($detalle['link_url'])): ?>
      <div style="margin-top:16px;">
        <a href="<?= e($detalle['link_url']) ?>" class="btn btn-primary btn-sm">Ver detalle →</a>
      </div>
    <?php endif; ?>
```

- [ ] **Step 3: Verificar en navegador**

Abrir `http://localhost:8080/socio/comunicaciones` (login como socio cualquiera). Una comunicación existente no tiene `link_url`, así que no se ve el botón. Eso es lo esperado de momento — más tarde el flujo de incidencias generará una con botón.

Verificar también que no se rompe ninguna comunicación existente.

- [ ] **Step 4: Commit**

```bash
git add public/socio/comunicaciones.php
git commit -m "feat(comunicaciones): renderizar botón 'Ver detalle' si link_url existe"
```

---

## Task 4: Entrada sidebar admin + nav socio

**Files:**
- Modify: `includes/layout.php`

- [ ] **Step 1: Identificar bloques de navegación**

```bash
grep -n "asistencia\|Asistencia" includes/layout.php
```

Localizar el bloque del sidebar admin (función `render_admin_layout`) y el menú del socio (en `render_header` o helper relacionado).

- [ ] **Step 2: Añadir entrada "Incidencias" en sidebar admin**

En `includes/layout.php`, dentro del sidebar admin, junto al item de Asistencia, añadir un item siguiendo el patrón existente:

```php
<li>
  <a href="/admin/incidencias" class="<?= $activePage === 'incidencias' ? 'active' : '' ?>">
    <i class="bi bi-exclamation-triangle"></i> Incidencias
  </a>
</li>
```

Ajustar la etiqueta HTML y la clase activa al patrón exacto que use el resto del sidebar (los otros items son la referencia).

- [ ] **Step 3: Añadir entrada en el menú del socio**

Localizar el bloque donde aparecen "Panel", "Ranking", "Comunicaciones" en el navbar para socios. Añadir tras Comunicaciones:

```php
<li><a href="/socio/incidencias" class="<?= $activePage === 'socio-incidencias' ? 'active' : '' ?>">Incidencias</a></li>
```

Adaptar a la sintaxis del bloque concreto (puede ser `<a>` directo sin `<li>`, según el marcado existente).

- [ ] **Step 4: Verificar en navegador**

Login admin → comprobar item "Incidencias" en sidebar (apuntará a 404 hasta Task 5, eso es OK).
Login socio → comprobar item "Incidencias" en navbar (404 hasta Task 11).

- [ ] **Step 5: Commit**

```bash
git add includes/layout.php
git commit -m "feat(incidencias): entradas de navegación en admin y socio"
```

---

## Task 5: Admin — listado con filtros + paginación

**Files:**
- Create: `public/admin/incidencias.php`
- Modify: `public/assets/css/main.css` (añadir clases badge si faltan)

- [ ] **Step 1: Añadir clases CSS de badges en `main.css`**

Buscar si ya existen las clases `.badge`. Si no hay las específicas, añadir al final de `public/assets/css/main.css`:

```css
.badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1.4; }
.badge-lesion { background:#fee2e2; color:#991b1b; }
.badge-conducta { background:#ffedd5; color:#9a3412; }
.badge-operativa { background:#dbeafe; color:#1e3a8a; }
.badge-justificante { background:#e5e7eb; color:#374151; }
.badge-abierta { background:#fee2e2; color:#991b1b; }
.badge-en-curso { background:#fef3c7; color:#854d0e; }
.badge-cerrada { background:#dcfce7; color:#166534; }
.badge-gray { background:#e5e7eb; color:#374151; }
```

Si ya existe `.badge` no duplicarla.

- [ ] **Step 2: Crear el fichero del listado admin (esqueleto)**

Crear `public/admin/incidencias.php`. Este fichero unifica listado, nueva, ver y editar mediante parámetros GET (`accion`, `ver`, `id`). En esta task solo implementamos el listado:

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/incidencias.php';

require_admin();

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;

// Listado por defecto
$filtros = [
    'tipo' => $_GET['tipo'] ?? '',
    'estado' => $_GET['estado'] ?? '',
    'user_id' => $_GET['user_id'] ?? '',
    'desde' => $_GET['desde'] ?? '',
    'hasta' => $_GET['hasta'] ?? '',
    'q' => trim($_GET['q'] ?? ''),
];
$pagina = max(1, (int)($_GET['p'] ?? 1));
$limit = 25;
$offset = ($pagina - 1) * $limit;

$res = listar_incidencias_admin($pdo, $filtros, $limit, $offset);
$incidencias = $res['rows'];
$total = $res['total'];
$totalPaginas = max(1, (int)ceil($total / $limit));

// Para el filtro de socio: lista socios activos para datalist
$sociosStmt = $pdo->query("SELECT id, nombre FROM users WHERE rol='socio' AND estado='activo' ORDER BY nombre");
$socios = $sociosStmt->fetchAll();

render_header('Incidencias', 'admin-incidencias');
render_admin_layout('incidencias', function() use ($filtros, $incidencias, $total, $pagina, $totalPaginas, $socios) {
?>

<h1>Incidencias</h1>
<?php render_flash(); ?>

<div class="card mb-6">
  <form method="GET" class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;">
      <label class="form-label">Tipo</label>
      <select name="tipo" class="form-control" style="width:auto;">
        <option value="">Todos</option>
        <?php foreach (INCIDENCIA_TIPOS as $t): ?>
          <option value="<?= $t ?>" <?= $filtros['tipo'] === $t ? 'selected' : '' ?>><?= format_incidencia_tipo($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Estado</label>
      <select name="estado" class="form-control" style="width:auto;">
        <option value="">Todos</option>
        <?php foreach (INCIDENCIA_ESTADOS as $e): ?>
          <option value="<?= $e ?>" <?= $filtros['estado'] === $e ? 'selected' : '' ?>><?= format_incidencia_estado($e) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Socio</label>
      <select name="user_id" class="form-control" style="width:auto;min-width:160px;">
        <option value="">Todos</option>
        <?php foreach ($socios as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$filtros['user_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Desde</label>
      <input type="date" name="desde" value="<?= e($filtros['desde']) ?>" class="form-control" style="width:auto;">
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Hasta</label>
      <input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>" class="form-control" style="width:auto;">
    </div>
    <div class="form-group" style="margin:0;flex:1;min-width:160px;">
      <label class="form-label">Buscar título</label>
      <input type="text" name="q" value="<?= e($filtros['q']) ?>" class="form-control" placeholder="Texto…">
    </div>
    <div style="display:flex;gap:8px;align-items:flex-end;">
      <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
      <a href="/admin/incidencias" class="btn btn-gray">Limpiar</a>
    </div>
    <div style="margin-left:auto;">
      <a href="/admin/incidencias?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva incidencia</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <h2 class="card-title"><?= (int)$total ?> incidencia<?= $total === 1 ? '' : 's' ?></h2>
  </div>
  <?php if (!$incidencias): ?>
    <div style="padding:32px;text-align:center;color:var(--gray);">No hay incidencias con esos filtros.</div>
  <?php else: ?>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>Título</th>
          <th>Socio</th>
          <th>Estado</th>
          <th>Visible</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incidencias as $i): ?>
          <tr>
            <td>#<?= (int)$i['id'] ?></td>
            <td><?= date('d/m/Y', strtotime($i['fecha_suceso'])) ?></td>
            <td><span class="badge <?= badge_clase_tipo($i['tipo']) ?>"><?= format_incidencia_tipo($i['tipo']) ?></span></td>
            <td><?= e($i['titulo']) ?></td>
            <td><?= $i['socio_nombre'] ? e($i['socio_nombre']) : '<span class="text-muted">—</span>' ?></td>
            <td><span class="badge <?= badge_clase_estado($i['estado']) ?>"><?= format_incidencia_estado($i['estado']) ?></span></td>
            <td><?= (int)$i['visible_socio'] === 1 ? '✓' : '✗' ?></td>
            <td><a href="/admin/incidencias?ver=<?= (int)$i['id'] ?>" class="btn btn-gray btn-sm">Ver</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($totalPaginas > 1):
    $qs = $_GET; ?>
  <div style="padding:16px;display:flex;gap:8px;justify-content:center;align-items:center;border-top:1px solid #eee;">
    <?php for ($p = 1; $p <= $totalPaginas; $p++):
      $qs['p'] = $p; ?>
      <a href="?<?= http_build_query($qs) ?>" class="btn btn-sm <?= $p === $pagina ? 'btn-primary' : 'btn-gray' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php
});
render_footer();
```

- [ ] **Step 3: Verificar listado vacío en navegador**

Login admin → `http://localhost:8080/admin/incidencias` → debe cargar el listado vacío con filtros + botón Nueva (que aún apunta a `?accion=nueva` que mostrará el listado normal hasta Task 6 — es OK).

Insertar una incidencia de prueba directamente vía phpMyAdmin para validar render:

```sql
INSERT INTO incidencias (tipo, titulo, descripcion, fecha_suceso, user_id, creado_por, estado, visible_socio)
VALUES ('lesion','Prueba lesion','Desc prueba','2026-05-20', NULL, 1, 'abierta', 1);
```

(Asume admin tiene id=1; ajustar.) Recargar → debe verse la fila con badges.

Borrar después:
```sql
DELETE FROM incidencias WHERE titulo='Prueba lesion';
```

- [ ] **Step 4: Commit**

```bash
git add public/admin/incidencias.php public/assets/css/main.css
git commit -m "feat(incidencias): admin listado con filtros + paginación + badges"
```

---

## Task 6: Admin — crear nueva incidencia

**Files:**
- Modify: `public/admin/incidencias.php`

- [ ] **Step 1: Añadir manejo POST + form de nueva al fichero**

Insertar **antes de** la línea `$accion = $_GET['accion'] ?? '';` el bloque POST:

```php
// ── POST: crear nueva ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_verify();
    try {
        $data = [
            'tipo' => $_POST['tipo'] ?? '',
            'titulo' => $_POST['titulo'] ?? '',
            'descripcion' => $_POST['descripcion'] ?? '',
            'fecha_suceso' => $_POST['fecha_suceso'] ?? '',
            'user_id' => $_POST['user_id'] ?? null,
            'visible_socio' => isset($_POST['visible_socio']) ? 1 : 0,
            'creado_por' => current_user()['id'],
        ];
        $files = [];
        if (!empty($_FILES['adjuntos']) && is_array($_FILES['adjuntos']['name'])) {
            foreach ($_FILES['adjuntos']['name'] as $idx => $name) {
                if (($_FILES['adjuntos']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $files[] = [
                    'name' => $name,
                    'tmp_name' => $_FILES['adjuntos']['tmp_name'][$idx],
                    'error' => $_FILES['adjuntos']['error'][$idx],
                    'size' => $_FILES['adjuntos']['size'][$idx],
                    'type' => $_FILES['adjuntos']['type'][$idx],
                ];
            }
        }
        $id = crear_incidencia($pdo, $data, $files);
        flash('Incidencia creada (#' . $id . ').', 'success');
        header('Location: /admin/incidencias?ver=' . $id);
        exit;
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
        header('Location: /admin/incidencias?accion=nueva');
        exit;
    }
}
```

- [ ] **Step 2: Añadir el render del form en lugar del listado cuando `accion=nueva`**

Justo antes del `render_admin_layout(...)`, añadir bifurcación. Reestructurar el cierre del helper para que cuando `accion === 'nueva'` muestre el form en vez del listado. Patrón concreto: dentro del callback de `render_admin_layout`, al inicio:

```php
render_admin_layout('incidencias', function() use ($accion, $filtros, $incidencias, $total, $pagina, $totalPaginas, $socios) {

    if ($accion === 'nueva') {
        $hoy = date('Y-m-d');
?>
<h1>Nueva incidencia</h1>
<?php render_flash(); ?>
<form method="POST" action="/admin/incidencias" enctype="multipart/form-data" class="card" style="padding:24px;max-width:760px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="crear">

  <div class="form-group">
    <label class="form-label">Tipo *</label>
    <select name="tipo" class="form-control" required onchange="ajustarVisibleDefault(this.value)">
      <option value="">— Selecciona —</option>
      <?php foreach (INCIDENCIA_TIPOS as $t): ?>
        <option value="<?= $t ?>"><?= format_incidencia_tipo($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-group">
    <label class="form-label">Título *</label>
    <input type="text" name="titulo" class="form-control" required maxlength="200">
  </div>

  <div class="form-group">
    <label class="form-label">Descripción *</label>
    <textarea name="descripcion" class="form-control" rows="5" required></textarea>
  </div>

  <div class="d-flex gap-3 flex-wrap">
    <div class="form-group" style="flex:1;min-width:200px;">
      <label class="form-label">Fecha del suceso *</label>
      <input type="date" name="fecha_suceso" class="form-control" required max="<?= $hoy ?>" value="<?= $hoy ?>">
    </div>
    <div class="form-group" style="flex:2;min-width:240px;">
      <label class="form-label">Socio (opcional para operativas)</label>
      <select name="user_id" class="form-control">
        <option value="">— Sin socio —</option>
        <?php foreach ($socios as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-group">
    <label class="form-label">
      <input type="checkbox" name="visible_socio" id="visible_socio" checked> Visible para el socio
    </label>
    <div class="text-muted text-sm">Si se desmarca, el socio no verá la incidencia ni recibirá notificación.</div>
  </div>

  <div class="form-group">
    <label class="form-label">Adjuntos (PDF, JPG, PNG — máx 5 MB, hasta 5 ficheros)</label>
    <input type="file" name="adjuntos[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png">
  </div>

  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Crear incidencia</button>
    <a href="/admin/incidencias" class="btn btn-gray">Cancelar</a>
  </div>
</form>

<script>
function ajustarVisibleDefault(tipo) {
  var cb = document.getElementById('visible_socio');
  if (tipo === 'conducta') cb.checked = false;
  else cb.checked = true;
}
</script>
<?php
        return;
    }
?>
```

Y mantener el listado existente después del `if ($accion === 'nueva')`. El resto del listado original queda intacto a continuación.

- [ ] **Step 3: Verificar creación**

Login admin → `Nueva incidencia`. Crear una de tipo "Lesión", socio = uno cualquiera, sin adjuntos. Submit → debe redirigir a `?ver=<id>` (que aún no existe el render, mostrará el listado o un error — en Task 7 se implementa).

Verificar en phpMyAdmin que se insertó la fila en `incidencias`.

Probar adjunto: editar fichero PDF pequeño (<5 MB), crear nueva con él. Verificar fila en `incidencia_adjuntos` y fichero físico en `public/uploads/incidencias/`.

Probar validación: dejar título vacío → flash "Error: Título inválido".

- [ ] **Step 4: Commit**

```bash
git add public/admin/incidencias.php
git commit -m "feat(incidencias): admin crear nueva incidencia + adjuntos opcionales"
```

---

## Task 7: Admin — vista detalle (sin edición/comentarios todavía)

**Files:**
- Modify: `public/admin/incidencias.php`

- [ ] **Step 1: Añadir bloque `ver` antes del listado**

Dentro del callback, justo después del bloque `accion === 'nueva'` (y antes del listado), añadir:

```php
    if ($verId > 0) {
        global $pdo;
        $inc = obtener_incidencia($pdo, $verId);
        if (!$inc) {
            echo '<div class="card" style="padding:24px;">Incidencia no encontrada. <a href="/admin/incidencias">Volver</a></div>';
            return;
        }
        $adjuntos = listar_adjuntos($pdo, $verId);
        $comentarios = listar_comentarios($pdo, $verId);
        $socio = null;
        if (!empty($inc['user_id'])) {
            $s = $pdo->prepare('SELECT id, nombre FROM users WHERE id=?');
            $s->execute([$inc['user_id']]);
            $socio = $s->fetch();
        }
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;">Incidencia #<?= (int)$inc['id'] ?></h1>
  <a href="/admin/incidencias" class="btn btn-gray btn-sm">← Volver al listado</a>
</div>
<?php render_flash(); ?>

<div class="card mb-6" style="padding:24px;">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
    <span class="badge <?= badge_clase_tipo($inc['tipo']) ?>"><?= format_incidencia_tipo($inc['tipo']) ?></span>
    <span class="badge <?= badge_clase_estado($inc['estado']) ?>"><?= format_incidencia_estado($inc['estado']) ?></span>
    <span class="text-muted text-sm">Visible socio: <?= (int)$inc['visible_socio'] === 1 ? '✓' : '✗' ?></span>
  </div>
  <h2 style="margin:0 0 12px;"><?= e($inc['titulo']) ?></h2>
  <div class="text-muted text-sm" style="margin-bottom:16px;">
    Fecha suceso: <?= date('d/m/Y', strtotime($inc['fecha_suceso'])) ?>
    · Creada: <?= date('d/m/Y H:i', strtotime($inc['created_at'])) ?>
    · Actualizada: <?= date('d/m/Y H:i', strtotime($inc['updated_at'])) ?>
    <?php if ($socio): ?> · Socio: <?= e($socio['nombre']) ?><?php endif; ?>
  </div>
  <div style="white-space:pre-wrap;line-height:1.6;"><?= e($inc['descripcion']) ?></div>
</div>

<div class="card mb-6" style="padding:24px;">
  <h3>Adjuntos (<?= count($adjuntos) ?>)</h3>
  <?php if (!$adjuntos): ?>
    <p class="text-muted">Sin adjuntos.</p>
  <?php else: ?>
    <ul style="list-style:none;padding:0;">
      <?php foreach ($adjuntos as $a): ?>
        <li style="padding:8px 0;border-bottom:1px solid #eee;">
          <a href="/admin/incidencia_descargar.php?id=<?= (int)$a['id'] ?>"><?= e($a['nombre_original']) ?></a>
          <span class="text-muted text-sm">— <?= number_format($a['tamano'] / 1024, 0) ?> KB · <?= e($a['mime']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="card" style="padding:24px;">
  <h3>Comentarios (<?= count($comentarios) ?>)</h3>
  <?php if (!$comentarios): ?>
    <p class="text-muted">Sin comentarios.</p>
  <?php else: ?>
    <?php foreach ($comentarios as $c): ?>
      <div style="border-left:3px solid var(--blue);padding:8px 12px;margin-bottom:12px;background:#f9fafb;">
        <div class="text-sm">
          <strong><?= e($c['autor_nombre']) ?></strong>
          <span class="badge <?= $c['autor_rol'] === 'admin' ? 'badge-operativa' : 'badge-gray' ?>" style="font-size:10px;"><?= e($c['autor_rol']) ?></span>
          <span class="text-muted">· <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></span>
        </div>
        <div style="white-space:pre-wrap;margin-top:6px;"><?= e($c['contenido']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php
        return;
    }
?>
```

(El `global $pdo;` es necesario porque el callback no captura `$pdo` por closure — alternativamente añadirlo al `use(...)`. Más limpio: añadir `$pdo` al `use(...)` del callback. Hacerlo así.)

- [ ] **Step 2: Añadir `$pdo` al `use(...)` del callback**

Cambiar:

```php
render_admin_layout('incidencias', function() use ($accion, $filtros, $incidencias, $total, $pagina, $totalPaginas, $socios) {
```

por:

```php
render_admin_layout('incidencias', function() use ($accion, $verId, $filtros, $incidencias, $total, $pagina, $totalPaginas, $socios, $pdo) {
```

Eliminar el `global $pdo;` del Step 1.

- [ ] **Step 3: Verificar render del detalle**

Crear una incidencia con adjunto desde admin (Task 6 ya lo permite). Comprobar `/admin/incidencias?ver=<id>` muestra: cabecera con badges, descripción, lista de adjuntos (con link descargar — aún 404 hasta Task 9), bloque comentarios vacío.

- [ ] **Step 4: Commit**

```bash
git add public/admin/incidencias.php
git commit -m "feat(incidencias): admin vista detalle (campos + adjuntos + comentarios)"
```

---

## Task 8: Admin — acciones en detalle (estado, visibilidad, comentar, subir adjunto, eliminar adjunto, editar campos)

**Files:**
- Modify: `public/admin/incidencias.php`

- [ ] **Step 1: Añadir manejadores POST**

Insertar tras el bloque POST de `accion='crear'`:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'actualizar_estado') {
    csrf_verify();
    try {
        actualizar_estado($pdo, (int)$_POST['id'], $_POST['estado'] ?? '', (int)current_user()['id']);
        flash('Estado actualizado.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . (int)$_POST['id']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'toggle_visible') {
    csrf_verify();
    try {
        $visible = isset($_POST['visible_socio']);
        toggle_visible_socio($pdo, (int)$_POST['id'], $visible, (int)current_user()['id']);
        flash('Visibilidad actualizada.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . (int)$_POST['id']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'comentar') {
    csrf_verify();
    try {
        $inc = obtener_incidencia($pdo, (int)$_POST['id']);
        if (!$inc) throw new RuntimeException('Incidencia no encontrada');
        if (!puede_comentar_incidencia($inc, current_user())) throw new RuntimeException('No puedes comentar');
        agregar_comentario($pdo, (int)$_POST['id'], (int)current_user()['id'], $_POST['contenido'] ?? '');
        flash('Comentario añadido.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . (int)$_POST['id']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'subir_adjunto') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        $inc = obtener_incidencia($pdo, $id);
        if (!$inc) throw new RuntimeException('Incidencia no encontrada');
        if (!puede_subir_adjunto($inc, current_user())) throw new RuntimeException('No puedes subir adjuntos');
        if (empty($_FILES['adjunto']) || ($_FILES['adjunto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No se seleccionó fichero');
        }
        subir_adjunto($pdo, $id, $_FILES['adjunto'], (int)current_user()['id']);
        flash('Adjunto subido.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_adjunto') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        eliminar_adjunto($pdo, (int)$_POST['adjunto_id'], current_user());
        flash('Adjunto eliminado.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_campos') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        actualizar_campos($pdo, $id, [
            'tipo' => $_POST['tipo'] ?? '',
            'titulo' => $_POST['titulo'] ?? '',
            'descripcion' => $_POST['descripcion'] ?? '',
            'fecha_suceso' => $_POST['fecha_suceso'] ?? '',
            'user_id' => $_POST['user_id'] ?? null,
        ]);
        flash('Cambios guardados.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias?ver=' . $id);
    exit;
}
```

- [ ] **Step 2: Añadir controles al render del detalle**

En el bloque del detalle (Task 7), modificar los tres cards para incluir formularios.

Card de cabecera — sustituir el bloque que muestra estado/visibilidad por:

```php
<div class="card mb-6" style="padding:24px;">
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
    <span class="badge <?= badge_clase_tipo($inc['tipo']) ?>"><?= format_incidencia_tipo($inc['tipo']) ?></span>

    <form method="POST" action="/admin/incidencias" style="display:inline-flex;gap:6px;align-items:center;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="actualizar_estado">
      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
      <select name="estado" class="form-control" style="padding:4px 8px;font-size:13px;">
        <?php foreach (INCIDENCIA_ESTADOS as $e): ?>
          <option value="<?= $e ?>" <?= $inc['estado'] === $e ? 'selected' : '' ?>><?= format_incidencia_estado($e) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-gray btn-sm">Cambiar estado</button>
    </form>

    <form method="POST" action="/admin/incidencias" style="display:inline-flex;gap:6px;align-items:center;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="toggle_visible">
      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
      <label style="font-size:13px;display:inline-flex;gap:6px;align-items:center;">
        <input type="checkbox" name="visible_socio" <?= (int)$inc['visible_socio'] === 1 ? 'checked' : '' ?>>
        Visible socio
      </label>
      <button type="submit" class="btn btn-gray btn-sm">Aplicar</button>
    </form>
  </div>

  <form method="POST" action="/admin/incidencias">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="editar_campos">
    <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
    <div class="form-group">
      <label class="form-label">Tipo</label>
      <select name="tipo" class="form-control" style="max-width:240px;">
        <?php foreach (INCIDENCIA_TIPOS as $t): ?>
          <option value="<?= $t ?>" <?= $inc['tipo'] === $t ? 'selected' : '' ?>><?= format_incidencia_tipo($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Título</label>
      <input type="text" name="titulo" value="<?= e($inc['titulo']) ?>" class="form-control" maxlength="200">
    </div>
    <div class="form-group">
      <label class="form-label">Descripción</label>
      <textarea name="descripcion" class="form-control" rows="5"><?= e($inc['descripcion']) ?></textarea>
    </div>
    <div class="d-flex gap-3 flex-wrap">
      <div class="form-group" style="flex:1;min-width:200px;">
        <label class="form-label">Fecha del suceso</label>
        <input type="date" name="fecha_suceso" value="<?= e($inc['fecha_suceso']) ?>" max="<?= date('Y-m-d') ?>" class="form-control">
      </div>
      <div class="form-group" style="flex:2;min-width:240px;">
        <label class="form-label">Socio</label>
        <select name="user_id" class="form-control">
          <option value="">— Sin socio —</option>
          <?php foreach ($socios as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$inc['user_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Guardar cambios</button>
  </form>
</div>
```

Card de adjuntos — añadir botón eliminar por adjunto y form de subida:

```php
<div class="card mb-6" style="padding:24px;">
  <h3>Adjuntos (<?= count($adjuntos) ?>)</h3>
  <?php if ($adjuntos): ?>
    <ul style="list-style:none;padding:0;">
      <?php foreach ($adjuntos as $a): ?>
        <li style="padding:8px 0;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;gap:8px;">
          <div>
            <a href="/admin/incidencia_descargar.php?id=<?= (int)$a['id'] ?>"><?= e($a['nombre_original']) ?></a>
            <span class="text-muted text-sm">— <?= number_format($a['tamano'] / 1024, 0) ?> KB · <?= e($a['mime']) ?></span>
          </div>
          <form method="POST" action="/admin/incidencias">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="eliminar_adjunto">
            <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
            <input type="hidden" name="adjunto_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="btn btn-gray btn-sm">Eliminar</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($inc['estado'] !== 'cerrada' && count($adjuntos) < INCIDENCIA_MAX_ADJUNTOS): ?>
    <form method="POST" action="/admin/incidencias" enctype="multipart/form-data" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="subir_adjunto">
      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
      <div class="form-group">
        <label class="form-label">Añadir adjunto</label>
        <input type="file" name="adjunto" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Subir</button>
    </form>
  <?php endif; ?>
</div>
```

Card de comentarios — añadir form al final si se puede comentar:

```php
<div class="card" style="padding:24px;">
  <h3>Comentarios (<?= count($comentarios) ?>)</h3>
  <?php if ($comentarios): ?>
    <?php foreach ($comentarios as $c): ?>
      <div style="border-left:3px solid var(--blue);padding:8px 12px;margin-bottom:12px;background:#f9fafb;">
        <div class="text-sm">
          <strong><?= e($c['autor_nombre']) ?></strong>
          <span class="badge <?= $c['autor_rol'] === 'admin' ? 'badge-operativa' : 'badge-gray' ?>" style="font-size:10px;"><?= e($c['autor_rol']) ?></span>
          <span class="text-muted">· <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></span>
        </div>
        <div style="white-space:pre-wrap;margin-top:6px;"><?= e($c['contenido']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="text-muted">Sin comentarios.</p>
  <?php endif; ?>

  <?php if (puede_comentar_incidencia($inc, current_user())): ?>
    <form method="POST" action="/admin/incidencias" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="comentar">
      <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
      <div class="form-group">
        <textarea name="contenido" class="form-control" rows="3" placeholder="Escribe un comentario…" required></textarea>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Comentar</button>
    </form>
  <?php else: ?>
    <p class="text-muted text-sm">Comentarios bloqueados (incidencia cerrada).</p>
  <?php endif; ?>
</div>
```

Nota: para eliminar un adjunto se envía el form sin confirmación. Es una acción recuperable (re-subir) y no merece interrupción. El modal custom se reserva para "eliminar incidencia" (Task 10), que es destructivo e irreversible.

- [ ] **Step 3: Verificar acciones**

1. Cambiar estado de incidencia abierta → en_curso → cerrada → volver a abierta.
2. Toggle visibilidad on/off.
3. Editar campos (cambiar título, descripción).
4. Subir un adjunto adicional.
5. Eliminar un adjunto (debe desaparecer fila BD + fichero físico).
6. Comentar (verificar que aparece en hilo).
7. Cerrar incidencia → comprobar que botón "Comentar" desaparece y "Añadir adjunto" desaparece.

- [ ] **Step 4: Commit**

```bash
git add public/admin/incidencias.php
git commit -m "feat(incidencias): admin acciones (estado, visibilidad, editar, comentar, adjuntos)"
```

---

## Task 9: Admin — endpoint de descarga de adjuntos

**Files:**
- Create: `public/admin/incidencia_descargar.php`

- [ ] **Step 1: Crear el endpoint**

Crear `public/admin/incidencia_descargar.php`:

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/incidencias.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
$adj = obtener_adjunto($pdo, $id);
if (!$adj) {
    http_response_code(404);
    die('Adjunto no encontrado.');
}

$path = INCIDENCIA_UPLOAD_DIR . $adj['archivo'];
if (!is_file($path)) {
    http_response_code(404);
    die('Fichero no encontrado en disco.');
}

$disposition = str_starts_with($adj['mime'], 'image/') || $adj['mime'] === 'application/pdf'
    ? 'inline'
    : 'attachment';

header('Content-Type: ' . $adj['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $adj['nombre_original']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
```

- [ ] **Step 2: Verificar descarga**

Desde detalle admin de una incidencia con adjunto PDF/imagen, click en el nombre → debe abrirse inline. Para confirmar:

```bash
curl -sI -b "PHPSESSID=$SESSION" "http://localhost:8080/admin/incidencia_descargar.php?id=1" | head -5
```

(Obtener PHPSESSID de las cookies del navegador admin.) Esperado: `Content-Type: application/pdf` o image, `Content-Disposition: inline`.

- [ ] **Step 3: Commit**

```bash
git add public/admin/incidencia_descargar.php
git commit -m "feat(incidencias): endpoint descarga de adjuntos para admin"
```

---

## Task 10: Admin — eliminar incidencia + modal de confirmación custom

**Files:**
- Modify: `public/admin/incidencias.php`

- [ ] **Step 1: Añadir manejador POST de eliminación**

Insertar junto al resto de manejadores POST:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        eliminar_incidencia($pdo, $id);
        flash('Incidencia eliminada.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /admin/incidencias');
    exit;
}
```

- [ ] **Step 2: Añadir botón eliminar + modal al detalle**

Al final del último card del detalle (después del bloque de comentarios), antes del cierre del bloque `if ($verId > 0)`:

```php
<div style="margin-top:24px;text-align:right;">
  <button type="button" class="btn btn-gray btn-sm" onclick="abrirModalEliminar(<?= (int)$inc['id'] ?>)" style="background:#dc2626;color:white;">
    <i class="bi bi-trash"></i> Eliminar incidencia
  </button>
</div>

<div id="modal-eliminar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:8px;max-width:420px;width:90%;">
    <h3 style="margin:0 0 12px;">¿Eliminar incidencia?</h3>
    <p>Se eliminarán también todos los adjuntos y comentarios. Esta acción no se puede deshacer.</p>
    <form method="POST" action="/admin/incidencias" id="form-eliminar">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="modal-eliminar-id">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-gray" onclick="cerrarModalEliminar()">Cancelar</button>
        <button type="submit" class="btn" style="background:#dc2626;color:white;">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalEliminar(id) {
  document.getElementById('modal-eliminar-id').value = id;
  var m = document.getElementById('modal-eliminar');
  m.style.display = 'flex';
}
function cerrarModalEliminar() {
  document.getElementById('modal-eliminar').style.display = 'none';
}
</script>
```

- [ ] **Step 3: Verificar borrado**

Crear una incidencia de prueba con adjunto. Borrarla desde el detalle → modal aparece, confirmar → redirige al listado vacío + flash success. Verificar en BD que ya no existe la fila ni adjuntos. Verificar en `public/uploads/incidencias/` que el fichero se borró.

- [ ] **Step 4: Commit**

```bash
git add public/admin/incidencias.php
git commit -m "feat(incidencias): admin eliminar incidencia con modal de confirmación"
```

---

## Task 11: Socio — listado de incidencias propias

**Files:**
- Create: `public/socio/incidencias.php`

- [ ] **Step 1: Crear listado básico**

Crear `public/socio/incidencias.php`:

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/incidencias.php';

require_login();
$user = current_user();

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;

$filtros = [
    'tipo' => $_GET['tipo'] ?? '',
    'estado' => $_GET['estado'] ?? '',
];
$incidencias = listar_incidencias_socio($pdo, (int)$user['id'], $filtros);

render_header('Incidencias', 'socio-incidencias');
?>

<main class="container" style="padding:24px 16px;">
  <?php if ($accion === '' && $verId === 0): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
      <h1 style="margin:0;">Mis incidencias</h1>
      <a href="/socio/incidencias?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva incidencia</a>
    </div>
    <?php render_flash(); ?>

    <div class="card mb-6">
      <form method="GET" class="d-flex gap-3 align-center flex-wrap" style="padding:16px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Tipo</label>
          <select name="tipo" class="form-control" style="width:auto;">
            <option value="">Todos</option>
            <?php foreach (INCIDENCIA_TIPOS as $t): ?>
              <option value="<?= $t ?>" <?= $filtros['tipo'] === $t ? 'selected' : '' ?>><?= format_incidencia_tipo($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Estado</label>
          <select name="estado" class="form-control" style="width:auto;">
            <option value="">Todos</option>
            <?php foreach (INCIDENCIA_ESTADOS as $e): ?>
              <option value="<?= $e ?>" <?= $filtros['estado'] === $e ? 'selected' : '' ?>><?= format_incidencia_estado($e) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <a href="/socio/incidencias" class="btn btn-gray btn-sm">Limpiar</a>
      </form>
    </div>

    <?php if (!$incidencias): ?>
      <div class="card text-center" style="padding:32px;">
        <p class="text-muted">No tienes incidencias.</p>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Título</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($incidencias as $i): ?>
                <tr>
                  <td><?= date('d/m/Y', strtotime($i['fecha_suceso'])) ?></td>
                  <td><span class="badge <?= badge_clase_tipo($i['tipo']) ?>"><?= format_incidencia_tipo($i['tipo']) ?></span></td>
                  <td><?= e($i['titulo']) ?></td>
                  <td><span class="badge <?= badge_clase_estado($i['estado']) ?>"><?= format_incidencia_estado($i['estado']) ?></span></td>
                  <td><a href="/socio/incidencias?ver=<?= (int)$i['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>

<?php render_footer(); ?>
```

- [ ] **Step 2: Verificar listado vacío y con datos**

Login socio → `http://localhost:8080/socio/incidencias` → debe mostrar empty state.

Insertar una incidencia visible para ese socio desde admin → recargar → debe aparecer en la tabla.

- [ ] **Step 3: Commit**

```bash
git add public/socio/incidencias.php
git commit -m "feat(incidencias): socio listado de incidencias propias"
```

---

## Task 12: Socio — crear nueva incidencia propia

**Files:**
- Modify: `public/socio/incidencias.php`

- [ ] **Step 1: Añadir manejador POST**

Tras `$user = current_user();` añadir:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_verify();
    try {
        $data = [
            'tipo' => $_POST['tipo'] ?? '',
            'titulo' => $_POST['titulo'] ?? '',
            'descripcion' => $_POST['descripcion'] ?? '',
            'fecha_suceso' => $_POST['fecha_suceso'] ?? '',
            'user_id' => (int)$user['id'],
            'visible_socio' => 1,
            'creado_por' => (int)$user['id'],
        ];
        $files = [];
        if (!empty($_FILES['adjuntos']) && is_array($_FILES['adjuntos']['name'])) {
            foreach ($_FILES['adjuntos']['name'] as $idx => $name) {
                if (($_FILES['adjuntos']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $files[] = [
                    'name' => $name,
                    'tmp_name' => $_FILES['adjuntos']['tmp_name'][$idx],
                    'error' => $_FILES['adjuntos']['error'][$idx],
                    'size' => $_FILES['adjuntos']['size'][$idx],
                    'type' => $_FILES['adjuntos']['type'][$idx],
                ];
            }
        }
        $id = crear_incidencia($pdo, $data, $files);
        flash('Incidencia creada.', 'success');
        header('Location: /socio/incidencias?ver=' . $id);
        exit;
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
        header('Location: /socio/incidencias?accion=nueva');
        exit;
    }
}
```

- [ ] **Step 2: Añadir bloque render del form**

Tras el bloque `<?php if ($accion === '' && $verId === 0): ?>...<?php endif; ?>` añadir:

```php
<?php if ($accion === 'nueva'): ?>
  <h1>Nueva incidencia</h1>
  <?php render_flash(); ?>
  <form method="POST" action="/socio/incidencias" enctype="multipart/form-data" class="card" style="padding:24px;max-width:680px;">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="crear">

    <div class="form-group">
      <label class="form-label">Tipo *</label>
      <select name="tipo" class="form-control" required>
        <option value="">— Selecciona —</option>
        <?php foreach (INCIDENCIA_TIPOS as $t): ?>
          <option value="<?= $t ?>"><?= format_incidencia_tipo($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Título *</label>
      <input type="text" name="titulo" class="form-control" required maxlength="200">
    </div>

    <div class="form-group">
      <label class="form-label">Descripción *</label>
      <textarea name="descripcion" class="form-control" rows="5" required></textarea>
    </div>

    <div class="form-group">
      <label class="form-label">Fecha del suceso *</label>
      <input type="date" name="fecha_suceso" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
    </div>

    <div class="form-group">
      <label class="form-label">Adjuntos (PDF, JPG, PNG — máx 5 MB, hasta 5)</label>
      <input type="file" name="adjuntos[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png">
    </div>

    <div style="display:flex;gap:12px;">
      <button type="submit" class="btn btn-primary">Crear</button>
      <a href="/socio/incidencias" class="btn btn-gray">Cancelar</a>
    </div>
  </form>
<?php endif; ?>
```

- [ ] **Step 3: Verificar creación por socio**

Login socio → "Nueva incidencia" → crear una de tipo "Justificante", con un PDF. Submit → redirige a `?ver=<id>` (404 hasta Task 13). Verificar en phpMyAdmin que `user_id` y `creado_por` = id del socio, `visible_socio=1`.

Verificar también que NO se generó comunicación (es autonotif del socio, debe estar bloqueado).

```sql
SELECT * FROM comunicaciones WHERE link_url LIKE '/socio/incidencias?ver=%' ORDER BY id DESC LIMIT 5;
```

Esperado: ninguna entrada nueva tras la creación por socio.

- [ ] **Step 4: Commit**

```bash
git add public/socio/incidencias.php
git commit -m "feat(incidencias): socio crear nueva incidencia propia"
```

---

## Task 13: Socio — detalle (ver + comentar + subir adjunto)

**Files:**
- Modify: `public/socio/incidencias.php`

- [ ] **Step 1: Añadir manejadores POST de comentar y subir adjunto**

Tras el bloque POST de crear, añadir:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'comentar') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        $inc = obtener_incidencia($pdo, $id);
        if (!$inc) throw new RuntimeException('Incidencia no encontrada');
        if (!puede_comentar_incidencia($inc, $user)) throw new RuntimeException('No puedes comentar');
        agregar_comentario($pdo, $id, (int)$user['id'], $_POST['contenido'] ?? '');
        flash('Comentario añadido.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /socio/incidencias?ver=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'subir_adjunto') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        $inc = obtener_incidencia($pdo, $id);
        if (!$inc) throw new RuntimeException('Incidencia no encontrada');
        if (!puede_subir_adjunto($inc, $user)) throw new RuntimeException('No puedes subir adjuntos');
        if (empty($_FILES['adjunto']) || ($_FILES['adjunto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No se seleccionó fichero');
        }
        subir_adjunto($pdo, $id, $_FILES['adjunto'], (int)$user['id']);
        flash('Adjunto subido.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /socio/incidencias?ver=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_adjunto') {
    csrf_verify();
    $id = (int)$_POST['id'];
    try {
        eliminar_adjunto($pdo, (int)$_POST['adjunto_id'], $user);
        flash('Adjunto eliminado.', 'success');
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
    }
    header('Location: /socio/incidencias?ver=' . $id);
    exit;
}
```

- [ ] **Step 2: Añadir render del detalle**

Tras el bloque `accion === 'nueva'`:

```php
<?php if ($verId > 0):
    $inc = obtener_incidencia($pdo, $verId);
    if (!$inc || !puede_ver_incidencia($inc, $user)):
?>
  <div class="card" style="padding:32px;text-align:center;">
    <h2 style="margin-top:0;">No tienes acceso a esta incidencia</h2>
    <p class="text-muted">La incidencia no existe o ya no es visible.</p>
    <a href="/socio/incidencias" class="btn btn-primary btn-sm">Volver a mis incidencias</a>
  </div>
<?php else:
    $adjuntos = listar_adjuntos($pdo, $verId);
    $comentarios = listar_comentarios($pdo, $verId);
?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <h1 style="margin:0;">Incidencia</h1>
    <a href="/socio/incidencias" class="btn btn-gray btn-sm">← Volver</a>
  </div>
  <?php render_flash(); ?>

  <div class="card mb-6" style="padding:24px;">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
      <span class="badge <?= badge_clase_tipo($inc['tipo']) ?>"><?= format_incidencia_tipo($inc['tipo']) ?></span>
      <span class="badge <?= badge_clase_estado($inc['estado']) ?>"><?= format_incidencia_estado($inc['estado']) ?></span>
    </div>
    <h2 style="margin:0 0 12px;"><?= e($inc['titulo']) ?></h2>
    <div class="text-muted text-sm" style="margin-bottom:16px;">
      Fecha suceso: <?= date('d/m/Y', strtotime($inc['fecha_suceso'])) ?>
      · Creada: <?= date('d/m/Y H:i', strtotime($inc['created_at'])) ?>
    </div>
    <div style="white-space:pre-wrap;line-height:1.6;"><?= e($inc['descripcion']) ?></div>
  </div>

  <div class="card mb-6" style="padding:24px;">
    <h3>Adjuntos (<?= count($adjuntos) ?>)</h3>
    <?php if ($adjuntos): ?>
      <ul style="list-style:none;padding:0;">
        <?php foreach ($adjuntos as $a): ?>
          <li style="padding:8px 0;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <div>
              <a href="/socio/incidencia_descargar.php?id=<?= (int)$a['id'] ?>"><?= e($a['nombre_original']) ?></a>
              <span class="text-muted text-sm">— <?= number_format($a['tamano'] / 1024, 0) ?> KB</span>
            </div>
            <?php if ((int)$a['subido_por'] === (int)$user['id'] && $inc['estado'] === 'abierta'): ?>
              <form method="POST" action="/socio/incidencias">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="eliminar_adjunto">
                <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
                <input type="hidden" name="adjunto_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn btn-gray btn-sm">Eliminar</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-muted">Sin adjuntos.</p>
    <?php endif; ?>

    <?php if (puede_subir_adjunto($inc, $user) && count($adjuntos) < INCIDENCIA_MAX_ADJUNTOS): ?>
      <form method="POST" action="/socio/incidencias" enctype="multipart/form-data" style="margin-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="subir_adjunto">
        <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
        <div class="form-group">
          <label class="form-label">Añadir adjunto</label>
          <input type="file" name="adjunto" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Subir</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:24px;">
    <h3>Comentarios (<?= count($comentarios) ?>)</h3>
    <?php if ($comentarios): ?>
      <?php foreach ($comentarios as $c): ?>
        <div style="border-left:3px solid var(--blue);padding:8px 12px;margin-bottom:12px;background:#f9fafb;">
          <div class="text-sm">
            <strong><?= e($c['autor_nombre']) ?></strong>
            <span class="badge <?= $c['autor_rol'] === 'admin' ? 'badge-operativa' : 'badge-gray' ?>" style="font-size:10px;"><?= e($c['autor_rol']) ?></span>
            <span class="text-muted">· <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></span>
          </div>
          <div style="white-space:pre-wrap;margin-top:6px;"><?= e($c['contenido']) ?></div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="text-muted">Sin comentarios.</p>
    <?php endif; ?>

    <?php if (puede_comentar_incidencia($inc, $user)): ?>
      <form method="POST" action="/socio/incidencias" style="margin-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="comentar">
        <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
        <div class="form-group">
          <textarea name="contenido" class="form-control" rows="3" placeholder="Escribe un comentario…" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Comentar</button>
      </form>
    <?php else: ?>
      <p class="text-muted text-sm">Comentarios bloqueados (incidencia cerrada).</p>
    <?php endif; ?>
  </div>
<?php endif; endif; ?>
```

- [ ] **Step 3: Verificar detalle socio**

1. Login socio, ver una incidencia propia visible → ver bloques.
2. Comentar → debe aparecer en hilo.
3. Subir un adjunto → debe aparecer.
4. Eliminar el adjunto propio en incidencia abierta → debe funcionar.
5. Como admin, cerrar la incidencia → socio recarga → form de comentar y de subir desaparecen.
6. Como admin, ocultar visibilidad (toggle off) → socio recarga `?ver=<id>` → debe mostrar "No tienes acceso".
7. Como admin, intentar ver una incidencia que pertenece a otro socio desde la URL del socio → "No tienes acceso".

- [ ] **Step 4: Commit**

```bash
git add public/socio/incidencias.php
git commit -m "feat(incidencias): socio detalle (ver + comentar + adjuntos propios)"
```

---

## Task 14: Socio — endpoint de descarga de adjuntos

**Files:**
- Create: `public/socio/incidencia_descargar.php`

- [ ] **Step 1: Crear endpoint**

Crear `public/socio/incidencia_descargar.php`:

```php
<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/incidencias.php';

require_login();
$user = current_user();

$id = (int)($_GET['id'] ?? 0);
$adj = obtener_adjunto($pdo, $id);
if (!$adj) {
    http_response_code(404);
    die('Adjunto no encontrado.');
}

$inc = obtener_incidencia($pdo, (int)$adj['incidencia_id']);
if (!$inc || !puede_ver_incidencia($inc, $user)) {
    http_response_code(403);
    die('No tienes acceso a este adjunto.');
}

$path = INCIDENCIA_UPLOAD_DIR . $adj['archivo'];
if (!is_file($path)) {
    http_response_code(404);
    die('Fichero no encontrado en disco.');
}

$disposition = str_starts_with($adj['mime'], 'image/') || $adj['mime'] === 'application/pdf'
    ? 'inline'
    : 'attachment';

header('Content-Type: ' . $adj['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $adj['nombre_original']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
```

- [ ] **Step 2: Verificar descarga socio**

Como socio, desde el detalle de su incidencia, click en un adjunto → debe abrirse el PDF inline / imagen inline.

Probar acceso indirecto: socio A intenta abrir un adjunto de incidencia de socio B vía URL → debe responder 403.

- [ ] **Step 3: Commit**

```bash
git add public/socio/incidencia_descargar.php
git commit -m "feat(incidencias): endpoint descarga de adjuntos para socio con check de permisos"
```

---

## Task 15: Verificación end-to-end + actualizar CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Recorrido end-to-end del flujo completo**

Como **admin**:

1. Crear incidencia tipo "Lesión" para socio A, con adjunto PDF, visible_socio=1.
2. Verificar en `comunicaciones` (phpMyAdmin) que aparece fila individual para socio A con `link_url='/socio/incidencias?ver=<id>'`.
3. Cambiar estado a "en_curso" → verificar nueva fila en `comunicaciones`.
4. Cerrar → verificar tercera fila.
5. Editar título → verificar que NO se generó comunicación.
6. Añadir comentario → verificar que NO se generó comunicación.

Como **socio A**:

7. Login → ir a `/socio/comunicaciones` → ver las 3 notificaciones, cada una con botón "Ver detalle →" que abre la incidencia.
8. Ir a "Incidencias" en navbar → ver su incidencia en el listado.
9. Abrir detalle → ver descripción, adjunto, sin comentarios todavía.
10. Como incidencia está cerrada, no debe poder comentar ni subir adjunto.

Como **admin** (regreso):

11. Reabrir incidencia (estado → abierta) → nueva comunicación al socio.
12. Como socio, comentar.
13. Como admin, responder al comentario.

Como **socio A**:

14. Crear incidencia propia tipo "Justificante" con adjunto.
15. Verificar en `comunicaciones` que NO se generó nada (auto-notif bloqueada).

Como **admin**:

16. Listado → filtrar por tipo justificante → debe ver la del socio.
17. Eliminar incidencia con adjunto → verificar fichero borrado del disco y filas BD.

Como **socio B**:

18. Intentar `http://localhost:8080/socio/incidencias?ver=<id_de_A>` → ver "No tienes acceso".

Si alguna parte falla, anotar y resolver antes del paso 2.

- [ ] **Step 2: Actualizar CLAUDE.md**

En `CLAUDE.md`, en la sección **Base de datos**, añadir filas a la tabla:

```markdown
| `incidencias` | Incidencias del club (lesiones, conducta, operativas, justificantes) con estados y visibilidad por socio |
| `incidencia_adjuntos` | Ficheros adjuntos por incidencia (PDF/JPG/PNG, máx 5 MB, máx 5/incidencia) |
| `incidencia_comentarios` | Hilo de comentarios bidireccional (admin + socio asociado) |
```

En la sección **Estructura de directorios**, dentro del bloque `public/admin/`:

```
    │   ├── incidencias.php  ← Listado + nueva + detalle + edit
    │   ├── incidencia_descargar.php ← Descarga de adjuntos
```

Y en `public/socio/`:

```
        ├── incidencias.php  ← Listado + nueva + detalle (solo propias visibles)
        ├── incidencia_descargar.php ← Descarga de adjuntos (con check)
```

En la sección **Estado de páginas**, añadir:

```markdown
| Admin — Incidencias | `public/admin/incidencias.php` | ✅ |
| Socio — Incidencias | `public/socio/incidencias.php` | ✅ |
```

- [ ] **Step 3: Push final**

```bash
git add CLAUDE.md
git commit -m "docs: añadir incidencias al inventario CLAUDE.md"
git push
```

- [ ] **Step 4: Verificación final**

```bash
git log --oneline -20
git status
```

Esperado: clean tree, 15 commits relacionados con incidencias en el historial reciente.

---

## Resumen de commits esperados

1. `feat(incidencias): migración SQL + directorio uploads protegido`
2. `feat(incidencias): helpers de dominio (CRUD + adjuntos + notif)`
3. `feat(comunicaciones): renderizar botón 'Ver detalle' si link_url existe`
4. `feat(incidencias): entradas de navegación en admin y socio`
5. `feat(incidencias): admin listado con filtros + paginación + badges`
6. `feat(incidencias): admin crear nueva incidencia + adjuntos opcionales`
7. `feat(incidencias): admin vista detalle (campos + adjuntos + comentarios)`
8. `feat(incidencias): admin acciones (estado, visibilidad, editar, comentar, adjuntos)`
9. `feat(incidencias): endpoint descarga de adjuntos para admin`
10. `feat(incidencias): admin eliminar incidencia con modal de confirmación`
11. `feat(incidencias): socio listado de incidencias propias`
12. `feat(incidencias): socio crear nueva incidencia propia`
13. `feat(incidencias): socio detalle (ver + comentar + adjuntos propios)`
14. `feat(incidencias): endpoint descarga de adjuntos para socio con check de permisos`
15. `docs: añadir incidencias al inventario CLAUDE.md`
