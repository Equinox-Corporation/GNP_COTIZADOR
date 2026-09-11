#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_multipaquete_plantillas.php — ¿Conviven dos <PAQUETE>, cada uno con
 * su propio <COBERTURAS> modificado, en UNA sola llamada?
 *
 * ADR-007 punto 7, hasta ahora [PENDIENTE]. ADR-005 punto 8 ya confirmó que
 * varios paquetes ESTÁNDAR de GNP conviven en una llamada; nunca se había
 * probado con paquetes que traen <COBERTURAS> modificado cada uno.
 *
 * Usa dos plantillas reales ya cargadas y activas:
 *   Equinox Amplia Plus (id=75, PRS0009355/AMPLIA, 13 coberturas)
 *   Equinox Limitada    (id=77, PRS0009356/LIMITADA, 7 coberturas)
 * Cada una resuelta con PlantillaServicio::paraCotizar() — mismo mecanismo
 * de revalidación que ya usa el flujo real de cotización (02.7/02.9).
 *
 * IMPORTANTE: para que cada <PAQUETE> lleve sus propias coberturas, este
 * script requiere el parche TEMPORAL marcado "prueba 02.11" en
 * GnpClient::cotizar() (permite $paquetes[i]['opcionales'] por elemento).
 * Se agrega antes de correr esto y se revierte inmediatamente después —
 * GnpClient.php no debe quedar modificado hasta que Beto vea el resultado y
 * decida cómo se arma la llamada del módulo nuevo.
 *
 * Misma cotización base de siempre. Pasa por GnpClient::cotizar() y queda en
 * sys_llamadas (cotizacion_id NULL), igual que el resto de esta serie.
 *
 * Uso:
 *   php app/scripts/prueba_multipaquete_plantillas.php
 */

require __DIR__ . '/_arranque.php';
require RUTA_APP . '/servicios/PlantillaServicio.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " ¿Dos plantillas, cada una con su <COBERTURAS>, en una sola llamada? (PRODUCCIÓN)\n";
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

// ── Resolver las dos plantillas, mismo mecanismo que el flujo real ─────────
$plantillasIds = ['Equinox Amplia Plus' => 75, 'Equinox Limitada' => 77];
$paquetes = [];
foreach ($plantillasIds as $nombreEsperado => $id) {
    $r = PlantillaServicio::paraCotizar($id);
    if (!$r['ok']) {
        fwrite(STDERR, "No se pudo resolver la plantilla {$id} ({$nombreEsperado}): {$r['mensaje']}\n");
        exit(1);
    }
    $nombrePaquete = (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$r['cve_paquete']]) ?? '');

    // AJUSTES SÓLO PARA ESTA CORRIDA, NO TOCAN LA BASE — dos hallazgos
    // ajenos a la pregunta de multi-paquete, aislados para no mezclarlos con
    // el resultado que se busca:
    //  1. "Amparada" es la palabra de estatus que GNP devuelve para
    //     coberturas fijas (Club GNP, Robo Parcial Plus, etc.), no un valor
    //     de entrada válido — mandarla como SUMA_ASEGURADA literal causó
    //     "For input string: Amparada".
    //  2. Siempre en Agencia (0000001473) fue rechazada por GNP como "no
    //     aplica para el vehículo cotizado" — restricción real de negocio
    //     (antigüedad del Honda Fit 2015, lo más probable), no un problema
    //     de formato. Se quita por completo de esta prueba.
    $coberturas = array_values(array_filter(array_map(static function (array $c): ?array {
        if ($c['cve'] === '0000001473') {
            return null;
        }
        if (($c['suma'] ?? '') === 'Amparada') {
            $c['suma'] = '';
        }
        return $c;
    }, $r['coberturas'])));

    $paquetes[] = [
        'cve'        => $r['cve_paquete'],
        'desc'       => $nombreEsperado, // el nombre de la plantilla identifica cuál es cuál en la respuesta
        'opcionales' => $coberturas,
    ];
    echo "Resuelta: {$nombreEsperado} -> {$r['cve_paquete']} ({$nombrePaquete}), " . count($r['coberturas']) . " coberturas\n";
}
echo "\n";

