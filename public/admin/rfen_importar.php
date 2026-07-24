<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/rfen.php';

require_admin_area(['director_tecnico']);

// ── Cargar usuario ───────────────────────────────────────────────────────────

$user_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if (!$user_id) {
  flash('Usuario no especificado.', 'danger');
  header('Location: /admin/marcas');
  exit;
}

$stmt = $pdo->prepare('SELECT id, nombre, liga, sexo, rfen_id FROM users WHERE id=?');
$stmt->execute([$user_id]);
$nadador = $stmt->fetch();

if (!$nadador || !$nadador['rfen_id']) {
  flash('Este usuario no tiene vinculación RFEN.', 'danger');
  header('Location: /admin/marcas?user_id=' . $user_id);
  exit;
}

// ── POST: confirmar importación ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $PRUEBAS = [
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
            INSERT INTO marcas (user_id, prueba, piscina, tiempo, tiempo_seg, fecha_marca, lugar)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                tiempo=IF(VALUES(tiempo_seg)<tiempo_seg, VALUES(tiempo), tiempo),
                tiempo_seg=IF(VALUES(tiempo_seg)<tiempo_seg, VALUES(tiempo_seg), tiempo_seg),
                fecha_marca=IF(VALUES(tiempo_seg)<tiempo_seg, VALUES(fecha_marca), fecha_marca),
                lugar=IF(VALUES(tiempo_seg)<tiempo_seg, VALUES(lugar), lugar),
                updated_at=NOW()
        ');
  foreach (array_keys($_POST['imp_sel'] ?? []) as $idx) {
    $idx = (string)$idx;
    if (!isset($payload[$idx]) || !is_array($payload[$idx])) {
      continue;
    }
    $row = $payload[$idx];
    $prueba  = (string)($row['prueba'] ?? '');
    $piscina = (string)($row['piscina'] ?? '');
    $tiempo  = (string)($row['tiempo'] ?? '');
    $fecha_m = (string)($row['fecha'] ?? '');
    $lugar   = trim((string)($row['lugar'] ?? ''));
    if (!in_array($prueba, $PRUEBAS) || !in_array($piscina, ['25m', '50m']) || !$tiempo) {
      continue;
    }
    $secs = tiempo_a_segundos($tiempo);
    if ($secs <= 0) {
      continue;
    }
    $stmtImport->execute([$user_id, $prueba, $piscina, $tiempo, $secs, $fecha_m, $lugar]);
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
  header('Location: /admin/marcas?user_id=' . $user_id);
  exit;
}

// ── GET: selección de temporada y fetch paginado ──────────────────────────────

// Temporadas disponibles (desde 2012 hasta actual)
$current_year  = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporadas_disp = [];
for ($y = $current_year; $y >= 2012; $y--) {
  $temporadas_disp[] = $y . '-' . substr((string)($y + 1), 2);
}

$filterTemporada = $_GET['temporada'] ?? $temporadas_disp[0];
if (!in_array($filterTemporada, $temporadas_disp) && $filterTemporada !== 'todas')
  $filterTemporada = $temporadas_disp[0];

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
$registros   = [];
$paginas_leidas = 0;
$current_url = $rfen_base;

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

  $registros = array_merge($registros, $rows);
  $paginas_leidas++;

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

  // Estrategia 1: link con page=N+1 en el href
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

if (empty($registros) && !$parse_error) {
  $parse_error = 'No hay marcas para la temporada seleccionada.';
}

// Agrupar por prueba+piscina+fecha+lugar para evitar duplicados exactos del feed RFEN
$agrupados = [];
foreach ($registros as $r) {
  $key = implode('|', [$r['prueba'], $r['piscina'], $r['fecha_iso'], mb_strtolower(trim($r['lugar'] ?? ''))]);
  if (!isset($agrupados[$key]) || $r['tiempo_seg'] < $agrupados[$key]['tiempo_seg']) {
    $agrupados[$key] = $r;
  }
}
usort($agrupados, fn($a, $b) => [$b['fecha_iso'], $a['prueba'], $a['piscina']] <=> [$a['fecha_iso'], $b['prueba'], $b['piscina']]);

// ── Render ────────────────────────────────────────────────────────────────────

render_header('Importar desde RFEN', 'admin-marcas');
render_admin_layout('marcas', function () use ($nadador, $user_id, $agrupados, $parse_error, $rfen_base, $temporadas_disp, $filterTemporada, $paginas_leidas) {
?>

  <div class="d-flex align-center gap-3 mb-6" style="flex-wrap:wrap;">
    <a href="/admin/marcas?user_id=<?= $user_id ?>" class="btn btn-gray btn-sm">
      <i class="bi bi-arrow-left"></i> Volver
    </a>
    <h1 style="margin:0;">Importar desde RFEN — <?= e($nadador['nombre']) ?></h1>
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
          <?php foreach ($temporadas_disp as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterTemporada === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>


  <?php if ($parse_error): ?>
    <div class="alert alert-danger"><?= e($parse_error) ?></div>
    <p class="text-muted text-sm">URL consultada: <a href="<?= e($rfen_base) ?>" target="_blank"><?= e($rfen_base) ?></a></p>
  <?php elseif (empty($agrupados)): ?>
    <div class="alert alert-info">No hay marcas para la temporada seleccionada.</div>
  <?php else: ?>


    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="user_id" value="<?= $user_id ?>">
      <input type="hidden" name="imp_payload" value="<?= e(json_encode(array_map(
        fn($r) => [
          'prueba' => $r['prueba'],
          'piscina' => $r['piscina'],
          'tiempo' => $r['tiempo'],
          'fecha' => $r['fecha_iso'],
          'lugar' => $r['lugar'],
        ],
        $agrupados
      ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">

      <div class="d-flex gap-3" style="margin-bottom:16px;justify-content:flex-end;">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-cloud-download-fill"></i> Importar seleccionadas
        </button>
        <a href="/admin/marcas?user_id=<?= $user_id ?>" class="btn btn-gray">
          Cancelar
        </a>
      </div>
      <div class="card mb-4">
        <p style="margin:0;" class="text-muted text-sm">
          <strong><?= count($agrupados) ?></strong> marcas encontradas
          · Temporada <strong><?= e($filterTemporada) ?></strong>
          · <?= $paginas_leidas ?> página<?= $paginas_leidas !== 1 ? 's' : '' ?> leída<?= $paginas_leidas !== 1 ? 's' : '' ?> de RFEN.
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
              <?php foreach ($agrupados as $i => $r): ?>
                <tr>
                  <td>
                    <input type="checkbox" name="imp_sel[<?= $i ?>]" value="1" checked>
                  </td>
                  <td><strong><?= e(format_prueba($r['prueba'])) ?></strong></td>
                  <td><?= e($r['piscina']) ?></td>
                  <td><span class="mark-time"><?= e($r['tiempo']) ?></span></td>
                  <td class="text-sm text-muted"><?= e($r['lugar']) ?></td>
                  <td class="text-sm text-muted"><?= date('d/m/Y', strtotime($r['fecha_iso'])) ?></td>
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
