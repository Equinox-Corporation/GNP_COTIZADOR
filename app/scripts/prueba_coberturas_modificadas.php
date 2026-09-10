#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_coberturas_modificadas.php — La prueba de fuego del módulo Juega y Compara.
 *
 * Confirma, contra PRODUCCIÓN, si GNP acepta un <COBERTURAS> modificado dentro
 * de un <PAQUETE> y tarifica correctamente. Ver ADR-007, punto 2 (bloqueante).
 *
 * Corre CUATRO cotizaciones reales (cotizar NO emite póliza, sólo tarifica):
 *
 *   00-control   Honda Fit / Amplia, <COBERTURAS/> vacío — la referencia.
 *   01-caso1     Igual, pero Gastos Médicos Ocupantes (0000000906) a 300,000
 *                — valor que el negocio marcó como permitido para esa clave.
 *   02-caso2     Igual, pero a 250,000 — valor que el negocio marcó como
 *                fuera de la lista permitida. Se espera que GNP la rechace.
 *   03-caso3     Igual, pero con una cobertura que no pertenece a Amplia/AUTO
 *                en absoluto (ver nota más abajo sobre por qué es una moto).
 *
 * Cada llamada pasa por GnpClient::cotizar() y queda en sys_llamadas igual que
 * cualquier otra (cotizacion_id NULL, mismo patrón que los demás scripts de
 * catálogo). NO se toca cot_cotizaciones, cot_resultados ni cot_opcionales:
 * esta es una prueba de protocolo, no una cotización de negocio, y cot_opcionales
 * tiene su diseño real pendiente de revisión (ADR-007 punto 5).
 *
 * La evidencia de entrada/salida de cada caso se guarda aparte, en
 * datos/evidencia_02.6_coberturas_modificadas/, con la contraseña ya
 * enmascarada por GnpClient (ADR-006).
 *
 * Nota sobre el Caso 3: en cat_coberturas, TODAS las coberturas de AUTO
 * (27 claves) aparecen en la matriz de Amplia — ninguna está marcada N/A ahí.
 * Amplia, Amplia Total, Premium y Auto Elite comparten exactamente el mismo
 * conjunto de claves; sólo cambia si son BASICA u OPCIONAL. No hay, en los
 * datos ya cargados, una cobertura que sea Básica en Premium y N/A en Amplia
 * como sugiere el ejemplo del ADR-007. Para no inventar una clave que no
 * existe, el Caso 3 usa una cobertura real de GNP pero de otro grupo:
 * Asistencia Vial Moto (0000001567), que sólo aplica a motocicletas y no
 * aparece en ninguna fila de AUTO. Es la forma más honesta, con los datos que
 * hay hoy, de probar "cobertura fuera del paquete/tipo de vehículo base".
 *
 * Uso:
 *   php app/scripts/prueba_coberturas_modificadas.php
 */

require __DIR__ . '/_arranque.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Prueba de fuego — <COBERTURAS> modificado contra GNP (PRODUCCIÓN)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$gnp = cliente();

// ── Vehículo y persona base: Honda Fit FUN 1.5 AUT 2015 (clavemarca AUTHO0614) ──
$veh = CatalogoServicio::vehiculo('AUT', 'HO', '06', '14', 2015);
if ($veh === null) {
    fwrite(STDERR, "No se encontró el vehículo base (AUTHO0614, 2015) en cat_vehiculos.\n");
    fwrite(STDERR, "¿Corrió ya el ETL de vehículos? Sin él no se puede armar la prueba.\n");
    exit(1);
}
if ($veh['clavemarca'] !== 'AUTHO0614') {
    fwrite(STDERR, "El vehículo encontrado no tiene la clavemarca esperada (AUTHO0614): {$veh['clavemarca']}\n");
    exit(1);
}

