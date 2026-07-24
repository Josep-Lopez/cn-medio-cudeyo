<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();

$u            = current_user();
$uid          = (int)$u['id'];
$esDirectiva  = is_admin() || es_directiva() || user_tiene_cargo('director_tecnico');
$puedeDecidir = is_admin() || user_tiene_cargo('presidente');

// ── POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $titulo      = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        if (!$titulo || !$descripcion) {
            flash('Título y descripción son obligatorios.', 'danger');
        } else {
            $pdo->prepare(
                'INSERT INTO cuestiones (autor_id, titulo, descripcion) VALUES (?,?,?)'
            )->execute([$uid, $titulo, $descripcion]);
            flash('Propuesta enviada. La directiva la revisará.', 'success');
        }
    } elseif ($action === 'decidir' && $puedeDecidir) {
        $cuestion_id = (int)($_POST['cuestion_id'] ?? 0);
        $decision    = $_POST['decision'] ?? '';
        $motivo      = trim($_POST['motivo'] ?? '');
        if ($cuestion_id && in_array($decision, ['aprobada', 'rechazada'], true)) {
            $pdo->prepare(
                'UPDATE cuestiones
                 SET estado=?, decision_por=?, decision_fecha=NOW(), decision_motivo=?
                 WHERE id=? AND estado=?'
            )->execute([$decision, $uid, $motivo ?: null, $cuestion_id, 'propuesta']);
            flash('Decisión registrada.', 'success');
        }
    } elseif ($action === 'reabrir' && $puedeDecidir) {
        $cuestion_id = (int)($_POST['cuestion_id'] ?? 0);
        if ($cuestion_id) {
            $pdo->prepare(
                "UPDATE cuestiones SET estado='propuesta', decision_por=NULL, decision_fecha=NULL, decision_motivo=NULL WHERE id=?"
            )->execute([$cuestion_id]);
            flash('Cuestión reabierta.', 'warning');
        }
    } elseif ($action === 'comentar') {
        $cuestion_id = (int)($_POST['cuestion_id'] ?? 0);
        $contenido   = trim($_POST['contenido'] ?? '');
        if ($cuestion_id && $contenido !== '') {
            // Verificar acceso: autor o directiva
            $chk = $pdo->prepare('SELECT autor_id, estado FROM cuestiones WHERE id=?');
            $chk->execute([$cuestion_id]);
            $q = $chk->fetch();
            if ($q && ((int)$q['autor_id'] === $uid || $esDirectiva)) {
                $pdo->prepare(
                    'INSERT INTO cuestion_comentarios (cuestion_id, user_id, contenido) VALUES (?,?,?)'
                )->execute([$cuestion_id, $uid, $contenido]);
                flash('Comentario añadido.', 'success');
            }
        }
    } elseif ($action === 'eliminar') {
        $cuestion_id = (int)($_POST['cuestion_id'] ?? 0);
        $chk = $pdo->prepare('SELECT autor_id, estado FROM cuestiones WHERE id=?');
        $chk->execute([$cuestion_id]);
        $q = $chk->fetch();
        // Autor puede borrar la suya mientras esté en estado 'propuesta'. Admin puede siempre.
        if ($q && (is_admin() || ((int)$q['autor_id'] === $uid && $q['estado'] === 'propuesta'))) {
            $pdo->prepare('DELETE FROM cuestiones WHERE id=?')->execute([$cuestion_id]);
            flash('Cuestión eliminada.', 'warning');
        }
    }

    $redir = '/directiva/cuestiones';
    if (isset($_GET['ver'])) $redir .= '?ver=' . (int)$_GET['ver'];
    header('Location: ' . $redir);
    exit;
}

