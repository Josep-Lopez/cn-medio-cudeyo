<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

// ── POST: crear comunicación ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $tipo = $_POST['tipo'] ?? 'mensaje';
        $titulo = trim($_POST['titulo'] ?? '');
        $contenido = trim($_POST['contenido'] ?? '');
        $dest_tipo = $_POST['destinatario_tipo'] ?? 'todos';
        $dest_valor = $_POST['destinatario_valor'] ?? null;
        $preguntas_raw = $_POST['preguntas'] ?? [];

        $errors = [];
        if (!$titulo) $errors[] = 'El título es obligatorio.';
        if (!$contenido) $errors[] = 'El contenido es obligatorio.';
        if (!in_array($tipo, ['mensaje', 'encuesta'])) $errors[] = 'Tipo inválido.';
        if (!in_array($dest_tipo, ['todos', 'liga', 'individual'])) $errors[] = 'Destinatario inválido.';
        if ($dest_tipo === 'liga' && empty($dest_valor)) $errors[] = 'Selecciona una liga.';
        if ($dest_tipo === 'individual' && empty($dest_valor)) $errors[] = 'Selecciona un usuario.';

        if ($tipo === 'encuesta') {
            if (empty($preguntas_raw)) {
                $errors[] = 'Una encuesta necesita al menos una pregunta.';
            } else {
                foreach ($preguntas_raw as $i => $pq) {
                    $texto_pq = trim($pq['texto'] ?? '');
                    $tipo_pq = $pq['tipo'] ?? 'opciones';
                    if (!$texto_pq) { $errors[] = 'La pregunta ' . ($i + 1) . ' no tiene texto.'; continue; }
                    if ($tipo_pq === 'opciones') {
                        $opts = array_filter(array_map('trim', $pq['opciones'] ?? []));
                        if (count($opts) < 2) $errors[] = 'La pregunta ' . ($i + 1) . ' necesita al menos 2 opciones.';
                    }
                }
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO comunicaciones (tipo, titulo, contenido, destinatario_tipo, destinatario_valor, admin_id) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$tipo, $titulo, $contenido, $dest_tipo, $dest_valor ?: null, current_user()['id']]);
            $com_id = $pdo->lastInsertId();

            if ($tipo === 'encuesta') {
                $stmtPq = $pdo->prepare('INSERT INTO encuestas_preguntas (comunicacion_id, texto, tipo, orden) VALUES (?,?,?,?)');
                $stmtOp = $pdo->prepare('INSERT INTO encuestas_opciones (pregunta_id, texto, es_libre, orden) VALUES (?,?,?,?)');

                foreach ($preguntas_raw as $orden => $pq) {
                    $texto_pq = trim($pq['texto'] ?? '');
                    $tipo_pq = $pq['tipo'] ?? 'opciones';
                    if (!$texto_pq) continue;

                    $stmtPq->execute([$com_id, $texto_pq, $tipo_pq, $orden]);
                    $pq_id = $pdo->lastInsertId();

                    if ($tipo_pq === 'opciones') {
                        $opts = $pq['opciones'] ?? [];
                        $opts_libre = $pq['opciones_libre'] ?? [];
                        foreach ($opts as $oi => $opt) {
                            $opt = trim($opt);
                            if (!$opt) continue;
                            $es_libre = (int)($opts_libre[$oi] ?? 0);
                            $stmtOp->execute([$pq_id, $opt, $es_libre, $oi]);
                        }
                    }
                }
            }
            flash('Comunicación enviada correctamente.', 'success');
            header('Location: /admin/comunicaciones');
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
    }
}

