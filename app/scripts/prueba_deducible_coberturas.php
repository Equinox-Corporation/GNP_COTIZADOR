#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_deducible_coberturas.php — ¿GNP acepta <DEDUCIBLE> dentro de <COBERTURA>?
 *
 * Responde la pregunta que quedó abierta en docs/02.7-plantillas-conectadas.md:
 * el deducible de una plantilla se guarda en cot_opcionales pero nunca se ha
 * transmitido a GNP, porque GnpClient.php nunca tuvo la etiqueta. Antes de
 * escribir esa línea de código, se prueba si GNP la acepta — mismo orden que
 * se siguió con <COBERTURAS> modificado (02.6 antes de conectar 02.7).
 *
 * IMPORTANTE: para poder mandar <DEDUCIBLE>, este script requiere que
 * GnpClient.php tenga temporalmente el bloque marcado "TEMPORAL — prueba 02.8"
 * en el armado de <COBERTURA>. Se agrega antes de correr este script y se
 * revierte inmediatamente después — GnpClient.php no debe quedar modificado
 * de forma permanente hasta que se decida, con el resultado en la mano, si el
 * soporte a DEDUCIBLE se queda de verdad (ver docs/02.8-deducible-en-coberturas.md).
 *
 * Usa Daños Materiales Pérdida Total (0000001288): Básica en Amplia, ya
 * incluida por default, y su único valor configurable es el deducible
 * (0/3/5/10%, cat_cobertura_valores) — no hace falta agregarla como opcional,
 * sólo intentar sobreescribir su deducible por default (5% para Amplia/AUTO).
 *
 * Misma cotización base de siempre. Cada llamada pasa por GnpClient::cotizar()
 * y queda en sys_llamadas (cotizacion_id NULL), igual que 02.6.
 *
 * Uso:
 *   php app/scripts/prueba_deducible_coberturas.php
 */

require __DIR__ . '/_arranque.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " ¿GNP acepta <DEDUCIBLE> dentro de <COBERTURA>? (PRODUCCIÓN)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$gnp = cliente();

$veh = CatalogoServicio::vehiculo('AUT', 'HO', '06', '14', 2015);
if ($veh === null || $veh['clavemarca'] !== 'AUTHO0614') {
    fwrite(STDERR, "No se encontró el vehículo base (AUTHO0614, 2015).\n");
    exit(1);
}

