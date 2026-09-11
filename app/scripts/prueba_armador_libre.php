#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_armador_libre.php — Prueba de aceptación del armador libre de
 * coberturas, Fase 1 backend (ver docs/02.13-armador-libre-backend.md).
 *
 * Tres casos, contra PRODUCCIÓN, mismo patrón de siempre:
 *
 *   1. Parte de Equinox Amplia Plus (id=75): quita "Siempre en Agencia",
 *      agrega "Robo Parcial" (deducible 10%) — cotiza directo, sin tocar
 *      cat_plantillas.
 *   2. Parte del paquete Limitada VACÍO (sin plantilla_id) — agrega sólo
 *      "Robo Total" con deducible 5% — cotiza directo.
 *   3. Guarda la combinación del caso 1 como plantilla nueva
 *      "[PRUEBA DEV] Armador — caso 1", confirma que quedó bien guardada,
 *      y la borra — no debe quedar basura de prueba en la base.
 *
 * Uso:
 *   php app/scripts/prueba_armador_libre.php
 */

require __DIR__ . '/_arranque.php';
require RUTA_APP . '/core/Auth.php';
require RUTA_APP . '/servicios/PlantillaServicio.php';
require RUTA_APP . '/servicios/ArmadorLibreServicio.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Armador libre de coberturas — Fase 1 backend (PRODUCCIÓN)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$veh = CatalogoServicio::vehiculo('AUT', 'HO', '06', '14', 2015);
if ($veh === null || $veh['clavemarca'] !== 'AUTHO0614') {
    fwrite(STDERR, "No se encontró el vehículo base (AUTHO0614, 2015).\n");
    exit(1);
}

$edadConductor = 43;
$fBase = [
    'tipo_vehiculo'        => $veh['tipo_vehiculo'],
    'armadora'             => $veh['armadora'],
    'carroceria'           => $veh['carroceria'],
    'version'              => $veh['version'],
    'modelo'               => $veh['modelo'],
    'procedencia'          => 'Residentes',
    'tipo_persona'         => 'F',
    'contratante_rfc'      => '',
    'contratante_cp'       => '76000',
    'contratante_edad'     => 50,
    'conductor_nacimiento' => (string) ((int) date('Y') - $edadConductor) . '0101',
    'conductor_sexo'       => 'M',
    'conductor_edad'       => $edadConductor,
    'conductor_cp'         => '04200',
    'correo'               => 'prueba@equinox.com.mx',
    'periodicidad'         => 'A',
];

$ok = true;

// ── Caso 1: partir de Amplia Plus, quitar Siempre en Agencia, agregar Robo Parcial ──
echo "── Caso 1: Equinox Amplia Plus (id=75) − Siempre en Agencia + Robo Parcial (ded. 10%) ──\n";

$partida1 = PlantillaServicio::puntoDePartida('PRS0009355', 75);
if (!$partida1['ok']) {
    fwrite(STDERR, "No se pudo obtener el punto de partida: {$partida1['mensaje']}\n");
    exit(1);
}
echo 'Punto de partida (plantilla 75): ' . count($partida1['coberturas']) . " coberturas\n";

// Hallazgo real de la primera corrida de esta prueba (11-sep-2026): Amplia
// Plus ya trae "Robo Parcial Plus" (0000001462) por default, y GNP rechaza
// (clave 37) pedir "Robo Parcial" (0000001461) junto con ella -- son
// excluyentes entre sí, algo que no estaba en cat_coberturas_excluyentes
// hasta ahora. Ya se agregó esa regla a Esquema.php::semillas(), así que
// validarCoberturas() la detecta localmente sin gastar la llamada -- por
// eso aquí también se quita Robo Parcial Plus al agregar Robo Parcial,
// igual que tendría que hacerlo un vendedor de verdad armando esto a mano.
$combinacion1 = array_values(array_filter(
    $partida1['coberturas'],
    static fn (array $c): bool => !in_array($c['cve'], ['0000001473', '0000001462'], true)
));
$combinacion1[] = ['cve' => '0000001461', 'suma' => '', 'deducible' => '10']; // Robo Parcial
echo 'Combinación armada: ' . count($combinacion1) . " coberturas (quitadas Siempre en Agencia y Robo Parcial Plus, agregada Robo Parcial)\n\n";

