<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_admin_area(['director_tecnico']);
header('Content-Type: application/json; charset=utf-8');

$nombre    = trim($_GET['nombre']    ?? '');
$apellidos = trim($_GET['apellidos'] ?? '');
$sexo      = $_GET['sexo'] ?? 'M';

if (!$nombre || !$apellidos) {
    echo json_encode(['error' => 'Faltan nombre y apellidos']);
    exit;
}
if (!in_array($sexo, ['M', 'F'])) $sexo = 'M';

$url = 'https://intranet.rfen.es/FormularioAjaxProcesar?'
     . 'x_nombre='    . urlencode($nombre)
     . '&x_apellidos=' . urlencode($apellidos)
     . '&x_genero='   . urlencode($sexo)
     . '&buscar=1';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_SSL_VERIFYPEER => false,
]);
$html = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err || !$html) {
    echo json_encode(['error' => 'No se ha podido conectar con RFEN: ' . $err]);
    exit;
}

if (!mb_check_encoding($html, 'UTF-8')) {
    $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
}
$html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$rows  = $xpath->query('//table//tr');

$results = [];
foreach ($rows as $row) {
    $cells = $xpath->query('.//td', $row);
    if ($cells->length < 4) continue;

    $nombre_cell    = trim($cells->item(0)->textContent);
    $apellidos_cell = trim($cells->item(1)->textContent);
    $anio_nac       = trim($cells->item(2)->textContent);

    // Buscar link de consulta
    $links = $xpath->query('.//a', $row);
    $href  = '';
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        if (str_contains($href, 'ConsultarHistorial')) break;
    }
    if (!$href) continue;

    // Extraer parámetros e= y d= del href
    parse_str(parse_url($href, PHP_URL_QUERY), $params);
    $rfen_id     = $params['e'] ?? '';
    $rfen_nombre = $params['d'] ?? ($nombre_cell . '-' . $apellidos_cell);

    if (!$rfen_id) continue;

    $results[] = [
        'nombre'         => $nombre_cell,
        'apellidos_cell' => $apellidos_cell,
        'anio_nac'       => $anio_nac,
        'rfen_id'        => $rfen_id,
        'rfen_nombre'    => $rfen_nombre,
    ];
}

echo json_encode(['results' => $results]);
