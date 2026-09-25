<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/encuestas.php';

require_admin_area(['director_tecnico']);

const COM_LIGAS = ['benjamin', 'alevin', 'infantil', 'junior', 'absoluto', 'master'];

function obtener_comunicacion(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT c.*, u.nombre AS admin_nombre,
               (SELECT COUNT(*) FROM comunicaciones_leidas WHERE comunicacion_id = c.id) AS lecturas,
               (SELECT COUNT(*) FROM encuestas_envios WHERE comunicacion_id = c.id) AS envios
        FROM comunicaciones c
        JOIN users u ON u.id = c.admin_id
        WHERE c.id = ?
    ');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Mensajes siempre; encuestas solo mientras nadie haya respondido
function comunicacion_editable(array $c): bool
{
    return $c['tipo'] === 'mensaje' || (int)$c['envios'] === 0;
}

function num_destinatarios(PDO $pdo, array $c): int
{
    if ($c['destinatario_tipo'] === 'individual') return 1;
    $sql = "SELECT COUNT(*) FROM users WHERE estado = 'activo' AND rol = 'socio'";
    if ($c['destinatario_tipo'] === 'liga') {
        $stmt = $pdo->prepare($sql . ' AND liga = ?');
        $stmt->execute([$c['destinatario_valor']]);
    } else {
        $stmt = $pdo->query($sql);
    }
    return (int)$stmt->fetchColumn();
}

function destinatario_label(array $c, array $usuarios_por_id): string
{
    if ($c['destinatario_tipo'] === 'todos') return 'Todos';
    if ($c['destinatario_tipo'] === 'liga') return 'Liga: ' . format_liga($c['destinatario_valor']);
    return $usuarios_por_id[$c['destinatario_valor']] ?? 'Individual';
}

// datetime-local (Y-m-d\TH:i) → DATETIME o null
function parse_fecha_cierre(string $raw, array &$errors): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw);
    if (!$dt) { $errors[] = 'Fecha de cierre inválida.'; return null; }
    return $dt->format('Y-m-d H:i:00');
}