$f1 = $fBase + ['nombres' => 'PRUEBA', 'apellido_paterno' => 'ARMADOR', 'apellido_materno' => 'CASO1'];
$r1 = ArmadorLibreServicio::cotizar($f1, 'PRS0009355', $combinacion1);

echo 'ok: ' . ($r1['ok'] ? 'true' : 'false') . "\n";
echo "cotizacion_id: {$r1['cotizacion_id']}\n";
echo "mensaje: {$r1['mensaje']}\n";

if ($r1['ok']) {
    $res = Db::uno('SELECT * FROM cot_resultados WHERE cotizacion_id = ? LIMIT 1', [$r1['cotizacion_id']]);
    $covs = Db::todos('SELECT * FROM cot_resultado_coberturas WHERE resultado_id = ? ORDER BY orden', [$res['id']]);
    echo 'TOTAL_PAGAR: ' . number_format((float) $res['total_pagar'], 2) . "\n";
    $tieneRoboParcial = false; $tieneAgencia = false; $tieneRoboParcialPlus = false;
    foreach ($covs as $c) {
        echo "   {$c['cve_cobertura']}  {$c['nombre']}  suma={$c['suma_asegurada']}  ded={$c['deducible']}\n";
        if ($c['cve_cobertura'] === '0000001461') { $tieneRoboParcial = true; }
        if ($c['cve_cobertura'] === '0000001473') { $tieneAgencia = true; }
        if ($c['cve_cobertura'] === '0000001462') { $tieneRoboParcialPlus = true; }
    }
    echo "\n¿Robo Parcial aparece en la respuesta? " . ($tieneRoboParcial ? 'SÍ, correcto' : 'NO — revisar, debería estar') . "\n";
    echo "¿Siempre en Agencia aparece? " . (!$tieneAgencia ? 'NO, correcto — se quitó a propósito' : 'SÍ — revisar, no debería estar') . "\n";
    echo "¿Robo Parcial Plus aparece? " . (!$tieneRoboParcialPlus ? 'NO, correcto — se quitó por ser excluyente con Robo Parcial' : 'SÍ — revisar') . "\n";
    $ok = $ok && $tieneRoboParcial && !$tieneAgencia && !$tieneRoboParcialPlus;
} else {
    echo "FALLÓ — revisar mensaje arriba.\n";
    $ok = false;
}
echo "\n";

// ── Caso 2: partir del paquete Limitada vacío (sin plantilla), agregar sólo Robo Total ──
echo "── Caso 2: paquete Limitada, sin plantilla de partida, + Robo Total (ded. 5%) ──\n";

$partida2 = PlantillaServicio::puntoDePartida('PRS0009356', null);
echo 'Punto de partida (sin plantilla): ' . count($partida2['coberturas']) . " coberturas (debe ser 0)\n";

$combinacion2 = [['cve' => '0000000916', 'suma' => '', 'deducible' => '5']]; // Robo Total
$f2 = $fBase + ['nombres' => 'PRUEBA', 'apellido_paterno' => 'ARMADOR', 'apellido_materno' => 'CASO2'];
$r2 = ArmadorLibreServicio::cotizar($f2, 'PRS0009356', $combinacion2);

echo 'ok: ' . ($r2['ok'] ? 'true' : 'false') . "\n";
echo "cotizacion_id: {$r2['cotizacion_id']}\n";
echo "mensaje: {$r2['mensaje']}\n";

