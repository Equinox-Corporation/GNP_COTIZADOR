#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * etl_catalogos.php — Descarga los catálogos planos de GNP a la base propia.
 *
 * Uso:
 *   php app/scripts/etl_catalogos.php --lista
 *   php app/scripts/etl_catalogos.php
 *   php app/scripts/etl_catalogos.php --grupo=todos
 *   php app/scripts/etl_catalogos.php --catalogos=PAQUETE,PERIODICIDAD
 *
 * NO baja VEHICULOS: ése tiene su propio script.
 */

require __DIR__ . '/_arranque.php';

$CATALOGOS = [
    // Imprescindibles para cotizar autos
    'PAQUETE'             => ['cotizacion', 'Los "planes". Se cruzan con la matriz del Excel.'],
    'PERIODICIDAD'        => ['cotizacion', 'Forma de pago. Se manda la LETRA de la clave.'],
    'SUB_RAMO'            => ['cotizacion', 'Procedencia del vehículo (01 = Residentes).'],
    'TIPO_VEHICULO'       => ['cotizacion', 'AUT / CA1 / CA2 / MOT.'],
    'USO_VEHICULO'        => ['cotizacion', ''],
    'TIPO_CARGA_VEHICULO' => ['cotizacion', ''],
    'ARMADORA_VEHICULO'   => ['cotizacion', 'Marcas.'],
    'FORMA_INDEMNIZACION' => ['cotizacion', ''],
    'COBERTURA'           => ['cotizacion', 'Nombres oficiales de las coberturas.'],
    'DESCUENTO'           => ['cotizacion', 'Incluye POAJUTEC, el ajuste técnico del 5%.'],
    'MONEDA'              => ['cotizacion', ''],
    'VIA_PAGO'            => ['cotizacion', ''],
    'TIPO_PERSONA'        => ['cotizacion', ''],
    'SEXO'                => ['cotizacion', 'Dato del conductor, que es quien tarifica.'],
    'ESTADO_CIVIL'        => ['cotizacion', ''],
    'OCUPACION'           => ['cotizacion', 'SIN ACENTO: con acento el servicio lo rechaza.'],
    'GIRO'                => ['cotizacion', ''],
    'GIRO_TARIFICACION'   => ['cotizacion', ''],
    'ESTADO_CIRCULACION'  => ['cotizacion', ''],
    'NEGOCIO'             => ['cotizacion', ''],
    // Para más adelante
    'ESTADO'                => ['complemento', ''],
    'PAIS'                  => ['complemento', ''],
    'TIPO_VIA'              => ['complemento', ''],
    'TIPO_REFERENCIA'       => ['complemento', ''],
    'BANCO'                 => ['complemento', ''],
    'TIPO_CUENTA_TARJETA'   => ['complemento', ''],
    'TIPO_DATO_BANCARIO'    => ['complemento', ''],
    'TIPO_DOCUMENTO_FISCAL' => ['complemento', ''],
    'REGIMEN_CAPITAL'       => ['complemento', ''],
    'REGIMEN_FISCAL'        => ['complemento', 'Exige filtro TIPO_PERSONA: se baja dos veces.'],
    // Enormes: pueden no responder sin filtro
    'CODIGO_POSTAL' => ['geo', 'Miles de registros.'],
    'COLONIA'       => ['geo', 'Miles de registros.'],
    'MUNICIPIO'     => ['geo', ''],
];

$VARIANTES = ['REGIMEN_FISCAL' => [['TIPO_PERSONA' => 'F'], ['TIPO_PERSONA' => 'M']]];

$args  = argumentos($argv);
$grupo = strtolower($args['grupo'] ?? 'cotizacion');

if (isset($args['lista'])) {
    echo pad('CATÁLOGO', 24) . pad('GRUPO', 13) . "NOTA\n" . str_repeat('─', 92) . "\n";
    foreach ($CATALOGOS as $t => [$g, $n]) { echo pad($t, 24) . pad($g, 13) . $n . "\n"; }
    exit(0);
}