$paqueteNombre = (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', ['PRS0009355']) ?? '');
$paquete = ['cve' => 'PRS0009355', 'desc' => ucwords(mb_strtolower($paqueteNombre, 'UTF-8'))];

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

$dirEvidencia = RUTA_BASE . '/datos/evidencia_02.8_deducible';
if (!is_dir($dirEvidencia)) {
    mkdir($dirEvidencia, 0777, true);
}

/** @param list<array{cve:string,nombre?:string,suma?:string,deducible?:string}> $opcionales */
function correrCaso(string $id, string $titulo, GnpClient $gnp, array $datosBase, array $paquete, array $opcionales, string $dirEvidencia): array
{
    echo "── {$id} · {$titulo} " . str_repeat('─', max(0, 55 - strlen($id) - strlen($titulo))) . "\n";

    $r = $gnp->cotizar($datosBase, [$paquete], $opcionales);
    bitacora($r, "prueba 02.8 · {$id} · {$titulo}");

    file_put_contents("{$dirEvidencia}/{$id}-peticion.txt", (string) ($r['xml_entrada'] ?? ''));
    file_put_contents("{$dirEvidencia}/{$id}-respuesta.txt", (string) ($r['xml_salida'] ?? ''));

    $resultado = ['id' => $id, 'titulo' => $titulo, 'estado' => $r['estado'], 'total_pagar' => null, 'deducible_devuelto' => null, 'error' => $r['error'] ?? null];

    if ($r['estado'] === GnpClient::OK) {
        $p = $r['paquetes'][0] ?? null;
        if ($p !== null) {
            $resultado['total_pagar'] = $p['total_pagar'];
            foreach ($p['coberturas'] as $cb) {
                if ($cb['cve'] === '0000001288') {
                    $resultado['deducible_devuelto'] = $cb['ded'];
                    $resultado['suma_devuelta'] = $cb['suma'];
                }
            }
        }
        echo "   GNP aceptó. TOTAL_PAGAR = " . ($resultado['total_pagar'] !== null ? number_format((float) $resultado['total_pagar'], 2) : '(sin dato)') . "\n";
        echo "   Daños Materiales Pérdida Total → DEDUCIBLE devuelto: {$resultado['deducible_devuelto']}  (suma: {$resultado['suma_devuelta']})\n";
    } else {
        echo "   GNP respondió con error: [{$r['estado']}] " . ($r['error']['descripcion'] ?? '(sin descripción)') . "\n";
        echo "   CLAVE={$resultado['error']['clave']}  ORIGEN={$resultado['error']['origen']}\n";
    }
    echo "   Evidencia: {$dirEvidencia}/{$id}-peticion.txt / {$id}-respuesta.txt\n\n";

    return $resultado;
}

// ── 00 · Control: la misma cotización, sin tocar <COBERTURAS> ──────────────
$control = correrCaso('00-control', 'Amplia sin COBERTURAS (deducible por default)', $gnp, $datosBase, $paquete, [], $dirEvidencia);

// ── A · Deducible válido, distinto al default (5% en Amplia/AUTO) ─────────
$casoA = correrCaso(
    '01-caso-a',
    'Daños Mat. Pérdida Total: deducible 5% → 10%',
    $gnp, $datosBase, $paquete,
    [['cve' => '0000001288', 'nombre' => 'Daños Materiales Pérdida Total', 'deducible' => '10']],
    $dirEvidencia
);

// ── B · Deducible fuera de la lista permitida (0/3/5/10) ───────────────────
$casoB = correrCaso(
    '02-caso-b',
    'Daños Mat. Pérdida Total: deducible 7% (fuera de lista)',
    $gnp, $datosBase, $paquete,
    [['cve' => '0000001288', 'nombre' => 'Daños Materiales Pérdida Total', 'deducible' => '7']],
    $dirEvidencia
);

// ── Resumen ──────────────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════════════\n";
echo " Resumen\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo pad('Caso', 12) . pad('Estado', 10) . pad('TOTAL_PAGAR', 14) . pad('DEDUCIBLE resp.', 18) . "Detalle\n";
echo str_repeat('─', 100), "\n";
foreach ([$control, $casoA, $casoB] as $c) {
    $tp  = $c['total_pagar'] !== null ? number_format((float) $c['total_pagar'], 2) : '—';
    $ded = $c['deducible_devuelto'] ?? '—';
    $detalle = $c['estado'] === GnpClient::OK ? '' : trim(($c['error']['clave'] ?? '') . '/' . ($c['error']['origen'] ?? '') . ' ' . ($c['error']['descripcion'] ?? ''));
    echo pad($c['id'], 12) . pad($c['estado'], 10) . pad($tp, 14) . pad($ded, 18) . $detalle . "\n";
}

echo "\n";
if ($control['estado'] === GnpClient::OK && $casoA['estado'] === GnpClient::OK) {
    $cambio = $casoA['deducible_devuelto'] !== $control['deducible_devuelto'];
    echo "Caso A — ¿el deducible devuelto cambió respecto al control?\n";
    echo "   Control: {$control['deducible_devuelto']}  →  Caso A: {$casoA['deducible_devuelto']}  →  " . ($cambio ? "SÍ cambió\n" : "NO cambió (aceptada pero IGNORADA)\n");
    $deltaPagar = (float) $casoA['total_pagar'] - (float) $control['total_pagar'];
    echo "Caso A — TOTAL_PAGAR: control " . number_format((float) $control['total_pagar'], 2)
       . " → caso A " . number_format((float) $casoA['total_pagar'], 2)
       . " (diferencia " . number_format($deltaPagar, 2) . ", "
       . ($deltaPagar < 0 ? "bajó — coherente con subir el deducible" : ($deltaPagar > 0 ? "subió — dirección inesperada" : "sin cambio")) . ")\n";
} elseif ($casoA['estado'] !== GnpClient::OK) {
    echo "Caso A — GNP RECHAZÓ el DEDUCIBLE en <COBERTURA>. Conclusión: no soportado (al menos no con esta forma).\n";
}

echo "\nConclusión pendiente de escribir en docs/02.8-deducible-en-coberturas.md.\n";
echo "RECORDATORIO: revertir el bloque TEMPORAL de GnpClient.php ahora.\n";
