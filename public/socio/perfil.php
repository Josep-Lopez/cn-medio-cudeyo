<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
$user = current_user();

$errors_perfil = [];
$errors_pass   = [];

// ── Formulario A: Datos personales + foto ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'perfil') {
    csrf_verify();
    $nombre = trim($_POST['nombre'] ?? '');
    $email  = trim($_POST['email']  ?? '');

    if (!$nombre)                                     $errors_perfil[] = 'El nombre es obligatorio.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errors_perfil[] = 'El email no es válido.';

    // Procesar upload de foto
    $avatar = null; // null = no cambia
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $info = @getimagesize($_FILES['avatar']['tmp_name']);
        if (!$info) {
            $errors_perfil[] = 'El archivo no es una imagen válida.';
        } elseif (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
            $errors_perfil[] = 'Formato no permitido. Usa JPG, PNG o WebP.';
        } elseif ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            $errors_perfil[] = 'La imagen no puede superar los 2 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $errors_perfil[] = 'Extensión no permitida. Usa JPG, PNG, WebP o GIF.';
            } else {
            $filename = 'avatar_' . $user['id'] . '.' . $ext;
            $dir      = dirname(__DIR__, 2) . '/public/uploads/avatars/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dir . $filename)) {
                $avatar = '/uploads/avatars/' . $filename . '?v=' . time();
            } else {
                $errors_perfil[] = 'Error al guardar la imagen.';
            }
            } // end ext whitelist
        }
    } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors_perfil[] = 'Error al subir el archivo (código ' . $_FILES['avatar']['error'] . ').';
    }

    if (!$errors_perfil) {
        $dup = $pdo->prepare('SELECT id FROM users WHERE email=? AND id!=?');
        $dup->execute([$email, $user['id']]);
        if ($dup->fetch()) {
            $errors_perfil[] = 'Este email ya está en uso por otra cuenta.';
        } else {
            if ($avatar !== null) {
                $pdo->prepare('UPDATE users SET nombre=?, email=?, avatar_url=?, updated_at=NOW() WHERE id=?')
                    ->execute([$nombre, $email, $avatar, $user['id']]);
            } else {
                $pdo->prepare('UPDATE users SET nombre=?, email=?, updated_at=NOW() WHERE id=?')
                    ->execute([$nombre, $email, $user['id']]);
            }
            $_SESSION['user']['nombre'] = $nombre;
            $_SESSION['user']['email']  = $email;
            if ($avatar !== null) $_SESSION['user']['avatar_url'] = $avatar;
            $user = current_user();
            flash('Datos actualizados correctamente.', 'success');
            header('Location: /socio/perfil');
            exit;
        }
    }
}

// ── Formulario: Eliminar avatar ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_avatar') {
    csrf_verify();
    $pdo->prepare('UPDATE users SET avatar_url=NULL, updated_at=NOW() WHERE id=?')
        ->execute([$user['id']]);
    $_SESSION['user']['avatar_url'] = null;
    flash('Foto de perfil eliminada.', 'success');
    header('Location: /socio/perfil');
    exit;
}

// ── Formulario B: Cambiar contraseña ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    csrf_verify();
    $pass_actual  = $_POST['pass_actual']  ?? '';
    $pass_nueva   = $_POST['pass_nueva']   ?? '';
    $pass_confirm = $_POST['pass_confirm'] ?? '';

    if (!$pass_actual)               $errors_pass[] = 'Introduce tu contraseña actual.';
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $pass_nueva))
        $errors_pass[] = 'La nueva contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.';
    if ($pass_nueva !== $pass_confirm) $errors_pass[] = 'Las contraseñas nuevas no coinciden.';

    if (!$errors_pass) {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id=?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($pass_actual, $hash)) {
            $errors_pass[] = 'La contraseña actual es incorrecta.';
        } else {
            $pdo->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?')
                ->execute([password_hash($pass_nueva, PASSWORD_DEFAULT), $user['id']]);
            flash('Contraseña cambiada correctamente.', 'success');
            header('Location: /socio/perfil');
            exit;
        }
    }
}