if (isset($args['catalogos'])) {
    $pedidos = array_values(array_filter(array_map('trim', explode(',', strtoupper($args['catalogos'])))));
    if ($mal = array_diff($pedidos, array_keys($CATALOGOS))) {
        fwrite(STDERR, 'No configurados: ' . implode(', ', $mal) . "\n"); exit(1);
    }
} else {
    $pedidos = array_keys(array_filter($CATALOGOS, fn ($c) => $grupo === 'todos' || $c[0] === $grupo));
    if ($pedidos === []) { fwrite(STDERR, "Grupo desconocido: {$grupo}\n"); exit(1); }
}

$gnp = cliente();
$pdo = Db::get();
$ins = $pdo->prepare(
    'INSERT INTO cat_catalogos (tipo_catalogo, filtros, clave, nombre, valor, orden)
     VALUES (:tc,:fi,:cl,:no,:va,:or)
     ON CONFLICT (tipo_catalogo, filtros, clave) DO UPDATE SET
        nombre = excluded.nombre, valor = excluded.valor, orden = excluded.orden,
        descargado_en = datetime(\'now\',\'localtime\')'
);

echo pad('CATÁLOGO', 24) . pad('ESTADO', 10) . pad('ELEM.', 8) . pad('ms', 8) . "DETALLE\n";
echo str_repeat('─', 92), "\n";

$total = 0; $fallidos = [];
foreach ($pedidos as $tipo) {
    foreach ($VARIANTES[$tipo] ?? [[]] as $filtros) {
        $json = $filtros === [] ? '' : json_encode($filtros, JSON_UNESCAPED_UNICODE);
        $etq  = $tipo . ($filtros === [] ? '' : ' [' . implode(',', $filtros) . ']');

        $r = $gnp->catalogo($tipo, $filtros);
        bitacora($r, $etq);

        $detalle = '';
        if ($r['estado'] === GnpClient::OK) {
            $pdo->beginTransaction();
            foreach ($r['elementos'] as $i => $e) {
                $ins->execute([':tc' => $tipo, ':fi' => $json, ':cl' => $e['clave'],
                               ':no' => $e['nombre'], ':va' => $e['valor'], ':or' => $i]);
            }
            $pdo->commit();
            $total += count($r['elementos']);
            if ($r['elementos'] === []) { $detalle = 'devolvió 0 elementos'; }
        } else {
            $detalle    = $r['error']['descripcion'] ?? '';
            $fallidos[] = "{$etq} → {$r['estado']}: {$detalle}";
        }

        echo pad(mb_strimwidth($etq, 0, 23), 24) . pad($r['estado'], 10)
           . pad((string) count($r['elementos']), 8) . pad((string) $r['ms'], 8)
           . mb_strimwidth($detalle, 0, 45, '…') . "\n";

        if ($r['estado'] === GnpClient::E_AUTH) {
            echo "\nGNP rechazó las credenciales. Se detiene.\nRevisa GNP_USUARIO y GNP_PASSWORD en config/.env.local\n";
            exit(2);
        }
        usleep(300000);
    }
}

echo str_repeat('─', 92), "\n";
echo 'Elementos guardados: ' . number_format($total) . "\n";
foreach ($pdo->query('SELECT tipo_catalogo, COUNT(*) n FROM cat_catalogos GROUP BY 1 ORDER BY 1') as $f) {
    echo '   ' . pad($f['tipo_catalogo'], 24) . str_pad((string) $f['n'], 6, ' ', STR_PAD_LEFT) . "\n";
}
if ($fallidos !== []) {
    echo "\nCon falla (" . count($fallidos) . "):\n";
    foreach ($fallidos as $f) { echo "   · {$f}\n"; }
    echo "\nUn TIMEOUT no es rechazo: el catálogo es demasiado grande y hay que filtrarlo.\n";
}
exit($fallidos === [] ? 0 : 1);
