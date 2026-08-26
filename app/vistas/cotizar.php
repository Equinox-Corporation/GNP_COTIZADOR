<?php declare(strict_types=1);
/** @var array $diag @var array $procedencias @var string $error @var array $previo */
$v = static fn (string $k, string $d = ''): string => h($previo[$k] ?? $d);
?>

<h1>Nueva cotización</h1>

<p class="ayuda">Los campos marcados con <span class="req">*</span> son obligatorios: sin ellos GNP no puede cotizar.</p>

<?php if (!CatalogoServicio::listoParaCotizar()): ?>
  <div class="aviso alerta">
    <strong>El catálogo todavía no está cargado.</strong>
    Hay <?= number_format($diag['vehiculos']) ?> vehículos y <?= number_format($diag['paquetes']) ?> paquetes.
    Antes de cotizar hay que correr los scripts de carga — vienen explicados en el README.
  </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="aviso error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= h(url('cotizar')) ?>" id="frm" autocomplete="off">
<input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">

<section class="tarjeta">
  <h2>1 · El vehículo</h2>

  <div class="buscador">
    <label for="busca">Búsqueda rápida</label>
    <input type="search" id="busca" placeholder="Escribe el modelo: swift, civic, versa…">
    <p class="ayuda">Busca en el catálogo de GNP y llena los desplegables de abajo. También puedes elegirlos a mano.</p>
    <ul id="sugerencias" class="sugerencias" hidden></ul>
  </div>

  <div class="rejilla">
    <label>Tipo
      <select name="tipo_vehiculo" id="tipo_vehiculo">
        <?php foreach (CatalogoServicio::TIPOS_VEHICULO as $k => $n): ?>
          <option value="<?= h($k) ?>"<?= $v('tipo_vehiculo', 'AUT') === $k ? ' selected' : '' ?>><?= h($n) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Marca <span class="req">*</span>
      <select name="armadora" id="armadora" data-valor="<?= $v('armadora') ?>" required><option value="">…</option></select>
    </label>

    <label>Línea <span class="req">*</span>
      <select name="carroceria" id="carroceria" data-valor="<?= $v('carroceria') ?>" required><option value="">…</option></select>
    </label>

    <label>Año <span class="req">*</span>
      <select name="modelo" id="modelo" data-valor="<?= $v('modelo') ?>" required><option value="">…</option></select>
    </label>

    <label class="ancho">Versión <span class="req">*</span>
      <select name="version" id="version" data-valor="<?= $v('version') ?>" required><option value="">…</option></select>
      <span class="ayuda" id="clave_veh"></span>
    </label>

    <label>Procedencia
      <select name="procedencia" id="procedencia">
        <?php foreach ($procedencias as $p): ?>
          <option value="<?= h($p['procedencia']) ?>"
                  <?= $v('procedencia', 'Residentes') === $p['procedencia'] ? ' selected' : '' ?>
                  <?= (int) $p['verificado'] === 0 ? ' data-sinverificar="1"' : '' ?>>
            <?= h($p['procedencia']) ?><?= (int) $p['verificado'] === 0 ? ' (sin verificar)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="ayuda">Sólo Residentes está confirmado con GNP.</span>
    </label>
  </div>
</section>

<!-- ─────────────────────────────────────────────────────────────────────────
     El conductor va ANTES que el contratante, a propósito.
     Es el que fija el precio: se captura primero, y de ahí se copia hacia el
     titular cuando es la misma persona. Al revés se corre el riesgo de que el
     vendedor arrastre los datos del titular a la tarifa, que es justo el error
     que esta pantalla trata de evitar.
     ───────────────────────────────────────────────────────────────────────── -->
