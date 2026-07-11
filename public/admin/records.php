<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();
$admin_user = current_user();

$PRUEBAS = ['50L', '100L', '200L', '400L', '800L', '1500L', '50E', '100E', '200E', '50B', '100B', '200B', '50M', '100M', '200M', '100X', '200X', '400X'];

// --- Filtros (por defecto: Nadador=Todos) ---
$filterPrueba    = $_GET['prueba']    ?? '';
$filterPiscina   = $_GET['piscina']   ?? '25m';
$filterNadador   = $_GET['nadador']   ?? '';

// Validación
if (!in_array($filterPrueba, $PRUEBAS, true))         $filterPrueba = '';
if (!in_array($filterPiscina, ['25m', '50m'], true))  $filterPiscina = '25m';
if (!in_array($filterNadador, ['1', '0', ''], true))  $filterNadador = '';

// Temporada actual (mm>=9 => YYYY-YY+1)
$cy_now = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporada_actual = $cy_now . '-' . substr((string)($cy_now + 1), 2);

// --- Récord = mejor marca vigente por prueba × piscina × sexo (el último récord) ---
// El récord real se calcula sobre TODAS las temporadas. El filtro Nadador se
// aplica al titular del récord (post-filtro): no recalcula el récord.
$inner_where  = "WHERE u.estado = 'activo' AND m.piscina = ? AND m.es_parcial = 0";
$inner_params = [$filterPiscina];
if ($filterPrueba !== '') { $inner_where .= ' AND m.prueba = ?'; $inner_params[] = $filterPrueba; }

$params = $inner_params;
$outer_where = '';
if ($filterNadador !== '') { $outer_where = ' AND nadador_activo = ?'; $params[] = (int)$filterNadador; }

$sql = "
    WITH ranked AS (
        SELECT
            m.id, m.prueba, m.piscina, m.tiempo, m.tiempo_seg, m.fecha_marca,
            m.lugar, m.temporada,
            u.id AS uid, u.nombre, u.sexo, u.liga, u.fecha_nacimiento, u.nadador_activo,
            ROW_NUMBER() OVER (
                PARTITION BY m.prueba, u.sexo
                ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC, m.id ASC
            ) AS rn
        FROM marcas m
        JOIN users u ON u.id = m.user_id
        $inner_where
    )
    SELECT * FROM ranked WHERE rn = 1$outer_where
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$records = ['M' => [], 'F' => []]; // [sexo][prueba] = row
foreach ($stmt->fetchAll() as $row) {
    $records[$row['sexo']][$row['prueba']] = $row;
}

/**
 * Renderiza una tabla de récords para un sexo concreto.
 */
function celdas_lado(?array $r, string $p, string $piscina, string $sexo, bool $reverse, string $temporada_actual): string
{
    if (!$r) {
        return str_repeat('<td class="text-sm" style="text-align:center;color:#ccc;">—</td>', 6);
    }
    $wa   = calcular_aqua((float)$r['tiempo_seg'], $p, $piscina, $sexo);
    $anio = $r['fecha_nacimiento'] ? substr($r['fecha_nacimiento'], 0, 4) : '';
    $bg   = (($r['temporada'] ?? '') === $temporada_actual) ? 'background:#dcfce7;' : '';
    $cells = [
        'fecha'  => '<td class="text-sm text-muted" style="' . $bg . '">' . date('d/m/Y', strtotime($r['fecha_marca'])) . '</td>',
        'lugar'  => '<td class="text-sm text-muted" style="' . $bg . '">' . e($r['lugar'] ?? '') . '</td>',
        'anio'   => '<td style="' . $bg . '">' . e($anio) . '</td>',
        'nombre' => '<td style="' . $bg . '">' . e(nombre_corto($r['nombre'])) . '</td>',
        'wa'     => '<td class="text-sm" style="' . $bg . '">' . ($wa !== null ? (int)$wa : '—') . '</td>',
        'tiempo' => '<td style="' . $bg . '"><span class="mark-time">' . e($r['tiempo']) . '</span></td>',
    ];
    $order = ['fecha', 'lugar', 'anio', 'nombre', 'wa', 'tiempo'];
    if ($reverse) $order = array_reverse($order);
    $out = '';
    foreach ($order as $k) $out .= $cells[$k];
    return $out;
}

/**
 * Tabla única de récords con columna Prueba central compartida (Masculino | Prueba | Femenino).
 * El lado femenino se renderiza en espejo, como en el Excel del club.
 */
