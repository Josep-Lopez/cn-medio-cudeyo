<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

$LIGAS = ['benjamin'=>'Benjamín','alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','absoluto'=>'Absoluto','master'=>'Master'];

// --- Acciones POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($action === 'vincular_rfen') {
        $rfen_id     = trim($_POST['rfen_id']     ?? '');
        $rfen_nombre = trim($_POST['rfen_nombre'] ?? '');
        if ($user_id && $rfen_id && $rfen_nombre) {
            $pdo->prepare('UPDATE users SET rfen_id=?, rfen_nombre=?, updated_at=NOW() WHERE id=?')
                ->execute([$rfen_id, $rfen_nombre, $user_id]);
            flash('Usuario vinculado a RFEN.', 'success');
        }
    } elseif ($action === 'desvincular_rfen') {
        if ($user_id) {
            $pdo->prepare('UPDATE users SET rfen_id=NULL, rfen_nombre=NULL, updated_at=NOW() WHERE id=?')
                ->execute([$user_id]);
            flash('Vinculación RFEN eliminada.', 'warning');
        }
    } elseif ($action === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email']  ?? '');
        $pass   = $_POST['password']    ?? '';
        $sexo   = in_array($_POST['sexo']   ?? '', ['M','F'])                                           ? $_POST['sexo']   : 'M';
        $liga   = in_array($_POST['liga']   ?? '', array_keys($LIGAS))                                   ? $_POST['liga']   : '';
        $rol    = in_array($_POST['rol']    ?? '', ['socio','admin'])                                     ? $_POST['rol']    : 'socio';
        $estado = in_array($_POST['estado'] ?? '', ['pendiente','activo','rechazado'])                   ? $_POST['estado'] : 'activo';

        if (!$nombre || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('Datos incorrectos. Nombre, email válido y contraseña de al menos 8 caracteres son obligatorios.', 'danger');
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE email=?');
            $check->execute([$email]);
            if ($check->fetch()) {
                flash('El email ya está registrado.', 'danger');
            } else {
                $nadador_activo = isset($_POST['nadador_activo']) ? 1 : 0;
                $must_change_pwd = isset($_POST['must_change_pwd']) ? 1 : 0;
                $pdo->prepare('INSERT INTO users (nombre, email, password, sexo, liga, rol, estado, nadador_activo, must_change_pwd) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), $sexo, $liga ?: null, $rol, $estado, $nadador_activo, $must_change_pwd]);
                flash('Usuario creado correctamente.', 'success');
            }
        }
    } elseif ($user_id > 0) {
        switch ($action) {
            case 'editar':
                $nombre = trim($_POST['nombre'] ?? '');
                $email  = trim($_POST['email']  ?? '');
                $pass   = $_POST['password']    ?? '';
                $sexo   = in_array($_POST['sexo']   ?? '', ['M','F'])                        ? $_POST['sexo']   : 'M';
                $liga   = in_array($_POST['liga']   ?? '', array_keys($LIGAS))                ? $_POST['liga']   : '';
                $rol    = in_array($_POST['rol']    ?? '', ['socio','admin'])                  ? $_POST['rol']    : 'socio';
                $estado = in_array($_POST['estado'] ?? '', ['pendiente','activo','rechazado'])? $_POST['estado'] : 'activo';

                if (!$nombre || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    flash('Nombre y email válido son obligatorios.', 'danger');
                    break;
                }
                $check = $pdo->prepare('SELECT id FROM users WHERE email=? AND id!=?');
                $check->execute([$email, $user_id]);
                if ($check->fetch()) {
                    flash('El email ya está en uso por otro usuario.', 'danger');
                    break;
                }
                $force_pwd = isset($_POST['must_change_pwd']) ? 1 : 0;
                if ($pass) {
                    $pdo->prepare('UPDATE users SET nombre=?,email=?,password=?,sexo=?,liga=?,rol=?,estado=?,must_change_pwd=?,updated_at=NOW() WHERE id=?')
                        ->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), $sexo, $liga ?: null, $rol, $estado, $force_pwd, $user_id]);
                } else {
                    $pdo->prepare('UPDATE users SET nombre=?,email=?,sexo=?,liga=?,rol=?,estado=?,must_change_pwd=?,updated_at=NOW() WHERE id=?')
                        ->execute([$nombre, $email, $sexo, $liga ?: null, $rol, $estado, $force_pwd, $user_id]);
                }
                flash('Usuario actualizado.', 'success');
                break;
            case 'aprobar':
                $pdo->prepare('UPDATE users SET estado=\'activo\', updated_at=NOW() WHERE id=?')
                    ->execute([$user_id]);
                flash('Usuario aprobado correctamente.', 'success');
                break;
            case 'rechazar':
                $pdo->prepare('UPDATE users SET estado=\'rechazado\', updated_at=NOW() WHERE id=?')
                    ->execute([$user_id]);
                flash('Usuario rechazado.', 'warning');
                break;
            case 'cambiar_liga':
                $liga = $_POST['liga'] ?? '';
                if (in_array($liga, array_keys($LIGAS))) {
                    $pdo->prepare('UPDATE users SET liga=?, updated_at=NOW() WHERE id=?')
                        ->execute([$liga, $user_id]);
                    flash('Categoría actualizada.', 'success');
                }
                break;
            case 'toggle_nadador':
                $val = (int)($_POST['nadador_activo'] ?? 0);
                $pdo->prepare('UPDATE users SET nadador_activo=?, updated_at=NOW() WHERE id=?')
                    ->execute([$val ? 1 : 0, $user_id]);
                flash($val ? 'Nadador marcado como activo.' : 'Nadador marcado como no activo.', 'success');
                break;
            case 'cambiar_rol':
                $rol = $_POST['rol'] ?? '';
                if (in_array($rol, ['socio','admin'])) {
                    $pdo->prepare('UPDATE users SET rol=?, updated_at=NOW() WHERE id=?')
                        ->execute([$rol, $user_id]);
                    flash('Rol actualizado.', 'success');
                }
                break;
            case 'autologin':
                $target = $pdo->prepare('SELECT * FROM users WHERE id=? AND rol!=\'admin\'');
                $target->execute([$user_id]);
                $target_user = $target->fetch();
                if ($target_user) {
                    // Guardar sesión admin para poder volver
                    $_SESSION['admin_original'] = $_SESSION['user'];
                    $_SESSION['user'] = [
                        'id'    => $target_user['id'],
                        'nombre'   => $target_user['nombre'],
                        'email' => $target_user['email'],
                        'rol'   => $target_user['rol'],
                        'liga' => $target_user['liga'],
                        'sexo'  => $target_user['sexo'],
                        'nadador_activo' => (int)$target_user['nadador_activo'],
                        'avatar_url' => $target_user['avatar_url'] ?? null,
                        'must_change_pwd' => 0,
                        'tutor_email' => $target_user['tutor_email'] ?? null,
                    ];
                    flash('Sesión iniciada como ' . $target_user['nombre'] . '. Usa el botón "Volver a admin" para regresar.', 'info');
                    header('Location: /socio/panel');
                    exit;
                }
                break;
            case 'eliminar':
                $pdo->prepare('DELETE FROM users WHERE id=? AND rol!=\'admin\'')
                    ->execute([$user_id]);
                flash('Usuario eliminado.', 'danger');
                break;
        }
    }
    $redir = '/admin/usuarios';
    $rp = [];
    if (isset($_GET['estado'])  && $_GET['estado']  !== 'todos') $rp[] = 'estado='  . urlencode($_GET['estado']);
    if (isset($_GET['liga'])    && $_GET['liga']    !== 'todos') $rp[] = 'liga='    . urlencode($_GET['liga']);
    if (isset($_GET['nadador']) && $_GET['nadador'] !== 'todos') $rp[] = 'nadador=' . urlencode($_GET['nadador']);
    if (isset($_GET['q'])       && trim($_GET['q']) !== '')      $rp[] = 'q='       . urlencode(trim($_GET['q']));
    if ($rp) $redir .= '?' . implode('&', $rp);
    header('Location: ' . $redir);
    exit;
}