<!-- ─────────────────────────────────────────────────────────────────────────
     SOLICITANTE — una sola sección en pantalla, dos entidades por dentro.
     
     GNP pide dos bloques en el XML, CONTRATANTE y CONDUCTOR, y los dos repiten
     edad y código postal. Para cotizar esa distinción no aporta nada: el precio
     lo fija el conductor y el contratante sólo existe para el documento. Pedir
     dos veces la misma edad y el mismo CP invita al error de capturar mal cuál
     es cuál, y ese error se paga con una prima equivocada.
     
     Así que en pantalla se pide UNA sola vez. Los campos que se ven son los del
     CONDUCTOR —son los que mandan— y el CONTRATANTE se llena solo con ellos.
     Esa copia se hace en el servidor (public/index.php, ruta "cotizar"), no
     aquí: el formulario no debe ser el que decida, porque un navegador se puede
     manipular. El XML que sale a GNP no cambió en nada.
     
     Cuando llegue la pantalla de emisión —donde el titular sí puede ser otra
     persona, con otro domicilio fiscal— aquí se vuelven a separar los campos y
     se quita la copia del servidor. Está anotado en ese archivo.
     ───────────────────────────────────────────────────────────────────────── -->
<section class="tarjeta destacada">
  <h2>2 · Solicitante</h2>
  <p class="nota">
    <strong>La edad y el código postal son los que fijan el precio.</strong> GNP tarifica con los datos
    de quien maneja el vehículo. Si el titular de la póliza va a ser otra persona, eso se define
    al momento de emitir; para cotizar no cambia nada.
  </p>
  <!-- Dos grupos, separados a propósito: uno es lo que TARIFICA (el
       conductor), el otro es lo que sólo va en el documento (el
       contratante — el RFC es suyo, no del conductor). No se mezclan para
       que sea obvio de un vistazo qué campos fijan el precio. -->
  <div class="rejilla">
    <label>Tipo de persona
      <select name="tipo_persona" id="tipo_persona">
        <option value="F"<?= $v('tipo_persona', 'F') === 'F' ? ' selected' : '' ?>>Física</option>
        <option value="M"<?= $v('tipo_persona') === 'M' ? ' selected' : '' ?>>Moral</option>
      </select>
    </label>
  </div>

  <p class="subgrupo">Quién maneja el vehículo — fija el precio</p>
  <div class="rejilla">
    <label>Sexo
      <select name="conductor_sexo">
        <option value="M"<?= $v('conductor_sexo', 'M') === 'M' ? ' selected' : '' ?>>Masculino</option>
        <option value="F"<?= $v('conductor_sexo') === 'F' ? ' selected' : '' ?>>Femenino</option>
      </select>
    </label>
    <label>Fecha de nacimiento
      <input type="date" name="conductor_nacimiento_fecha" id="nac">
      <input type="hidden" name="conductor_nacimiento" id="nac_h" value="<?= $v('conductor_nacimiento') ?>">
      <span class="ayuda">Opcional: al capturarla se calcula la edad.</span>
    </label>
    <label>Edad <span class="req">*</span>
      <?php /* min="1", no min="18": la mayoría de edad se avisa, no se bloquea.
               Hay excepciones legales y no le toca al formulario decidirlas.
               Se pide igual en Persona Moral: GNP tarifica y pide el XML con
               los datos de quien maneja el vehículo, sin importar quién sea
               el titular de la póliza. */ ?>
      <input type="number" name="conductor_edad" id="edad" value="<?= $v('conductor_edad') ?>" min="1" max="99" required>
      <span class="ayuda" id="pista_edad"></span>
      <span class="ayuda solo-moral" id="nota_edad_moral">De quien maneja el vehículo, no de la empresa.</span>
    </label>
    <label>Código postal <span class="req">*</span>
      <input name="conductor_cp" value="<?= $v('conductor_cp') ?>" maxlength="5" inputmode="numeric" required>
    </label>
  </div>

  <p class="subgrupo">Para el documento — no afecta el precio</p>
  <div class="rejilla">
    <label id="etiqueta_nombre">Nombre(s)
      <input name="nombres" value="<?= $v('nombres') ?>" maxlength="40">
    </label>
    <label class="solo-fisica">Apellido paterno
      <input name="apellido_paterno" value="<?= $v('apellido_paterno') ?>" maxlength="40">
    </label>
    <label class="solo-fisica">Apellido materno
      <input name="apellido_materno" value="<?= $v('apellido_materno') ?>" maxlength="40">
    </label>
    <label>RFC del contratante
      <input name="contratante_rfc" value="<?= $v('contratante_rfc') ?>" maxlength="13" style="text-transform:uppercase">
      <span class="ayuda">Opcional. Del titular de la póliza, no de quien maneja.</span>
    </label>
    <label>Correo del cliente
      <input type="email" name="correo" value="<?= $v('correo') ?>" maxlength="80">
      <span class="ayuda">Sólo se guarda aquí. El PDF de GNP llega al buzón de Equinox.</span>
    </label>
  </div>
