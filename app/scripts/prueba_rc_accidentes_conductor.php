#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_rc_accidentes_conductor.php — ¿Accidentes al Conductor (0000000893)
 * se puede pedir sobre el paquete Responsabilidad Civil?
 *
 * `cat_coberturas` dice N/A: esa clave no aparece en ninguna fila de RC (ni
 * Básica ni Opcional). Nunca se había probado en vivo — es exactamente el
 * caso que quedó pendiente en docs/02.6-coberturas-modificadas.md ("una
 * cobertura N/A dentro del mismo tipo de vehículo, en un paquete distinto";
 * la prueba original cruzó tipo de vehículo, no paquete-contra-paquete).
 *
 * Apareció al cargar la plantilla "Equinox RC": PlantillaServicio::guardar()
 * la rechazó localmente, tal como está diseñado. Esta prueba habla con
 * GnpClient DIRECTO, sin pasar por esa validación — a propósito, para
 * confirmar si el rechazo local es correcto o si la matriz local está
 * desactualizada. NO se toca PlantillaServicio ni validarCoberturas() hasta
 * tener el resultado.
 *
 * Misma cotización base de siempre, mismo mecanismo GnpClient::cotizar() +
 * bitácora (sys_llamadas, cotizacion_id NULL) que 02.6/02.8/02.9.
 *
 * Uso:
 *   php app/scripts/prueba_rc_accidentes_conductor.php
 */

require __DIR__ . '/_arranque.php';

echo "═══════════════════════════════════════════════════════════════════\n";
echo " ¿Accidentes al Conductor (0000000893) se puede pedir sobre RC? (PRODUCCIÓN)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$gnp = cliente();

$veh = CatalogoServicio::vehiculo('AUT', 'HO', '06', '14', 2015);
if ($veh === null || $veh['clavemarca'] !== 'AUTHO0614') {
    fwrite(STDERR, "No se encontró el vehículo base (AUTHO0614, 2015).\n");
    exit(1);
}

$paqueteNombre = (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', ['PRP0000289']) ?? '');
if ($paqueteNombre === '') {
    fwrite(STDERR, "No se encontró el paquete PRP0000289 en cat_paquetes.\n");
    exit(1);
}
$paquete = ['cve' => 'PRP0000289', 'desc' => ucwords(mb_strtolower($paqueteNombre, 'UTF-8'))];

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

$dirEvidencia = RUTA_BASE . '/datos/evidencia_02.10_rc_accidentes_conductor';
if (!is_dir($dirEvidencia)) {
    mkdir($dirEvidencia, 0777, true);
}

/** @param list<array{cve:string,nombre?:string,suma?:string}> $opcionales */
function correrCaso(string $id, string $titulo, GnpClient $gnp, array $datosBase, array $paquete, array $opcionales, string $dirEvidencia): array
{
    echo "── {$id} · {$titulo} " . str_repeat('─', max(0, 55 - strlen($id) - strlen($titulo))) . "\n";

    $r = $gnp->cotizar($datosBase, [$paquete], $opcionales);
    bitacora($r, "prueba 02.10 · {$id} · {$titulo}");

    file_put_contents("{$dirEvidencia}/{$id}-peticion.txt", (string) ($r['xml_entrada'] ?? ''));
    file_put_contents("{$dirEvidencia}/{$id}-respuesta.txt", (string) ($r['xml_salida'] ?? ''));

    $resultado = ['id' => $id, 'titulo' => $titulo, 'estado' => $r['estado'], 'total_pagar' => null, 'coberturas' => [], 'error' => $r['error'] ?? null];

    if ($r['estado'] === GnpClient::OK) {
        $p = $r['paquetes'][0] ?? null;
        if ($p !== null) {
            $resultado['total_pagar'] = $p['total_pagar'];
            $resultado['coberturas']  = $p['coberturas'];
        }
        echo "   GNP aceptó. TOTAL_PAGAR = " . ($resultado['total_pagar'] !== null ? number_format((float) $resultado['total_pagar'], 2) : '(sin dato)') . "\n";
        echo "   Coberturas devueltas (" . count($resultado['coberturas']) . "):\n";
        foreach ($resultado['coberturas'] as $cb) {
            $marca = $cb['cve'] === '0000000893' ? '  <<< Accidentes al Conductor' : '';
            echo "      {$cb['cve']}  {$cb['nombre']}  suma={$cb['suma']}{$marca}\n";
        }
    } else {
        echo "   GNP respondió con error: [{$r['estado']}] " . ($r['error']['descripcion'] ?? '(sin descripción)') . "\n";
        echo "   CLAVE={$resultado['error']['clave']}  ORIGEN={$resultado['error']['origen']}\n";
    }
    echo "   Evidencia: {$dirEvidencia}/{$id}-peticion.txt / {$id}-respuesta.txt\n\n";

    return $resultado;
}

// ── 00 · Control: RC sin <COBERTURAS> ───────────────────────────────────────
$control = correrCaso('00-control', 'RC sin COBERTURAS (¿trae Accidentes al Conductor por default?)', $gnp, $datosBase, $paquete, [], $dirEvidencia);

$yaViene = false;
foreach ($control['coberturas'] as $cb) {
    if ($cb['cve'] === '0000000893') {
        $yaViene = true;
        break;
    }
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Resultado del control\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

if ($yaViene) {
    echo "Accidentes al Conductor YA viene incluida por default en RC — sin pedirla.\n";
    echo "No hace falta el Caso A: cat_coberturas está desactualizada (le falta esta fila para RC),\n";
    echo "pero la pregunta de fondo (¿se puede tener en RC?) queda respondida: sí.\n";
    exit(0);
}

echo "No viene por default. Se corre el Caso A: pedirla explícitamente sobre RC.\n\n";

// ── A · Pedir Accidentes al Conductor explícitamente sobre RC ──────────────
$casoA = correrCaso(
    '01-caso-a',
    'RC + Accidentes al Conductor (0000000893, suma 100,000) explícita',
    $gnp, $datosBase, $paquete,
    [['cve' => '0000000893', 'nombre' => 'Accidentes al Conductor', 'suma' => '100000']],
    $dirEvidencia
);

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Conclusión\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

if ($casoA['estado'] === GnpClient::OK) {
    $reflejada = false;
    foreach ($casoA['coberturas'] as $cb) {
        if ($cb['cve'] === '0000000893') {
            $reflejada = true;
        }
    }
    echo $reflejada
        ? "GNP ACEPTÓ Accidentes al Conductor sobre RC, y aparece reflejada en la respuesta.\n   -> cat_coberturas está desactualizada para esta combinación. Corregir con nota fechada.\n"
        : "GNP aceptó la llamada (200, sin error) pero la cobertura NO aparece en la respuesta — aceptada pero ignorada.\n   -> Caso más engañoso: no es soporte real, hay que tratarlo como rechazo funcional.\n";
} else {
    echo "GNP RECHAZÓ la combinación: [{$casoA['estado']}] {$casoA['error']['descripcion']}\n";
    echo "CLAVE={$casoA['error']['clave']}  ORIGEN={$casoA['error']['origen']}\n";
    echo "-> cat_coberturas es correcta: GNP estructuralmente no permite Accidentes al Conductor en RC.\n";
    echo "   No es limitación del sistema, es limitación real de GNP para este paquete.\n";
}

echo "\nDocumentar en docs/02.10-rc-accidentes-conductor.md.\n";
