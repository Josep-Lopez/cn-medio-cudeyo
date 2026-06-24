<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

render_header('Sobre nosotros', 'sobre', '', 'Conoce el Club de Natación Medio Cudeyo: historia, valores, categorías y cómo hacerte socio. Un club para todas las edades en Cantabria.');
?>

<!-- Hero -->
<section class="hero hero-sm hero-sobre">
  <div class="container">
    <div class="hero-eyebrow">El club</div>
    <h1>Club natación medio cudeyo</h1>
    <p>Conoce quiénes somos, nuestros equipos y todo lo que hace especial a nuestra grupo de deportistas.</p>
  </div>
</section>

<!-- Historia -->
<section id="historia" class="section section-white">
  <div class="container">
    <div class="sobre-two-col">
      <div>
        <div class="sobre-eyebrow">Equipo de natación</div>
        <h2 class="sobre-h2">Ven a entrenar con nosotros</h2>
        <p style="font-size:16px;line-height:1.8;" class="text-muted">
          Desde las categorias inferiores hasta los más veteranos, nuestro equipo acoge a todos aquellos que quieran compertir y disfrutar del deporte acuático en el municipio.
        </p>
        <p style="font-size:16px;line-height:1.8;" class="text-muted">Se acepta la inscripción desde prebenjamín hasta master</p>
      </div>
      <div>
        <img src="/assets/images/fotoequipo3.jpg"
          alt="CN Medio Cudeyo"
          class="sobre-photo">
      </div>
    </div>
  </div>
</section>

<!-- Categorias -->
<section id="equipo-natacion" class="section">
  <div class="container">
    <div class="sobre-eyebrow" style="text-align:center;">Categorias y horarios de entreno</div>

    <!-- Horario de la piscina -->
    <div style="max-width:540px;margin:0 auto 48px;background:#fff;border-top:4px solid var(--blue);border-radius:12px;padding:24px;box-shadow:0 8px 24px rgba(9,63,180,0.08);">
      <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;text-align:center;"><i class="bi bi-water" style="color:var(--blue);"></i> Horario del club de la piscina</h3>
      <table style="width:100%;font-size:14px;border-collapse:collapse;border-radius:8px;overflow:hidden;">
        <thead>
          <tr style="background:#f5f7ff;">
            <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:0.05em;">Día</th>
            <th style="padding:10px 14px;text-align:right;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:0.05em;">Hora</th>
          </tr>
        </thead>
        <tbody>
          <tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:11px 14px;" class="text-muted">Lunes a Jueves</td>
            <td style="padding:11px 14px;text-align:right;font-weight:600;font-family:monospace;">19:00 – 21:45</td>
          </tr>
          <tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:11px 14px;" class="text-muted">Viernes</td>
            <td style="padding:11px 14px;text-align:right;font-weight:600;font-family:monospace;">19:15 – 21:45</td>
          </tr>
          <tr>
            <td style="padding:11px 14px;" class="text-muted">Sábados</td>
            <td style="padding:11px 14px;text-align:right;font-weight:600;font-family:monospace;">Competiciones</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Grupo 1: Prebenjamín, Benjamín, Alevín -->
    <div class="sobre-two-col" style="margin-bottom:32px;">
      <div style="background:linear-gradient(135deg,#093FB4,#2563eb);border-radius:12px;padding:24px;color:#fff;box-shadow:0 8px 24px rgba(9,63,180,0.25);">
        <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,0.75);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px;"><i class="bi bi-clock-fill"></i> Horario</div>
        <div style="font-size:28px;font-weight:800;font-family:monospace;">19:00 – 20:00</div>
        <div style="margin-top:6px;font-size:14px;color:rgba(255,255,255,0.85);">Mínimo 3 días a la semana</div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
        <div class="service-card" style="border-top:4px solid #093FB4;">
          <div class="service-title">Prebenjamín</div>
          <div class="service-desc">2019 / 2018</div>
        </div>
        <div class="service-card" style="border-top:4px solid #093FB4;">
          <div class="service-title">Benjamín</div>
          <div class="service-desc">2017 / 2016</div>
        </div>
        <div class="service-card" style="border-top:4px solid #093FB4;">
          <div class="service-title">Alevín</div>
          <div class="service-desc">2015 / 2014</div>
        </div>
      </div>
    </div>

    <!-- Grupo 2: Infantil, Junior, Absoluto -->
    <div class="sobre-two-col" style="margin-bottom:32px;">
      <div style="background:linear-gradient(135deg,#0891b2,#06b6d4);border-radius:12px;padding:24px;color:#fff;box-shadow:0 8px 24px rgba(8,145,178,0.25);">
        <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,0.75);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px;"><i class="bi bi-clock-fill"></i> Horario</div>
        <div style="font-size:28px;font-weight:800;font-family:monospace;">20:00 – 21:45</div>
        <div style="margin-top:6px;font-size:14px;color:rgba(255,255,255,0.85);">Mínimo 5 días a la semana</div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
        <div class="service-card" style="border-top:4px solid #0891b2;">
          <div class="service-title">Infantil</div>
          <div class="service-desc">2013 / 2012</div>
        </div>
        <div class="service-card" style="border-top:4px solid #0891b2;">
          <div class="service-title">Junior</div>
          <div class="service-desc">2011 / 2010 / 2009</div>
        </div>
        <div class="service-card" style="border-top:4px solid #0891b2;">
          <div class="service-title">Absoluto</div>
          <div class="service-desc">2008 y mayores</div>
        </div>
      </div>
    </div>

    <!-- Master -->
    <div style="display:flex;justify-content:center;margin-bottom:40px;">
      <div class="service-card" style="max-width:280px;text-align:center;border-top:4px solid #f59e0b;">
        <div class="service-title">Master</div>
        <div class="service-desc">A partir de +20 años</div>
      </div>
    </div>

    <div style="text-align:center;background:linear-gradient(135deg,#093FB4,#2563eb);color:#fff;border-radius:16px;padding:40px 24px;box-shadow:0 12px 32px rgba(9,63,180,0.25);">
      <h3 style="font-size:22px;font-weight:800;margin-bottom:12px;">¿Quieres unirte al equipo?</h3>
      <p style="line-height:1.7;margin-bottom:24px;color:rgba(255,255,255,0.9);">Realizamos pruebas de nivel para nadadores nuevos cada inicio de temporada.</p>
      <a href="/contacto" class="btn btn-lg" style="background:#fff;color:var(--blue);font-weight:700;">Contactanos ahora</a>
    </div>
  </div>
