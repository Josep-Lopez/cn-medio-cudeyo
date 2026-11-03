<?php
// Helpers de dominio para competiciones registradas por el entrenador
// (independiente del ranking oficial en la tabla marcas).
// Requiere $pdo (config/db.php) y tiempo_a_segundos() de includes/auth.php.

require_once __DIR__ . '/minimas.php';

const CE_PRUEBAS = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];
const CE_PISCINAS = ['25m','50m'];
const CE_MINIMAS_FUENTES = ['ninguna' => 'Sin mínimas', 'rfen' => 'Mínimas RFEN (configuración)', 'propias' => 'Mínimas propias de la competición'];
// Relevos: código (postas x distancia + estilo) => [distancia por posta, estilo]. Estilos: E, B, M, L por orden de posta.
const CE_RELEVOS = ['4x50L' => [50, 'L'], '4x100L' => [100, 'L'], '4x200L' => [200, 'L'], '4x50X' => [50, 'X'], '4x100X' => [100, 'X'], '8x50L' => [50, 'L']];
const CE_RELEVO_MAX_POSTAS = 8;
const CE_RELEVO_SEXOS = ['M' => 'Masculino', 'F' => 'Femenino', 'X' => 'Mixto'];

function listar_competiciones_entrenador(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT c.*,
               (SELECT COUNT(*) FROM competicion_entrenador_tiempos t WHERE t.competicion_id = c.id) AS num_tiempos,
               (SELECT COUNT(*) FROM competicion_entrenador_convocados v WHERE v.competicion_id = c.id) AS num_convocados,
               (SELECT COUNT(*) FROM competicion_entrenador_relevos r WHERE r.competicion_id = c.id) AS num_relevos
        FROM competicion_entrenador c
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

// Valida y normaliza los datos editables de una competición.
function ce_validar_datos(array $d): array
{
    $nombre = trim($d['nombre'] ?? '');
    if ($nombre === '' || mb_strlen($nombre) > 150) {
        throw new InvalidArgumentException('Nombre de competición inválido');
    }
    $fecha = $d['fecha'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        throw new InvalidArgumentException('Fecha inválida');
    }
    $piscina = $d['piscina'] ?? '25m';
    if (!in_array($piscina, CE_PISCINAS, true)) {
        throw new InvalidArgumentException('Piscina no válida');
    }
    $fuente = $d['minimas_fuente'] ?? 'ninguna';
    if (!array_key_exists($fuente, CE_MINIMAS_FUENTES)) {
        throw new InvalidArgumentException('Fuente de mínimas no válida');
    }
    $fechaOpc = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new InvalidArgumentException('Fecha de marcas inválida');
        return $v;
    };
    $desde = $fechaOpc($d['marcas_desde'] ?? '');
    $hasta = $fechaOpc($d['marcas_hasta'] ?? '');
    if ($desde && $hasta && $desde > $hasta) {
        throw new InvalidArgumentException('La fecha "marcas desde" es posterior a "marcas hasta"');
    }
    $lugar = trim($d['lugar'] ?? '');
    return [
        'nombre' => $nombre,
        'lugar' => $lugar !== '' ? $lugar : null,
        'fecha' => $fecha,
        'piscina' => $piscina,
        'marcas_desde' => $desde,
        'marcas_hasta' => $hasta,
        'minimas_fuente' => $fuente,
    ];
}

function crear_competicion_entrenador(PDO $pdo, array $datos, int $creado_por): int
{
    $v = ce_validar_datos($datos);
    $stmt = $pdo->prepare('
        INSERT INTO competicion_entrenador (nombre, lugar, fecha, piscina, marcas_desde, marcas_hasta, minimas_fuente, creado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$v['nombre'], $v['lugar'], $v['fecha'], $v['piscina'], $v['marcas_desde'], $v['marcas_hasta'], $v['minimas_fuente'], $creado_por]);
    return (int)$pdo->lastInsertId();
}

