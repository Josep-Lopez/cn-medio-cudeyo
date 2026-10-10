<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/grupos.php';
require_once dirname(__DIR__, 2) . '/includes/asistencia.php';
require_once dirname(__DIR__, 2) . '/includes/minimas.php';

require_admin_area(['director_tecnico', 'entrenador']);

$LIGAS = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','absoluto'=>'Absoluto'];
$FILTROS_ASIS = ['90' => '≥ 90%', '80' => '≥ 80%', '50' => '≥ 50%', 'lt50' => '< 50%'];
$FILTROS_MIN  = ['ok' => 'Conseguida (≥ 100%)', '95' => '≥ 95%', '90' => '≥ 90%', '85' => '≥ 85%', 'lt85' => '< 85%', 'sin' => 'Sin mínima'];

$filtroLiga = $_GET['liga'] ?? 'todos';
if ($filtroLiga !== 'todos' && !array_key_exists($filtroLiga, $LIGAS)) $filtroLiga = 'todos';
$filtroGrupo = isset($_GET['grupo']) ? (int)$_GET['grupo'] : 0;
$filtroAsis = $_GET['asis'] ?? '';
if (!array_key_exists($filtroAsis, $FILTROS_ASIS)) $filtroAsis = '';
$filtroMin = $_GET['min'] ?? '';
if (!array_key_exists($filtroMin, $FILTROS_MIN)) $filtroMin = '';
$orden = $_GET['orden'] ?? 'nombre';
if (!in_array($orden, ['nombre', 'asistencia', 'minima'], true)) $orden = 'nombre';
$dir = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
$grupos = listar_grupos($pdo);

// Temporada (mes>=9 => YYYY-YY+1, por defecto la actual): marcas de esa temporada y asistencia de sep a ago.
$current_year    = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporadas_disp = [];
for ($y = $current_year; $y >= 2012; $y--)
    $temporadas_disp[] = $y . '-' . substr((string)($y + 1), 2);
$temporada = $_GET['temporada'] ?? $temporadas_disp[0];
if (!in_array($temporada, $temporadas_disp, true)) $temporada = $temporadas_disp[0];
$anio_ini = (int)substr($temporada, 0, 4);

$where = "estado='activo' AND rol='socio' AND nadador_activo=1 AND liga <> 'master'";
$params = [];
if ($filtroLiga && $filtroLiga !== 'todos') {
    $where .= ' AND liga=?';
    $params[] = $filtroLiga;
}
if ($filtroGrupo > 0) {
    $where .= ' AND id IN (SELECT user_id FROM grupo_nadadores WHERE grupo_id=?)';
    $params[] = $filtroGrupo;
}
$stmt = $pdo->prepare("SELECT id, nombre, liga, sexo, fecha_nacimiento FROM users WHERE $where ORDER BY nombre");
$stmt->execute($params);
$nadadores = $stmt->fetchAll();

// ── Asistencia (solo verificada) ─────────────────────────────────────────────
$ids = array_column($nadadores, 'id');
$asistencia = resumen_asistencia_rango($pdo, $ids, "$anio_ini-09-01", ($anio_ini + 1) . '-08-31');