// ── Datos del perfil ─────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT avatar_url, rfen_id, liga, created_at FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$user_db = $stmt->fetch();
$avatar_url = $user_db['avatar_url'];

// ── Estadísticas ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT COUNT(*) FROM marcas WHERE user_id=?');
$stmt->execute([$user['id']]);
$total_marcas = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(DISTINCT temporada) FROM marcas WHERE user_id=?');
$stmt->execute([$user['id']]);
$total_temporadas = (int)$stmt->fetchColumn();

// Mejor posición en ranking (de cualquier prueba, temporada actual)
$current_year = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporada_actual = $current_year . '-' . substr((string)($current_year + 1), 2);
$mejor_posicion = null;
$stmt = $pdo->prepare('
    SELECT prueba, piscina, MIN(tiempo_seg) as mejor
    FROM marcas WHERE user_id=? AND temporada=?
    GROUP BY prueba, piscina
');
$stmt->execute([$user['id'], $temporada_actual]);
$mis_mejores = $stmt->fetchAll();

foreach ($mis_mejores as $mm) {
    $stmtR = $pdo->prepare('
        SELECT COUNT(DISTINCT user_id) + 1 as pos
        FROM marcas m
        JOIN users u ON u.id = m.user_id
        WHERE m.prueba=? AND m.piscina=? AND m.temporada=?
          AND u.liga = ? AND u.sexo = ?
          AND m.tiempo_seg < ?
    ');
    $stmtR->execute([$mm['prueba'], $mm['piscina'], $temporada_actual, $user['liga'], $user['sexo'], $mm['mejor']]);
    $pos = (int)$stmtR->fetchColumn();
    if ($mejor_posicion === null || $pos < $mejor_posicion) {
        $mejor_posicion = $pos;
    }
}

// ── Mejores marcas personales (all-time) ─────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT m.prueba, m.piscina, m.tiempo_seg AS mejor_seg, m.tiempo AS mejor_tiempo, m.fecha_marca AS fecha_mejor
    FROM marcas m
    INNER JOIN (
        SELECT prueba, piscina, MIN(tiempo_seg) AS min_seg
        FROM marcas WHERE user_id=?
        GROUP BY prueba, piscina
    ) best ON m.prueba = best.prueba AND m.piscina = best.piscina AND m.tiempo_seg = best.min_seg
    WHERE m.user_id=?
    ORDER BY m.prueba, m.piscina
    LIMIT 100
');
$stmt->execute([$user['id'], $user['id']]);
$raw_marcas = $stmt->fetchAll();
// Deduplicate in case of ties (same min time)
$mejores_marcas = [];
foreach ($raw_marcas as $mm) {
    $key = $mm['prueba'] . '_' . $mm['piscina'];
    if (!isset($mejores_marcas[$key])) $mejores_marcas[$key] = $mm;
}
$mejores_marcas = array_values($mejores_marcas);

// ── Datos para gráfico de progresión (una sola query) ────────────────────────
$stmt = $pdo->prepare('
    SELECT prueba, fecha_marca, tiempo_seg, tiempo, piscina
    FROM marcas WHERE user_id=?
    ORDER BY prueba, fecha_marca ASC
');
$stmt->execute([$user['id']]);
$all_prog = $stmt->fetchAll();

$progresion_data = [];
foreach ($all_prog as $row) {
    $progresion_data[$row['prueba']][] = $row;
}
// Limitar a últimos 30 puntos por prueba
foreach ($progresion_data as $pr => &$rows) {
    if (count($rows) > 30) $rows = array_slice($rows, -30);
}
unset($rows);
$pruebas_usuario = array_keys($progresion_data);

// Colores por liga
$liga_colors = [
    'benjamin'  => ['#06b6d4', '#0891b2'],
    'alevin'    => ['#10b981', '#059669'],
    'infantil'  => ['#f59e0b', '#d97706'],
    'junior'    => ['#8b5cf6', '#7c3aed'],
    'absoluto'  => ['#093FB4', '#1565e8'],
    'master'    => ['#dc2626', '#b91c1c'],
];
$liga = $user['liga'] ?? 'absoluto';
$hero_colors = $liga_colors[$liga] ?? $liga_colors['absoluto'];

render_header('Mi perfil', 'socio-perfil');
?>

<style>
.profile-hero {
  background: linear-gradient(135deg, <?= $hero_colors[0] ?> 0%, <?= $hero_colors[1] ?> 100%);
  border-radius: 16px;
  padding: 48px 24px 36px;
  text-align: center;
  color: white;
  margin-bottom: 24px;
  position: relative;
}
.profile-hero h1 { color: white; font-size: 28px; margin: 16px 0 4px; }
.profile-hero p  { color: rgba(255,255,255,0.75); margin: 0; font-size: 15px; }
.profile-avatar-wrap {
  position: relative;
  display: inline-block;
  cursor: pointer;
}
.profile-avatar {
  width: 110px; height: 110px;
  border-radius: 50%;
  border: 4px solid rgba(255,255,255,0.85);
  overflow: hidden;
  background: rgba(255,255,255,0.18);
  display: flex; align-items: center; justify-content: center;
  font-size: 44px; font-weight: 700; color: white;
  margin: 0 auto;
}
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-avatar-overlay {
  position: absolute; inset: 0;
  border-radius: 50%;
  background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity 0.2s;
  font-size: 22px; color: white;
}
.profile-avatar-wrap:hover .profile-avatar-overlay { opacity: 1; }
.profile-back {
  position: absolute; top: 16px; left: 20px;
  color: rgba(255,255,255,0.75); text-decoration: none; font-size: 14px;
  display: flex; align-items: center; gap: 6px;
}
.profile-back:hover { color: white; }
.profile-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}
.stat-card {
  background: white;
  border-radius: 12px;
  padding: 20px 16px;
  text-align: center;
  box-shadow: 0 2px 8px rgba(0,0,0,0.06);
  border: 1px solid #eee;
}
.stat-card .stat-value {
  font-size: 28px;
  font-weight: 800;
  color: <?= $hero_colors[0] ?>;
  line-height: 1;
}
.stat-card .stat-label {
  font-size: 12px;
  color: var(--gray);
  margin-top: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.best-marks-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 10px;
}
.best-mark-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: #f8fafc;
  border-radius: 8px;
  border: 1px solid #eee;
}
.best-mark-item .prueba { font-weight: 600; font-size: 14px; }
.best-mark-item .tiempo { font-weight: 700; color: <?= $hero_colors[0] ?>; font-size: 15px; font-family: monospace; }
.best-mark-item .meta { font-size: 11px; color: var(--gray); }
.piscina-badge {
  display: inline-block;
  font-size: 10px;
  padding: 1px 5px;
  border-radius: 4px;
  background: #e8f0fe;
  color: #1a56db;
  font-weight: 600;
}
.profile-rfen {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  padding: 4px 12px;
  background: rgba(255,255,255,0.15);
  border-radius: 20px;
  font-size: 12px;
  color: rgba(255,255,255,0.9);
}
.delete-avatar-btn {
  position: absolute;
  top: 16px;
  right: 20px;
  background: rgba(255,255,255,0.15);
  border: none;
  color: rgba(255,255,255,0.75);
  font-size: 12px;
  padding: 4px 10px;
  border-radius: 6px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 4px;
}
.delete-avatar-btn:hover { background: rgba(255,255,255,0.25); color: white; }
@media (max-width: 767px) {
  .profile-stats { grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .stat-card { padding: 14px 8px; }
  .stat-card .stat-value { font-size: 22px; }
  .best-marks-grid { grid-template-columns: 1fr; }
}
</style>

<div class="container page-content" style="max-width:780px;">

  <?php render_flash(); ?>

  <!-- ── Hero ──────────────────────────────────────────────────────────────── -->
  <div class="profile-hero">
    <a href="/socio/panel" class="profile-back"><i class="bi bi-arrow-left"></i> Mi panel</a>

    <?php if ($avatar_url): ?>
      <button type="button" class="delete-avatar-btn" onclick="document.getElementById('deleteAvatarModal').style.display='flex'">
        <i class="bi bi-x-lg"></i> Quitar foto
      </button>
    <?php endif; ?>

    <label for="avatar-input" class="profile-avatar-wrap" title="Cambiar foto">
      <div class="profile-avatar" id="hero-avatar">
        <?php if ($avatar_url): ?>
          <img src="<?= e($avatar_url) ?>" alt="<?= e($user['nombre']) ?>">
        <?php else: ?>
          <?= strtoupper(mb_substr($user['nombre'], 0, 1)) ?>
        <?php endif; ?>
      </div>
      <div class="profile-avatar-overlay"><i class="bi bi-camera-fill"></i></div>
    </label>

    <h1><?= e($user['nombre']) ?></h1>
    <p><?= e(format_liga($user['liga'] ?? '')) ?> · <?= $user['sexo'] === 'M' ? 'Masculino' : 'Femenino' ?></p>
    <?php if (!empty($user_db['rfen_id'])): ?>
      <div class="profile-rfen"><i class="bi bi-patch-check-fill"></i> RFEN: <?= e($user_db['rfen_id']) ?></div>
    <?php endif; ?>
  </div>

  <!-- ── Datos personales ─────────────────────────────────────────────────── -->
  <div class="card mb-6">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;">Datos personales</h2>

    <?php if ($errors_perfil): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors_perfil as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="perfil">
      <input type="file" id="avatar-input" name="avatar"
             accept="image/jpeg,image/png,image/webp,image/gif"
             style="display:none;" onchange="previewAvatar(this)">

      <div class="form-group">
        <label class="form-label">Nombre *</label>
        <input type="text" name="nombre" class="form-control"
               value="<?= e($_POST['nombre'] ?? $user['nombre']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Email *</label>
        <input type="email" name="email" class="form-control"
               value="<?= e($_POST['email'] ?? $user['email']) ?>" required>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <a href="/socio/panel" class="btn btn-gray">Cancelar</a>
      </div>
    </form>
  </div>

  <!-- ── Cambiar contraseña ───────────────────────────────────────────────── -->
  <div class="card mb-6">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;">Cambiar contraseña</h2>

    <?php if ($errors_pass): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors_pass as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <div class="form-group">
        <label class="form-label">Contraseña actual *</label>
        <div class="input-password-wrapper">
          <input type="password" name="pass_actual" class="form-control" placeholder="••••••••" required>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Nueva contraseña *</label>
        <div class="input-password-wrapper">
          <input type="password" name="pass_nueva" class="form-control" placeholder="Mínimo 8 caracteres" required>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Confirmar nueva contraseña *</label>
        <div class="input-password-wrapper">
          <input type="password" name="pass_confirm" class="form-control" placeholder="Repite la nueva contraseña" required>
          <button type="button" class="toggle-password" onclick="togglePwd(this)" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Cambiar contraseña</button>
    </form>
  </div>

  <!-- ── Estadísticas ─────────────────────────────────────────────────────── -->
  <div class="profile-stats">
    <div class="stat-card">
      <div class="stat-value"><?= $total_marcas ?></div>
      <div class="stat-label">Marcas</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $mejor_posicion ? '#' . $mejor_posicion : '—' ?></div>
      <div class="stat-label">Mejor posición</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $total_temporadas ?></div>
      <div class="stat-label">Temporadas</div>
    </div>
  </div>

  <!-- ── Mejores marcas personales ────────────────────────────────────────── -->
  <?php if ($mejores_marcas): ?>
  <div class="card mb-6">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:16px;"><i class="bi bi-trophy"></i> Mejores marcas personales</h2>
    <div class="best-marks-grid" style="max-height:320px;overflow-y:auto;">
      <?php foreach ($mejores_marcas as $mm): ?>
        <div class="best-mark-item">
          <div>
            <div class="prueba"><?= e(format_prueba($mm['prueba'])) ?></div>
            <div class="meta"><span class="piscina-badge"><?= e($mm['piscina']) ?></span> <?= date('d/m/Y', strtotime($mm['fecha_mejor'])) ?></div>
          </div>
          <div class="tiempo"><?= e($mm['mejor_tiempo']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Gráfico de progresión ────────────────────────────────────────────── -->
  <?php if ($pruebas_usuario): ?>
  <div class="card mb-6">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
      <h2 style="font-size:16px;font-weight:700;margin:0;"><i class="bi bi-graph-down"></i> Progresión</h2>
      <select id="progresion-prueba" class="form-control" style="width:auto;min-width:160px;">
        <?php foreach ($pruebas_usuario as $pr): ?>
          <option value="<?= e($pr) ?>"><?= e(format_prueba($pr)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="position:relative;height:250px;">
      <canvas id="progresionChart"></canvas>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php if ($avatar_url): ?>
<div id="deleteAvatarModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:200;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:12px;padding:32px;max-width:380px;width:100%;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2);text-align:center;">
    <div style="font-size:40px;margin-bottom:12px;"><i class="bi bi-trash3" style="color:var(--red);"></i></div>
    <h3 style="margin-bottom:8px;">¿Eliminar foto de perfil?</h3>
    <p style="color:var(--gray);margin-bottom:20px;">Se volverá a mostrar tu inicial como avatar.</p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_avatar">
      <div class="d-flex gap-2" style="justify-content:center;">
        <button type="submit" class="btn btn-danger">Eliminar</button>
        <button type="button" class="btn btn-gray" onclick="document.getElementById('deleteAvatarModal').style.display='none'">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
function previewAvatar(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function(ev) {
    const container = document.getElementById('hero-avatar');
    container.textContent = '';
    const img = document.createElement('img');
    img.src = ev.target.result;
    img.alt = 'preview';
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
    container.appendChild(img);
  };
  reader.readAsDataURL(input.files[0]);
}
function togglePwd(btn) {
  const input = btn.previousElementSibling;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}

// ── Chart.js progresión ──────────────────────────────────────────────────────
<?php if ($pruebas_usuario): ?>
const progresionData = <?= json_encode($progresion_data, JSON_UNESCAPED_UNICODE) ?>;
const heroColor = '<?= $hero_colors[0] ?>';
const select = document.getElementById('progresion-prueba');
let chart = null;

function renderChart(prueba) {
  const data = progresionData[prueba] || [];
  if (!data.length) return;

  const labels = data.map(d => d.fecha_marca);
  const values = data.map(d => parseFloat(d.tiempo_seg));
  const tiempos = data.map(d => d.tiempo);

  const ctx = document.getElementById('progresionChart').getContext('2d');
  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Tiempo (s)',
        data: values,
        borderColor: heroColor,
        backgroundColor: heroColor + '20',
        fill: true,
        tension: 0.3,
        pointRadius: 5,
        pointHoverRadius: 7,
        pointBackgroundColor: heroColor
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(context) {
              return tiempos[context.dataIndex] + ' (' + data[context.dataIndex].piscina + ')';
            }
          }
        }
      },
      scales: {
        x: {
          ticks: { maxTicksLimit: 8, font: { size: 11 } },
          grid: { display: false }
        },
        y: {
          reverse: false,
          ticks: {
            font: { size: 11 },
            callback: function(value) {
              const m = Math.floor(value / 60);
              const s = (value % 60).toFixed(2).padStart(5, '0');
              return m > 0 ? m + ':' + s : parseFloat(s).toFixed(2);
            }
          }
        }
      }
    }
  });
}

select.addEventListener('change', () => renderChart(select.value));
renderChart(select.value);
<?php endif; ?>
</script>

<?php render_footer(); ?>
