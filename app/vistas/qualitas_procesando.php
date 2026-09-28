<?php declare(strict_types=1);
/**
 * Llegó un segundo envío de un formulario cuya primera petición todavía está
 * cotizando (SolicitudUnica, EN_CURSO). No se llama a Qualitas otra vez.
 */
?>

<h1>Cotizar con Qualitas</h1>

<div class="aviso alerta">
  <strong>Tu cotización se está procesando.</strong>
  Este formulario ya se envió y Qualitas todavía está respondiendo; no se mandó otra vez.
  En unos segundos la encontrarás en el historial.
</div>

<div class="acciones">
  <a class="btn primario" href="<?= h(url('historial', ['aseguradora' => 'QUALITAS'])) ?>">Ir al historial de Qualitas</a>
</div>
