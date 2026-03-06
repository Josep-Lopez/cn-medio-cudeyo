<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

render_header('Política de Cookies', '');
?>

<div class="container page-content" style="max-width:800px;">
  <h1 style="margin-bottom:8px;">Política de Cookies</h1>
  <p class="text-muted" style="margin-bottom:40px;">Última actualización: <?= date('d/m/Y') ?></p>

  <div style="line-height:1.8;color:#333;">

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">¿Qué son las cookies?</h2>
    <p>Las cookies son pequeños archivos de texto que los sitios web guardan en tu navegador para recordar información entre visitas.</p>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">Cookies que utilizamos</h2>
    <p>Este sitio web utiliza únicamente cookies técnicas, estrictamente necesarias para el funcionamiento del servicio. No utilizamos cookies de seguimiento ni publicidad.</p>

    <div style="overflow-x:auto;margin:20px 0;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead>
          <tr style="background:var(--bg);">
            <th style="padding:12px 16px;text-align:left;border-bottom:2px solid #e8e8e8;">Cookie</th>
            <th style="padding:12px 16px;text-align:left;border-bottom:2px solid #e8e8e8;">Tipo</th>
            <th style="padding:12px 16px;text-align:left;border-bottom:2px solid #e8e8e8;">Duración</th>
            <th style="padding:12px 16px;text-align:left;border-bottom:2px solid #e8e8e8;">Finalidad</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="padding:12px 16px;border-bottom:1px solid #f0f0f0;font-family:monospace;">PHPSESSID</td>
            <td style="padding:12px 16px;border-bottom:1px solid #f0f0f0;">Técnica</td>
            <td style="padding:12px 16px;border-bottom:1px solid #f0f0f0;">Sesión</td>
            <td style="padding:12px 16px;border-bottom:1px solid #f0f0f0;">Mantener la sesión iniciada del socio. Se elimina al cerrar el navegador.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">Cookies de terceros</h2>
    <p>La página de Biblioteca carga contenido desde Google Drive. Google puede establecer sus propias cookies técnicas para servir ese contenido. Consulta la <a href="https://policies.google.com/technologies/cookies" target="_blank" rel="noopener">política de cookies de Google</a> para más información.</p>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">¿Cómo desactivar las cookies?</h2>
    <p>Puedes configurar tu navegador para bloquear o eliminar cookies. Ten en cuenta que si bloqueas la cookie de sesión (<code>PHPSESSID</code>) no podrás acceder al área privada del club.</p>
    <ul style="margin:12px 0 12px 24px;">
      <li><a href="https://support.google.com/chrome/answer/95647" target="_blank" rel="noopener">Chrome</a></li>
      <li><a href="https://support.mozilla.org/es/kb/habilitar-y-deshabilitar-cookies-sitios-web-rastrear-preferencias" target="_blank" rel="noopener">Firefox</a></li>
      <li><a href="https://support.apple.com/es-es/guide/safari/sfri11471/mac" target="_blank" rel="noopener">Safari</a></li>
    </ul>

  </div>

  <div style="margin-top:48px;">
    <a href="/" class="btn btn-secondary">← Volver al inicio</a>
  </div>
</div>

<?php render_footer(); ?>