// ── Listado ──────────────────────────────────────────────────────────────────
$stmt = $pdo->query('
    SELECT c.*, u.nombre as admin_nombre,
           (SELECT COUNT(*) FROM comunicaciones_leidas WHERE comunicacion_id=c.id) as lecturas
    FROM comunicaciones c
    JOIN users u ON u.id = c.admin_id
    ORDER BY c.created_at DESC
');
$comunicaciones = $stmt->fetchAll();

// Usuarios para selector
$usuarios = $pdo->query("SELECT id, nombre, liga FROM users WHERE estado='activo' AND rol='socio' ORDER BY nombre")->fetchAll();

// ── Ver detalle ──────────────────────────────────────────────────────────────
$detalle = null;
$preguntas_detalle = [];
if (isset($_GET['ver'])) {
    $stmt = $pdo->prepare('SELECT c.*, u.nombre as admin_nombre, (SELECT COUNT(*) FROM comunicaciones_leidas WHERE comunicacion_id=c.id) as lecturas FROM comunicaciones c JOIN users u ON u.id=c.admin_id WHERE c.id=?');
    $stmt->execute([(int)$_GET['ver']]);
    $detalle = $stmt->fetch();

    if ($detalle && $detalle['tipo'] === 'encuesta') {
        $stmt = $pdo->prepare('SELECT * FROM encuestas_preguntas WHERE comunicacion_id=? ORDER BY orden');
        $stmt->execute([$detalle['id']]);
        $preguntas_detalle = $stmt->fetchAll();

        foreach ($preguntas_detalle as &$pq) {
            if ($pq['tipo'] === 'opciones') {
                $stmt = $pdo->prepare('SELECT o.*, (SELECT COUNT(*) FROM encuestas_respuestas WHERE opcion_id=o.id) as votos FROM encuestas_opciones o WHERE o.pregunta_id=? ORDER BY o.orden');
                $stmt->execute([$pq['id']]);
                $pq['opciones'] = $stmt->fetchAll();
            }
            $stmt = $pdo->prepare('
                SELECT er.*, u.nombre, eo.texto as opcion_texto
                FROM encuestas_respuestas er
                JOIN users u ON u.id = er.user_id
                LEFT JOIN encuestas_opciones eo ON eo.id = er.opcion_id
                WHERE er.pregunta_id=?
                ORDER BY er.created_at DESC
            ');
            $stmt->execute([$pq['id']]);
            $pq['respuestas'] = $stmt->fetchAll();
        }
        unset($pq);
    }
}

$errors = $errors ?? [];

render_header('Comunicaciones', 'admin-comunicaciones');
render_admin_layout('comunicaciones', function() use ($comunicaciones, $usuarios, $detalle, $preguntas_detalle, $errors) {
?>

<h1>Comunicaciones</h1>
<?php render_flash(); ?>

<?php if ($detalle): ?>
<!-- ── Detalle ─────────────────────────────────────────────────────────────── -->
<div class="card mb-6">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div>
      <span class="badge <?= $detalle['tipo'] === 'encuesta' ? 'badge-green' : 'badge-blue' ?>"><?= $detalle['tipo'] === 'encuesta' ? 'Encuesta' : 'Mensaje' ?></span>
      <span class="text-muted text-sm" style="margin-left:8px;">
        <?= $detalle['destinatario_tipo'] === 'todos' ? 'Todos' : ($detalle['destinatario_tipo'] === 'liga' ? 'Liga: ' . e(format_liga($detalle['destinatario_valor'])) : 'Individual') ?>
      </span>
    </div>
    <a href="/admin/comunicaciones" class="btn btn-gray btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>
  <h2 style="margin-bottom:8px;"><?= e($detalle['titulo']) ?></h2>
  <p class="text-muted text-sm" style="margin-bottom:16px;">Enviado por <?= e($detalle['admin_nombre']) ?> el <?= date('d/m/Y H:i', strtotime($detalle['created_at'])) ?> · <?= (int)$detalle['lecturas'] ?> lectura(s)</p>
  <div style="line-height:1.6;"><?= nl2br(e($detalle['contenido'])) ?></div>

  <?php if ($detalle['tipo'] === 'encuesta' && $preguntas_detalle): ?>
    <div style="margin-top:24px;border-top:1px solid #eee;padding-top:20px;">
      <?php foreach ($preguntas_detalle as $pi => $pq): ?>
        <div style="margin-bottom:24px;<?= $pi > 0 ? 'padding-top:16px;border-top:1px solid #f0f0f0;' : '' ?>">
          <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;">
            <?= ($pi + 1) ?>. <?= e($pq['texto']) ?>
            <span class="text-muted text-sm" style="font-weight:400;">(<?= $pq['tipo'] === 'texto_libre' ? 'texto libre' : 'opciones' ?>)</span>
          </h3>

          <?php if ($pq['tipo'] === 'opciones' && !empty($pq['opciones'])): ?>
            <?php $total_votos = array_sum(array_column($pq['opciones'], 'votos')); ?>
            <?php foreach ($pq['opciones'] as $op): ?>
              <?php $pct = $total_votos > 0 ? round(($op['votos'] / $total_votos) * 100) : 0; ?>
              <div style="margin-bottom:8px;">
                <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:3px;">
                  <span><?= e($op['texto']) ?></span>
                  <span class="text-muted"><?= $op['votos'] ?> (<?= $pct ?>%)</span>
                </div>
                <div style="background:#eee;border-radius:6px;height:8px;overflow:hidden;">
                  <div style="background:var(--blue);height:100%;width:<?= $pct ?>%;border-radius:6px;"></div>
                </div>
              </div>
            <?php endforeach; ?>
            <p class="text-muted text-sm">Total: <?= $total_votos ?> respuesta<?= $total_votos != 1 ? 's' : '' ?></p>
          <?php endif; ?>

          <?php if ($pq['respuestas']): ?>
            <details style="margin-top:10px;">
              <summary style="cursor:pointer;font-size:13px;font-weight:600;">Ver respuestas (<?= count($pq['respuestas']) ?>)</summary>
              <div class="table-wrapper" style="margin-top:8px;">
                <table>
                  <thead><tr><th>Socio</th><th>Respuesta</th><th>Fecha</th></tr></thead>
                  <tbody>
                    <?php foreach ($pq['respuestas'] as $r): ?>
                      <tr>
                        <td><?= e($r['nombre']) ?></td>
                        <td><?= $r['respuesta_texto'] ? e($r['respuesta_texto']) : e($r['opcion_texto'] ?? '') ?></td>
                        <td class="text-sm text-muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ── Crear nueva ─────────────────────────────────────────────────────────── -->
<div class="card mb-6">
  <h2 style="font-size:15px;font-weight:700;margin-bottom:16px;">Nueva comunicación</h2>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" id="formCom">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="crear">

    <div class="d-flex gap-3 flex-wrap" style="margin-bottom:16px;">
      <div class="form-group" style="margin:0;min-width:150px;">
        <label class="form-label">Tipo</label>
        <select name="tipo" class="form-control" id="tipoSelect" onchange="toggleEncuesta()">
          <option value="mensaje">Mensaje</option>
          <option value="encuesta">Encuesta</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:150px;">
        <label class="form-label">Destinatario</label>
        <select name="destinatario_tipo" class="form-control" id="destTipo" onchange="toggleDest()">
          <option value="todos">Todos los socios</option>
          <option value="liga">Por liga</option>
          <option value="individual">Individual</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:180px;display:none;" id="destLiga">
        <label class="form-label">Liga</label>
        <select name="destinatario_valor" class="form-control" id="destLigaSelect">
          <option value="">— Seleccionar —</option>
          <option value="benjamin">Benjamín</option>
          <option value="alevin">Alevín</option>
          <option value="infantil">Infantil</option>
          <option value="junior">Junior</option>
          <option value="absoluto">Absoluto</option>
          <option value="master">Master</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:220px;display:none;" id="destUser">
        <label class="form-label">Usuario</label>
        <select name="destinatario_valor" class="form-control searchable" id="destUserSelect">
          <option value="">— Seleccionar —</option>
          <?php foreach ($usuarios as $u): ?>
            <option value="<?= $u['id'] ?>"><?= e($u['nombre']) ?><?= $u['liga'] ? ' · ' . e(format_liga($u['liga'])) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Título *</label>
      <input type="text" name="titulo" class="form-control" required placeholder="Asunto de la comunicación">
    </div>
    <div class="form-group">
      <label class="form-label">Contenido *</label>
      <textarea name="contenido" class="form-control" rows="4" required placeholder="Escribe el mensaje o descripción de la encuesta..."></textarea>
    </div>

    <!-- Preguntas de encuesta -->
    <div id="encuestaContainer" style="display:none;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <label class="form-label" style="margin:0;font-weight:700;">Preguntas</label>
        <button type="button" class="btn btn-gray btn-sm" onclick="addPregunta()"><i class="bi bi-plus"></i> Añadir pregunta</button>
      </div>
      <div id="preguntasList"></div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Enviar</button>
  </form>
</div>

<!-- ── Listado ─────────────────────────────────────────────────────────────── -->
<div class="card">
  <h2 style="font-size:15px;font-weight:700;margin-bottom:16px;">Comunicaciones enviadas</h2>

  <?php if (!$comunicaciones): ?>
    <p class="text-muted">No hay comunicaciones enviadas aún.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>Tipo</th><th>Título</th><th>Destinatario</th><th>Lecturas</th><th>Fecha</th><th>Acción</th></tr>
        </thead>
        <tbody>
          <?php foreach ($comunicaciones as $c): ?>
            <tr>
              <td><span class="badge <?= $c['tipo'] === 'encuesta' ? 'badge-green' : 'badge-blue' ?>"><?= $c['tipo'] === 'encuesta' ? 'Encuesta' : 'Mensaje' ?></span></td>
              <td><a href="/admin/comunicaciones?ver=<?= $c['id'] ?>" style="font-weight:600;"><?= e($c['titulo']) ?></a></td>
              <td class="text-sm">
                <?php if ($c['destinatario_tipo'] === 'todos'): ?>Todos
                <?php elseif ($c['destinatario_tipo'] === 'liga'): ?>Liga: <?= e(format_liga($c['destinatario_valor'])) ?>
                <?php else: ?>Individual
                <?php endif; ?>
              </td>
              <td class="text-sm"><?= (int)$c['lecturas'] ?></td>
              <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
              <td>
                <form method="POST" style="display:inline;" data-confirm="¿Eliminar esta comunicación?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="eliminar">
                  <input type="hidden" name="comunicacion_id" value="<?= $c['id'] ?>">
                  <button class="btn btn-danger btn-sm"><i class="bi bi-trash3"></i></button>
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

<script>
let preguntaCount = 0;

function toggleEncuesta() {
  const isEncuesta = document.getElementById('tipoSelect').value === 'encuesta';
  document.getElementById('encuestaContainer').style.display = isEncuesta ? '' : 'none';
  if (isEncuesta && preguntaCount === 0) addPregunta();
  // Habilitar/deshabilitar required en inputs ocultos
  document.querySelectorAll('#preguntasList input[type="text"]').forEach(function(input) {
    input.required = isEncuesta;
  });
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

function addPregunta() {
  const idx = preguntaCount++;
  const container = document.getElementById('preguntasList');
  const div = document.createElement('div');
  div.className = 'pregunta-block';
  div.dataset.idx = idx;
  div.style.cssText = 'border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-bottom:12px;background:#fafbfc;';

  const header = document.createElement('div');
  header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;';

  const title = document.createElement('strong');
  title.style.fontSize = '14px';
  title.textContent = 'Pregunta ' + (idx + 1);

  const removeBtn = document.createElement('button');
  removeBtn.type = 'button';
  removeBtn.className = 'btn btn-danger btn-sm';
  removeBtn.textContent = 'Eliminar';
  removeBtn.onclick = function() { div.remove(); renumerarPreguntas(); };

  header.appendChild(title);
  header.appendChild(removeBtn);
  div.appendChild(header);

  // Texto pregunta
  const textoGroup = document.createElement('div');
  textoGroup.className = 'form-group';
  const textoInput = document.createElement('input');
  textoInput.type = 'text';
  textoInput.name = 'preguntas[' + idx + '][texto]';
  textoInput.className = 'form-control';
  textoInput.placeholder = 'Escribe la pregunta...';
  textoInput.required = (document.getElementById('tipoSelect').value === 'encuesta');
  textoGroup.appendChild(textoInput);
  div.appendChild(textoGroup);

  // Tipo selector
  const tipoGroup = document.createElement('div');
  tipoGroup.className = 'form-group';
  tipoGroup.style.marginBottom = '10px';
  const tipoSelect = document.createElement('select');
  tipoSelect.name = 'preguntas[' + idx + '][tipo]';
  tipoSelect.className = 'form-control';
  tipoSelect.style.width = 'auto';
  const optOpciones = document.createElement('option');
  optOpciones.value = 'opciones';
  optOpciones.textContent = 'Con opciones';
  const optTexto = document.createElement('option');
  optTexto.value = 'texto_libre';
  optTexto.textContent = 'Solo texto libre';
  tipoSelect.appendChild(optOpciones);
  tipoSelect.appendChild(optTexto);
  tipoGroup.appendChild(tipoSelect);
  div.appendChild(tipoGroup);

  // Opciones container
  const optsContainer = document.createElement('div');
  optsContainer.className = 'opciones-container';

  const optsList = document.createElement('div');
  optsList.className = 'opciones-list';

  // 2 opciones por defecto
  for (let i = 0; i < 2; i++) {
    optsList.appendChild(createOpcionRow(idx, i + 1));
  }
  optsContainer.appendChild(optsList);

  const addBtn = document.createElement('button');
  addBtn.type = 'button';
  addBtn.className = 'btn btn-gray btn-sm';
  addBtn.style.marginTop = '6px';
  addBtn.textContent = '+ Añadir opción';
  addBtn.onclick = function() {
    const count = optsList.children.length + 1;
    optsList.appendChild(createOpcionRow(idx, count));
  };
  optsContainer.appendChild(addBtn);
  div.appendChild(optsContainer);

  // Botón añadir opción libre (solo una por pregunta)
  const addLibreBtn = document.createElement('button');
  addLibreBtn.type = 'button';
  addLibreBtn.className = 'btn btn-secondary btn-sm';
  addLibreBtn.style.marginTop = '6px';
  addLibreBtn.style.marginLeft = '8px';
  addLibreBtn.textContent = '+ Opción libre';
  addLibreBtn.onclick = function() {
    // Solo permitir una opción libre por pregunta
    if (optsList.querySelector('.opcion-libre-row')) return;
    optsList.appendChild(createOpcionLibreRow(idx));
    addLibreBtn.disabled = true;
    addLibreBtn.style.opacity = '0.5';
  };
  optsContainer.appendChild(addLibreBtn);

  // Guardar referencia para re-habilitar si se elimina la opción libre
  optsContainer.dataset.libreBtn = 'true';
  div._addLibreBtn = addLibreBtn;

  // Toggle tipo
  tipoSelect.onchange = function() {
    optsContainer.style.display = this.value === 'opciones' ? '' : 'none';
  };

  container.appendChild(div);
  renumerarPreguntas();
}

function renumerarPreguntas() {
  const blocks = document.querySelectorAll('#preguntasList .pregunta-block');
  blocks.forEach(function(block, i) {
    const title = block.querySelector('strong');
    if (title) title.textContent = 'Pregunta ' + (i + 1);
  });
}

function createOpcionRow(pregIdx, num) {
  const row = document.createElement('div');
  row.className = 'd-flex gap-2 mb-2 opcion-row';
  const input = document.createElement('input');
  input.type = 'text';
  input.name = 'preguntas[' + pregIdx + '][opciones][]';
  input.className = 'form-control';
  input.placeholder = 'Opción ' + num;
  const hidden = document.createElement('input');
  hidden.type = 'hidden';
  hidden.name = 'preguntas[' + pregIdx + '][opciones_libre][]';
  hidden.value = '0';
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-danger btn-sm';
  btn.title = 'Eliminar';
  btn.onclick = function() { row.remove(); };
  const icon = document.createElement('i');
  icon.className = 'bi bi-x-lg';
  btn.appendChild(icon);
  row.appendChild(input);
  row.appendChild(hidden);
  row.appendChild(btn);
  return row;
}

function createOpcionLibreRow(pregIdx) {
  const row = document.createElement('div');
  row.className = 'd-flex gap-2 mb-2 opcion-row opcion-libre-row';
  row.style.cssText = 'background:#f0fdf4;padding:8px 12px;border-radius:6px;align-items:center;';
  const hidden = document.createElement('input');
  hidden.type = 'hidden';
  hidden.name = 'preguntas[' + pregIdx + '][opciones][]';
  hidden.value = 'Otro (especificar)';
  const hiddenLibre = document.createElement('input');
  hiddenLibre.type = 'hidden';
  hiddenLibre.name = 'preguntas[' + pregIdx + '][opciones_libre][]';
  hiddenLibre.value = '1';
  const label = document.createElement('span');
  label.style.cssText = 'flex:1;font-size:14px;font-weight:500;';
  label.textContent = 'Otro (especificar) — el usuario escribirá su respuesta';
  const badge = document.createElement('span');
  badge.className = 'badge badge-green';
  badge.style.cssText = 'white-space:nowrap;font-size:11px;margin-right:8px;';
  badge.textContent = 'libre';
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-danger btn-sm';
  btn.title = 'Eliminar';
  btn.onclick = function() {
    // Re-habilitar botón de añadir opción libre
    const block = row.closest('.pregunta-block');
    if (block && block._addLibreBtn) {
      block._addLibreBtn.disabled = false;
      block._addLibreBtn.style.opacity = '';
    }
    row.remove();
  };
  const icon = document.createElement('i');
  icon.className = 'bi bi-x-lg';
  btn.appendChild(icon);
  row.appendChild(hidden);
  row.appendChild(hiddenLibre);
  row.appendChild(badge);
  row.appendChild(label);
  row.appendChild(btn);
  return row;
}

toggleDest();
</script>

<?php
});
render_footer();
