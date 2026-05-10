<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
$user = current_user();

// ── POST: responder encuesta ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'responder') {
        $com_id = (int)($_POST['comunicacion_id'] ?? 0);
        $respuestas = $_POST['respuestas'] ?? [];

        if ($com_id && $respuestas) {
            foreach ($respuestas as $pq_id => $resp) {
                $pq_id = (int)$pq_id;
                $opcion_id = !empty($resp['opcion_id']) ? (int)$resp['opcion_id'] : null;
                $texto = trim($resp['texto'] ?? '');

                if ($pq_id && ($opcion_id || $texto)) {
                    // Verificar que no ha respondido ya
                    $check = $pdo->prepare('SELECT id FROM encuestas_respuestas WHERE pregunta_id=? AND user_id=?');
                    $check->execute([$pq_id, $user['id']]);
                    if (!$check->fetch()) {
                        $pdo->prepare('INSERT INTO encuestas_respuestas (pregunta_id, opcion_id, user_id, respuesta_texto) VALUES (?,?,?,?)')
                            ->execute([$pq_id, $opcion_id, $user['id'], $texto ?: null]);
                    }
                }
            }
            flash('Respuestas enviadas.', 'success');
        }
        header('Location: /socio/comunicaciones?ver=' . $com_id);
        exit;
    }
}

// ── Comunicaciones dirigidas a este usuario ──────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM comunicaciones_leidas WHERE comunicacion_id=c.id AND user_id=?) as leida
    FROM comunicaciones c
    WHERE c.destinatario_tipo = 'todos'
       OR (c.destinatario_tipo = 'liga' AND c.destinatario_valor = ?)
       OR (c.destinatario_tipo = 'individual' AND c.destinatario_valor = ?)
    ORDER BY c.created_at DESC
");
$stmt->execute([$user['id'], $user['liga'], $user['id']]);
$comunicaciones = $stmt->fetchAll();

$no_leidas = 0;
foreach ($comunicaciones as $c) {
    if (!(int)$c['leida']) $no_leidas++;
}

