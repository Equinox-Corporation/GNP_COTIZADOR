#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_piso_minimo_paquetes.php — ¿Qué coberturas trae cada paquete real de
 * GNP por default, sin pedir nada explícito? Para ADR-008 (piso mínimo por
 * paquete, ver docs/03_Decisiones/ADR-008-piso-minimo-por-paquete.md).
 *
 * Responsabilidad Civil (PRP0000289) YA se probó así en docs/02.10
 * (sys_llamadas.id = 72) — no se repite aquí, se cita. Este script corre el
 * mismo caso "control, sin <COBERTURAS>" para los otros cinco paquetes
 * reales: Amplia, Amplia Total, Limitada, Premium, Auto Elite.
 *
 * Habla con GnpClient DIRECTO (como 02.10), sin pasar por PlantillaServicio —
 * no hay ninguna plantilla de por medio, sólo el paquete base puro.
 *
 * Misma cotización base de siempre (Honda Fit AUTHO0614, PF 50 años CP
 * 76000, conductor 43 años CP 04200, anual).
 *
 * Uso:
 *   php app/scripts/prueba_piso_minimo_paquetes.php
 */

require __DIR__ . '/_arranque.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Piso mínimo por paquete — 5 llamadas de control (PRODUCCIÓN)\n";
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

$paquetes = [
    'amplia'       => 'PRS0009355',
    'amplia_total' => 'PRS0054748',
    'limitada'     => 'PRS0009356',
    'premium'      => 'PRS0010536',
    'auto_elite'   => 'PRP0000357',
];

$resumen = [];

foreach ($paquetes as $id => $cve) {
    $nombre = (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$cve]) ?? '');
    if ($nombre === '') {
        fwrite(STDERR, "No se encontró el paquete {$cve} en cat_paquetes.\n");
        exit(1);
    }
    $paquete = ['cve' => $cve, 'desc' => ucwords(mb_strtolower($nombre, 'UTF-8'))];

    echo "── {$paquete['desc']} ({$cve}) — control sin <COBERTURAS> ──\n";
    $r = $gnp->cotizar($datosBase, [$paquete], []);
    bitacora($r, "ADR-008 · piso mínimo · {$paquete['desc']} sin COBERTURAS");

    file_put_contents("{$dirEvidencia}/{$id}-control-peticion.txt", (string) ($r['xml_entrada'] ?? ''));
    file_put_contents("{$dirEvidencia}/{$id}-control-respuesta.txt", (string) ($r['xml_salida'] ?? ''));

    if ($r['estado'] !== GnpClient::OK) {
        echo "   GNP RECHAZÓ: [{$r['estado']}] " . ($r['error']['descripcion'] ?? '(sin descripción)') . "\n\n";
        $resumen[$id] = ['cve' => $cve, 'desc' => $paquete['desc'], 'error' => $r['error'] ?? $r['estado']];
        continue;
    }

    $p = $r['paquetes'][0] ?? null;
    $coberturas = $p['coberturas'] ?? [];
    echo '   TOTAL_PAGAR = ' . number_format((float) ($p['total_pagar'] ?? 0), 2) . "\n";
    echo '   Coberturas devueltas (' . count($coberturas) . "):\n";
    foreach ($coberturas as $cb) {
        echo "      {$cb['cve']}  {$cb['nombre']}  suma={$cb['suma']}\n";
    }
    echo "\n";

    $resumen[$id] = [
        'cve' => $cve, 'desc' => $paquete['desc'], 'total_pagar' => $p['total_pagar'] ?? null,
        'coberturas' => $coberturas,
    ];
}

// ── Comparar contra lo que dice cat_coberturas localmente ──────────────────
echo "═══════════════════════════════════════════════════════════════════\n";
echo " Comparación contra cat_coberturas (BASICA declarada localmente)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

foreach ($resumen as $id => $info) {
    if (!isset($info['coberturas'])) {
        continue;
    }
    $paqueteNombreDb = Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$info['cve']]);
    $basicasLocales = Db::todos(
        "SELECT cve_cobertura, nombre FROM cat_coberturas WHERE grupo = 'AUTO' AND paquete = ? AND tipo = 'BASICA' ORDER BY nombre",
        [$paqueteNombreDb]
    );
    $cvesLocales = array_column($basicasLocales, 'cve_cobertura');
    $cvesGnp     = array_column($info['coberturas'], 'cve');

    sort($cvesLocales);
    sort($cvesGnp);

    $soloLocal = array_diff($cvesLocales, $cvesGnp);
    $soloGnp   = array_diff($cvesGnp, $cvesLocales);

    echo "── {$info['desc']} ({$info['cve']}) ──\n";
    echo '   cat_coberturas (BASICA): ' . count($cvesLocales) . ' | GNP devolvió: ' . count($cvesGnp) . "\n";
    if ($soloLocal === [] && $soloGnp === []) {
        echo "   COINCIDEN exactamente.\n\n";
    } else {
        echo "   *** DIFERENCIA ENCONTRADA ***\n";
        if ($soloLocal !== []) {
            echo '   En cat_coberturas pero NO en la respuesta de GNP: ' . implode(', ', $soloLocal) . "\n";
        }
        if ($soloGnp !== []) {
            echo '   En la respuesta de GNP pero NO en cat_coberturas como BASICA: ' . implode(', ', $soloGnp) . "\n";
        }
        echo "\n";
    }
}

echo "Documentar en docs/03_Decisiones/ADR-008-piso-minimo-por-paquete.md.\n";
