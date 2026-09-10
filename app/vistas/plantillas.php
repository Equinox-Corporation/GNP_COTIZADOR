<?php declare(strict_types=1);
/** @var list<array<string,mixed>> $filas @var list<array<string,mixed>> $paquetesBase
 *  @var string $error @var string $ok @var int $editarId */

$editando = $editarId > 0 ? PlantillaServicio::obtener($editarId) : null;

$cvePaqueteInicial = $editando['cve_paquete'] ?? ($paquetesBase[0]['cve_paquete'] ?? '');
$coberturasIniciales = $cvePaqueteInicial !== '' ? PlantillaServicio::coberturasDisponibles($cvePaqueteInicial) : [];
$sumaGuardada = [];
$dedGuardada  = [];
foreach ($editando['coberturas'] ?? [] as $g) {
    $sumaGuardada[$g['cve_cobertura']] = $g['suma_asegurada'];
    $dedGuardada[$g['cve_cobertura']]  = $g['deducible'];
}

/** Selector real si hay menú de valores; texto libre sólo si esa cobertura no tiene ninguno cargado. */
$campoValor = static function (string $nombreCampo, string $etiqueta, array $valores, string $valorActual, string $placeholder = ''): string {
    if ($valores === []) {
        return '<label class="campo-valor">' . h($etiqueta)
             . '<input name="' . h($nombreCampo) . '" value="' . h($valorActual) . '" placeholder="' . h($placeholder) . '">'
             . '<small class="ayuda">sin menú cargado</small></label>';
    }
    $html = '<label class="campo-valor">' . h($etiqueta) . '<select name="' . h($nombreCampo) . '">';
    $html .= '<option value="">—</option>';
    foreach ($valores as $v) {
        $sel = $v === $valorActual ? ' selected' : '';
        $html .= '<option value="' . h($v) . '"' . $sel . '>' . h($v) . '</option>';
    }
    // El valor guardado puede haber quedado fuera del menú actual (cambió el kit,
    // o venía de antes de cargar cat_cobertura_valores) — no se pierde en silencio.
    if ($valorActual !== '' && !in_array($valorActual, $valores, true)) {
        $html .= '<option value="' . h($valorActual) . '" selected>' . h($valorActual) . ' (fuera del menú actual)</option>';
    }
    return $html . '</select></label>';
};
?>

<h1>Paquetes propios · Juega y Compara</h1>

<p class="ayuda">
  Arma paquetes propios de Equinox sobre un paquete base de GNP, eligiendo entre las
  coberturas que ese paquete ya trae. Ver <code>ADR-007</code>.
  <strong>Esta pantalla todavía no está conectada a la cotización</strong>: crear o
  editar una plantilla aquí no cambia lo que un vendedor puede cotizar hoy.
</p>

<div class="aviso ok">
  La suma asegurada y el deducible se eligen de <code>cat_cobertura_valores</code> — el menú real que GNP
  maneja para cada cobertura, cargado desde el kit (<code>importar_valores_coberturas.php</code>). Ya no es
  texto libre: se valida aquí mismo antes de guardar, y la prueba de
  <code>docs/02.6-coberturas-modificadas.md</code> confirmó que GNP también valida en serio del otro lado
  (rechaza con clave 14 cuando el valor no es válido para esa cobertura).
</div>

<?php if ($error !== ''): ?>
  <div class="aviso error"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($ok !== ''): ?>
  <div class="aviso ok"><?= h($ok) ?></div>
<?php endif; ?>

