<?php declare(strict_types=1);
/** @var array $cot @var array $resultados @var array $documentos @var bool $vencida @var string $aviso */

// Todas las coberturas que aparecen en algún paquete, para armar la tabla comparativa.
$filas = [];
foreach ($resultados as $r) {
    foreach ($r['coberturas'] as $c) {
        $filas[$c['cve_cobertura']] = $c['nombre'];
    }
}
$porPaquete = [];
foreach ($resultados as $r) {
    foreach ($r['coberturas'] as $c) {
        $porPaquete[$r['cve_paquete']][$c['cve_cobertura']] = $c;
    }
}
$barato = $resultados[0] ?? null;
?>

<h1>Cotización <?= h($cot['folio'] ?: '(sin folio)') ?></h1>

<?php if ($aviso !== ''):
  // Tres tonos, no dos: "salió bien", "salió pero revísalo" y "no salió".
  // Un aviso de captura —menor de edad, paquetes incompletos— no es un error:
  // pintarlo de rojo hace que el vendedor deje de leerlos.
  $tono = str_contains($aviso, 'generado')                        ? 'ok'
        : (preg_match('/Advertencia|menor de Edad|Ojo|revisar|se pidieron/iu', $aviso) ? 'alerta' : 'error');
?>
  <div class="aviso <?= $tono ?>"><?= h($aviso) ?></div>
<?php endif; ?>

<?php if ($vencida): ?>
  <div class="aviso alerta">
    <strong>Esta cotización venció</strong> el <?= h($cot['vence_en']) ?>.
    GNP sólo las respeta 15 días naturales. Para presentarla hay que volver a cotizar.
  </div>
<?php endif; ?>

<section class="ficha">
  <div><span>Vehículo</span><strong><?= h($cot['descripcion_veh']) ?> · <?= h($cot['modelo']) ?></strong></div>
  <div><span>Clave GNP</span><strong><?= h($cot['clavemarca']) ?></strong></div>
  <?php
    // Desde que la pantalla unificó ambas entidades en "Solicitante", el
    // contratante hereda edad y CP del conductor y mostrarlos dos veces es
    // ruido. Las cotizaciones viejas sí pueden traerlos distintos: en ese caso
    // se siguen mostrando por separado, para no ocultar lo que realmente se
    // mandó a GNP.
    $mismaPersona = (int) $cot['contratante_edad'] === (int) $cot['conductor_edad']
                 && (string) $cot['contratante_cp'] === (string) $cot['conductor_cp'];
    $sexo = $cot['conductor_sexo'] === 'F' ? 'Femenino' : 'Masculino';
  ?>
  <?php if ($mismaPersona): ?>
    <div><span>Solicitante</span><strong><?= h($cot['contratante'] ?: '—') ?></strong></div>
    <div><span>Datos que fijaron el precio</span><strong><?= h($cot['conductor_edad']) ?> años · CP <?= h($cot['conductor_cp']) ?> · <?= $sexo ?></strong></div>
  <?php else: ?>
    <div><span>Contratante (titular)</span><strong><?= h($cot['contratante'] ?: '—') ?> · <?= h($cot['contratante_edad']) ?> años · CP <?= h($cot['contratante_cp']) ?></strong></div>
    <div><span>Conductor (define el precio)</span><strong><?= h($cot['conductor_edad']) ?> años · CP <?= h($cot['conductor_cp']) ?> · <?= $sexo ?></strong></div>
  <?php endif; ?>
  <div><span>Procedencia</span><strong><?= h($cot['procedencia']) ?> (subramo <?= h($cot['sub_ramo']) ?>)</strong></div>
  <div><span>Vigencia</span><strong>hasta <?= h($cot['vence_en'] ?: '—') ?></strong></div>
</section>

<?php if ($resultados === []): ?>
  <div class="aviso error">Esta cotización no tiene resultados. <?= h($cot['error_desc'] ?? '') ?></div>
<?php else: ?>

<h2>Comparativo</h2>
<p class="ayuda">
  El precio es el <strong>total a pagar</strong>, con derechos e IVA incluidos. Es lo que el cliente desembolsa.
</p>

