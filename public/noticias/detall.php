<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /noticias/');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM noticias WHERE id=? AND publicado=1 LIMIT 1');
$stmt->execute([$id]);
$noticia = $stmt->fetch();

if (!$noticia) {
    http_response_code(404);
    render_header('Noticia no encontrada', 'noticias');
    echo '<div class="container page-content"><div class="alert alert-danger">Noticia no encontrada.</div><a href="/noticias/" class="btn btn-secondary">← Volver a noticias</a></div>';
    render_footer();
    exit;
}

$desc = mb_substr(strip_tags($noticia['contenido']), 0, 155);
if (mb_strlen(strip_tags($noticia['contenido'])) > 155) $desc .= '…';
render_header(e($noticia['titulo']), 'noticias', '', $desc);
?>

<div class="container-sm page-content">
  <a href="/noticias/" class="text-muted text-sm" style="display:inline-block;margin-bottom:24px;">← Volver a noticias</a>

  <article>
    <?php if ($noticia['imagen_url']): ?>
      <img src="<?= e($noticia['imagen_url']) ?>" alt="<?= e($noticia['titulo']) ?>"
           style="width:100%;border-radius:12px;margin-bottom:28px;max-height:420px;object-fit:cover;">
    <?php endif; ?>

    <div class="text-muted text-sm mb-4">
      <?php
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $ts = strtotime($noticia['created_at']);
        echo date('j', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' de ' . date('Y', $ts);
      ?>
    </div>

    <h1 style="font-size:28px;font-weight:800;margin-bottom:16px;line-height:1.3;">
      <?= e($noticia['titulo']) ?>
    </h1>

    <?php if ($noticia['resumen']): ?>
      <p style="font-size:17px;color:#555;margin-bottom:28px;line-height:1.6;">
        <?= e($noticia['resumen']) ?>
      </p>
    <?php endif; ?>

    <?php if ($noticia['contenido']): ?>
      <div style="font-size:15px;line-height:1.8;color:#333;">
        <?php
          // Permitir solo etiquetas HTML seguras del editor Quill
          $allowed = '<p><br><h2><h3><strong><em><u><s><ol><ul><li><a><blockquote><span>';
          echo strip_tags($noticia['contenido'], $allowed);
        ?>
      </div>
    <?php endif; ?>
  </article>

  <hr style="margin:40px 0;border:none;border-top:1px solid #e8e8e8;">
  <a href="/noticias/" class="btn btn-secondary">← Volver a noticias</a>
</div>

<?php render_footer(); ?>
