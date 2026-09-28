<?php declare(strict_types=1);
/**
 * Captura de Qualitas. Sólo administradores mientras Qualitas no esté OPERATIVA.
 *
 * @var string $error @var array $previo @var array $paquetes @var array $deducibles
 * @var array{minimo:int,maximo:int,fila:?string} $rango @var string $estadoAseg
 */
$v = static fn (string $k, string $omision = ''): string => (string) ($previo[$k] ?? $omision);
$paqPrevios = array_map('intval', (array) ($previo['paquetes'] ?? []));
$dedPrevios = (array) ($previo['deducibles'] ?? []);
$ambiente = strtoupper(Env::get('QUALITAS_AMBIENTE', 'QA'));
?>

<h1>Cotizar con Qualitas</h1>

<?php if ($estadoAseg !== Aseguradoras::OPERATIVA): ?>
  <div class="aviso alerta">
    Qualitas está <strong><?= h(str_replace('_', ' ', strtolower($estadoAseg))) ?></strong>: esta pantalla sólo la ven administradores.
    Las cotizaciones van al ambiente <strong><?= h($ambiente === 'QA' ? 'de pruebas (QA)' : 'de producción') ?></strong> de Qualitas.
    Cotizar no genera póliza.
  </div>
<?php endif; ?>

<div class="aviso alerta">
  <strong>Catálogo de vehículos pendiente.</strong> Qualitas todavía no entrega el usuario del servicio de catálogo,
  así que la clave AMIS y el modelo se escriben a mano. Revisa la clave: si no corresponde al vehículo, Qualitas cotiza otro.
</div>

<?php if ($error !== ''): ?>
  <div class="aviso error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= h(url('qualitas/cotizar')) ?>" id="form-qualitas" autocomplete="off">
  <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
  <input type="hidden" name="solicitud" value="<?= h($solicitud) ?>">

  <section class="tarjeta">
    <h2>Vehículo</h2>
    <div class="rejilla">
      <label>Clave AMIS
        <input name="clave_vehiculo" required inputmode="numeric" pattern="\d{1,5}" maxlength="5" value="<?= h($v('clave_vehiculo')) ?>">
        <span class="ayuda">Hasta 5 dígitos. El dígito verificador lo calcula el sistema.</span>
      </label>
      <label>Modelo (año)
        <input name="modelo" required inputmode="numeric" pattern="\d{4}" maxlength="4" value="<?= h($v('modelo')) ?>">
      </label>
      <label>Uso
        <select name="uso" id="uso">
          <?php foreach (AseguradoraQualitas::USOS as $clave => $nombre): ?>
            <option value="<?= h($clave) ?>"<?= $v('uso', '1') === $clave ? ' selected' : '' ?>><?= h($nombre) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="ayuda">Servicio: particular (el único autorizado en el negocio).</span>
      </label>
    </div>
    <div class="rejilla" id="bloque-carga"<?= $v('uso', '1') === '6' ? '' : ' hidden' ?>>
      <label>Tipo de carga
        <select name="tipo_carga">
          <?php foreach (['A', 'B', 'C'] as $t): ?>
            <option value="<?= $t ?>"<?= $v('tipo_carga', 'A') === $t ? ' selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="ancho">Descripción de la carga
        <input name="descripcion_carga" maxlength="60" value="<?= h($v('descripcion_carga')) ?>">
      </label>
    </div>
  </section>

  <section class="tarjeta">
    <h2>Solicitante</h2>
    <div class="rejilla">
      <label>Código postal
        <input name="conductor_cp" required inputmode="numeric" pattern="\d{5}" maxlength="5" value="<?= h($v('conductor_cp')) ?>">
      </label>
      <label>Estado
        <select name="estado" required>
          <option value="">— elige —</option>
          <?php foreach (AseguradoraQualitas::ESTADOS as $id => $nombre): ?>
            <option value="<?= $id ?>"<?= $v('estado') === (string) $id ? ' selected' : '' ?>><?= h($nombre) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="ayuda">Tiene que corresponder con el código postal: si no, Qualitas lo rechaza.</span>
      </label>
    </div>
  </section>

  <section class="tarjeta">
    <h2>Paquetes y deducibles</h2>
    <div class="rejilla">
      <?php foreach ($paquetes as $p): ?>
        <?php $activo = (int) $p['activo'] === 1 && $p['codigo'] !== ''; ?>
        <label class="linea">
          <input type="checkbox" name="paquetes[]" value="<?= (int) $p['id'] ?>"
            <?= $activo ? '' : ' disabled' ?><?= $activo && ($paqPrevios === [] ? $p['nombre'] === 'Amplia' : in_array((int) $p['id'], $paqPrevios, true)) ? ' checked' : '' ?>>
          <?= h($p['nombre']) ?>
          <?php if (!$activo): ?><span class="ayuda">Todavía no. <?= h($p['nota']) ?></span><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="ayuda">Cada paquete es una llamada a Qualitas. Se cotiza de contado; las otras formas de pago se piden desde el resultado.</p>
    <div class="rejilla">
      <?php foreach ($deducibles as $no => $d): ?>
        <label>Deducible <?= h($d['nombre']) ?>
          <select name="deducibles[<?= (int) $no ?>]">
            <?php foreach ($d['opciones'] as $op): ?>
              <option value="<?= h($op) ?>"<?= (string) ($dedPrevios[$no] ?? $d['por_omision']) === $op ? ' selected' : '' ?>><?= h($op) ?>%</option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="tarjeta">
    <h2>Descuento</h2>
    <div class="rejilla">
      <label>Porcentaje de descuento
        <input name="porcentaje_descuento" id="descuento" required inputmode="numeric" type="number" step="1"
               min="<?= (int) $rango['minimo'] ?>" max="<?= (int) $rango['maximo'] ?>"
               value="<?= h($v('porcentaje_descuento', (string) $rango['maximo'])) ?>">
        <span class="ayuda">
          Rango permitido: <strong><?= (int) $rango['minimo'] ?> a <?= (int) $rango['maximo'] ?>%</strong>
          <?= $rango['fila'] === null ? '(sin rango configurado: no se permite descuento)' : '' ?>.
          Lo que escribas es lo que se manda a Qualitas.
        </span>
      </label>
    </div>
    <p class="ayuda">Pronto pago: siempre se pide con <?= (int) Env::get('QUALITAS_PRONTO_PAGO_DIAS', '14') ?> días.</p>
  </section>

  <div class="acciones">
    <button class="btn primario" id="btn-cotizar" data-texto="Cotizar con Qualitas">Cotizar con Qualitas</button>
    <span class="aviso error" id="error-qualitas" hidden></span>
  </div>