<div class="comparativo">
  <?php foreach ($resultados as $i => $r): ?>
    <article class="paquete<?= $i === 0 ? ' mejor' : '' ?>">
      <?php if ($i === 0 && count($resultados) > 1): ?><div class="etiqueta">Más económico</div><?php endif; ?>
      <h3><?= h($r['paquete']) ?></h3>
      <p class="clave"><?= h($r['cve_paquete']) ?></p>

      <p class="precio"><?= dinero($r['total_pagar'] !== null ? (float) $r['total_pagar'] : null) ?></p>
      <p class="periodo"><?= (int) $r['num_pagos'] === 1 ? 'pago único anual' : ((int) $r['num_pagos'] . ' pagos') ?></p>

      <dl class="desglose">
        <dt>Prima neta</dt><dd><?= dinero($r['prima_neta'] !== null ? (float) $r['prima_neta'] : null) ?></dd>
        <dt>Derechos</dt><dd><?= dinero($r['derechos'] !== null ? (float) $r['derechos'] : null) ?></dd>
        <dt>IVA</dt><dd><?= dinero($r['iva'] !== null ? (float) $r['iva'] : null) ?></dd>
        <?php if ($r['descuento'] !== null && (float) $r['descuento'] != 0.0): ?>
          <dt>Descuento</dt><dd><?= dinero((float) $r['descuento']) ?></dd>
        <?php endif; ?>
      </dl>

      <?php if ($barato !== null && $i > 0 && $r['total_pagar'] !== null && $barato['total_pagar'] !== null): ?>
        <p class="diferencia">
          <?= dinero((float) $r['total_pagar'] - (float) $barato['total_pagar']) ?> más que <?= h($barato['paquete']) ?>
        </p>
      <?php endif; ?>

      <form method="post" action="<?= h(url('imprimir')) ?>">
        <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $cot['id'] ?>">
        <input type="hidden" name="cve" value="<?= h($r['cve_paquete']) ?>">
        <button class="btn<?= $vencida ? ' desactivado' : '' ?>"<?= $vencida ? ' disabled' : '' ?>>Generar PDF de GNP</button>
      </form>
    </article>
  <?php endforeach; ?>
</div>

<h2>Qué cubre cada uno</h2>
<div class="tabla-envoltura">
<table class="coberturas">
  <thead>
    <tr>
      <th>Cobertura</th>
      <?php foreach ($resultados as $r): ?><th><?= h($r['paquete']) ?></th><?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($filas as $cve => $nombre): ?>
      <tr>
        <th scope="row"><?= h($nombre) ?></th>
        <?php foreach ($resultados as $r):
              $c = $porPaquete[$r['cve_paquete']][$cve] ?? null; ?>
          <td<?= $c === null ? ' class="no"' : '' ?>>
            <?php if ($c === null): ?>
              no incluida
            <?php else: ?>
              <strong><?= h($c['suma_asegurada'] ?: 'Amparada') ?></strong>
              <?php if (trim((string) $c['deducible']) !== ''): ?>
                <small>deducible <?= h($c['deducible']) ?></small>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2>Comparativo Multi-Plan</h2>
<p class="ayuda">
  Un reporte propio del cotizador —GNP no lo genera— con las primas y todas las coberturas de
  <?= count($resultados) === 1 ? 'este paquete' : 'los ' . count($resultados) . ' paquetes' ?> lado a lado, listo para presentarle al cliente.
</p>
<div class="acciones">
  <a class="btn" href="<?= h(url('comparativo', ['id' => (int) $cot['id'], 'formato' => 'pdf'])) ?>">Descargar PDF</a>
  <a class="btn" href="<?= h(url('comparativo', ['id' => (int) $cot['id'], 'formato' => 'excel'])) ?>">Descargar Excel</a>
</div>

<?php endif; ?>

<?php if ($documentos !== []): ?>
  <h2>Documentos</h2>
  <ul class="documentos">
    <?php foreach ($documentos as $d): ?>
      <li>
        <a href="<?= h(url('pdf', ['id' => $d['id']])) ?>" target="_blank" rel="noopener">
          PDF · <?= h($d['cve_paquete']) ?>
        </a>
        <small><?= number_format((int) $d['bytes'] / 1024, 0) ?> KB · <?= h($d['generado_en']) ?>
        <?php if ($d['referencia'] !== ''): ?>· acuse <?= h($d['referencia']) ?><?php endif; ?></small>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<h2>Respaldo técnico</h2>
<p class="ayuda">
  Dos archivos con el XML literal de ida y vuelta: uno con lo que se le pidió a GNP,
  otro con lo que contestó. Sirven para soporte y como evidencia ante GNP.
  La contraseña del servicio va enmascarada; los datos del cliente no, así que trátalo como confidencial.
</p>
<div class="acciones">
  <a class="btn" href="<?= h(url('evidencia', ['id' => (int) $cot['id'], 'parte' => 'peticion'])) ?>">Descargar JSON de la petición</a>
  <a class="btn" href="<?= h(url('evidencia', ['id' => (int) $cot['id'], 'parte' => 'respuesta'])) ?>">Descargar JSON de la respuesta</a>
</div>

<div class="acciones">
  <a class="btn" href="<?= h(url('cotizar')) ?>">Nueva cotización</a>
  <a class="btn plano" href="<?= h(url('historial')) ?>">Ver historial</a>
</div>
