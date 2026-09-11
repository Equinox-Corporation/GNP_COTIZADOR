<?php declare(strict_types=1);
/** @var array $diag @var array $procedencias @var list<array<string,mixed>> $plantillas @var string $error @var array $previo */
$v = static fn (string $k, string $d = ''): string => h($previo[$k] ?? $d);
$plantillasMarcadas = array_map('strval', (array) ($previo['plantillas'] ?? []));
?>

<h1>GNP Juega y Compara</h1>

<p class="ayuda">
  Compara varias plantillas propias de Equinox (<code>ADR-007</code>) lado a lado, en una sola cotización.
  Es un módulo aparte de "GNP Cotizador": aquí siempre se elige entre plantillas, nunca paquetes sueltos.
</p>

<?php if (!CatalogoServicio::listoParaCotizar()): ?>
  <div class="aviso alerta">
    <strong>El catálogo todavía no está cargado.</strong>
    Hay <?= number_format($diag['vehiculos']) ?> vehículos y <?= number_format($diag['paquetes']) ?> paquetes.
  </div>
<?php endif; ?>

<?php if ($plantillas === []): ?>
  <div class="aviso alerta">
    <strong>Todavía no hay plantillas activas.</strong>
    Créalas primero en <a href="<?= h(url('plantillas')) ?>">Paquetes propios</a>.
  </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="aviso error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= h(url('juega-y-compara')) ?>" id="frmJyc" autocomplete="off">
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

<section class="tarjeta destacada">
  <h2>2 · Solicitante</h2>
  <p class="nota">
    <strong>La edad y el código postal son los que fijan el precio.</strong> GNP tarifica con los datos
    de quien maneja el vehículo.
  </p>
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
    </label>
    <label>Correo del cliente
      <input type="email" name="correo" value="<?= $v('correo') ?>" maxlength="80">
    </label>
  </div>
</section>

<section class="tarjeta">
  <h2>3 · Plantillas a comparar</h2>
  <p class="ayuda">
    Marca <span class="req">*</span> las plantillas que quieres ver lado a lado. Todas se cotizan en
    <strong>una sola llamada</strong> a GNP — cada una con sus propias coberturas.
  </p>
  <div class="opciones">
    <?php foreach ($plantillas as $pl): ?>
      <label class="chip">
        <input type="checkbox" name="plantillas[]" value="<?= (int) $pl['id'] ?>"
               <?= in_array((string) $pl['id'], $plantillasMarcadas, true) ? ' checked' : '' ?>>
        <span><?= h($pl['nombre']) ?></span>
        <small><?= h(ucwords(mb_strtolower($pl['paquete'], 'UTF-8'))) ?>
          · <?= $pl['tipo_persona'] === 'F' ? 'Física' : 'Moral' ?>
          · <?= h(CatalogoServicio::TIPOS_VEHICULO[$pl['tipo_vehiculo']] ?? $pl['tipo_vehiculo']) ?></small>
      </label>
    <?php endforeach; ?>
  </div>
  <p class="ayuda">
    Si una plantilla incluye una cobertura que el vehículo elegido no cumple (por ejemplo, por antigüedad),
    se cotiza igual sin esa cobertura y se avisa explícitamente — nunca se oculta.
  </p>

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
  <button type="submit" class="btn primario" id="enviarJyc">Comparar en GNP</button>
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
function actualizarTipoPersona() {
  const moral = $('#tipo_persona').value === 'M';
  document.querySelectorAll('.solo-fisica').forEach((campo) => {
    campo.style.display = moral ? 'none' : '';
    if (moral) campo.querySelectorAll('input').forEach((c) => { c.value = ''; });
  });
  $('#nota_edad_moral').style.display = moral ? '' : 'none';
  $('#etiqueta_nombre').firstChild.textContent = moral ? 'Razón social ' : 'Nombre(s) ';
}

$('#tipo_vehiculo').addEventListener('change', cargarMarcas);
$('#armadora').addEventListener('change', cargarLineas);
$('#carroceria').addEventListener('change', cargarAnios);
$('#modelo').addEventListener('change', cargarVersiones);
$('#version').addEventListener('change', cargarVersiones);
$('#tipo_persona').addEventListener('change', actualizarTipoPersona);
actualizarTipoPersona();

// ── Fecha de nacimiento ↔ Edad ───────────────────────────────────────────────
const anios = (iso) => {
  const f = new Date(iso + 'T00:00:00');
  if (isNaN(f)) return null;
  const h = new Date();
  let a = h.getFullYear() - f.getFullYear();
  const m = h.getMonth() - f.getMonth();
  if (m < 0 || (m === 0 && h.getDate() < f.getDate())) a--;
  return a;
};

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

$('#frmJyc').addEventListener('submit', () => {
  const b = $('#enviarJyc');
  b.disabled = true;
  b.textContent = 'Consultando a GNP…';
  setTimeout(() => { b.disabled = false; b.textContent = 'Comparar en GNP'; }, 60000);
});

cargarMarcas();
</script>