// ── POST ─────────────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear' || $action === 'actualizar') {
        $editando = $action === 'actualizar' ? obtener_comunicacion($pdo, (int)($_POST['comunicacion_id'] ?? 0)) : null;
        if ($action === 'actualizar' && (!$editando || !comunicacion_editable($editando))) {
            flash('Esta comunicación ya no se puede editar (tiene respuestas).', 'warning');
            header('Location: /admin/comunicaciones');
            exit;
        }

        // Al editar, el tipo no cambia
        $tipo       = $editando ? $editando['tipo'] : ($_POST['tipo'] ?? 'mensaje');
        $titulo     = trim($_POST['titulo'] ?? '');
        $contenido  = trim($_POST['contenido'] ?? '');
        $dest_tipo  = $_POST['destinatario_tipo'] ?? 'todos';
        $dest_valor = $_POST['destinatario_valor'] ?? null;
        $fecha_cierre = $tipo === 'encuesta' ? parse_fecha_cierre($_POST['fecha_cierre'] ?? '', $errors) : null;

        if (!$titulo) $errors[] = 'El título es obligatorio.';
        elseif (mb_strlen($titulo) > 255) $errors[] = 'El título no puede superar 255 caracteres.';
        if (!$contenido) $errors[] = 'El contenido es obligatorio.';
        elseif (strlen($contenido) > 65000) $errors[] = 'El contenido es demasiado largo.';
        if (!in_array($tipo, ['mensaje', 'encuesta'], true)) $errors[] = 'Tipo inválido.';
        if (!in_array($dest_tipo, ['todos', 'liga', 'individual'], true)) $errors[] = 'Destinatario inválido.';
        if ($dest_tipo === 'liga' && !in_array($dest_valor, COM_LIGAS, true)) $errors[] = 'Selecciona una liga.';
        if ($dest_tipo === 'individual' && empty($dest_valor)) $errors[] = 'Selecciona un usuario.';
        if ($dest_tipo === 'todos') $dest_valor = null;

        $preguntas = [];
        if ($tipo === 'encuesta') {
            $preguntas = enc_normalizar_preguntas(json_decode($_POST['preguntas_json'] ?? '', true), $errors);
        }

        if (!$errors) {
            // Transacción: si algo falla no queda la encuesta a medias
            $pdo->beginTransaction();
            if ($editando) {
                $com_id = (int)$editando['id'];
                $pdo->prepare('UPDATE comunicaciones SET titulo=?, contenido=?, destinatario_tipo=?, destinatario_valor=?, fecha_cierre=? WHERE id=?')
                    ->execute([$titulo, $contenido, $dest_tipo, $dest_valor ?: null, $fecha_cierre, $com_id]);
            } else {
                $pdo->prepare('INSERT INTO comunicaciones (tipo, titulo, contenido, destinatario_tipo, destinatario_valor, admin_id, fecha_cierre) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$tipo, $titulo, $contenido, $dest_tipo, $dest_valor ?: null, current_user()['id'], $fecha_cierre]);
                $com_id = (int)$pdo->lastInsertId();
            }
            if ($tipo === 'encuesta') enc_guardar_preguntas($pdo, $com_id, $preguntas);
            $pdo->commit();

            flash($editando ? 'Comunicación actualizada.' : 'Comunicación enviada correctamente.', 'success');
            header('Location: /admin/comunicaciones' . ($tipo === 'encuesta' ? '?ver=' . $com_id : ''));
            exit;
        }
    } elseif ($action === 'eliminar') {
        $id = (int)($_POST['comunicacion_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM comunicaciones WHERE id=?')->execute([$id]);
            flash('Comunicación eliminada.', 'warning');
        }
        header('Location: /admin/comunicaciones');
        exit;
    } elseif (in_array($action, ['cerrar', 'reabrir', 'fecha_cierre'], true)) {
        $id = (int)($_POST['comunicacion_id'] ?? 0);
        if ($action === 'cerrar') {
            $pdo->prepare("UPDATE comunicaciones SET cerrada=1 WHERE id=? AND tipo='encuesta'")->execute([$id]);
            flash('Encuesta cerrada: ya no admite respuestas.', 'info');
        } elseif ($action === 'reabrir') {
            // Reabrir también quita una fecha de cierre ya pasada
            $pdo->prepare("UPDATE comunicaciones SET cerrada=0, fecha_cierre = IF(fecha_cierre <= NOW(), NULL, fecha_cierre) WHERE id=? AND tipo='encuesta'")->execute([$id]);
            flash('Encuesta reabierta.', 'success');
        } else {
            $fecha = parse_fecha_cierre($_POST['fecha_cierre'] ?? '', $errors);
            if (!$errors) {
                $pdo->prepare("UPDATE comunicaciones SET fecha_cierre=? WHERE id=? AND tipo='encuesta'")->execute([$fecha, $id]);
                flash($fecha ? 'Fecha de cierre guardada.' : 'Fecha de cierre eliminada.', 'success');
            } else {
                flash(implode(' ', $errors), 'danger');
            }
        }
        header('Location: /admin/comunicaciones?ver=' . $id);
        exit;
    }
}

// Usuarios para selector y para mostrar destinatario individual
$usuarios = $pdo->query("SELECT id, nombre, liga FROM users WHERE estado='activo' AND rol='socio' ORDER BY nombre")->fetchAll();
$usuarios_por_id = array_column($usuarios, 'nombre', 'id');

// ── Vista ────────────────────────────────────────────────────────────────────
$vista = 'listado';
$detalle = null;
$resultados = [];
$num_dest = 0;
$form = null; // datos del editor

