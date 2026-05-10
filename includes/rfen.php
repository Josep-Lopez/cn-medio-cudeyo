<?php
/**
 * RFEN helpers — compartidos entre páginas de admin.
 *
 * Requiere: tiempo_a_segundos() de includes/auth.php (siempre cargado antes).
 */

// ── Helpers de conversión ────────────────────────────────────────────────────

function rfen_tiempo_to_local(string $t): ?string
{
  $t = trim($t);
  if (!preg_match('/^(\d+):(\d{2}):(\d{2})\.(\d{2})$/', $t, $m)) return null;
  $total_min = (int)$m[1] * 60 + (int)$m[2];
  $sec = (int)$m[3];
  $cs = $m[4];
  if ($total_min > 0) return $total_min . ':' . str_pad($sec, 2, '0', STR_PAD_LEFT) . '.' . $cs;
  return $sec . '.' . $cs;
}

function rfen_prueba(string $estilo, string $distancia): ?string
{
  $map = ['libre' => 'L', 'crol' => 'L', 'espalda' => 'E', 'braza' => 'B', 'mariposa' => 'M', 'estilos' => 'X'];
  $suf  = $map[strtolower(trim($estilo))] ?? null;
  $dist = (int)preg_replace('/[^0-9]/', '', $distancia);
  if (!$suf || !$dist) return null;
  $validas = [
    '50L',
    '100L',
    '200L',
    '400L',
    '800L',
    '1500L',
    '50E',
    '100E',
    '200E',
    '50B',
    '100B',
    '200B',
    '50M',
    '100M',
    '200M',
    '100X',
    '200X',
    '400X'
  ];
  $prueba = $dist . $suf;
  return in_array($prueba, $validas) ? $prueba : null;
}

function rfen_fecha_iso(string $fecha): string
{
  // DD/MM/YYYY -> YYYY-MM-DD
  if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $fecha, $m))
    return $m[3] . '-' . $m[2] . '-' . $m[1];
  return date('Y-m-d');
}

/** Fetch una URL de la intranet RFEN y devuelve el HTML ya en UTF-8 con entidades. */
function rfen_fetch_html(string $url): string
{
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => ['Accept-Language: es-ES,es;q=0.9'],
  ]);
  $html = curl_exec($ch);
  curl_close($ch);
  if (!$html) return '';
  if (!mb_check_encoding($html, 'UTF-8'))
    $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
  return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
}

/**
 * Parse las filas de datos de la tabla RFEN a partir del HTML ya cargado en un DOMDocument.
 * Devuelve array de registros crudos.
 */
/**
 * @return array{rows: array, has_table: bool}
 */
function rfen_parse_rows(DOMXPath $xpath): array
{
  $all_tr  = $xpath->query('//table//tr');
  $col_idx = [];
  $header_found = false;
  $registros = [];

  foreach ($all_tr as $tr) {
    $cells_text = [];
    foreach ($tr->childNodes as $node)
      if (in_array($node->nodeName, ['th', 'td']))
        $cells_text[] = strtoupper(trim($node->textContent));

    if (!$header_found) {
      if (in_array('FECHA', $cells_text) && in_array('RESULTADO', $cells_text)) {
        foreach ($cells_text as $ci => $name) $col_idx[$name] = $ci;
        $header_found = true;
      }
      continue;
    }

    // Fila de datos
    $cells = [];
    foreach ($tr->childNodes as $node)
      if ($node->nodeName === 'td') $cells[] = trim($node->textContent);
    if (count($cells) < 5) continue;

    $get = fn(string $col) => $cells[$col_idx[$col] ?? -1] ?? '';

    $relevo  = $get('RELEVO');
    $parcial = $get('PARCIAL');
    if ($relevo !== '' && $relevo !== '-')  continue;
    if ($parcial !== '' && $parcial !== '-') continue;

    // Filtrar por club: solo marcas de CN Medio Cudeyo
    $club = $get('CLUB') ?: '';
    if ($club !== '' && stripos($club, 'MEDIO CUDEYO') === false) continue;

    $prueba = rfen_prueba($get('ESTILO'), $get('DISTANCIA'));
    if (!$prueba) continue;

    $piscina_r = $get('PISCINA') ?: $get('TIPO PISCINA');
    $piscina   = str_starts_with(trim($piscina_r), '50') ? '50m' : '25m';

    $tiempo_local = rfen_tiempo_to_local($get('RESULTADO'));
    if (!$tiempo_local) continue;

    $fecha    = $get('FECHA');
    $fecha_iso = rfen_fecha_iso($fecha);

    $registros[] = [
      'fecha'      => $fecha,
      'lugar'      => $get('LUGAR'),
      'prueba'     => $prueba,
      'piscina'    => $piscina,
      'tiempo'     => $tiempo_local,
      'tiempo_seg' => tiempo_a_segundos($tiempo_local),
      'fecha_iso'  => $fecha_iso,
    ];
  }
  return ['rows' => $registros, 'has_table' => $header_found];
}

// ── Flujo completo de importación ────────────────────────────────────────────

/**
 * Fetch todas las páginas de RFEN para un nadador, deduplicar e insertar/actualizar
 * marcas en la BD.
 *
 * @param PDO         $pdo        Conexión a BD activa.
 * @param int         $user_id    ID del usuario local.
 * @param string      $rfen_id    ID del deportista en la intranet RFEN.
 * @param string|null $temporada  Cadena de temporada como '2024-25', o null/'todas' para todas.
 *
 * @return array{procesadas:int, insertadas:int, actualizadas:int, sin_cambios:int, error:?string}
 */
