/**
 * Editor de coberturas en chips — compartido entre plantillas.php (crear/
 * editar una plantilla oficial) y armador.php (armar una combinación ad-hoc,
 * ADR-007 / docs/02.14). Espera un <select id="cve_paquete"> y un
 * <div id="coberturas"> ya en la página (ver
 * app/vistas/parciales/editor_coberturas.php para el HTML inicial).
 */
const EditorCoberturas = (function () {
  const $  = (s) => document.querySelector(s);
  const el = (t, p = {}) => Object.assign(document.createElement(t), p);

  // Selector real si hay menú de valores (cat_cobertura_valores); texto libre
  // sólo si esa cobertura específica no tiene ninguno cargado (no debería pasar
  // hoy: importar_valores_coberturas.php cubre las 29 de cat_coberturas).
  function campoValor(nombre, etiqueta, valores, valorActual, placeholder) {
    const w = el('label', { className: 'campo-valor' });
    w.appendChild(document.createTextNode(etiqueta));
    if (!valores || !valores.length) {
      w.appendChild(el('input', { name: nombre, value: valorActual || '', placeholder: placeholder || '' }));
      w.appendChild(el('small', { className: 'ayuda', textContent: 'sin menú cargado' }));
      return w;
    }
    const sel = el('select', { name: nombre });
    sel.appendChild(el('option', { value: '', textContent: '—' }));
    for (const v of valores) sel.appendChild(el('option', { value: v, textContent: v, selected: v === valorActual }));
    w.appendChild(sel);
    return w;
  }

  function filaCobertura(c) {
    const w = el('div', { className: 'fila-cobertura' });
    const chip = el('label', { className: 'chip' });
    const i = el('input', { type: 'checkbox', name: 'coberturas[]', value: c.cve_cobertura });
    if (c.grupo_excl) { i.dataset.excl = c.grupo_excl; }
    chip.append(
      i,
      el('span', { textContent: c.nombre }),
      el('small', { className: 'marca-estado' + (c.tipo === 'BASICA' ? ' ok' : ''), textContent: c.tipo })
    );
    w.append(
      chip,
      campoValor('suma_' + c.cve_cobertura, 'Suma asegurada', c.valores_suma, c.sa_valor, c.sa_unidad),
      campoValor('ded_' + c.cve_cobertura, 'Deducible', c.valores_deducible, c.ded_valor, '')
    );
    return w;
  }

  // Bloquea marcar dos coberturas del mismo grupo excluyente a la vez, con
  // mensaje claro — ADR-007 punto 6 / docs/02.13 (Robo Parcial / Robo Parcial
  // Plus). GNP rechaza la cotización completa si llegan juntas
  // (cat_coberturas_excluyentes) — esto avisa antes de someterlo, sin
  // esperar el rechazo de GNP.
  function activarExclusion() {
    document.querySelectorAll('#coberturas input[data-excl]').forEach((i) => {
      i.addEventListener('change', () => {
        if (!i.checked) return;
        const enElMismoGrupo = [...document.querySelectorAll('#coberturas input[data-excl="' + i.dataset.excl + '"]')]
          .filter((o) => o !== i && o.checked);
        if (enElMismoGrupo.length) {
          enElMismoGrupo.forEach((o) => { o.checked = false; });
          alert('"' + i.closest('.chip').querySelector('span').textContent + '" es excluyente con lo que acabas de desmarcar '
              + '(grupo "' + i.dataset.excl + '"). GNP no permite pedir las dos juntas — sólo se dejó la que acabas de marcar.');
        }
      });
    });
  }

  // Recarga el editor completo desde la API cuando cambia el paquete base.
  // No conserva marcas previas a propósito: cambiar de paquete cambia el
  // menú entero de coberturas disponibles, así que lo seleccionado ya no
  // aplica necesariamente.
  async function cargar(apiUrl) {
    const cve = $('#cve_paquete').value;
    const cont = $('#coberturas');
    cont.innerHTML = '';
    if (!cve) {
      cont.appendChild(el('p', { className: 'ayuda', textContent: 'Elige un paquete base para ver sus coberturas.' }));
      return;
    }
    const u = new URL(apiUrl, location.href);
    u.searchParams.set('cve_paquete', cve);
    const r = await fetch(u, { headers: { 'Accept': 'application/json' } });
    const datos = r.ok ? (await r.json()).datos || [] : [];
    if (!datos.length) {
      cont.appendChild(el('p', { className: 'ayuda', textContent: 'Ese paquete no tiene coberturas cargadas en cat_coberturas.' }));
      return;
    }
    for (const c of datos) cont.appendChild(filaCobertura(c));
    activarExclusion();
  }

  return { campoValor, filaCobertura, activarExclusion, cargar };
})();
