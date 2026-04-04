<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

require_login();

// Cargar config desde BD (con fallback a null si no existe)
$finaTimesJson = $pdo->query("SELECT valor FROM config WHERE clau='fina_times' LIMIT 1")->fetchColumn() ?: null;
$minimesJson   = $pdo->query("SELECT valor FROM config WHERE clau='minimes_rfen' LIMIT 1")->fetchColumn() ?: null;
$edatsCatJson  = $pdo->query("SELECT valor FROM config WHERE clau='minimes_edats' LIMIT 1")->fetchColumn() ?: null;

render_header('Calculadoras', 'calculadoras');
?>

<div class="container page-content">
  <div class="mb-6">
    <h1 style="margin-bottom:4px;">Calculadoras</h1>
    <p class="text-muted">Herramientas de rendimiento para nadadores</p>
  </div>

  <!-- Tabs -->
  <div class="calc-tabs">
    <button class="calc-tab active" onclick="switchTab('aqua', this)"><i class="bi bi-bar-chart-fill"></i> Puntos AQUA</button>
    <button class="calc-tab" onclick="switchTab('minimas', this)"><i class="bi bi-trophy-fill"></i> Mínimas RFEN</button>
    <button class="calc-tab" onclick="switchTab('parciales', this)"><i class="bi bi-stopwatch-fill"></i> Parciales</button>
  </div>

  <!-- Panel AQUA -->
  <div id="panel-aqua" class="calc-panel active">
    <div class="card">
      <h2 style="font-size:16px;font-weight:700;margin-bottom:4px;">Puntos AQUA</h2>
      <p class="text-muted text-sm mb-6">Fórmula: 1000 × (Tiempo FINA / Tu marca)³</p>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Prueba</label>
          <select id="aqua-prueba" class="form-control">
            <option value="">— Seleccionar —</option>
            <optgroup label="🌊 Libre">
              <option value="50 libre">50 Libre</option>
              <option value="100 libre">100 Libre</option>
              <option value="200 libre">200 Libre</option>
              <option value="400 libre">400 Libre</option>
              <option value="800 libre">800 Libre</option>
              <option value="1500 libre">1500 Libre</option>
            </optgroup>
            <optgroup label="↩ Espalda">
              <option value="50 espalda">50 Espalda</option>
              <option value="100 espalda">100 Espalda</option>
              <option value="200 espalda">200 Espalda</option>
            </optgroup>
            <optgroup label="🐸 Braza">
              <option value="50 braza">50 Braza</option>
              <option value="100 braza">100 Braza</option>
              <option value="200 braza">200 Braza</option>
            </optgroup>
            <optgroup label="🦋 Mariposa">
              <option value="50 mariposa">50 Mariposa</option>
              <option value="100 mariposa">100 Mariposa</option>
              <option value="200 mariposa">200 Mariposa</option>
            </optgroup>
            <optgroup label="⭐ Estilos">
              <option value="100 estilos">100 Estilos</option>
              <option value="200 estilos">200 Estilos</option>
              <option value="400 estilos">400 Estilos</option>
            </optgroup>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Sexo</label>
          <select id="aqua-sexo" class="form-control">
            <option value="M">Masculino</option>
            <option value="F">Femenino</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Piscina</label>
          <select id="aqua-piscina" class="form-control">
            <option value="25m">25 metros</option>
            <option value="50m">50 metros</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Tu marca</label>
          <input type="text" id="aqua-marca" class="form-control" placeholder="Ej: 28.50 o 1:05.43">
        </div>
      </div>
      <div id="aqua-error" style="color:var(--red);font-size:13px;margin-bottom:8px;"></div>
      <div class="d-flex gap-2">
        <button onclick="calcularAQUA()" class="btn btn-primary">Calcular</button>
        <button onclick="limpiarAQUA()" class="btn btn-gray">Limpiar</button>
      </div>

      <div id="aqua-result" class="calc-result">
        <hr style="margin:24px 0;border:none;border-top:1px solid #f0f0f0;">
        <div class="text-center" style="margin-bottom:24px;">
          <div class="calc-puntos-big" id="aqua-puntos">—<span>pts</span></div>
          <div class="text-muted text-sm mt-2">Puntos AQUA</div>
        </div>

        <div class="calc-meta mb-6">
          <div class="calc-meta-item">
            <label>Tiempo FINA</label>
            <span id="aqua-fina">—</span>
          </div>
          <div class="calc-meta-item">
            <label>Tu marca</label>
            <span id="aqua-marca-show">—</span>
          </div>
        </div>

        <div style="margin-bottom:8px;font-size:13px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:0.05em;">
          Niveles del club
        </div>
        <div class="calc-badge not" id="nivel-300">Iniciación competitiva (300 pts) <span id="nivel-300-val">✗</span></div>
        <div class="calc-badge not" id="nivel-400">Nivel club (400 pts) <span id="nivel-400-val">✗</span></div>
        <div class="calc-badge not" id="nivel-500">Nivel autonómico (500 pts) <span id="nivel-500-val">✗</span></div>
        <div class="calc-badge not" id="nivel-600">Nivel nacional (600 pts) <span id="nivel-600-val">✗</span></div>
      </div>
    </div>
  </div>

  <!-- Panel Mínimas -->
  <div id="panel-minimas" class="calc-panel">
    <div class="card">
      <h2 style="font-size:16px;font-weight:700;margin-bottom:4px;">Mínimas RFEN</h2>
      <p class="text-muted text-sm mb-6">Comprueba si cumples la mínima para tu categoría (temporada 25/26)</p>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Prueba</label>
          <select id="min-prueba" class="form-control">
            <option value="">— Seleccionar —</option>
            <?php render_prova_options(''); ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Sexo</label>
          <select id="min-sexo" class="form-control">
            <option value="M">Masculino</option>
            <option value="F">Femenino</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Categoría</label>
          <select id="min-categoria" class="form-control" onchange="actualizarEdatSelect()">
            <option value="alevin">Alevín</option>
            <option value="infantil">Infantil</option>
            <option value="junior">Junior</option>
            <option value="sub20">Sub-20</option>
            <option value="absoluto" selected>Absoluto</option>
          </select>
        </div>
        <div class="form-group" id="min-edat-group">
          <label class="form-label">Edad</label>
          <select id="min-edat" class="form-control">
            <option value="">— Seleccionar —</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Piscina</label>
          <select id="min-piscina" class="form-control">
            <option value="25m">25 metros (PC)</option>
            <option value="50m">50 metros (PL)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Tu marca</label>
          <input type="text" id="min-marca" class="form-control" placeholder="Ej: 28.50 o 1:05.43">
        </div>
      </div>
      <div id="min-error" style="color:var(--red);font-size:13px;margin-bottom:8px;"></div>
      <div class="d-flex gap-2">
        <button onclick="calcularMinimas()" class="btn btn-primary">Calcular</button>
        <button onclick="limpiarMinimas()" class="btn btn-gray">Limpiar</button>
      </div>

      <div id="min-result" class="calc-result">
        <hr style="margin:24px 0;border:none;border-top:1px solid #f0f0f0;">
        <div class="text-center" style="margin-bottom:24px;">
          <div class="calc-percent-big" id="min-percent">—%</div>
          <div class="text-muted text-sm mt-2">% respecto a la mínima</div>
        </div>
        <div class="calc-meta mb-4">
          <div class="calc-meta-item">
            <label>Mínima requerida</label>
            <span id="min-req">—</span>
          </div>
          <div class="calc-meta-item">
            <label>Tu marca</label>
            <span id="min-marca-show">—</span>
          </div>
        </div>
        <div id="min-diff" style="font-size:14px;margin-bottom:12px;"></div>
        <div id="min-msg" style="padding:12px 16px;border-radius:8px;font-size:14px;font-weight:600;"></div>
      </div>
    </div>
  </div>

  <!-- Panel Parciales -->
  <div id="panel-parciales" class="calc-panel">
    <div class="card">
      <h2 style="font-size:16px;font-weight:700;margin-bottom:4px;">Calculadora de parciales</h2>
      <p class="text-muted text-sm mb-6">Planifica tu ritmo por longitudes según tu tiempo objetivo</p>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Prueba</label>
          <select id="par-prueba" class="form-control">
            <option value="100_25">100m (25m por longitud)</option>
            <option value="200_25">200m (25m por longitud)</option>
            <option value="400_25">400m (25m por longitud)</option>
            <option value="800_25">800m (25m por longitud)</option>
            <option value="1500_25">1500m (25m por longitud)</option>
            <option value="50_50">50m (50m por longitud)</option>
            <option value="100_50">100m (50m por longitud)</option>
            <option value="200_50">200m (50m por longitud)</option>
            <option value="400_50">400m (50m por longitud)</option>
            <option value="800_50">800m (50m por longitud)</option>
            <option value="1500_50">1500m (50m por longitud)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Tiempo objetivo</label>
          <input type="text" id="par-tiempo" class="form-control" placeholder="Ej: 1:05.43">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Estrategia de pacing</label>
        <select id="par-estrategia" class="form-control">
          <option value="uniforme">Uniforme (mismo ritmo)</option>
          <option value="negativo" selected>Split negativo (recomendado)</option>
          <option value="positivo">Split positivo (arranque rápido)</option>
        </select>
      </div>
      <div id="par-error" style="color:var(--red);font-size:13px;margin-bottom:8px;"></div>
      <button onclick="calcularParciales()" class="btn btn-primary">Calcular parciales</button>

      <div id="par-result" class="calc-result">
        <hr style="margin:24px 0;border:none;border-top:1px solid #f0f0f0;">
        <div class="table-wrapper">
          <table class="parciales-table">
            <thead>
              <tr>
                <th>#</th><th>Distancia</th><th>Tiempo longitud</th><th>Acumulado</th><th>% vs base</th>
              </tr>
            </thead>
            <tbody id="par-splits"></tbody>
          </table>
        </div>
        <div id="par-legend" style="margin-top:12px;font-size:13px;"></div>
        <div id="par-note"   style="margin-top:8px;font-size:13px;color:#888;"></div>
      </div>
    </div>
  </div>
</div>

<?php if ($finaTimesJson || $minimesJson || $edatsCatJson): ?>
<script>
<?php if ($finaTimesJson): ?>window.FINA_TIMES_DB = <?= $finaTimesJson ?>;<?php endif; ?>
<?php if ($minimesJson): ?>window.MINIMES_DB = <?= $minimesJson ?>;<?php endif; ?>
<?php if ($edatsCatJson): ?>window.EDATS_CAT_DB = <?= $edatsCatJson ?>;<?php endif; ?>
</script>
<?php endif; ?>
<script src="/assets/js/calculadora.js"></script>

<?php render_footer(); ?>
