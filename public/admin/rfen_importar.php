<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

// ── Helpers ───────────────────────────────────────────────────────────────────

function rfen_temps_to_local(string $t): ?string
{
  $t = trim($t);
  if (!preg_match('/^(\d+):(\d{2}):(\d{2})\.(\d{2})$/', $t, $m)) return null;
  $total_min = (int)$m[1] * 60 + (int)$m[2];
  $sec = (int)$m[3];
  $cs = $m[4];
  if ($total_min > 0) return $total_min . ':' . str_pad($sec, 2, '0', STR_PAD_LEFT) . '.' . $cs;
  return $sec . '.' . $cs;
}

function rfen_prova(string $estilo, string $distancia): ?string
{
  $map = ['libre' => 'L', 'crol' => 'L', 'espalda' => 'E', 'braza' => 'B', 'mariposa' => 'M', 'estilos' => 'X'];
  $suf  = $map[strtolower(trim($estilo))] ?? null;
  $dist = (int)preg_replace('/[^0-9]/', '', $distancia);
  if (!$suf || !$dist) return null;
  $valides = [
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
  $prova = $dist . $suf;
  return in_array($prova, $valides) ? $prova : null;
}

function rfen_fecha_iso(string $fecha): string
{
  // DD/MM/YYYY → YYYY-MM-DD
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
function rfen_parse_rows(DOMXPath $xpath): array
{
  $all_tr  = $xpath->query('//table//tr');
  $col_idx = [];
  $header_found = false;
  $registres = [];

  foreach ($all_tr as $tr) {
    $cells_text = [];
    foreach ($tr->childNodes as $node)
      if (in_array($node->nodeName, ['th', 'td']))
        $cells_text[] = strtoupper(trim($node->textContent));

    if (!$header_found) {
      // Detectar fila de cabecera buscando columnas clave
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
    if ($relevo !== '' && $relevo !== '-')  continue; // saltar relevos
    if ($parcial !== '' && $parcial !== '-') continue; // saltar parciales

    $prova = rfen_prova($get('ESTILO'), $get('DISTANCIA'));
    if (!$prova) continue;

    $piscina_r = $get('PISCINA') ?: $get('TIPO PISCINA');
    $piscina   = str_starts_with(trim($piscina_r), '50') ? '50m' : '25m';

    $temps_local = rfen_temps_to_local($get('RESULTADO'));
    if (!$temps_local) continue;

    $fecha    = $get('FECHA');
    $data_iso = rfen_fecha_iso($fecha);

    $registres[] = [
      'fecha'     => $fecha,
      'lugar'     => $get('LUGAR'),
      'prova'     => $prova,
      'piscina'   => $piscina,
      'temps'     => $temps_local,
      'temps_seg' => temps_a_segons($temps_local),
      'data_iso'  => $data_iso,
    ];
  }
  return $registres;
}

// ── Càrrega usuari ────────────────────────────────────────────────────────────

$user_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if (!$user_id) {
  flash('Usuario no especificado.', 'danger');
  header('Location: /admin/marques');
  exit;
}

$stmt = $pdo->prepare('SELECT id, nom, lliga, sexe, rfen_id FROM users WHERE id=?');
$stmt->execute([$user_id]);
$nadador = $stmt->fetch();

if (!$nadador || !$nadador['rfen_id']) {
  flash('Este usuario no tiene vinculación RFEN.', 'danger');
  header('Location: /admin/marques?user_id=' . $user_id);
  exit;
}

// ── POST: confirmar importación ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $PROVES = [
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
  $payload = json_decode($_POST['imp_payload'] ?? '[]', true);
  if (!is_array($payload)) $payload = [];
  $insertadas = 0;
  $actualizadas = 0;
  $sin_cambios = 0;
  $procesadas = 0;
  $stmtImport = $pdo->prepare('
            INSERT INTO marques (user_id, prova, piscina, temps, temps_seg, data_marca, lugar)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                temps=IF(VALUES(temps_seg)<temps_seg, VALUES(temps), temps),
                temps_seg=IF(VALUES(temps_seg)<temps_seg, VALUES(temps_seg), temps_seg),
                data_marca=IF(VALUES(temps_seg)<temps_seg, VALUES(data_marca), data_marca),
                lugar=IF(VALUES(temps_seg)<temps_seg, VALUES(lugar), lugar),
                updated_at=NOW()
        ');
  foreach (array_keys($_POST['imp_sel'] ?? []) as $idx) {
    $idx = (string)$idx;
    if (!isset($payload[$idx]) || !is_array($payload[$idx])) {
      continue;
    }
    $row = $payload[$idx];
    $prova   = (string)($row['prova'] ?? '');
    $piscina = (string)($row['piscina'] ?? '');
    $temps   = (string)($row['temps'] ?? '');
    $data_m  = (string)($row['data'] ?? '');
    $lugar   = trim((string)($row['lugar'] ?? ''));
    if (!in_array($prova, $PROVES) || !in_array($piscina, ['25m', '50m']) || !$temps) {
      continue;
    }
    $secs = temps_a_segons($temps);
    if ($secs <= 0) {
      continue;
    }
    $stmtImport->execute([$user_id, $prova, $piscina, $temps, $secs, $data_m, $lugar]);
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
  flash("RFEN: {$procesadas} procesadas · {$insertadas} insertadas · {$actualizadas} actualizadas · {$sin_cambios} sin cambios.", 'success');
  header('Location: /admin/marques?user_id=' . $user_id);
  exit;
}

// ── GET: selección de temporada y fetch paginado ──────────────────────────────

// Temporadas disponibles (últimas 6 seasons)
$current_year  = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporades_disp = [];
for ($y = $current_year; $y >= $current_year - 5; $y--) {
  $temporades_disp[] = $y . '-' . substr((string)($y + 1), 2);
}

$filterTemporada = $_GET['temporada'] ?? $temporades_disp[0];
if (!in_array($filterTemporada, $temporades_disp) && $filterTemporada !== 'todas')
  $filterTemporada = $temporades_disp[0];

// Calcular rango de fechas RFEN para la temporada (formato YYYY-MM-DD)
$rfen_inicio = '';
$rfen_fin    = '';
if ($filterTemporada !== 'todas' && preg_match('/^(\d{4})-(\d{2})$/', $filterTemporada, $m)) {
  $y_start = (int)$m[1];
  $rfen_inicio = $y_start       . '-09-01';
  $rfen_fin    = ($y_start + 1) . '-08-31';
}

// URL base RFEN
$base_params = http_build_query(array_filter([
  'e'               => $nadador['rfen_id'],
  'x_OPCION'        => 'ResultadosNatacion',
  'x_FILTRO5_INICIO' => $rfen_inicio,
  'x_FILTRO5_FIN'   => $rfen_fin,
]));
$rfen_base = 'https://intranet.rfen.es/ConsultarHistorial.dcl?' . $base_params;

// Fetch paginado
$parse_error = null;
$registres   = [];
$pagines_llegides = 0;
$current_url = $rfen_base;

while ($current_url && $pagines_llegides < 50) {
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

  $rows = rfen_parse_rows($xpath);

  if (empty($rows)) {
    if ($pagines_llegides === 0) {
      $all_tr = $xpath->query('//table//tr');
      if (!$all_tr || $all_tr->length === 0)
        $parse_error = 'No se ha encontrado la tabla de marcas. La página puede haber cambiado.';
    }
    break;
  }

  $registres = array_merge($registres, $rows);
  $pagines_llegides++;

  parse_str(parse_url($current_url, PHP_URL_QUERY), $qp);
  $current_page = (int)($qp['page'] ?? 1);
  $next_page    = $current_page + 1;

  $next_url = null;

  // Estrategia 1: link con page=N+1 en el href
  $next_links = $xpath->query('//a[contains(@href, "page=' . $next_page . '")]');
  foreach ($next_links as $link) {
    $href = $link instanceof DOMElement ? trim($link->getAttribute('href')) : '';
    if ($href && !str_starts_with($href, 'javascript')) {
      if (str_starts_with($href, 'http')) {
        $next_url = $href;
      } elseif (str_starts_with($href, '?')) {
        // href es solo query string: ?eje=30&page=2&...
        $next_url = 'https://intranet.rfen.es/ConsultarHistorial.dcl' . $href;
      } else {
        $next_url = 'https://intranet.rfen.es/' . ltrim($href, '/');
      }
      break;
    }
  }

  // Estrategia 2: incrementar page preservando todos los params (incluido eje)
  if (!$next_url) {
    $qp['page'] = $next_page;
    $next_url = 'https://intranet.rfen.es/ConsultarHistorial.dcl?' . http_build_query($qp);
  }

  $current_url = $next_url;
}

if (empty($registres) && !$parse_error) {
  $parse_error = 'No hay marcas para la temporada seleccionada.';
}

// Agrupar por prova+piscina+fecha+lugar para evitar duplicados exactos del feed RFEN
$agrupats = [];
foreach ($registres as $r) {
  $key = implode('|', [$r['prova'], $r['piscina'], $r['data_iso'], mb_strtolower(trim($r['lugar'] ?? ''))]);
  if (!isset($agrupats[$key]) || $r['temps_seg'] < $agrupats[$key]['temps_seg']) {
    $agrupats[$key] = $r;
  }
}
usort($agrupats, fn($a, $b) => [$b['data_iso'], $a['prova'], $a['piscina']] <=> [$a['data_iso'], $b['prova'], $b['piscina']]);

// ── Render ────────────────────────────────────────────────────────────────────

render_header('Importar desde RFEN', 'admin-marques');
render_admin_layout('marques', function () use ($nadador, $user_id, $agrupats, $parse_error, $rfen_base, $temporades_disp, $filterTemporada, $pagines_llegides) {
?>

  <div class="d-flex align-center gap-3 mb-6" style="flex-wrap:wrap;">
    <a href="/admin/marques?user_id=<?= $user_id ?>" class="btn btn-gray btn-sm">
      <i class="bi bi-arrow-left"></i> Volver
    </a>
    <h1 style="margin:0;">Importar desde RFEN — <?= e($nadador['nom']) ?></h1>
  </div>

  <?php render_flash(); ?>

  <style>
    @keyframes loading-spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    @keyframes loading-float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-4px); }
    }
  </style>
  <div id="pageLoadingOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);backdrop-filter:blur(2px);z-index:2000;align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:16px;padding:24px 28px;box-shadow:0 24px 80px rgba(15,23,42,0.22);min-width:260px;text-align:center;animation:loading-float 1.8s ease-in-out infinite;">
      <div style="font-size:28px;color:var(--blue);margin-bottom:10px;display:inline-flex;animation:loading-spin 1s linear infinite;"><i class="bi bi-arrow-repeat"></i></div>
      <div style="font-weight:700;margin-bottom:6px;">Cargando importación</div>
      <div class="text-muted text-sm">Consultando RFEN, esto puede tardar unos segundos.</div>
    </div>
  </div>
  <!-- Selector de temporada (controla el fetch) -->
  <div class="filters-bar" style="margin-bottom:20px;">
    <form method="GET" class="filters-form js-loading-form" style="align-items:center;">
      <input type="hidden" name="user_id" value="<?= $user_id ?>">
      <div class="form-group">
        <label class="form-label">Temporada</label>
        <select name="temporada" class="form-control js-loading-select">
          <option value="todas" <?= $filterTemporada === 'todas' ? 'selected' : '' ?>>Todas</option>
          <?php foreach ($temporades_disp as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterTemporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>


  <?php if ($parse_error): ?>
    <div class="alert alert-danger"><?= e($parse_error) ?></div>
    <p class="text-muted text-sm">URL consultada: <a href="<?= e($rfen_base) ?>" target="_blank"><?= e($rfen_base) ?></a></p>
  <?php elseif (empty($agrupats)): ?>
    <div class="alert alert-info">No hay marcas para la temporada seleccionada.</div>
  <?php else: ?>


    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="user_id" value="<?= $user_id ?>">
      <input type="hidden" name="imp_payload" value="<?= e(json_encode(array_map(
        fn($r) => [
          'prova' => $r['prova'],
          'piscina' => $r['piscina'],
          'temps' => $r['temps'],
          'data' => $r['data_iso'],
          'lugar' => $r['lugar'],
        ],
        $agrupats
      ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">

      <div class="d-flex gap-3" style="margin-bottom:16px;justify-content:flex-end;">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-cloud-download-fill"></i> Importar seleccionadas
        </button>
        <a href="/admin/marques?user_id=<?= $user_id ?>" class="btn btn-gray">
          Cancelar
        </a>
      </div>
      <div class="card mb-4">
        <p style="margin:0;" class="text-muted text-sm">
          <strong><?= count($agrupats) ?></strong> marcas encontradas
          · Temporada <strong><?= e($filterTemporada) ?></strong>
          · <?= $pagines_llegides ?> página<?= $pagines_llegides !== 1 ? 's' : '' ?> leída<?= $pagines_llegides !== 1 ? 's' : '' ?> de RFEN.
          Si ya existe la marca, se actualizará <strong>solo si el tiempo es mejor</strong>.
        </p>
      </div>

      <div class="table-card">
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th style="width:36px;">
                  <input type="checkbox" id="sel-all" onchange="toggleAll(this)" checked>
                </th>
                <th>Prueba</th>
                <th>Piscina</th>
                <th>Tiempo</th>
                <th>Lugar</th>
                <th>Fecha</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($agrupats as $i => $r): ?>
                <tr>
                  <td>
                    <input type="checkbox" name="imp_sel[<?= $i ?>]" value="1" checked>
                  </td>
                  <td><strong><?= e(format_prova($r['prova'])) ?></strong></td>
                  <td><?= e($r['piscina']) ?></td>
                  <td><span class="mark-time"><?= e($r['temps']) ?></span></td>
                  <td class="text-sm text-muted"><?= e($r['lugar']) ?></td>
                  <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($r['data_iso'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </form>

    <script>
      function showPageLoading(message) {
        const overlay = document.getElementById('pageLoadingOverlay');
        if (!overlay) return;
        const text = overlay.querySelector('.text-muted');
        if (text && message) text.textContent = message;
        overlay.style.display = 'flex';
      }

      document.querySelectorAll('.js-loading-form').forEach(form => {
        form.addEventListener('submit', () => {
          showPageLoading('Consultando RFEN, esto puede tardar unos segundos.');
        });
      });

      document.querySelectorAll('.js-loading-select').forEach(select => {
        select.addEventListener('change', function () {
          showPageLoading('Consultando RFEN, esto puede tardar unos segundos.');
          this.form.requestSubmit();
        });
      });

      window.addEventListener('pageshow', () => {
        const overlay = document.getElementById('pageLoadingOverlay');
        if (overlay) overlay.style.display = 'none';
      });

      function toggleAll(cb) {
        document.querySelectorAll('input[name^="imp_sel"]').forEach(c => c.checked = cb.checked);
      }
    </script>

  <?php endif; ?>

<?php
});
render_footer();
