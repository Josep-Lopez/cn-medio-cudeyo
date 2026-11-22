<?php
// Logros de una marca recién guardada: récord del club, marca de edad del club, mejor marca personal.
// Mismos criterios que /records (activo, prueba×piscina×sexo) y /ranking-edades (edad = año marca − año nacimiento, 10-18).
// Un empate no cuenta: el récord sigue siendo del que lo hizo antes.

const LOGROS_EDAD_MIN = 10;
const LOGROS_EDAD_MAX = 18;
// Días que una marca de edad se marca en verde como «nueva»
const LOGROS_DIAS_NUEVA = 30;

// Devuelve ['club' => bool, 'edad' => ?int (edad si es marca de edad), 'personal' => bool, 'marca' => fila]
function marca_logros(PDO $pdo, int $marca_id): ?array
{
    $stmt = $pdo->prepare('
        SELECT m.*, u.nombre, u.sexo, u.estado, u.fecha_nacimiento,
               YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento) AS edad
        FROM marcas m JOIN users u ON u.id = m.user_id
        WHERE m.id = ?
    ');
    $stmt->execute([$marca_id]);
    $m = $stmt->fetch();
    if (!$m || (int)$m['es_parcial'] === 1 || (float)$m['tiempo_seg'] <= 0) return null;

    // Otra marca igual o mejor (mismo nadador)
    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS total, SUM(tiempo_seg <= ?) AS mejores
        FROM marcas WHERE user_id = ? AND prueba = ? AND piscina = ? AND es_parcial = 0 AND id <> ?
    ');
    $stmt->execute([$m['tiempo_seg'], $m['user_id'], $m['prueba'], $m['piscina'], $marca_id]);
    $p = $stmt->fetch();
    // Solo es «mejor marca personal» si ya tenía marcas en esa prueba (la primera no es noticia)
    $personal = (int)$p['total'] > 0 && (int)$p['mejores'] === 0;

    $club = false;
    $edad = null;
    // Si el propio nadador tiene algo igual o mejor, no puede ser récord
    if ($m['estado'] === 'activo' && (int)$p['mejores'] === 0) {
        $base = '
            SELECT 1 FROM marcas o JOIN users u ON u.id = o.user_id
            WHERE o.prueba = ? AND o.piscina = ? AND o.es_parcial = 0 AND o.id <> ?
              AND u.estado = \'activo\' AND u.sexo = ? AND o.tiempo_seg <= ?';
        $params = [$m['prueba'], $m['piscina'], $marca_id, $m['sexo'], $m['tiempo_seg']];

        $stmt = $pdo->prepare($base . ' LIMIT 1');
        $stmt->execute($params);
        $club = !$stmt->fetchColumn();

        $e = $m['edad'] !== null ? (int)$m['edad'] : null;
        if ($e !== null && $e >= LOGROS_EDAD_MIN && $e <= LOGROS_EDAD_MAX) {
            $stmt = $pdo->prepare($base . '
              AND u.fecha_nacimiento IS NOT NULL
              AND YEAR(o.fecha_marca) - YEAR(u.fecha_nacimiento) = ? LIMIT 1');
            $stmt->execute([...$params, $e]);
            if (!$stmt->fetchColumn()) $edad = $e;
        }
    }

    return ['club' => $club, 'edad' => $edad, 'personal' => $personal, 'marca' => $m];
}

// Texto para el flash. Con $con_personal = false (importaciones masivas) solo se cuentan récords.
function logros_resumen(PDO $pdo, array $marca_ids, bool $con_personal = true, int $max = 8): string
{
    $lineas = [];
    foreach (array_unique(array_map('intval', $marca_ids)) as $id) {
        $l = marca_logros($pdo, $id);
        if (!$l) continue;
        $tipos = [];
        if ($l['club']) $tipos[] = '🏆 Récord del club';
        if ($l['edad'] !== null) $tipos[] = '🥇 Marca de edad del club (' . $l['edad'] . ' años)';
        if (!$tipos && $con_personal && $l['personal']) $tipos[] = '⭐ Mejor marca personal';
        if (!$tipos) continue;
        $m = $l['marca'];
        $lineas[] = implode(' + ', $tipos) . ': ' . $m['nombre'] . ' · ' . format_prueba($m['prueba']) . ' ' . $m['piscina'] . ' (' . $m['tiempo'] . ')';
    }
    if (!$lineas) return '';
    $extra = count($lineas) > $max ? ' … y ' . (count($lineas) - $max) . ' más.' : '';
    return ' ' . implode(' · ', array_slice($lineas, 0, $max)) . $extra;
}
