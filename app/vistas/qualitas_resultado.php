<?php declare(strict_types=1);
/**
 * Resultado de Qualitas.
 *
 * @var array $cot @var array $datos @var array $resultados @var bool $vencida @var string $aviso @var bool $puedeCotizar
 */
$noDisp = 'no disponible';
$pct = static fn ($n): string => $n === null || $n === '' ? 'no disponible' : rtrim(rtrim(number_format((float) $n, 2), '0'), '.') . '%';
$din = static fn ($n): string => $n === null || $n === '' ? 'no disponible' : dinero((float) $n);
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
    <div class="ficha"><span>Código postal · estado</span><strong><?= h((string) $cot['conductor_cp']) ?> · <?= h(AseguradoraQualitas::ESTADOS[(int) ($datos['estado'] ?? 0)] ?? '—') ?></strong></div>
    <div class="ficha"><span>Uso</span><strong><?= h(AseguradoraQualitas::USOS[(string) ($datos['uso'] ?? '1')] ?? '—') ?></strong></div>
    <div class="ficha"><span>Descuento aplicado</span><strong><?= isset($datos['porcentaje_descuento']) ? (int) $datos['porcentaje_descuento'] . '%' : '—' ?></strong></div>
    <div class="ficha"><span>Vigencia de la cotización</span><strong>hasta <?= h((string) $cot['vence_en']) ?></strong><small class="ayuda">7 días (por confirmar con Qualitas)</small></div>
  </div>
</section>

<?php if ($resultados !== []): ?>
<div class="comparativo">
  <?php foreach ($resultados as $i => $r): $c = $r['conceptos']; ?>
    <article class="paquete<?= $i === 0 ? ' mejor' : '' ?>">
      <?php if ($i === 0 && count($resultados) > 1): ?><div class="etiqueta">Más económico</div><?php endif; ?>
      <h3><?= h($r['paquete']) ?></h3>
      <p class="clave">Qualitas · cotización <?= h((string) ($c['no_cotizacion'] ?? '')) ?></p>

      <p class="precio"><?= $din($r['total_pagar']) ?></p>
      <p class="periodo">contado · total a pagar con derechos e IVA</p>

      <dl class="desglose">
        <dt>Prima neta</dt><dd><?= $din($r['prima_neta']) ?></dd>
        <dt>Recargo (incluye pronto pago <?= (int) ($c['pronto_pago_dias'] ?? 0) ?> días)</dt><dd><?= $din($c['recargo'] ?? null) ?></dd>
        <dt>Derecho de póliza</dt><dd><?= $din($r['derechos']) ?></dd>
        <dt>IVA</dt><dd><?= $din($r['iva']) ?></dd>
        <dt><strong>Comisión (porcentaje)</strong></dt><dd><strong><?= h($pct($c['comision_porcentaje'] ?? null)) ?></strong></dd>
        <dt><strong>Comisión (importe)</strong></dt><dd><strong><?= h($din($c['comision_importe'] ?? null)) ?></strong></dd>
      </dl>
      <p class="ayuda">Comisión tal como la devuelve Qualitas; el sistema no la calcula.</p>

      <div class="acciones">
        <a class="btn" href="<?= h(url('qualitas/pdf', ['resultado_id' => $r['id']])) ?>" target="_blank">PDF de la cotización</a>
      </div>

      <h4>Formas de pago</h4>
      <div class="tabla-envoltura">
      <table class="listado">
        <thead><tr><th>Forma</th><th>Total</th><th>Pagos</th><th>Recibos</th><th>Comisión</th></tr></thead>
        <tbody>
          <?php
            $formas = ['C' => [
                'total_pagar' => $r['total_pagar'], 'num_pagos' => $r['num_pagos'], 'recibos' => $c['recibos'] ?? [],
                'comision_porcentaje' => $c['comision_porcentaje'] ?? null, 'comision_importe' => $c['comision_importe'] ?? null,
            ]] + (array) ($c['formas_pago'] ?? []);
          ?>
          <?php foreach (AseguradoraQualitas::FORMAS_PAGO as $clave => $nombre): if (!isset($formas[$clave])) { continue; } $fp = $formas[$clave]; ?>
            <tr>
              <td><?= h($nombre) ?></td>
              <td><?= $din($fp['total_pagar'] ?? null) ?></td>
              <td><?= $fp['num_pagos'] !== null ? (int) $fp['num_pagos'] : '—' ?></td>
              <td>
                <?php foreach ((array) ($fp['recibos'] ?? []) as $rc): ?>
                  <small>#<?= h((string) ($rc['@NoRecibo'] ?? '')) ?>: <?= $din($rc['PrimaTotal'] ?? null) ?></small>
                <?php endforeach; ?>
              </td>
              <td>
                <small><?= h($pct($fp['comision_porcentaje'] ?? null)) ?></small>
                <?php if (($fp['comision_importe'] ?? null) !== null): ?>
                  <small><?= $din($fp['comision_importe']) ?></small>
                <?php else: ?>
                  <?php foreach ((array) ($fp['recibos'] ?? []) as $rc): ?>
                    <small>#<?= h((string) ($rc['@NoRecibo'] ?? '')) ?>: <?= h($din($rc['Comision'] ?? null)) ?></small>
                  <?php endforeach; ?>
                  <?php if (($fp['recibos'] ?? []) === []): ?><small><?= $noDisp ?></small><?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php $faltan = array_diff(['S', 'T', 'M'], array_keys($formas)); ?>
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