// --- Filtros ---
$filtroEstado = $_GET['estado'] ?? 'activo';
$filtroLiga   = $_GET['liga']   ?? 'todos';
$filtroNadador = $_GET['nadador'] ?? 'activo';
$filtroBuscar = trim($_GET['q'] ?? '');
$validos = ['todos','pendiente','activo','rechazado'];
if (!in_array($filtroEstado, $validos)) $filtroEstado = 'todos';
$ligasValidas = array_keys($LIGAS);
if ($filtroLiga !== 'todos' && !in_array($filtroLiga, $ligasValidas)) $filtroLiga = 'todos';
if (!in_array($filtroNadador, ['todos','activo','no_activo'])) $filtroNadador = 'todos';

$where  = [];
$params = [];
if ($filtroEstado !== 'todos') {
    $where[]  = 'estado = ?';
    $params[] = $filtroEstado;
}
if ($filtroLiga !== 'todos') {
    $where[]  = 'liga = ?';
    $params[] = $filtroLiga;
}
if ($filtroNadador === 'activo') {
    $where[] = 'nadador_activo = 1';
} elseif ($filtroNadador === 'no_activo') {
    $where[] = 'nadador_activo = 0';
}
if ($filtroBuscar !== '') {
    $where[]  = 'nombre LIKE ?';
    $params[] = '%' . $filtroBuscar . '%';
}
$sql = 'SELECT id,nombre,email,rol,estado,liga,sexo,rfen_id,rfen_nombre,nadador_activo,must_change_pwd,tutor_email,created_at FROM users';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Cuenta por estado
$counts = $pdo->query('SELECT estado, COUNT(*) as n FROM users GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);

render_header('Gestión de usuarios', 'admin-usuarios');
render_admin_layout('usuarios', function() use ($users, $filtroEstado, $filtroLiga, $filtroNadador, $filtroBuscar, $counts, $LIGAS) {
?>

<style>
.dropdown-item {
  display:flex;align-items:center;gap:8px;width:100%;padding:8px 16px;
  border:none;background:none;font-size:13px;font-weight:500;color:#333;
  cursor:pointer;text-align:left;white-space:nowrap;
}
.dropdown-item:hover { background:#f5f5f5; }
.dropdown-item i { width:16px;text-align:center; }
</style>

<div class="d-flex justify-between align-center mb-6" style="gap:12px;">
  <h1 style="margin:0;">Gestión de usuarios</h1>
  <button class="btn btn-primary" onclick="abrirModalCrear()">
    <i class="bi bi-person-plus-fill"></i> Nuevo usuario
  </button>
</div>

<?php render_flash(); ?>

<!-- Filtros -->
<div class="filters-bar" style="margin-bottom:16px;flex-wrap:wrap;">
  <?php
  $estados = ['todos' => 'Todos', 'pendiente' => 'Pendientes', 'activo' => 'Aprobados', 'rechazado' => 'Rechazados'];
  foreach ($estados as $val => $label):
    $count = $val === 'todos' ? array_sum($counts) : ($counts[$val] ?? 0);
    $active = $filtroEstado === $val ? 'btn-primary' : 'btn-gray';
    $params = 'estado=' . $val;
    if ($filtroLiga !== 'todos') $params .= '&liga=' . urlencode($filtroLiga);
    if ($filtroNadador !== 'todos') $params .= '&nadador=' . urlencode($filtroNadador);
    if ($filtroBuscar !== '') $params .= '&q=' . urlencode($filtroBuscar);
  ?>
    <a href="?<?= $params ?>" class="btn btn-sm <?= $active ?>">
      <?= $label ?> <span class="badge badge-gray" style="margin-left:4px;"><?= $count ?></span>
    </a>
  <?php endforeach; ?>

  <div style="width:100%;display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:12px;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex:1;min-width:200px;max-width:360px;margin:0;">
      <input type="hidden" name="estado" value="<?= e($filtroEstado) ?>">
      <input type="hidden" name="liga" value="<?= e($filtroLiga) ?>">
      <input type="text" name="q" class="form-control" placeholder="Buscar por nombre..." value="<?= e($filtroBuscar) ?>" style="padding:6px 10px;font-size:13px;flex:1;">
      <button type="submit" class="btn btn-primary btn-sm" style="height:34px;"><i class="bi bi-search"></i></button>
      <?php if ($filtroBuscar !== ''): ?>
        <a href="?estado=<?= e($filtroEstado) ?>&liga=<?= e($filtroLiga) ?>" class="btn btn-gray btn-sm" style="height:34px;" title="Limpiar"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </form>

    <select class="form-control" style="padding:6px 24px 6px 10px;font-size:13px;width:auto;min-width:170px;" onchange="window.location='?estado=<?= e($filtroEstado) ?>&liga='+this.value+'&nadador=<?= e($filtroNadador) ?><?= $filtroBuscar ? '&q=' . urlencode($filtroBuscar) : '' ?>'">
      <option value="todos" <?= $filtroLiga === 'todos' ? 'selected' : '' ?>>Todas las categorías</option>
      <?php foreach ($LIGAS as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $filtroLiga === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>

    <span style="font-size:12px;color:var(--gray);font-weight:600;margin-left:8px;">Nadador:</span>
    <?php
    $nadadorTabs = ['todos' => 'Todos', 'activo' => 'Activos', 'no_activo' => 'No activos'];
    foreach ($nadadorTabs as $nv => $nl):
      $nActive = $filtroNadador === $nv ? 'btn-primary' : 'btn-gray';
      $nParams = 'estado=' . e($filtroEstado) . '&liga=' . e($filtroLiga) . '&nadador=' . $nv;
      if ($filtroBuscar !== '') $nParams .= '&q=' . urlencode($filtroBuscar);
    ?>
      <a href="?<?= $nParams ?>" class="btn btn-sm <?= $nActive ?>"><?= $nl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Tabla -->
<div class="table-card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Usuario</th>
          <th>Categoría</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>Nadador</th>
          <th>RFEN</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="7" class="text-center text-muted" style="padding:32px;">No hay usuarios.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
        <tr>
          <!-- Usuario: nombre + email + sexo + tutor -->
          <td>
            <div style="display:flex;flex-direction:column;gap:2px;">
              <div>
                <strong><?= e($u['nombre']) ?></strong>
                <span class="text-muted" style="font-size:12px;margin-left:4px;"><?= $u['sexo'] === 'M' ? '♂' : '♀' ?></span>
                <?php if (in_array($u['liga'], ['benjamin','alevin','infantil','junior'])): ?>
                  <?php if ($u['tutor_email']): ?>
                    <span class="badge badge-green" style="font-size:10px;margin-left:4px;" title="Tutor: <?= e($u['tutor_email']) ?>"><i class="bi bi-person-check"></i></span>
                  <?php else: ?>
                    <span class="badge badge-warning" style="font-size:10px;margin-left:4px;" title="Pendiente autorización tutor"><i class="bi bi-exclamation-triangle"></i></span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <span class="text-muted" style="font-size:12px;"><?= e($u['email']) ?></span>
            </div>
          </td>
          <!-- Categoría -->
          <td>
            <?php if ($u['rol'] !== 'admin'): ?>
            <form method="POST" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cambiar_liga">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="liga" class="form-control" style="width:120px;padding:4px 8px;font-size:12px;" onchange="this.form.submit()">
                <option value="">— Sin —</option>
                <?php foreach ($LIGAS as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $u['liga'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <!-- Rol -->
          <td>
            <span class="badge badge-<?= $u['rol'] === 'admin' ? 'blue' : 'gray' ?>"><?= $u['rol'] === 'admin' ? 'Admin' : 'Socio' ?></span>
          </td>
          <!-- Estado -->
          <td>
            <?php
            $badges = ['pendiente'=>'warning','activo'=>'success','rechazado'=>'danger'];
            $labels = ['pendiente'=>'Pendiente','activo'=>'Activo','rechazado'=>'Rechazado'];
            $b = $badges[$u['estado']] ?? 'gray';
            $l = $labels[$u['estado']] ?? $u['estado'];
            ?>
            <span class="badge badge-<?= $b ?>"><?= $l ?></span>
          </td>
          <!-- Nadador -->
          <td>
            <?php if ($u['rol'] !== 'admin'): ?>
              <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_nadador">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <input type="hidden" name="nadador_activo" value="<?= $u['nadador_activo'] ? '0' : '1' ?>">
                <?php if ($u['nadador_activo']): ?>
                  <button type="submit" class="badge badge-green" style="cursor:pointer;border:none;font-size:11px;" title="Clic para desactivar">
                    <i class="bi bi-check-circle-fill"></i>&nbsp;Activo
                  </button>
                <?php else: ?>
                  <button type="submit" class="badge badge-gray" style="cursor:pointer;border:none;font-size:11px;" title="Clic para activar">
                    <i class="bi bi-x-circle"></i>&nbsp;No activo
                  </button>
                <?php endif; ?>
              </form>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <!-- RFEN -->
          <td>
            <?php if ($u['rfen_id']): ?>
              <span class="badge badge-success" title="<?= e($u['rfen_nombre']) ?>">Sí</span>
            <?php else: ?>
              <span class="badge badge-gray">No</span>
            <?php endif; ?>
          </td>
          <!-- Acciones dropdown -->
          <td>
            <div style="display:flex;align-items:center;gap:4px;">
              <!-- Editar -->
              <button class="btn btn-secondary btn-sm" title="Editar"
                onclick="abrirModalEditar(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['nombre']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['email']), ENT_QUOTES) ?>, '<?= e($u['sexo']) ?>', '<?= e($u['liga'] ?? '') ?>', '<?= e($u['rol']) ?>', '<?= e($u['estado']) ?>', <?= (int)$u['must_change_pwd'] ?>)">
                <i class="bi bi-pencil-fill"></i>
              </button>
              <?php if ($u['rol'] !== 'admin'): ?>
              <!-- Autologin -->
              <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="autologin">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-gray btn-sm" title="Entrar como este usuario"><i class="bi bi-box-arrow-in-right"></i></button>
              </form>
              <?php endif; ?>
              <!-- Dropdown con el resto -->
              <div class="dropdown" style="position:relative;">
                <button class="btn btn-gray btn-sm" onclick="toggleDropdown(this)">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
                <div class="dropdown-menu" style="display:none;position:absolute;right:0;top:100%;margin-top:4px;background:#fff;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,0.15);min-width:180px;z-index:100;padding:6px 0;">
                <?php if ($u['rol'] !== 'admin'): ?>
                  <!-- Estado -->
                  <?php if ($u['estado'] === 'pendiente'): ?>
                    <form method="POST">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="aprobar">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button type="submit" class="dropdown-item" style="color:#16a34a;"><i class="bi bi-check-circle-fill"></i> Aprobar</button>
                    </form>
                    <form method="POST">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="rechazar">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button type="submit" class="dropdown-item" style="color:var(--red);"><i class="bi bi-x-circle-fill"></i> Rechazar</button>
                    </form>
                  <?php elseif ($u['estado'] === 'activo'): ?>
                    <form method="POST">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="rechazar">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button type="submit" class="dropdown-item" style="color:#d97706;"><i class="bi bi-pause-circle-fill"></i> Desactivar</button>
                    </form>
                  <?php elseif ($u['estado'] === 'rechazado'): ?>
                    <form method="POST">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="aprobar">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button type="submit" class="dropdown-item" style="color:#16a34a;"><i class="bi bi-check-circle-fill"></i> Reactivar</button>
                    </form>
                  <?php endif; ?>
                  <!-- RFEN -->
                  <?php if ($u['rfen_id']): ?>
                    <form method="POST" data-confirm="¿Eliminar vinculación RFEN de <?= e($u['nombre']) ?>?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="desvincular_rfen">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button type="submit" class="dropdown-item"><i class="bi bi-link"></i> Desvincular RFEN</button>
                    </form>
                  <?php else: ?>
                    <button class="dropdown-item" onclick="abrirModalRFEN(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['nombre']), ENT_QUOTES) ?>, '<?= e($u['sexo']) ?>')">
                      <i class="bi bi-link-45deg"></i> Vincular RFEN
                    </button>
                  <?php endif; ?>
                  <!-- Rol -->
                  <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cambiar_rol">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="rol" value="<?= $u['rol'] === 'socio' ? 'admin' : 'socio' ?>">
                    <button type="submit" class="dropdown-item"><i class="bi bi-shield-fill"></i> Hacer <?= $u['rol'] === 'socio' ? 'admin' : 'socio' ?></button>
                  </form>
                  <!-- Eliminar -->
                  <div style="border-top:1px solid #f0f0f0;margin:4px 0;"></div>
                  <form method="POST" data-confirm="¿Eliminar a <?= e($u['nombre']) ?>? Esta acción no se puede deshacer.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="eliminar">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="dropdown-item" style="color:var(--red);"><i class="bi bi-trash-fill"></i> Eliminar</button>
                  </form>
                <?php endif; ?>
                </div>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Crear usuario -->
<div class="modal-overlay" id="modal-crear" style="display:none;">
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
            <input type="text" name="nombre" class="form-control" required autofocus>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input type="email" name="email" class="form-control" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Contraseña *</label>
          <div class="input-password-wrapper">
            <input type="password" name="password" id="crear-password" class="form-control" required placeholder="Mínimo 8 caracteres" autocomplete="new-password">
            <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1" aria-label="Mostrar contraseña">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <button type="button" class="btn btn-gray btn-sm" style="margin-top:6px;" onclick="generarPassword()">
            <i class="bi bi-key-fill"></i> Generar contraseña
          </button>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Sexo</label>
            <select name="sexo" class="form-control">
              <option value="M">Masculino</option>
              <option value="F">Femenino</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Liga / Categoría</label>
            <select name="liga" class="form-control">
              <option value="">— Sin liga —</option>
              <?php foreach ($LIGAS as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Rol</label>
            <select name="rol" class="form-control">
              <option value="socio">Socio</option>
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
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="nadador_activo" value="1" checked>
            <span class="form-label" style="margin:0;">Nadador activo</span>
          </label>
          <div class="form-hint">Desmarca si solo quieres guardar sus marcas antiguas sin acceso completo.</div>
        </div>
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="must_change_pwd" value="1" checked>
            <span class="form-label" style="margin:0;">Obligar a cambiar contraseña en primer login</span>
          </label>
          <div class="form-hint">El usuario deberá establecer su propia contraseña la primera vez que acceda.</div>
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
            <input type="text" name="nombre" id="edit-nombre" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input type="email" name="email" id="edit-email" class="form-control" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Nueva contraseña</label>
          <div class="input-password-wrapper">
            <input type="password" name="password" id="edit-password" class="form-control" autocomplete="new-password" placeholder="Dejar en blanco para no cambiar">
            <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1" aria-label="Mostrar">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div class="form-hint">Solo rellena si quieres cambiar la contraseña.</div>
        </div>
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="must_change_pwd" id="edit-must-change-pwd" value="1">
            <span class="form-label" style="margin:0;">Obligar a cambiar contraseña en próximo login</span>
          </label>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Sexo</label>
            <select name="sexo" id="edit-sexo" class="form-control">
              <option value="M">Masculino</option>
              <option value="F">Femenino</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Liga / Categoría</label>
            <select name="liga" id="edit-liga" class="form-control">
              <option value="">— Sin liga —</option>
              <?php foreach ($LIGAS as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Rol</label>
            <select name="rol" id="edit-rol" class="form-control">
              <option value="socio">Socio</option>
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
      <div id="rfen-resultados" style="margin-top:16px;"></div>
      <form method="POST" id="rfen-vincular-form" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action"       value="vincular_rfen">
        <input type="hidden" name="user_id"      id="rfen-user-id">
        <input type="hidden" name="rfen_id"      id="rfen-id-val">
        <input type="hidden" name="rfen_nombre"  id="rfen-nombre-val">
      </form>
    </div>
  </div>
</div>

<script>
let rfenUserId = 0;
let rfenSexo   = 'M';

function abrirModalRFEN(userId, nombre, sexo) {
  rfenUserId = userId;
  rfenSexo   = sexo;
  // Rellenar nombre y apellidos del socio
  const parts = nombre.trim().split(/\s+/);
  document.getElementById('rfen-nombre').value   = parts[0] || '';
  document.getElementById('rfen-apellidos').value = parts.slice(1).join(' ') || '';
  document.getElementById('rfen-resultados').innerHTML = '';
  document.getElementById('rfen-vincular-form').style.display = 'none';
  document.getElementById('modal-rfen').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function buscarRFEN() {
  const nombre = document.getElementById('rfen-nombre').value.trim();
  const cog    = document.getElementById('rfen-apellidos').value.trim();
  const btn    = document.getElementById('rfen-buscar-btn');
  const div    = document.getElementById('rfen-resultados');
  if (!nombre || !cog) { div.innerHTML = '<p class="text-danger text-sm">Introduce nombre y apellidos.</p>'; return; }
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Buscando...';
  div.innerHTML = '';
  fetch(`/admin/rfen_buscar?nombre=${encodeURIComponent(nombre)}&apellidos=${encodeURIComponent(cog)}&sexo=${rfenSexo}`)
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
        html += `<tr style="cursor:pointer;" onclick="seleccionarRFEN('${escHtml(r.rfen_id)}','${escHtml(r.rfen_nombre)}',${i})">
          <td><input type="radio" name="rfen_sel" id="rfen_r${i}"></td>
          <td>${escHtml(r.nombre)}</td><td>${escHtml(r.apellidos_cell)}</td><td>${escHtml(r.anio_nac)}</td>
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

function seleccionarRFEN(rfen_id, rfen_nombre, idx) {
  document.getElementById('rfen_r' + idx).checked = true;
  document.getElementById('rfen-user-id').value = rfenUserId;
  document.getElementById('rfen-id-val').value  = rfen_id;
  document.getElementById('rfen-nombre-val').value = rfen_nombre;
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
function abrirModalEditar(id, nombre, email, sexo, liga, rol, estado, mustChangePwd) {
  document.getElementById('edit-user-id').value = id;
  document.getElementById('edit-nombre').value  = nombre;
  document.getElementById('edit-email').value   = email;
  document.getElementById('edit-sexo').value    = sexo;
  document.getElementById('edit-liga').value     = liga;
  document.getElementById('edit-rol').value     = rol;
  document.getElementById('edit-estado').value  = estado;
  document.getElementById('edit-password').value = '';
  document.getElementById('edit-must-change-pwd').checked = !!mustChangePwd;
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

function toggleDropdown(btn) {
  const menu = btn.nextElementSibling;
  const isOpen = menu.style.display === 'block';
  document.querySelectorAll('.dropdown-menu').forEach(m => { m.style.display = 'none'; m.style.top = ''; m.style.bottom = ''; });
  if (!isOpen) {
    menu.style.display = 'block';
    const rect = menu.getBoundingClientRect();
    if (rect.bottom > window.innerHeight) {
      menu.style.top = 'auto';
      menu.style.bottom = '100%';
      menu.style.marginTop = '0';
      menu.style.marginBottom = '4px';
    }
  }
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.dropdown')) {
    document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
  }
});
function generarPassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
  let pwd = '';
  for (let i = 0; i < 12; i++) pwd += chars[Math.floor(Math.random() * chars.length)];
  const input = document.getElementById('crear-password');
  input.value = pwd;
  input.type = 'text';
  const eyeBtn = input.nextElementSibling;
  if (eyeBtn) eyeBtn.querySelector('i').className = 'bi bi-eye-slash';
}
</script>

<?php
});
render_footer();