// ── Lectura ─────────────────────────────────────────────────────
$verId  = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$cuestionVer = null;
$comentarios = [];
if ($verId) {
    $st = $pdo->prepare("
        SELECT c.*, ua.nombre AS autor_nombre, ud.nombre AS decisor_nombre
        FROM cuestiones c
        LEFT JOIN users ua ON ua.id = c.autor_id
        LEFT JOIN users ud ON ud.id = c.decision_por
        WHERE c.id = ?
    ");
    $st->execute([$verId]);
    $cuestionVer = $st->fetch();

    // Visibilidad
    if ($cuestionVer) {
        $puede = $esDirectiva
              || (int)$cuestionVer['autor_id'] === $uid
              || in_array($cuestionVer['estado'], ['aprobada', 'rechazada'], true);
        if (!$puede) $cuestionVer = null;
    }

    if ($cuestionVer) {
        $stC = $pdo->prepare("
            SELECT cc.*, u.nombre AS user_nombre
            FROM cuestion_comentarios cc
            LEFT JOIN users u ON u.id = cc.user_id
            WHERE cc.cuestion_id = ?
            ORDER BY cc.created_at ASC
        ");
        $stC->execute([$verId]);
        $comentarios = $stC->fetchAll();
    }
}

// Listado
$fEstado = $_GET['estado'] ?? 'todos';
$wheres  = [];
$params  = [];

if ($esDirectiva) {
    // Directiva ve todas. Filtro por estado opcional.
    if (in_array($fEstado, ['propuesta','aprobada','rechazada'], true)) {
        $wheres[] = 'c.estado=?'; $params[] = $fEstado;
    }
} else {
    // No-directiva: las propias en cualquier estado + las aprobadas/rechazadas de todos
    $wheres[] = "(c.autor_id = ? OR c.estado IN ('aprobada','rechazada'))";
    $params[] = $uid;
    if (in_array($fEstado, ['propuesta','aprobada','rechazada'], true)) {
        $wheres[] = 'c.estado=?'; $params[] = $fEstado;
    }
}

$sqlW = $wheres ? ('WHERE ' . implode(' AND ', $wheres)) : '';
$st = $pdo->prepare("
    SELECT c.id, c.titulo, c.estado, c.autor_id, c.created_at, c.decision_fecha,
           ua.nombre AS autor_nombre
    FROM cuestiones c
    LEFT JOIN users ua ON ua.id = c.autor_id
    $sqlW
    ORDER BY
      FIELD(c.estado, 'propuesta', 'aprobada', 'rechazada'),
      c.created_at DESC
");
$st->execute($params);
$cuestiones = $st->fetchAll();

// Conteos rápidos por estado (solo para directiva)
$counts = [];
if ($esDirectiva) {
    $counts = $pdo->query("SELECT estado, COUNT(*) AS n FROM cuestiones GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
}

function badge_estado(string $estado): string
{
    return match($estado) {
        'aprobada'   => 'badge-green',
        'rechazada'  => 'badge-red',
        'propuesta'  => 'badge-blue',
        default      => 'badge-gray',
    };
}

render_header('Cuestiones', 'directiva-cuestiones');

$contenido = function() use (
    $cuestiones, $cuestionVer, $comentarios, $esDirectiva, $puedeDecidir, $uid,
    $fEstado, $counts
) {
?>

<div class="d-flex justify-between align-center mb-4" style="gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;">Cuestiones</h1>
  <button class="btn btn-primary" onclick="document.getElementById('modalNueva').style.display='flex'">
    <i class="bi bi-plus-circle-fill"></i> Nueva propuesta
  </button>
</div>

<?php render_flash(); ?>

<?php if ($cuestionVer): ?>
  <!-- DETALLE -->
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex justify-between align-center mb-2" style="gap:12px;flex-wrap:wrap;">
        <div>
          <h2 style="margin:0;"><?= e($cuestionVer['titulo']) ?></h2>
          <p style="margin:4px 0 0;color:var(--gray);font-size:14px;">
            Propuesta por <strong><?= e($cuestionVer['autor_nombre'] ?? '—') ?></strong>
            · <?= e(substr((string)$cuestionVer['created_at'], 0, 10)) ?>
            · <span class="badge <?= badge_estado($cuestionVer['estado']) ?>"><?= e(ucfirst($cuestionVer['estado'])) ?></span>
          </p>
        </div>
        <a href="/directiva/cuestiones" class="btn btn-gray btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
      </div>
      <hr style="margin:16px 0;">
      <div style="white-space:pre-wrap;line-height:1.6;"><?= nl2br(e($cuestionVer['descripcion'])) ?></div>

      <?php if ($cuestionVer['estado'] !== 'propuesta'): ?>
        <div class="alert alert-<?= $cuestionVer['estado'] === 'aprobada' ? 'success' : 'danger' ?>" style="margin-top:16px;">
          <strong><?= $cuestionVer['estado'] === 'aprobada' ? 'Aprobada' : 'Rechazada' ?></strong>
          <?php if ($cuestionVer['decisor_nombre']): ?>
            por <?= e($cuestionVer['decisor_nombre']) ?>
          <?php endif; ?>
          <?php if ($cuestionVer['decision_fecha']): ?>
            · <?= e(substr((string)$cuestionVer['decision_fecha'], 0, 16)) ?>
          <?php endif; ?>
          <?php if ($cuestionVer['decision_motivo']): ?>
            <div style="margin-top:8px;"><em><?= nl2br(e($cuestionVer['decision_motivo'])) ?></em></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Acciones de decisión -->
      <?php if ($puedeDecidir && $cuestionVer['estado'] === 'propuesta'): ?>
        <hr style="margin:16px 0;">
        <div style="background:#f7f7f7;padding:16px;border-radius:8px;">
          <h4 style="margin-top:0;">Decisión del presidente</h4>
          <form method="POST" action="/directiva/cuestiones?ver=<?= (int)$cuestionVer['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="decidir">
            <input type="hidden" name="cuestion_id" value="<?= (int)$cuestionVer['id'] ?>">
            <div class="form-group">
              <label class="form-label">Motivo (opcional)</label>
              <textarea name="motivo" class="form-control" rows="3" maxlength="500" placeholder="Razones de la decisión"></textarea>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" name="decision" value="aprobada" class="btn btn-success">
                <i class="bi bi-check-circle"></i> Aprobar
              </button>
              <button type="submit" name="decision" value="rechazada" class="btn btn-danger">
                <i class="bi bi-x-circle"></i> Rechazar
              </button>
            </div>
          </form>
        </div>
      <?php elseif ($puedeDecidir && $cuestionVer['estado'] !== 'propuesta'): ?>
        <hr style="margin:16px 0;">
        <form method="POST" action="/directiva/cuestiones?ver=<?= (int)$cuestionVer['id'] ?>" data-confirm="¿Reabrir esta cuestión y volverla a estado de propuesta?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reabrir">
          <input type="hidden" name="cuestion_id" value="<?= (int)$cuestionVer['id'] ?>">
          <button type="submit" class="btn btn-gray btn-sm"><i class="bi bi-arrow-clockwise"></i> Reabrir</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Comentarios -->
  <div class="card mb-4">
    <div class="card-header"><h3 style="margin:0;font-size:16px;"><i class="bi bi-chat-dots"></i> Comentarios</h3></div>
    <div class="card-body">
      <?php if (!$comentarios): ?>
        <p style="color:var(--gray);font-style:italic;margin:0;">Sin comentarios todavía.</p>
      <?php else: ?>
        <?php foreach ($comentarios as $c): ?>
          <div style="padding:12px 0;border-bottom:1px solid #eee;">
            <div style="font-size:13px;color:var(--gray);margin-bottom:4px;">
              <strong><?= e($c['user_nombre'] ?? '—') ?></strong>
              · <?= e(substr((string)$c['created_at'], 0, 16)) ?>
            </div>
            <div style="white-space:pre-wrap;"><?= nl2br(e($c['contenido'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($esDirectiva || (int)$cuestionVer['autor_id'] === $uid): ?>
        <form method="POST" action="/directiva/cuestiones?ver=<?= (int)$cuestionVer['id'] ?>" style="margin-top:16px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="comentar">
          <input type="hidden" name="cuestion_id" value="<?= (int)$cuestionVer['id'] ?>">
          <div class="form-group">
            <textarea name="contenido" class="form-control" rows="3" placeholder="Escribe un comentario..." required></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-send"></i> Enviar
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <!-- LISTADO -->
  <?php if ($esDirectiva): ?>
    <div class="card mb-4">
      <div class="card-body">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <span style="color:var(--gray);font-size:13px;margin-right:8px;">Filtrar:</span>
          <a href="?estado=todos"     class="btn btn-sm <?= $fEstado === 'todos'     ? 'btn-primary' : 'btn-gray' ?>">Todas</a>
          <a href="?estado=propuesta" class="btn btn-sm <?= $fEstado === 'propuesta' ? 'btn-primary' : 'btn-gray' ?>">
            Pendientes <?= isset($counts['propuesta']) ? '(' . (int)$counts['propuesta'] . ')' : '' ?>
          </a>
          <a href="?estado=aprobada"  class="btn btn-sm <?= $fEstado === 'aprobada'  ? 'btn-primary' : 'btn-gray' ?>">
            Aprobadas <?= isset($counts['aprobada']) ? '(' . (int)$counts['aprobada'] . ')' : '' ?>
          </a>
          <a href="?estado=rechazada" class="btn btn-sm <?= $fEstado === 'rechazada' ? 'btn-primary' : 'btn-gray' ?>">
            Rechazadas <?= isset($counts['rechazada']) ? '(' . (int)$counts['rechazada'] . ')' : '' ?>
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body" style="padding:0;">
      <?php if (!$cuestiones): ?>
        <p style="padding:24px;text-align:center;color:var(--gray);margin:0;">Sin cuestiones.</p>
      <?php else: ?>
        <table class="table" style="margin:0;">
          <thead>
            <tr>
              <th>Título</th>
              <th>Autor</th>
              <th>Fecha</th>
              <th>Estado</th>
              <th style="width:120px;text-align:right;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cuestiones as $c): ?>
              <tr>
                <td><strong><?= e($c['titulo']) ?></strong></td>
                <td><?= e($c['autor_nombre'] ?? '—') ?></td>
                <td><?= e(substr((string)$c['created_at'], 0, 10)) ?></td>
                <td><span class="badge <?= badge_estado($c['estado']) ?>"><?= e(ucfirst($c['estado'])) ?></span></td>
                <td style="text-align:right;">
                  <a href="/directiva/cuestiones?ver=<?= (int)$c['id'] ?>" class="btn btn-sm btn-secondary">
                    <i class="bi bi-eye"></i> Ver
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<!-- Modal nueva propuesta -->
<div id="modalNueva" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:24px;max-width:620px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="margin-top:0;margin-bottom:16px;"><i class="bi bi-plus-circle-fill"></i> Nueva propuesta</h3>
    <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">
      Tu propuesta se envía al presidente para revisión. Recibirás respuesta en esta misma pantalla.
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="crear">

      <div class="form-group">
        <label class="form-label">Título</label>
        <input type="text" name="titulo" class="form-control" maxlength="200" required>
      </div>

      <div class="form-group">
        <label class="form-label">Descripción</label>
        <textarea name="descripcion" class="form-control" rows="8" required
                  placeholder="Describe la propuesta con el detalle necesario para que la directiva pueda valorarla."></textarea>
      </div>

      <div class="d-flex gap-2" style="justify-content:flex-end;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modalNueva').style.display='none'">Cancelar</button>
        <button type="submit" class="btn btn-primary">Enviar propuesta</button>
      </div>
    </form>
  </div>
</div>

<?php
}; // $contenido

if ($esDirectiva) {
    render_directiva_layout('cuestiones', $contenido);
} else {
    echo '<div class="container page-content">';
    $contenido();
    echo '</div>';
}

render_footer();