if (isset($_GET['ver'])) {
    $detalle = obtener_comunicacion($pdo, (int)$_GET['ver']);
    if ($detalle) {
        $vista = 'detalle';
        if ($detalle['tipo'] === 'encuesta') {
            $resultados = enc_resultados($pdo, (int)$detalle['id']);
            $num_dest = num_destinatarios($pdo, $detalle);
        }
    }
} elseif (isset($_GET['nueva']) || isset($_GET['editar']) || $errors) {
    $vista = 'editor';
    $editando = null;
    $editar_id = (int)($_GET['editar'] ?? ($_POST['comunicacion_id'] ?? 0));
    if ($editar_id) {
        $editando = obtener_comunicacion($pdo, $editar_id);
        if (!$editando || !comunicacion_editable($editando)) {
            flash('Esta comunicación ya no se puede editar (tiene respuestas).', 'warning');
            header('Location: /admin/comunicaciones' . ($editando ? '?ver=' . $editando['id'] : ''));
            exit;
        }
    }

    if ($errors) {
        // Repoblar con lo enviado para no perder el trabajo
        $form = [
            'id'                 => $editando['id'] ?? null,
            'tipo'               => $editando['tipo'] ?? ($_POST['tipo'] ?? 'mensaje'),
            'titulo'             => $_POST['titulo'] ?? '',
            'contenido'          => $_POST['contenido'] ?? '',
            'destinatario_tipo'  => $_POST['destinatario_tipo'] ?? 'todos',
            'destinatario_valor' => (string)($_POST['destinatario_valor'] ?? ''),
            'fecha_cierre'       => $_POST['fecha_cierre'] ?? '',
            'preguntas'          => json_decode($_POST['preguntas_json'] ?? '[]', true) ?: [],
        ];
    } elseif ($editando) {
        $form = [
            'id'                 => $editando['id'],
            'tipo'               => $editando['tipo'],
            'titulo'             => $editando['titulo'],
            'contenido'          => $editando['contenido'],
            'destinatario_tipo'  => $editando['destinatario_tipo'],
            'destinatario_valor' => (string)$editando['destinatario_valor'],
            'fecha_cierre'       => $editando['fecha_cierre'] ? date('Y-m-d\TH:i', strtotime($editando['fecha_cierre'])) : '',
            'preguntas'          => enc_preguntas_para_editor(enc_cargar_preguntas($pdo, (int)$editando['id'])),
        ];
    } else {
        $form = [
            'id' => null, 'tipo' => ($_GET['nueva'] ?? '') === 'encuesta' ? 'encuesta' : 'mensaje',
            'titulo' => '', 'contenido' => '', 'destinatario_tipo' => 'todos', 'destinatario_valor' => '',
            'fecha_cierre' => '', 'preguntas' => [],
        ];
    }
}