$paqueteNombre = (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', ['PRS0009355']) ?? '');
if ($paqueteNombre === '') {
    fwrite(STDERR, "No se encontró el paquete PRS0009355 en cat_paquetes.\n");
    exit(1);
}
$paqueteDesc = ucwords(mb_strtolower($paqueteNombre, 'UTF-8'));

$inicio = new DateTimeImmutable('today');
$fin    = $inicio->modify('+1 year');
$edadConductor = 43;

$datosBase = [
    'vigencia_inicio'      => $inicio->format('Ymd'),
    'vigencia_fin'         => $fin->format('Ymd'),
    'periodicidad'         => 'A',
    'sub_ramo'             => '01', // Residentes, la única procedencia verificada (ADR-005 punto 11)
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

$paquete = ['cve' => 'PRS0009355', 'desc' => $paqueteDesc];

echo "Base:\n";
echo '   ' . pad('Vehículo', 16) . $veh['clavemarca'] . ' · ' . $veh['carroceria_nombre'] . ' ' . $veh['version_nombre'] . ' ' . $veh['modelo'] . "\n";
echo '   ' . pad('Paquete', 16) . $paquete['cve'] . ' · ' . $paquete['desc'] . "\n";
echo '   ' . pad('Contratante', 16) . "PF, 50 años, CP 76000\n";
echo '   ' . pad('Conductor', 16) . "43 años, CP 04200, nacimiento {$datosBase['conductor_nacimiento']}\n";
echo '   ' . pad('Vigencia', 16) . "{$datosBase['vigencia_inicio']} a {$datosBase['vigencia_fin']}, anual\n\n";

$dirEvidencia = RUTA_BASE . '/datos/evidencia_02.6_coberturas_modificadas';
if (!is_dir($dirEvidencia)) {
    mkdir($dirEvidencia, 0777, true);
}

/**
 * Corre un caso, lo registra en sys_llamadas y guarda su evidencia.
 *
 * @param list<array{cve:string,nombre?:string,suma?:string}> $opcionales
 */
function correrCaso(
    string $id,
    string $titulo,
    GnpClient $gnp,
    array $datosBase,
    array $paquete,
    array $opcionales,
    string $dirEvidencia
): array {
    echo "── {$id} · {$titulo} " . str_repeat('─', max(0, 60 - strlen($id) - strlen($titulo))) . "\n";

    $r = $gnp->cotizar($datosBase, [$paquete], $opcionales);
    bitacora($r, "prueba 02.6 · {$id} · {$titulo}");

    file_put_contents("{$dirEvidencia}/{$id}-peticion.txt", (string) ($r['xml_entrada'] ?? ''));
    file_put_contents("{$dirEvidencia}/{$id}-respuesta.txt", (string) ($r['xml_salida'] ?? ''));

    $resultado = [
        'id'          => $id,
        'titulo'      => $titulo,
        'estado'      => $r['estado'],
        'http'        => $r['http'] ?? 0,
        'error'       => $r['error'] ?? null,
        'total_pagar' => null,
        'coberturas'  => [],
    ];

    if ($r['estado'] === GnpClient::OK) {
        $p = $r['paquetes'][0] ?? null;
        if ($p !== null) {
            $resultado['total_pagar'] = $p['total_pagar'];
            $resultado['coberturas']  = $p['coberturas'];
        }
        echo "   GNP aceptó la llamada. TOTAL_PAGAR = " . ($p['total_pagar'] !== null ? number_format((float) $p['total_pagar'], 2) : '(sin dato)') . "\n";
    } else {
        echo "   GNP respondió con error: [{$r['estado']}] " . ($r['error']['descripcion'] ?? '(sin descripción)') . "\n";
        echo "   CLAVE={$resultado['error']['clave']}  ORIGEN={$resultado['error']['origen']}\n";
    }
    echo "   Evidencia: {$dirEvidencia}/{$id}-peticion.txt / {$id}-respuesta.txt\n\n";

    return $resultado;
}

// ── 00 · Control: la misma cotización, sin <COBERTURAS> ─────────────────────
$control = correrCaso('00-control', 'Amplia sin COBERTURAS (referencia)', $gnp, $datosBase, $paquete, [], $dirEvidencia);

// ── 01 · Caso 1: cambio válido — GMO de 200,000 (default) a 300,000 ─────────
$caso1 = correrCaso(
    '01-caso1',
    'GMO (0000000906) 200,000 → 300,000',
    $gnp, $datosBase, $paquete,
    [['cve' => '0000000906', 'nombre' => 'Gastos Médicos Ocupantes', 'suma' => '300000']],
    $dirEvidencia
);

// ── 02 · Caso 2: combinación inválida a propósito — 250,000 no está en la lista ─
$caso2 = correrCaso(
    '02-caso2',
    'GMO (0000000906) a 250,000 (fuera de la lista permitida)',
    $gnp, $datosBase, $paquete,
    [['cve' => '0000000906', 'nombre' => 'Gastos Médicos Ocupantes', 'suma' => '250000']],
    $dirEvidencia
);

// ── 03 · Caso 3: cobertura fuera del paquete/tipo de vehículo base ──────────
$caso3 = correrCaso(
    '03-caso3',
    'Asistencia Vial Moto (0000001567) en un paquete de AUTO',
    $gnp, $datosBase, $paquete,
    [['cve' => '0000001567', 'nombre' => 'Asistencia Vial Moto']],
    $dirEvidencia
);

// ── Resumen ──────────────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════════════\n";
echo " Resumen\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo pad('Caso', 12) . pad('Estado', 10) . pad('TOTAL_PAGAR', 14) . "Detalle\n";
echo str_repeat('─', 90), "\n";
foreach ([$control, $caso1, $caso2, $caso3] as $c) {
    $tp = $c['total_pagar'] !== null ? number_format((float) $c['total_pagar'], 2) : '—';
    $detalle = $c['estado'] === GnpClient::OK
        ? ''
        : trim(($c['error']['clave'] ?? '') . '/' . ($c['error']['origen'] ?? '') . ' ' . ($c['error']['descripcion'] ?? ''));
    echo pad($c['id'], 12) . pad($c['estado'], 10) . pad($tp, 14) . $detalle . "\n";
}
echo "\n";

if ($control['estado'] === GnpClient::OK && $caso1['estado'] === GnpClient::OK) {
    $gmoCaso1 = null;
    foreach ($caso1['coberturas'] as $cb) {
        if ($cb['cve'] === '0000000906') {
            $gmoCaso1 = $cb;
            break;
        }
    }
    echo "Caso 1 — ¿la cobertura modificada aparece reflejada en la respuesta?\n";
    echo $gmoCaso1 !== null
        ? "   Sí: GMO vuelve con suma_asegurada = {$gmoCaso1['suma']}\n"
        : "   No: la respuesta no trae la clave 0000000906 en su bloque de coberturas.\n";
    echo "Caso 1 — ¿cambió TOTAL_PAGAR de forma coherente respecto al control?\n";
    $delta = (float) $caso1['total_pagar'] - (float) $control['total_pagar'];
    echo '   Control: ' . number_format((float) $control['total_pagar'], 2)
       . '  →  Caso 1: ' . number_format((float) $caso1['total_pagar'], 2)
       . '  (diferencia: ' . number_format($delta, 2) . ")\n";
}

echo "\nSiguiente paso: documentar esto en docs/02.6-coberturas-modificadas.md\n";
echo "y actualizar ADR-007 (puntos 2 y 3) con el resultado.\n";
