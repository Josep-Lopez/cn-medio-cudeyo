<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

$perPage = 12;
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $perPage;

$total   = $pdo->query('SELECT COUNT(*) FROM noticias WHERE publicat=1')->fetchColumn();
$pages   = (int)ceil($total / $perPage);

$stmt    = $pdo->prepare('SELECT * FROM noticias WHERE publicat=1 ORDER BY created_at DESC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$noticias = $stmt->fetchAll();

render_header('Noticias', 'noticias', '', 'Últimas noticias y novedades del Club de Natación Medio Cudeyo: competiciones, resultados, eventos y actividades del club.');
?>

<div class="container page-content">
  <div class="mb-6">
    <h1 style="margin-bottom:4px;">Noticias</h1>
    <p class="text-muted">Novedades y actividades del CN Medio Cudeyo</p>
  </div>

  <?php if (!$noticias): ?>
    <div class="card text-center" style="padding:60px;">
      <div style="font-size:48px;margin-bottom:16px;color:var(--blue);"><i class="bi bi-newspaper"></i></div>
      <p class="text-muted">No hay noticias publicadas todavía.</p>
    </div>
  <?php else: ?>
    <div class="news-grid">
      <?php foreach ($noticias as $n): ?>
      <a href="/noticias/detall?id=<?= $n['id'] ?>" class="news-card">
        <?php if ($n['imatge_url']): ?>
          <img src="<?= e($n['imatge_url']) ?>" alt="<?= e($n['titol']) ?>" class="news-card-img">
        <?php else: ?>
          <div class="news-card-img-placeholder"><i class="bi bi-newspaper"></i></div>
        <?php endif; ?>
        <div class="news-card-body">
          <div class="news-card-date"><?= date('d/m/Y', strtotime($n['created_at'])) ?></div>
          <div class="news-card-title"><?= e($n['titol']) ?></div>
          <?php if ($n['resum']): ?>
            <div class="news-card-excerpt"><?= e(mb_strimwidth($n['resum'], 0, 120, '…')) ?></div>
          <?php endif; ?>
          <span class="news-card-link">Leer más →</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Paginación -->
    <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="?p=<?= $page - 1 ?>">← Anterior</a>
      <?php endif; ?>
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <?php if ($i === $page): ?>
          <span class="current"><?= $i ?></span>
        <?php elseif (abs($i - $page) <= 2 || $i === 1 || $i === $pages): ?>
          <a href="?p=<?= $i ?>"><?= $i ?></a>
        <?php elseif (abs($i - $page) === 3): ?>
          <span>…</span>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a href="?p=<?= $page + 1 ?>">Siguiente →</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
