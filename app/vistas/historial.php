<?php declare(strict_types=1); /** @var array $filas */ ?>

<h1>Historial de cotizaciones</h1>

<?php if ($filas === []): ?>
  <p class="ayuda">Todavía no hay cotizaciones. <a href="<?= h(url('cotizar')) ?>">Haz la primera</a>.</p>
<?php else: ?>
<div class="tabla-envoltura">
<table class="listado">
  <thead>
    <tr><th>Folio</th><th>Vehículo</th><th>Conductor</th><th>Paquetes</th><th>Desde</th><th>Vigencia</th><th>Ver cotización</th><th>JSON request</th><th>JSON response</th></tr>
  </thead>
  <tbody>
  <?php foreach ($filas as $f): ?>
    <tr<?= (int) ($f['vencida'] ?? 0) === 1 ? ' class="vencida"' : '' ?>>
      <td>
        <strong><?= h($f['folio'] ?: '—') ?></strong>
        <small><?= h($f['creada_en']) ?></small>
      </td>
      <td><?= h($f['descripcion_veh']) ?> <small><?= h($f['modelo']) ?></small></td>
      <td><?= h($f['conductor_edad']) ?> años <small>CP <?= h($f['conductor_cp']) ?></small></td>
      <td><?= (int) $f['paquetes'] ?><?php if ((int) $f['pdfs'] > 0): ?> <small><?= (int) $f['pdfs'] ?> PDF</small><?php endif; ?></td>
      <td><?= $f['desde'] !== null ? dinero((float) $f['desde']) : '—' ?></td>
      <td>
        <?php if ($f['estado'] !== 'COTIZADA'): ?>
          <span class="marca-estado err"><?= h($f['estado']) ?></span>
        <?php elseif ((int) ($f['vencida'] ?? 0) === 1): ?>
          <span class="marca-estado err">venció <?= h($f['vence_en']) ?></span>
        <?php else: ?>
          <span class="marca-estado ok">vigente al <?= h($f['vence_en']) ?></span>
        <?php endif; ?>
      </td>
      <td><a href="<?= h(url('resultado', ['id' => $f['id']])) ?>">Ver</a></td>
      <td><a href="<?= h(url('evidencia', ['id' => $f['id'], 'parte' => 'peticion'])) ?>">Descargar</a></td>
      <td><a href="<?= h(url('evidencia', ['id' => $f['id'], 'parte' => 'respuesta'])) ?>">Descargar</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
