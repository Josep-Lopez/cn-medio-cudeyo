<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_admin();
header('Content-Type: application/json; charset=utf-8');

$nombre   = trim($_GET['nombre']   ?? '');
$apellidos = trim($_GET['apellidos'] ?? '');
$sexe     = $_GET['sexe'] ?? 'M';

if (!$nombre || !$apellidos) {
    echo json_encode(['error' => 'Faltan nombre y apellidos']);
    exit;
}
if (!in_array($sexe, ['M', 'F'])) $sexe = 'M';

$url = 'https://intranet.rfen.es/FormularioAjaxProcesar?'
     . 'x_nombre='    . urlencode($nombre)
     . '&x_apellidos=' . urlencode($apellidos)
     . '&x_genero='   . urlencode($sexe)
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
    echo json_encode(['error' => 'No s\'ha pogut connectar amb RFEN: ' . $err]);
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

    $nom_cell = trim($cells->item(0)->textContent);
    $cog_cell = trim($cells->item(1)->textContent);
    $any_cell = trim($cells->item(2)->textContent);

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
    $rfen_id  = $params['e'] ?? '';
    $rfen_nom = $params['d'] ?? ($nom_cell . '-' . $cog_cell);

    if (!$rfen_id) continue;

    $results[] = [
        'nom'      => $nom_cell,
        'cognoms'  => $cog_cell,
        'any_naix' => $any_cell,
        'rfen_id'  => $rfen_id,
        'rfen_nom' => $rfen_nom,
    ];
}

echo json_encode(['results' => $results]);
