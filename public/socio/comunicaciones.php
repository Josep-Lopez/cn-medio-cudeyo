<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/encuestas.php';

require_login();
$user = current_user();

// Comunicación visible para este usuario (o null)
function comunicacion_para_usuario(PDO $pdo, int $id, array $user): ?array
{
    $stmt = $pdo->prepare("
        SELECT * FROM comunicaciones
        WHERE id = ?
          AND (destinatario_tipo = 'todos'
               OR (destinatario_tipo = 'liga' AND destinatario_valor = ?)
               OR (destinatario_tipo = 'individual' AND destinatario_valor = ?))
    ");
    $stmt->execute([$id, $user['liga'], $user['id']]);
    return $stmt->fetch() ?: null;
}

// ── POST: responder encuesta ─────────────────────────────────────────────────
$errores_resp = [];
$old_resp = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'responder') {
        $com_id = (int)($_POST['comunicacion_id'] ?? 0);
        $com = $com_id ? comunicacion_para_usuario($pdo, $com_id, $user) : null;

        if (!$com || $com['tipo'] !== 'encuesta') {
            header('Location: /socio/comunicaciones');
            exit;
        }
        if (!enc_esta_abierta($com)) {
            flash('La encuesta está cerrada y ya no admite respuestas.', 'warning');
        } elseif (enc_ha_respondido($pdo, $com_id, (int)$user['id'])) {
            flash('Ya habías respondido esta encuesta.', 'info');
        } else {
            $raw = is_array($_POST['respuestas'] ?? null) ? $_POST['respuestas'] : [];
            $filas = enc_validar_respuestas(enc_cargar_preguntas($pdo, $com_id), $raw, $errores_resp);
            if (!$errores_resp) {
                enc_guardar_respuestas($pdo, $com_id, (int)$user['id'], $filas)
                    ? flash('¡Gracias! Tus respuestas se han enviado.', 'success')
                    : flash('Ya habías respondido esta encuesta.', 'info');
            } else {
                // Volver a mostrar el formulario con lo que había marcado
                $old_resp = $raw;
                $_GET['ver'] = $com_id;
            }
        }
        if (!$errores_resp) {
            header('Location: /socio/comunicaciones?ver=' . $com_id);
            exit;
        }
    }
}

// ── Comunicaciones dirigidas a este usuario ──────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM comunicaciones_leidas WHERE comunicacion_id=c.id AND user_id=?) as leida,
           (SELECT COUNT(*) FROM encuestas_envios WHERE comunicacion_id=c.id AND user_id=?) as respondida
    FROM comunicaciones c
    WHERE c.destinatario_tipo = 'todos'
       OR (c.destinatario_tipo = 'liga' AND c.destinatario_valor = ?)
       OR (c.destinatario_tipo = 'individual' AND c.destinatario_valor = ?)
    ORDER BY c.created_at DESC
");
$stmt->execute([$user['id'], $user['id'], $user['liga'], $user['id']]);
$comunicaciones = $stmt->fetchAll();

$no_leidas = 0;
foreach ($comunicaciones as $c) {
    if (!(int)$c['leida']) $no_leidas++;
}

// ── Ver detalle ──────────────────────────────────────────────────────────────
$detalle = null;
$preguntas = [];
$ya_respondio = false;
$abierta = false;
$mis_respuestas = [];
if (isset($_GET['ver'])) {
    $detalle = comunicacion_para_usuario($pdo, (int)$_GET['ver'], $user);

    if ($detalle) {
        $pdo->prepare('INSERT IGNORE INTO comunicaciones_leidas (comunicacion_id, user_id) VALUES (?,?)')
            ->execute([$detalle['id'], $user['id']]);

        if ($detalle['tipo'] === 'encuesta') {
            $ya_respondio = enc_ha_respondido($pdo, (int)$detalle['id'], (int)$user['id']);
            $abierta = enc_esta_abierta($detalle);
            if ($ya_respondio || !$abierta) {
                $preguntas = enc_resultados($pdo, (int)$detalle['id']);
                $mis_respuestas = enc_respuestas_usuario($pdo, (int)$detalle['id'], (int)$user['id']);
            } else {
                $preguntas = enc_cargar_preguntas($pdo, (int)$detalle['id']);
            }
        }
    }
}

