# RFEN Import Improvements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract RFEN helpers to a shared file, extend the season selector to 2012, and create a CLI script for weekly automated import of all users.

**Architecture:** Move RFEN parsing/fetching functions from `rfen_importar.php` to `includes/rfen.php`, add a shared `rfen_import_marks()` function. The admin page uses the shared functions. A new CLI script `scripts/rfen_import_all.php` loads `.env` + DB + shared helpers and imports the current season for all active users with `rfen_id`.

**Tech Stack:** PHP 8.4, MySQL 8, cURL, DOMDocument

---

### Task 1: Create `includes/rfen.php` with extracted helpers and shared import function

**Files:**
- Create: `includes/rfen.php`

- [ ] **Step 1: Create `includes/rfen.php` with all RFEN helper functions**

```php
<?php
// includes/rfen.php — Shared RFEN fetch, parse, and import helpers

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
  $valides = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];
  $prova = $dist . $suf;
  return in_array($prova, $valides) ? $prova : null;
}

function rfen_fecha_iso(string $fecha): string
{
  if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $fecha, $m))
    return $m[3] . '-' . $m[2] . '-' . $m[1];
  return date('Y-m-d');
}

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
      if (in_array('FECHA', $cells_text) && in_array('RESULTADO', $cells_text)) {
        foreach ($cells_text as $ci => $name) $col_idx[$name] = $ci;
        $header_found = true;
      }
      continue;
    }

    $cells = [];
    foreach ($tr->childNodes as $node)
      if ($node->nodeName === 'td') $cells[] = trim($node->textContent);
    if (count($cells) < 5) continue;

    $get = fn(string $col) => $cells[$col_idx[$col] ?? -1] ?? '';

    $relevo  = $get('RELEVO');
    $parcial = $get('PARCIAL');
    if ($relevo !== '' && $relevo !== '-')  continue;
    if ($parcial !== '' && $parcial !== '-') continue;

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

/**
 * Fetch and import RFEN marks for a single user.
 *
 * @param PDO    $pdo       Database connection
 * @param int    $user_id   Local user ID
 * @param string $rfen_id   RFEN identifier
 * @param ?string $temporada Season string like '2024-25', or null/'todas' for all
 * @return array{procesadas:int, insertadas:int, actualizadas:int, sin_cambios:int, error:?string}
 */
function rfen_import_marks(PDO $pdo, int $user_id, string $rfen_id, ?string $temporada = null): array
{
  $result = ['procesadas' => 0, 'insertadas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'error' => null];

  // Build date range
  $rfen_inicio = '';
  $rfen_fin    = '';
  if ($temporada && $temporada !== 'todas' && preg_match('/^(\d{4})-(\d{2})$/', $temporada, $m)) {
    $y_start = (int)$m[1];
    $rfen_inicio = $y_start       . '-09-01';
    $rfen_fin    = ($y_start + 1) . '-08-31';
  }

  // Build RFEN URL
  $base_params = http_build_query(array_filter([
    'e'                => $rfen_id,
    'x_OPCION'         => 'ResultadosNatacion',
    'x_FILTRO5_INICIO' => $rfen_inicio,
    'x_FILTRO5_FIN'    => $rfen_fin,
  ]));
  $current_url = 'https://intranet.rfen.es/ConsultarHistorial.dcl?' . $base_params;

  // Fetch all pages
  $registres = [];
  $pagines   = 0;

  while ($current_url && $pagines < 50) {
    $html = rfen_fetch_html($current_url);
    if (!$html) {
      $result['error'] = 'No se ha podido conectar con RFEN.';
      return $result;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $rows = rfen_parse_rows($xpath);
    if (empty($rows)) break;

    $registres = array_merge($registres, $rows);
    $pagines++;

    parse_str(parse_url($current_url, PHP_URL_QUERY), $qp);
    $current_page = (int)($qp['page'] ?? 1);
    $next_page    = $current_page + 1;

    $next_url = null;
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
    if (!$next_url) {
      $qp['page'] = $next_page;
      $next_url = 'https://intranet.rfen.es/ConsultarHistorial.dcl?' . http_build_query($qp);
    }
    $current_url = $next_url;
  }

  if (empty($registres)) return $result;

  // Deduplicate
  $agrupats = [];
  foreach ($registres as $r) {
    $key = implode('|', [$r['prova'], $r['piscina'], $r['data_iso'], mb_strtolower(trim($r['lugar'] ?? ''))]);
    if (!isset($agrupats[$key]) || $r['temps_seg'] < $agrupats[$key]['temps_seg']) {
      $agrupats[$key] = $r;
    }
  }

  // Import
  $PROVES = ['50L','100L','200L','400L','800L','1500L','50E','100E','200E','50B','100B','200B','50M','100M','200M','100X','200X','400X'];
  $stmt = $pdo->prepare('
    INSERT INTO marques (user_id, prova, piscina, temps, temps_seg, data_marca, lugar)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      temps=IF(VALUES(temps_seg)<temps_seg, VALUES(temps), temps),
      temps_seg=IF(VALUES(temps_seg)<temps_seg, VALUES(temps_seg), temps_seg),
      data_marca=IF(VALUES(temps_seg)<temps_seg, VALUES(data_marca), data_marca),
      lugar=IF(VALUES(temps_seg)<temps_seg, VALUES(lugar), lugar),
      updated_at=NOW()
  ');

  foreach ($agrupats as $r) {
    if (!in_array($r['prova'], $PROVES) || !in_array($r['piscina'], ['25m', '50m'])) continue;
    $secs = $r['temps_seg'];
    if ($secs <= 0) continue;

    $stmt->execute([$user_id, $r['prova'], $r['piscina'], $r['temps'], $secs, $r['data_iso'], trim($r['lugar'] ?? '')]);
    $result['procesadas']++;
    $affected = $stmt->rowCount();
    if ($affected === 1) $result['insertadas']++;
    elseif ($affected === 2) $result['actualizadas']++;
    else $result['sin_cambios']++;
  }

  return $result;
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/rfen.php
git commit -m "feat: extract RFEN helpers and import logic to includes/rfen.php"
```

