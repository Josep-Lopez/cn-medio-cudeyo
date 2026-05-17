<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_login();
$user = current_user();

// Avatar (no está en la sesión)
$db_avatar = $pdo->prepare('SELECT avatar_url FROM users WHERE id=?');
$db_avatar->execute([$user['id']]);
$avatar_url = $db_avatar->fetchColumn();

// Marcas del usuario agrupadas por estilo
$nadador_activo = is_nadador_activo();
$temporada_activa = $pdo->query("SELECT valor FROM config WHERE clave='temporada_activa' LIMIT 1")->fetchColumn() ?: '2025-26';

// Temporadas disponibles para este usuario
$stmt = $pdo->prepare('SELECT DISTINCT temporada FROM marcas WHERE user_id=? ORDER BY temporada DESC');
$stmt->execute([$user['id']]);
$temporadas_usuario = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($temporada_activa, $temporadas_usuario)) {
    array_unshift($temporadas_usuario, $temporada_activa);
}

// Temporada seleccionada (GET param o activa por defecto)
$temporada = $_GET['temporada'] ?? ($nadador_activo ? $temporada_activa : ($temporadas_usuario[0] ?? $temporada_activa));
$show_all = $temporada === 'todas';

if ($show_all) {
    $stmt = $pdo->prepare('SELECT * FROM marcas WHERE user_id=? ORDER BY prueba, piscina');
    $stmt->execute([$user['id']]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM marcas WHERE user_id=? AND temporada=? ORDER BY prueba, piscina');
    $stmt->execute([$user['id'], $temporada]);
}
$all_marks = $stmt->fetchAll();

// Agrupar por estilo
$grupos = [
    'Libre'    => ['50L','100L','200L','400L','800L','1500L'],
    'Espalda'  => ['50E','100E','200E'],
    'Braza'    => ['50B','100B','200B'],
    'Mariposa' => ['50M','100M','200M'],
    'Estilos'  => ['100X','200X','400X'],
];

// Indexar la mejor marca por prueba+piscina para evitar mostrar una peor al haber historial
$marcas = [];
foreach ($all_marks as $m) {
    $marcas[$m['prueba']] = $marcas[$m['prueba']] ?? [];
    if (
        !isset($marcas[$m['prueba']][$m['piscina']]) ||
        (float)$m['tiempo_seg'] < (float)$marcas[$m['prueba']][$m['piscina']]['tiempo_seg']
    ) {
        $marcas[$m['prueba']][$m['piscina']] = $m;
    }
}

// Récords del club: misma lógica que el ranking
// Actual = marca que sigue siendo la mejor del club (prueba+piscina+sexo)
// Batido = marca que fue récord en su momento pero ya fue superada
$records_stmt = $pdo->prepare("
    SELECT m.id, m.prueba, m.piscina, m.tiempo_seg, u.sexo
    FROM marcas m
    JOIN users u ON u.id = m.user_id
    WHERE m.user_id = ? AND u.estado = 'activo'
      AND NOT EXISTS (
        SELECT 1 FROM marcas m2
        JOIN users u2 ON u2.id = m2.user_id
        WHERE m2.prueba = m.prueba
          AND m2.piscina = m.piscina
          AND u2.sexo = u.sexo
          AND u2.estado = 'activo'
          AND m2.fecha_marca < m.fecha_marca
          AND m2.tiempo_seg <= m.tiempo_seg
      )
");
$records_stmt->execute([$user['id']]);
$user_records = $records_stmt->fetchAll();

$club_best_stmt = $pdo->query("
    SELECT m.prueba, m.piscina, u.sexo, MIN(m.tiempo_seg) AS best_seg
    FROM marcas m
    JOIN users u ON u.id = m.user_id
    WHERE u.estado = 'activo'
    GROUP BY m.prueba, m.piscina, u.sexo
");
$club_bests = [];
foreach ($club_best_stmt->fetchAll() as $cb) {
    $club_bests[$cb['prueba'] . '_' . $cb['piscina'] . '_' . $cb['sexo']] = (float)$cb['best_seg'];
}

$records_actuales_set = [];
$records_batidos = 0;
foreach ($user_records as $r) {
    $key = $r['prueba'] . '_' . $r['piscina'] . '_' . $r['sexo'];
    if (isset($club_bests[$key]) && (float)$r['tiempo_seg'] <= $club_bests[$key]) {
        $records_actuales_set[$r['prueba']] = true;
    } else {
        $records_batidos++;
    }
}
$records_actuales = count($records_actuales_set);

// Top 10 absoluto: en cuántas prueba+piscina está este usuario en el top 10
// Primero obtener las mejores marcas del usuario, luego contar posición por cada una
$user_best_stmt = $pdo->prepare("
    SELECT prueba, piscina, MIN(tiempo_seg) AS best_seg
    FROM marcas
    WHERE user_id = ?
    GROUP BY prueba, piscina
");
$user_best_stmt->execute([$user['id']]);
$user_bests = $user_best_stmt->fetchAll();

$user_top3 = ['25m' => 0, '50m' => 0];
$user_top10 = ['25m' => 0, '50m' => 0];
if ($user_bests) {
    $rank_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT m.user_id) AS ahead
        FROM marcas m
        JOIN users u ON u.id = m.user_id
        WHERE u.estado = 'activo' AND u.sexo = ?
          AND m.prueba = ? AND m.piscina = ?
          AND m.tiempo_seg < ?
    ");
    foreach ($user_bests as $ub) {
        $rank_stmt->execute([$user['sexo'], $ub['prueba'], $ub['piscina'], $ub['best_seg']]);
        $ahead = (int)$rank_stmt->fetchColumn();
        $pisc = $ub['piscina'];
        if (!isset($user_top3[$pisc])) continue;
        if ($ahead < 3) $user_top3[$pisc]++;
        if ($ahead < 10) $user_top10[$pisc]++;
    }
}
$user_top3_total = $user_top3['25m'] + $user_top3['50m'];
$user_top10_total = $user_top10['25m'] + $user_top10['50m'];

render_header('Mi panel', 'socio-panel');
?>

<div class="container page-content">
  <div class="panel-header mb-6">
    <div style="display:flex;align-items:center;gap:16px;">
      <?php if ($avatar_url): ?>
        <img src="<?= e($avatar_url) ?>" alt="<?= e($user['nombre']) ?>"
             style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:3px solid var(--blue);flex-shrink:0;">
      <?php else: ?>
        <div class="navbar-user-avatar" style="width:56px;height:56px;font-size:22px;flex-shrink:0;">
          <?= strtoupper(mb_substr($user['nombre'], 0, 1)) ?>
        </div>
      <?php endif; ?>
      <div>
        <h1 style="margin-bottom:2px;">Hola, <?= e($user['nombre']) ?></h1>
        <span class="text-muted">
          <?= e(format_liga($user['liga'] ?? '')) ?> · Temporada <?= $show_all ? 'Todas' : e($temporada) ?>
          · <?= $user['sexo'] === 'M' ? 'Masculino' : 'Femenino' ?>
        </span>
      </div>
    </div>
  </div>

  <?php render_flash(); ?>

  <?php if (!$nadador_activo): ?>
    <div class="alert alert-info" style="display:flex;align-items:center;gap:10px;">
      <i class="bi bi-info-circle-fill" style="font-size:20px;flex-shrink:0;"></i>
      <span>Tu cuenta está marcada como <strong>nadador no activo</strong>. Aquí puedes consultar tus últimas marcas registradas.</span>
    </div>
  <?php endif; ?>

  <?php if ($nadador_activo): ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
    <a href="/socio/ranking" class="card" style="text-decoration:none;display:flex;align-items:center;gap:14px;padding:16px 20px;transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow=''">
      <div style="font-size:24px;color:var(--blue);"><i class="bi bi-trophy-fill"></i></div>
      <div>
        <div style="font-weight:700;font-size:14px;">Ranking de mi liga</div>
        <div class="text-muted text-sm"><?= e(format_liga($user['liga'] ?? '')) ?></div>
      </div>
    </a>
    <a href="/calculadoras" class="card" style="text-decoration:none;display:flex;align-items:center;gap:14px;padding:16px 20px;transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow=''">
      <div style="font-size:24px;color:var(--blue);"><i class="bi bi-calculator-fill"></i></div>
      <div>
        <div style="font-weight:700;font-size:14px;">Calculadoras</div>
        <div class="text-muted text-sm">AQUA, mínimas y parciales</div>
      </div>
    </a>
    <div class="card" style="display:flex;align-items:center;gap:14px;padding:16px 20px;">
      <div style="font-size:24px;color:#15803d;"><i class="bi bi-trophy-fill"></i></div>
      <div>
        <div style="font-weight:700;font-size:14px;">Récords del club</div>
        <div style="display:flex;gap:12px;margin-top:2px;">
          <span style="font-size:13px;font-weight:700;color:#15803d;" title="Récords vigentes"><?= $records_actuales ?> actual<?= $records_actuales !== 1 ? 'es' : '' ?></span>
          <span style="font-size:13px;font-weight:700;color:#a16207;" title="Récords batidos"><?= $records_batidos ?> batido<?= $records_batidos !== 1 ? 's' : '' ?></span>
        </div>
      </div>
    </div>
    <div class="card" style="padding:16px 20px;">
      <div style="font-weight:700;font-size:14px;margin-bottom:10px;"><i class="bi bi-star-fill" style="color:#d97706;"></i> Ranking del club</div>
      <div style="display:flex;gap:16px;">
        <div style="text-align:center;flex:1;">
          <div style="font-size:26px;font-weight:800;color:<?= $user_top3_total > 0 ? '#b45309' : 'var(--gray)' ?>;"><?= $user_top3_total ?></div>
          <span style="display:inline-block;background:#fef3c7;color:#92400e;font-size:13px;font-weight:700;padding:3px 10px;border-radius:6px;border:1px solid #fde68a;margin-top:4px;">TOP 3</span>
          <div class="text-muted text-sm" style="margin-top:6px;font-size:11px;">25m: <strong style="color:#111;"><?= $user_top3['25m'] ?></strong> · 50m: <strong style="color:#111;"><?= $user_top3['50m'] ?></strong></div>
        </div>
        <div style="width:1px;background:#e5e7eb;"></div>
        <div style="text-align:center;flex:1;">
          <div style="font-size:26px;font-weight:800;color:<?= $user_top10_total > 0 ? '#d97706' : 'var(--gray)' ?>;"><?= $user_top10_total ?></div>
          <span style="display:inline-block;background:#fff7ed;color:#a16207;font-size:13px;font-weight:700;padding:3px 10px;border-radius:6px;border:1px solid #fed7aa;margin-top:4px;">TOP 10</span>
          <div class="text-muted text-sm" style="margin-top:6px;font-size:11px;">25m: <strong style="color:#111;"><?= $user_top10['25m'] ?></strong> · 50m: <strong style="color:#111;"><?= $user_top10['50m'] ?></strong></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Mis marcas -->
  <div class="card mb-6">
    <div class="card-header">
      <h2 class="card-title"><i class="bi bi-stopwatch-fill"></i> Mis marcas — Temporada <?= $show_all ? 'Todas' : e($temporada) ?></h2>
      <div class="d-flex gap-2 align-center flex-wrap">
        <select class="form-control" style="width:auto;min-width:130px;font-size:13px;padding:5px 10px;" onchange="window.location.href='/socio/panel?temporada='+this.value">
          <?php foreach ($temporadas_usuario as $t): ?>
            <option value="<?= e($t) ?>" <?= $temporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
          <option value="todas" <?= $show_all ? 'selected' : '' ?>>Todas</option>
        </select>
        <span class="badge badge-blue" id="pistBadge" style="cursor:pointer;" onclick="togglePiscina()"><i class="bi bi-water"></i> 25m</span>
      </div>
    </div>

    <?php if (!$all_marks): ?>
      <div class="text-center text-muted" style="padding:32px;">
        <div style="font-size:32px;margin-bottom:12px;color:var(--blue);"><i class="bi bi-person-arms-up"></i></div>
        <p>Aún no tienes marcas registradas para esta temporada.</p>
        <p class="text-sm">El administrador puede añadirlas desde el panel de gestión.</p>
      </div>
    <?php else: ?>

    <!-- Tabs piscina -->
    <div id="piscina-25" class="piscina-panel">
      <?php foreach ($grupos as $grupo => $pruebas): ?>
        <?php
        $hasMarcas = false;
        foreach ($pruebas as $p) if (isset($marcas[$p]['25m'])) { $hasMarcas = true; break; }
        if (!$hasMarcas) continue;
        ?>
        <div class="marks-section">
          <div class="marks-section-title"><?= $grupo ?></div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr><th>Prueba</th><th>Tiempo</th><th>Lugar</th><th>Fecha</th></tr>
              </thead>
              <tbody>
                <?php foreach ($pruebas as $prueba): ?>
                  <?php if (!isset($marcas[$prueba]['25m'])) continue; ?>
                  <?php $m = $marcas[$prueba]['25m']; ?>
                  <tr>
                    <td><?= e(format_prueba($prueba)) ?></td>
                    <td><span class="mark-time"><?= e($m['tiempo']) ?></span></td>
                    <td class="text-sm text-muted"><?= e($m['lugar'] ?? '') ?></td>
                    <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($m['fecha_marca'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div id="piscina-50" class="piscina-panel" style="display:none;">
      <?php foreach ($grupos as $grupo => $pruebas): ?>
        <?php
        $hasMarcas = false;
        foreach ($pruebas as $p) if (isset($marcas[$p]['50m'])) { $hasMarcas = true; break; }
        if (!$hasMarcas) continue;
        ?>
        <div class="marks-section">
          <div class="marks-section-title"><?= $grupo ?></div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr><th>Prueba</th><th>Tiempo</th><th>Lugar</th><th>Fecha</th></tr>
              </thead>
              <tbody>
                <?php foreach ($pruebas as $prueba): ?>
                  <?php if (!isset($marcas[$prueba]['50m'])) continue; ?>
                  <?php $m = $marcas[$prueba]['50m']; ?>
                  <tr>
                    <td><?= e(format_prueba($prueba)) ?></td>
                    <td><span class="mark-time"><?= e($m['tiempo']) ?></span></td>
                    <td class="text-sm text-muted"><?= e($m['lugar'] ?? '') ?></td>
                    <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($m['fecha_marca'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>
  </div>

  <?php if ($nadador_activo): ?>
  <!-- Calendario -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><i class="bi bi-calendar-event-fill"></i> Calendario</h2>
    </div>
    <div class="calendar-embed">
      <iframe
        src="https://calendar.google.com/calendar/embed?src=e5aa12a1773d829adc2af7b43f0c3420350b6d0418e1159d2adcd23c69929f81%40group.calendar.google.com&ctz=Europe%2FMadrid&hl=es"
        style="border:0;width:100%;height:500px;"
        frameborder="0"
        scrolling="no">
      </iframe>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
let pisc = '25';
function togglePiscina() {
  pisc = pisc === '25' ? '50' : '25';
  document.getElementById('piscina-25').style.display = pisc === '25' ? '' : 'none';
  document.getElementById('piscina-50').style.display = pisc === '50' ? '' : 'none';
  const badge = document.getElementById('pistBadge');
  badge.textContent = '';
  const icon = document.createElement('i');
  icon.className = 'bi bi-water';
  badge.appendChild(icon);
  badge.appendChild(document.createTextNode(' ' + pisc + 'm'));
}
</script>

<?php render_footer(); ?>
