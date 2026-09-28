<?php declare(strict_types=1);
/** @var array $filas @var array $cambios @var array $aseguradoras @var string $error @var string $ok */
$nombreAseg = array_column($aseguradoras, 'nombre', 'clave');
?>

<h1>Rango de descuento por aseguradora</h1>

<p class="ayuda">
  El usuario captura el porcentaje de descuento al cotizar y el sistema lo valida contra este rango, en pantalla y en el servidor.
  Se busca primero la fila de la aseguradora y el tipo de vehículo; si no existe, la fila <strong>Todos</strong>.
  Si tampoco existe, <strong>no se permite descuento</strong> (0%).
  Mientras no se confirme qué dato del catálogo de Qualitas dice el tipo de vehículo, Qualitas usa la fila <strong>Todos</strong>.
  GNP no aparece aquí: no recibe descuento en la petición.
</p>

<?php if ($error !== ''): ?>
  <div class="aviso error"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($ok !== ''): ?>
  <div class="aviso ok"><?= h($ok) ?></div>
<?php endif; ?>

<section class="tarjeta">
  <h2>Agregar o cambiar un rango</h2>
  <form method="post" action="<?= h(url('descuentos/guardar')) ?>" id="form-descuento" autocomplete="off">
    <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
    <div class="rejilla">
      <label>Aseguradora
        <select name="aseguradora" required>
          <?php foreach ($aseguradoras as $a): ?>
            <option value="<?= h($a['clave']) ?>"><?= h($a['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Tipo de vehículo
        <select name="tipo_vehiculo" required>
          <?php foreach (RangoDescuento::TIPOS as $clave => $nombre): ?>
            <option value="<?= h($clave) ?>"><?= h($nombre) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Mínimo (%)
        <input type="number" name="minimo" min="0" max="100" step="1" required inputmode="numeric">
      </label>
      <label>Máximo (%)
        <input type="number" name="maximo" min="0" max="100" step="1" required inputmode="numeric">
        <span class="ayuda">Enteros, 0 ≤ mínimo ≤ máximo ≤ 100.</span>
      </label>
    </div>
    <div class="acciones">
      <button class="btn primario">Guardar rango</button>
      <span class="aviso error" id="error-descuento" hidden></span>
    </div>
  </form>
</section>

<h2>Rangos vigentes</h2>
<div class="tabla-envoltura">
<table class="listado">
  <thead>
    <tr><th>Aseguradora</th><th>Tipo de vehículo</th><th>Mínimo</th><th>Máximo</th><th>Último cambio</th><th></th></tr>
  </thead>
  <tbody>
  <?php if ($filas === []): ?>
    <tr><td colspan="6">Sin rangos: ninguna aseguradora permite descuento.</td></tr>
  <?php endif; ?>
  <?php foreach ($filas as $f): ?>
    <tr>
      <td><strong><?= h($nombreAseg[$f['aseguradora']] ?? $f['aseguradora']) ?></strong></td>
      <td><?= h(RangoDescuento::TIPOS[$f['tipo_vehiculo']] ?? $f['tipo_vehiculo']) ?></td>
      <td><?= (int) $f['minimo'] ?>%</td>
      <td><?= (int) $f['maximo'] ?>%</td>
      <td><small><?= h($f['actualizado_por'] ?: '—') ?> · <?= h($f['actualizado_en']) ?></small></td>
      <td>
        <details>
          <summary>Editar</summary>
          <form method="post" action="<?= h(url('descuentos/guardar')) ?>" class="form-descuento-fila">
            <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
            <input type="hidden" name="aseguradora" value="<?= h($f['aseguradora']) ?>">
            <input type="hidden" name="tipo_vehiculo" value="<?= h($f['tipo_vehiculo']) ?>">
            <label>Mínimo <input type="number" name="minimo" min="0" max="100" step="1" required value="<?= (int) $f['minimo'] ?>"></label>
            <label>Máximo <input type="number" name="maximo" min="0" max="100" step="1" required value="<?= (int) $f['maximo'] ?>"></label>
            <button class="btn">Guardar</button>
          </form>
        </details>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2>Cambios registrados</h2>
<div class="tabla-envoltura">
<table class="listado">
  <thead>
    <tr><th>Cuándo</th><th>Quién</th><th>Aseguradora</th><th>Tipo</th><th>Antes</th><th>Después</th></tr>
  </thead>
  <tbody>
  <?php foreach ($cambios as $c): ?>
    <tr>
      <td><small><?= h($c['cambiado_en']) ?></small></td>
      <td><?= h($c['usuario'] ?: '—') ?></td>
      <td><?= h($nombreAseg[$c['aseguradora']] ?? $c['aseguradora']) ?></td>
      <td><?= h(RangoDescuento::TIPOS[$c['tipo_vehiculo']] ?? $c['tipo_vehiculo']) ?></td>
      <td><?= $c['minimo_antes'] === null ? '—' : (int) $c['minimo_antes'] . '–' . (int) $c['maximo_antes'] . '%' ?></td>
      <td><?= (int) $c['minimo'] ?>–<?= (int) $c['maximo'] ?>%</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<script>
// Validación en pantalla. El servidor la repite (RangoDescuento::guardar).
document.querySelectorAll('#form-descuento, .form-descuento-fila').forEach(function (form) {
  form.addEventListener('submit', function (ev) {
    var min = form.elements.minimo.value.trim(), max = form.elements.maximo.value.trim();
    var msg = '';
    if (!/^\d{1,3}$/.test(min) || !/^\d{1,3}$/.test(max)) {
      msg = 'Mínimo y máximo tienen que ser números enteros.';
    } else if (+min > +max || +max > 100) {
      msg = 'Tiene que cumplirse 0 ≤ mínimo ≤ máximo ≤ 100.';
    }
    if (msg !== '') {
      ev.preventDefault();
      var caja = document.getElementById('error-descuento');
      caja.textContent = msg;
      caja.hidden = false;
      if (form.id !== 'form-descuento') { alert(msg); }
    }
  });
});
</script>
