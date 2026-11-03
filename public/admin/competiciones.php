<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';
require_once dirname(__DIR__, 2) . '/includes/grupos.php';
require_once dirname(__DIR__, 2) . '/includes/rfen.php';

require_admin_area(['director_tecnico', 'entrenador']);

const CE_TABS = ['convocatoria' => 'Convocatoria', 'relevos' => 'Relevos', 'minimas' => 'Mínimas', 'resultados' => 'Resultados', 'datos' => 'Datos'];

function ce_volver(int $id, string $tab, string $extra = ''): never
{
    header('Location: /admin/competiciones?ver=' . $id . '&tab=' . $tab . $extra);
    exit;
}

function ce_int_o_null($v): ?int
{
    $v = trim((string)$v);
    return $v === '' ? null : (int)$v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        try {
            $id = crear_competicion_entrenador($pdo, $_POST, (int)current_user()['id']);
            flash('Competición creada. Ahora añade a los nadadores convocados.', 'success');
            ce_volver($id, 'convocatoria');
        } catch (Throwable $ex) {
            flash('Error: ' . $ex->getMessage(), 'danger');
            header('Location: /admin/competiciones?accion=nueva');
            exit;
        }
    }

    if ($accion === 'eliminar') {
        eliminar_competicion_entrenador($pdo, (int)$_POST['id']);
        flash('Competición eliminada.', 'success');
        header('Location: /admin/competiciones');
        exit;
    }

    $competicionId = (int)($_POST['competicion_id'] ?? 0);
    $comp = $competicionId ? obtener_competicion_entrenador($pdo, $competicionId) : null;
    if (!$comp) {
        flash('Competición no encontrada.', 'danger');
        header('Location: /admin/competiciones');
        exit;
    }
    $uid = (int)($_POST['user_id'] ?? 0);

    try {
        switch ($accion) {
            case 'editar':
                actualizar_competicion_entrenador($pdo, $competicionId, $_POST);
                $comp = obtener_competicion_entrenador($pdo, $competicionId);
                recalcular_marcas_convocatoria($pdo, $comp);
                flash('Datos guardados. Marcas de inscripción recalculadas.', 'success');
                ce_volver($competicionId, 'datos');

            case 'convocar':
                convocar_nadador($pdo, $competicionId, $uid);
                flash('Nadador convocado.', 'success');
                ce_volver($competicionId, 'convocatoria', '#n' . $uid);

            case 'convocar_grupo':
                $n = convocar_grupo($pdo, $competicionId, (int)($_POST['grupo_id'] ?? 0));
                flash($n ? "$n nadador(es) convocados." : 'Todos los nadadores del grupo ya estaban convocados.', $n ? 'success' : 'info');
                ce_volver($competicionId, 'convocatoria');

            case 'desconvocar':
                desconvocar_nadador($pdo, $competicionId, $uid);
                flash('Nadador quitado de la convocatoria.', 'success');
                ce_volver($competicionId, 'convocatoria');

            case 'inscribir_prueba':
                $nadador = null;
                foreach (listar_convocados($pdo, $competicionId) as $n) {
                    if ((int)$n['id'] === $uid) $nadador = $n;
                }
                if (!$nadador) throw new InvalidArgumentException('El nadador no está convocado');
                inscribir_prueba($pdo, $comp, $nadador, $_POST['prueba'] ?? '');
                ce_volver($competicionId, 'convocatoria', '#n' . $uid);

            case 'desinscribir_prueba':
                desinscribir_prueba($pdo, $competicionId, $uid, $_POST['prueba'] ?? '');
                ce_volver($competicionId, 'convocatoria', '#n' . $uid);

            case 'recalcular':
                $n = recalcular_marcas_convocatoria($pdo, $comp);
                flash("Marcas recalculadas ($n pruebas).", 'success');
                ce_volver($competicionId, 'convocatoria');

            case 'guardar_minimas':
                $piscina = $_POST['piscina'] ?? '';
                $etiqueta = trim($_POST['etiqueta'] ?? '');
                $desde = ce_int_o_null($_POST['anio_desde'] ?? '');
                $hasta = ce_int_o_null($_POST['anio_hasta'] ?? '');
                $n = guardar_bloque_minimas($pdo, $competicionId, $etiqueta, $desde, $hasta, $piscina, $_POST['t'] ?? []);
                // Si se ha editado un bloque y ha cambiado su clave, se borra el original
                if (isset($_POST['orig_piscina'])) {
                    $o = [trim($_POST['orig_etiqueta'] ?? ''), ce_int_o_null($_POST['orig_anio_desde'] ?? ''), ce_int_o_null($_POST['orig_anio_hasta'] ?? ''), $_POST['orig_piscina']];
                    if ($o !== [$etiqueta, $desde, $hasta, $piscina]) {
                        eliminar_bloque_minimas($pdo, $competicionId, $o[0] !== '' ? $o[0] : null, $o[1], $o[2], $o[3]);
                    }
                }
                recalcular_marcas_convocatoria($pdo, $comp);
                flash("$n mínimas guardadas.", 'success');
                ce_volver($competicionId, 'minimas');

            case 'eliminar_minimas':
                $etiqueta = trim($_POST['etiqueta'] ?? '');
                eliminar_bloque_minimas($pdo, $competicionId, $etiqueta !== '' ? $etiqueta : null,
                    ce_int_o_null($_POST['anio_desde'] ?? ''), ce_int_o_null($_POST['anio_hasta'] ?? ''), $_POST['piscina'] ?? '');
                recalcular_marcas_convocatoria($pdo, $comp);
                flash('Bloque de mínimas eliminado.', 'success');
                ce_volver($competicionId, 'minimas');

            case 'guardar_relevo':
                $relevoId = (int)($_POST['relevo_id'] ?? 0);
                $guardadoId = guardar_relevo($pdo, $competicionId, $relevoId ?: null, $_POST);
                flash($relevoId ? 'Relevo actualizado.' : 'Relevo creado.', 'success');
                ce_volver($competicionId, 'relevos', '#r' . $guardadoId);

            case 'eliminar_relevo':
                eliminar_relevo($pdo, $competicionId, (int)($_POST['relevo_id'] ?? 0));
                flash('Relevo eliminado.', 'success');
                ce_volver($competicionId, 'relevos');

            case 'agregar_tiempo':
                agregar_tiempo_entrenador($pdo, [
                    'competicion_id' => $competicionId,
                    'user_id' => $_POST['user_id'] ?? '',
                    'prueba' => $_POST['prueba'] ?? '',
                    'piscina' => $_POST['piscina'] ?? '',
                    'tiempo' => $_POST['tiempo'] ?? '',
                    'parciales' => $_POST['parciales'] ?? '',
                    'registrado_por' => current_user()['id'],
                ]);
                flash('Tiempo añadido.', 'success');
                ce_volver($competicionId, 'resultados');

            case 'importar_rfen':
                // Refresca de RFEN solo los días de la competición para los convocados vinculados y copia los tiempos
                set_time_limit(0);
                $rango = [$comp['fecha'], date('Y-m-d', strtotime($comp['fecha'] . ' +' . CE_RFEN_DIAS_COMPETICION . ' days'))];
                $sinRfen = [];
                $stmtRfen = $pdo->prepare('SELECT rfen_id FROM users WHERE id = ?');
                foreach (listar_convocados($pdo, $competicionId) as $nad) {
                    $stmtRfen->execute([$nad['id']]);
                    $rfenId = (string)$stmtRfen->fetchColumn();
                    if ($rfenId === '') { $sinRfen[] = $nad['nombre']; continue; }
                    rfen_import_marks($pdo, (int)$nad['id'], $rfenId, null, $rango);
                }
                $n = ce_importar_tiempos_rfen($pdo, $comp, (int)current_user()['id']);
                $msg = $n ? "$n tiempo(s) importados de RFEN." : 'No hay tiempos nuevos en RFEN para estas fechas (puede que aún no estén publicados).';
                if ($sinRfen) $msg .= ' Sin RFEN vinculado: ' . implode(', ', $sinRfen) . '.';
                flash($msg, $n ? 'success' : 'info');
                ce_volver($competicionId, 'resultados');

            case 'eliminar_tiempo':
                eliminar_tiempo_entrenador($pdo, $competicionId, (int)($_POST['tiempo_id'] ?? 0));
                flash('Tiempo eliminado.', 'success');
                ce_volver($competicionId, 'resultados');
        }
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
        $tabError = match ($accion) {
            'editar' => 'datos',
            'guardar_minimas', 'eliminar_minimas' => 'minimas',
            'guardar_relevo', 'eliminar_relevo' => 'relevos',
            'agregar_tiempo', 'eliminar_tiempo', 'importar_rfen' => 'resultados',
            default => 'convocatoria',
        };
        $extra = '';
        if ($accion === 'guardar_relevo' && !empty($_POST['relevo_id'])) $extra = '&relevo=' . (int)$_POST['relevo_id'];
        ce_volver($competicionId, $tabError, $extra);
    }
    header('Location: /admin/competiciones?ver=' . $competicionId);
    exit;
}

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$tab = $_GET['tab'] ?? 'convocatoria';
if (!isset(CE_TABS[$tab])) $tab = 'convocatoria';
$competiciones = listar_competiciones_entrenador($pdo);

