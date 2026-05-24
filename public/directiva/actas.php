<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_cargo(['presidente', 'secretario', 'tesorero', 'vocal']);

$puedeEditar = is_admin() || user_tiene_cargo('secretario');

// ── POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$puedeEditar) {
        http_response_code(403);
        die('Solo el secretario puede editar actas.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'crear' || $action === 'editar') {
        $titulo    = trim($_POST['titulo'] ?? '');
        $fecha     = trim($_POST['fecha'] ?? '');
        $contenido = trim($_POST['contenido'] ?? '');
        $publicada = isset($_POST['publicada']) ? 1 : 0;
        $acta_id   = (int)($_POST['acta_id'] ?? 0);

        $dt = DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$titulo || !$contenido || !$dt || $dt->format('Y-m-d') !== $fecha) {
            flash('Título, fecha y contenido son obligatorios.', 'danger');
        } else {
            if ($action === 'crear') {
                $autor_id = current_user()['id'];
                $pdo->prepare(
                    'INSERT INTO actas (fecha, titulo, contenido, autor_id, publicada) VALUES (?,?,?,?,?)'
                )->execute([$fecha, $titulo, $contenido, $autor_id, $publicada]);
                flash('Acta creada.', 'success');
            } else {
                if ($acta_id) {
                    $pdo->prepare(
                        'UPDATE actas SET fecha=?, titulo=?, contenido=?, publicada=?, updated_at=NOW() WHERE id=?'
                    )->execute([$fecha, $titulo, $contenido, $publicada, $acta_id]);
                    flash('Acta actualizada.', 'success');
                }
            }
        }
    } elseif ($action === 'eliminar') {
        $acta_id = (int)($_POST['acta_id'] ?? 0);
        if ($acta_id) {
            $pdo->prepare('DELETE FROM actas WHERE id=?')->execute([$acta_id]);
            flash('Acta eliminada.', 'warning');
        }
    } elseif ($action === 'toggle_publicar') {
        $acta_id = (int)($_POST['acta_id'] ?? 0);
        if ($acta_id) {
            $pdo->prepare('UPDATE actas SET publicada = 1 - publicada WHERE id=?')->execute([$acta_id]);
            flash('Estado de publicación cambiado.', 'success');
        }
    }

    header('Location: /directiva/actas' . (isset($_GET['ver']) ? '?ver=' . (int)$_GET['ver'] : ''));
    exit;
}

// ── Lectura ─────────────────────────────────────────────────────
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$actaVer = null;
if ($verId) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.nombre AS autor_nombre
        FROM actas a
        LEFT JOIN users u ON u.id = a.autor_id
        WHERE a.id = ?
    ");
    $stmt->execute([$verId]);
    $actaVer = $stmt->fetch();
    if ($actaVer && !$actaVer['publicada'] && !$puedeEditar) {
        $actaVer = null;
    }
}