function rfen_import_marks(PDO $pdo, int $user_id, string $rfen_id, ?string $temporada = null): array
{
  // Construir rango de fechas
  $rfen_inicio = '';
  $rfen_fin    = '';
  if ($temporada && $temporada !== 'todas' && preg_match('/^(\d{4})-(\d{2})$/', $temporada, $m)) {
    $y_start     = (int)$m[1];
    $rfen_inicio = $y_start       . '-09-01';
    $rfen_fin    = ($y_start + 1) . '-08-31';
  }

  $base_params = http_build_query(array_filter([
    'e'                => $rfen_id,
    'x_OPCION'         => 'ResultadosNatacion',
    'x_FILTRO5_INICIO' => $rfen_inicio,
    'x_FILTRO5_FIN'    => $rfen_fin,
  ]));
  $rfen_base   = 'https://intranet.rfen.es/ConsultarHistorial.dcl?' . $base_params;

  // Fetch paginado
  $parse_error     = null;
  $registros       = [];
  $paginas_leidas  = 0;
  $current_url     = $rfen_base;

  while ($current_url && $paginas_leidas < 100) {
    $html = rfen_fetch_html($current_url);
    if (!$html) {
      $parse_error = 'No se ha podido conectar con RFEN.';
      break;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $result = rfen_parse_rows($xpath);
    $rows = $result['rows'];

    if (!$result['has_table']) {
      if ($paginas_leidas === 0)
        $parse_error = 'No se ha encontrado la tabla de marcas. La página puede haber cambiado.';
      break;
    }

    if (empty($rows) && $paginas_leidas === 0 && $registros === []) {
      // Primera página con tabla pero sin marcas válidas — seguir paginando
    }

    $registros = array_merge($registros, $rows);

    parse_str(parse_url($current_url, PHP_URL_QUERY), $qp);
    $current_page = (int)($qp['page'] ?? 1);
    $next_page    = $current_page + 1;

    // Detectar última página desde el select de paginación
    $max_page = $current_page;
    $page_options = $xpath->query('//select[@name="page"]/option');
    if ($page_options && $page_options->length > 0) {
      $last_option = $page_options->item($page_options->length - 1);
      $max_page = (int)$last_option->getAttribute('value');
    }

    if ($current_page >= $max_page) {
      $current_url = null;
      continue;
    }

    $next_url = null;

    // Estrategia 1: link con page=N+1 en el href (enlace "Siguiente")
    $next_links = $xpath->query('//a[contains(@href, "page=' . $next_page . '")]');
    foreach ($next_links as $link) {
      $href = $link instanceof DOMElement ? trim($link->getAttribute('href')) : '';
      if ($href && !str_starts_with($href, 'javascript')) {
        if (str_starts_with($href, 'http')) {
          $next_url = $href;
        } elseif (str_starts_with($href, '?')) {
          $next_url = 'https://intranet.rfen.es/ConsultarHistorial.dcl' . $href;
        } else {
          $next_url = 'https://intranet.rfen.es/' . ltrim($href, '/');
        }
        break;
      }
    }

    // Estrategia 2: incrementar page preservando todos los params
    if (!$next_url) {
      $qp['page'] = $next_page;
      $next_url = 'https://intranet.rfen.es/ConsultarHistorial.dcl?' . http_build_query($qp);
    }

    $current_url = $next_url;
  }

  if ($parse_error) {
    return ['procesadas' => 0, 'insertadas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'error' => $parse_error];
  }

  if (empty($registros)) {
    return ['procesadas' => 0, 'insertadas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'error' => 'No hay marcas de CN Medio Cudeyo para este período.'];
  }

  // Deduplicar por prueba+piscina+fecha+lugar (quedarse con el mejor tiempo)
  $agrupados = [];
  foreach ($registros as $r) {
    $key = implode('|', [$r['prueba'], $r['piscina'], $r['fecha_iso'], mb_strtolower(trim($r['lugar'] ?? ''))]);
    if (!isset($agrupados[$key]) || $r['tiempo_seg'] < $agrupados[$key]['tiempo_seg']) {
      $agrupados[$key] = $r;
    }
  }

  // Insert / update
  $stmtImport = $pdo->prepare('
        INSERT INTO marcas (user_id, prueba, piscina, tiempo, tiempo_seg, fecha_marca, lugar)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            tiempo=IF(VALUES(tiempo_seg)<tiempo_seg, VALUES(tiempo), tiempo),
            tiempo_seg=IF(VALUES(tiempo_seg)<tiempo_seg, VALUES(tiempo_seg), tiempo_seg),
            fecha_marca=IF(VALUES(tiempo_seg)<tiempo_seg, VALUES(fecha_marca), fecha_marca),
            lugar=IF(VALUES(tiempo_seg)<tiempo_seg, VALUES(lugar), lugar),
            updated_at=NOW()
    ');

  $procesadas   = 0;
  $insertadas   = 0;
  $actualizadas = 0;
  $sin_cambios  = 0;

  foreach ($agrupados as $r) {
    $secs = $r['tiempo_seg'];
    if ($secs <= 0) continue;
    $stmtImport->execute([$user_id, $r['prueba'], $r['piscina'], $r['tiempo'], $secs, $r['fecha_iso'], $r['lugar']]);
    $procesadas++;
    $affected = $stmtImport->rowCount();
    if ($affected === 1) {
      $insertadas++;
    } elseif ($affected === 2) {
      $actualizadas++;
    } else {
      $sin_cambios++;
    }
  }

  return [
    'procesadas'   => $procesadas,
    'insertadas'   => $insertadas,
    'actualizadas' => $actualizadas,
    'sin_cambios'  => $sin_cambios,
    'error'        => null,
  ];
}