// Formulario de datos de la competición (nueva o edición)
function ce_form_datos(?array $comp): void
{
    $c = $comp ?? ['nombre' => '', 'lugar' => '', 'fecha' => date('Y-m-d'), 'piscina' => '25m', 'marcas_desde' => '', 'marcas_hasta' => '', 'minimas_fuente' => 'ninguna'];
?>
<form method="POST" action="/admin/competiciones" class="card" style="padding:24px;max-width:640px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="<?= $comp ? 'editar' : 'crear' ?>">
  <?php if ($comp): ?><input type="hidden" name="competicion_id" value="<?= (int)$comp['id'] ?>"><?php endif; ?>
  <div class="form-group">
    <label class="form-label">Nombre *</label>
    <input type="text" name="nombre" class="form-control" required maxlength="150" value="<?= e($c['nombre']) ?>">
  </div>
  <div class="d-flex gap-3 flex-wrap">
    <div class="form-group" style="flex:2;min-width:200px;">
      <label class="form-label">Lugar</label>
      <input type="text" name="lugar" class="form-control" maxlength="255" value="<?= e($c['lugar'] ?? '') ?>">
    </div>
    <div class="form-group" style="flex:1;min-width:150px;">
      <label class="form-label">Fecha *</label>
      <input type="date" name="fecha" class="form-control" required value="<?= e($c['fecha']) ?>">
    </div>
    <div class="form-group" style="flex:1;min-width:110px;">
      <label class="form-label">Piscina *</label>
      <select name="piscina" class="form-control">
        <?php foreach (CE_PISCINAS as $p): ?>
          <option value="<?= $p ?>" <?= $c['piscina'] === $p ? 'selected' : '' ?>><?= $p ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="d-flex gap-3 flex-wrap">
    <div class="form-group" style="flex:1;min-width:150px;">
      <label class="form-label">Marcas válidas desde</label>
      <input type="date" name="marcas_desde" class="form-control" value="<?= e($c['marcas_desde'] ?? '') ?>">
    </div>
    <div class="form-group" style="flex:1;min-width:150px;">
      <label class="form-label">Marcas válidas hasta</label>
      <input type="date" name="marcas_hasta" class="form-control" value="<?= e($c['marcas_hasta'] ?? '') ?>">
    </div>
  </div>
  <p class="text-muted text-sm" style="margin-top:-8px;">Periodo en el que se busca la mejor marca de cada nadador. Vacío = sin límite.</p>
  <div class="form-group">
    <label class="form-label">Mínimas exigidas</label>
    <select name="minimas_fuente" class="form-control">
      <?php foreach (CE_MINIMAS_FUENTES as $k => $label): ?>
        <option value="<?= $k ?>" <?= $c['minimas_fuente'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="text-muted text-sm" style="margin:6px 0 0;">«Propias» se introducen en la pestaña Mínimas (por prueba, piscina, sexo y año de nacimiento).</p>
  </div>
  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary"><?= $comp ? 'Guardar cambios' : 'Crear' ?></button>
    <?php if (!$comp): ?><a href="/admin/competiciones" class="btn btn-gray">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php
}

// Botón de borrado con modal de confirmación propio (ver JS al final)
function ce_form_borrar(int $compId, string $accion, array $campos, string $mensaje, string $texto = '×', string $clase = 'ce-x'): void
{
?>
<form method="POST" action="/admin/competiciones" class="js-confirm" data-msg="<?= e($mensaje) ?>" style="display:inline;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="<?= e($accion) ?>">
  <input type="hidden" name="competicion_id" value="<?= $compId ?>">
  <?php foreach ($campos as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
  <button type="submit" class="<?= e($clase) ?>" title="Eliminar"><?= $texto ?></button>
</form>
<?php
}

render_header('Competiciones', 'admin-competiciones');
render_admin_layout('competiciones', function() use ($accion, $verId, $tab, $competiciones, $pdo) {

    if ($accion === 'nueva') {
?>
<h1>Nueva competición</h1>
<?php render_flash(); ?>
<?php ce_form_datos(null); ?>
<?php
        return;
    }

    if ($verId > 0) {
        $comp = obtener_competicion_entrenador($pdo, $verId);
        if (!$comp) {
            echo '<div class="card" style="padding:24px;">Competición no encontrada. <a href="/admin/competiciones">Volver</a></div>';
            return;
        }
        $cid = (int)$comp['id'];
        $convocados = listar_convocados($pdo, $cid);
        $porId = [];
        foreach ($convocados as $n) $porId[(int)$n['id']] = $n;
        $inscripciones = listar_pruebas_convocatoria($pdo, $cid);
        $tiempos = listar_tiempos_competicion($pdo, $cid);
        $resultados = [];
        foreach ($tiempos as $t) {
            $k = $t['user_id'] . '|' . $t['prueba'];
            if (!isset($resultados[$k]) || $t['tiempo_seg'] < $resultados[$k]['tiempo_seg']) $resultados[$k] = $t;
        }

        $numPruebas = 0; $numMinimas = 0;
        foreach ($inscripciones as $u => $ps) {
            foreach ($ps as $f) {
                $numPruebas++;
                if (isset($porId[$u]) && (ce_estado_minima($pdo, $comp, $porId[$u], $f)['ok'] ?? false)) $numMinimas++;
            }
        }
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;"><?= e($comp['nombre']) ?></h1>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($convocados && (is_admin() || user_tiene_cargo('director_tecnico'))): ?>
      <a href="/admin/comunicaciones?nueva&convocados=<?= $cid ?>" class="btn btn-primary btn-sm"><i class="bi bi-megaphone"></i> Comunicado a convocados</a>
    <?php endif; ?>
    <a href="/admin/competiciones" class="btn btn-gray btn-sm">← Volver al listado</a>
  </div>
</div>
<?php render_flash(); ?>

<div class="card mb-6 ce-resumen">
  <div><i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($comp['fecha'])) ?><?php if ($comp['lugar']): ?> · <?= e($comp['lugar']) ?><?php endif; ?> · Piscina <?= e($comp['piscina']) ?></div>
  <div class="text-muted text-sm">
    <?= e(CE_MINIMAS_FUENTES[$comp['minimas_fuente']]) ?>
    <?php if ($comp['marcas_desde'] || $comp['marcas_hasta']): ?>
      · Marcas <?= $comp['marcas_desde'] ? 'desde ' . date('d/m/Y', strtotime($comp['marcas_desde'])) : '' ?>
      <?= $comp['marcas_hasta'] ? ' hasta ' . date('d/m/Y', strtotime($comp['marcas_hasta'])) : '' ?>
    <?php endif; ?>
  </div>
  <div class="ce-stats">
    <span><strong><?= count($convocados) ?></strong> convocados</span>
    <span><strong><?= $numPruebas ?></strong> pruebas</span>
    <?php if ($comp['minimas_fuente'] !== 'ninguna'): ?><span><strong><?= $numMinimas ?></strong> con mínima</span><?php endif; ?>
    <span><strong><?= count($tiempos) ?></strong> resultados</span>
  </div>
</div>

<nav class="ranking-tabs ce-tabs">
  <?php foreach (CE_TABS as $k => $label): ?>
    <a href="/admin/competiciones?ver=<?= $cid ?>&tab=<?= $k ?>" class="<?= $tab === $k ? 'tab--active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'convocatoria'):
        $noConvocados = listar_no_convocados($pdo, $cid);
        $grupos = listar_grupos($pdo);
?>
<div class="card mb-6" style="padding:20px;">
  <div class="d-flex gap-3 flex-wrap" style="align-items:flex-end;">
    <form method="POST" action="/admin/competiciones" class="d-flex gap-2" style="flex:2;min-width:260px;align-items:flex-end;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="convocar">
      <input type="hidden" name="competicion_id" value="<?= $cid ?>">
      <div class="form-group" style="flex:1;margin:0;">
        <label class="form-label">Añadir nadador</label>
        <select name="user_id" class="form-control" required>
          <option value="">— Selecciona —</option>
          <?php foreach ($noConvocados as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?><?= $s['liga'] ? ' · ' . e(format_liga($s['liga'])) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Convocar</button>
    </form>
    <?php if ($grupos): ?>
    <form method="POST" action="/admin/competiciones" class="d-flex gap-2" style="flex:1;min-width:220px;align-items:flex-end;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="convocar_grupo">
      <input type="hidden" name="competicion_id" value="<?= $cid ?>">
      <div class="form-group" style="flex:1;margin:0;">
        <label class="form-label">Añadir grupo entero</label>
        <select name="grupo_id" class="form-control" required>
          <option value="">— Grupo —</option>
          <?php foreach ($grupos as $g): ?>
            <option value="<?= (int)$g['id'] ?>"><?= e($g['nombre']) ?> (<?= (int)$g['num_nadadores'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-gray btn-sm">Añadir</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$convocados): ?>
  <div class="card text-center" style="padding:32px;"><p class="text-muted">Todavía no hay nadadores convocados.</p></div>
<?php else: ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
  <form method="POST" action="/admin/competiciones">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="recalcular">
    <input type="hidden" name="competicion_id" value="<?= $cid ?>">
    <button type="submit" class="btn btn-gray btn-sm" title="Vuelve a buscar la mejor marca de cada prueba inscrita"><i class="bi bi-arrow-repeat"></i> Recalcular mejores marcas</button>
  </form>
</div>
<div class="ce-convocados">
  <?php foreach ($convocados as $n):
      $uid = (int)$n['id'];
      $suyas = $inscripciones[$uid] ?? [];
      $mejores = ce_mejores_marcas($pdo, $uid, $comp);
      $anio = $n['fecha_nacimiento'] ? substr($n['fecha_nacimiento'], 0, 4) : null;
  ?>
  <div class="card ce-nadador" id="n<?= $uid ?>">
    <div class="ce-nadador__head">
      <div>
        <strong><?= e($n['nombre']) ?></strong>
        <span class="text-muted text-sm"><?= $n['sexo'] === 'F' ? 'F' : 'M' ?><?= $anio ? ' · ' . $anio : '' ?><?= $n['liga'] ? ' · ' . e(format_liga($n['liga'])) : '' ?></span>
      </div>
      <?php ce_form_borrar($cid, 'desconvocar', ['user_id' => $uid], '¿Quitar a ' . $n['nombre'] . ' de la convocatoria? Se borrarán sus pruebas y sus postas de relevo.', '<i class="bi bi-person-dash"></i> Quitar', 'btn btn-gray btn-sm'); ?>
    </div>

    <?php if ($suyas): ?>
    <div class="table-wrapper">
      <table class="ce-pruebas">
        <thead><tr><th>Prueba</th><th>Mejor marca</th><th>Mínima</th><th>Resultado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($suyas as $prueba => $f):
            $res = $resultados[$uid . '|' . $prueba] ?? null;
        ?>
          <tr>
            <td><?= e(format_prueba($prueba)) ?></td>
            <td>
              <?php if ($f['marca_seg']): ?>
                <strong><?= e($f['marca_tiempo']) ?></strong>
                <span class="text-muted text-sm"><?= e($f['marca_piscina']) ?> · <?= date('d/m/y', strtotime($f['marca_fecha'])) ?></span>
              <?php else: ?>
                <span class="text-muted">Sin marca</span>
              <?php endif; ?>
            </td>
            <td><?= render_ce_minima_badge(ce_estado_minima($pdo, $comp, $n, $f)) ?: '<span class="text-muted">—</span>' ?></td>
            <td>
              <?php if ($res): ?>
                <strong><?= e($res['tiempo']) ?></strong>
                <?php if ($f['marca_seg'] && $res['piscina'] === $f['marca_piscina']):
                    $d = (float)$res['tiempo_seg'] - (float)$f['marca_seg']; ?>
                  <span class="ce-diff <?= $d < 0 ? 'ce-diff--mejor' : 'ce-diff--peor' ?>"><?= $d < 0 ? '−' : '+' ?><?= number_format(abs($d), 2, ',', '') ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <form method="POST" action="/admin/competiciones" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="desinscribir_prueba">
                <input type="hidden" name="competicion_id" value="<?= $cid ?>">
                <input type="hidden" name="user_id" value="<?= $uid ?>">
                <input type="hidden" name="prueba" value="<?= e($prueba) ?>">
                <button type="submit" class="ce-x" title="Quitar prueba">×</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <form method="POST" action="/admin/competiciones" class="d-flex gap-2 ce-add-prueba">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="inscribir_prueba">
      <input type="hidden" name="competicion_id" value="<?= $cid ?>">
      <input type="hidden" name="user_id" value="<?= $uid ?>">
      <select name="prueba" class="form-control" required>
        <option value="">+ Añadir prueba…</option>
        <?php foreach (CE_PRUEBAS as $p):
            if (isset($suyas[$p])) continue;
            $m = $mejores[$p][$comp['piscina']] ?? null;
            $otra = $m ? null : ($mejores[$p] ?? null);
            $hint = $m ? ' — ' . $m['tiempo'] : ($otra ? ' — ' . reset($otra)['tiempo'] . ' (' . key($otra) . ')' : '');
        ?>
          <option value="<?= $p ?>"><?= e(format_prueba($p) . $hint) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Añadir</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'relevos'):
        $relevos = listar_relevos($pdo, $cid);
        $editId = (int)($_GET['relevo'] ?? 0);
        $edit = $editId ? obtener_relevo($pdo, $editId) : null;
        if ($edit && (int)$edit['competicion_id'] !== $cid) $edit = null;
?>
<?php ob_start(); ?>
<?php if (!$convocados): ?>
  <div class="card" style="padding:20px;"><p class="text-muted" style="margin:0;">Convoca nadadores primero para poder formar relevos.</p></div>
<?php else:
    $rv = $edit ?? ['id' => 0, 'prueba' => '4x50L', 'sexo' => 'M', 'nombre' => '', 'tiempo' => '', 'postas' => [], 'parciales' => []];
?>
<form method="POST" action="/admin/competiciones" class="card mb-6" id="form-relevo" style="padding:24px;scroll-margin-top:90px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar_relevo">
  <input type="hidden" name="competicion_id" value="<?= $cid ?>">
  <input type="hidden" name="relevo_id" value="<?= (int)$rv['id'] ?>">
  <h3 style="margin-top:0;"><?= $edit ? 'Editar relevo' : 'Nuevo relevo' ?></h3>
  <div class="d-flex gap-3 flex-wrap">
    <div class="form-group" style="flex:1;min-width:150px;">
      <label class="form-label">Relevo *</label>
      <select name="prueba" class="form-control" id="relevo-prueba">
        <?php foreach (CE_RELEVOS as $k => $_): ?>
          <option value="<?= $k ?>" <?= $rv['prueba'] === $k ? 'selected' : '' ?>><?= e(format_relevo($k)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:130px;">
      <label class="form-label">Categoría *</label>
      <select name="sexo" class="form-control" id="relevo-sexo">
        <?php foreach (CE_RELEVO_SEXOS as $k => $label): ?>
          <option value="<?= $k ?>" <?= $rv['sexo'] === $k ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:150px;">
      <label class="form-label">Nombre del equipo</label>
      <input type="text" name="nombre" class="form-control" maxlength="60" placeholder="Equipo A" value="<?= e($rv['nombre'] ?? '') ?>">
    </div>
  </div>
  <div class="d-flex gap-3 flex-wrap">
    <?php for ($pos = 1; $pos <= CE_RELEVO_MAX_POSTAS; $pos++): ?>
    <div class="form-group relevo-posta-grupo" data-pos="<?= $pos ?>" style="flex:1 1 22%;min-width:170px;">
      <label class="form-label relevo-posta-label" data-pos="<?= $pos ?>"><?= e(relevo_nombre_posta($rv['prueba'], $pos)) ?></label>
      <div class="d-flex gap-2">
        <select name="postas[<?= $pos ?>]" class="form-control relevo-posta" style="flex:1;min-width:0;">
          <option value="">— Sin asignar —</option>
          <?php foreach ($convocados as $n): ?>
            <option value="<?= (int)$n['id'] ?>" data-sexo="<?= e($n['sexo']) ?>" <?= ($rv['postas'][$pos] ?? 0) === (int)$n['id'] ? 'selected' : '' ?>><?= e($n['nombre']) ?> (<?= e($n['sexo']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="parciales[<?= $pos ?>]" class="form-control" maxlength="20" placeholder="Tiempo" inputmode="decimal" title="Tiempo de la posta (opcional)" value="<?= e($rv['parciales'][$pos] ?? '') ?>" style="width:90px;flex:none;">
      </div>
    </div>
    <?php endfor; ?>
  </div>
  <div class="form-group" style="max-width:200px;">
    <label class="form-label">Tiempo final</label>
    <input type="text" name="tiempo" class="form-control" placeholder="2:05.43" value="<?= e($rv['tiempo'] ?? '') ?>">
  </div>
  <div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary"><?= $edit ? 'Guardar relevo' : 'Crear relevo' ?></button>
    <?php if ($edit): ?><a href="/admin/competiciones?ver=<?= $cid ?>&tab=relevos#r<?= (int)$edit['id'] ?>" class="btn btn-gray">Cancelar</a><?php endif; ?>
  </div>
</form>
<script>
(function () {
  var prueba = document.getElementById('relevo-prueba');
  var sexo = document.getElementById('relevo-sexo');
  var estilos = ['Espalda', 'Braza', 'Mariposa', 'Libre'];
  function actualizar() {
    var esEstilos = prueba.value.slice(-1) === 'X';
    var numPostas = parseInt(prueba.value, 10);
    // Postas que sobran (relevo de 4): ocultas y sin enviar
    document.querySelectorAll('.relevo-posta-grupo').forEach(function (g) {
      var fuera = +g.dataset.pos > numPostas;
      g.style.display = fuera ? 'none' : '';
      g.querySelectorAll('select, input').forEach(function (el) { el.disabled = fuera; });
    });
    document.querySelectorAll('.relevo-posta-label').forEach(function (l) {
      var pos = +l.dataset.pos;
      l.textContent = esEstilos ? estilos[pos - 1] : 'Posta ' + pos;
    });
    document.querySelectorAll('.relevo-posta option[data-sexo]').forEach(function (o) {
      var oculto = sexo.value !== 'X' && o.dataset.sexo !== sexo.value;
      o.hidden = oculto;
      if (oculto && o.selected) o.parentNode.value = '';
    });
  }
  prueba.addEventListener('change', actualizar);
  sexo.addEventListener('change', actualizar);
  actualizar();
})();
</script>
<?php endif; ?>
<?php $htmlFormRelevo = ob_get_clean();
    if ($edit) echo $htmlFormRelevo; ?>
<?php if ($relevos): ?>
<div class="ce-relevos">
  <?php foreach ($relevos as $r):
      $est = estimar_relevo($pdo, $comp, $r);
  ?>
  <div class="card ce-relevo" id="r<?= (int)$r['id'] ?>">
    <div class="ce-nadador__head">
      <div>
        <strong><?= e(format_relevo($r['prueba'])) ?></strong>
        <span class="text-muted text-sm"><?= e(CE_RELEVO_SEXOS[$r['sexo']]) ?><?= $r['nombre'] ? ' · ' . e($r['nombre']) : '' ?></span>
      </div>
      <div class="d-flex gap-2">
        <a href="/admin/competiciones?ver=<?= $cid ?>&tab=relevos&relevo=<?= (int)$r['id'] ?>#form-relevo" class="btn btn-gray btn-sm"><i class="bi bi-pencil"></i></a>
        <?php ce_form_borrar($cid, 'eliminar_relevo', ['relevo_id' => (int)$r['id']], '¿Eliminar este relevo?', '<i class="bi bi-trash"></i>', 'btn btn-gray btn-sm'); ?>
      </div>
    </div>
    <table class="ce-pruebas">
      <tbody>
      <?php for ($pos = 1, $nPostas = relevo_num_postas($r['prueba']); $pos <= $nPostas; $pos++): $p = $r['postas'][$pos] ?? null; ?>
        <tr>
          <td class="text-muted text-sm" style="width:90px;"><?= e(relevo_nombre_posta($r['prueba'], $pos)) ?></td>
          <td><?= $p ? e($p['nombre']) : '<span class="text-muted">— sin asignar —</span>' ?></td>
          <td style="text-align:right;white-space:nowrap;">
            <?php if ($p): ?>
              <span class="text-muted text-sm" title="Mejor marca individual"><?= $est['postas'][$pos] !== null ? e(segundos_a_tiempo($est['postas'][$pos])) : 'sin marca ' . e(format_prueba(relevo_prueba_posta($r['prueba'], $pos))) ?></span>
              <?php if ($p['parcial']): ?><strong style="margin-left:8px;" title="Tiempo de la posta"><?= e($p['parcial']) ?></strong><?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    <div class="ce-relevo__foot">
      <span>Estimado (<?= e($comp['piscina']) ?>): <strong><?= $est['total'] !== null ? e(segundos_a_tiempo($est['total'])) : '—' ?></strong></span>
      <?php if ($r['tiempo']): ?>
        <span>Final: <strong><?= e($r['tiempo']) ?></strong>
          <?php if ($est['total'] !== null): $d = (float)$r['tiempo_seg'] - $est['total']; ?>
            <span class="ce-diff <?= $d < 0 ? 'ce-diff--mejor' : 'ce-diff--peor' ?>"><?= $d < 0 ? '−' : '+' ?><?= number_format(abs($d), 2, ',', '') ?></span>
          <?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="card text-center mb-6" style="padding:24px;"><p class="text-muted" style="margin:0;">No hay relevos todavía.</p></div>
<?php endif; ?>

<?php if (!$edit) echo $htmlFormRelevo; ?>

<?php elseif ($tab === 'minimas'):
        $bloques = agrupar_minimas(listar_minimas_competicion($pdo, $cid));
        $editIdx = isset($_GET['bloque']) ? (int)$_GET['bloque'] : -1;
        $editBloque = $bloques[$editIdx] ?? null;
?>
<?php if ($comp['minimas_fuente'] === 'rfen'): ?>
  <div class="card mb-6" style="padding:20px;">
    <p style="margin:0;">Esta competición usa las <strong>mínimas RFEN</strong> de la configuración del club (por edad y piscina). Para usar otras, cambia «Mínimas exigidas» en la pestaña <a href="/admin/competiciones?ver=<?= $cid ?>&tab=datos">Datos</a>.</p>
  </div>
<?php elseif ($comp['minimas_fuente'] === 'ninguna'): ?>
  <div class="card mb-6" style="padding:20px;">
    <p style="margin:0;">Esta competición no exige mínimas. Para introducir las suyas, elige «Mínimas propias» en la pestaña <a href="/admin/competiciones?ver=<?= $cid ?>&tab=datos">Datos</a>.</p>
  </div>
<?php else: ?>

<?php foreach ($bloques as $i => $b): ?>
  <div class="card mb-6" style="padding:20px;">
    <div class="ce-nadador__head">
      <div>
        <strong><?= e($b['etiqueta'] ?? 'Mínimas') ?></strong>
        <span class="text-muted text-sm"><?= e(ce_rango_anios($b['anio_desde'] !== null ? (int)$b['anio_desde'] : null, $b['anio_hasta'] !== null ? (int)$b['anio_hasta'] : null)) ?> · Piscina <?= e($b['piscina']) ?></span>
      </div>
      <div class="d-flex gap-2">
        <a href="/admin/competiciones?ver=<?= $cid ?>&tab=minimas&bloque=<?= $i ?>#form-minimas" class="btn btn-gray btn-sm"><i class="bi bi-pencil"></i></a>
        <?php ce_form_borrar($cid, 'eliminar_minimas', [
            'etiqueta' => $b['etiqueta'] ?? '', 'anio_desde' => $b['anio_desde'] ?? '', 'anio_hasta' => $b['anio_hasta'] ?? '', 'piscina' => $b['piscina'],
        ], '¿Eliminar este bloque de mínimas?', '<i class="bi bi-trash"></i>', 'btn btn-gray btn-sm'); ?>
      </div>
    </div>
    <div class="table-wrapper">
      <table class="ce-pruebas" style="min-width:0;">
        <thead><tr><th>Prueba</th><th>Masculino</th><th>Femenino</th></tr></thead>
        <tbody>
        <?php foreach (CE_PRUEBAS as $p): if (empty($b['tiempos'][$p])) continue; ?>
          <tr>
            <td><?= e(format_prueba($p)) ?></td>
            <td><?= isset($b['tiempos'][$p]['M']) ? e(segundos_a_tiempo($b['tiempos'][$p]['M'])) : '—' ?></td>
            <td><?= isset($b['tiempos'][$p]['F']) ? e(segundos_a_tiempo($b['tiempos'][$p]['F'])) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php $eb = $editBloque ?? ['etiqueta' => '', 'anio_desde' => null, 'anio_hasta' => null, 'piscina' => $comp['piscina'], 'tiempos' => []]; ?>
<form method="POST" action="/admin/competiciones" class="card" id="form-minimas" style="padding:24px;">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="guardar_minimas">
  <input type="hidden" name="competicion_id" value="<?= $cid ?>">
  <?php if ($editBloque): ?>
    <input type="hidden" name="orig_etiqueta" value="<?= e($eb['etiqueta'] ?? '') ?>">
    <input type="hidden" name="orig_anio_desde" value="<?= e((string)($eb['anio_desde'] ?? '')) ?>">
    <input type="hidden" name="orig_anio_hasta" value="<?= e((string)($eb['anio_hasta'] ?? '')) ?>">
    <input type="hidden" name="orig_piscina" value="<?= e($eb['piscina']) ?>">
  <?php endif; ?>
  <h3 style="margin-top:0;"><?= $editBloque ? 'Editar bloque de mínimas' : 'Añadir bloque de mínimas' ?></h3>
  <p class="text-muted text-sm">Un bloque = una categoría (rango de años de nacimiento) y una piscina. Deja vacíos los años para que valga para todos. Rellena solo las pruebas que tengan mínima.</p>
  <div class="d-flex gap-3 flex-wrap">
    <div class="form-group" style="flex:2;min-width:180px;">
      <label class="form-label">Etiqueta</label>
      <input type="text" name="etiqueta" class="form-control" maxlength="60" placeholder="Infantil" value="<?= e($eb['etiqueta'] ?? '') ?>">
    </div>
    <div class="form-group" style="flex:1;min-width:110px;">
      <label class="form-label">Nacidos desde</label>
      <input type="number" name="anio_desde" class="form-control" min="1930" max="2100" placeholder="2011" value="<?= e((string)($eb['anio_desde'] ?? '')) ?>">
    </div>
    <div class="form-group" style="flex:1;min-width:110px;">
      <label class="form-label">Nacidos hasta</label>
      <input type="number" name="anio_hasta" class="form-control" min="1930" max="2100" placeholder="2012" value="<?= e((string)($eb['anio_hasta'] ?? '')) ?>">
    </div>
    <div class="form-group" style="flex:1;min-width:100px;">
      <label class="form-label">Piscina *</label>
      <select name="piscina" class="form-control">
        <?php foreach (CE_PISCINAS as $p): ?>
          <option value="<?= $p ?>" <?= $eb['piscina'] === $p ? 'selected' : '' ?>><?= $p ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="ce-minimas-grid">
    <div class="ce-minimas-grid__h">Prueba</div><div class="ce-minimas-grid__h">Masc.</div><div class="ce-minimas-grid__h">Fem.</div>
    <?php foreach (CE_PRUEBAS as $p): ?>
      <div class="ce-minimas-grid__p"><?= e(format_prueba($p)) ?></div>
      <?php foreach (['M', 'F'] as $sx): ?>
        <input type="text" name="t[<?= $p ?>][<?= $sx ?>]" class="form-control" placeholder="—" inputmode="decimal"
               value="<?= isset($eb['tiempos'][$p][$sx]) ? e(segundos_a_tiempo($eb['tiempos'][$p][$sx])) : '' ?>">
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:12px;margin-top:16px;">
    <button type="submit" class="btn btn-primary">Guardar mínimas</button>
    <?php if ($editBloque): ?><a href="/admin/competiciones?ver=<?= $cid ?>&tab=minimas" class="btn btn-gray">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php elseif ($tab === 'resultados'):
        if ($convocados) {
            $socios = $convocados;
        } else {
            $socios = $pdo->query("SELECT id, nombre FROM users WHERE rol='socio' AND estado='activo' AND nadador_activo=1 ORDER BY nombre")->fetchAll();
        }
?>
<div class="card mb-6" style="padding:24px;">
  <div class="d-flex gap-2 flex-wrap" style="justify-content:space-between;align-items:center;">
    <h3 style="margin:0;">Tiempos registrados (<?= count($tiempos) ?>)</h3>
    <?php if ($convocados): ?>
      <form method="POST" action="/admin/competiciones" class="js-loading">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="importar_rfen">
        <input type="hidden" name="competicion_id" value="<?= $cid ?>">
        <button type="submit" class="btn btn-gray btn-sm"><i class="bi bi-cloud-download"></i> Traer tiempos de RFEN</button>
      </form>
      <script>
      document.querySelector('form.js-loading').addEventListener('submit', function () {
        var b = this.querySelector('button');
        b.disabled = true;
        b.textContent = 'Consultando RFEN…';
      });
      </script>
    <?php endif; ?>
  </div>
  <p class="text-muted text-sm" style="margin:8px 0 16px;">
    Los tiempos de los convocados se importan solos de RFEN cada semana (competiciones de los últimos <?= CE_RFEN_DIAS_SINCRONIZAR ?> días). Los parciales no vienen de RFEN.
  </p>
  <?php if ($tiempos): ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Nadador</th><th>Prueba</th><th>Piscina</th><th>Tiempo</th><th>Parciales</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($tiempos as $t): ?>
            <tr>
              <td><?= e($t['nadador_nombre']) ?></td>
              <td><?= e(format_prueba($t['prueba'])) ?></td>
              <td><?= e($t['piscina']) ?></td>
              <td style="font-weight:600;"><?= e($t['tiempo']) ?><?php if ($t['origen'] === 'rfen'): ?> <span class="badge badge-blue" title="Importado de RFEN">RFEN</span><?php endif; ?></td>
              <td class="text-muted text-sm"><?= e($t['parciales'] ?? '—') ?></td>
              <td style="white-space:nowrap;">
                <a href="/admin/competicion_tiempo?id=<?= (int)$t['id'] ?>" class="btn btn-gray btn-sm">Ver / comentar</a>
                <?php ce_form_borrar($cid, 'eliminar_tiempo', ['tiempo_id' => (int)$t['id']], '¿Eliminar el tiempo de ' . $t['nadador_nombre'] . ' en ' . format_prueba($t['prueba']) . ' (' . $t['tiempo'] . ')? Se borrarán también sus comentarios.', '<i class="bi bi-trash"></i>', 'btn btn-gray btn-sm'); ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted">Sin tiempos registrados todavía.</p>
  <?php endif; ?>

  <form method="POST" action="/admin/competiciones" style="margin-top:20px;border-top:1px solid #eee;padding-top:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="agregar_tiempo">
    <input type="hidden" name="competicion_id" value="<?= $cid ?>">
    <div class="d-flex gap-3 flex-wrap">
      <div class="form-group" style="flex:2;min-width:200px;">
        <label class="form-label">Nadador *</label>
        <select name="user_id" class="form-control" required id="res-nadador">
          <option value="">— Selecciona —</option>
          <?php foreach ($socios as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:140px;">
        <label class="form-label">Prueba *</label>
        <select name="prueba" class="form-control" required id="res-prueba">
          <?php foreach (CE_PRUEBAS as $p): ?>
            <option value="<?= $p ?>"><?= format_prueba($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:100px;">
        <label class="form-label">Piscina *</label>
        <select name="piscina" class="form-control" required>
          <?php foreach (CE_PISCINAS as $p): ?>
            <option value="<?= $p ?>" <?= $comp['piscina'] === $p ? 'selected' : '' ?>><?= $p ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:120px;">
        <label class="form-label">Tiempo *</label>
        <input type="text" name="tiempo" class="form-control" placeholder="1:05.43" required>
      </div>
      <div class="form-group" style="flex:2;min-width:200px;">
        <label class="form-label">Parciales</label>
        <input type="text" name="parciales" class="form-control" placeholder="28.5 / 1:01.2 / ...">
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Añadir tiempo</button>
  </form>
</div>
<?php if ($inscripciones):
    // Al elegir nadador, la prueba salta a la primera que tiene inscrita
    $mapa = [];
    foreach ($inscripciones as $u => $ps) $mapa[$u] = array_keys($ps);
?>
<script>
(function () {
  var mapa = <?= json_encode($mapa) ?>;
  var nad = document.getElementById('res-nadador'), pr = document.getElementById('res-prueba');
  nad.addEventListener('change', function () {
    var ps = mapa[nad.value];
    if (ps && ps.length) pr.value = ps[0];
  });
})();
</script>
<?php endif; ?>

<?php else: /* datos */ ?>
<?php ce_form_datos($comp); ?>

<div style="text-align:right;margin-top:24px;">
  <?php ce_form_borrar($cid, 'eliminar', ['id' => $cid], '¿Eliminar la competición? Se borrarán también la convocatoria, relevos, mínimas, tiempos y comentarios. No se puede deshacer.', '<i class="bi bi-trash"></i> Eliminar competición', 'btn btn-sm ce-btn-danger'); ?>
</div>
<?php endif; ?>

<div id="ce-modal" class="ce-modal" hidden>
  <div class="ce-modal__box">
    <h3 style="margin:0 0 12px;">Confirmar</h3>
    <p id="ce-modal-msg"></p>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
      <button type="button" class="btn btn-gray" id="ce-modal-no">Cancelar</button>
      <button type="button" class="btn ce-btn-danger" id="ce-modal-si">Eliminar</button>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('ce-modal'), pendiente = null;
  document.querySelectorAll('form.js-confirm').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.ok) return;
      ev.preventDefault();
      pendiente = f;
      document.getElementById('ce-modal-msg').textContent = f.dataset.msg;
      modal.hidden = false;
    });
  });
  document.getElementById('ce-modal-no').onclick = function () { modal.hidden = true; pendiente = null; };
  document.getElementById('ce-modal-si').onclick = function () {
    if (!pendiente) return;
    pendiente.dataset.ok = '1';
    pendiente.submit();
  };
  modal.addEventListener('click', function (ev) { if (ev.target === modal) { modal.hidden = true; pendiente = null; } });
})();
</script>
<?php
        return;
    }
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h1 style="margin:0;">Competiciones</h1>
  <a href="/admin/competiciones?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva competición</a>
</div>
<?php render_flash(); ?>

<?php if (!$competiciones): ?>
  <div class="card text-center" style="padding:32px;">
    <p class="text-muted">No hay competiciones registradas todavía.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Fecha</th><th>Nombre</th><th>Lugar</th><th>Piscina</th><th>Convocados</th><th>Relevos</th><th>Tiempos</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($competiciones as $c): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
              <td style="font-weight:600;"><?= e($c['nombre']) ?></td>
              <td class="text-muted"><?= e($c['lugar'] ?? '—') ?></td>
              <td><?= e($c['piscina']) ?></td>
              <td><?= (int)$c['num_convocados'] ?></td>
              <td><?= (int)$c['num_relevos'] ?></td>
              <td><?= (int)$c['num_tiempos'] ?></td>
              <td><a href="/admin/competiciones?ver=<?= (int)$c['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php
});
render_footer();