if ($r2['ok']) {
    $res = Db::uno('SELECT * FROM cot_resultados WHERE cotizacion_id = ? LIMIT 1', [$r2['cotizacion_id']]);
    $covs = Db::todos('SELECT * FROM cot_resultado_coberturas WHERE resultado_id = ? ORDER BY orden', [$res['id']]);
    echo 'TOTAL_PAGAR: ' . number_format((float) $res['total_pagar'], 2) . "\n";
    $roboTotal = null;
    foreach ($covs as $c) {
        echo "   {$c['cve_cobertura']}  {$c['nombre']}  suma={$c['suma_asegurada']}  ded={$c['deducible']}\n";
        if ($c['cve_cobertura'] === '0000000916') { $roboTotal = $c; }
    }
    // GNP devuelve el deducible ya convertido a importe ("$ 6,495"), no el
    // "5" (%) que se pidió -- se compara contra el 5% de la suma asegurada
    // que la propia respuesta trae, no contra el literal que se mandó.
    $sumaNum = $roboTotal !== null ? (float) str_replace([',', '$', ' '], '', (string) $roboTotal['suma_asegurada']) : 0.0;
    $dedNum  = $roboTotal !== null ? (float) str_replace([',', '$', ' '], '', (string) $roboTotal['deducible']) : 0.0;
    $dedOk = $roboTotal !== null && abs($dedNum - $sumaNum * 0.05) < 1.0;
    echo "\n¿Robo Total aparece con deducible del 5% de la suma (\${$sumaNum} x 5% = \$" . number_format($sumaNum * 0.05, 2) . ")? "
       . ($dedOk ? "SÍ, correcto (\${$dedNum})" : 'NO — revisar') . "\n";
    $ok = $ok && $dedOk;
} else {
    echo "FALLÓ — revisar mensaje arriba.\n";
    $ok = false;
}
echo "\n";

// ── Caso 3: guardar la combinación del caso 1 como plantilla nueva, y borrarla ──
echo "── Caso 3: guardar la combinación del caso 1 como plantilla nueva ──\n";

$nombrePrueba = '[PRUEBA DEV] Armador — caso 1';
$rGuardar = PlantillaServicio::guardar(null, $nombrePrueba, 'PRS0009355', true, $combinacion1);
echo 'Guardar: ' . var_export($rGuardar, true) . "\n";

if ($rGuardar['ok']) {
    $idNueva = $rGuardar['id'];
    $guardada = PlantillaServicio::obtener($idNueva);
    $numCoberturas = $guardada !== null ? count($guardada['coberturas']) : 0;
    echo "Plantilla guardada con id={$idNueva}, {$numCoberturas} coberturas (esperado: " . count($combinacion1) . ")\n";
    $cvesGuardadas = array_column($guardada['coberturas'] ?? [], 'cve_cobertura');
    $tieneRoboParcial = in_array('0000001461', $cvesGuardadas, true);
    $tieneAgencia     = in_array('0000001473', $cvesGuardadas, true);
    echo '¿Tiene Robo Parcial guardado? ' . ($tieneRoboParcial ? 'SÍ, correcto' : 'NO — revisar') . "\n";
    echo '¿NO tiene Siempre en Agencia? ' . (!$tieneAgencia ? 'SÍ (correcto, no la tiene)' : 'NO — la tiene, revisar') . "\n";
    $ok = $ok && $numCoberturas === count($combinacion1) && $tieneRoboParcial && !$tieneAgencia;

    Db::ejecutar('DELETE FROM cat_plantillas WHERE id = ?', [$idNueva]);
    $sigueExistiendo = Db::valor('SELECT id FROM cat_plantillas WHERE id = ?', [$idNueva]);
    echo '¿Se borró la plantilla de prueba? ' . ($sigueExistiendo === null ? 'SÍ, limpia' : 'NO — quedó basura, revisar') . "\n";
    $ok = $ok && $sigueExistiendo === null;
} else {
    echo "FALLÓ al guardar — revisar mensaje arriba.\n";
    $ok = false;
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo $ok ? " TODO CORRECTO\n" : " HAY ALGO QUE REVISAR — ver arriba\n";
echo "═══════════════════════════════════════════════════════════════════\n";