</section>

<section class="tarjeta">
  <h2>3 · Plan</h2>
  <p class="ayuda">
    Marca <span class="req">*</span> al menos un paquete. Todos los que marques se cotizan en
    <strong>una sola llamada</strong> a GNP y se muestran lado a lado.
  </p>
  <div id="paquetes" class="opciones"><p class="ayuda">Elige primero el vehículo y el tipo de persona.</p></div>

  <details id="det_opcionales">
    <summary>Coberturas opcionales</summary>
    <p class="ayuda">
      Sólo aparecen las que aplican a <em>todos</em> los paquetes marcados.
      Verifica siempre que el precio cambie: si no cambia, GNP no las aplicó.
    </p>
    <div id="opcionales" class="opciones"></div>
  </details>

  <label class="linea">Forma de pago
    <select name="periodicidad">
      <option value="A">Anual</option>
      <option value="S">Semestral</option>
      <option value="T">Trimestral</option>
      <option value="M">Mensual</option>
    </select>
  </label>
</section>

<div class="acciones">
  <button type="submit" class="btn primario" id="enviar">Cotizar en GNP</button>
  <span class="ayuda">Cotizar no genera póliza.</span>
</div>

</form>

<script>
const API = <?= json_encode(url('api')) ?>;

const $  = (s) => document.querySelector(s);
const el = (t, p = {}) => Object.assign(document.createElement(t), p);

async function pedir(q, extra = {}) {
  const u = new URL(API, location.href);
  u.searchParams.set('q', q);
  u.searchParams.set('tipo', $('#tipo_vehiculo').value);
  for (const [k, v] of Object.entries(extra)) u.searchParams.set(k, v);
  const r = await fetch(u, { headers: { 'Accept': 'application/json' } });
  if (!r.ok) return [];
  return (await r.json()).datos || [];
}

function llenar(sel, filas, mapa, marcador) {
  const deseado = sel.dataset.valor || sel.value;
  sel.innerHTML = '';
  sel.appendChild(el('option', { value: '', textContent: marcador }));
  for (const f of filas) {
    const [valor, texto] = mapa(f);
    sel.appendChild(el('option', { value: valor, textContent: texto }));
  }
  if (deseado && [...sel.options].some(o => o.value === String(deseado))) sel.value = String(deseado);
  sel.dataset.valor = '';
}

async function cargarMarcas() {
  llenar($('#armadora'), await pedir('marcas'), f => [f.clave, f.nombre], 'Elige la marca');
  await cargarLineas();
}
async function cargarLineas() {
  const a = $('#armadora').value;
  llenar($('#carroceria'), a ? await pedir('lineas', { armadora: a }) : [], f => [f.clave, f.nombre], 'Elige la línea');
  await cargarAnios();
}
async function cargarAnios() {
  const a = $('#armadora').value, c = $('#carroceria').value;
  const datos = (a && c) ? await pedir('anios', { armadora: a, carroceria: c }) : [];
  llenar($('#modelo'), datos, f => [f, f], 'Elige el año');
  await cargarVersiones();
}
async function cargarVersiones() {
  const a = $('#armadora').value, c = $('#carroceria').value, m = $('#modelo').value;
  const datos = (a && c && m) ? await pedir('versiones', { armadora: a, carroceria: c, modelo: m }) : [];
  llenar($('#version'), datos, f => [f.clave, f.nombre], datos.length ? `Elige la versión (${datos.length})` : 'Elige la versión');
  mostrarClave(datos);
}
function mostrarClave(datos) {
  const v = $('#version').value;
  const f = datos.find(x => x.clave === v);
  $('#clave_veh').textContent = f ? `Clave GNP: ${f.clavemarca} · año ${$('#modelo').value}` : '';
}

