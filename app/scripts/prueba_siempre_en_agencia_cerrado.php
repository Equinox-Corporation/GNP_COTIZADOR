#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_siempre_en_agencia_cerrado.php — Prueba de aceptación del cierre del
 * Punto 2 de docs/02.12-bug-amparada.md: "Siempre en Agencia" ya no bloquea
 * la cotización completa cuando el vehículo no cumple la antigüedad — se
 * omite, se avisa, y el resto de las coberturas se cotiza igual.
 *
 * Decisión de Beto: avisar y cotizar sin la cobertura, no bloquear.
 *
 * Caso: Equinox Amplia Plus (id=75) con el Honda Fit 2015 (AUTHO0614) de
 * siempre — el caso que antes fallaba con CLAVE 37 (modelo 2015 < 2026-4=2022).
 *
 * Usa CotizacionServicio::cotizar() — el flujo real, el mismo que usa
 * cotizar.php con "usar plantilla propia" — no una llamada aislada a
 * GnpClient. Es la única forma de probar de verdad que el cierre quedó
 * conectado de punta a punta.
 *
 * Uso:
 *   php app/scripts/prueba_siempre_en_agencia_cerrado.php
 */

require __DIR__ . '/_arranque.php';
require RUTA_APP . '/core/Auth.php';
require RUTA_APP . '/servicios/PlantillaServicio.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Cierre 'Siempre en Agencia': Equinox Amplia Plus + Honda Fit 2015 (PRODUCCIÓN)\n";
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
    'apellido_paterno'     => 'CIERRE',
    'apellido_materno'     => 'AGENCIA',
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

echo "Cotizando plantilla id=75 (Equinox Amplia Plus) con {$veh['clavemarca']} modelo {$veh['modelo']}...\n\n";

$r = CotizacionServicio::cotizar($f, [], [], 75);

echo 'ok: ' . ($r['ok'] ? 'true' : 'false') . "\n";
echo "cotizacion_id: {$r['cotizacion_id']}\n";
echo "mensaje: {$r['mensaje']}\n\n";

if ($r['ok']) {
    $res = Db::uno('SELECT * FROM cot_resultados WHERE cotizacion_id = ? LIMIT 1', [$r['cotizacion_id']]);
    if ($res !== null) {
        echo "── Resultado guardado ──\n";
        echo "Paquete: {$res['paquete']} ({$res['cve_paquete']})\n";
        echo 'TOTAL_PAGAR: ' . number_format((float) $res['total_pagar'], 2) . "\n";
        $covs = Db::todos('SELECT * FROM cot_resultado_coberturas WHERE resultado_id = ? ORDER BY orden', [$res['id']]);
        echo 'Coberturas devueltas (' . count($covs) . "):\n";
        foreach ($covs as $c) {
            echo "   {$c['cve_cobertura']}  {$c['nombre']}  suma={$c['suma_asegurada']}  ded={$c['deducible']}\n";
        }
        $tieneAgencia = false;
        foreach ($covs as $c) {
            if ($c['cve_cobertura'] === '0000001473') {
                $tieneAgencia = true;
            }
        }
        echo "\n¿'Siempre en Agencia' aparece en la respuesta de GNP? " . ($tieneAgencia ? 'SÍ — revisar, no debería' : 'NO, correcto — se omitió') . "\n";
        echo "\nComparación contra 02.12 (Amplia Plus + Honda Civic 2025, con la cobertura incluida): 30,205.27\n";
        echo 'Diferencia: ' . number_format(30205.27 - (float) $res['total_pagar'], 2) . " (nota: vehículos distintos — Civic 2025 vs Fit 2015 — la comparación es orientativa, no una resta exacta de la sola cobertura)\n";
    }
} else {
    echo "FALLÓ — revisar mensaje arriba.\n";
}