function actualizar_competicion_entrenador(PDO $pdo, int $id, array $datos): void
{
    $v = ce_validar_datos($datos);
    $pdo->prepare('
        UPDATE competicion_entrenador
        SET nombre = ?, lugar = ?, fecha = ?, piscina = ?, marcas_desde = ?, marcas_hasta = ?, minimas_fuente = ?
        WHERE id = ?
    ')->execute([$v['nombre'], $v['lugar'], $v['fecha'], $v['piscina'], $v['marcas_desde'], $v['marcas_hasta'], $v['minimas_fuente'], $id]);
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

// Borra un tiempo de la competición (sus comentarios caen por ON DELETE CASCADE).
// Si venía de RFEN se anota como descarte para que la importación no lo vuelva a meter.
function eliminar_tiempo_entrenador(PDO $pdo, int $competicion_id, int $tiempo_id): void
{
    $stmt = $pdo->prepare('SELECT * FROM competicion_entrenador_tiempos WHERE id = ? AND competicion_id = ?');
    $stmt->execute([$tiempo_id, $competicion_id]);
    $t = $stmt->fetch();
    if (!$t) return;
    if ($t['origen'] === 'rfen') {
        $pdo->prepare('INSERT IGNORE INTO competicion_entrenador_rfen_descartes (competicion_id, user_id, prueba, piscina) VALUES (?, ?, ?, ?)')
            ->execute([$competicion_id, $t['user_id'], $t['prueba'], $t['piscina']]);
    }
    $pdo->prepare('DELETE FROM competicion_entrenador_tiempos WHERE id = ?')->execute([$tiempo_id]);
}

// ─── Resultados desde RFEN ───────────────────────────────────────────────────

// Días tras `fecha` que cuentan como la misma competición (competiciones de varios días)
const CE_RFEN_DIAS_COMPETICION = 2;
// El cron revisa las competiciones celebradas en los últimos N días
const CE_RFEN_DIAS_SINCRONIZAR = 30;

// Copia a la competición las marcas (ya importadas de RFEN) que los convocados hicieron en sus fechas.
// Por nadador×prueba×piscina se queda la mejor; no toca lo que ya tenga tiempo ni lo descartado.
function ce_importar_tiempos_rfen(PDO $pdo, array $comp, ?int $registrado_por = null): int
{
    $stmt = $pdo->prepare('
        SELECT m.user_id, m.prueba, m.piscina, m.tiempo, m.tiempo_seg
        FROM marcas m
        JOIN competicion_entrenador_convocados v ON v.user_id = m.user_id AND v.competicion_id = ?
        WHERE m.es_parcial = 0 AND m.tiempo_seg > 0
          AND m.fecha_marca BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)
          AND NOT EXISTS (SELECT 1 FROM competicion_entrenador_tiempos t
                          WHERE t.competicion_id = v.competicion_id AND t.user_id = m.user_id
                            AND t.prueba = m.prueba AND t.piscina = m.piscina)
          AND NOT EXISTS (SELECT 1 FROM competicion_entrenador_rfen_descartes d
                          WHERE d.competicion_id = v.competicion_id AND d.user_id = m.user_id
                            AND d.prueba = m.prueba AND d.piscina = m.piscina)
        ORDER BY m.tiempo_seg
    ');
    $stmt->execute([$comp['id'], $comp['fecha'], $comp['fecha'], CE_RFEN_DIAS_COMPETICION]);

    $mejores = [];
    foreach ($stmt->fetchAll() as $m) {
        $mejores[$m['user_id'] . '|' . $m['prueba'] . '|' . $m['piscina']] ??= $m;
    }

    $ins = $pdo->prepare("
        INSERT INTO competicion_entrenador_tiempos
            (competicion_id, user_id, prueba, piscina, tiempo, tiempo_seg, origen, registrado_por)
        VALUES (?, ?, ?, ?, ?, ?, 'rfen', ?)
    ");
    foreach ($mejores as $m) {
        $ins->execute([$comp['id'], $m['user_id'], $m['prueba'], $m['piscina'], $m['tiempo'], $m['tiempo_seg'],
            $registrado_por ?? (int)$comp['creado_por']]);
    }
    return count($mejores);
}

// Para el cron: importa en todas las competiciones recientes. Devuelve [nombre competición => tiempos añadidos].
function ce_importar_tiempos_rfen_recientes(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT * FROM competicion_entrenador WHERE fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND CURDATE()');
    $stmt->execute([CE_RFEN_DIAS_SINCRONIZAR]);
    $res = [];
    foreach ($stmt->fetchAll() as $comp) {
        $res[$comp['nombre']] = ce_importar_tiempos_rfen($pdo, $comp);
    }
    return $res;
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

// ─── Convocatoria ────────────────────────────────────────────────────────────

function listar_convocados(PDO $pdo, int $competicion_id): array
{
    $stmt = $pdo->prepare('
        SELECT u.id, u.nombre, u.sexo, u.liga, u.fecha_nacimiento
        FROM competicion_entrenador_convocados v
        JOIN users u ON u.id = v.user_id
        WHERE v.competicion_id = ?
        ORDER BY u.nombre
    ');
    $stmt->execute([$competicion_id]);
    return $stmt->fetchAll();
}

function listar_no_convocados(PDO $pdo, int $competicion_id): array
{
    $stmt = $pdo->prepare("
        SELECT id, nombre, sexo, liga
        FROM users
        WHERE rol = 'socio' AND estado = 'activo' AND nadador_activo = 1
          AND id NOT IN (SELECT user_id FROM competicion_entrenador_convocados WHERE competicion_id = ?)
        ORDER BY nombre
    ");
    $stmt->execute([$competicion_id]);
    return $stmt->fetchAll();
}

function convocar_nadador(PDO $pdo, int $competicion_id, int $user_id): void
{
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND rol = 'socio' AND estado = 'activo'");
    $stmt->execute([$user_id]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Nadador no válido');
    $pdo->prepare('INSERT IGNORE INTO competicion_entrenador_convocados (competicion_id, user_id) VALUES (?, ?)')
        ->execute([$competicion_id, $user_id]);
}

// Convoca a todos los nadadores activos de un grupo de entrenamiento. Devuelve cuántos nuevos.
function convocar_grupo(PDO $pdo, int $competicion_id, int $grupo_id): int
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO competicion_entrenador_convocados (competicion_id, user_id)
        SELECT ?, u.id
        FROM grupo_nadadores gn
        JOIN users u ON u.id = gn.user_id
        WHERE gn.grupo_id = ? AND u.rol = 'socio' AND u.estado = 'activo' AND u.nadador_activo = 1
    ");
    $stmt->execute([$competicion_id, $grupo_id]);
    return $stmt->rowCount();
}

// Quita al nadador de la convocatoria: sus pruebas caen por FK; las postas de relevo se borran aquí.
function desconvocar_nadador(PDO $pdo, int $competicion_id, int $user_id): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            DELETE rn FROM competicion_entrenador_relevo_nadadores rn
            JOIN competicion_entrenador_relevos r ON r.id = rn.relevo_id
            WHERE r.competicion_id = ? AND rn.user_id = ?
        ')->execute([$competicion_id, $user_id]);
        $pdo->prepare('DELETE FROM competicion_entrenador_convocados WHERE competicion_id = ? AND user_id = ?')
            ->execute([$competicion_id, $user_id]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

// Pruebas inscritas agrupadas por nadador: [user_id => [prueba => fila]]
function listar_pruebas_convocatoria(PDO $pdo, int $competicion_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM competicion_entrenador_pruebas WHERE competicion_id = ? ORDER BY id');
    $stmt->execute([$competicion_id]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['user_id']][$r['prueba']] = $r;
    }
    foreach ($out as &$pruebas) {
        uksort($pruebas, fn($a, $b) => array_search($a, CE_PRUEBAS, true) <=> array_search($b, CE_PRUEBAS, true));
    }
    return $out;
}

function inscribir_prueba(PDO $pdo, array $comp, array $nadador, string $prueba): void
{
    if (!in_array($prueba, CE_PRUEBAS, true)) throw new InvalidArgumentException('Prueba no válida');
    $marca = ce_marca_inscripcion($pdo, $comp, $nadador, $prueba);
    $pdo->prepare('
        INSERT INTO competicion_entrenador_pruebas
            (competicion_id, user_id, prueba, marca_tiempo, marca_seg, marca_piscina, marca_fecha)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE prueba = prueba
    ')->execute([
        (int)$comp['id'], (int)$nadador['id'], $prueba,
        $marca['tiempo'] ?? null, $marca['seg'] ?? null, $marca['piscina'] ?? null, $marca['fecha'] ?? null,
    ]);
}

function desinscribir_prueba(PDO $pdo, int $competicion_id, int $user_id, string $prueba): void
{
    $pdo->prepare('DELETE FROM competicion_entrenador_pruebas WHERE competicion_id = ? AND user_id = ? AND prueba = ?')
        ->execute([$competicion_id, $user_id, $prueba]);
}

// Vuelve a calcular la marca de inscripción de todas las pruebas (tras cambiar ventana, piscina o mínimas).
function recalcular_marcas_convocatoria(PDO $pdo, array $comp): int
{
    $nadadores = [];
    foreach (listar_convocados($pdo, (int)$comp['id']) as $n) $nadadores[(int)$n['id']] = $n;
    $upd = $pdo->prepare('
        UPDATE competicion_entrenador_pruebas
        SET marca_tiempo = ?, marca_seg = ?, marca_piscina = ?, marca_fecha = ?
        WHERE id = ?
    ');
    $n = 0;
    foreach (listar_pruebas_convocatoria($pdo, (int)$comp['id']) as $uid => $pruebas) {
        if (!isset($nadadores[$uid])) continue;
        foreach ($pruebas as $prueba => $fila) {
            $m = ce_marca_inscripcion($pdo, $comp, $nadadores[$uid], $prueba);
            $upd->execute([$m['tiempo'] ?? null, $m['seg'] ?? null, $m['piscina'] ?? null, $m['fecha'] ?? null, (int)$fila['id']]);
            $n++;
        }
    }
    return $n;
}

// ─── Mejores marcas ──────────────────────────────────────────────────────────

// Mejores marcas del nadador dentro de la ventana de la competición, de marcas (RFEN/admin)
// y de tiempos registrados por el entrenador. Sin "hasta", solo cuentan marcas anteriores a la
// competición; los tiempos de la propia competición nunca cuentan.
// Devuelve [prueba][piscina] => [seg, tiempo, fecha].
function ce_mejores_marcas(PDO $pdo, int $user_id, array $comp): array
{
    static $cache = [];
    $key = $user_id . '|' . $comp['id'] . '|' . ($comp['fecha'] ?? '') . '|' . ($comp['marcas_desde'] ?? '') . '|' . ($comp['marcas_hasta'] ?? '');
    if (isset($cache[$key])) return $cache[$key];

    $condM = ''; $condT = ' AND t.competicion_id <> ?'; $pM = [$user_id]; $pT = [$user_id, (int)$comp['id']];
    if (!empty($comp['marcas_desde'])) {
        $condM .= ' AND fecha_marca >= ?'; $pM[] = $comp['marcas_desde'];
        $condT .= ' AND c.fecha >= ?';     $pT[] = $comp['marcas_desde'];
    }
    if (!empty($comp['marcas_hasta'])) {
        $condM .= ' AND fecha_marca <= ?'; $pM[] = $comp['marcas_hasta'];
        $condT .= ' AND c.fecha <= ?';     $pT[] = $comp['marcas_hasta'];
    } else {
        $condM .= ' AND fecha_marca < ?'; $pM[] = $comp['fecha'];
        $condT .= ' AND c.fecha < ?';     $pT[] = $comp['fecha'];
    }
    $stmt = $pdo->prepare("
        SELECT prueba, piscina, tiempo_seg, tiempo, fecha_marca AS fecha
        FROM marcas WHERE user_id = ? AND es_parcial = 0 AND tiempo_seg > 0 $condM
        UNION ALL
        SELECT t.prueba, t.piscina, t.tiempo_seg, t.tiempo, c.fecha
        FROM competicion_entrenador_tiempos t
        JOIN competicion_entrenador c ON c.id = t.competicion_id
        WHERE t.user_id = ? AND t.tiempo_seg > 0 $condT
    ");
    $stmt->execute(array_merge($pM, $pT));
    $best = [];
    foreach ($stmt->fetchAll() as $r) {
        $seg = (float)$r['tiempo_seg'];
        $cur = $best[$r['prueba']][$r['piscina']] ?? null;
        if (!$cur || $seg < $cur['seg']) {
            $best[$r['prueba']][$r['piscina']] = ['seg' => $seg, 'tiempo' => segundos_a_tiempo($seg), 'fecha' => $r['fecha']];
        }
    }
    return $cache[$key] = $best;
}

// Elige la marca con la que se inscribe: si hay mínimas, la que mejor % da respecto a la
// mínima de su piscina; si no, la de la piscina de la competición y, en su defecto, la otra.
function ce_marca_inscripcion(PDO $pdo, array $comp, array $nadador, string $prueba): ?array
{
    $porPiscina = ce_mejores_marcas($pdo, (int)$nadador['id'], $comp)[$prueba] ?? [];
    if (!$porPiscina) return null;

    $minimas = ce_minimas_nadador($pdo, $comp, $nadador, $prueba);
    $mejor = null; $mejorPct = -1;
    foreach ($porPiscina as $piscina => $m) {
        if (isset($minimas[$piscina])) {
            $pct = $minimas[$piscina] / $m['seg'];
            if ($pct > $mejorPct) { $mejorPct = $pct; $mejor = $m + ['piscina' => $piscina]; }
        }
    }
    if ($mejor) return $mejor;

    $piscina = isset($porPiscina[$comp['piscina']]) ? $comp['piscina'] : array_key_first($porPiscina);
    return $porPiscina[$piscina] + ['piscina' => $piscina];
}

// ─── Mínimas ─────────────────────────────────────────────────────────────────

// Mínimas aplicables al nadador para la prueba: [piscina => segundos].
function ce_minimas_nadador(PDO $pdo, array $comp, array $nadador, string $prueba): array
{
    $fuente = $comp['minimas_fuente'] ?? 'ninguna';
    $sexo = $nadador['sexo'] ?? null;
    if ($fuente === 'ninguna' || !$sexo) return [];

    if ($fuente === 'rfen') {
        static $rfen = null;
        $rfen ??= minimas_cargar($pdo);
        $edad = edad_deportiva($comp['fecha'], $nadador['fecha_nacimiento'] ?? null);
        $out = [];
        foreach (CE_PISCINAS as $piscina) {
            $m = minima_para($rfen, $prueba, $piscina, $sexo, $edad);
            if ($m) $out[$piscina] = $m['seg'];
        }
        return $out;
    }

    // Propias: coincide sexo y año de nacimiento dentro del rango (rango vacío = cualquiera).
    // Si varias encajan, gana la de rango más estrecho.
    $anio = !empty($nadador['fecha_nacimiento']) ? (int)substr($nadador['fecha_nacimiento'], 0, 4) : null;
    $out = []; $ancho = [];
    foreach (listar_minimas_competicion($pdo, (int)$comp['id']) as $m) {
        if ($m['prueba'] !== $prueba || $m['sexo'] !== $sexo) continue;
        $desde = $m['anio_desde'] !== null ? (int)$m['anio_desde'] : null;
        $hasta = $m['anio_hasta'] !== null ? (int)$m['anio_hasta'] : null;
        if (($desde !== null || $hasta !== null) && $anio === null) continue;
        if ($desde !== null && $anio < $desde) continue;
        if ($hasta !== null && $anio > $hasta) continue;
        $w = ($hasta ?? 9999) - ($desde ?? 0);
        if (!isset($ancho[$m['piscina']]) || $w < $ancho[$m['piscina']]) {
            $ancho[$m['piscina']] = $w;
            $out[$m['piscina']] = (float)$m['tiempo_seg'];
        }
    }
    return $out;
}

// Estado de la marca de inscripción frente a la mínima de su piscina, o null si no hay mínima.
function ce_estado_minima(PDO $pdo, array $comp, array $nadador, array $fila): ?array
{
    $minimas = ce_minimas_nadador($pdo, $comp, $nadador, $fila['prueba']);
    if (!$minimas) return null;
    if (empty($fila['marca_seg']) || !isset($minimas[$fila['marca_piscina']])) {
        $piscina = isset($minimas[$comp['piscina']]) ? $comp['piscina'] : array_key_first($minimas);
        return ['ok' => false, 'pct' => null, 'min_seg' => $minimas[$piscina], 'piscina' => $piscina];
    }
    $min = $minimas[$fila['marca_piscina']];
    $pct = $min / (float)$fila['marca_seg'] * 100;
    return ['ok' => $pct >= 100, 'pct' => $pct, 'min_seg' => $min, 'piscina' => $fila['marca_piscina']];
}

function render_ce_minima_badge(?array $estado): string
{
    if (!$estado) return '';
    $title = 'Mínima ' . $estado['piscina'] . ': ' . segundos_a_tiempo($estado['min_seg']);
    if ($estado['pct'] === null) {
        return '<span class="ce-badge ce-badge--none" title="' . e($title) . '">Sin marca · mín. ' . e(segundos_a_tiempo($estado['min_seg'])) . '</span>';
    }
    $cls = $estado['ok'] ? 'ok' : ($estado['pct'] >= 95 ? 'cerca' : 'lejos');
    $txt = $estado['ok'] ? '✓ Mínima' : number_format($estado['pct'], 1, ',', '') . '%';
    return '<span class="ce-badge ce-badge--' . $cls . '" title="' . e($title) . '">' . $txt . '</span>';
}

function listar_minimas_competicion(PDO $pdo, int $competicion_id, bool $refrescar = false): array
{
    static $cache = [];
    if (!$refrescar && isset($cache[$competicion_id])) return $cache[$competicion_id];
    $stmt = $pdo->prepare('
        SELECT * FROM competicion_entrenador_minimas
        WHERE competicion_id = ?
        ORDER BY etiqueta, anio_desde, anio_hasta, piscina, FIELD(prueba, ' . implode(',', array_fill(0, count(CE_PRUEBAS), '?')) . '), sexo
    ');
    $stmt->execute(array_merge([$competicion_id], CE_PRUEBAS));
    return $cache[$competicion_id] = $stmt->fetchAll();
}

// Guarda un bloque de mínimas (misma etiqueta, rango de años y piscina) desde una rejilla prueba×sexo.
// $tiempos = [prueba => ['M' => '1:05.43', 'F' => '...']]. Sustituye las existentes del mismo bloque.
function guardar_bloque_minimas(PDO $pdo, int $competicion_id, ?string $etiqueta, ?int $anio_desde, ?int $anio_hasta, string $piscina, array $tiempos): int
{
    if (!in_array($piscina, CE_PISCINAS, true)) throw new InvalidArgumentException('Piscina no válida');
    if ($anio_desde !== null && $anio_hasta !== null && $anio_desde > $anio_hasta) {
        throw new InvalidArgumentException('El año "desde" es posterior al año "hasta"');
    }
    $etiqueta = $etiqueta !== null && trim($etiqueta) !== '' ? mb_substr(trim($etiqueta), 0, 60) : null;

    $filas = [];
    foreach (CE_PRUEBAS as $prueba) {
        foreach (['M', 'F'] as $sexo) {
            $txt = trim($tiempos[$prueba][$sexo] ?? '');
            if ($txt === '') continue;
            $seg = tiempo_a_segundos($txt);
            if ($seg <= 0) throw new InvalidArgumentException('Tiempo incorrecto en ' . format_prueba($prueba) . " ($sexo): $txt");
            $filas[] = [$prueba, $sexo, $seg];
        }
    }
    if (!$filas) throw new InvalidArgumentException('No has introducido ninguna mínima');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            DELETE FROM competicion_entrenador_minimas
            WHERE competicion_id = ? AND piscina = ? AND etiqueta <=> ? AND anio_desde <=> ? AND anio_hasta <=> ?
        ')->execute([$competicion_id, $piscina, $etiqueta, $anio_desde, $anio_hasta]);
        $ins = $pdo->prepare('
            INSERT INTO competicion_entrenador_minimas (competicion_id, etiqueta, prueba, piscina, sexo, anio_desde, anio_hasta, tiempo_seg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($filas as [$prueba, $sexo, $seg]) {
            $ins->execute([$competicion_id, $etiqueta, $prueba, $piscina, $sexo, $anio_desde, $anio_hasta, $seg]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    listar_minimas_competicion($pdo, $competicion_id, true);
    return count($filas);
}

function eliminar_bloque_minimas(PDO $pdo, int $competicion_id, ?string $etiqueta, ?int $anio_desde, ?int $anio_hasta, string $piscina): void
{
    $pdo->prepare('
        DELETE FROM competicion_entrenador_minimas
        WHERE competicion_id = ? AND piscina = ? AND etiqueta <=> ? AND anio_desde <=> ? AND anio_hasta <=> ?
    ')->execute([$competicion_id, $piscina, $etiqueta, $anio_desde, $anio_hasta]);
    listar_minimas_competicion($pdo, $competicion_id, true);
}

// Agrupa las mínimas por bloque para mostrarlas como rejilla.
function agrupar_minimas(array $minimas): array
{
    $bloques = [];
    foreach ($minimas as $m) {
        $k = json_encode([$m['etiqueta'], $m['anio_desde'], $m['anio_hasta'], $m['piscina']]);
        $bloques[$k] ??= [
            'etiqueta' => $m['etiqueta'], 'anio_desde' => $m['anio_desde'], 'anio_hasta' => $m['anio_hasta'],
            'piscina' => $m['piscina'], 'tiempos' => [],
        ];
        $bloques[$k]['tiempos'][$m['prueba']][$m['sexo']] = (float)$m['tiempo_seg'];
    }
    return array_values($bloques);
}

function ce_rango_anios(?int $desde, ?int $hasta): string
{
    if ($desde === null && $hasta === null) return 'Todos los años';
    if ($desde !== null && $hasta !== null) return $desde === $hasta ? "Nacidos en $desde" : "Nacidos $desde–$hasta";
    return $desde !== null ? "Nacidos en $desde o después" : "Nacidos en $hasta o antes";
}

// ─── Relevos ─────────────────────────────────────────────────────────────────

function format_relevo(string $codigo): string
{
    [$dist, $estilo] = CE_RELEVOS[$codigo] ?? [0, ''];
    if (!$dist) return $codigo;
    return relevo_num_postas($codigo) . '×' . $dist . ' ' . ($estilo === 'X' ? 'Estilos' : 'Libre');
}

// Número de postas del relevo (4 u 8), sacado del código
function relevo_num_postas(string $codigo): int
{
    return isset(CE_RELEVOS[$codigo]) ? (int)$codigo : 4;
}

// Prueba individual de cada posta (1..4). Estilos: espalda, braza, mariposa, libre.
function relevo_prueba_posta(string $codigo, int $posicion): ?string
{
    [$dist, $estilo] = CE_RELEVOS[$codigo] ?? [0, ''];
    if (!$dist) return null;
    if ($estilo === 'L') return $dist . 'L';
    return $dist . ['E', 'B', 'M', 'L'][$posicion - 1];
}

function relevo_nombre_posta(string $codigo, int $posicion): string
{
    [, $estilo] = CE_RELEVOS[$codigo] ?? [0, ''];
    if ($estilo === 'X') return ['Espalda', 'Braza', 'Mariposa', 'Libre'][$posicion - 1];
    return 'Posta ' . $posicion;
}

function listar_relevos(PDO $pdo, int $competicion_id): array
{
    $stmt = $pdo->prepare('
        SELECT * FROM competicion_entrenador_relevos
        WHERE competicion_id = ?
        ORDER BY FIELD(prueba, ' . implode(',', array_fill(0, count(CE_RELEVOS), '?')) . '), sexo, nombre, id
    ');
    $stmt->execute(array_merge([$competicion_id], array_keys(CE_RELEVOS)));
    $relevos = $stmt->fetchAll();
    if (!$relevos) return [];

    $ids = array_column($relevos, 'id');
    $stmt = $pdo->prepare('
        SELECT rn.relevo_id, rn.posicion, rn.parcial, u.id, u.nombre, u.sexo
        FROM competicion_entrenador_relevo_nadadores rn
        JOIN users u ON u.id = rn.user_id
        WHERE rn.relevo_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
    ');
    $stmt->execute($ids);
    $postas = [];
    foreach ($stmt->fetchAll() as $r) $postas[(int)$r['relevo_id']][(int)$r['posicion']] = $r;
    foreach ($relevos as &$r) $r['postas'] = $postas[(int)$r['id']] ?? [];
    return $relevos;
}

function obtener_relevo(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM competicion_entrenador_relevos WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) return null;
    $stmt = $pdo->prepare('SELECT posicion, user_id, parcial FROM competicion_entrenador_relevo_nadadores WHERE relevo_id = ?');
    $stmt->execute([$id]);
    $r['postas'] = []; $r['parciales'] = [];
    foreach ($stmt->fetchAll() as $p) {
        $r['postas'][(int)$p['posicion']] = (int)$p['user_id'];
        $r['parciales'][(int)$p['posicion']] = $p['parcial'];
    }
    return $r;
}

// Crea o actualiza un relevo. postas = [1 => user_id|0, ..., N => ...], parciales = [pos => tiempo de la posta].
// Los nadadores deben estar convocados.
function guardar_relevo(PDO $pdo, int $competicion_id, ?int $relevo_id, array $data): int
{
    $prueba = $data['prueba'] ?? '';
    if (!isset(CE_RELEVOS[$prueba])) throw new InvalidArgumentException('Relevo no válido');
    $sexo = $data['sexo'] ?? '';
    if (!isset(CE_RELEVO_SEXOS[$sexo])) throw new InvalidArgumentException('Categoría de relevo no válida');
    $nombre = trim($data['nombre'] ?? '');
    $nombre = $nombre !== '' ? mb_substr($nombre, 0, 60) : null;

    $tiempoTxt = trim($data['tiempo'] ?? '');
    $tiempoSeg = null;
    if ($tiempoTxt !== '') {
        $tiempoSeg = tiempo_a_segundos($tiempoTxt);
        if ($tiempoSeg <= 0) throw new InvalidArgumentException('Formato de tiempo incorrecto. Usa mm:ss.cc');
    }

    $convocados = [];
    foreach (listar_convocados($pdo, $competicion_id) as $n) $convocados[(int)$n['id']] = $n;
    $postas = []; $parciales = [];
    $cuenta = ['M' => 0, 'F' => 0];
    $numPostas = relevo_num_postas($prueba);
    for ($pos = 1; $pos <= $numPostas; $pos++) {
        $uid = (int)($data['postas'][$pos] ?? 0);
        if (!$uid) continue;
        if (!isset($convocados[$uid])) throw new InvalidArgumentException('Solo puedes poner nadadores convocados');
        if (in_array($uid, $postas, true)) throw new InvalidArgumentException('Un nadador no puede nadar dos postas');
        $sx = $convocados[$uid]['sexo'];
        if ($sexo !== 'X' && $sx !== $sexo) {
            throw new InvalidArgumentException($convocados[$uid]['nombre'] . ' no puede nadar un relevo ' . mb_strtolower(CE_RELEVO_SEXOS[$sexo]));
        }
        $cuenta[$sx] = ($cuenta[$sx] ?? 0) + 1;
        $postas[$pos] = $uid;
        $pTxt = trim($data['parciales'][$pos] ?? '');
        if ($pTxt !== '') {
            $pSeg = tiempo_a_segundos($pTxt);
            if ($pSeg <= 0) throw new InvalidArgumentException("Tiempo de la posta $pos incorrecto. Usa mm:ss.cc o ss.cc");
            $parciales[$pos] = segundos_a_tiempo($pSeg);
        }
    }
    $mitad = intdiv($numPostas, 2);
    if ($sexo === 'X' && ($cuenta['M'] > $mitad || $cuenta['F'] > $mitad)) {
        throw new InvalidArgumentException("Un relevo mixto lleva $mitad chicos y $mitad chicas");
    }

    $pdo->beginTransaction();
    try {
        if ($relevo_id) {
            $pdo->prepare('UPDATE competicion_entrenador_relevos SET prueba = ?, sexo = ?, nombre = ?, tiempo = ?, tiempo_seg = ? WHERE id = ? AND competicion_id = ?')
                ->execute([$prueba, $sexo, $nombre, $tiempoTxt !== '' ? $tiempoTxt : null, $tiempoSeg, $relevo_id, $competicion_id]);
            $pdo->prepare('DELETE FROM competicion_entrenador_relevo_nadadores WHERE relevo_id = ?')->execute([$relevo_id]);
        } else {
            $pdo->prepare('INSERT INTO competicion_entrenador_relevos (competicion_id, prueba, sexo, nombre, tiempo, tiempo_seg) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$competicion_id, $prueba, $sexo, $nombre, $tiempoTxt !== '' ? $tiempoTxt : null, $tiempoSeg]);
            $relevo_id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO competicion_entrenador_relevo_nadadores (relevo_id, posicion, user_id, parcial) VALUES (?, ?, ?, ?)');
        foreach ($postas as $pos => $uid) $ins->execute([$relevo_id, $pos, $uid, $parciales[$pos] ?? null]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $relevo_id;
}

function eliminar_relevo(PDO $pdo, int $competicion_id, int $relevo_id): void
{
    $pdo->prepare('DELETE FROM competicion_entrenador_relevos WHERE id = ? AND competicion_id = ?')
        ->execute([$relevo_id, $competicion_id]);
}

// Tiempo estimado del relevo sumando la mejor marca de cada posta en la piscina de la competición.
// Devuelve ['total' => float|null, 'postas' => [pos => seg|null]]. total = null si falta alguna.
function estimar_relevo(PDO $pdo, array $comp, array $relevo): array
{
    $out = ['total' => 0.0, 'postas' => []];
    for ($pos = 1, $n = relevo_num_postas($relevo['prueba']); $pos <= $n; $pos++) {
        $p = $relevo['postas'][$pos] ?? null;
        $seg = null;
        if ($p) {
            $prueba = relevo_prueba_posta($relevo['prueba'], $pos);
            $seg = ce_mejores_marcas($pdo, (int)$p['id'], $comp)[$prueba][$comp['piscina']]['seg'] ?? null;
        }
        $out['postas'][$pos] = $seg;
        if ($seg === null) $out['total'] = null;
        elseif ($out['total'] !== null) $out['total'] += $seg;
    }
    return $out;
}

// ─── Vista del nadador ───────────────────────────────────────────────────────

// Competiciones a las que está convocado el socio, con sus pruebas y relevos.
function listar_convocatorias_socio(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare('
        SELECT c.*
        FROM competicion_entrenador_convocados v
        JOIN competicion_entrenador c ON c.id = v.competicion_id
        WHERE v.user_id = ?
        ORDER BY c.fecha DESC, c.id DESC
    ');
    $stmt->execute([$user_id]);
    $comps = $stmt->fetchAll();
    if (!$comps) return [];

    $stmt = $pdo->prepare('SELECT * FROM competicion_entrenador_pruebas WHERE user_id = ? ORDER BY id');
    $stmt->execute([$user_id]);
    $pruebas = [];
    foreach ($stmt->fetchAll() as $p) $pruebas[(int)$p['competicion_id']][] = $p;

    $stmt = $pdo->prepare('
        SELECT r.*, rn.posicion, rn.parcial
        FROM competicion_entrenador_relevo_nadadores rn
        JOIN competicion_entrenador_relevos r ON r.id = rn.relevo_id
        WHERE rn.user_id = ?
    ');
    $stmt->execute([$user_id]);
    $relevos = [];
    foreach ($stmt->fetchAll() as $r) $relevos[(int)$r['competicion_id']][] = $r;

    foreach ($comps as &$c) {
        $lista = $pruebas[(int)$c['id']] ?? [];
        usort($lista, fn($a, $b) => array_search($a['prueba'], CE_PRUEBAS, true) <=> array_search($b['prueba'], CE_PRUEBAS, true));
        $c['pruebas'] = $lista;
        $c['relevos'] = $relevos[(int)$c['id']] ?? [];
    }
    return $comps;
}