<section class="tarjeta">
  <h2><?= $editando !== null ? 'Editar plantilla' : 'Nueva plantilla' ?></h2>

  <form method="post" action="<?= h(url('plantillas/guardar')) ?>" id="frm_plantilla" autocomplete="off">
    <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
    <input type="hidden" name="id" value="<?= $editando !== null ? (int) $editando['id'] : 0 ?>">

    <div class="rejilla">
      <label class="ancho">Nombre <span class="req">*</span>
        <input name="nombre" required maxlength="80" value="<?= h($editando['nombre'] ?? '') ?>"
               placeholder="Equinox Agente de Seguros y de Fianzas">
      </label>

      <label class="ancho">Paquete base de GNP <span class="req">*</span>
        <select name="cve_paquete" id="cve_paquete" required>
          <?php if ($paquetesBase === []): ?>
            <option value="">No hay paquetes disponibles — revisa cat_paquetes</option>
          <?php endif; ?>
          <?php foreach ($paquetesBase as $p): ?>
            <option value="<?= h($p['cve_paquete']) ?>" <?= $p['cve_paquete'] === $cvePaqueteInicial ? 'selected' : '' ?>>
              <?= h(ucwords(mb_strtolower($p['paquete'], 'UTF-8'))) ?>
              · <?= $p['tipo_persona'] === 'F' ? 'Física' : 'Moral' ?>
              · <?= h(CatalogoServicio::TIPOS_VEHICULO[$p['tipo_vehiculo']] ?? $p['tipo_vehiculo']) ?>
              (<?= h($p['cve_paquete']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <span class="ayuda">
          Sólo procedencia Residentes: es la única verificada contra GNP (ADR-005 punto 11).
          Un mismo paquete tiene una clave distinta por tipo de persona y de vehículo.
        </span>
      </label>

      <label class="linea">
        <input type="checkbox" name="activo" value="1" <?= (int) ($editando['activo'] ?? 1) === 1 ? 'checked' : '' ?>>
        Activa
      </label>
    </div>

    <h3>Coberturas</h3>
    <p class="ayuda">
      Sólo se pueden elegir coberturas que este paquete ya trae, como Básica u Opcional
      — confirmado contra GNP que no se puede salir de ahí (ADR-007 punto 3).
      Las excluyentes entre sí se desmarcan solas.
    </p>
    <div id="coberturas" class="lista-coberturas">
      <?php foreach ($coberturasIniciales as $c): ?>
        <?php
          $marcada = $editando !== null && array_key_exists($c['cve_cobertura'], $sumaGuardada);
          $sumaVal = $sumaGuardada[$c['cve_cobertura']] ?? $c['sa_valor'];
          $dedVal  = $dedGuardada[$c['cve_cobertura']]  ?? $c['ded_valor'];
        ?>
        <div class="fila-cobertura">
          <label class="chip">
            <input type="checkbox" name="coberturas[]" value="<?= h($c['cve_cobertura']) ?>"
                   <?= $marcada ? 'checked' : '' ?>
                   <?= $c['grupo_excl'] !== '' ? 'data-excl="' . h($c['grupo_excl']) . '"' : '' ?>>
            <span><?= h($c['nombre']) ?></span>
            <small class="marca-estado<?= $c['tipo'] === 'BASICA' ? ' ok' : '' ?>"><?= h($c['tipo']) ?></small>
          </label>
          <?= $campoValor('suma_' . $c['cve_cobertura'], 'Suma asegurada', $c['valores_suma'], (string) $sumaVal, $c['sa_unidad']) ?>
          <?= $campoValor('ded_' . $c['cve_cobertura'], 'Deducible', $c['valores_deducible'], (string) $dedVal) ?>
        </div>
      <?php endforeach; ?>
      <?php if ($coberturasIniciales === []): ?>
        <p class="ayuda">Elige un paquete base para ver sus coberturas.</p>
      <?php endif; ?>
    </div>

    <div class="acciones">
      <button class="btn primario"><?= $editando !== null ? 'Guardar cambios' : 'Crear plantilla' ?></button>
      <?php if ($editando !== null): ?>
        <a class="btn plano" href="<?= h(url('plantillas')) ?>">Cancelar edición</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<h2>Plantillas existentes</h2>
<div class="tabla-envoltura">
<table class="listado">
  <thead>
    <tr><th>Nombre</th><th>Paquete base</th><th>Coberturas</th><th>Estado</th><th></th></tr>
  </thead>
  <tbody>
  <?php if ($filas === []): ?>
    <tr><td colspan="5" class="ayuda">Todavía no hay ninguna plantilla.</td></tr>
  <?php endif; ?>
  <?php foreach ($filas as $f): ?>
    <tr>
      <td><strong><?= h($f['nombre']) ?></strong></td>
      <td><small><?= h($f['cve_paquete']) ?></small></td>
      <td><?= (int) $f['num_coberturas'] ?></td>
      <td>
        <?php if ((int) $f['activo'] === 1): ?>
          <span class="marca-estado ok">activa</span>
        <?php else: ?>
          <span class="marca-estado err">apagada</span>
        <?php endif; ?>
      </td>
      <td>
        <div class="acciones-fila">
          <a class="btn plano" href="<?= h(url('plantillas', ['editar' => (int) $f['id']])) ?>">Editar</a>
          <form method="post" action="<?= h(url('plantillas/estado')) ?>">
            <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button class="btn plano"><?= (int) $f['activo'] === 1 ? 'Apagar' : 'Prender' ?></button>
          </form>
          <form method="post" action="<?= h(url('plantillas/eliminar')) ?>"
                onsubmit="return confirm('¿Eliminar la plantilla &quot;<?= h(addslashes($f['nombre'])) ?>&quot;? No se puede deshacer.');">
            <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button class="btn plano peligro">Eliminar</button>
          </form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<script>
const API_COB = <?= json_encode(url('plantillas/api-coberturas')) ?>;
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

async function cargarCoberturas() {
  const cve = $('#cve_paquete').value;
  const cont = $('#coberturas');
  cont.innerHTML = '';
  if (!cve) {
    cont.appendChild(el('p', { className: 'ayuda', textContent: 'Elige un paquete base para ver sus coberturas.' }));
    return;
  }
  const u = new URL(API_COB, location.href);
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

// Bloquea marcar dos coberturas del mismo grupo excluyente a la vez, con
// mensaje claro — ADR-007 punto 6. GNP rechaza la cotización completa si
// llegan juntas (cat_coberturas_excluyentes).
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

$('#cve_paquete').addEventListener('change', cargarCoberturas);
activarExclusion(); // por si la carga inicial (edición) ya trae excluyentes marcadas
</script>
