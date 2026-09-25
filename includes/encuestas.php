<?php
// Helpers de dominio para encuestas (comunicaciones tipo 'encuesta').
// Requiere $pdo (config/db.php).
//
// Tipos de pregunta ('opciones' y 'texto_libre' se mantienen por compatibilidad):
//   opciones    → opción única (radio)       casillas    → varias respuestas (checkbox)
//   desplegable → opción única (select)      escala      → escala lineal min..max
//   texto_corto → respuesta corta (input)    texto_libre → párrafo (textarea)

const ENC_TIPOS = [
    'opciones'    => 'Opción única',
    'casillas'    => 'Casillas',
    'desplegable' => 'Desplegable',
    'escala'      => 'Escala lineal',
    'texto_corto' => 'Respuesta corta',
    'texto_libre' => 'Párrafo',
];
const ENC_TIPOS_CON_OPCIONES = ['opciones', 'casillas', 'desplegable'];
const ENC_TIPOS_CON_OTRO     = ['opciones', 'casillas'];
const ENC_MAX_PREGUNTAS      = 100;
const ENC_MAX_OPCIONES       = 200;
const ENC_MAX_TEXTO_CORTO    = 500;
const ENC_MAX_PARRAFO        = 10000;

// ── Definición de la encuesta ────────────────────────────────────────────────

// Valida y normaliza las preguntas enviadas por el editor (JSON decodificado).
// Devuelve la lista limpia; los problemas se añaden a $errors.
function enc_normalizar_preguntas(mixed $raw, array &$errors): array
{
    if (!is_array($raw) || !$raw) {
        $errors[] = 'Una encuesta necesita al menos una pregunta.';
        return [];
    }
    if (count($raw) > ENC_MAX_PREGUNTAS) {
        $errors[] = 'Máximo ' . ENC_MAX_PREGUNTAS . ' preguntas por encuesta.';
        return [];
    }

    $out = [];
    foreach (array_values($raw) as $i => $pq) {
        $n = $i + 1;
        if (!is_array($pq)) { $errors[] = "La pregunta $n no es válida."; continue; }

        $texto = trim((string)($pq['texto'] ?? ''));
        $desc  = trim((string)($pq['descripcion'] ?? ''));
        $tipo  = (string)($pq['tipo'] ?? 'opciones');

        if ($texto === '') $errors[] = "La pregunta $n no tiene texto.";
        elseif (strlen($texto) > 65000) $errors[] = "La pregunta $n es demasiado larga.";
        if (strlen($desc) > 65000) $errors[] = "La descripción de la pregunta $n es demasiado larga.";
        if (!isset(ENC_TIPOS[$tipo])) { $errors[] = "La pregunta $n tiene un tipo inválido."; continue; }

        $item = [
            'texto'            => $texto,
            'descripcion'      => $desc !== '' ? $desc : null,
            'tipo'             => $tipo,
            'obligatoria'      => !empty($pq['obligatoria']) ? 1 : 0,
            'escala_min'       => 1,
            'escala_max'       => 5,
            'escala_label_min' => null,
            'escala_label_max' => null,
            'opciones'         => [],
        ];

        if (in_array($tipo, ENC_TIPOS_CON_OPCIONES, true)) {
            $tiene_otro = false;
            foreach ((array)($pq['opciones'] ?? []) as $op) {
                $es_libre = !empty($op['es_libre']) && in_array($tipo, ENC_TIPOS_CON_OTRO, true);
                if ($es_libre) {
                    if ($tiene_otro) continue; // solo un "Otro" por pregunta
                    $tiene_otro = true;
                    $item['opciones'][] = ['texto' => 'Otro', 'es_libre' => 1];
                    continue;
                }
                $txt = trim((string)($op['texto'] ?? ''));
                if ($txt === '') continue;
                if (mb_strlen($txt) > 1000) { $errors[] = "Una opción de la pregunta $n supera 1000 caracteres."; continue; }
                $item['opciones'][] = ['texto' => $txt, 'es_libre' => 0];
            }
            // "Otro" siempre al final
            usort($item['opciones'], fn($a, $b) => $a['es_libre'] <=> $b['es_libre']);
            if (count($item['opciones']) < 2) $errors[] = "La pregunta $n necesita al menos 2 opciones.";
            if (count($item['opciones']) > ENC_MAX_OPCIONES) $errors[] = "La pregunta $n tiene demasiadas opciones (máx. " . ENC_MAX_OPCIONES . ').';
        }

        if ($tipo === 'escala') {
            $min = (int)($pq['escala_min'] ?? 1);
            $max = (int)($pq['escala_max'] ?? 5);
            if (!in_array($min, [0, 1], true)) $min = 1;
            if ($max < 2 || $max > 10) $max = 5;
            $item['escala_min'] = $min;
            $item['escala_max'] = $max;
            foreach (['escala_label_min', 'escala_label_max'] as $k) {
                $lbl = trim((string)($pq[$k] ?? ''));
                if (mb_strlen($lbl) > 100) { $errors[] = "Las etiquetas de la escala de la pregunta $n no pueden superar 100 caracteres."; $lbl = ''; }
                $item[$k] = $lbl !== '' ? $lbl : null;
            }
        }

        $out[] = $item;
    }
    return $out;
}

