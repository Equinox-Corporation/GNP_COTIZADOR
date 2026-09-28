<?php declare(strict_types=1);
/**
 * Resultado de Qualitas. El contexto lo arma QualitasServicio::contextoResultado().
 *
 * Importes con las mismas etiquetas, orden y formato que los PDF de Qualitas
 * (AseguradoraQualitas::importes), igual que el PDF propio. La comisión sólo
 * aparece aquí, nunca en el PDF: esta pantalla la ven administradores.
 *
 * @var array $cot @var array $datosAseg @var array $resultados @var bool $vencida @var string $aviso @var bool $puedeCotizar
 */
$m = static fn ($n): string => AseguradoraQualitas::monto($n === null || $n === '' ? null : (float) $n);
$pct = static fn ($n): string => $n === null || $n === '' ? 'no disponible' : rtrim(rtrim(number_format((float) $n, 2), '0'), '.') . '%';
$estadoNombre = AseguradoraQualitas::ESTADOS[(int) ($datosAseg['estado'] ?? 0)] ?? 'no disponible';
$descuento = isset($datosAseg['porcentaje_descuento']) ? (int) $datosAseg['porcentaje_descuento'] . '%' : 'no disponible';
?>

<h1>Cotización Qualitas</h1>

<?php if ($aviso !== ''): ?>
  <div class="aviso alerta"><?= h($aviso) ?></div>
<?php endif; ?>
<?php if ($cot['estado'] === 'ERROR'): ?>
  <div class="aviso error">Qualitas no cotizó: <?= h((string) $cot['error_desc']) ?></div>
<?php elseif (!empty($cot['error_desc'])): ?>
  <div class="aviso alerta">Algunos paquetes no se cotizaron: <?= h((string) $cot['error_desc']) ?></div>
<?php endif; ?>
<?php if ($vencida): ?>
  <div class="aviso error">Esta cotización venció el <?= h((string) $cot['vence_en']) ?>. Hay que cotizar de nuevo.</div>
<?php endif; ?>

<section class="tarjeta">
  <div class="rejilla">
    <div class="ficha"><span>Número Qualitas</span><strong><?= h((string) ($cot['folio'] ?? '') ?: '—') ?></strong></div>
    <div class="ficha"><span>Vehículo</span><strong><?= h((string) $cot['descripcion_veh']) ?></strong></div>
    <div class="ficha"><span>Código postal · estado</span><strong><?= h((string) $cot['conductor_cp']) ?> · <?= h($estadoNombre) ?></strong></div>
    <div class="ficha"><span>Uso</span><strong><?= h(AseguradoraQualitas::USOS[(string) ($datosAseg['uso'] ?? '')] ?? 'no disponible') ?></strong></div>
    <div class="ficha"><span>Descuento aplicado</span><strong><?= h($descuento) ?></strong></div>
    <div class="ficha"><span>Vigencia de la cotización</span><strong>7 días (hasta <?= h((string) $cot['vence_en']) ?>)</strong><small class="ayuda">Los 7 días salen de los PDF de ejemplo de Qualitas: pendiente de confirmar con ellos.</small></div>
  </div>
</section>

