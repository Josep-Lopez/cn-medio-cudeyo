<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';

require_login();
$user = current_user();
$tiempos = listar_tiempos_socio($pdo, (int)$user['id']);
$convocatorias = listar_convocatorias_socio($pdo, (int)$user['id']);

// Próximas primero (la más cercana arriba); anteriores de la más reciente a la más antigua
$hoy = date('Y-m-d');
$proximas = array_reverse(array_values(array_filter($convocatorias, fn($c) => $c['fecha'] >= $hoy)));
$anteriores = array_values(array_filter($convocatorias, fn($c) => $c['fecha'] < $hoy));

// Comunicados enviados a los convocados de cada competición
$comunicados = [];
if ($convocatorias) {
    $ids = array_map(fn($c) => (string)$c['id'], $convocatorias);
    $stmt = $pdo->prepare("
        SELECT id, titulo, tipo, destinatario_valor FROM comunicaciones
        WHERE destinatario_tipo = 'convocados' AND destinatario_valor IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
        ORDER BY created_at DESC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $m) $comunicados[(int)$m['destinatario_valor']][] = $m;
}

$stmt = $pdo->prepare('SELECT id, nombre, sexo, fecha_nacimiento FROM users WHERE id = ?');
$stmt->execute([(int)$user['id']]);
$yo = $stmt->fetch();

render_header('Mis competiciones', 'socio-competiciones');
?>
<main class="container" style="padding:24px 16px;">
  <h1>Mis convocatorias</h1>
  <?php if (!$convocatorias): ?>
    <div class="card text-center mb-6" style="padding:32px;">
      <p class="text-muted">Aún no te han convocado a ninguna competición.</p>
    </div>
  <?php endif; ?>
  <?php foreach (['Próximas' => $proximas, 'Anteriores' => $anteriores] as $grupo_label => $grupo): ?>
    <?php if (!$grupo) continue; ?>
    <h2 style="font-size:16px;margin:0 0 10px;"><?= $grupo_label ?></h2>
    <div class="ce-convocados mb-6">
      <?php foreach ($grupo as $c): ?>
        <div class="card ce-convocatoria-socio">
          <div class="ce-nadador__head">
            <div>
              <strong><?= e($c['nombre']) ?></strong>
              <span class="text-muted text-sm"><?= date('d/m/Y', strtotime($c['fecha'])) ?><?= $c['lugar'] ? ' · ' . e($c['lugar']) : '' ?> · Piscina <?= e($c['piscina']) ?></span>
            </div>
          </div>
          <?php if ($c['pruebas']): ?>
            <div class="table-wrapper">
              <table class="ce-pruebas">
                <thead><tr><th>Prueba</th><th>Marca de inscripción</th><th>Mínima</th></tr></thead>
                <tbody>
                  <?php foreach ($c['pruebas'] as $p): ?>
                    <tr>
                      <td><?= e(format_prueba($p['prueba'])) ?></td>
                      <td>
                        <?php if ($p['marca_seg']): ?>
                          <strong><?= e($p['marca_tiempo']) ?></strong>
                          <span class="text-muted text-sm"><?= e($p['marca_piscina']) ?></span>
                        <?php else: ?>
                          <span class="text-muted">Sin marca</span>
                        <?php endif; ?>
                      </td>
                      <td><?= render_ce_minima_badge(ce_estado_minima($pdo, $c, $yo, $p)) ?: '<span class="text-muted">—</span>' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="text-muted text-sm" style="margin:0;">Tu entrenador aún no te ha asignado pruebas.</p>
          <?php endif; ?>
          <?php if ($c['relevos']): ?>
            <p class="text-sm" style="margin:10px 0 0;">
              <i class="bi bi-people"></i> Relevos:
              <?php foreach ($c['relevos'] as $i => $r): ?>
                <?= $i ? ' · ' : '' ?><strong><?= e(format_relevo($r['prueba'])) ?></strong>
                <?= e(CE_RELEVO_SEXOS[$r['sexo']]) ?><?= $r['nombre'] ? ' (' . e($r['nombre']) . ')' : '' ?>
                — <?= e(relevo_nombre_posta($r['prueba'], (int)$r['posicion'])) ?><?= $r['parcial'] ? ' · ' . e($r['parcial']) : '' ?><?= $r['tiempo'] ? ' (final ' . e($r['tiempo']) . ')' : '' ?>
              <?php endforeach; ?>
            </p>
          <?php endif; ?>
          <?php foreach ($comunicados[(int)$c['id']] ?? [] as $m): ?>
            <p class="text-sm" style="margin:10px 0 0;">
              <a href="/socio/comunicaciones?ver=<?= (int)$m['id'] ?>"><i class="bi bi-<?= $m['tipo'] === 'encuesta' ? 'ui-checks' : 'envelope' ?>"></i> <?= e($m['titulo']) ?></a>
            </p>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <h1>Mis tiempos de competición</h1>

  <?php if (!$tiempos): ?>
    <div class="card text-center" style="padding:32px;">
      <p class="text-muted">Aún no tienes tiempos de competición registrados.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Fecha</th><th>Competición</th><th>Prueba</th><th>Tiempo</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($tiempos as $t): ?>
              <tr>
                <td><?= date('d/m/Y', strtotime($t['competicion_fecha'])) ?></td>
                <td><?= e($t['competicion_nombre']) ?></td>
                <td><?= e(format_prueba($t['prueba'])) ?></td>
                <td style="font-weight:600;"><?= e($t['tiempo']) ?></td>
                <td><a href="/socio/competicion_tiempo?id=<?= (int)$t['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</main>
<?php render_footer(); ?>