</section>

<!-- Equipo máster -->
<section id="equipo-master" class="section section-white">
  <div class="container">
    <div class="sobre-eyebrow" style="text-align:center;">Equipo máster</div>
    <h2 class="section-title">La competición no tiene edad</h2>
    <p class="section-sub">El equipo máster demuestra que la pasión por la natación es para siempre. Entrenamos, competimos y disfrutamos del agua sin límites de edad.</p>

    <div class="sobre-two-col" style="align-items:start;">
      <div>
        <div style="display:inline-flex;align-items:center;gap:12px;background:#eef2ff;padding:16px 24px;border-radius:12px;margin-bottom:20px;">
          <span style="font-size:36px;font-weight:800;color:var(--blue);">+15</span>
          <span class="text-muted" style="font-size:14px;font-weight:500;">nadadores máster</span>
        </div>
        <img src="/assets/images/fotomaster.jpg"
          alt="Equipo máster CN Medio Cudeyo"
          class="sobre-photo">
      </div>
      <div class="master-features">
        <!-- <div class="card" style="padding:20px;">
          <div style="font-size:28px;margin-bottom:10px;color:var(--blue);"><i class="bi bi-activity"></i></div>
          <div style="font-weight:700;margin-bottom:6px;">Entrenamiento adaptado</div>
          <div class="text-muted text-sm">Grupos de nivel, desde iniciación hasta competición</div>
        </div> -->
        <div class="card" style="padding:20px;">
          <div style="font-size:28px;margin-bottom:10px;color:var(--blue);"><i class="bi bi-trophy-fill"></i></div>
          <div style="font-weight:700;margin-bottom:6px;">Competiciones regionales</div>
          <div class="text-muted text-sm">Circuito Liga promoción Norte </div>
        </div>
        <div class="card" style="padding:20px;">
          <div style="font-size:28px;margin-bottom:10px;color:var(--blue);"><i class="bi bi-trophy-fill"></i></div>
          <div style="font-weight:700;margin-bottom:6px;">Competiciones nacionales</div>
          <div class="text-muted text-sm">Campeonatos de españa</div>
        </div>
        <div class="card" style="padding:20px;">
          <div style="font-size:28px;margin-bottom:10px;color:var(--blue);"><i class="bi bi-people-fill"></i></div>
          <div style="font-weight:700;margin-bottom:6px;">Comunidad activa</div>
          <div class="text-muted text-sm">Quedadas, viajes a competiciones y eventos sociales</div>
        </div>
        <div class="card" style="padding:20px;">
          <div style="font-size:28px;margin-bottom:10px;color:var(--blue);"><i class="bi bi-calendar-event-fill"></i></div>
          <div style="font-weight:700;margin-bottom:6px;">Calendario propio</div>
          <div class="text-muted text-sm">Competiciones y eventos exclusivos para el equipo máster</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Encuentros sociales -->
