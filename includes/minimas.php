<?php
// Helpers mínimas RFEN: % de aproximación de una marca a la mínima de su categoría.
// Datos en config: minimas_rfen (prueba→piscina→sexo→cat[→edad]) y minimas_edats (rangos por cat).

const MIN_CAT_LABELS = ['alevin' => 'Alevín', 'infantil' => 'Infantil', 'junior' => 'Junior', 'sub20' => 'Sub-20', 'absoluto' => 'Absoluto'];

function minimas_cargar(PDO $pdo): array
{
    $rows = $pdo->query("SELECT clave, valor FROM config WHERE clave IN ('minimas_rfen','minimas_edats')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
    $edades = array_merge(
        ['alevin' => ['min' => 12, 'max' => 13], 'infantil' => ['min' => 14, 'max' => 15], 'junior' => ['min' => 16, 'max' => 18]],
        json_decode($rows['minimas_edats'] ?? '{}', true) ?? []
    );
    return [
        'minimas' => json_decode($rows['minimas_rfen'] ?? '{}', true) ?? [],
        'edades'  => $edades,
    ];
}

// Devuelve ['seg' => float, 'cat' => 'alevin', 'edad' => 12] o null si no hay mínima aplicable.
function minima_para(array $datos, string $prueba, string $piscina, ?string $sexo, ?int $edad): ?array
{
    if (!$sexo || $edad === null) return null;
    $porCat = $datos['minimas'][$prueba][$piscina][$sexo] ?? null;
    if (!is_array($porCat)) return null;

    $maxEdadCat = 0;
    foreach ($datos['edades'] as $cat => $rango) {
        $maxEdadCat = max($maxEdadCat, (int)$rango['max']);
        if ($edad >= (int)$rango['min'] && $edad <= (int)$rango['max']) {
            $seg = $porCat[$cat][(string)$edad] ?? null;
            return $seg ? ['seg' => (float)$seg, 'cat' => $cat, 'edad' => $edad] : null;
        }
    }
    // Por debajo de la primera categoría con mínimas (benjamín): sin mínima
    if ($edad <= $maxEdadCat) return null;

    if ($edad <= 20 && !empty($porCat['sub20']) && !is_array($porCat['sub20'])) {
        return ['seg' => (float)$porCat['sub20'], 'cat' => 'sub20', 'edad' => $edad];
    }
    if (!empty($porCat['absoluto']) && !is_array($porCat['absoluto'])) {
        return ['seg' => (float)$porCat['absoluto'], 'cat' => 'absoluto', 'edad' => $edad];
    }
    return null;
}

// Badge HTML con el % (mínima / marca × 100). >=100% = mínima conseguida.
function render_minima_badge(array $datos, array $marca, ?string $sexo, ?string $fecha_nacimiento): string
{
    $tiempo = (float)($marca['tiempo_seg'] ?? 0);
    if ($tiempo <= 0) return '';
    $edad = edad_deportiva($marca['fecha_marca'] ?? null, $fecha_nacimiento);
    $min  = minima_para($datos, $marca['prueba'], $marca['piscina'], $sexo, $edad);
    if (!$min) return '';

    $pct = $min['seg'] / $tiempo * 100;
    if ($pct >= 100)     { $bg = '#dcfce7'; $fg = '#15803d'; }
    elseif ($pct >= 95)  { $bg = '#fef9c3'; $fg = '#a16207'; }
    else                 { $bg = '#f3f4f6'; $fg = '#6b7280'; }

    $label = MIN_CAT_LABELS[$min['cat']] ?? $min['cat'];
    if (in_array($min['cat'], ['alevin', 'infantil', 'junior'], true)) $label .= " {$min['edad']} años";
    $title = 'Mínima ' . $label . ': ' . segundos_a_tiempo($min['seg'])
           . ($pct >= 100 ? ' — conseguida' : ' — faltan ' . number_format($tiempo - $min['seg'], 2, ',', '') . ' s');

    return sprintf(
        '<span class="minima-badge" title="%s" style="display:inline-block;margin-left:6px;font-size:11px;font-weight:700;border-radius:4px;padding:1px 6px;background:%s;color:%s;white-space:nowrap;">%s%s%%</span>',
        e($title), $bg, $fg, $pct >= 100 ? '✓ ' : '', number_format($pct, 1, ',', '')
    );
}