$comunicaciones = [];
if ($vista === 'listado') {
    $comunicaciones = $pdo->query('
        SELECT c.*, u.nombre AS admin_nombre,
               (SELECT COUNT(*) FROM comunicaciones_leidas WHERE comunicacion_id = c.id) AS lecturas,
               (SELECT COUNT(*) FROM encuestas_envios WHERE comunicacion_id = c.id) AS envios
        FROM comunicaciones c
        JOIN users u ON u.id = c.admin_id
        ORDER BY c.created_at DESC
    ')->fetchAll();
}

// Badge de estado de una encuesta
function estado_encuesta_badge(array $c): string
{
    if (!empty($c['cerrada'])) return '<span class="badge badge-gray">Cerrada</span>';
    if (!empty($c['fecha_cierre']) && strtotime($c['fecha_cierre']) <= time()) return '<span class="badge badge-gray">Cerrada</span>';
    if (!empty($c['fecha_cierre'])) return '<span class="badge badge-warning">Hasta ' . date('d/m H:i', strtotime($c['fecha_cierre'])) . '</span>';
    return '<span class="badge badge-success">Abierta</span>';
}

render_header('Comunicaciones', 'admin-comunicaciones');
render_admin_layout('comunicaciones', function() use ($vista, $comunicaciones, $usuarios, $usuarios_por_id, $detalle, $resultados, $num_dest, $form, $errors) {
?>

<h1>Comunicaciones</h1>
<?php render_flash(); ?>

<?php if ($vista === 'detalle'): ?>
<!-- ── Detalle / resultados ─────────────────────────────────────────────── -->
<div class="card mb-6">
  <div class="d-flex flex-wrap gap-2" style="align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div class="d-flex flex-wrap gap-2" style="align-items:center;">
      <span class="badge <?= $detalle['tipo'] === 'encuesta' ? 'badge-success' : 'badge-blue' ?>"><?= $detalle['tipo'] === 'encuesta' ? 'Encuesta' : 'Mensaje' ?></span>
      <?php if ($detalle['tipo'] === 'encuesta'): ?><?= estado_encuesta_badge($detalle) ?><?php endif; ?>
      <span class="text-muted text-sm"><?= e(destinatario_label($detalle, $usuarios_por_id)) ?></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <?php if (comunicacion_editable($detalle)): ?>
        <a href="/admin/comunicaciones?editar=<?= $detalle['id'] ?>" class="btn btn-secondary btn-sm"><i class="bi bi-pencil"></i> Editar</a>
      <?php endif; ?>
      <a href="/admin/comunicaciones" class="btn btn-gray btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
  </div>
  <h2 style="margin-bottom:8px;"><?= e($detalle['titulo']) ?></h2>
  <p class="text-muted text-sm" style="margin-bottom:16px;">Enviado por <?= e($detalle['admin_nombre']) ?> el <?= date('d/m/Y H:i', strtotime($detalle['created_at'])) ?> · <?= (int)$detalle['lecturas'] ?> lectura(s)</p>
  <div style="line-height:1.6;"><?= nl2br(e($detalle['contenido'])) ?></div>
</div>

<?php if ($detalle['tipo'] === 'encuesta'): ?>
  <?php $abierta = enc_esta_abierta($detalle); ?>
  <div class="card mb-6">
    <div class="gf-stats">
      <div class="gf-stat">
        <div class="gf-stat-num"><?= (int)$detalle['envios'] ?></div>
        <div class="gf-stat-label">respuesta<?= (int)$detalle['envios'] !== 1 ? 's' : '' ?><?= $num_dest ? ' de ' . $num_dest . ' destinatario' . ($num_dest !== 1 ? 's' : '') : '' ?></div>
      </div>
      <?php if ($num_dest): ?>
        <div class="gf-stat-bar"><div style="width:<?= min(100, round((int)$detalle['envios'] / $num_dest * 100)) ?>%;"></div></div>
      <?php endif; ?>
    </div>

    <div class="d-flex flex-wrap gap-2" style="align-items:flex-end;margin-top:16px;">
      <form method="POST" class="d-flex gap-2" style="align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="fecha_cierre">
        <input type="hidden" name="comunicacion_id" value="<?= $detalle['id'] ?>">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Cerrar automáticamente el</label>
          <input type="datetime-local" name="fecha_cierre" class="form-control" value="<?= $detalle['fecha_cierre'] ? date('Y-m-d\TH:i', strtotime($detalle['fecha_cierre'])) : '' ?>">
        </div>
        <button class="btn btn-gray btn-sm" style="margin-bottom:4px;">Guardar</button>
      </form>
      <div style="flex:1;"></div>
      <form method="POST" data-confirm="<?= $abierta ? '¿Cerrar la encuesta? Los socios no podrán responder.' : '¿Reabrir la encuesta?' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $abierta ? 'cerrar' : 'reabrir' ?>">
        <input type="hidden" name="comunicacion_id" value="<?= $detalle['id'] ?>">
        <button class="btn <?= $abierta ? 'btn-warning' : 'btn-success' ?> btn-sm">
          <i class="bi <?= $abierta ? 'bi-lock' : 'bi-unlock' ?>"></i> <?= $abierta ? 'Cerrar encuesta' : 'Reabrir encuesta' ?>
        </button>
      </form>
      <a href="/admin/comunicacion_exportar.php?id=<?= $detalle['id'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-download"></i> Exportar a Excel (CSV)</a>
    </div>
    <?php if (!comunicacion_editable($detalle)): ?>
      <p class="text-muted text-sm" style="margin-top:12px;"><i class="bi bi-info-circle"></i> Las preguntas no se pueden editar porque ya hay respuestas.</p>
    <?php endif; ?>
  </div>

  <?php foreach ($resultados as $pi => $pq): ?>
    <div class="card mb-4 gf-result">
      <div class="gf-answer-title"><?= ($pi + 1) ?>. <?= nl2br(e($pq['texto'])) ?></div>
      <div class="text-muted text-sm" style="margin:4px 0 14px;">
        <?= e(ENC_TIPOS[$pq['tipo']] ?? $pq['tipo']) ?> · <?= $pq['respondieron'] ?> respuesta<?= $pq['respondieron'] !== 1 ? 's' : '' ?><?= !(int)$pq['obligatoria'] ? ' · opcional' : '' ?>
        <?php if ($pq['tipo'] === 'casillas'): ?> · varias respuestas por socio<?php endif; ?>
      </div>

      <?php if (in_array($pq['tipo'], ENC_TIPOS_CON_OPCIONES, true)): ?>
        <?php $max_votos = max(1, ...array_map(fn($o) => $o['votos'], $pq['opciones'] ?: [['votos' => 0]])); ?>
        <?php foreach ($pq['opciones'] as $op): ?>
          <?php $pct = $pq['respondieron'] > 0 ? round($op['votos'] / $pq['respondieron'] * 100) : 0; ?>
          <div class="gf-bar-row">
            <div class="gf-bar-label"><?= $op['es_libre'] ? '<em>Otro</em>' : e($op['texto']) ?></div>
            <div class="gf-bar"><div style="width:<?= round($op['votos'] / $max_votos * 100) ?>%;"></div></div>
            <div class="gf-bar-val"><?= $op['votos'] ?> <span class="text-muted">(<?= $pct ?>%)</span></div>
          </div>
        <?php endforeach; ?>
      <?php elseif ($pq['tipo'] === 'escala'): ?>
        <?php $max_d = max(1, ...array_values($pq['distribucion'])); ?>
        <div class="gf-histo">
          <?php foreach ($pq['distribucion'] as $v => $cnt): ?>
            <div class="gf-histo-col" title="<?= $v ?>: <?= $cnt ?> respuesta(s)">
              <div class="gf-histo-track">
                <div class="gf-histo-val"><?= $cnt ?: '' ?></div>
                <div class="gf-histo-bar" style="height:<?= round($cnt / $max_d * 85) ?>%;"></div>
              </div>
              <div class="gf-histo-x"><?= $v ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex text-muted text-sm" style="justify-content:space-between;margin-top:6px;">
          <span><?= e($pq['escala_label_min'] ?? '') ?></span>
          <?php if ($pq['media'] !== null): ?><strong style="color:var(--text);">Media: <?= number_format($pq['media'], 2, ',', '') ?></strong><?php endif; ?>
          <span><?= e($pq['escala_label_max'] ?? '') ?></span>
        </div>
      <?php endif; ?>

      <?php if (in_array($pq['tipo'], ['texto_corto', 'texto_libre'], true)): ?>
        <?php if (!$pq['textos']): ?>
          <p class="text-muted text-sm">Sin respuestas todavía.</p>
        <?php else: ?>
          <div class="gf-textos">
            <?php foreach ($pq['textos'] as $ti => $t): ?>
              <div class="gf-texto<?= $ti >= 5 ? ' gf-extra' : '' ?>" <?= $ti >= 5 ? 'hidden' : '' ?>>
                <div><?= nl2br(e($t['texto'])) ?></div>
                <div class="text-muted text-sm"><?= e($t['nombre']) ?> · <?= date('d/m/Y H:i', strtotime($t['fecha'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if (count($pq['textos']) > 5): ?>
            <button type="button" class="btn btn-gray btn-sm" style="margin-top:8px;" onclick="this.previousElementSibling.querySelectorAll('.gf-extra').forEach(el => el.hidden = false); this.remove();">
              Ver las <?= count($pq['textos']) ?> respuestas
            </button>
          <?php endif; ?>
        <?php endif; ?>
      <?php elseif ($pq['detalle']): ?>
        <details style="margin-top:12px;">
          <summary style="cursor:pointer;font-size:13px;font-weight:600;">Ver quién respondió (<?= count($pq['detalle']) ?>)</summary>
          <div class="table-wrapper" style="margin-top:8px;">
            <table>
              <thead><tr><th>Socio</th><th>Respuesta</th><th>Fecha</th></tr></thead>
              <tbody>
                <?php foreach ($pq['detalle'] as $d): ?>
                  <tr>
                    <td><?= e($d['nombre']) ?></td>
                    <td><?= e($d['respuesta']) ?></td>
                    <td class="text-sm text-muted"><?= date('d/m/Y H:i', strtotime($d['fecha'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php elseif ($vista === 'editor'): ?>
<!-- ── Editor (nueva / editar) ──────────────────────────────────────────── -->
<?php $es_encuesta = $form['tipo'] === 'encuesta'; $dv = $form['destinatario_valor']; $dt = $form['destinatario_tipo']; ?>
<div class="gf-wrap">
  <div class="d-flex" style="align-items:center;justify-content:space-between;margin-bottom:16px;">
    <h2 style="font-size:17px;font-weight:700;"><?= $form['id'] ? 'Editar comunicación' : 'Nueva comunicación' ?></h2>
    <a href="/admin/comunicaciones<?= $form['id'] ? '?ver=' . $form['id'] : '' ?>" class="btn btn-gray btn-sm"><i class="bi bi-x-lg"></i> Cancelar</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" id="formCom" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $form['id'] ? 'actualizar' : 'crear' ?>">
    <?php if ($form['id']): ?><input type="hidden" name="comunicacion_id" value="<?= (int)$form['id'] ?>"><?php endif; ?>
    <input type="hidden" name="preguntas_json" id="preguntasJson">

    <!-- Ajustes -->
    <div class="card mb-4">
      <div class="d-flex gap-3 flex-wrap">
        <div class="form-group" style="margin:0;min-width:150px;">
          <label class="form-label">Tipo</label>
          <select name="tipo" class="form-control" id="tipoSelect" onchange="toggleEncuesta()" <?= $form['id'] ? 'disabled' : '' ?>>
            <option value="mensaje">Mensaje</option>
            <option value="encuesta" <?= $es_encuesta ? 'selected' : '' ?>>Encuesta</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;min-width:150px;">
          <label class="form-label">Destinatario</label>
          <select name="destinatario_tipo" class="form-control" id="destTipo" onchange="toggleDest()">
            <option value="todos">Todos los socios</option>
            <option value="liga" <?= $dt === 'liga' ? 'selected' : '' ?>>Por liga</option>
            <option value="individual" <?= $dt === 'individual' ? 'selected' : '' ?>>Individual</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;min-width:180px;display:none;" id="destLiga">
          <label class="form-label">Liga</label>
          <select name="destinatario_valor" class="form-control" id="destLigaSelect">
            <option value="">— Seleccionar —</option>
            <?php foreach (COM_LIGAS as $lg): ?>
              <option value="<?= $lg ?>" <?= $dt === 'liga' && $dv === $lg ? 'selected' : '' ?>><?= e(format_liga($lg)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;min-width:220px;display:none;" id="destUser">
          <label class="form-label">Usuario</label>
          <select name="destinatario_valor" class="form-control searchable" id="destUserSelect">
            <option value="">— Seleccionar —</option>
            <?php foreach ($usuarios as $u): ?>
              <option value="<?= $u['id'] ?>" <?= $dt === 'individual' && $dv === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?><?= $u['liga'] ? ' · ' . e(format_liga($u['liga'])) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group encuesta-only" style="margin:0;min-width:200px;">
          <label class="form-label">Cierre automático (opcional)</label>
          <input type="datetime-local" name="fecha_cierre" class="form-control" value="<?= e($form['fecha_cierre']) ?>">
        </div>
      </div>
    </div>

    <!-- Cabecera tipo formulario -->
    <div class="gf-header">
      <input type="text" name="titulo" id="tituloInput" class="gf-header-title" maxlength="255" placeholder="Título" value="<?= e($form['titulo']) ?>" aria-label="Título">
      <textarea name="contenido" id="contenidoInput" class="gf-header-desc" rows="2" placeholder="Mensaje o descripción de la encuesta" aria-label="Contenido"><?= e($form['contenido']) ?></textarea>
    </div>

    <!-- Preguntas -->
    <div id="encuestaContainer" class="encuesta-only">
      <div id="preguntasList"></div>
      <button type="button" class="gf-add-q" onclick="EncuestaBuilder.add()"><i class="bi bi-plus-circle"></i> Añadir pregunta</button>
    </div>

    <div id="clientErrors" class="alert alert-danger" style="display:none;margin-top:16px;"></div>

    <div class="d-flex gap-2 flex-wrap" style="margin-top:20px;">
      <button type="submit" class="btn btn-primary"><i class="bi <?= $form['id'] ? 'bi-check-lg' : 'bi-send' ?>"></i> <?= $form['id'] ? 'Guardar cambios' : 'Enviar' ?></button>
      <button type="button" class="btn btn-secondary encuesta-only" onclick="abrirPreview()"><i class="bi bi-eye"></i> Vista previa</button>
    </div>
  </form>
</div>

<!-- Modal vista previa -->
<div id="previewModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)cerrarPreview()">
  <div class="modal-box" style="max-width:760px;background:var(--bg);">
    <div class="modal-header" style="background:white;">
      <h3>Vista previa · así lo verá el socio</h3>
      <button type="button" class="modal-close" onclick="cerrarPreview()" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body" id="previewBody"></div>
  </div>
</div>

<script src="/assets/js/encuesta-builder.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/encuesta-builder.js') ?>"></script>
<script>
function esEncuesta() { return document.getElementById('tipoSelect').value === 'encuesta'; }

function toggleEncuesta() {
  const on = esEncuesta();
  document.querySelectorAll('.encuesta-only').forEach(function(el) { el.style.display = on ? '' : 'none'; });
  if (on) { EncuestaBuilder.ensureOne(); EncuestaBuilder.refresh(); }
}

function toggleDest() {
  const tipo = document.getElementById('destTipo').value;
  document.getElementById('destLiga').style.display = tipo === 'liga' ? '' : 'none';
  document.getElementById('destUser').style.display = tipo === 'individual' ? '' : 'none';
  document.getElementById('destLigaSelect').disabled = tipo !== 'liga';
  document.getElementById('destUserSelect').disabled = tipo !== 'individual';
  if (tipo === 'individual') {
    const sel = document.getElementById('destUserSelect');
    if (typeof initSearchable === 'function') initSearchable(sel);
  }
}

function abrirPreview() {
  const body = document.getElementById('previewBody');
  body.replaceChildren(EncuestaBuilder.preview(
    document.getElementById('tituloInput').value.trim(),
    document.getElementById('contenidoInput').value.trim()
  ));
  document.getElementById('previewModal').style.display = 'flex';
}
function cerrarPreview() { document.getElementById('previewModal').style.display = 'none'; }
document.addEventListener('keydown', function(ev) { if (ev.key === 'Escape') cerrarPreview(); });

// Autoajuste del textarea de contenido
(function() {
  const ta = document.getElementById('contenidoInput');
  function grow() { ta.style.height = 'auto'; ta.style.height = Math.max(ta.scrollHeight, 60) + 'px'; }
  ta.addEventListener('input', grow); grow();
})();

// Enter en el título no envía el formulario a medio escribir
document.getElementById('tituloInput').addEventListener('keydown', function(ev) {
  if (ev.key === 'Enter') ev.preventDefault();
});

EncuestaBuilder.init({
  list: document.getElementById('preguntasList'),
  input: document.getElementById('preguntasJson'),
  form: document.getElementById('formCom'),
  data: <?= json_encode($form['preguntas'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
});

// Validación en cliente con mensajes visibles (el navegador no bloquea en silencio)
document.getElementById('formCom').addEventListener('submit', function(ev) {
  const errs = [];
  if (!document.getElementById('tituloInput').value.trim()) errs.push('El título es obligatorio.');
  if (!document.getElementById('contenidoInput').value.trim()) errs.push('El contenido es obligatorio.');
  const dt = document.getElementById('destTipo').value;
  if (dt === 'liga' && !document.getElementById('destLigaSelect').value) errs.push('Selecciona una liga.');
  if (dt === 'individual' && !document.getElementById('destUserSelect').value) errs.push('Selecciona un usuario.');
  if (esEncuesta()) {
    JSON.parse(document.getElementById('preguntasJson').value || '[]').forEach(function(q, i) {
      if (!q.texto.trim()) errs.push('La pregunta ' + (i + 1) + ' no tiene texto.');
      if (['opciones', 'casillas', 'desplegable'].indexOf(q.tipo) !== -1 &&
          q.opciones.filter(function(o) { return o.es_libre || o.texto.trim(); }).length < 2) {
        errs.push('La pregunta ' + (i + 1) + ' necesita al menos 2 opciones.');
      }
    });
  }
  const box = document.getElementById('clientErrors');
  if (errs.length) {
    ev.preventDefault();
    ev.stopImmediatePropagation();
    box.replaceChildren(...errs.map(function(t) { const d = document.createElement('div'); d.textContent = t; return d; }));
    box.style.display = '';
    box.scrollIntoView({ block: 'center', behavior: 'smooth' });
  } else {
    box.style.display = 'none';
  }
});

// Mantener viva la sesión mientras se redacta (evita perderlo todo por sesión caducada)
setInterval(function() {
  fetch('/admin/comunicaciones', { method: 'HEAD', credentials: 'same-origin' }).catch(function() {});
}, 5 * 60 * 1000);

toggleEncuesta();
toggleDest();
</script>

<?php else: ?>
<!-- ── Listado ──────────────────────────────────────────────────────────── -->
<div class="d-flex gap-2 flex-wrap mb-4">
  <a href="/admin/comunicaciones?nueva=mensaje" class="btn btn-primary"><i class="bi bi-envelope"></i> Nuevo mensaje</a>
  <a href="/admin/comunicaciones?nueva=encuesta" class="btn btn-secondary"><i class="bi bi-ui-checks"></i> Nueva encuesta</a>
</div>

<div class="card">
  <h2 style="font-size:15px;font-weight:700;margin-bottom:16px;">Comunicaciones enviadas</h2>

  <?php if (!$comunicaciones): ?>
    <p class="text-muted">No hay comunicaciones enviadas aún.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>Tipo</th><th>Título</th><th>Destinatario</th><th>Estado</th><th>Lecturas</th><th>Fecha</th><th>Acción</th></tr>
        </thead>
        <tbody>
          <?php foreach ($comunicaciones as $c): ?>
            <tr>
              <td><span class="badge <?= $c['tipo'] === 'encuesta' ? 'badge-success' : 'badge-blue' ?>"><?= $c['tipo'] === 'encuesta' ? 'Encuesta' : 'Mensaje' ?></span></td>
              <td><a href="/admin/comunicaciones?ver=<?= $c['id'] ?>" style="font-weight:600;"><?= e($c['titulo']) ?></a></td>
              <td class="text-sm"><?= e(destinatario_label($c, $usuarios_por_id)) ?></td>
              <td class="text-sm">
                <?php if ($c['tipo'] === 'encuesta'): ?>
                  <?= estado_encuesta_badge($c) ?> <span class="text-muted"><?= (int)$c['envios'] ?> resp.</span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-sm"><?= (int)$c['lecturas'] ?></td>
              <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
              <td style="white-space:nowrap;">
                <?php if (comunicacion_editable($c)): ?>
                  <a href="/admin/comunicaciones?editar=<?= $c['id'] ?>" class="btn btn-gray btn-sm" title="Editar"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <form method="POST" style="display:inline;" data-confirm="¿Eliminar esta comunicación?<?= (int)$c['envios'] ? ' Se borrarán también sus ' . (int)$c['envios'] . ' respuestas.' : '' ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="eliminar">
                  <input type="hidden" name="comunicacion_id" value="<?= $c['id'] ?>">
                  <button class="btn btn-danger btn-sm" title="Eliminar"><i class="bi bi-trash3"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
});
render_footer();
