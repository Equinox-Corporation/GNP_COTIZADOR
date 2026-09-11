<?php declare(strict_types=1);
/**
 * Editor de coberturas en chips — compartido entre `plantillas.php` (crear/
 * editar una plantilla oficial) y `armador.php` (armar una combinación
 * ad-hoc, ADR-007 / docs/02.14). Un solo lugar, no dos copias: si algo se
 * corrige aquí (como el resaltado de "ya seleccionada"), se corrige para las
 * dos pantallas a la vez.
 *
 * Variables que espera quien lo incluya:
 * @var list<array<string,mixed>> $coberturasIniciales  de CatalogoServicio::coberturasDe() / PlantillaServicio::coberturasDisponibles()
 * @var array<string,string> $sumaGuardada  cve_cobertura => suma ya elegida (vacío si no hay punto de partida)
 * @var array<string,string> $dedGuardada   cve_cobertura => deducible ya elegido
 */

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
<div id="coberturas" class="lista-coberturas">
  <?php foreach ($coberturasIniciales as $c): ?>
    <?php
      $marcada = array_key_exists($c['cve_cobertura'], $sumaGuardada);
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
