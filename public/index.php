<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

// Últimas 3 noticias publicadas
$noticias = $pdo->query(
    'SELECT * FROM noticias WHERE publicat=1 ORDER BY created_at DESC LIMIT 3'
)->fetchAll();

render_header('Inicio', 'inicio', '', 'Club de Natación Medio Cudeyo, en Cantabria. Consulta marcas personales, ranking de liga, noticias del club y más. ¡Únete a nosotros!');
?>

<!-- Hero -->
<section class="hero hero-with-photo">
  <div class="container">
    <div class="hero-eyebrow">Cantabria · Medio Cudeyo</div>
    <h1>CN Medio Cudeyo</h1>
    <blockquote class="lema-club">
      <p>La disciplina crea hábitos.</p>
      <p>Los hábitos crean constancia.</p>
      <p>Con constancia lo consigues todo.</p>
    </blockquote>
    <div class="hero-btns">
      <?php if (!current_user()): ?>
        <a href="/register" class="btn btn-primary btn-lg"><i class="bi bi-person-plus-fill"></i> Hazte socio</a>
        <a href="/sobre-nosotros" class="btn btn-secondary btn-lg">Conocer el club</a>
      <?php else: ?>
        <a href="/soci/panel" class="btn btn-primary btn-lg"><i class="bi bi-grid-fill"></i> Mi panel</a>
        <a href="/calculadoras" class="btn btn-secondary btn-lg">Calculadoras</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Servicios -->
<section class="section">
  <div class="container">
    <h2 class="section-title">Nadamos juntos</h2>
    <p class="section-sub">Un club para todas las edades y niveles</p>
    <div class="services-grid">
      <div class="service-card">
        <div class="service-icon"><i class="bi bi-person-arms-up"></i></div>
        <div class="service-title">Equipo de natación</div>
        <div class="service-desc">Desde benjamines hasta absolutos. Entrenamiento técnico y de competición para todos los niveles.</div>
      </div>
      <div class="service-card">
        <div class="service-icon"><i class="bi bi-award-fill"></i></div>
        <div class="service-title">Equipo master</div>
        <div class="service-desc">Natación para adultos con entrenamiento adaptado, competiciones masters y actividad social.</div>
      </div>
      <div class="service-card">
        <div class="service-icon"><i class="bi bi-people-fill"></i></div>
        <div class="service-title">Actividades sociales</div>
        <div class="service-desc">Cenas de temporada, torneos internos y eventos especiales para toda la familia del club.</div>
      </div>
    </div>
  </div>
</section>

<!-- Stats -->
<section class="section section-white">
  <div class="container">
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number">60</div>
        <div class="stat-label">Nadadores activos</div>
      </div>
      <div class="stat-card">
        <div class="stat-number">5</div>
        <div class="stat-label">Categorías de competición</div>
      </div>
      <div class="stat-card">
        <div class="stat-number">+20</div>
        <div class="stat-label">Competiciones por temporada</div>
      </div>
    </div>
  </div>
</section>

<!-- Noticias recientes -->
<?php if ($noticias): ?>
<section class="section section-white">
  <div class="container">
    <div class="d-flex justify-between align-center mb-6">
      <div>
        <h2 class="section-title" style="margin-bottom:4px;">Últimas noticias</h2>
        <p class="text-muted">Novedades del club</p>
      </div>
      <a href="/noticias/" class="btn btn-secondary">Ver todas</a>
    </div>
    <div class="news-grid">
      <?php foreach ($noticias as $n): ?>
      <a href="/noticias/detall?id=<?= (int)$n['id'] ?>" class="news-card">
        <?php if ($n['imatge_url']): ?>
          <img src="<?= e($n['imatge_url']) ?>" alt="<?= e($n['titol']) ?>" class="news-card-img">
        <?php else: ?>
          <div class="news-card-img-placeholder"><i class="bi bi-newspaper"></i></div>
        <?php endif; ?>
        <div class="news-card-body">
          <div class="news-card-date"><?= date('d/m/Y', strtotime($n['created_at'])) ?></div>
          <div class="news-card-title"><?= e($n['titol']) ?></div>
          <?php if ($n['resum']): ?>
            <div class="news-card-excerpt"><?= e(mb_strimwidth($n['resum'], 0, 100, '…')) ?></div>
          <?php endif; ?>
          <span class="news-card-link">Leer más →</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Instagram -->
<section class="section">
  <div class="container">
    <h2 class="section-title">Síguenos en Instagram</h2>
    <p class="section-sub">Las últimas publicaciones del club</p>
    <behold-widget feed-id="D3v6lzQGhJU2Hx815nk3"></behold-widget>
  </div>
</section>

<!-- Contacte -->
<section class="section section-white">
  <div class="container">
    <h2 class="section-title">¿Dónde estamos?</h2>
    <p class="section-sub">Ven a conocernos</p>
    <div class="services-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr))">
      <div class="service-card">
        <div class="service-icon"><i class="bi bi-geo-alt-fill"></i></div>
        <div class="service-title">Ubicación</div>
        <div class="service-desc">Medio Cudeyo, Cantabria</div>
      </div>
      <div class="service-card">
        <div class="service-icon"><i class="bi bi-envelope-fill"></i></div>
        <div class="service-title">Contacto</div>
        <div class="service-desc"><a href="mailto:admin@cnmediocudeyo.es">admin@cnmediocudeyo.es</a></div>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta-section">
  <div class="container">
    <?php if (!current_user()): ?>
      <h2>¿Quieres unirte al club?</h2>
      <p>Regístrate y el administrador revisará tu solicitud. ¡Te esperamos en el agua!</p>
      <div class="hero-btns">
        <a href="/register" class="btn btn-primary btn-lg"><i class="bi bi-person-plus-fill"></i> Solicitar alta</a>
        <a href="/login" class="btn btn-secondary btn-lg">Acceso socios</a>
      </div>
    <?php else: ?>
      <h2>¡A por los mejores tiempos!</h2>
      <p>Consulta el ranking de tu liga, revisa tus marcas y calcula tus tiempos FINA.</p>
      <div class="hero-btns">
        <a href="/soci/ranking" class="btn btn-primary btn-lg"><i class="bi bi-bar-chart-fill"></i> Ver ranking</a>
        <a href="/calculadoras" class="btn btn-secondary btn-lg">Calculadoras</a>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
  (() => {
    const d=document,s=d.createElement("script");s.type="module";
    s.src="https://w.behold.so/widget.js";d.head.append(s);
  })();
</script>

<?php render_footer(); ?>
