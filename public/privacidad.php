<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

render_header('Política de Privacidad', '');
?>

<div class="container page-content" style="max-width:800px;">
  <h1 style="margin-bottom:8px;">Política de Privacidad</h1>
  <p class="text-muted" style="margin-bottom:40px;">Última actualización: <?= date('d/m/Y') ?></p>

  <div style="line-height:1.8;color:#333;">

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">1. Responsable del tratamiento</h2>
    <p>
      El responsable del tratamiento de los datos personales recogidos en este sitio web es:<br><br>
      <strong>Nombre:</strong> <span style="background:#fff3cd;padding:2px 6px;border-radius:4px;">Gabriel Martí Holgado</span><br>
      <strong>Correo electrónico:</strong> <span style="background:#fff3cd;padding:2px 6px;border-radius:4px;">coachgabimarti@gmail.com</span><br>
      <strong>Entidad:</strong> Club de Natación Medio Cudeyo
    </p>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">2. Datos que recogemos</h2>
    <p>Este sitio web recoge los siguientes datos personales:</p>
    <ul style="margin:12px 0 12px 24px;">
      <li><strong>Registro de socios:</strong> nombre, correo electrónico y contraseña (almacenada de forma cifrada).</li>
      <li><strong>Formulario de contacto:</strong> nombre, correo electrónico y mensaje.</li>
      <li><strong>Marcas deportivas:</strong> resultados de natación asociados al perfil del socio.</li>
    </ul>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">3. Finalidad del tratamiento</h2>
    <p>Los datos recogidos se utilizan exclusivamente para:</p>
    <ul style="margin:12px 0 12px 24px;">
      <li>Gestionar el acceso de los socios al área privada del club.</li>
      <li>Mantener el registro de marcas y rankings deportivos internos.</li>
      <li>Responder a las consultas enviadas a través del formulario de contacto.</li>
    </ul>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">4. Base legal</h2>
    <p>El tratamiento se basa en el consentimiento del interesado al registrarse en la plataforma (art. 6.1.a RGPD) y en la ejecución de la relación asociativa entre el socio y el club (art. 6.1.b RGPD).</p>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">5. Conservación de los datos</h2>
    <p>Los datos se conservan mientras el socio mantenga su relación con el club. Una vez dada de baja, los datos se eliminarán salvo que exista obligación legal de conservarlos.</p>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">6. Cesión a terceros</h2>
    <p>No se ceden datos a terceros salvo obligación legal. El sitio web utiliza la API de Google Drive para mostrar documentos del club; consulta la <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">política de privacidad de Google</a> para más información.</p>

    <h2 style="font-size:18px;font-weight:700;margin:32px 0 12px;">7. Derechos del interesado</h2>
    <p>Puedes ejercer en cualquier momento los derechos de acceso, rectificación, supresión, oposición, limitación del tratamiento y portabilidad de datos escribiendo a
      <span style="background:#fff3cd;padding:2px 6px;border-radius:4px;">coachgabimarti@gmail.com</span>,
      indicando en el asunto "Protección de datos".
    </p>
    <p>También tienes derecho a presentar una reclamación ante la Agencia Española de Protección de Datos (<a href="https://www.aepd.es" target="_blank" rel="noopener">www.aepd.es</a>).</p>

  </div>

  <div style="margin-top:48px;">
    <a href="/" class="btn btn-secondary">← Volver al inicio</a>
  </div>
</div>

<?php render_footer(); ?>
