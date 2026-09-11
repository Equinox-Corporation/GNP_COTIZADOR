#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_club_gnp_plus.php — "Club GNP Plus" (0000001687) apareció en el
 * control de Amplia Total (ADR-008) como Básica, pero no existe en
 * absoluto en cat_coberturas. Antes de agregarla al catálogo hay que saber
 * si sólo aplica a Amplia Total o si también es Opcional en otros paquetes
 * — no se adivina la matriz completa con una sola llamada.
 *
 * Prueba: pedirla explícitamente como Opcional sobre Amplia (PRS0009355,
 * el "hermano" de Amplia Total, que hoy trae "Club GNP" 0000001268 plano).
 * Habla con GnpClient directo, sin pasar por PlantillaServicio — el
 * catálogo local todavía no conoce esta clave, así que la validación local
 * la rechazaría antes de preguntarle a GNP.
 *
 * Uso:
 *   php app/scripts/prueba_club_gnp_plus.php
 */

require __DIR__ . '/_arranque.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " ¿'Club GNP Plus' (0000001687) aplica también a Amplia? (PRODUCCIÓN)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$gnp = cliente();

$veh = CatalogoServicio::vehiculo('AUT', 'HO', '06', '14', 2015);
if ($veh === null || $veh['clavemarca'] !== 'AUTHO0614') {
    fwrite(STDERR, "No se encontró el vehículo base (AUTHO0614, 2015).\n");
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

$paqueteNombre = (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', ['PRS0009355']) ?? '');
$paquete = ['cve' => 'PRS0009355', 'desc' => ucwords(mb_strtolower($paqueteNombre, 'UTF-8'))];

// Mismo patrón que las coberturas de estatus fijo sin dimensión configurable
// (Eliminación de Deducible, Siempre en Agencia — docs/02.12): sólo
// CVE_COBERTURA + NOMBRE, sin SUMA_ASEGURADA ni DEDUCIBLE.
$opcionales = [['cve' => '0000001687', 'nombre' => 'Club GNP Plus']];

$r = $gnp->cotizar($datosBase, [$paquete], $opcionales);
bitacora($r, 'ADR-008 · ¿Club GNP Plus (0000001687) aplica a Amplia?');

file_put_contents("{$dirEvidencia}/club-gnp-plus-en-amplia-peticion.txt", (string) ($r['xml_entrada'] ?? ''));
file_put_contents("{$dirEvidencia}/club-gnp-plus-en-amplia-respuesta.txt", (string) ($r['xml_salida'] ?? ''));

echo "Estado: {$r['estado']}\n\n";

if ($r['estado'] !== GnpClient::OK) {
    echo "GNP RECHAZÓ: [{$r['estado']}] {$r['error']['descripcion']}\n";
    echo "CLAVE={$r['error']['clave']}  ORIGEN={$r['error']['origen']}\n\n";
    echo "-> 'Club GNP Plus' NO aplica a Amplia. Es exclusiva de Amplia Total.\n";
} else {
    $p = $r['paquetes'][0] ?? null;
    $coberturas = $p['coberturas'] ?? [];
    echo 'TOTAL_PAGAR = ' . number_format((float) ($p['total_pagar'] ?? 0), 2) . "\n";
    echo 'Coberturas devueltas (' . count($coberturas) . "):\n";
    $reflejada = false;
    foreach ($coberturas as $cb) {
        $marca = $cb['cve'] === '0000001687' ? '  <<< Club GNP Plus' : '';
        echo "   {$cb['cve']}  {$cb['nombre']}  suma={$cb['suma']}{$marca}\n";
        if ($cb['cve'] === '0000001687') { $reflejada = true; }
    }
    echo "\n";
    echo $reflejada
        ? "-> GNP ACEPTÓ 'Club GNP Plus' sobre Amplia y aparece reflejada: SÍ aplica como Opcional en Amplia también.\n"
        : "-> GNP aceptó la llamada (sin error) pero la cobertura NO aparece reflejada — aceptada pero ignorada, tratar como rechazo funcional.\n";
}

echo "\nDocumentar en docs/03_Decisiones/ADR-008-piso-minimo-por-paquete.md.\n";