async function cargarPaquetes() {
  const cont = $('#paquetes');
  const datos = await pedir('paquetes', {
    persona: $('#tipo_persona').value,
    procedencia: $('#procedencia').value,
  });
  cont.innerHTML = '';
  if (!datos.length) {
    cont.appendChild(el('p', { className: 'ayuda', textContent: 'GNP no ofrece paquetes para esta combinación.' }));
    return cargarOpcionales();
  }
  for (const p of datos) {
    const id = 'p_' + p.cve_paquete;
    const w  = el('label', { className: 'chip' });
    const i  = el('input', { type: 'checkbox', name: 'paquetes[]', value: p.cve_paquete, id });
    i.addEventListener('change', cargarOpcionales);
    w.append(i, el('span', { textContent: p.paquete }), el('small', { textContent: p.cve_paquete }));
    cont.appendChild(w);
  }
  cargarOpcionales();
}

async function cargarOpcionales() {
  const marcados = [...document.querySelectorAll('input[name="paquetes[]"]:checked')].map(i => i.value);
  const nombres  = [...document.querySelectorAll('input[name="paquetes[]"]:checked')]
                     .map(i => i.parentElement.querySelector('span').textContent);
  const cont = $('#opcionales');
  cont.innerHTML = '';
  if (!marcados.length) {
    cont.appendChild(el('p', { className: 'ayuda', textContent: 'Marca al menos un paquete.' }));
    return;
  }
  const datos = await pedir('opcionales', { paquetes: nombres.join(',') });
  if (!datos.length) {
    cont.appendChild(el('p', { className: 'ayuda', textContent: 'No hay opcionales comunes a los paquetes marcados.' }));
    return;
  }
  for (const c of datos) {
    const w = el('label', { className: 'chip' });
    const i = el('input', { type: 'checkbox', name: 'opcionales[]', value: c.cve_cobertura });
    if (c.grupo_excl) {
      // GNP rechaza la cotización completa si se piden dos del mismo grupo.
      // Se desmarcan solas para que el vendedor no descubra el choque hasta el final.
      i.dataset.excl = c.grupo_excl;
      w.title = 'Excluyente con otras de "' + c.grupo_excl + '": sólo se puede elegir una.';
      i.addEventListener('change', () => {
        if (!i.checked) return;
        document.querySelectorAll('input[name="opcionales[]"]').forEach(o => {
          if (o !== i && o.dataset.excl === i.dataset.excl) o.checked = false;
        });
      });
    }
    w.append(
      i,
      el('span', { textContent: c.nombre }),
      el('small', { textContent: c.sa_valor ? `${c.sa_valor} ${c.sa_unidad}` : '' })
    );
    if (c.grupo_excl) w.append(el('small', { className: 'excl', textContent: 'sólo una' }));
    cont.appendChild(w);
  }
}

// ── Buscador rápido ──────────────────────────────────────────────────────────
let temporizador;
$('#busca').addEventListener('input', (e) => {
  clearTimeout(temporizador);
  const t = e.target.value.trim();
  const lista = $('#sugerencias');
  if (t.length < 3) { lista.hidden = true; return; }
  temporizador = setTimeout(async () => {
    const datos = await pedir('buscar', { texto: t });
    lista.innerHTML = '';
    for (const d of datos.slice(0, 15)) {
      const li = el('li', { textContent: `${d.modelo} · ${d.carroceria_nombre} — ${d.version_nombre}` });
      li.addEventListener('click', async () => {
        $('#armadora').dataset.valor   = d.armadora;
        $('#carroceria').dataset.valor = d.carroceria;
        $('#modelo').dataset.valor     = d.modelo;
        $('#version').dataset.valor    = d.version;
        lista.hidden = true;
        $('#busca').value = '';
        await cargarMarcas();
      });
      lista.appendChild(li);
    }
    lista.hidden = datos.length === 0;
  }, 250);
});

