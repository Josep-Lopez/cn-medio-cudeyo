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