$dirEvidencia = RUTA_BASE . '/datos/evidencia_02.11_multipaquete_plantillas';
if (!is_dir($dirEvidencia)) {
    mkdir($dirEvidencia, 0777, true);
}

$sufijo = '02-multipaquete-limpio';
echo "── Llamada única con los dos <PAQUETE> (Amparada y Siempre en Agencia ya aisladas) ──\n";
$r = $gnp->cotizar($datosBase, $paquetes);
bitacora($r, 'prueba 02.11 · dos plantillas en una llamada (Amplia Plus + Limitada), tercer intento limpio');

file_put_contents("{$dirEvidencia}/{$sufijo}-peticion.txt", (string) ($r['xml_entrada'] ?? ''));
file_put_contents("{$dirEvidencia}/{$sufijo}-respuesta.txt", (string) ($r['xml_salida'] ?? ''));

echo "Estado: {$r['estado']}  HTTP: " . ($r['http'] ?? '?') . "\n";

if ($r['estado'] !== GnpClient::OK) {
    echo "GNP RECHAZÓ la llamada completa.\n";
    echo "CLAVE={$r['error']['clave']}  ORIGEN={$r['error']['origen']}\n";
    echo "DESC: {$r['error']['descripcion']}\n";
} else {
    echo 'Paquetes devueltos: ' . count($r['paquetes']) . " (se pidieron " . count($paquetes) . ")\n\n";
    foreach ($r['paquetes'] as $p) {
        echo "── {$p['desc']} ({$p['cve']}) ──\n";
        echo '   TOTAL_PAGAR = ' . number_format((float) ($p['total_pagar'] ?? 0), 2) . "\n";
        echo '   Coberturas devueltas (' . count($p['coberturas']) . "):\n";
        foreach ($p['coberturas'] as $cb) {
            echo "      {$cb['cve']}  {$cb['nombre']}  suma={$cb['suma']}  ded={$cb['ded']}\n";
        }
        echo "\n";
    }

    // ¿Se contaminaron entre sí? Cada paquete debe traer SÓLO sus propias
    // coberturas modificadas, no las de la otra plantilla.
    $clavesPorPaquete = [];
    foreach ($r['paquetes'] as $p) {
        $clavesPorPaquete[$p['cve']] = array_column($p['coberturas'], 'cve');
    }
    echo "Verificación de contaminación cruzada:\n";
    $clavesEsperadas75 = array_column($paquetes[0]['opcionales'], 'cve');
    $clavesEsperadas77 = array_column($paquetes[1]['opcionales'], 'cve');
    echo '   Amplia Plus debía traer Robo Parcial Plus (0000001462) y Ayuda Llantas/Rines (0000001470) — exclusivas de Plus: ';
    $cvesAmpliaPlus = $clavesPorPaquete[$paquetes[0]['cve']] ?? [];
    echo (in_array('0000001462', $cvesAmpliaPlus, true) && in_array('0000001470', $cvesAmpliaPlus, true)) ? "SÍ\n" : "NO — revisar\n";
    echo '   Limitada NO debía traer esas dos claves (no están en su plantilla): ';
    $cvesLimitada = $clavesPorPaquete[$paquetes[1]['cve']] ?? [];
    echo (!in_array('0000001462', $cvesLimitada, true) && !in_array('0000001470', $cvesLimitada, true)) ? "correcto, no aparecen\n" : "SÍ aparecen — CONTAMINACIÓN CRUZADA\n";
}

echo "\nEvidencia: {$dirEvidencia}/{$sufijo}-peticion.txt / {$sufijo}-respuesta.txt\n";
echo "RECORDATORIO: revertir el parche TEMPORAL de GnpClient.php ahora.\n";
echo "Documentar en docs/02.11-multipaquete-plantillas.md.\n";