function tabla_records_unica(array $records, array $PRUEBAS, string $piscina, string $temporada_actual): void
{
    $any = false;
    foreach ($PRUEBAS as $p) if (isset($records['M'][$p]) || isset($records['F'][$p])) { $any = true; break; }
    ?>
    <style>
      .records-matriz th, .records-matriz td { padding:6px 8px; font-size:12px; }
      .records-matriz .mark-time { font-size:12px; }
      .records-matriz td:nth-child(4), .records-matriz td:nth-child(10) { white-space:normal; min-width:90px; }
    </style>
    <div class="table-card" style="margin-top:16px;">
      <div class="table-wrapper">
        <table class="records-matriz">
          <thead>
            <tr>
              <th colspan="6" style="text-align:center;">Masculino</th>
              <th rowspan="2" style="text-align:center;vertical-align:middle;background:#eef2ff;">Prueba</th>
              <th colspan="6" style="text-align:center;">Femenino</th>
            </tr>
            <tr>
              <th>Fecha</th><th>Lugar</th><th>Año</th><th>Nombre</th><th>WA</th><th>Tiempo</th>
              <th>Tiempo</th><th>WA</th><th>Nombre</th><th>Año</th><th>Lugar</th><th>Fecha</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$any): ?>
              <tr><td colspan="13" class="text-center text-muted" style="padding:32px;">Sin récords para esta selección.</td></tr>
            <?php else: foreach ($PRUEBAS as $p):
              if (!isset($records['M'][$p]) && !isset($records['F'][$p])) continue; ?>
              <tr>
                <?= celdas_lado($records['M'][$p] ?? null, $p, $piscina, 'M', false, $temporada_actual) ?>
                <td style="text-align:center;background:#eef2ff;"><strong><?= e(format_prueba($p)) ?></strong></td>
                <?= celdas_lado($records['F'][$p] ?? null, $p, $piscina, 'F', true, $temporada_actual) ?>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
}

render_header('Récords del club', 'admin-ranking');
render_admin_layout('ranking', function () use ($PRUEBAS, $records, $filterPrueba, $filterPiscina, $filterNadador, $temporada_actual) {
?>

<h1 style="margin-bottom:6px;">Récords del club</h1>

<div class="ranking-tabs">
  <a href="/admin/ranking" class="js-loading-link">Ranking</a>
  <a href="/admin/ranking-edades" class="js-loading-link">Marcas de Edad</a>
  <a href="/admin/records" class="tab--active">Récords del Club</a>
  <a href="/admin/puntos-aqua" class="js-loading-link">Puntos AQUA</a>
</div>

<?php
$base_filters = [
  'prueba'  => $filterPrueba,
  'piscina' => $filterPiscina,
  'nadador' => $filterNadador,
];
?>
<div class="filters-bar" style="flex-direction:column;gap:16px;">
  <form method="GET" class="filters-form js-loading-form">
    <input type="hidden" name="nadador" value="<?= e($filterNadador) ?>">

    <div class="form-group">
      <label class="form-label">Prueba</label>
      <select name="prueba" class="form-control">
        <option value="">Todas las pruebas</option>
        <?php foreach ($PRUEBAS as $p): ?>
          <option value="<?= e($p) ?>" <?= $filterPrueba === $p ? 'selected' : '' ?>><?= e(format_prueba($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Piscina</label>
      <select name="piscina" class="form-control">
        <option value="25m" <?= $filterPiscina === '25m' ? 'selected' : '' ?>>25m</option>
        <option value="50m" <?= $filterPiscina === '50m' ? 'selected' : '' ?>>50m</option>
      </select>
    </div>

    <div class="form-group" style="align-self:flex-end;display:flex;gap:8px;">
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </div>
  </form>

  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;border-top:1px solid #eee;padding-top:14px;">
    <?php $nadador_opts = ['1' => 'Activos', '0' => 'No activos', '' => 'Todos']; ?>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Nadador</label>
      <div style="display:inline-flex;border:2px solid var(--blue);border-radius:8px;overflow:hidden;">
        <?php foreach ($nadador_opts as $nv => $nl):
          $nv = (string)$nv;
          $np = $base_filters;
          $np['nadador'] = $nv;
          $active = $filterNadador === $nv;
        ?>
          <a href="?<?= http_build_query($np) ?>"
            class="js-loading-link"
            style="padding:5px 12px;font-size:13px;font-weight:<?= $active ? '700' : '500' ?>;text-decoration:none;color:<?= $active ? '#fff' : 'var(--blue)' ?>;background:<?= $active ? 'var(--blue)' : '#fff' ?>;"><?= $nl ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<p class="text-muted text-sm" style="margin:16px 0 0;">
  Récord = mejor marca vigente del club en cada prueba · Piscina <?= e($filterPiscina) ?>.
  Puntos <strong>WA</strong> calculados con el baremo World Aquatics.
</p>

<?php tabla_records_unica($records, $PRUEBAS, $filterPiscina, $temporada_actual); ?>

<script>
document.querySelectorAll('.js-loading-form select').forEach(select => {
  select.addEventListener('change', function () { this.form.requestSubmit(); });
});
</script>

<?php
});
render_footer();
