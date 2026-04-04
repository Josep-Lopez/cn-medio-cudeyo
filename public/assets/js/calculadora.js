// ============================================================
// CN Medio Cudeyo — Calculadora de rendimiento
// Extraído del diseño Webflow. Sin dependencias externas.
// ============================================================

// FINA Times (tiempos mundiales de referencia, temporada 2024-25)
// Si se inyecta window.FINA_TIMES_DB desde PHP, se usa ese valor (datos de la BD).
const FINA_TIMES = (typeof window.FINA_TIMES_DB !== 'undefined') ? window.FINA_TIMES_DB : {
  "50 libre_M_50m": 20.91, "50 libre_M_25m": 19.9,
  "50 libre_F_50m": 23.61, "50 libre_F_25m": 22.83,
  "100 libre_M_50m": 46.4, "100 libre_M_25m": 44.84,
  "100 libre_F_50m": 51.71, "100 libre_F_25m": 50.25,
  "200 libre_M_50m": 102.0, "200 libre_M_25m": 98.61,
  "200 libre_F_50m": 112.23, "200 libre_F_25m": 110.31,
  "400 libre_M_50m": 219.96, "400 libre_M_25m": 212.25,
  "400 libre_F_50m": 235.38, "400 libre_F_25m": 230.25,
  "800 libre_M_50m": 452.12, "800 libre_M_25m": 440.46,
  "800 libre_F_50m": 484.79, "800 libre_F_25m": 477.42,
  "1500 libre_M_50m": 870.67, "1500 libre_M_25m": 846.88,
  "1500 libre_F_50m": 920.48, "1500 libre_F_25m": 908.24,
  "50 espalda_M_50m": 23.55, "50 espalda_M_25m": 22.11,
  "50 espalda_F_50m": 26.86, "50 espalda_F_25m": 25.23,
  "100 espalda_M_50m": 51.6, "100 espalda_M_25m": 48.33,
  "100 espalda_F_50m": 57.13, "100 espalda_F_25m": 54.02,
  "200 espalda_M_50m": 111.92, "200 espalda_M_25m": 105.63,
  "200 espalda_F_50m": 123.14, "200 espalda_F_25m": 118.04,
  "50 braza_M_50m": 25.95, "50 braza_M_25m": 24.95,
  "50 braza_F_50m": 29.16, "50 braza_F_25m": 28.37,
  "100 braza_M_50m": 56.88, "100 braza_M_25m": 55.28,
  "100 braza_F_50m": 64.13, "100 braza_F_25m": 62.36,
  "200 braza_M_50m": 125.48, "200 braza_M_25m": 120.16,
  "200 braza_F_50m": 137.55, "200 braza_F_25m": 132.5,
  "50 mariposa_M_50m": 22.27, "50 mariposa_M_25m": 21.32,
  "50 mariposa_F_50m": 24.43, "50 mariposa_F_25m": 23.94,
  "100 mariposa_M_50m": 49.45, "100 mariposa_M_25m": 47.71,
  "100 mariposa_F_50m": 54.6, "100 mariposa_F_25m": 52.71,
  "200 mariposa_M_50m": 110.34, "200 mariposa_M_25m": 106.85,
  "200 mariposa_F_50m": 121.81, "200 mariposa_F_25m": 119.32,
  "100 estilos_M_25m": 49.28, "100 estilos_F_25m": 55.11,
  "200 estilos_M_50m": 114.0, "200 estilos_M_25m": 108.88,
  "200 estilos_F_50m": 126.12, "200 estilos_F_25m": 121.63,
  "400 estilos_M_50m": 242.5, "400 estilos_M_25m": 234.41,
  "400 estilos_F_50m": 264.38, "400 estilos_F_25m": 255.48,
};

