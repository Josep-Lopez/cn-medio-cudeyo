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

    try {
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
    } catch (Throwable $ex) {
        if (is_file($destino)) @unlink($destino);
        throw $ex;
    }
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
