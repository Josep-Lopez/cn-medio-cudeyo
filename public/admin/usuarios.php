<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

$LLIGUES = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior/Absoluto','master'=>'Master'];

// --- Acciones POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($action === 'vincular_rfen') {
        $rfen_id  = trim($_POST['rfen_id']  ?? '');
        $rfen_nom = trim($_POST['rfen_nom'] ?? '');
        if ($user_id && $rfen_id && $rfen_nom) {
            $pdo->prepare('UPDATE users SET rfen_id=?, rfen_nom=?, updated_at=NOW() WHERE id=?')
                ->execute([$rfen_id, $rfen_nom, $user_id]);
            flash('Usuario vinculado a RFEN.', 'success');
        }
    } elseif ($action === 'desvincular_rfen') {
        if ($user_id) {
            $pdo->prepare('UPDATE users SET rfen_id=NULL, rfen_nom=NULL, updated_at=NOW() WHERE id=?')
                ->execute([$user_id]);
            flash('Vinculación RFEN eliminada.', 'warning');
        }
    } elseif ($action === 'crear') {
        $nom    = trim($_POST['nom']   ?? '');
        $email  = trim($_POST['email'] ?? '');
        $pass   = $_POST['password']   ?? '';
        $sexe   = in_array($_POST['sexe']   ?? '', ['M','F'])                                           ? $_POST['sexe']   : 'M';
        $lliga  = in_array($_POST['lliga']  ?? '', array_keys($LLIGUES))                                 ? $_POST['lliga']  : '';
        $rol    = in_array($_POST['rol']    ?? '', ['soci','admin'])                                     ? $_POST['rol']    : 'soci';
        $estado = in_array($_POST['estado'] ?? '', ['pendiente','activo','rechazado'])                   ? $_POST['estado'] : 'activo';

        if (!$nom || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('Datos incorrectos. Nombre, email válido y contraseña de al menos 8 caracteres son obligatorios.', 'danger');
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE email=?');
            $check->execute([$email]);
            if ($check->fetch()) {
                flash('El email ya está registrado.', 'danger');
            } else {
                $pdo->prepare('INSERT INTO users (nom, email, password, sexe, lliga, rol, estado) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$nom, $email, password_hash($pass, PASSWORD_DEFAULT), $sexe, $lliga ?: null, $rol, $estado]);
                flash('Usuario creado correctamente.', 'success');
            }
        }
    } elseif ($user_id > 0) {
        switch ($action) {
            case 'editar':
                $nom    = trim($_POST['nom']   ?? '');
                $email  = trim($_POST['email'] ?? '');
                $pass   = $_POST['password']   ?? '';
                $sexe   = in_array($_POST['sexe']   ?? '', ['M','F'])                        ? $_POST['sexe']   : 'M';
                $lliga  = in_array($_POST['lliga']  ?? '', array_keys($LLIGUES))              ? $_POST['lliga']  : '';
                $rol    = in_array($_POST['rol']    ?? '', ['soci','admin'])                  ? $_POST['rol']    : 'soci';
                $estado = in_array($_POST['estado'] ?? '', ['pendiente','activo','rechazado'])? $_POST['estado'] : 'activo';

                if (!$nom || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    flash('Nombre y email válido son obligatorios.', 'danger');
                    break;
                }
                $check = $pdo->prepare('SELECT id FROM users WHERE email=? AND id!=?');
                $check->execute([$email, $user_id]);
                if ($check->fetch()) {
                    flash('El email ya está en uso por otro usuario.', 'danger');
                    break;
                }
                if ($pass) {
                    $pdo->prepare('UPDATE users SET nom=?,email=?,password=?,sexe=?,lliga=?,rol=?,estado=?,updated_at=NOW() WHERE id=?')
                        ->execute([$nom, $email, password_hash($pass, PASSWORD_DEFAULT), $sexe, $lliga ?: null, $rol, $estado, $user_id]);
                } else {
                    $pdo->prepare('UPDATE users SET nom=?,email=?,sexe=?,lliga=?,rol=?,estado=?,updated_at=NOW() WHERE id=?')
                        ->execute([$nom, $email, $sexe, $lliga ?: null, $rol, $estado, $user_id]);
                }
                flash('Usuario actualizado.', 'success');
                break;
            case 'aprovar':
                $pdo->prepare('UPDATE users SET estado=\'activo\', updated_at=NOW() WHERE id=?')
                    ->execute([$user_id]);
                flash('Usuario aprobado correctamente.', 'success');
                break;
            case 'rebutjar':
                $pdo->prepare('UPDATE users SET estado=\'rechazado\', updated_at=NOW() WHERE id=?')
                    ->execute([$user_id]);
                flash('Usuario rechazado.', 'warning');
                break;
            case 'canviar_lliga':
                $lliga = $_POST['lliga'] ?? '';
                if (in_array($lliga, array_keys($LLIGUES))) {
                    $pdo->prepare('UPDATE users SET lliga=?, updated_at=NOW() WHERE id=?')
                        ->execute([$lliga, $user_id]);
                    flash('Categoría actualizada.', 'success');
                }
                break;
            case 'canviar_rol':
                $rol = $_POST['rol'] ?? '';
                if (in_array($rol, ['soci','admin'])) {
                    $pdo->prepare('UPDATE users SET rol=?, updated_at=NOW() WHERE id=?')
                        ->execute([$rol, $user_id]);
                    flash('Rol actualizado.', 'success');
                }
                break;
            case 'eliminar':
                $pdo->prepare('DELETE FROM users WHERE id=? AND rol!=\'admin\'')
                    ->execute([$user_id]);
                flash('Usuario eliminado.', 'danger');
                break;
        }
    }
    header('Location: /admin/usuarios' . (isset($_GET['estado']) ? '?estado=' . urlencode($_GET['estado']) : ''));
    exit;
}