// ── Mejor % de mínima por nadador (marcas no parciales de la temporada) ──────
$minimas_datos = minimas_cargar($pdo);
$mejor_minima = []; // [user_id => ['pct' => float, 'marca' => row]]
if ($ids) {
    $por_id = array_column($nadadores, null, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT user_id, prueba, piscina, tiempo, tiempo_seg, fecha_marca FROM marcas
                           WHERE user_id IN ($ph) AND temporada = ? AND es_parcial = 0 AND tiempo_seg > 0");
    $stmt->execute(array_merge($ids, [$temporada]));
    foreach ($stmt->fetchAll() as $m) {
        $n = $por_id[$m['user_id']];
        $edad = edad_deportiva($m['fecha_marca'], $n['fecha_nacimiento']);
        $min  = minima_para($minimas_datos, $m['prueba'], $m['piscina'], $n['sexo'], $edad);
        if (!$min) continue;
        $pct = $min['seg'] / (float)$m['tiempo_seg'] * 100;
        if (!isset($mejor_minima[$m['user_id']]) || $pct > $mejor_minima[$m['user_id']]['pct']) {
            $mejor_minima[$m['user_id']] = ['pct' => $pct, 'marca' => $m, 'minima_seg' => $min['seg']];
        }
    }
}

// ── Filtros % ────────────────────────────────────────────────────────────────
$nadadores = array_values(array_filter($nadadores, function ($n) use ($asistencia, $mejor_minima, $filtroAsis, $filtroMin) {
    $pa = $asistencia[$n['id']][2];
    if ($filtroAsis !== '') {
        if ($pa === null) return false;
        if ($filtroAsis === 'lt50' ? $pa >= 50 : $pa < (int)$filtroAsis) return false;
    }
    $pm = $mejor_minima[$n['id']]['pct'] ?? null;
    return match ($filtroMin) {
        ''     => true,
        'sin'  => $pm === null,
        'ok'   => $pm !== null && $pm >= 100,
        'lt85' => $pm !== null && $pm < 85,
        default => $pm !== null && $pm >= (int)$filtroMin,
    };
}));

// ── Orden (sin datos siempre al final; usort es estable → empates por nombre) ─
if ($orden === 'nombre') {
    if ($dir === 'desc') $nadadores = array_reverse($nadadores);
} else {
    $valor = fn($n) => $orden === 'asistencia' ? $asistencia[$n['id']][2] : ($mejor_minima[$n['id']]['pct'] ?? null);
    usort($nadadores, function ($a, $b) use ($valor, $dir) {
        $va = $valor($a);
        $vb = $valor($b);
        if ($va === null || $vb === null) return ($va === null) <=> ($vb === null);
        return $dir === 'desc' ? $vb <=> $va : $va <=> $vb;
    });
}

// URL de cabecera ordenable: mismo filtro, alterna sentido si ya es la columna activa.
$url_orden = function (string $col, string $dir_defecto) use ($orden, $dir): string {
    $q = $_GET;
    $q['orden'] = $col;
    $q['dir'] = $orden === $col ? ($dir === 'asc' ? 'desc' : 'asc') : $dir_defecto;
    return '?' . http_build_query($q);
};

render_header('Deportistas', 'admin-deportistas');
render_admin_layout('deportistas', function() use ($LIGAS, $FILTROS_ASIS, $FILTROS_MIN, $grupos, $nadadores, $filtroLiga, $filtroGrupo, $filtroAsis, $filtroMin, $orden, $dir, $temporada, $temporadas_disp, $asistencia, $mejor_minima, $minimas_datos, $url_orden) {
  $flecha = fn(string $col) => $orden === $col ? ($dir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
?>

<h1>Deportistas</h1>
<?php render_flash(); ?>

<form method="get" class="card mb-6">
  <input type="hidden" name="orden" value="<?= e($orden) ?>">
  <input type="hidden" name="dir" value="<?= e($dir) ?>">
  <div class="d-flex gap-3 align-center flex-wrap">
    <div class="form-group" style="margin:0;">
      <label class="form-label">Temporada</label>
      <select name="temporada" class="form-control" style="width:auto;min-width:120px;" onchange="this.form.submit()">
        <?php foreach ($temporadas_disp as $t): ?>
          <option value="<?= e($t) ?>" <?= $temporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Categoría</label>
      <select name="liga" class="form-control" style="width:auto;min-width:160px;" onchange="this.form.submit()">
        <option value="todos" <?= $filtroLiga === 'todos' ? 'selected' : '' ?>>Todos</option>
        <?php foreach ($LIGAS as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroLiga === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Grupo</label>
      <select name="grupo" class="form-control" style="width:auto;min-width:160px;" onchange="this.form.submit()">
        <option value="0">Todos</option>
        <?php foreach ($grupos as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $filtroGrupo === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">Asistencia</label>
      <select name="asis" class="form-control" style="width:auto;min-width:130px;" onchange="this.form.submit()">
        <option value="">Todas</option>
        <?php foreach ($FILTROS_ASIS as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroAsis === (string)$k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;">
      <label class="form-label">% mínima</label>
      <select name="min" class="form-control" style="width:auto;min-width:170px;" onchange="this.form.submit()">
        <option value="">Todas</option>
        <?php foreach ($FILTROS_MIN as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroMin === (string)$k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;flex:1;min-width:180px;">
      <label class="form-label">Buscar nadador</label>
      <input type="text" id="buscador" class="form-control" placeholder="Nombre…"
             oninput="filtrarNadadores(this.value)">
    </div>
  </div>
  <p class="text-sm text-muted" style="margin:12px 0 0;">
    Temporada <?= e($temporada) ?>: asistencia verificada y mejor marca respecto a la mínima RFEN de su edad.
  </p>
</form>

<?php if (!$nadadores): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay nadadores activos con estos filtros.</p>
  </div>
<?php else: ?>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead><tr>
        <th><a href="<?= e($url_orden('nombre', 'asc')) ?>" style="color:inherit;">Nadador/a<?= $flecha('nombre') ?></a></th>
        <th>Categoría</th>
        <th>Sexo</th>
        <th><a href="<?= e($url_orden('asistencia', 'desc')) ?>" style="color:inherit;">Asistencia<?= $flecha('asistencia') ?></a></th>
        <th><a href="<?= e($url_orden('minima', 'desc')) ?>" style="color:inherit;">% mínima<?= $flecha('minima') ?></a></th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($nadadores as $n):
          [$presentes, $computables, $pct] = $asistencia[$n['id']];
          $mm = $mejor_minima[$n['id']] ?? null;
        ?>
          <tr data-nombre="<?= e(mb_strtolower($n['nombre'])) ?>">
            <td style="font-weight:600;"><?= e($n['nombre']) ?></td>
            <td><?= e(format_liga($n['liga'] ?? '')) ?></td>
            <td><?= e($n['sexo'] ?? '') ?></td>
            <td style="white-space:nowrap;">
              <?php if ($pct === null): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <span class="text-sm text-muted"><?= $presentes ?>/<?= $computables ?></span>
                <span style="font-weight:600;margin-left:4px;color:<?= $pct >= 80 ? 'var(--green)' : ($pct < 50 ? 'var(--red)' : 'var(--text)') ?>;"><?= $pct ?>%</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <?php if (!$mm): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <span class="text-sm"><?= e(format_prueba($mm['marca']['prueba'])) ?> · <?= e($mm['marca']['piscina']) ?></span>
                <span class="mark-time" style="margin-left:6px;"><?= e($mm['marca']['tiempo']) ?></span>
                <span class="text-sm text-muted">/ mín. <?= e(segundos_a_tiempo($mm['minima_seg'])) ?></span><?= render_minima_badge($minimas_datos, $mm['marca'], $n['sexo'], $n['fecha_nacimiento']) ?>
              <?php endif; ?>
            </td>
            <td><a href="/admin/deportista?id=<?= (int)$n['id'] ?>" class="btn btn-gray btn-sm">Ver perfil →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<script>
function filtrarNadadores(q) {
  var term = q.toLowerCase().trim();
  document.querySelectorAll('tbody tr').forEach(function(tr) {
    var nombre = tr.getAttribute('data-nombre') || '';
    tr.hidden = term && nombre.indexOf(term) === -1;
  });
}
</script>

<?php
});
render_footer();