// Mínimas RFEN (temporada 2025-26)
// Si se inyecta window.MINIMES_DB desde PHP, se usa ese valor (datos de la BD).
// Formato:
//   age-based:  MINIMES[prueba][piscina][sexo][cat][age]  p.ej. ["alevin"]["12"]
//   sin edad:   MINIMES[prueba][piscina][sexo][cat]        p.ej. ["sub20"] = float|null
const MINIMES = (typeof window.MINIMES_DB !== 'undefined') ? window.MINIMES_DB : {
  "50L":   { "25m": { M:{ alevin:{"12":28.10,"13":28.10}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:23.85 }, F:{ alevin:{"12":29.30,"13":29.30}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:26.85 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "100L":  { "25m": { M:{ alevin:{"12":62.00,"13":62.00}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:51.90 }, F:{ alevin:{"12":63.90,"13":63.90}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:58.50 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "200L":  { "25m": { M:{ alevin:{"12":136.00,"13":136.00}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:113.75 }, F:{ alevin:{"12":140.00,"13":140.00}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:126.95 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "400L":  { "25m": { M:{ alevin:{"12":290.00,"13":290.00}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:244.50 }, F:{ alevin:{"12":294.35,"13":294.35}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:267.30 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "800L":  { "25m": { M:{ alevin:{"12":597.00,"13":597.00}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:508.00 }, F:{ alevin:{"12":607.00,"13":607.00}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:545.90 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "1500L": { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:979.00 }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:1050.00 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "50E":   { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:27.60  }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:30.95  } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "100E":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:58.75  }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:65.75  } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "200E":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:129.50 }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:142.55 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "50B":   { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:29.80  }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:33.95  } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "100B":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:65.00  }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:74.50  } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "200B":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:144.10 }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:161.50 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "50M":   { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:25.10  }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:28.40  } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "100M":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:56.00  }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:63.80  } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "200M":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:128.40 }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:141.00 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "100X":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null   }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null   } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "200X":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:129.20 }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:144.00 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
  "400X":  { "25m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:278.90 }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:303.95 } }, "50m": { M:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null }, F:{ alevin:{"12":null,"13":null}, infantil:{"14":null,"15":null}, junior:{"16":null,"17":null,"18":null}, sub20:null, absoluto:null } } },
};

// Edats per categoria (rangs d'edat RFEN — només Alevín/Infantil/Junior)
// Si se inyecta window.EDATS_CAT_DB desde PHP, se usa ese valor (datos de la BD).
const EDATS_CAT = (typeof window.EDATS_CAT_DB !== 'undefined') ? window.EDATS_CAT_DB : {
  alevin:   { min: 12, max: 13 },
  infantil: { min: 14, max: 15 },
  junior:   { min: 16, max: 18 }
};

// ============================================================
// Utils
// ============================================================
function parseTime(t) {
  t = t.trim().replace(',', '.');
  if (t.includes(':')) {
    const parts = t.split(':');
    return parseFloat(parts[0]) * 60 + parseFloat(parts[1]);
  }
  return parseFloat(t);
}

function formatTime(s) {
  if (s === null || s === undefined || isNaN(s)) return '—';
  if (s >= 60) {
    const m   = Math.floor(s / 60);
    const sec = (s % 60).toFixed(2).padStart(5, '0');
    return `${m}:${sec}`;
  }
  return s.toFixed(2);
}

// ============================================================
// Tabs
// ============================================================
window.switchTab = function(tab, btn) {
  document.querySelectorAll('.calc-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.calc-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('panel-' + tab).classList.add('active');
  if (btn) btn.classList.add('active');
};

// ============================================================
// Calculadora AQUA
// ============================================================
window.calcularAQUA = function() {
  const prueba   = document.getElementById('aqua-prueba').value;
  const sexo     = document.getElementById('aqua-sexo').value;
  const piscina  = document.getElementById('aqua-piscina').value;
  const marcaStr = document.getElementById('aqua-marca').value;
  const errorEl  = document.getElementById('aqua-error');

  if (!prueba)   { errorEl.textContent = 'Selecciona una prueba.'; return; }
  if (!marcaStr) { errorEl.textContent = 'Introduce tu marca.'; return; }

  const marca = parseTime(marcaStr);
  if (isNaN(marca) || marca <= 0) { errorEl.textContent = 'Formato incorrecto. Usa mm:ss.cc o ss.cc'; return; }

  const clave    = `${prueba}_${sexo}_${piscina}`;
  const finaTime = FINA_TIMES[clave];
  if (!finaTime) { errorEl.textContent = 'No hay datos FINA para esta combinación (prueba/sexo/piscina).'; return; }

  const puntos = Math.round(1000 * Math.pow(finaTime / marca, 3));

  errorEl.textContent = '';
  document.getElementById('aqua-puntos').innerHTML = `${puntos}<span>pts</span>`;
  document.getElementById('aqua-fina').textContent       = formatTime(finaTime);
  document.getElementById('aqua-marca-show').textContent = formatTime(marca);

  [300, 400, 500, 600].forEach(llindar => {
    const el    = document.getElementById(`nivel-${llindar}`);
    const valEl = document.getElementById(`nivel-${llindar}-val`);
    if (!el) return;
    if (puntos >= llindar) {
      el.className = 'calc-badge met';
      valEl.textContent = '✓';
    } else {
      el.className = 'calc-badge not';
      valEl.textContent = '✗';
    }
  });

  document.getElementById('aqua-result').classList.add('visible');
};

window.limpiarAQUA = function() {
  document.getElementById('aqua-prueba').value = '';
  document.getElementById('aqua-marca').value  = '';
  document.getElementById('aqua-error').textContent = '';
  document.getElementById('aqua-result').classList.remove('visible');
};

// ============================================================
// Calculadora Mínimas RFEN
// ============================================================
window.actualizarEdatSelect = function() {
  const cat      = document.getElementById('min-categoria').value;
  const edatGrup = document.getElementById('min-edat-group');
  const edatSel  = document.getElementById('min-edat');
  const rang     = EDATS_CAT[cat];

  if (!rang) {
    // Sub-20 / Absoluto — amagar selector edat
    edatGrup.style.display = 'none';
    edatSel.value = '';
    return;
  }

  edatGrup.style.display = '';
  const prevVal = edatSel.value;
  edatSel.innerHTML = '<option value="">— Seleccionar —</option>';
  for (let e = rang.min; e <= rang.max; e++) {
    const opt = document.createElement('option');
    opt.value = String(e);
    opt.textContent = e + ' años';
    if (String(e) === prevVal) opt.selected = true;
    edatSel.appendChild(opt);
  }
};

// Inicializar al cargar
document.addEventListener('DOMContentLoaded', function() {
  actualizarEdatSelect();
});

window.calcularMinimas = function() {
  const prueba    = document.getElementById('min-prueba').value;
  const sexo      = document.getElementById('min-sexo').value;
  const piscina   = document.getElementById('min-piscina').value;
  const categoria = document.getElementById('min-categoria').value;
  const edatStr   = document.getElementById('min-edat').value;
  const marcaStr  = document.getElementById('min-marca').value;
  const errorEl   = document.getElementById('min-error');

  if (!prueba)   { errorEl.textContent = 'Selecciona una prueba.'; return; }
  if (!marcaStr) { errorEl.textContent = 'Introduce tu marca.'; return; }

  const marca = parseTime(marcaStr);
  if (isNaN(marca) || marca <= 0) { errorEl.textContent = 'Formato incorrecto. Usa mm:ss.cc o ss.cc'; return; }

  const datos = MINIMES[prueba];
  if (!datos || !datos[piscina] || !datos[piscina][sexo]) { errorEl.textContent = 'No hay datos para esta prueba.'; return; }

  const catDatos = datos[piscina][sexo][categoria];

  // Para Alevín/Infantil/Junior necesitamos la edad concreta
  let minTime;
  const isAgeCat = (categoria in EDATS_CAT);
  if (isAgeCat) {
    const edat = parseInt(edatStr, 10);
    if (isNaN(edat) || edat < 1) {
      errorEl.textContent = 'Selecciona tu edad.'; return;
    }
    if (typeof catDatos !== 'object' || catDatos === null) {
      errorEl.textContent = 'No hay datos para esta categoría.'; return;
    }
    minTime = catDatos[String(edat)];
  } else {
    // Sub-20 / Absoluto — valor directo
    minTime = catDatos;
  }

  if (minTime === null || minTime === undefined) {
    errorEl.textContent = 'No existe mínima RFEN para esta combinación.'; return;
  }

  const percent = (minTime / marca * 100).toFixed(1);
  const diff    = marca - minTime;

  const percentEl = document.getElementById('min-percent');
  percentEl.textContent = `${percent}%`;
  percentEl.className   = 'calc-percent-big ' + (percent >= 100 ? 'good' : percent >= 95 ? 'warn' : 'bad');

  document.getElementById('min-req').textContent        = formatTime(minTime);
  document.getElementById('min-marca-show').textContent = formatTime(marca);

  const diffEl = document.getElementById('min-diff');
  if (diff > 0) {
    diffEl.textContent = `+${diff.toFixed(2)}s por encima de la mínima`;
    diffEl.style.color = '#dc2626';
  } else {
    diffEl.textContent = `${Math.abs(diff).toFixed(2)}s por debajo de la mínima ✓`;
    diffEl.style.color = '#16a34a';
  }

  const catNoms = { alevin:'Alevín', infantil:'Infantil', junior:'Junior', sub20:'Sub-20', absoluto:'Absoluto' };
  const edatLabel = isAgeCat ? ` (${edatStr} años)` : '';
  const msgEl = document.getElementById('min-msg');
  if (percent >= 100) {
    msgEl.style.background = '#f0fdf4';
    msgEl.style.color      = '#16a34a';
    msgEl.textContent      = `✓ Superas la mínima ${catNoms[categoria]}${edatLabel}. ¡Enhorabuena!`;
  } else {
    msgEl.style.background = '#fff5f5';
    msgEl.style.color      = '#dc2626';
    msgEl.textContent      = `✗ Te faltan ${Math.abs(diff).toFixed(2)}s para la mínima ${catNoms[categoria]}${edatLabel}.`;
  }

  errorEl.textContent = '';
  document.getElementById('min-result').classList.add('visible');
};

window.limpiarMinimas = function() {
  document.getElementById('min-prueba').value  = '';
  document.getElementById('min-edat').value    = '';
  document.getElementById('min-marca').value   = '';
  document.getElementById('min-error').textContent = '';
  document.getElementById('min-result').classList.remove('visible');
};

// ============================================================
// Calculadora de Parciales
// ============================================================
window.calcularParciales = function() {
  const pruebaVal  = document.getElementById('par-prueba').value;
  const tiempoStr  = document.getElementById('par-tiempo').value;
  const estrategia = document.getElementById('par-estrategia').value;
  const errorEl    = document.getElementById('par-error');

  if (!tiempoStr) { errorEl.textContent = 'Introduce tu tiempo objetivo.'; return; }
  const totalTime = parseTime(tiempoStr);
  if (isNaN(totalTime) || totalTime <= 0) { errorEl.textContent = 'Formato incorrecto. Usa mm:ss.cc o ss.cc'; return; }

  const distancia  = parseInt(pruebaVal.split('_')[0]);
  const piscina    = parseInt(pruebaVal.split('_')[1]);
  const numTramos  = distancia / piscina;

  if (!isFinite(numTramos) || numTramos <= 0) { errorEl.textContent = 'Error en la prueba seleccionada.'; return; }

  const factors  = generarFactors(numTramos, estrategia);
  const sumFactors = factors.reduce((a, b) => a + b, 0);
  const splits   = factors.map(f => f * totalTime / sumFactors);
  const tempsBase = totalTime / numTramos;

  const tbody = document.getElementById('par-splits');
  tbody.innerHTML = '';
  let acum = 0;
  splits.forEach((s, i) => {
    acum += s;
    const pct  = ((s - tempsBase) / tempsBase * 100);
    const diff = s - tempsBase;
    const tr   = document.createElement('tr');
    if (pct < -1) tr.className = 'fast';
    else if (pct > 1) tr.className = 'slow';
    const signe = diff >= 0 ? '+' : '';
    tr.innerHTML = `
      <td>${i + 1}</td>
      <td>${(i + 1) * piscina}m</td>
      <td class="accent">${formatTime(s)}</td>
      <td>${formatTime(acum)}</td>
      <td style="font-size:12px;color:${pct < -0.5 ? '#16a34a' : pct > 0.5 ? '#f59e0b' : '#888'}">
        ${signe}${pct.toFixed(1)}%
      </td>
    `;
    tbody.appendChild(tr);
  });

  const legendEl = document.getElementById('par-legend');
  const noteEl   = document.getElementById('par-note');
  if (estrategia === 'negativo') {
    legendEl.innerHTML = '<span style="color:#16a34a">■</span> Tramos rápidos &nbsp;<span style="color:#f59e0b">■</span> Tramos lentos';
    noteEl.textContent = 'Split negativo: arranque prudente, aceleración progresiva hacia el final. Recomendado para 200m y distancias largas.';
  } else if (estrategia === 'positivo') {
    legendEl.innerHTML = '<span style="color:#16a34a">■</span> Tramos rápidos &nbsp;<span style="color:#f59e0b">■</span> Tramos lentos';
    noteEl.textContent = 'Split positivo: arranque rápido con fatiga progresiva. Habitual en pruebas cortas de velocidad.';
  } else {
    legendEl.textContent = '';
    noteEl.textContent = 'Ritmo uniforme: todos los tramos al mismo tiempo. Útil como referencia base.';
  }

  errorEl.textContent = '';
  document.getElementById('par-result').classList.add('visible');
};

function generarFactors(n, estrategia) {
  if (estrategia === 'uniforme') return new Array(n).fill(1.0);

  const factors = [];
  for (let i = 0; i < n; i++) {
    const t = i / Math.max(n - 1, 1);
    if (estrategia === 'negativo') {
      // Inicio ligeramente más lento, final rápido
      factors.push(1.0 + 0.06 * (0.5 - t));
    } else {
      // Positivo: inicio rápido, final lento
      factors.push(1.0 + 0.06 * (t - 0.5));
    }
  }
  // Primer tramo: impulso de salida (-1.5%)
  if (n > 1) factors[0] *= 0.985;
  return factors;
}
