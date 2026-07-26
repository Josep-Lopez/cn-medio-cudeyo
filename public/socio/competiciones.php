<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/competicion_entrenador.php';

require_login();
$user = current_user();
$tiempos = listar_tiempos_socio($pdo, (int)$user['id']);

render_header('Mis competiciones', 'socio-competiciones');
?>
<main class="container" style="padding:24px 16px;">
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