// Valor previo de un campo del formulario (tras error de validación)
function old_resp(array $old, int $pid, string $key): mixed
{
    return $old[$pid][$key] ?? null;
}

render_header('Comunicaciones', 'socio-comunicaciones');
?>

<div class="container page-content" style="max-width:780px;">

  <?php render_flash(); ?>

  <?php if ($detalle): ?>
  <!-- ── Detalle ───────────────────────────────────────────────────────────── -->
  <div style="margin-bottom:16px;">
    <a href="/socio/comunicaciones" style="font-size:14px;color:var(--gray);text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
      <i class="bi bi-arrow-left"></i> Volver a comunicaciones
    </a>
  </div>

  <?php if ($detalle['tipo'] !== 'encuesta'): ?>
    <div class="card">
      <div style="margin-bottom:12px;">
        <span class="badge badge-blue">Mensaje</span>
        <span class="text-muted text-sm" style="margin-left:8px;"><?= date('d/m/Y H:i', strtotime($detalle['created_at'])) ?></span>
      </div>
      <h1 style="font-size:22px;margin-bottom:12px;"><?= e($detalle['titulo']) ?></h1>
      <div style="line-height:1.7;font-size:15px;"><?= nl2br(e($detalle['contenido'])) ?></div>
      <?php if (!empty($detalle['link_url'])): ?>
        <div style="margin-top:16px;">
          <a href="<?= e($detalle['link_url']) ?>" class="btn btn-primary btn-sm">Ver detalle →</a>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <div class="gf-form">
      <div class="gf-form-header">
        <h1><?= e($detalle['titulo']) ?></h1>
        <div class="gf-form-desc"><?= nl2br(e($detalle['contenido'])) ?></div>
        <div class="gf-form-meta">
          <?= date('d/m/Y', strtotime($detalle['created_at'])) ?>
          <?php if ($abierta && !empty($detalle['fecha_cierre'])): ?>
            · <i class="bi bi-clock"></i> Se cierra el <?= date('d/m/Y \a \l\a\s H:i', strtotime($detalle['fecha_cierre'])) ?>
          <?php endif; ?>
        </div>
        <?php if (!$ya_respondio && $abierta && array_filter(array_column($preguntas, 'obligatoria'))): ?>
          <div class="gf-req-note"><span class="gf-req">*</span> Obligatorio</div>
        <?php endif; ?>
      </div>

      <?php if ($ya_respondio || !$abierta): ?>
        <!-- Encuesta respondida o cerrada: resultados + mis respuestas -->
        <div class="alert <?= $ya_respondio ? 'alert-success' : 'alert-warning' ?>" style="margin-bottom:12px;">
          <?php if ($ya_respondio): ?>
            <i class="bi bi-check-circle"></i> Ya has respondido esta encuesta. Estos son los resultados hasta ahora.
          <?php else: ?>
            <i class="bi bi-lock"></i> Esta encuesta está cerrada y ya no admite respuestas.
          <?php endif; ?>
        </div>

        <?php foreach ($preguntas as $pi => $pq): ?>
          <?php $mia = $mis_respuestas[$pq['id']] ?? null; ?>
          <div class="gf-answer">
            <div class="gf-answer-title"><?= nl2br(e($pq['texto'])) ?></div>
            <?php if (!empty($pq['descripcion'])): ?><div class="gf-answer-desc"><?= nl2br(e($pq['descripcion'])) ?></div><?php endif; ?>

            <?php if (in_array($pq['tipo'], ENC_TIPOS_CON_OPCIONES, true)): ?>
              <?php $max_votos = max(1, ...array_map(fn($o) => $o['votos'], $pq['opciones'] ?: [['votos' => 0]])); ?>
              <?php foreach ($pq['opciones'] as $op): ?>
                <?php $pct = $pq['respondieron'] > 0 ? round($op['votos'] / $pq['respondieron'] * 100) : 0; $es_mia = $mia && in_array((int)$op['id'], $mia['opciones'], true); ?>
                <div class="gf-bar-row<?= $es_mia ? ' is-mine' : '' ?>">
                  <div class="gf-bar-label"><?= $op['es_libre'] ? '<em>Otro</em>' : e($op['texto']) ?><?= $es_mia ? ' <strong>(tu respuesta)</strong>' : '' ?></div>
                  <div class="gf-bar"><div style="width:<?= round($op['votos'] / $max_votos * 100) ?>%;"></div></div>
                  <div class="gf-bar-val"><?= $op['votos'] ?> <span class="text-muted">(<?= $pct ?>%)</span></div>
                </div>
              <?php endforeach; ?>
            <?php elseif ($pq['tipo'] === 'escala'): ?>
              <?php $max_d = max(1, ...array_values($pq['distribucion'])); ?>
              <div class="gf-histo">
                <?php foreach ($pq['distribucion'] as $v => $cnt): ?>
                  <div class="gf-histo-col<?= $mia && $mia['valor'] === $v ? ' is-mine' : '' ?>">
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
                <?php if ($mia && $mia['valor'] !== null): ?><strong style="color:var(--text);">Tu respuesta: <?= $mia['valor'] ?></strong><?php endif; ?>
                <span><?= e($pq['escala_label_max'] ?? '') ?></span>
              </div>
            <?php endif; ?>

            <?php if ($mia && $mia['texto'] !== null && $mia['texto'] !== ''): ?>
              <div class="gf-my-text"><strong>Tu respuesta:</strong> <?= nl2br(e($mia['texto'])) ?></div>
            <?php elseif (!$mia && $ya_respondio): ?>
              <div class="text-muted text-sm" style="margin-top:8px;">No respondiste a esta pregunta.</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

      <?php else: ?>
        <!-- Formulario de respuesta -->
        <?php if ($errores_resp): ?>
          <div class="alert alert-danger">
            <?php foreach ($errores_resp as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST" id="formEncuesta">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="responder">
          <input type="hidden" name="comunicacion_id" value="<?= $detalle['id'] ?>">

          <?php foreach ($preguntas as $pi => $pq): ?>
            <?php
              $pid = (int)$pq['id'];
              $req = (int)$pq['obligatoria'] === 1;
              $base = 'respuestas[' . $pid . ']';
              $old_oid = (int)old_resp($old_resp, $pid, 'opcion_id');
              $old_ops = array_map('intval', (array)(old_resp($old_resp, $pid, 'opciones') ?? []));
              $old_txt = (string)(old_resp($old_resp, $pid, 'texto') ?? '');
              $old_val = old_resp($old_resp, $pid, 'valor');
            ?>
            <div class="gf-answer" data-tipo="<?= e($pq['tipo']) ?>" data-obligatoria="<?= $req ? 1 : 0 ?>">
              <div class="gf-answer-title"><?= nl2br(e($pq['texto'])) ?><?php if ($req): ?><span class="gf-req"> *</span><?php endif; ?></div>
              <?php if (!empty($pq['descripcion'])): ?><div class="gf-answer-desc"><?= nl2br(e($pq['descripcion'])) ?></div><?php endif; ?>

              <?php if ($pq['tipo'] === 'opciones' || $pq['tipo'] === 'casillas'): ?>
                <?php $multi = $pq['tipo'] === 'casillas'; ?>
                <?php foreach ($pq['opciones'] as $op): ?>
                  <?php $checked = $multi ? in_array((int)$op['id'], $old_ops, true) : $old_oid === (int)$op['id']; ?>
                  <label class="gf-choice">
                    <input type="<?= $multi ? 'checkbox' : 'radio' ?>"
                           name="<?= $base . ($multi ? '[opciones][]' : '[opcion_id]') ?>"
                           value="<?= (int)$op['id'] ?>"
                           <?= !$multi && $req ? 'required' : '' ?>
                           <?= $op['es_libre'] ? 'data-otro="1"' : '' ?>
                           <?= $checked ? 'checked' : '' ?>>
                    <span><?= $op['es_libre'] ? 'Otro:' : e($op['texto']) ?></span>
                    <?php if ($op['es_libre']): ?>
                      <input type="text" name="<?= $base ?>[texto]" class="gf-otro-input" maxlength="<?= ENC_MAX_TEXTO_CORTO ?>" value="<?= e($old_txt) ?>" aria-label="Especifica tu respuesta">
                    <?php endif; ?>
                  </label>
                <?php endforeach; ?>

              <?php elseif ($pq['tipo'] === 'desplegable'): ?>
                <select name="<?= $base ?>[opcion_id]" class="form-control gf-select" <?= $req ? 'required' : '' ?>>
                  <option value="">Elige</option>
                  <?php foreach ($pq['opciones'] as $op): ?>
                    <option value="<?= (int)$op['id'] ?>" <?= $old_oid === (int)$op['id'] ? 'selected' : '' ?>><?= e($op['texto']) ?></option>
                  <?php endforeach; ?>
                </select>

              <?php elseif ($pq['tipo'] === 'escala'): ?>
                <div class="gf-scale">
                  <span class="gf-scale-end"><?= e($pq['escala_label_min'] ?? '') ?></span>
                  <?php for ($v = (int)$pq['escala_min']; $v <= (int)$pq['escala_max']; $v++): ?>
                    <label class="gf-scale-opt">
                      <span><?= $v ?></span>
                      <input type="radio" name="<?= $base ?>[valor]" value="<?= $v ?>" <?= $req ? 'required' : '' ?> <?= $old_val !== null && (int)$old_val === $v ? 'checked' : '' ?>>
                    </label>
                  <?php endfor; ?>
                  <span class="gf-scale-end"><?= e($pq['escala_label_max'] ?? '') ?></span>
                </div>
                <div class="gf-scale-legend"><span><?= e($pq['escala_label_min'] ?? '') ?></span><span><?= e($pq['escala_label_max'] ?? '') ?></span></div>

              <?php elseif ($pq['tipo'] === 'texto_corto'): ?>
                <input type="text" name="<?= $base ?>[texto]" class="form-control" maxlength="<?= ENC_MAX_TEXTO_CORTO ?>" placeholder="Tu respuesta" value="<?= e($old_txt) ?>" <?= $req ? 'required' : '' ?>>

              <?php else: ?>
                <textarea name="<?= $base ?>[texto]" class="form-control gf-autogrow" rows="3" maxlength="<?= ENC_MAX_PARRAFO ?>" placeholder="Tu respuesta" <?= $req ? 'required' : '' ?>><?= e($old_txt) ?></textarea>
              <?php endif; ?>

              <div class="gf-answer-error" hidden><i class="bi bi-exclamation-circle"></i> <span></span></div>
            </div>
          <?php endforeach; ?>

          <div class="d-flex" style="justify-content:space-between;align-items:center;margin-top:8px;">
            <button type="submit" class="btn btn-primary">Enviar respuestas</button>
            <button type="button" class="gf-link" onclick="limpiarEncuesta()">Borrar respuestas</button>
          </div>
        </form>

        <script>
        (function() {
          const form = document.getElementById('formEncuesta');

          function setError(card, msg) {
            const box = card.querySelector('.gf-answer-error');
            card.classList.toggle('has-error', !!msg);
            box.hidden = !msg;
            box.querySelector('span').textContent = msg || '';
          }

          // «Otro»: el texto solo es obligatorio si la opción está marcada
          function syncOtro(card) {
            const otro = card.querySelector('input[data-otro]');
            const txt = card.querySelector('.gf-otro-input');
            if (!otro || !txt) return;
            txt.required = otro.checked;
          }

          form.querySelectorAll('.gf-answer').forEach(function(card) {
            card.addEventListener('change', function() { syncOtro(card); setError(card, ''); });
            card.addEventListener('input', function() { setError(card, ''); });
            // Escribir en «Otro» marca la opción automáticamente
            const txt = card.querySelector('.gf-otro-input');
            if (txt) txt.addEventListener('input', function() {
              const otro = card.querySelector('input[data-otro]');
              if (txt.value.trim() && !otro.checked) { otro.checked = true; syncOtro(card); }
            });
            syncOtro(card);
          });

          form.querySelectorAll('.gf-autogrow').forEach(function(ta) {
            function grow() { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; }
            ta.addEventListener('input', grow); grow();
          });

          form.addEventListener('submit', function(ev) {
            let primera = null;
            form.querySelectorAll('.gf-answer').forEach(function(card) {
              let msg = '';
              const req = card.dataset.obligatoria === '1';
              if (card.dataset.tipo === 'casillas' && req && !card.querySelector('input[type="checkbox"]:checked')) {
                msg = 'Marca al menos una opción.';
              }
              card.querySelectorAll('input, select, textarea').forEach(function(el) {
                if (!msg && !el.checkValidity()) {
                  msg = el.classList.contains('gf-otro-input') ? 'Especifica tu respuesta en «Otro».' : 'Esta pregunta es obligatoria.';
                }
              });
              setError(card, msg);
              if (msg && !primera) primera = card;
            });
            if (primera) {
              ev.preventDefault();
              primera.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
          });
          form.noValidate = true; // mostramos nuestros propios mensajes

          window.limpiarEncuesta = function() {
            form.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(function(el) { el.checked = false; });
            form.querySelectorAll('input[type="text"], textarea').forEach(function(el) { el.value = ''; });
            form.querySelectorAll('select').forEach(function(el) { el.selectedIndex = 0; });
            form.querySelectorAll('.gf-answer').forEach(function(card) { syncOtro(card); setError(card, ''); });
          };
        })();
        </script>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- ── Listado ───────────────────────────────────────────────────────────── -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <h1><i class="bi bi-bell"></i> Comunicaciones</h1>
    <?php if ($no_leidas > 0): ?>
      <span class="badge badge-blue"><?= $no_leidas ?> sin leer</span>
    <?php endif; ?>
  </div>

  <?php if (!$comunicaciones): ?>
    <div class="card text-center" style="padding:40px;">
      <div style="font-size:36px;color:var(--blue);margin-bottom:12px;"><i class="bi bi-inbox"></i></div>
      <p class="text-muted">No tienes comunicaciones.</p>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <?php foreach ($comunicaciones as $c): ?>
        <?php
          $es_enc = $c['tipo'] === 'encuesta';
          $pendiente = $es_enc && !(int)$c['respondida'] && enc_esta_abierta($c);
        ?>
        <a href="/socio/comunicaciones?ver=<?= $c['id'] ?>" class="card" style="text-decoration:none;display:flex;align-items:center;gap:14px;padding:16px 20px;transition:box-shadow 0.15s;<?= !(int)$c['leida'] ? 'border-left:3px solid var(--blue);' : '' ?>"
           onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.08)'" onmouseout="this.style.boxShadow=''">
          <div style="font-size:20px;color:<?= $es_enc ? 'var(--green)' : 'var(--blue)' ?>;flex-shrink:0;">
            <i class="bi <?= $es_enc ? 'bi-bar-chart-line' : 'bi-envelope-fill' ?>"></i>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:<?= !(int)$c['leida'] ? '700' : '500' ?>;font-size:14px;color:var(--text);margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= e($c['titulo']) ?>
            </div>
            <div class="text-muted text-sm"><?= date('d/m/Y', strtotime($c['created_at'])) ?> · <?= $es_enc ? 'Encuesta' : 'Mensaje' ?></div>
          </div>
          <?php if ($es_enc): ?>
            <?php if ((int)$c['respondida']): ?>
              <span class="badge badge-success">Respondida</span>
            <?php elseif ($pendiente): ?>
              <span class="badge badge-warning">Pendiente</span>
            <?php else: ?>
              <span class="badge badge-gray">Cerrada</span>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!(int)$c['leida']): ?>
            <div style="width:8px;height:8px;border-radius:50%;background:var(--blue);flex-shrink:0;"></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>

</div>

<?php render_footer(); ?>
