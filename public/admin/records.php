<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/records_relevos.php';

require_admin_area(['director_tecnico']);
$admin_user = current_user();

// --- Gestión de récords de relevo (manuales) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';
    $volverPiscina = in_array($_POST['piscina'] ?? '', ['25m', '50m'], true) ? $_POST['piscina'] : '25m';
    try {
        if ($accion === 'guardar_relevo') {
            $id = (int)($_POST['id'] ?? 0);
            records_relevos_guardar($pdo, $id ?: null, $_POST, (int)$admin_user['id']);
            flash($id ? 'Récord de relevo actualizado.' : 'Récord de relevo añadido.', 'success');
        } elseif ($accion === 'eliminar_relevo') {
            records_relevos_eliminar($pdo, (int)($_POST['id'] ?? 0));
            flash('Récord de relevo eliminado.', 'success');
        }
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
        $extra = !empty($_POST['id']) ? '&editar_relevo=' . (int)$_POST['id'] : '';
        header('Location: /admin/records?piscina=' . $volverPiscina . $extra . '#gestion-relevos');
        exit;
    }
    header('Location: /admin/records?piscina=' . $volverPiscina . '#gestion-relevos');
    exit;
}

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

$relevosVigentes = records_relevos_vigentes($pdo, $filterPiscina);
$relevosTodos = records_relevos_listar($pdo, $filterPiscina);
$editarRelevo = isset($_GET['editar_relevo']) ? records_relevos_obtener($pdo, (int)$_GET['editar_relevo']) : null;
$nombresSocios = $pdo->query("SELECT nombre FROM users WHERE rol = 'socio' ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);

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
render_admin_layout('ranking', function () use ($PRUEBAS, $records, $filterPrueba, $filterPiscina, $filterNadador, $temporada_actual, $relevosVigentes, $relevosTodos, $editarRelevo, $nombresSocios) {
?>

<h1 style="margin-bottom:6px;">Récords del club</h1>
<?php render_flash(); ?>

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

<?php if ($filterPrueba === '') render_tabla_records_relevos($relevosVigentes, $filterPiscina, $temporada_actual); ?>

<?php
$vigentesIds = [];
foreach ($relevosVigentes as $porPrueba) foreach ($porPrueba as $r) $vigentesIds[(int)$r['id']] = true;
$rv = $editarRelevo ?? ['id' => 0, 'prueba' => '4x50L', 'piscina' => $filterPiscina, 'sexo' => 'M', 'tiempo' => '', 'fecha' => date('Y-m-d'), 'lugar' => '',
                        'nadador1' => '', 'nadador2' => '', 'nadador3' => '', 'nadador4' => ''];
?>
<div class="card" id="gestion-relevos" style="padding:24px;margin-top:32px;scroll-margin-top:90px;">
  <h3 style="margin-top:0;">Gestionar récords de relevo · <?= e($filterPiscina) ?></h3>
  <p class="text-muted text-sm">Los récords de relevo se introducen a mano. Si añades un tiempo mejor, pasa a ser el récord vigente; los anteriores quedan como histórico (puedes borrarlos).</p>

  <?php if ($relevosTodos): ?>
  <div class="table-wrapper" style="margin-bottom:20px;">
    <table>
      <thead><tr><th>Relevo</th><th>Categoría</th><th>Tiempo</th><th>Nadadores</th><th>Fecha</th><th>Lugar</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($relevosTodos as $r): ?>
          <tr>
            <td style="white-space:nowrap;"><?= e(format_relevo($r['prueba'])) ?></td>
            <td><?= e(CE_RELEVO_SEXOS[$r['sexo']]) ?></td>
            <td style="white-space:nowrap;"><span class="mark-time"><?= e($r['tiempo']) ?></span>
              <?php if (isset($vigentesIds[(int)$r['id']])): ?><span class="badge badge-blue" style="margin-left:4px;">Récord</span><?php endif; ?></td>
            <td class="text-sm"><?= records_relevos_nadadores($r, ' · ') ?></td>
            <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
            <td class="text-sm text-muted"><?= e($r['lugar']) ?></td>
            <td style="white-space:nowrap;text-align:right;">
              <a href="/admin/records?piscina=<?= e($filterPiscina) ?>&editar_relevo=<?= (int)$r['id'] ?>#gestion-relevos" class="btn btn-gray btn-sm"><i class="bi bi-pencil"></i></a>
              <form method="POST" action="/admin/records" class="js-confirm-relevo" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="eliminar_relevo">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="piscina" value="<?= e($filterPiscina) ?>">
                <button type="submit" class="btn btn-gray btn-sm"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <form method="POST" action="/admin/records" style="border-top:1px solid #eee;padding-top:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar_relevo">
    <input type="hidden" name="id" value="<?= (int)$rv['id'] ?>">
    <h4 style="margin:0 0 12px;"><?= $editarRelevo ? 'Editar récord de relevo' : 'Añadir récord de relevo' ?></h4>
    <div class="d-flex gap-3 flex-wrap">
      <div class="form-group" style="flex:1;min-width:150px;">
        <label class="form-label">Relevo *</label>
        <select name="prueba" class="form-control" id="rr-prueba">
          <?php foreach (array_keys(CE_RELEVOS) as $k): ?>
            <option value="<?= $k ?>" <?= $rv['prueba'] === $k ? 'selected' : '' ?>><?= e(format_relevo($k)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:110px;">
        <label class="form-label">Piscina *</label>
        <select name="piscina" class="form-control">
          <?php foreach (['25m', '50m'] as $pp): ?>
            <option value="<?= $pp ?>" <?= $rv['piscina'] === $pp ? 'selected' : '' ?>><?= $pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:130px;">
        <label class="form-label">Categoría *</label>
        <select name="sexo" class="form-control">
          <?php foreach (CE_RELEVO_SEXOS as $k => $label): ?>
            <option value="<?= $k ?>" <?= $rv['sexo'] === $k ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:120px;">
        <label class="form-label">Tiempo *</label>
        <input type="text" name="tiempo" class="form-control" placeholder="1:52.30" required value="<?= e($rv['tiempo']) ?>">
      </div>
    </div>
    <div class="d-flex gap-3 flex-wrap">
      <div class="form-group" style="flex:1;min-width:150px;">
        <label class="form-label">Fecha *</label>
        <input type="date" name="fecha" class="form-control" required value="<?= e($rv['fecha']) ?>">
      </div>
      <div class="form-group" style="flex:2;min-width:200px;">
        <label class="form-label">Lugar</label>
        <input type="text" name="lugar" class="form-control" maxlength="255" value="<?= e($rv['lugar']) ?>">
      </div>
    </div>
    <div class="d-flex gap-3 flex-wrap">
      <?php for ($i = 1; $i <= 4; $i++): ?>
        <div class="form-group" style="flex:1;min-width:200px;">
          <label class="form-label relevo-posta-label" data-pos="<?= $i ?>"><?= e(relevo_nombre_posta($rv['prueba'], $i)) ?> *</label>
          <div class="d-flex gap-2">
            <input type="text" name="nadador<?= $i ?>" class="form-control" maxlength="100" list="socios-nombres" required placeholder="Nombre" value="<?= e($rv['nadador' . $i]) ?>" style="flex:1;min-width:0;">
            <input type="text" name="parcial<?= $i ?>" class="form-control" maxlength="20" placeholder="Tiempo" inputmode="decimal" title="Tiempo de su posta (opcional)" value="<?= e($rv['parcial' . $i] ?? '') ?>" style="width:90px;flex:none;">
          </div>
        </div>
      <?php endfor; ?>
    </div>
    <p class="text-muted text-sm" style="margin:-6px 0 12px;">El tiempo de cada posta es opcional. Si el nombre coincide con un socio (usa la lista de sugerencias), el récord aparece también en su perfil.</p>
    <datalist id="socios-nombres">
      <?php foreach ($nombresSocios as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?>
    </datalist>
    <div style="display:flex;gap:12px;">
      <button type="submit" class="btn btn-primary"><?= $editarRelevo ? 'Guardar cambios' : 'Añadir récord' ?></button>
      <?php if ($editarRelevo): ?><a href="/admin/records?piscina=<?= e($filterPiscina) ?>#gestion-relevos" class="btn btn-gray">Cancelar</a><?php endif; ?>
    </div>
  </form>
</div>

<script>
(function () {
  var sel = document.getElementById('rr-prueba'), estilos = ['Espalda', 'Braza', 'Mariposa', 'Libre'];
  function etiquetas() {
    var x = sel.value.slice(-1) === 'X';
    document.querySelectorAll('.relevo-posta-label').forEach(function (l) {
      var p = +l.dataset.pos;
      l.textContent = (x ? estilos[p - 1] : 'Posta ' + p) + ' *';
    });
  }
  sel.addEventListener('change', etiquetas);
})();
</script>

<div id="modal-relevo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;padding:24px;border-radius:8px;max-width:420px;width:calc(100% - 32px);">
    <h3 style="margin:0 0 12px;">¿Eliminar récord de relevo?</h3>
    <p>Esta acción no se puede deshacer.</p>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
      <button type="button" class="btn btn-gray" id="modal-relevo-no">Cancelar</button>
      <button type="button" class="btn" style="background:#dc2626;color:#fff;" id="modal-relevo-si">Eliminar</button>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('modal-relevo'), pendiente = null;
  document.querySelectorAll('form.js-confirm-relevo').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.ok) return;
      ev.preventDefault();
      pendiente = f;
      modal.style.display = 'flex';
    });
  });
  document.getElementById('modal-relevo-no').onclick = function () { modal.style.display = 'none'; pendiente = null; };
  document.getElementById('modal-relevo-si').onclick = function () {
    if (!pendiente) return;
    pendiente.dataset.ok = '1';
    pendiente.submit();
  };
})();
</script>

<script>
document.querySelectorAll('.js-loading-form select').forEach(select => {
  select.addEventListener('change', function () { this.form.requestSubmit(); });
});
</script>

<?php
});
render_footer();