---

### Task 2: Refactor `rfen_importar.php` to use shared helpers and extend season range

**Files:**
- Modify: `public/admin/rfen_importar.php`

- [ ] **Step 1: Add require for shared helpers**

After line 4 (`require_once dirname(__DIR__, 2) . '/includes/layout.php';`), add:

```php
require_once dirname(__DIR__, 2) . '/includes/rfen.php';
```

- [ ] **Step 2: Delete inline helper functions**

Remove lines 8–142 entirely (from `// ── Helpers ──` through the closing `}` of `rfen_parse_rows`). These functions are now in `includes/rfen.php`.

- [ ] **Step 3: Extend season range to 2012**

Find the season generation loop:
```php
for ($y = $current_year; $y >= $current_year - 5; $y--) {
```

Change to:
```php
for ($y = $current_year; $y >= 2012; $y--) {
```

Also update the comment above it from "últimas 6 seasons" to "desde 2012 hasta actual".

- [ ] **Step 4: Verify the file has no redefined functions**

Grep the file for `function rfen_` — should return zero matches.

- [ ] **Step 5: Commit**

```bash
git add public/admin/rfen_importar.php
git commit -m "rfen_importar: use shared helpers from includes/rfen.php, extend seasons to 2012"
```

---

### Task 3: Create CLI script `scripts/rfen_import_all.php`

**Files:**
- Create: `scripts/rfen_import_all.php`

- [ ] **Step 1: Create the CLI import script**

```php
#!/usr/bin/env php
<?php
/**
 * Import RFEN marks for all active users.
 *
 * Usage:
 *   php scripts/rfen_import_all.php                     # current season, all users
 *   php scripts/rfen_import_all.php --temporada=2024-25 # specific season
 *   php scripts/rfen_import_all.php --user_id=5         # single user
 *
 * Crontab (weekly, Wednesday 6am):
 *   0 6 * * 3  php /path/to/scripts/rfen_import_all.php >> /var/log/rfen_import.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Load project dependencies
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/rfen.php';

// Parse CLI arguments
$options = getopt('', ['temporada:', 'user_id:']);

// Default temporada: current season
$current_year = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$temporada = $options['temporada'] ?? $current_year . '-' . substr((string)($current_year + 1), 2);

$filter_user_id = isset($options['user_id']) ? (int)$options['user_id'] : null;

// Fetch users
$sql = "SELECT id, nom, rfen_id FROM users WHERE estado='activo' AND rfen_id IS NOT NULL AND rfen_id != ''";
$params = [];
if ($filter_user_id) {
    $sql .= ' AND id=?';
    $params[] = $filter_user_id;
}
$sql .= ' ORDER BY nom';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

if (empty($users)) {
    echo "[" . date('Y-m-d H:i:s') . "] No hay usuarios activos con RFEN vinculado.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Importando temporada {$temporada} para " . count($users) . " usuario(s)...\n";

$has_errors = false;

foreach ($users as $u) {
    $r = rfen_import_marks($pdo, (int)$u['id'], $u['rfen_id'], $temporada);

    $line = "[" . date('Y-m-d H:i:s') . "] " . $u['nom'] . " (rfen:" . $u['rfen_id'] . "): ";

    if ($r['error']) {
        $line .= "ERROR - " . $r['error'];
        $has_errors = true;
    } else {
        $line .= $r['procesadas'] . " procesadas, "
               . $r['insertadas'] . " insertadas, "
               . $r['actualizadas'] . " actualizadas, "
               . $r['sin_cambios'] . " sin cambios";
    }

    echo $line . "\n";

    // Small delay between users to avoid hammering RFEN
    usleep(500_000);
}

echo "[" . date('Y-m-d H:i:s') . "] Importacion completada.\n";
exit($has_errors ? 1 : 0);
```

- [ ] **Step 2: Make the script executable**

```bash
chmod +x scripts/rfen_import_all.php
```

- [ ] **Step 3: Test the script runs without errors (dry check)**

```bash
docker compose exec app php /var/www/html/scripts/rfen_import_all.php --user_id=999
```

Expected output contains: `No hay usuarios activos con RFEN vinculado.`

- [ ] **Step 4: Commit**

```bash
git add scripts/rfen_import_all.php
git commit -m "feat: add CLI script for automated RFEN import of all users"
```

---

### Task 4: Smoke test — admin RFEN import still works

- [ ] **Step 1: Test admin RFEN import page loads**

Login as admin, navigate to admin marques, select a user with RFEN ID, click "Importar desde RFEN".

Verify:
- Page loads without PHP errors
- Season selector shows seasons from current down to 2012-13
- "Todas" option still works

- [ ] **Step 2: Verify no function redefinition errors**

```bash
curl -s -b "$COOKIE_JAR" -L "http://localhost:8080/admin/rfen_importar?user_id=2" 2>&1 | grep -i "cannot redeclare\|fatal"
```

Expected: no output (no errors).
