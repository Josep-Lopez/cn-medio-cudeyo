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
