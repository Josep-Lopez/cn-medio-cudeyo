// Editor de encuestas estilo Google Forms (admin/comunicaciones).
// El estado vive en `model`; al enviar el formulario se serializa a JSON en un
// input oculto (preguntas_json). Las ediciones de texto actualizan el modelo sin
// re-renderizar (para no perder el foco); los cambios estructurales sí re-renderizan.

(function () {
  const TIPOS = {
    opciones:    { label: 'Opción única',    icon: 'bi-record-circle' },
    casillas:    { label: 'Casillas',        icon: 'bi-check-square' },
    desplegable: { label: 'Desplegable',     icon: 'bi-caret-down-square' },
    escala:      { label: 'Escala lineal',   icon: 'bi-sliders' },
    texto_corto: { label: 'Respuesta corta', icon: 'bi-text-left' },
    texto_libre: { label: 'Párrafo',         icon: 'bi-justify-left' },
  };
  const CON_OPCIONES = ['opciones', 'casillas', 'desplegable'];
  const CON_OTRO = ['opciones', 'casillas'];

  let model = [];
  let listEl = null;
  let activeIdx = 0;

  // ── Utilidades DOM ──────────────────────────────────────────────────────────
  function h(tag, attrs, children) {
    const el = document.createElement(tag);
    for (const k in (attrs || {})) {
      const v = attrs[k];
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k in el && k !== 'list') el[k] = v;
      else el.setAttribute(k, v);
    }
    (children || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }

  function icon(name) { return h('i', { class: 'bi ' + name }); }

  function autoGrow(el) {
    if (!el.offsetParent) return; // oculto: se recalcula al mostrarse (refresh)
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
  }

  function iconBtn(name, title, onclick, disabled) {
    return h('button', { type: 'button', class: 'gf-icon-btn', title: title, 'aria-label': title, disabled: disabled, onclick: onclick }, [icon(name)]);
  }

  // ── Modelo ──────────────────────────────────────────────────────────────────
  function nuevaPregunta(tipo) {
    return {
      texto: '', descripcion: '', tipo: tipo || 'opciones', obligatoria: 1,
      escala_min: 1, escala_max: 5, escala_label_min: '', escala_label_max: '',
      opciones: [{ texto: '', es_libre: 0 }],
      _desc: false,
    };
  }

  function normalizar(pq) {
    const q = Object.assign(nuevaPregunta(), pq || {});
    q.opciones = (pq && Array.isArray(pq.opciones) ? pq.opciones : q.opciones).map(function (o) {
      return { texto: String(o.texto || ''), es_libre: parseInt(o.es_libre || 0, 10) === 1 ? 1 : 0 };
    });
    if (!TIPOS[q.tipo]) q.tipo = 'opciones';
    q.obligatoria = parseInt(q.obligatoria, 10) === 1 ? 1 : 0;
    q._desc = !!q.descripcion;
    return q;
  }

  function opcionesNormales(q) { return q.opciones.filter(function (o) { return !o.es_libre; }); }
  function tieneOtro(q) { return q.opciones.some(function (o) { return o.es_libre; }); }

  // ── Render ──────────────────────────────────────────────────────────────────
  function render(focus) {
    listEl.innerHTML = '';
    model.forEach(function (q, i) { listEl.appendChild(renderPregunta(q, i)); });
    listEl.querySelectorAll('textarea').forEach(autoGrow);
    if (focus) {
      const card = listEl.children[focus.q];
      if (!card) return;
      let target = null;
      if (focus.opt !== undefined) target = card.querySelectorAll('.gf-opt input')[focus.opt];
      else target = card.querySelector('.gf-q-title');
      if (target) { target.focus(); if (target.select && focus.select) target.select(); }
      card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }

  function renderPregunta(q, i) {
    const card = h('div', { class: 'gf-q' + (i === activeIdx ? ' is-active' : '') });
    card.addEventListener('focusin', function () {
      activeIdx = i;
      listEl.querySelectorAll('.gf-q.is-active').forEach(function (c) { c.classList.remove('is-active'); });
      card.classList.add('is-active');
    });

    // Cabecera: enunciado + tipo
    const title = h('textarea', {
      class: 'gf-q-title', rows: 1, placeholder: 'Pregunta ' + (i + 1), value: q.texto,
      oninput: function () { q.texto = this.value; autoGrow(this); },
    });
    const tipoSel = h('select', { class: 'form-control gf-q-type', 'aria-label': 'Tipo de pregunta', onchange: function () { cambiarTipo(i, this.value); } },
      Object.keys(TIPOS).map(function (t) { return h('option', { value: t, text: TIPOS[t].label, selected: q.tipo === t }); }));
    card.appendChild(h('div', { class: 'gf-q-top' }, [title, tipoSel]));

    if (q._desc) {
      card.appendChild(h('textarea', {
        class: 'gf-q-desc', rows: 1, placeholder: 'Descripción o instrucciones (opcional)', value: q.descripcion,
        oninput: function () { q.descripcion = this.value; autoGrow(this); },
      }));
    }

    card.appendChild(renderCuerpo(q, i));

    // Pie: mover, descripción, duplicar, eliminar | obligatoria
    const sw = h('input', { type: 'checkbox', checked: q.obligatoria === 1, onchange: function () { q.obligatoria = this.checked ? 1 : 0; } });
    card.appendChild(h('div', { class: 'gf-q-footer' }, [
      iconBtn('bi-arrow-up', 'Subir', function () { mover(i, -1); }, i === 0),
      iconBtn('bi-arrow-down', 'Bajar', function () { mover(i, 1); }, i === model.length - 1),
      h('span', { class: 'gf-sep' }),
      iconBtn('bi-card-text', q._desc ? 'Quitar descripción' : 'Añadir descripción', function () {
        q._desc = !q._desc; if (!q._desc) q.descripcion = ''; render();
      }),
      iconBtn('bi-copy', 'Duplicar', function () { duplicar(i); }),
      iconBtn('bi-trash3', 'Eliminar pregunta', function () { eliminar(i); }, model.length === 1),
      h('span', { class: 'gf-sep' }),
      h('label', { class: 'gf-switch' }, [h('span', { text: 'Obligatoria' }), sw, h('span', { class: 'gf-switch-track' })]),
    ]));
    return card;
  }

  function renderCuerpo(q, i) {
    const body = h('div', { class: 'gf-q-body' });

    if (CON_OPCIONES.indexOf(q.tipo) !== -1) {
      let n = 0;
      q.opciones.forEach(function (op, oi) {
        if (op.es_libre) {
          body.appendChild(h('div', { class: 'gf-opt gf-opt-otro' }, [
            marcador(q.tipo, null),
            h('span', { class: 'gf-otro-label', text: 'Otro…' }),
            h('span', { class: 'gf-otro-hint', text: 'el socio escribe su respuesta' }),
            iconBtn('bi-x-lg', 'Quitar «Otro»', function () { q.opciones.splice(oi, 1); render(); }),
          ]));
          return;
        }
        n++;
        body.appendChild(h('div', { class: 'gf-opt' }, [
          marcador(q.tipo, n),
          h('input', {
            type: 'text', value: op.texto, placeholder: 'Opción ' + n, maxLength: 1000, 'aria-label': 'Opción ' + n,
            oninput: function () { op.texto = this.value; },
            onkeydown: function (ev) { teclaOpcion(ev, q, i, oi); },
            onpaste: function (ev) { pegarOpciones(ev, q, i, oi); },
          }),
          iconBtn('bi-x-lg', 'Quitar opción', function () { q.opciones.splice(oi, 1); render(); }, opcionesNormales(q).length <= 1),
        ]));
      });

      const acciones = h('div', { class: 'gf-opt gf-opt-add' }, [
        marcador(q.tipo, n + 1),
        h('button', { type: 'button', class: 'gf-link', text: 'Añadir opción', onclick: function () { insertarOpcion(q, i, null); } }),
      ]);
      if (CON_OTRO.indexOf(q.tipo) !== -1 && !tieneOtro(q)) {
        acciones.appendChild(h('span', { class: 'text-muted text-sm', text: 'o' }));
        acciones.appendChild(h('button', { type: 'button', class: 'gf-link', text: 'añadir «Otro»', onclick: function () { q.opciones.push({ texto: 'Otro', es_libre: 1 }); render(); } }));
      }
      body.appendChild(acciones);
      body.appendChild(h('div', { class: 'gf-hint', text: 'Enter añade otra opción · pegar varias líneas crea una opción por línea' }));
    } else if (q.tipo === 'escala') {
      const minSel = h('select', { class: 'form-control', onchange: function () { q.escala_min = parseInt(this.value, 10); } },
        [0, 1].map(function (v) { return h('option', { value: v, text: v, selected: q.escala_min === v }); }));
      const maxSel = h('select', { class: 'form-control', onchange: function () { q.escala_max = parseInt(this.value, 10); } },
        [2, 3, 4, 5, 6, 7, 8, 9, 10].map(function (v) { return h('option', { value: v, text: v, selected: q.escala_max === v }); }));
      body.appendChild(h('div', { class: 'gf-scale-range' }, [minSel, h('span', { text: 'a' }), maxSel]));
      ['min', 'max'].forEach(function (k) {
        const key = 'escala_label_' + k;
        body.appendChild(h('div', { class: 'gf-scale-label' }, [
          h('span', { class: 'gf-scale-num', text: k === 'min' ? 'Mín.' : 'Máx.' }),
          h('input', { type: 'text', value: q[key], maxLength: 100, placeholder: 'Etiqueta (opcional), p. ej. ' + (k === 'min' ? '«Nada de acuerdo»' : '«Muy de acuerdo»'),
            oninput: function () { q[key] = this.value; } }),
        ]));
      });
    } else {
      body.appendChild(h('div', { class: 'gf-fake-answer' + (q.tipo === 'texto_libre' ? ' is-long' : ''),
        text: q.tipo === 'texto_corto' ? 'Texto de respuesta corta' : 'Texto de respuesta larga' }));
    }
    return body;
  }

  function marcador(tipo, n) {
    if (tipo === 'desplegable') return h('span', { class: 'gf-marker gf-marker-num', text: n ? n + '.' : '' });
    return h('span', { class: 'gf-marker ' + (tipo === 'casillas' ? 'gf-marker-check' : 'gf-marker-radio') });
  }

  // ── Acciones ────────────────────────────────────────────────────────────────
  function insertarOpcion(q, i, despuesDe) {
    // Las opciones nuevas nunca van detrás de «Otro»
    let pos = despuesDe === null ? q.opciones.length : despuesDe + 1;
    const idxOtro = q.opciones.findIndex(function (o) { return o.es_libre; });
    if (idxOtro !== -1 && pos > idxOtro) pos = idxOtro;
    q.opciones.splice(pos, 0, { texto: '', es_libre: 0 });
    render({ q: i, opt: pos });
  }

  function teclaOpcion(ev, q, i, oi) {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      insertarOpcion(q, i, oi);
    } else if (ev.key === 'Backspace' && ev.target.value === '' && opcionesNormales(q).length > 1) {
      ev.preventDefault();
      q.opciones.splice(oi, 1);
      render({ q: i, opt: Math.max(0, oi - 1) });
    }
  }

  function pegarOpciones(ev, q, i, oi) {
    const txt = (ev.clipboardData || window.clipboardData).getData('text');
    const lineas = txt.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
    if (lineas.length < 2) return;
    ev.preventDefault();
    const actual = q.opciones[oi];
    const nuevas = lineas.map(function (l) { return { texto: l, es_libre: 0 }; });
    if (actual.texto.trim() === '') q.opciones.splice(oi, 1, ...nuevas);
    else q.opciones.splice(oi + 1, 0, ...nuevas);
    // Quitar opciones vacías sobrantes (las 2 iniciales, por ejemplo)
    q.opciones = q.opciones.filter(function (o) { return o.es_libre || o.texto.trim() !== ''; });
    render({ q: i, opt: Math.min(opcionesNormales(q).length - 1, oi + nuevas.length - 1) });
  }

  function cambiarTipo(i, tipo) {
    const q = model[i];
    q.tipo = tipo;
    if (CON_OPCIONES.indexOf(tipo) !== -1) {
      if (CON_OTRO.indexOf(tipo) === -1) q.opciones = opcionesNormales(q);
      if (!opcionesNormales(q).length) q.opciones.splice(opcionesNormales(q).length, 0, { texto: '', es_libre: 0 });
    }
    render();
  }

  function mover(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= model.length) return;
    const tmp = model[i]; model[i] = model[j]; model[j] = tmp;
    activeIdx = j;
    render({ q: j });
  }

  function duplicar(i) {
    const copia = JSON.parse(JSON.stringify(model[i]));
    model.splice(i + 1, 0, copia);
    activeIdx = i + 1;
    render({ q: i + 1, select: true });
  }

  function eliminar(i) {
    if (model.length === 1) return;
    model.splice(i, 1);
    activeIdx = Math.min(i, model.length - 1);
    render();
  }

  function anadir() {
    const tipo = model[activeIdx] ? model[activeIdx].tipo : 'opciones';
    model.splice(activeIdx + 1, 0, nuevaPregunta(tipo));
    activeIdx = activeIdx + 1;
    render({ q: activeIdx });
  }

  function serializar() {
    return JSON.stringify(model.map(function (q) {
      const o = Object.assign({}, q);
      delete o._desc;
      if (CON_OPCIONES.indexOf(q.tipo) === -1) o.opciones = [];
      return o;
    }));
  }

  // ── Vista previa (lo que verá el socio) ─────────────────────────────────────
  function preview(titulo, contenido) {
    const cont = h('div', { class: 'gf-form' });
    cont.appendChild(h('div', { class: 'gf-form-header' }, [
      h('h2', { text: titulo || 'Sin título' }),
      contenido ? h('div', { class: 'gf-form-desc', text: contenido }) : null,
      model.some(function (q) { return q.obligatoria; }) ? h('div', { class: 'gf-req-note' }, [h('span', { class: 'gf-req', text: '*' }), ' Obligatorio']) : null,
    ]));
    model.forEach(function (q, i) { cont.appendChild(previewPregunta(q, i)); });
    return cont;
  }

  function previewPregunta(q, i) {
    const name = 'prev_' + i;
    const card = h('div', { class: 'gf-answer' }, [
      h('div', { class: 'gf-answer-title' }, [q.texto || '(Pregunta sin texto)', q.obligatoria ? h('span', { class: 'gf-req', text: ' *' }) : null]),
      q.descripcion ? h('div', { class: 'gf-answer-desc', text: q.descripcion }) : null,
    ]);
    if (q.tipo === 'opciones' || q.tipo === 'casillas') {
      q.opciones.forEach(function (op) {
        if (!op.es_libre && !op.texto.trim()) return;
        card.appendChild(h('label', { class: 'gf-choice' }, [
          h('input', { type: q.tipo === 'casillas' ? 'checkbox' : 'radio', name: name }),
          h('span', { text: op.es_libre ? 'Otro:' : op.texto }),
          op.es_libre ? h('input', { type: 'text', class: 'gf-otro-input' }) : null,
        ]));
      });
    } else if (q.tipo === 'desplegable') {
      card.appendChild(h('select', { class: 'form-control gf-select' }, [h('option', { text: 'Elige' })].concat(
        opcionesNormales(q).filter(function (o) { return o.texto.trim(); }).map(function (o) { return h('option', { text: o.texto }); }))));
    } else if (q.tipo === 'escala') {
      const fila = h('div', { class: 'gf-scale' }, [h('span', { class: 'gf-scale-end', text: q.escala_label_min || '' })]);
      for (let v = q.escala_min; v <= q.escala_max; v++) {
        fila.appendChild(h('label', { class: 'gf-scale-opt' }, [h('span', { text: v }), h('input', { type: 'radio', name: name })]));
      }
      fila.appendChild(h('span', { class: 'gf-scale-end', text: q.escala_label_max || '' }));
      card.appendChild(fila);
      card.appendChild(h('div', { class: 'gf-scale-legend' }, [h('span', { text: q.escala_label_min || '' }), h('span', { text: q.escala_label_max || '' })]));
    } else if (q.tipo === 'texto_corto') {
      card.appendChild(h('input', { type: 'text', class: 'form-control', placeholder: 'Tu respuesta' }));
    } else {
      card.appendChild(h('textarea', { class: 'form-control', rows: 3, placeholder: 'Tu respuesta' }));
    }
    return card;
  }

  // ── API pública ─────────────────────────────────────────────────────────────
  window.EncuestaBuilder = {
    init: function (opts) {
      listEl = opts.list;
      model = (opts.data || []).map(normalizar);
      render();
      opts.form.addEventListener('submit', function () {
        opts.input.value = serializar();
      });
    },
    refresh: function () { render(); },
    ensureOne: function () { if (!model.length) { model.push(nuevaPregunta()); render(); } },
    add: anadir,
    preview: preview,
    count: function () { return model.length; },
  };
})();
