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
    <?php require RUTA_APP . '/vistas/parciales/editor_coberturas.php'; ?>

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

<script src="<?= h(BASE_URL) ?>/assets/editor-coberturas.js"></script>
<script>
const API_COB = <?= json_encode(url('plantillas/api-coberturas')) ?>;
document.querySelector('#cve_paquete').addEventListener('change', () => EditorCoberturas.cargar(API_COB));
EditorCoberturas.activarExclusion(); // por si la carga inicial (edición) ya trae excluyentes marcadas
</script>
