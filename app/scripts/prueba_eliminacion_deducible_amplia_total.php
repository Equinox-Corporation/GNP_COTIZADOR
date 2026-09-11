#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_eliminacion_deducible_amplia_total.php — "Eliminación de Deducible
 * en Pérdidas Parciales" (0000001689) no apareció en el control de Amplia
 * Total con el Honda Fit 2015 (ADR-008). ¿Es un límite de antigüedad, como
 * "Siempre en Agencia" (docs/02.12), o un error real de cat_coberturas?
 *
 * Repite el mismo control (Amplia Total, sin <COBERTURAS>) con un vehículo
 * más nuevo. AUTHO0614 (Fit) no tiene versión 2022+ en el catálogo local —
 * se usa AUTHO0218 (Honda Civic I-Style), la misma clave ya usada para
 * acotar el límite de "Siempre en Agencia", por año, empezando por el más
 * nuevo disponible (2026) y acotando hacia abajo si hace falta.
 *
 * Uso:
 *   php app/scripts/prueba_eliminacion_deducible_amplia_total.php <modelo>
 */

require __DIR__ . '/_arranque.php';

$modelo = (int) ($argv[1] ?? 2026);

echo "═══════════════════════════════════════════════════════════════════\n";
echo " ¿'Eliminación de Deducible' aparece en Amplia Total con modelo {$modelo}? (PRODUCCIÓN)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$gnp = cliente();

$veh = CatalogoServicio::vehiculo('AUT', 'HO', '02', '18', $modelo);
if ($veh === null || $veh['clavemarca'] !== 'AUTHO0218') {
    fwrite(STDERR, "No se encontró AUTHO0218 modelo {$modelo}.\n");
    exit(1);
}

$inicio = new DateTimeImmutable('today');
$fin    = $inicio->modify('+1 year');
$edadConductor = 43;

$datosBase = [
    'vigencia_inicio'      => $inicio->format('Ymd'),
    'vigencia_fin'         => $fin->format('Ymd'),
    'periodicidad'         => 'A',
    'sub_ramo'             => '01',
    'tipo_vehiculo'        => $veh['tipo_vehiculo'],
    'modelo'               => $veh['modelo'],
    'armadora'             => $veh['armadora'],
    'carroceria'           => $veh['carroceria'],
    'version'              => $veh['version'],
    'tipo_persona'         => 'F',
    'nombres'              => '',
    'apellido_paterno'     => '',
    'apellido_materno'     => '',
    'contratante_edad'     => 50,
    'contratante_rfc'      => '',
    'contratante_cp'       => '76000',
    'conductor_nacimiento' => (string) ((int) date('Y') - $edadConductor) . '0101',
    'conductor_sexo'       => 'M',
    'conductor_edad'       => $edadConductor,
    'conductor_cp'         => '04200',
];

$dirEvidencia = RUTA_BASE . '/datos/evidencia_ADR-008_piso_minimo';
if (!is_dir($dirEvidencia)) {
    mkdir($dirEvidencia, 0777, true);
}

$paqueteNombre = (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', ['PRS0054748']) ?? '');
$paquete = ['cve' => 'PRS0054748', 'desc' => ucwords(mb_strtolower($paqueteNombre, 'UTF-8'))];

$r = $gnp->cotizar($datosBase, [$paquete], []);
bitacora($r, "ADR-008 · Eliminación de Deducible en Amplia Total, modelo {$modelo}");

file_put_contents("{$dirEvidencia}/eliminacion-deducible-modelo{$modelo}-peticion.txt", (string) ($r['xml_entrada'] ?? ''));
file_put_contents("{$dirEvidencia}/eliminacion-deducible-modelo{$modelo}-respuesta.txt", (string) ($r['xml_salida'] ?? ''));

if ($r['estado'] !== GnpClient::OK) {
    echo "GNP RECHAZÓ: [{$r['estado']}] {$r['error']['descripcion']}\n";
    exit(1);
}

$p = $r['paquetes'][0] ?? null;
$coberturas = $p['coberturas'] ?? [];
echo 'TOTAL_PAGAR = ' . number_format((float) ($p['total_pagar'] ?? 0), 2) . "\n";
echo 'Coberturas devueltas (' . count($coberturas) . "):\n";
$tiene = false;
foreach ($coberturas as $cb) {
    $marca = $cb['cve'] === '0000001689' ? '  <<< Eliminación de Deducible' : '';
    echo "   {$cb['cve']}  {$cb['nombre']}  suma={$cb['suma']}{$marca}\n";
    if ($cb['cve'] === '0000001689') { $tiene = true; }
}
echo "\n";
echo $tiene
    ? "-> SÍ aparece con modelo {$modelo}.\n"
    : "-> NO aparece con modelo {$modelo}.\n";
