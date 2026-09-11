#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_juega_y_comparar.php — Prueba de aceptación de JuegaYCompararServicio
 * (ADR-007, Paso 2): comparar Equinox Amplia Plus (id=75) y Equinox Amplia
 * (id=76) en UNA sola cotización, con el Honda Fit 2015 de siempre.
 *
 * Dos cosas puntuales que esta prueba verifica, además de que cotice:
 *
 *   1. Amplia Plus y Amplia comparten el MISMO cve_paquete de GNP
 *      (PRS0009355) — el caso real que forzó cambiar el UNIQUE de
 *      cot_resultados (ver Esquema.php::migrar(), 10-sep-2026). Si el
 *      mapeo por DESC_PAQUETE fallara, esta prueba lo mostraría como un
 *      resultado faltante o mezclado.
 *   2. Sólo Amplia Plus trae "Siempre en Agencia" — Amplia no. Con el Fit
 *      2015 (fuera de rango de antigüedad), NINGÚN paquete comparado
 *      devuelve esa cobertura en la respuesta de GNP, así que la única
 *      forma de que la pantalla la muestre (en vez de desaparecer sin
 *      explicación) es vía cot_resultado_omitidas.
 *
 * Uso:
 *   php app/scripts/prueba_juega_y_comparar.php
 */

require __DIR__ . '/_arranque.php';
require RUTA_APP . '/core/Auth.php';
require RUTA_APP . '/servicios/PlantillaServicio.php';
require RUTA_APP . '/servicios/JuegaYCompararServicio.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Juega y Compara: Equinox Amplia Plus + Equinox Amplia, Honda Fit 2015 (PRODUCCIÓN)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$veh = CatalogoServicio::vehiculo('AUT', 'HO', '06', '14', 2015);
if ($veh === null || $veh['clavemarca'] !== 'AUTHO0614') {
    fwrite(STDERR, "No se encontró el vehículo base (AUTHO0614, 2015).\n");
    exit(1);
}

$edadConductor = 43;
$f = [
    'tipo_vehiculo'        => $veh['tipo_vehiculo'],
    'armadora'             => $veh['armadora'],
    'carroceria'           => $veh['carroceria'],
    'version'              => $veh['version'],
    'modelo'               => $veh['modelo'],
    'procedencia'          => 'Residentes',
    'tipo_persona'         => 'F',
    'nombres'              => 'PRUEBA',
    'apellido_paterno'     => 'JUEGA',
    'apellido_materno'     => 'YCOMPARA',
    'contratante_edad'     => 50,
    'contratante_rfc'      => '',
    'contratante_cp'       => '76000',
    'conductor_nacimiento' => (string) ((int) date('Y') - $edadConductor) . '0101',
    'conductor_sexo'       => 'M',
    'conductor_edad'       => $edadConductor,
    'conductor_cp'         => '04200',
    'correo'               => 'prueba@equinox.com.mx',
    'periodicidad'         => 'A',
];

echo "Cotizando plantillas 75 (Amplia Plus) + 76 (Amplia) con {$veh['clavemarca']} modelo {$veh['modelo']}...\n\n";

$r = JuegaYCompararServicio::cotizar($f, [75, 76]);

echo 'ok: ' . ($r['ok'] ? 'true' : 'false') . "\n";
echo "cotizacion_id: {$r['cotizacion_id']}\n";
echo "mensaje: {$r['mensaje']}\n\n";

if (!$r['ok']) {
    echo "FALLÓ — revisar mensaje arriba.\n";
    exit(1);
}

$resultados = CotizacionServicio::resultados($r['cotizacion_id']);
echo 'Resultados guardados: ' . count($resultados) . "\n\n";
foreach ($resultados as $res) {
    echo "── {$res['paquete']} (plantilla_id={$res['plantilla_id']}, cve_paquete={$res['cve_paquete']}) ──\n";
    echo '   TOTAL_PAGAR: ' . number_format((float) $res['total_pagar'], 2) . "\n";
    echo '   Coberturas devueltas (' . count($res['coberturas']) . '), omitidas (' . count($res['omitidas']) . "):\n";
    foreach ($res['coberturas'] as $c) {
        echo "      [incluida] {$c['cve_cobertura']}  {$c['nombre']}\n";
    }
    foreach ($res['omitidas'] as $o) {
        echo "      [OMITIDA]  {$o['cve_cobertura']}  {$o['nombre']} — {$o['motivo']}\n";
    }
    echo "\n";
}

echo "Verificación puntual:\n";
$porPlantilla = [];
foreach ($resultados as $res) {
    $porPlantilla[(int) $res['plantilla_id']] = $res;
}
$ok1 = isset($porPlantilla[75]) && isset($porPlantilla[76]) && $porPlantilla[75]['cve_paquete'] === $porPlantilla[76]['cve_paquete'];
echo '  Dos resultados distintos guardados pese a compartir cve_paquete: ' . ($ok1 ? 'SÍ, correcto' : 'NO — revisar') . "\n";
$ok2 = count(array_filter($porPlantilla[75]['omitidas'], static fn ($o) => $o['cve_cobertura'] === '0000001473')) === 1;
echo '  Amplia Plus (75) tiene "Siempre en Agencia" registrada como omitida: ' . ($ok2 ? 'SÍ, correcto' : 'NO — revisar') . "\n";
$ok3 = count(array_filter($porPlantilla[76]['coberturas'], static fn ($c) => $c['cve_cobertura'] === '0000001473')) === 0
    && count(array_filter($porPlantilla[76]['omitidas'], static fn ($o) => $o['cve_cobertura'] === '0000001473')) === 0;
echo '  Amplia (76) no tiene ni incluida ni omitida "Siempre en Agencia" (nunca fue parte de ella): ' . ($ok3 ? 'SÍ, correcto' : 'NO — revisar') . "\n";

echo "\nVer también en pantalla: http://localhost/cotizador-gnp/public/?r=resultado&id={$r['cotizacion_id']}\n";
echo "(ahí la fila 'Siempre en Agencia' debe verse como N/A bajo Amplia Plus, y sencillamente ausente bajo Amplia — nunca en blanco sin explicación bajo Amplia Plus.)\n";