// ── Física vs. Moral ─────────────────────────────────────────────────────────
// Una Persona Moral no tiene apellidos, sexo ni fecha de nacimiento: esos
// campos son sólo de Física. Si se dejan visibles y con algo capturado de
// antes, se mandarían como si fueran datos de la razón social.
//
// Edad y código postal NO se ocultan aunque parezcan "de persona física":
// son del CONDUCTOR, quien maneja el vehículo, y GNP los exige siempre para
// tarificar — sin importar si el titular de la póliza es una empresa. Por
// eso se dejan visibles y se aclara con una nota en vez de esconderlos.
function actualizarTipoPersona() {
  const moral = $('#tipo_persona').value === 'M';
  document.querySelectorAll('.solo-fisica').forEach((campo) => {
    campo.style.display = moral ? 'none' : '';
    // Sólo se limpian <input> (apellidos, fecha). El <select> de Sexo se deja
    // con su valor: aunque no se vea, GNP igual necesita un SEXO válido.
    if (moral) campo.querySelectorAll('input').forEach((c) => { c.value = ''; });
  });
  $('#nota_edad_moral').style.display = moral ? '' : 'none';
  $('#etiqueta_nombre').firstChild.textContent = moral ? 'Razón social ' : 'Nombre(s) ';
}

$('#tipo_vehiculo').addEventListener('change', async () => { await cargarMarcas(); cargarPaquetes(); });
$('#armadora').addEventListener('change', cargarLineas);
$('#carroceria').addEventListener('change', cargarAnios);
$('#modelo').addEventListener('change', cargarVersiones);
$('#version').addEventListener('change', cargarVersiones);
$('#tipo_persona').addEventListener('change', () => { actualizarTipoPersona(); cargarPaquetes(); });
$('#procedencia').addEventListener('change', cargarPaquetes);
actualizarTipoPersona();

// ── Fecha de nacimiento ↔ Edad ───────────────────────────────────────────────
// La fecha llena la edad, pero NO la bloquea. En una cotización muchas veces el
// cliente sólo dice "tengo 43" y no da la fecha completa: capturar la edad a
// mano tiene que seguir siendo posible.
//
// Si el vendedor captura las dos y no coinciden, se avisa en pantalla y manda
// la EDAD — que es lo que el vendedor escribió a propósito. El servidor
// reconstruye la fecha para que GNP nunca reciba un par contradictorio.
const anios = (iso) => {
  const f = new Date(iso + 'T00:00:00');
  if (isNaN(f)) return null;
  const h = new Date();
  let a = h.getFullYear() - f.getFullYear();
  const m = h.getMonth() - f.getMonth();
  if (m < 0 || (m === 0 && h.getDate() < f.getDate())) a--;
  return a;
};

// Dos cosas se avisan bajo el campo Edad, y ninguna bloquea:
//   1. Que la edad y la fecha no coincidan.
//   2. Que el solicitante sea menor de edad.
// La segunda pesa más, así que va primero.
const revisarEdad = () => {
  const pista = $('#pista_edad');
  const e     = parseInt($('#edad').value, 10);
  const calc  = $('#nac').value ? anios($('#nac').value) : null;
  const recados = [];
  let alertar = false;

  if (!isNaN(e) && e < 18) {
    recados.push('¡Advertencia! El Solicitante es menor de Edad.');
    alertar = true;
  }
  if (calc !== null && !isNaN(e)) {
    if (e === calc) {
      recados.push('Edad calculada con la fecha de nacimiento.');
    } else {
      recados.push(`La fecha capturada da ${calc} años; se cotiza con la edad que escribiste.`);
      alertar = true;
    }
  }

  pista.textContent = recados.join(' ');
  pista.style.color = alertar ? 'var(--alerta)' : '';
  pista.style.fontWeight = (!isNaN(e) && e < 18) ? '600' : '';
};

$('#nac').addEventListener('change', (e) => {
  $('#nac_h').value = e.target.value ? e.target.value.replaceAll('-', '') : '';
  const a = e.target.value ? anios(e.target.value) : null;
  if (a !== null && a >= 18 && a <= 99) $('#edad').value = a;
  revisarEdad();
});
$('#edad').addEventListener('input', revisarEdad);

// La copia de edad y código postal hacia el contratante ya NO se hace aquí.
// Se hace en el servidor (public/index.php, ruta "cotizar"), que es el único
// lugar donde no se puede manipular. Ver el comentario de la sección
// "2 · Solicitante" más arriba.

$('#frm').addEventListener('submit', () => {
  const b = $('#enviar');
  b.disabled = true;
  b.textContent = 'Consultando a GNP…';
  setTimeout(() => { b.disabled = false; b.textContent = 'Cotizar en GNP'; }, 60000);
});

cargarMarcas().then(cargarPaquetes);
</script>
