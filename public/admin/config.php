<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

require_admin();

// Estructura agrupada — FINA (claves = nombre en JSON, con emoji para el título)
$FINA_PROVES = [
    '🌊 Libre'    => ['50 libre','100 libre','200 libre','400 libre','800 libre','1500 libre'],
    '↩ Espalda'  => ['50 espalda','100 espalda','200 espalda'],
    '🐸 Braza'    => ['50 braza','100 braza','200 braza'],
    '🦋 Mariposa' => ['50 mariposa','100 mariposa','200 mariposa'],
    '⭐ Estilos'  => ['100 estilos','200 estilos','400 estilos'],
];

// Estructura agrupada — Mínimas (mismos grupos, con códigos de prueba)
$MIN_PROVES_BY_GROUP = [
    '🌊 Libre'    => ['50L','100L','200L','400L','800L','1500L'],
    '↩ Espalda'  => ['50E','100E','200E'],
    '🐸 Braza'    => ['50B','100B','200B'],
    '🦋 Mariposa' => ['50M','100M','200M'],
    '⭐ Estilos'  => ['100X','200X','400X'],
];
// Lista plana de pruebas (para el POST handler)
$MIN_PROVES = array_merge(...array_values($MIN_PROVES_BY_GROUP));

$MIN_CATS = ['alevin'=>'Alevín','infantil'=>'Infantil','junior'=>'Junior','sub20'=>'Sub20','absoluto'=>'Absoluto'];

// Temporadas: 2 años antes hasta 3 años después del año actual
$anyActual  = (int)date('Y');
$temporadas = [];
for ($y = $anyActual - 2; $y <= $anyActual + 3; $y++) {
    $temporadas[] = $y . '-' . substr((string)($y + 1), 2);
}

// --- POST: guardar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Temporada
    $temporada = trim($_POST['temporada_activa'] ?? '');
    if (!in_array($temporada, $temporadas, true)) $temporada = $temporadas[2]; // año actual
    $pdo->prepare('INSERT INTO config (clau, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?')
        ->execute(['temporada_activa', $temporada, $temporada]);

    // Tiempos FINA: reconstruir JSON desde inputs estructurados
    $finaData = [];
    foreach ($_POST['fina'] ?? [] as $prueba => $sexoArr) {
        foreach ($sexoArr as $sexo => $piscinaArr) {
            foreach ($piscinaArr as $piscina => $val) {
                $val = trim($val);
                if ($val !== '') {
                    $seg = temps_a_segons($val);
                    if ($seg > 0) {
                        $finaData["{$prueba}_{$sexo}_{$piscina}"] = round($seg, 2);
                    }
                }
            }
        }
    }
    $finaJson = json_encode($finaData);
    $pdo->prepare('INSERT INTO config (clau, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?')
        ->execute(['fina_times', $finaJson, $finaJson]);

    // Mínimas RFEN: reconstruir JSON con dimensión piscina
    // Formato: $minData[prueba][piscina][sexo][cat]
    $minData = [];
    foreach ($MIN_PROVES as $prueba) {
        foreach (['25m', '50m'] as $piscina) {
            foreach (['M', 'F'] as $sexo) {
                foreach (array_keys($MIN_CATS) as $cat) {
                    $val = trim($_POST['min'][$prueba][$piscina][$sexo][$cat] ?? '');
                    $minData[$prueba][$piscina][$sexo][$cat] = ($val !== '') ? round(temps_a_segons($val), 2) : null;
                }
            }
        }
    }
    $minJson = json_encode($minData);
    $pdo->prepare('INSERT INTO config (clau, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?')
        ->execute(['minimes_rfen', $minJson, $minJson]);

    flash('Configuración guardada correctamente.', 'success');
    header('Location: /admin/config');
    exit;
}

// --- Cargar valores desde BD ---
$configRows = $pdo->query('SELECT clau, valor FROM config')->fetchAll(PDO::FETCH_KEY_PAIR);
$finaData   = json_decode($configRows['fina_times']   ?? '{}', true) ?? [];
$minData    = json_decode($configRows['minimes_rfen'] ?? '{}', true) ?? [];