<?php if ($resultados !== []): ?>
<div class="comparativo">
  <?php foreach ($resultados as $i => $r): $c = $r['conceptos']; ?>
    <article class="paquete<?= $i === 0 ? ' mejor' : '' ?>">
      <?php if ($i === 0 && count($resultados) > 1): ?><div class="etiqueta">Más económico</div><?php endif; ?>
      <h3><?= h($r['paquete']) ?></h3>
      <p class="clave">Qualitas · cotización <?= h((string) ($c['no_cotizacion'] ?? '')) ?></p>

      <p class="precio"><?= h($m($r['total_pagar'])) ?></p>
      <p class="periodo">IMPORTE TOTAL · contado · con derechos e IVA</p>

      <dl class="desglose">
        <?php foreach (AseguradoraQualitas::importes(
            $r['prima_neta'] !== null ? (float) $r['prima_neta'] : null,
            isset($c['recargo']) ? (float) $c['recargo'] : null,
            $r['derechos'] !== null ? (float) $r['derechos'] : null,
            $r['iva'] !== null ? (float) $r['iva'] : null,
            $r['total_pagar'] !== null ? (float) $r['total_pagar'] : null
        ) as [$etiqueta, $valor]): ?>
          <dt><?= h($etiqueta) ?></dt><dd><?= h($valor) ?></dd>
        <?php endforeach; ?>
        <dt><strong>Comisión (porcentaje)</strong></dt><dd><strong><?= h($pct($c['comision_porcentaje'] ?? null)) ?></strong></dd>
        <dt><strong>Comisión (importe)</strong></dt><dd><strong><?= h($m($c['comision_importe'] ?? null)) ?></strong></dd>
      </dl>
      <p class="ayuda">TASA FIN. P.F. incluye el descuento por pronto pago (<?= (int) ($c['pronto_pago_dias'] ?? 0) ?> días). Comisión tal como la devuelve Qualitas; el sistema no la calcula y no aparece en el PDF.</p>

      <div class="acciones">
        <a class="btn" href="<?= h(url('qualitas/pdf', ['resultado_id' => $r['id']])) ?>" target="_blank">PDF de la cotización</a>
      </div>

      <h4>Formas de pago</h4>
      <?php $formas = AseguradoraQualitas::formasDePago($r); ?>
      <div class="tabla-envoltura">
      <table class="listado">
        <thead><tr><th>Forma</th><th>Total</th><th>Primer pago</th><th>Pagos siguientes</th><th>Pagos</th><th>Comisión</th></tr></thead>
        <tbody>
          <?php foreach ($formas as $fp): ?>
            <tr>
              <td><?= h($fp['nombre']) ?></td>
              <td><?= h($m($fp['total'])) ?></td>
              <td><?= h($m($fp['primer'])) ?></td>
              <td><?= $fp['siguientes'] !== null ? h($m($fp['siguientes'])) : '—' ?></td>
              <td><?= $fp['pagos'] !== null ? (int) $fp['pagos'] : '—' ?></td>
              <td>
                <small><?= h($pct($fp['comision_porcentaje'])) ?></small>
                <?php if ($fp['comision_recibos'] === []): ?>
                  <small>no disponible</small>
                <?php elseif (count($fp['comision_recibos']) === 1): ?>
                  <small><?= h($m($fp['comision_recibos'][0])) ?></small>
                <?php else: ?>
                  <small><?= h($m($fp['comision_recibos'][0])) ?> por recibo (<?= count($fp['comision_recibos']) ?>)</small>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php $faltan = array_diff(['S', 'T', 'M'], array_column($formas, 'clave')); ?>
      <?php if ($faltan !== [] && !$vencida && $puedeCotizar): ?>
        <form method="post" action="<?= h(url('qualitas/formas-pago')) ?>">
          <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
          <input type="hidden" name="resultado_id" value="<?= (int) $r['id'] ?>">
          <button class="btn">Ver otras formas de pago</button>
          <span class="ayuda">Cotiza semestral, trimestral y mensual: una llamada a Qualitas por cada una.</span>
        </form>
      <?php endif; ?>

      <h4>Coberturas</h4>
      <table class="coberturas">
        <thead><tr><th>Cobertura</th><th>Suma asegurada</th><th>Deducible</th></tr></thead>
        <tbody>
          <?php foreach ($r['coberturas'] as $cb): ?>
            <tr><th><?= h($cb['nombre']) ?></th><td><?= h($cb['suma_asegurada']) ?></td><td><?= h($cb['deducible']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="acciones">
  <a class="btn primario" href="<?= h(url('qualitas')) ?>">Nueva cotización Qualitas</a>
  <a class="btn" href="<?= h(url('historial', ['aseguradora' => 'QUALITAS'])) ?>">Historial de Qualitas</a>
  <a class="btn plano" href="<?= h(url('evidencia', ['id' => $cot['id'], 'parte' => 'peticion'])) ?>">Evidencia: petición</a>
  <a class="btn plano" href="<?= h(url('evidencia', ['id' => $cot['id'], 'parte' => 'respuesta'])) ?>">Evidencia: respuesta</a>
</div>