// ── Ver detalle ──────────────────────────────────────────────────────────────
$detalle = null;
$preguntas_detalle = [];
$ya_respondio = false;
if (isset($_GET['ver'])) {
    $stmt = $pdo->prepare('SELECT * FROM comunicaciones WHERE id=?');
    $stmt->execute([(int)$_GET['ver']]);
    $detalle = $stmt->fetch();

    if ($detalle) {
        // Marcar como leída
        $pdo->prepare('INSERT IGNORE INTO comunicaciones_leidas (comunicacion_id, user_id) VALUES (?,?)')
            ->execute([$detalle['id'], $user['id']]);

        if ($detalle['tipo'] === 'encuesta') {
            $stmt = $pdo->prepare('SELECT * FROM encuestas_preguntas WHERE comunicacion_id=? ORDER BY orden');
            $stmt->execute([$detalle['id']]);
            $preguntas_detalle = $stmt->fetchAll();

            foreach ($preguntas_detalle as &$pq) {
                if ($pq['tipo'] === 'opciones') {
                    $stmt = $pdo->prepare('SELECT o.*, (SELECT COUNT(*) FROM encuestas_respuestas WHERE opcion_id=o.id) as votos FROM encuestas_opciones o WHERE o.pregunta_id=? ORDER BY o.orden');
                    $stmt->execute([$pq['id']]);
                    $pq['opciones'] = $stmt->fetchAll();
                }
                // Mi respuesta a esta pregunta
                $stmt = $pdo->prepare('SELECT opcion_id, respuesta_texto FROM encuestas_respuestas WHERE pregunta_id=? AND user_id=?');
                $stmt->execute([$pq['id'], $user['id']]);
                $pq['mi_respuesta'] = $stmt->fetch();
                if ($pq['mi_respuesta']) $ya_respondio = true;
            }
            unset($pq);
        }
    }
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

  <div class="card">
    <div style="margin-bottom:12px;">
      <span class="badge <?= $detalle['tipo'] === 'encuesta' ? 'badge-green' : 'badge-blue' ?>"><?= $detalle['tipo'] === 'encuesta' ? 'Encuesta' : 'Mensaje' ?></span>
      <span class="text-muted text-sm" style="margin-left:8px;"><?= date('d/m/Y H:i', strtotime($detalle['created_at'])) ?></span>
    </div>
    <h1 style="font-size:22px;margin-bottom:12px;"><?= e($detalle['titulo']) ?></h1>
    <div style="line-height:1.7;font-size:15px;"><?= nl2br(e($detalle['contenido'])) ?></div>

    <?php if ($detalle['tipo'] === 'encuesta' && $preguntas_detalle): ?>
      <div style="margin-top:28px;padding-top:20px;border-top:1px solid #eee;">

        <?php if ($ya_respondio): ?>
          <!-- Ya respondió: mostrar resultados -->
          <?php foreach ($preguntas_detalle as $pi => $pq): ?>
            <div style="margin-bottom:24px;<?= $pi > 0 ? 'padding-top:16px;border-top:1px solid #f0f0f0;' : '' ?>">
              <h3 style="font-size:15px;font-weight:700;margin-bottom:10px;"><?= ($pi + 1) ?>. <?= e($pq['texto']) ?></h3>

              <?php if ($pq['tipo'] === 'opciones' && !empty($pq['opciones'])): ?>
                <?php $total_votos = array_sum(array_column($pq['opciones'], 'votos')); ?>
                <?php foreach ($pq['opciones'] as $op): ?>
                  <?php $pct = $total_votos > 0 ? round(($op['votos'] / $total_votos) * 100) : 0; ?>
                  <div style="margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:3px;">
                      <span><?= e($op['texto']) ?> <?= $pq['mi_respuesta'] && (int)($pq['mi_respuesta']['opcion_id']) === (int)$op['id'] ? '<strong>(tu voto)</strong>' : '' ?></span>
                      <span class="text-muted"><?= $op['votos'] ?> (<?= $pct ?>%)</span>
                    </div>
                    <div style="background:#eee;border-radius:6px;height:8px;overflow:hidden;">
                      <div style="background:var(--blue);height:100%;width:<?= $pct ?>%;border-radius:6px;"></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>

              <?php if ($pq['mi_respuesta'] && $pq['mi_respuesta']['respuesta_texto']): ?>
                <p style="font-size:14px;margin-top:8px;"><strong>Tu respuesta:</strong> <?= e($pq['mi_respuesta']['respuesta_texto']) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

        <?php else: ?>
          <!-- No ha respondido: formulario -->
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="responder">
            <input type="hidden" name="comunicacion_id" value="<?= $detalle['id'] ?>">

            <?php foreach ($preguntas_detalle as $pi => $pq): ?>
              <div style="margin-bottom:24px;<?= $pi > 0 ? 'padding-top:16px;border-top:1px solid #f0f0f0;' : '' ?>">
                <h3 style="font-size:15px;font-weight:700;margin-bottom:10px;"><?= ($pi + 1) ?>. <?= e($pq['texto']) ?></h3>

                <?php if ($pq['tipo'] === 'opciones' && !empty($pq['opciones'])): ?>
                  <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($pq['opciones'] as $op): ?>
                      <label style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:<?= $op['es_libre'] ? '#f0fdf4' : '#f8fafc' ?>;border:1px solid #eee;border-radius:8px;cursor:pointer;transition:border-color 0.15s;">
                        <input type="radio" name="respuestas[<?= $pq['id'] ?>][opcion_id]" value="<?= $op['id'] ?>" required
                               <?php if ($op['es_libre']): ?>onchange="document.getElementById('libre_<?= $pq['id'] ?>').style.display=''"<?php else: ?>onchange="document.getElementById('libre_<?= $pq['id'] ?>').style.display='none'"<?php endif; ?>>
                        <span style="font-size:14px;"><?= e($op['texto']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <?php $tiene_libre = false; foreach ($pq['opciones'] as $op) if ($op['es_libre']) { $tiene_libre = true; break; } ?>
                  <?php if ($tiene_libre): ?>
                    <div id="libre_<?= $pq['id'] ?>" style="display:none;margin-top:10px;">
                      <input type="text" name="respuestas[<?= $pq['id'] ?>][texto]" class="form-control" placeholder="Especifica tu respuesta...">
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <textarea name="respuestas[<?= $pq['id'] ?>][texto]" class="form-control" rows="3" placeholder="Escribe tu respuesta..." required></textarea>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <button type="submit" class="btn btn-primary">Enviar respuestas</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

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
        <a href="/socio/comunicaciones?ver=<?= $c['id'] ?>" class="card" style="text-decoration:none;display:flex;align-items:center;gap:14px;padding:16px 20px;transition:box-shadow 0.15s;<?= !(int)$c['leida'] ? 'border-left:3px solid var(--blue);' : '' ?>"
           onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.08)'" onmouseout="this.style.boxShadow=''">
          <div style="font-size:20px;color:<?= $c['tipo'] === 'encuesta' ? 'var(--green)' : 'var(--blue)' ?>;flex-shrink:0;">
            <i class="bi <?= $c['tipo'] === 'encuesta' ? 'bi-bar-chart-line' : 'bi-envelope-fill' ?>"></i>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:<?= !(int)$c['leida'] ? '700' : '500' ?>;font-size:14px;color:var(--text);margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= e($c['titulo']) ?>
            </div>
            <div class="text-muted text-sm"><?= date('d/m/Y', strtotime($c['created_at'])) ?> · <?= $c['tipo'] === 'encuesta' ? 'Encuesta' : 'Mensaje' ?></div>
          </div>
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