// Migración: detectar formato antiguo (sin dimensión piscina) y convertir
if (!empty($minData)) {
    $firstKey = array_key_first($minData);
    $firstVal = $minData[$firstKey] ?? [];
    // Formato antiguo: $minData[prueba][sexo][cat] — sexo es 'M' o 'F', no '25m'/'50m'
    if (isset($firstVal['M']) || isset($firstVal['F'])) {
        $newMinData = [];
        foreach ($minData as $prueba => $sexoArr) {
            $newMinData[$prueba]['25m'] = $sexoArr;
            foreach ($sexoArr as $sexo => $catArr) {
                foreach (array_keys((array)$catArr) as $cat) {
                    $newMinData[$prueba]['50m'][$sexo][$cat] = null;
                }
            }
        }
        $minData = $newMinData;
    }
}

// Helper: tiempo FINA formateado para un input
function cfg_fina(array $data, string $prueba, string $sexo, string $piscina): string {
    $key = "{$prueba}_{$sexo}_{$piscina}";
    return isset($data[$key]) ? segons_a_temps((float)$data[$key]) : '';
}

// Helper: mínima formateada para un input
function cfg_min(array $data, string $prueba, string $piscina, string $sexo, string $cat): string {
    $val = $data[$prueba][$piscina][$sexo][$cat] ?? null;
    return ($val !== null) ? segons_a_temps((float)$val) : '';
}