// --- Filtro ---
$filtroEstado = $_GET['estado'] ?? 'todos';
$validos = ['todos','pendiente','activo','rechazado'];
if (!in_array($filtroEstado, $validos)) $filtroEstado = 'todos';

if ($filtroEstado !== 'todos') {
    $stmt = $pdo->prepare('SELECT id,nom,email,rol,estado,lliga,sexe,rfen_id,rfen_nom,created_at FROM users WHERE estado=? ORDER BY created_at DESC');
    $stmt->execute([$filtroEstado]);
} else {
    $stmt = $pdo->query('SELECT id,nom,email,rol,estado,lliga,sexe,rfen_id,rfen_nom,created_at FROM users ORDER BY created_at DESC');
}
$users = $stmt->fetchAll();

// Cuenta por estado
$counts = $pdo->query('SELECT estado, COUNT(*) as n FROM users GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);

render_header('Gestión de usuarios', 'admin-usuarios');
render_admin_layout('usuarios', function() use ($users, $filtroEstado, $counts, $LLIGUES) {
?>

<div class="d-flex justify-between align-center mb-6" style="gap:12px;">
  <h1 style="margin:0;">Gestión de usuarios</h1>
  <button class="btn btn-primary" onclick="abrirModalCrear()">
    <i class="bi bi-person-plus-fill"></i> Nuevo usuario
  </button>
</div>

<?php render_flash(); ?>

<!-- Filtros de estado -->
<div class="filters-bar" style="margin-bottom:20px;">
  <?php
  $estados = ['todos' => 'Todos', 'pendiente' => 'Pendientes', 'activo' => 'Activos', 'rechazado' => 'Rechazados'];
  foreach ($estados as $val => $label):
    $count = $val === 'todos' ? array_sum($counts) : ($counts[$val] ?? 0);
    $active = $filtroEstado === $val ? 'btn-primary' : 'btn-gray';
  ?>
    <a href="?estado=<?= $val ?>" class="btn btn-sm <?= $active ?>">
      <?= $label ?> <span class="badge badge-gray" style="margin-left:4px;"><?= $count ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="table-card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Email</th>
          <th>Categoría</th>
          <th>Sexo</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>RFEN</th>
          <th>Registro</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="8" class="text-center text-muted" style="padding:32px;">No hay usuarios.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><strong><?= e($u['nom']) ?></strong></td>
          <td><?= e($u['email']) ?></td>
          <td>
            <?php if ($u['rol'] !== 'admin'): ?>
            <form method="POST" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="canviar_lliga">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="lliga" class="form-control" style="width:130px;padding:4px 8px;font-size:13px;" onchange="this.form.submit()">
                <option value="">— Sin liga —</option>
                <?php foreach ($LLIGUES as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $u['lliga'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= $u['sexe'] === 'M' ? 'Masc.' : 'Fem.' ?></td>
          <td>
            <form method="POST" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="canviar_rol">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="rol" class="form-control" style="width:90px;padding:4px 8px;font-size:13px;" onchange="this.form.submit()">
                <option value="soci"  <?= $u['rol']==='soci'  ? 'selected' : '' ?>>Socio</option>
                <option value="admin" <?= $u['rol']==='admin' ? 'selected' : '' ?>>Admin</option>
              </select>
            </form>
          </td>
          <td>
            <?php
            $badges = ['pendiente'=>'warning','activo'=>'success','rechazado'=>'danger'];
            $labels = ['pendiente'=>'Pendiente','activo'=>'Activo','rechazado'=>'Rechazado'];
            $b = $badges[$u['estado']] ?? 'gray';
            $l = $labels[$u['estado']] ?? $u['estado'];
            ?>
            <span class="badge badge-<?= $b ?>"><?= $l ?></span>
          </td>
          <td>
            <?php if ($u['rfen_id']): ?>
              <span class="badge badge-green" title="<?= e($u['rfen_nom']) ?>"><i class="bi bi-link-45deg"></i> Vinculado</span>
            <?php else: ?>
              <span class="badge badge-gray">—</span>
            <?php endif; ?>
          </td>
          <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
          <td>
            <div class="d-flex gap-2">
              <!-- Editar -->
              <button class="btn btn-secondary btn-sm"
                onclick="abrirModalEditar(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['nom']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['email']), ENT_QUOTES) ?>, '<?= e($u['sexe']) ?>', '<?= e($u['lliga'] ?? '') ?>', '<?= e($u['rol']) ?>', '<?= e($u['estado']) ?>')">
                <i class="bi bi-pencil-fill"></i>
              </button>
              <?php if ($u['estado'] === 'pendiente'): ?>
                <form method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="aprovar">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-success btn-sm">Aprobar</button>
                </form>
                <form method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="rebutjar">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-danger btn-sm">Rechazar</button>
                </form>
              <?php elseif ($u['estado'] === 'activo' && $u['rol'] !== 'admin'): ?>
                <form method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="rebutjar">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-warning btn-sm">Desactivar</button>
                </form>
              <?php elseif ($u['estado'] === 'rechazado'): ?>
                <form method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="aprovar">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-success btn-sm">Reactivar</button>
                </form>
              <?php endif; ?>
              <?php if ($u['rol'] !== 'admin'): ?>
                <?php if ($u['rfen_id']): ?>
                  <form method="POST" style="display:inline;"
                        onsubmit="return confirm('¿Eliminar vinculación RFEN de <?= e($u['nom']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="desvincular_rfen">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button class="btn btn-gray btn-sm" title="Desvincular RFEN"><i class="bi bi-link"></i></button>
                  </form>
                <?php else: ?>
                  <button class="btn btn-secondary btn-sm" title="Vincular RFEN"
                    onclick="abrirModalRFEN(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['nom']), ENT_QUOTES) ?>, '<?= e($u['sexe']) ?>')">
                    <i class="bi bi-link-45deg"></i>
                  </button>
                <?php endif; ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('¿Eliminar a <?= e($u['nom']) ?>? Esta acción no se puede deshacer.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="eliminar">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-gray btn-sm"><i class="bi bi-trash-fill"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Crear usuario -->
<div class="modal-overlay" id="modal-crear" style="display:none;" onclick="cerrarModalFondo(event,'crear')">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="bi bi-person-plus-fill"></i> Nuevo usuario</h3>
      <button type="button" class="modal-close" onclick="cerrarModal('crear')">×</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="crear">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nombre *</label>
            <input type="text" name="nom" class="form-control" required autofocus>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input type="email" name="email" class="form-control" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Contraseña *</label>
          <input type="password" name="password" class="form-control" required placeholder="Mínimo 8 caracteres">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Sexo</label>
            <select name="sexe" class="form-control">
              <option value="M">Masculino</option>
              <option value="F">Femenino</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Liga / Categoría</label>
            <select name="lliga" class="form-control">
              <option value="">— Sin liga —</option>
              <?php foreach ($LLIGUES as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Rol</label>
            <select name="rol" class="form-control">
              <option value="soci">Socio</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Estado</label>
            <select name="estado" class="form-control">
              <option value="activo">Activo</option>
              <option value="pendiente">Pendiente</option>
              <option value="rechazado">Rechazado</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-gray" onclick="cerrarModal('crear')">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Crear usuario</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar usuario -->
<div class="modal-overlay" id="modal-editar" style="display:none;" onclick="cerrarModalFondo(event,'editar')">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="bi bi-pencil-fill"></i> Editar usuario</h3>
      <button type="button" class="modal-close" onclick="cerrarModal('editar')">×</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="editar">
      <input type="hidden" name="user_id" id="edit-user-id">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nombre *</label>
            <input type="text" name="nom" id="edit-nom" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input type="email" name="email" id="edit-email" class="form-control" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Nueva contraseña</label>
          <div class="input-password-wrapper">
            <input type="password" name="password" class="form-control" placeholder="Dejar en blanco para no cambiar">
            <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1" aria-label="Mostrar">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div class="form-hint">Solo rellena si quieres cambiar la contraseña.</div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Sexo</label>
            <select name="sexe" id="edit-sexe" class="form-control">
              <option value="M">Masculino</option>
              <option value="F">Femenino</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Liga / Categoría</label>
            <select name="lliga" id="edit-lliga" class="form-control">
              <option value="">— Sin liga —</option>
              <?php foreach ($LLIGUES as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Rol</label>
            <select name="rol" id="edit-rol" class="form-control">
              <option value="soci">Socio</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Estado</label>
            <select name="estado" id="edit-estado" class="form-control">
              <option value="activo">Activo</option>
              <option value="pendiente">Pendiente</option>
              <option value="rechazado">Rechazado</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-gray" onclick="cerrarModal('editar')">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Vincular RFEN -->
<div class="modal-overlay" id="modal-rfen" style="display:none;" onclick="cerrarModalFondo(event,'rfen')">
  <div class="modal-box" style="max-width:560px;">
    <div class="modal-header">
      <h3><i class="bi bi-link-45deg"></i> Vincular a RFEN</h3>
      <button type="button" class="modal-close" onclick="cerrarModal('rfen')">×</button>
    </div>
    <div class="modal-body">
      <p class="text-muted text-sm" style="margin-bottom:16px;">
        Busca el deportista en la intranet de la RFEN. El nombre puede diferir ligeramente del registrado en el club.
      </p>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Nombre</label>
          <input type="text" id="rfen-nombre" class="form-control" placeholder="Nombre">
        </div>
        <div class="form-group">
          <label class="form-label">Apellidos</label>
          <input type="text" id="rfen-apellidos" class="form-control" placeholder="Apellidos">
        </div>
      </div>
      <button type="button" class="btn btn-primary btn-sm" id="rfen-buscar-btn" onclick="buscarRFEN()">
        <i class="bi bi-search"></i> Buscar en RFEN
      </button>
      <div id="rfen-resultats" style="margin-top:16px;"></div>
      <form method="POST" id="rfen-vincular-form" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action"   value="vincular_rfen">
        <input type="hidden" name="user_id"  id="rfen-user-id">
        <input type="hidden" name="rfen_id"  id="rfen-id-val">
        <input type="hidden" name="rfen_nom" id="rfen-nom-val">
      </form>
    </div>
  </div>
</div>

<script>
let rfenUserId = 0;
let rfenSexe   = 'M';

function abrirModalRFEN(userId, nom, sexe) {
  rfenUserId = userId;
  rfenSexe   = sexe;
  // Rellenar nombre y apellidos del socio
  const parts = nom.trim().split(/\s+/);
  document.getElementById('rfen-nombre').value   = parts[0] || '';
  document.getElementById('rfen-apellidos').value = parts.slice(1).join(' ') || '';
  document.getElementById('rfen-resultats').innerHTML = '';
  document.getElementById('rfen-vincular-form').style.display = 'none';
  document.getElementById('modal-rfen').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function buscarRFEN() {
  const nom  = document.getElementById('rfen-nombre').value.trim();
  const cog  = document.getElementById('rfen-apellidos').value.trim();
  const btn  = document.getElementById('rfen-buscar-btn');
  const div  = document.getElementById('rfen-resultats');
  if (!nom || !cog) { div.innerHTML = '<p class="text-danger text-sm">Introduce nombre y apellidos.</p>'; return; }
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Buscando...';
  div.innerHTML = '';
  fetch(`/admin/rfen_buscar?nombre=${encodeURIComponent(nom)}&apellidos=${encodeURIComponent(cog)}&sexe=${rfenSexe}`)
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-search"></i> Buscar en RFEN';
      if (data.error) { div.innerHTML = `<p class="text-danger text-sm">${data.error}</p>`; return; }
      if (!data.results || data.results.length === 0) {
        div.innerHTML = '<p class="text-muted text-sm">Sin resultados. Prueba con otro nombre.</p>';
        return;
      }
      let html = '<div class="table-wrapper"><table><thead><tr><th></th><th>Nombre</th><th>Apellidos</th><th>Año nac.</th></tr></thead><tbody>';
      data.results.forEach((r, i) => {
        html += `<tr style="cursor:pointer;" onclick="seleccionarRFEN('${escHtml(r.rfen_id)}','${escHtml(r.rfen_nom)}',${i})">
          <td><input type="radio" name="rfen_sel" id="rfen_r${i}"></td>
          <td>${escHtml(r.nom)}</td><td>${escHtml(r.cognoms)}</td><td>${escHtml(r.any_naix)}</td>
        </tr>`;
      });
      html += '</tbody></table></div>';
      div.innerHTML = html;
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-search"></i> Buscar en RFEN';
      div.innerHTML = '<p class="text-danger text-sm">Error de conexión.</p>';
    });
}

function seleccionarRFEN(rfen_id, rfen_nom, idx) {
  document.getElementById('rfen_r' + idx).checked = true;
  document.getElementById('rfen-user-id').value = rfenUserId;
  document.getElementById('rfen-id-val').value  = rfen_id;
  document.getElementById('rfen-nom-val').value  = rfen_nom;
  const form = document.getElementById('rfen-vincular-form');
  form.style.display = 'block';
  // Añadir botón de confirmación si no existe
  if (!document.getElementById('rfen-confirm-btn')) {
    const btn = document.createElement('button');
    btn.id = 'rfen-confirm-btn';
    btn.type = 'submit';
    btn.className = 'btn btn-primary';
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Vincular este deportista';
    btn.style.marginTop = '12px';
    form.appendChild(btn);
  }
}

function escHtml(str) {
  return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function abrirModalCrear() {
  document.getElementById('modal-crear').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function abrirModalEditar(id, nom, email, sexe, lliga, rol, estado) {
  document.getElementById('edit-user-id').value = id;
  document.getElementById('edit-nom').value     = nom;
  document.getElementById('edit-email').value   = email;
  document.getElementById('edit-sexe').value    = sexe;
  document.getElementById('edit-lliga').value   = lliga;
  document.getElementById('edit-rol').value     = rol;
  document.getElementById('edit-estado').value  = estado;
  document.getElementById('modal-editar').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function cerrarModal(which) {
  document.getElementById('modal-' + which).style.display = 'none';
  document.body.style.overflow = '';
}
function cerrarModalFondo(e, which) {
  if (e.target === e.currentTarget) cerrarModal(which);
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    cerrarModal('crear');
    cerrarModal('editar');
    cerrarModal('rfen');
  }
});
function togglePwd(btn) {
  const input = btn.previousElementSibling;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>

<?php
});
render_footer();
