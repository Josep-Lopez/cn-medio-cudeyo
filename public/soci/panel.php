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
$temporada = $pdo->query("SELECT valor FROM config WHERE clau='temporada_activa' LIMIT 1")->fetchColumn() ?: '2025-26';
$stmt = $pdo->prepare('SELECT * FROM marques WHERE user_id=? AND temporada=? ORDER BY prova, piscina');
$stmt->execute([$user['id'], $temporada]);
$all_marks = $stmt->fetchAll();

// Agrupar por estilo
$grupos = [
    'Libre'    => ['50L','100L','200L','400L','800L','1500L'],
    'Espalda'  => ['50E','100E','200E'],
    'Braza'    => ['50B','100B','200B'],
    'Mariposa' => ['50M','100M','200M'],
    'Estilos'  => ['100X','200X','400X'],
];

// Indexar la mejor marca por prova+piscina para evitar mostrar una peor al haber historial
$marcas = [];
foreach ($all_marks as $m) {
    $marcas[$m['prova']] = $marcas[$m['prova']] ?? [];
    if (
        !isset($marcas[$m['prova']][$m['piscina']]) ||
        (float)$m['temps_seg'] < (float)$marcas[$m['prova']][$m['piscina']]['temps_seg']
    ) {
        $marcas[$m['prova']][$m['piscina']] = $m;
    }
}

render_header('Mi panel', 'soci-panel');
?>

<div class="container page-content">
  <div class="panel-header mb-6">
    <div style="display:flex;align-items:center;gap:16px;">
      <?php if ($avatar_url): ?>
        <img src="<?= e($avatar_url) ?>" alt="<?= e($user['nom']) ?>"
             style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:3px solid var(--blue);flex-shrink:0;">
      <?php else: ?>
        <div class="navbar-user-avatar" style="width:56px;height:56px;font-size:22px;flex-shrink:0;">
          <?= strtoupper(mb_substr($user['nom'], 0, 1)) ?>
        </div>
      <?php endif; ?>
      <div>
        <h1 style="margin-bottom:2px;">Hola, <?= e($user['nom']) ?></h1>
        <span class="text-muted">
          <?= e(format_lliga($user['lliga'] ?? '')) ?> · Temporada <?= $temporada ?>
          · <?= $user['sexe'] === 'M' ? 'Masculino' : 'Femenino' ?>
        </span>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="/soci/perfil" class="btn btn-gray btn-sm"><i class="bi bi-person-gear"></i> Mi perfil</a>
      <a href="/soci/ranking" class="btn btn-secondary btn-sm">Ver ranking mi liga</a>
    </div>
  </div>

  <?php render_flash(); ?>

  <!-- Mis marcas -->
  <div class="card mb-6">
    <div class="card-header">
      <h2 class="card-title"><i class="bi bi-stopwatch-fill"></i> Mis marcas — Temporada <?= $temporada ?></h2>
      <div class="d-flex gap-2">
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
      <?php foreach ($grupos as $grupo => $proves): ?>
        <?php
        $hasMarcas = false;
        foreach ($proves as $p) if (isset($marcas[$p]['25m'])) { $hasMarcas = true; break; }
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
                <?php foreach ($proves as $prova): ?>
                  <?php if (!isset($marcas[$prova]['25m'])) continue; ?>
                  <?php $m = $marcas[$prova]['25m']; ?>
                  <tr>
                    <td><?= e(format_prova($prova)) ?></td>
                    <td><span class="mark-time"><?= e($m['temps']) ?></span></td>
                    <td class="text-sm text-muted"><?= e($m['lugar'] ?? '') ?></td>
                    <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($m['data_marca'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div id="piscina-50" class="piscina-panel" style="display:none;">
      <?php foreach ($grupos as $grupo => $proves): ?>
        <?php
        $hasMarcas = false;
        foreach ($proves as $p) if (isset($marcas[$p]['50m'])) { $hasMarcas = true; break; }
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
                <?php foreach ($proves as $prova): ?>
                  <?php if (!isset($marcas[$prova]['50m'])) continue; ?>
                  <?php $m = $marcas[$prova]['50m']; ?>
                  <tr>
                    <td><?= e(format_prova($prova)) ?></td>
                    <td><span class="mark-time"><?= e($m['temps']) ?></span></td>
                    <td class="text-sm text-muted"><?= e($m['lugar'] ?? '') ?></td>
                    <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($m['data_marca'])) ?></td>
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

  <!-- Accesos rápidos -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:32px;">
    <a href="/soci/ranking" class="card" style="text-decoration:none;display:block;transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow=''">
      <div style="font-size:28px;margin-bottom:10px;color:var(--blue);"><i class="bi bi-trophy-fill"></i></div>
      <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Ranking de mi liga</div>
      <div class="text-muted text-sm">Ver el ranking de <?= e(format_lliga($user['lliga'] ?? '')) ?></div>
    </a>
    <a href="/calculadoras" class="card" style="text-decoration:none;display:block;transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 20px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow=''">
      <div style="font-size:28px;margin-bottom:10px;color:var(--blue);"><i class="bi bi-bar-chart-fill"></i></div>
      <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Calculadoras</div>
      <div class="text-muted text-sm">Puntos AQUA, mínimas RFEN y parciales</div>
    </a>
  </div>

  <!-- Calendario -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><i class="bi bi-calendar-event-fill"></i> Calendario</h2>
    </div>
    <div class="calendar-embed">
      <iframe
        src="https://calendar.google.com/calendar/embed?src=f2cbdb814669d213dbd4b0f65ad28268882f62b21687e1009be0697343ebdae7%40group.calendar.google.com&ctz=Europe%2FMadrid&hl=es"
        style="border:0;width:100%;height:500px;"
        frameborder="0"
        scrolling="no">
      </iframe>
    </div>
  </div>
</div>

<script>
let pisc = '25';
function togglePiscina() {
  pisc = pisc === '25' ? '50' : '25';
  document.getElementById('piscina-25').style.display = pisc === '25' ? '' : 'none';
  document.getElementById('piscina-50').style.display = pisc === '50' ? '' : 'none';
  document.getElementById('pistBadge').innerHTML = '<i class="bi bi-water"></i> ' + pisc + 'm';
}
</script>

<?php render_footer(); ?>