// Sustituye todas las preguntas de la encuesta. Llamar dentro de una transacción
// y solo si nadie ha respondido (borra respuestas en cascada).
function enc_guardar_preguntas(PDO $pdo, int $com_id, array $preguntas): void
{
    $pdo->prepare('DELETE FROM encuestas_preguntas WHERE comunicacion_id = ?')->execute([$com_id]);

    $stmtPq = $pdo->prepare('
        INSERT INTO encuestas_preguntas
            (comunicacion_id, texto, descripcion, tipo, obligatoria, escala_min, escala_max, escala_label_min, escala_label_max, orden)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ');
    $stmtOp = $pdo->prepare('INSERT INTO encuestas_opciones (pregunta_id, texto, es_libre, orden) VALUES (?,?,?,?)');

    foreach ($preguntas as $orden => $pq) {
        $stmtPq->execute([
            $com_id, $pq['texto'], $pq['descripcion'], $pq['tipo'], $pq['obligatoria'],
            $pq['escala_min'], $pq['escala_max'], $pq['escala_label_min'], $pq['escala_label_max'], $orden,
        ]);
        $pq_id = (int)$pdo->lastInsertId();
        foreach ($pq['opciones'] as $oi => $op) {
            $stmtOp->execute([$pq_id, $op['texto'], $op['es_libre'], $oi]);
        }
    }
}

// Preguntas con sus opciones, en orden.
function enc_cargar_preguntas(PDO $pdo, int $com_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM encuestas_preguntas WHERE comunicacion_id = ? ORDER BY orden, id');
    $stmt->execute([$com_id]);
    $preguntas = $stmt->fetchAll();
    if (!$preguntas) return [];

    $ids = array_column($preguntas, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM encuestas_opciones WHERE pregunta_id IN ($in) ORDER BY orden, id");
    $stmt->execute($ids);
    $por_pregunta = [];
    foreach ($stmt->fetchAll() as $op) $por_pregunta[$op['pregunta_id']][] = $op;

    foreach ($preguntas as &$pq) $pq['opciones'] = $por_pregunta[$pq['id']] ?? [];
    unset($pq);
    return $preguntas;
}

// Formato que entiende el editor JS (para editar o repoblar tras un error).
function enc_preguntas_para_editor(array $preguntas): array
{
    return array_map(fn($pq) => [
        'texto'            => $pq['texto'],
        'descripcion'      => $pq['descripcion'] ?? '',
        'tipo'             => $pq['tipo'],
        'obligatoria'      => (int)$pq['obligatoria'],
        'escala_min'       => (int)$pq['escala_min'],
        'escala_max'       => (int)$pq['escala_max'],
        'escala_label_min' => $pq['escala_label_min'] ?? '',
        'escala_label_max' => $pq['escala_label_max'] ?? '',
        'opciones'         => array_map(fn($op) => ['texto' => $op['texto'], 'es_libre' => (int)$op['es_libre']], $pq['opciones'] ?? []),
    ], $preguntas);
}

// ── Estado ───────────────────────────────────────────────────────────────────

function enc_esta_abierta(array $com): bool
{
    if (!empty($com['cerrada'])) return false;
    return empty($com['fecha_cierre']) || strtotime($com['fecha_cierre']) > time();
}

function enc_num_envios(PDO $pdo, int $com_id): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM encuestas_envios WHERE comunicacion_id = ?');
    $stmt->execute([$com_id]);
    return (int)$stmt->fetchColumn();
}

function enc_ha_respondido(PDO $pdo, int $com_id, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM encuestas_envios WHERE comunicacion_id = ? AND user_id = ?');
    $stmt->execute([$com_id, $user_id]);
    return (bool)$stmt->fetchColumn();
}

// ── Respuestas ───────────────────────────────────────────────────────────────

// Valida lo enviado por el socio contra la definición. Devuelve filas
// [pregunta_id, opcion_id, valor, texto] listas para insertar.
function enc_validar_respuestas(array $preguntas, array $raw, array &$errors): array
{
    $filas = [];
    foreach ($preguntas as $i => $pq) {
        $n    = $i + 1;
        $pid  = (int)$pq['id'];
        $r    = is_array($raw[$pid] ?? null) ? $raw[$pid] : [];
        $ops  = array_column($pq['opciones'], null, 'id');
        $txt  = trim((string)($r['texto'] ?? ''));
        $antes = count($filas);
        $err_antes = count($errors);

        switch ($pq['tipo']) {
            case 'opciones':
            case 'desplegable':
                $oid = (int)($r['opcion_id'] ?? 0);
                if ($oid && isset($ops[$oid])) {
                    $libre = (int)$ops[$oid]['es_libre'] === 1;
                    if ($libre && $txt === '') { $errors[] = "Pregunta $n: especifica tu respuesta en «Otro»."; break; }
                    $filas[] = [$pid, $oid, null, $libre ? mb_substr($txt, 0, ENC_MAX_TEXTO_CORTO) : null];
                }
                break;

            case 'casillas':
                $sel = array_unique(array_map('intval', (array)($r['opciones'] ?? [])));
                foreach ($sel as $oid) {
                    if (!isset($ops[$oid])) continue;
                    $libre = (int)$ops[$oid]['es_libre'] === 1;
                    if ($libre && $txt === '') { $errors[] = "Pregunta $n: especifica tu respuesta en «Otro»."; continue; }
                    $filas[] = [$pid, $oid, null, $libre ? mb_substr($txt, 0, ENC_MAX_TEXTO_CORTO) : null];
                }
                break;

            case 'escala':
                if (isset($r['valor']) && $r['valor'] !== '') {
                    $v = (int)$r['valor'];
                    if ($v >= (int)$pq['escala_min'] && $v <= (int)$pq['escala_max']) $filas[] = [$pid, null, $v, null];
                }
                break;

            case 'texto_corto':
            case 'texto_libre':
                $max = $pq['tipo'] === 'texto_corto' ? ENC_MAX_TEXTO_CORTO : ENC_MAX_PARRAFO;
                if (mb_strlen($txt) > $max) { $errors[] = "Pregunta $n: la respuesta supera $max caracteres."; break; }
                if ($txt !== '') $filas[] = [$pid, null, null, $txt];
                break;
        }

        if ((int)$pq['obligatoria'] === 1 && count($filas) === $antes && count($errors) === $err_antes) {
            $errors[] = "La pregunta $n es obligatoria.";
        }
    }
    return $filas;
}

// Guarda el envío completo. Devuelve false si el socio ya había respondido.
function enc_guardar_respuestas(PDO $pdo, int $com_id, int $user_id, array $filas): bool
{
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT IGNORE INTO encuestas_envios (comunicacion_id, user_id) VALUES (?, ?)');
        $ins->execute([$com_id, $user_id]);
        if ($ins->rowCount() === 0) { $pdo->rollBack(); return false; }

        $stmt = $pdo->prepare('INSERT INTO encuestas_respuestas (pregunta_id, opcion_id, valor, user_id, respuesta_texto) VALUES (?,?,?,?,?)');
        foreach ($filas as [$pid, $oid, $valor, $texto]) {
            $stmt->execute([$pid, $oid, $valor, $user_id, $texto]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Respuestas de un socio indexadas por pregunta: ['opciones'=>[ids], 'valor'=>int|null, 'texto'=>string|null]
function enc_respuestas_usuario(PDO $pdo, int $com_id, int $user_id): array
{
    $stmt = $pdo->prepare('
        SELECT r.* FROM encuestas_respuestas r
        JOIN encuestas_preguntas p ON p.id = r.pregunta_id
        WHERE p.comunicacion_id = ? AND r.user_id = ?
    ');
    $stmt->execute([$com_id, $user_id]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $o = &$out[$r['pregunta_id']];
        $o ??= ['opciones' => [], 'valor' => null, 'texto' => null];
        if ($r['opcion_id']) $o['opciones'][] = (int)$r['opcion_id'];
        if ($r['valor'] !== null) $o['valor'] = (int)$r['valor'];
        if ($r['respuesta_texto'] !== null) $o['texto'] = $r['respuesta_texto'];
        unset($o);
    }
    return $out;
}

// ── Resultados ───────────────────────────────────────────────────────────────

// Añade a cada pregunta: 'respondieron' (socios distintos), 'votos' por opción,
// 'distribucion' + 'media' para escala y 'textos' (nombre, texto, fecha).
function enc_resultados(PDO $pdo, int $com_id): array
{
    $preguntas = enc_cargar_preguntas($pdo, $com_id);
    if (!$preguntas) return [];

    $stmt = $pdo->prepare('
        SELECT r.*, u.nombre
        FROM encuestas_respuestas r
        JOIN encuestas_preguntas p ON p.id = r.pregunta_id
        JOIN users u ON u.id = r.user_id
        WHERE p.comunicacion_id = ?
        ORDER BY r.created_at DESC, r.id DESC
    ');
    $stmt->execute([$com_id]);
    $por_pregunta = [];
    foreach ($stmt->fetchAll() as $r) $por_pregunta[$r['pregunta_id']][] = $r;

    foreach ($preguntas as &$pq) {
        $rows = $por_pregunta[$pq['id']] ?? [];
        $pq['respondieron'] = count(array_unique(array_column($rows, 'user_id')));

        $votos = [];
        foreach ($rows as $r) if ($r['opcion_id']) $votos[$r['opcion_id']] = ($votos[$r['opcion_id']] ?? 0) + 1;
        foreach ($pq['opciones'] as &$op) $op['votos'] = $votos[$op['id']] ?? 0;
        unset($op);

        if ($pq['tipo'] === 'escala') {
            $dist = array_fill_keys(range((int)$pq['escala_min'], (int)$pq['escala_max']), 0);
            $vals = [];
            foreach ($rows as $r) {
                if ($r['valor'] === null) continue;
                $v = (int)$r['valor'];
                if (isset($dist[$v])) $dist[$v]++;
                $vals[] = $v;
            }
            $pq['distribucion'] = $dist;
            $pq['media'] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
        }

        // Detalle por socio (casillas: varias filas del mismo socio → una sola)
        $ops_pq = array_column($pq['opciones'], null, 'id');
        $detalle = [];
        foreach ($rows as $r) {
            if ($r['opcion_id'] && isset($ops_pq[$r['opcion_id']])) {
                $op = $ops_pq[$r['opcion_id']];
                $parte = $op['es_libre'] ? 'Otro: ' . ($r['respuesta_texto'] ?? '') : $op['texto'];
            } elseif ($r['valor'] !== null) {
                $parte = (string)$r['valor'];
            } else {
                $parte = (string)$r['respuesta_texto'];
            }
            $d = &$detalle[$r['user_id']];
            $d ??= ['nombre' => $r['nombre'], 'fecha' => $r['created_at'], 'partes' => []];
            $d['partes'][] = $parte;
            unset($d);
        }
        $pq['detalle'] = array_values(array_map(fn($d) => [
            'nombre' => $d['nombre'], 'fecha' => $d['fecha'], 'respuesta' => implode(', ', $d['partes']),
        ], $detalle));

        $pq['textos'] = [];
        foreach ($rows as $r) {
            if ($r['respuesta_texto'] !== null && $r['respuesta_texto'] !== '') {
                $pq['textos'][] = ['nombre' => $r['nombre'], 'texto' => $r['respuesta_texto'], 'fecha' => $r['created_at']];
            }
        }
    }
    unset($pq);
    return $preguntas;
}

// Tabla plana socio × pregunta para exportar. Devuelve [cabeceras, filas].
function enc_tabla_export(PDO $pdo, int $com_id): array
{
    $preguntas = enc_cargar_preguntas($pdo, $com_id);
    $ops = [];
    foreach ($preguntas as $pq) foreach ($pq['opciones'] as $op) $ops[$op['id']] = $op;

    $stmt = $pdo->prepare('
        SELECT e.user_id, e.created_at, u.nombre, u.email
        FROM encuestas_envios e JOIN users u ON u.id = e.user_id
        WHERE e.comunicacion_id = ?
        ORDER BY e.created_at
    ');
    $stmt->execute([$com_id]);
    $envios = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT r.* FROM encuestas_respuestas r
        JOIN encuestas_preguntas p ON p.id = r.pregunta_id
        WHERE p.comunicacion_id = ?
        ORDER BY r.id
    ');
    $stmt->execute([$com_id]);
    $celdas = []; // [user_id][pregunta_id] => [partes]
    foreach ($stmt->fetchAll() as $r) {
        if ($r['opcion_id'] && isset($ops[$r['opcion_id']])) {
            $op = $ops[$r['opcion_id']];
            $parte = $op['es_libre'] ? 'Otro: ' . ($r['respuesta_texto'] ?? '') : $op['texto'];
        } elseif ($r['valor'] !== null) {
            $parte = (string)$r['valor'];
        } else {
            $parte = (string)$r['respuesta_texto'];
        }
        $celdas[$r['user_id']][$r['pregunta_id']][] = $parte;
    }

    $cab = ['Fecha', 'Socio', 'Email'];
    foreach ($preguntas as $pq) $cab[] = $pq['texto'];

    $filas = [];
    foreach ($envios as $e) {
        $fila = [date('d/m/Y H:i', strtotime($e['created_at'])), $e['nombre'], $e['email']];
        foreach ($preguntas as $pq) $fila[] = implode(' | ', $celdas[$e['user_id']][$pq['id']] ?? []);
        $filas[] = $fila;
    }
    return [$cab, $filas];
}