$where = $puedeEditar ? '' : 'WHERE a.publicada = 1';
$actas = $pdo->query("
    SELECT a.id, a.fecha, a.titulo, a.publicada, a.autor_id, u.nombre AS autor_nombre,
           a.created_at, a.updated_at
    FROM actas a
    LEFT JOIN users u ON u.id = a.autor_id
    $where
    ORDER BY a.fecha DESC, a.id DESC
")->fetchAll();

render_header('Actas', 'directiva-actas');
render_directiva_layout('actas', function() use ($actas, $actaVer, $puedeEditar) {
?>

<div class="d-flex justify-between align-center mb-4" style="gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;">Actas</h1>
  <?php if ($puedeEditar): ?>
    <button class="btn btn-primary" onclick="abrirNuevaActa()">
      <i class="bi bi-plus-circle-fill"></i> Nueva acta
    </button>
  <?php endif; ?>
</div>

<?php render_flash(); ?>

<?php if ($actaVer): ?>
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex justify-between align-center mb-2" style="gap:12px;flex-wrap:wrap;">
        <div>
          <h2 style="margin:0;"><?= e($actaVer['titulo']) ?></h2>
          <p style="margin:4px 0 0;color:var(--gray);font-size:14px;">
            <?= e($actaVer['fecha']) ?>
            <?php if ($actaVer['autor_nombre']): ?>
              · Redactada por <?= e($actaVer['autor_nombre']) ?>
            <?php endif; ?>
            <?php if (!$actaVer['publicada']): ?>
              · <span class="badge badge-gray">Borrador</span>
            <?php else: ?>
              · <span class="badge badge-green">Publicada</span>
            <?php endif; ?>
          </p>
        </div>
        <div class="d-flex gap-2">
          <a href="/directiva/actas" class="btn btn-gray btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
          <?php if ($puedeEditar): ?>
            <button type="button" class="btn btn-secondary btn-sm"
                    data-acta='<?= e(json_encode([
                        'id'       => (int)$actaVer['id'],
                        'fecha'    => $actaVer['fecha'],
                        'titulo'   => $actaVer['titulo'],
                        'contenido'=> $actaVer['contenido'],
                        'publicada'=> (int)$actaVer['publicada'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>'
                    onclick="abrirEditarActa(JSON.parse(this.dataset.acta))">
              <i class="bi bi-pencil"></i> Editar
            </button>
            <form method="POST" action="/directiva/actas" style="display:inline;" data-confirm="¿Eliminar esta acta? No se puede deshacer.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="eliminar">
              <input type="hidden" name="acta_id" value="<?= (int)$actaVer['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <hr style="margin:16px 0;">
      <div style="white-space:pre-wrap;line-height:1.6;"><?= nl2br(e($actaVer['contenido'])) ?></div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-body" style="padding:0;">
      <?php if (!$actas): ?>
        <p style="padding:24px;text-align:center;color:var(--gray);margin:0;">Sin actas registradas.</p>
      <?php else: ?>
        <table class="table" style="margin:0;">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Título</th>
              <th>Autor</th>
              <th>Estado</th>
              <th style="width:160px;text-align:right;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($actas as $a): ?>
              <tr>
                <td><?= e($a['fecha']) ?></td>
                <td><strong><?= e($a['titulo']) ?></strong></td>
                <td><?= e($a['autor_nombre'] ?? '—') ?></td>
                <td>
                  <?php if ($a['publicada']): ?>
                    <span class="badge badge-green">Publicada</span>
                  <?php else: ?>
                    <span class="badge badge-gray">Borrador</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <a href="/directiva/actas?ver=<?= (int)$a['id'] ?>" class="btn btn-sm btn-secondary">
                    <i class="bi bi-eye"></i> Ver
                  </a>
                  <?php if ($puedeEditar): ?>
                    <form method="POST" style="display:inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle_publicar">
                      <input type="hidden" name="acta_id" value="<?= (int)$a['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-gray" title="<?= $a['publicada'] ? 'Despublicar' : 'Publicar' ?>">
                        <i class="bi <?= $a['publicada'] ? 'bi-eye-slash' : 'bi-globe' ?>"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($puedeEditar): ?>
<div id="modalActa" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:24px;max-width:760px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);max-height:90vh;overflow-y:auto;">
    <h3 style="margin-top:0;margin-bottom:16px;">
      <i class="bi bi-journal-text"></i>
      <span id="modalActaTitulo">Nueva acta</span>
    </h3>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="actaAction" value="crear">
      <input type="hidden" name="acta_id" id="actaId">

      <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
          <label class="form-label">Fecha de la reunión</label>
          <input type="date" name="fecha" id="actaFecha" class="form-control" required>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;">
          <label class="form-label" style="margin:0;">
            <input type="checkbox" name="publicada" id="actaPublicada" value="1">
            Publicada (visible a la directiva)
          </label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Título</label>
        <input type="text" name="titulo" id="actaTitulo" class="form-control" maxlength="200" required>
      </div>

      <div class="form-group">
        <label class="form-label">Contenido</label>
        <textarea name="contenido" id="actaContenido" class="form-control" rows="14" required></textarea>
      </div>

      <div class="d-flex gap-2" style="justify-content:flex-end;">
        <button type="button" class="btn btn-gray" onclick="document.getElementById('modalActa').style.display='none'">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirNuevaActa() {
  document.getElementById('modalActaTitulo').textContent = 'Nueva acta';
  document.getElementById('actaAction').value     = 'crear';
  document.getElementById('actaId').value         = '';
  document.getElementById('actaFecha').value      = new Date().toISOString().slice(0,10);
  document.getElementById('actaTitulo').value     = '';
  document.getElementById('actaContenido').value  = '';
  document.getElementById('actaPublicada').checked = false;
  document.getElementById('modalActa').style.display = 'flex';
}

function abrirEditarActa(d) {
  document.getElementById('modalActaTitulo').textContent = 'Editar acta';
  document.getElementById('actaAction').value     = 'editar';
  document.getElementById('actaId').value         = String(d.id);
  document.getElementById('actaFecha').value      = d.fecha;
  document.getElementById('actaTitulo').value     = d.titulo;
  document.getElementById('actaContenido').value  = d.contenido;
  document.getElementById('actaPublicada').checked = !!d.publicada;
  document.getElementById('modalActa').style.display = 'flex';
}
</script>
<?php endif; ?>

<?php
});
render_footer();