render_header('Configuración', 'admin-config');
render_admin_layout('config', function() use ($configRows, $finaData, $minData, $FINA_PROVES, $MIN_PROVES_BY_GROUP, $MIN_CATS, $temporadas) {
?>

<h1>Configuración</h1>

<?php render_flash(); ?>

<form method="POST" id="form-config">

  <!-- ========== General ========== -->
  <div class="card mb-6">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px;">
      <i class="bi bi-gear-fill"></i> General
    </h2>
    <div class="form-group" style="max-width:300px;">
      <label class="form-label">Temporada activa</label>
      <select name="temporada_activa" class="form-control">
        <?php foreach ($temporadas as $t): ?>
          <option value="<?= e($t) ?>" <?= ($configRows['temporada_activa'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">Se usa en marcas y rankings.</div>
    </div>
  </div>

  <!-- ========== Tiempos FINA ========== -->
  <div class="card mb-6">
    <div class="card-header">
      <div>
        <h2 class="card-title"><i class="bi bi-stopwatch-fill"></i> Tiempos FINA</h2>
        <p class="text-muted text-sm" style="margin-top:4px;">
          Tiempos mundiales de referencia para el cálculo de puntos AQUA.<br>
          Formato: <code>ss.cc</code> o <code>m:ss.cc</code> &nbsp;·&nbsp; <strong>Masc.</strong> = Masculino &nbsp;·&nbsp; <strong>Fem.</strong> = Femenino
        </p>
      </div>
    </div>

    <?php foreach ($FINA_PROVES as $grupo => $proves): ?>
    <div class="marks-section">
      <div class="marks-section-title"><?= $grupo ?></div>
      <div class="table-wrapper">
        <table class="config-table">
          <thead>
            <tr>
              <th rowspan="2" style="min-width:120px;">Prueba</th>
              <th colspan="2" style="text-align:center;background:#eef2ff;color:var(--blue);">Masculino</th>
              <th colspan="2" style="text-align:center;background:#fdf2f8;color:#9d174d;">Femenino</th>
            </tr>
            <tr>
              <th style="background:#f0f4ff;">25m</th>
              <th style="background:#f0f4ff;">50m</th>
              <th style="background:#fdf2f8;">25m</th>
              <th style="background:#fdf2f8;">50m</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($proves as $prueba): ?>
            <tr>
              <td style="font-weight:600;font-size:13px;"><?= e(ucfirst($prueba)) ?></td>
              <?php foreach (['M', 'F'] as $sexo): ?>
                <?php foreach (['25m', '50m'] as $pisc): ?>
                  <?php
                  $noExiste = ($prueba === '100 estilos' && $pisc === '50m');
                  $val = cfg_fina($finaData, $prueba, $sexo, $pisc);
                  $bg  = $sexo === 'M' ? 'background:#f8faff;' : 'background:#fdf7fb;';
                  ?>
                  <td style="<?= $bg ?>">
                    <?php if ($noExiste): ?>
                      <span class="text-muted" style="font-size:12px;padding:0 8px;">—</span>
                    <?php else: ?>
                      <input type="text"
                             name="fina[<?= e($prueba) ?>][<?= $sexo ?>][<?= $pisc ?>]"
                             value="<?= e($val) ?>"
                             class="config-input"
                             placeholder="ss.cc">
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ========== Mínimas RFEN ========== -->
  <div class="card mb-6">
    <div class="card-header">
      <div>
        <h2 class="card-title"><i class="bi bi-trophy-fill"></i> Mínimas RFEN</h2>
        <p class="text-muted text-sm" style="margin-top:4px;">
          Tiempos mínimos requeridos por categoría para competiciones nacionales.<br>
          Formato: <code>ss.cc</code> o <code>m:ss.cc</code> &nbsp;·&nbsp; Dejar vacío si no existe mínima para esa combinación.
        </p>
      </div>
    </div>

    <!-- Selector piscina -->
    <div style="display:flex;gap:8px;margin-bottom:24px;">
      <button type="button" class="pisc-btn active" id="mintab-25m" onclick="showMinPisc('25m')">
        <i class="bi bi-water"></i> 25 metros
      </button>
      <button type="button" class="pisc-btn" id="mintab-50m" onclick="showMinPisc('50m')">
        <i class="bi bi-water"></i> 50 metros
      </button>
    </div>

    <?php foreach (['25m', '50m'] as $pisc): ?>
    <div id="min-pisc-<?= $pisc ?>" <?= $pisc === '50m' ? 'style="display:none"' : '' ?>>
      <?php foreach (['M' => ['label'=>'Masculino','color'=>'var(--blue)','cellbg'=>'#f8faff'], 'F' => ['label'=>'Femenino','color'=>'#9d174d','cellbg'=>'#fdf7fb']] as $sexo => $meta): ?>
      <div style="margin-bottom:32px;">
        <div style="font-size:13px;font-weight:700;color:<?= $meta['color'] ?>;text-transform:uppercase;letter-spacing:0.07em;padding:0 0 12px;border-bottom:2px solid currentColor;margin-bottom:20px;">
          <?= $meta['label'] ?>
        </div>

        <?php foreach ($MIN_PROVES_BY_GROUP as $grupo => $proves): ?>
        <div class="marks-section">
          <div class="marks-section-title"><?= $grupo ?></div>
          <div class="table-wrapper">
            <table class="config-table">
              <thead>
                <tr>
                  <th style="min-width:120px;">Prueba</th>
                  <?php foreach ($MIN_CATS as $cat => $label): ?>
                    <th><?= $label ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($proves as $prueba): ?>
                <tr>
                  <td style="font-weight:600;font-size:13px;"><?= e(format_prova($prueba)) ?></td>
                  <?php foreach (array_keys($MIN_CATS) as $cat): ?>
                    <td style="background:<?= $meta['cellbg'] ?>;">
                      <input type="text"
                             name="min[<?= e($prueba) ?>][<?= $pisc ?>][<?= $sexo ?>][<?= $cat ?>]"
                             value="<?= e(cfg_min($minData, $prueba, $pisc, $sexo, $cat)) ?>"
                             class="config-input"
                             placeholder="—">
                    </td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Botones -->
  <div class="d-flex gap-3" style="margin-bottom:40px;">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg"></i> Guardar cambios
    </button>
    <button type="button" class="btn btn-gray" onclick="history.back()">Cancelar</button>
  </div>

</form>

<style>
.config-input {
  width: 78px;
  padding: 4px 6px;
  font-size: 12px;
  font-family: monospace;
  text-align: center;
  border: 1.5px solid #e0e0e0;
  border-radius: 6px;
  background: white;
  color: var(--text);
  transition: border-color 0.15s;
}
.config-input:focus { outline: none; border-color: var(--blue); }
.config-input::placeholder { color: #ccc; }
.config-table td { padding: 7px 10px; }
.config-table th { padding: 8px 10px; }

.pisc-btn {
  padding: 7px 18px;
  font-size: 13px;
  font-weight: 600;
  border: 2px solid #ddd;
  border-radius: 8px;
  background: white;
  color: #555;
  cursor: pointer;
  transition: all 0.15s;
}
.pisc-btn:hover { border-color: var(--blue); color: var(--blue); }
.pisc-btn.active { border-color: var(--blue); background: var(--blue); color: white; }
</style>

<script>
function showMinPisc(pisc) {
  ['25m','50m'].forEach(function(p) {
    document.getElementById('min-pisc-' + p).style.display = (p === pisc) ? '' : 'none';
    document.getElementById('mintab-' + p).classList.toggle('active', p === pisc);
  });
}
</script>

<?php
});
render_footer();