</form>

<script>
(function () {
  var uso = document.getElementById('uso'), carga = document.getElementById('bloque-carga');
  uso.addEventListener('change', function () { carga.hidden = uso.value !== '6'; });

  // Validación en pantalla del descuento. El servidor la repite (RangoDescuento::validar).
  var min = <?= (int) $rango['minimo'] ?>, max = <?= (int) $rango['maximo'] ?>;
  document.getElementById('form-qualitas').addEventListener('submit', function (ev) {
    var d = document.getElementById('descuento').value.trim(), msg = '';
    if (!/^\d{1,3}$/.test(d)) {
      msg = 'El descuento tiene que ser un número entero. Rango permitido: ' + min + ' a ' + max + '%.';
    } else if (+d < min || +d > max) {
      msg = 'El descuento de ' + d + '% está fuera del rango permitido: ' + min + ' a ' + max + '%.';
    } else if (!document.querySelector('input[name="paquetes[]"]:checked')) {
      msg = 'Elige al menos un paquete.';
    }
    if (msg !== '') {
      ev.preventDefault();
      var caja = document.getElementById('error-qualitas');
      caja.textContent = msg;
      caja.hidden = false;
      return;
    }
    // Un solo envío: el botón se bloquea mientras Qualitas responde. El
    // servidor igual rechaza un segundo envío del mismo formulario (SolicitudUnica).
    var btn = document.getElementById('btn-cotizar');
    btn.disabled = true;
    btn.textContent = 'Cotizando…';
  });

  // Si se vuelve con "atrás" y el navegador muestra la página guardada, el
  // botón no debe quedarse bloqueado.
  window.addEventListener('pageshow', function () {
    var btn = document.getElementById('btn-cotizar');
    btn.disabled = false;
    btn.textContent = btn.dataset.texto;
  });
})();
</script>
