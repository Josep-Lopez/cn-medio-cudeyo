<?php
// Récords de relevos del club, introducidos a mano (tabla records_relevos).
// Requiere $pdo (config/db.php) y helpers de includes/auth.php.
// Reutiliza CE_RELEVOS, CE_RELEVO_SEXOS y format_relevo() de competicion_entrenador.php.

require_once __DIR__ . '/competicion_entrenador.php';

// Récord vigente por prueba × sexo en una piscina: [sexo][prueba] => fila
function records_relevos_vigentes(PDO $pdo, string $piscina): array
{
    $stmt = $pdo->prepare('
        SELECT * FROM (
            SELECT r.*, ROW_NUMBER() OVER (PARTITION BY prueba, sexo ORDER BY tiempo_seg ASC, fecha ASC, id ASC) AS rn
            FROM records_relevos r
            WHERE piscina = ?
        ) x WHERE rn = 1
    ');
    $stmt->execute([$piscina]);
    $out = ['M' => [], 'F' => [], 'X' => []];
    foreach ($stmt->fetchAll() as $r) $out[$r['sexo']][$r['prueba']] = $r;
    return $out;
}

// Todas las entradas de una piscina (para gestión), ordenadas por relevo, sexo y tiempo.
function records_relevos_listar(PDO $pdo, string $piscina): array
{
    $stmt = $pdo->prepare('
        SELECT * FROM records_relevos
        WHERE piscina = ?
        ORDER BY FIELD(prueba, ' . implode(',', array_fill(0, count(CE_RELEVOS), '?')) . '), FIELD(sexo, \'M\', \'F\', \'X\'), tiempo_seg, fecha
    ');
    $stmt->execute(array_merge([$piscina], array_keys(CE_RELEVOS)));
    return $stmt->fetchAll();
}

function records_relevos_obtener(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM records_relevos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function records_relevos_guardar(PDO $pdo, ?int $id, array $d, int $user_id): int
{
    $prueba = $d['prueba'] ?? '';
    if (!isset(CE_RELEVOS[$prueba])) throw new InvalidArgumentException('Relevo no válido');
    $piscina = $d['piscina'] ?? '';
    if (!in_array($piscina, ['25m', '50m'], true)) throw new InvalidArgumentException('Piscina no válida');
    $sexo = $d['sexo'] ?? '';
    if (!isset(CE_RELEVO_SEXOS[$sexo])) throw new InvalidArgumentException('Categoría no válida');
    $tiempo = trim($d['tiempo'] ?? '');
    $seg = tiempo_a_segundos($tiempo);
    if ($seg <= 0) throw new InvalidArgumentException('Formato de tiempo incorrecto. Usa mm:ss.cc');
    $fecha = $d['fecha'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new InvalidArgumentException('Fecha inválida');
    $lugar = mb_substr(trim($d['lugar'] ?? ''), 0, 255);
    $nadadores = []; $parciales = []; $userIds = [];
    $numPostas = relevo_num_postas($prueba);
    for ($i = 1; $i <= CE_RELEVO_MAX_POSTAS; $i++) {
        // Postas que no existen en este relevo (5-8 en los de 4) quedan vacías
        if ($i > $numPostas) { $nadadores[] = null; $parciales[] = null; $userIds[] = null; continue; }
        $n = trim($d['nadador' . $i] ?? '');
        if ($n === '') throw new InvalidArgumentException("Faltan nadadores: un relevo lleva $numPostas");
        $socio = records_relevos_socio_por_nombre($pdo, $n);
        $nadadores[] = $socio ? $socio['nombre'] : mb_substr($n, 0, 100);
        $userIds[] = $socio ? (int)$socio['id'] : null;
        $pTxt = trim($d['parcial' . $i] ?? '');
        if ($pTxt === '') {
            $parciales[] = null;
        } else {
            $pSeg = tiempo_a_segundos($pTxt);
            if ($pSeg <= 0) throw new InvalidArgumentException("Tiempo de la posta $i incorrecto. Usa mm:ss.cc o ss.cc");
            $parciales[] = segundos_a_tiempo($pSeg);
        }
    }

    $vals = [$prueba, $piscina, $sexo, segundos_a_tiempo($seg), $seg, $fecha, $lugar, ...$nadadores, ...$parciales, ...$userIds];
    if ($id) {
        $pdo->prepare('
            UPDATE records_relevos
            SET prueba = ?, piscina = ?, sexo = ?, tiempo = ?, tiempo_seg = ?, fecha = ?, lugar = ?,
                nadador1 = ?, nadador2 = ?, nadador3 = ?, nadador4 = ?, nadador5 = ?, nadador6 = ?, nadador7 = ?, nadador8 = ?,
                parcial1 = ?, parcial2 = ?, parcial3 = ?, parcial4 = ?, parcial5 = ?, parcial6 = ?, parcial7 = ?, parcial8 = ?,
                user1_id = ?, user2_id = ?, user3_id = ?, user4_id = ?, user5_id = ?, user6_id = ?, user7_id = ?, user8_id = ?
            WHERE id = ?
        ')->execute([...$vals, $id]);
        return $id;
    }
    $pdo->prepare('
        INSERT INTO records_relevos (prueba, piscina, sexo, tiempo, tiempo_seg, fecha, lugar,
            nadador1, nadador2, nadador3, nadador4, nadador5, nadador6, nadador7, nadador8,
            parcial1, parcial2, parcial3, parcial4, parcial5, parcial6, parcial7, parcial8,
            user1_id, user2_id, user3_id, user4_id, user5_id, user6_id, user7_id, user8_id, creado_por)
        VALUES (' . implode(', ', array_fill(0, 32, '?')) . ')
    ')->execute([...$vals, $user_id]);
    return (int)$pdo->lastInsertId();
}

// Socio cuyo nombre coincide exactamente (sin distinguir mayúsculas) => [id, nombre]; null si ninguno o varios.
function records_relevos_socio_por_nombre(PDO $pdo, string $nombre): ?array
{
    $stmt = $pdo->prepare('SELECT id, nombre FROM users WHERE nombre = ? LIMIT 2');
    $stmt->execute([trim($nombre)]);
    $rows = $stmt->fetchAll();
    return count($rows) === 1 ? $rows[0] : null;
}

// Récords de relevo en los que ha nadado el usuario, con su posta, su parcial y si sigue vigente.
function records_relevos_de_usuario(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare('
        SELECT r.*,
               (SELECT MIN(b.tiempo_seg) FROM records_relevos b
                WHERE b.prueba = r.prueba AND b.piscina = r.piscina AND b.sexo = r.sexo) AS best_seg
        FROM records_relevos r
        WHERE ? IN (r.user1_id, r.user2_id, r.user3_id, r.user4_id, r.user5_id, r.user6_id, r.user7_id, r.user8_id)
        ORDER BY r.piscina, FIELD(r.prueba, ' . implode(',', array_fill(0, count(CE_RELEVOS), '?')) . '), r.tiempo_seg
    ');
    $stmt->execute(array_merge([$user_id], array_keys(CE_RELEVOS)));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        for ($i = 1; $i <= CE_RELEVO_MAX_POSTAS; $i++) {
            if ((int)$r['user' . $i . '_id'] === $user_id) { $r['posicion'] = $i; break; }
        }
        $r['vigente'] = (float)$r['tiempo_seg'] <= (float)$r['best_seg'];
        $out[] = $r;
    }
    return $out;
}

// Tabla de récords de relevo de un nadador (perfil). Vacío => no imprime nada.
function render_records_relevos_usuario(array $rows): void
{
    if (!$rows) return;
?>
<div class="table-wrapper">
  <table class="ce-pruebas">
    <thead><tr><th>Relevo</th><th>Piscina</th><th>Tiempo</th><th>Posta</th><th>Equipo</th><th>Fecha</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): $pos = $r['posicion']; ?>
        <tr>
          <td style="white-space:nowrap;"><strong><?= e(format_relevo($r['prueba'])) ?></strong>
            <span class="text-muted text-sm"><?= e(CE_RELEVO_SEXOS[$r['sexo']]) ?></span></td>
          <td><?= e($r['piscina']) ?></td>
          <td><span class="mark-time"><?= e($r['tiempo']) ?></span></td>
          <td style="white-space:nowrap;"><?= e(relevo_nombre_posta($r['prueba'], $pos)) ?>
            <?php if ($r['parcial' . $pos]): ?> · <strong><?= e($r['parcial' . $pos]) ?></strong><?php endif; ?></td>
          <td class="text-sm"><?= records_relevos_nadadores($r, ' · ') ?></td>
          <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
          <td><?= $r['vigente']
                ? '<span class="ce-badge ce-badge--ok">Vigente</span>'
                : '<span class="ce-badge ce-badge--cerca">Histórico</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
}

function records_relevos_eliminar(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM records_relevos WHERE id = ?')->execute([$id]);
}

function records_relevos_temporada(string $fecha): string
{
    $y = (int)substr($fecha, 0, 4);
    $m = (int)substr($fecha, 5, 2);
    $ini = $m >= 9 ? $y : $y - 1;
    return $ini . '-' . substr((string)($ini + 1), 2);
}

// Nombres de las postas, con el parcial de cada una si se conoce.
function records_relevos_nadadores(array $r, string $sep = '<br>'): string
{
    $out = [];
    for ($i = 1, $n = relevo_num_postas($r['prueba']); $i <= $n; $i++) {
        $txt = e(nombre_corto($r['nadador' . $i]));
        if (!empty($r['parcial' . $i])) $txt .= ' <span class="text-muted">' . e($r['parcial' . $i]) . '</span>';
        $out[] = $txt;
    }
    return implode($sep, $out);
}

// Celdas de un lado (M en orden normal, F en espejo), como en la tabla individual.
function records_relevos_celdas(?array $r, bool $reverse, string $temporada_actual): string
{
    if (!$r) return str_repeat('<td class="text-sm" style="text-align:center;color:#ccc;">—</td>', 4);
    $bg = records_relevos_temporada($r['fecha']) === $temporada_actual ? 'background:#dcfce7;' : '';
    $cells = [
        '<td class="text-sm text-muted" style="' . $bg . '">' . date('d/m/Y', strtotime($r['fecha'])) . '</td>',
        '<td class="text-sm text-muted" style="' . $bg . '">' . e($r['lugar']) . '</td>',
        '<td class="text-sm" style="' . $bg . 'white-space:nowrap;">' . records_relevos_nadadores($r) . '</td>',
        '<td style="' . $bg . '"><span class="mark-time">' . e($r['tiempo']) . '</span></td>',
    ];
    if ($reverse) $cells = array_reverse($cells);
    return implode('', $cells);
}

function render_tabla_records_relevos(array $vigentes, string $piscina, string $temporada_actual): void
{
    $hayMF = $vigentes['M'] || $vigentes['F'];
?>
<h2 style="font-size:20px;margin:32px 0 0;">Relevos</h2>
<p class="text-muted text-sm" style="margin:4px 0 0;">Récords de relevo del club · Piscina <?= e($piscina) ?>.</p>
<div class="table-card" style="margin-top:12px;">
  <div class="table-wrapper">
    <table class="records-matriz">
      <thead>
        <tr>
          <th colspan="4" style="text-align:center;">Masculino</th>
          <th rowspan="2" style="text-align:center;vertical-align:middle;background:#eef2ff;">Relevo</th>
          <th colspan="4" style="text-align:center;">Femenino</th>
        </tr>
        <tr>
          <th>Fecha</th><th>Lugar</th><th>Nadadores</th><th>Tiempo</th>
          <th>Tiempo</th><th>Nadadores</th><th>Lugar</th><th>Fecha</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$hayMF): ?>
          <tr><td colspan="9" class="text-center text-muted" style="padding:24px;">Sin récords de relevo masculinos ni femeninos.</td></tr>
        <?php else: foreach (array_keys(CE_RELEVOS) as $p):
            if (!isset($vigentes['M'][$p]) && !isset($vigentes['F'][$p])) continue; ?>
          <tr>
            <?= records_relevos_celdas($vigentes['M'][$p] ?? null, false, $temporada_actual) ?>
            <td style="text-align:center;background:#eef2ff;white-space:nowrap;"><strong><?= e(format_relevo($p)) ?></strong></td>
            <?= records_relevos_celdas($vigentes['F'][$p] ?? null, true, $temporada_actual) ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($vigentes['X']): ?>
<div class="table-card" style="margin-top:16px;">
  <div class="table-wrapper">
    <table class="records-matriz">
      <thead>
        <tr><th colspan="5" style="text-align:center;">Mixto</th></tr>
        <tr><th style="background:#eef2ff;">Relevo</th><th>Tiempo</th><th>Nadadores</th><th>Lugar</th><th>Fecha</th></tr>
      </thead>
      <tbody>
        <?php foreach (array_keys(CE_RELEVOS) as $p): $r = $vigentes['X'][$p] ?? null; if (!$r) continue;
            $bg = records_relevos_temporada($r['fecha']) === $temporada_actual ? 'background:#dcfce7;' : ''; ?>
          <tr>
            <td style="background:#eef2ff;white-space:nowrap;"><strong><?= e(format_relevo($p)) ?></strong></td>
            <td style="<?= $bg ?>"><span class="mark-time"><?= e($r['tiempo']) ?></span></td>
            <td class="text-sm" style="<?= $bg ?>white-space:nowrap;"><?= records_relevos_nadadores($r) ?></td>
            <td class="text-sm text-muted" style="<?= $bg ?>"><?= e($r['lugar']) ?></td>
            <td class="text-sm text-muted" style="<?= $bg ?>"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php
}