<!-- <section id="encuentros" class="section">
  <div class="container">
    <div class="sobre-eyebrow" style="text-align:center;">Encuentros sociales y de equipo</div>
    <h2 class="section-title">Más que natación, una familia</h2>
    <p class="section-sub">La vida del club no termina en la piscina. Celebramos logros y creamos recuerdos que refuerzan los vínculos del equipo.</p>

    <div class="sobre-two-col" style="align-items:start;">
      <div style="display:grid;gap:14px;">
        <div class="card" style="border-left:4px solid var(--blue);display:flex;gap:20px;align-items:flex-start;padding:16px 20px;">
          <div style="text-align:center;min-width:40px;flex-shrink:0;">
            <div style="font-size:10px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:0.05em;">Jun</div>
            <div style="font-size:26px;font-weight:800;line-height:1.1;">14</div>
          </div>
          <div>
            <div style="font-weight:700;margin-bottom:4px;">Cena de fin de temporada</div>
            <div class="text-muted text-sm">Premiamos los mejores tiempos y el espíritu deportivo del año.</div>
          </div>
        </div>
        <div class="card" style="border-left:4px solid var(--blue);display:flex;gap:20px;align-items:flex-start;padding:16px 20px;">
          <div style="text-align:center;min-width:40px;flex-shrink:0;">
            <div style="font-size:10px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:0.05em;">Mar</div>
            <div style="font-size:26px;font-weight:800;line-height:1.1;">22</div>
          </div>
          <div>
            <div style="font-weight:700;margin-bottom:4px;">Torneo interno de primavera</div>
            <div class="text-muted text-sm">Competición amistosa interna en piscina larga.</div>
          </div>
        </div>
        <div class="card" style="border-left:4px solid var(--blue);display:flex;gap:20px;align-items:flex-start;padding:16px 20px;">
          <div style="text-align:center;min-width:40px;flex-shrink:0;">
            <div style="font-size:10px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:0.05em;">Sep</div>
            <div style="font-size:26px;font-weight:800;line-height:1.1;">16</div>
          </div>
          <div>
            <div style="font-weight:700;margin-bottom:4px;">Presentación de temporada</div>
            <div class="text-muted text-sm">Bienvenida oficial al nuevo curso y novedades del club.</div>
          </div>
        </div>
        <div class="card" style="border-left:4px solid var(--blue);display:flex;gap:20px;align-items:flex-start;padding:16px 20px;">
          <div style="text-align:center;min-width:40px;flex-shrink:0;">
            <div style="font-size:10px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:0.05em;">Dic</div>
            <div style="font-size:26px;font-weight:800;line-height:1.1;">21</div>
          </div>
          <div>
            <div style="font-weight:700;margin-bottom:4px;">Noche de Navidad</div>
            <div class="text-muted text-sm">Celebración para socios, familias y colaboradores.</div>
          </div>
        </div>
      </div>
      <div>
        <p class="text-muted" style="font-size:16px;line-height:1.8;margin-bottom:20px;">Creemos que la identidad de un club se construye tanto dentro como fuera del agua. Por eso organizamos a lo largo del año encuentros que van más allá del deporte: cenas de equipo, torneos internos y celebraciones que fortalecen el sentido de pertenencia.</p>
        <p class="text-muted" style="font-size:16px;line-height:1.8;">Estos momentos son especialmente importantes para los más jóvenes, que aprenden los valores del deporte —el respeto, la superación, el trabajo en equipo— en un ambiente distendido y festivo. Si quieres estar al día, publicamos todos los eventos en el panel de noticias.</p>
      </div>
    </div>
  </div>
</section> -->

<!-- CTA -->
<section class="cta-section">
  <div class="container">
    <h2>¿Listo para nadar con nosotros?</h2>
    <p>Únete a nuestra familia de nadadores. Da igual si empiezas de cero o llevas años en el agua.</p>
    <div class="hero-btns">
      <a href="/register" class="btn btn-primary btn-lg">Registrarse ahora</a>
      <a href="/noticias/" class="btn btn-secondary btn-lg">Ver noticias</a>
    </div>
  </div>
</section>

<?php render_footer(); ?>