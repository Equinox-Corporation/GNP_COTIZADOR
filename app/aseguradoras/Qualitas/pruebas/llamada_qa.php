#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * llamada_qa.php — llamadas REALES al ambiente de pruebas (QA) de Qualitas.
 *
 * Cada corrida es una llamada de verdad y necesita autorización explícita
 * en el chat antes de ejecutarse. Por eso exige --autorizado y se niega a
 * correr si el ambiente no es QA.
 *
 * Toda llamada queda en sys_llamadas (aseguradora='QUALITAS') y la respuesta
 * cruda se guarda además en docs/aseguradoras/qualitas/evidencia/.
 *
 * Uso:
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php test               --autorizado
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php wsdl               --autorizado
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php error-descuento-60 --autorizado
 *
 * Etapa 6 — prueba de igualdad contra los PDF de "Ejemplos Qualitas" (ADR-010 punto 12):
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php cotizar-captiva          --autorizado
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php cotizar-np300            --autorizado
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php cotizar-vento            --autorizado
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php cotizar-captiva-limitada --autorizado
 *
 * Moto en semestral: el PDF de la Vento sólo ofrece contado; ¿Qualitas la cotiza en semestral?
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php cotizar-vento-semestral --autorizado
 *
 * Consideración 40 (SEPOMEX), seguida de cotizar-captiva en la misma sesión:
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php cotizar-captiva-cp40 --municipio=NNN --colonia=NNNN --autorizado
 *
 * Los tres ejemplos mandan el mismo XML que los ejemplos de Qualitas (salvo
 * fechas: hoy) e imprimen la comparación contra su PDF. La Limitada no tiene
 * PDF de referencia: pasa por el módulo (AseguradoraQualitas, paquete del
 * catálogo con código 3) para confirmar que ese paquete cotiza.
 */

require dirname(__DIR__, 3) . '/scripts/_arranque.php';
foreach (['core/PdfBasico', 'plataforma/CotizadorAseguradora', 'plataforma/Resultado', 'plataforma/CandadoEmision',
          'plataforma/RangoDescuento', 'aseguradoras/Qualitas/QualitasXml', 'aseguradoras/Qualitas/QualitasClient',
          'aseguradoras/Qualitas/AseguradoraQualitas'] as $c) {
    require_once RUTA_APP . '/' . $c . '.php';
}

$a = argumentos($argv);
$que = $a[0] ?? '';

if (!isset($a['autorizado'])) {
    fwrite(STDERR, "Llamada real a Qualitas: falta --autorizado (y la autorización en el chat).\n");
    exit(1);
}
if (strtoupper(Env::get('QUALITAS_AMBIENTE', 'QA')) !== 'QA') {
    fwrite(STDERR, "Este script sólo llama a QA.\n");
    exit(1);
}

$cliente = QualitasClient::desdeEnv();
$dirEvidencia = RUTA_BASE . '/docs/aseguradoras/qualitas/evidencia';
if (!is_dir($dirEvidencia)) {
    mkdir($dirEvidencia, 0750, true);
}

$guardar = static function (array $r, string $nombre) use ($dirEvidencia): void {
    $base = sprintf('%s/%s_llamada-%s', $dirEvidencia, date('Ymd_His'), $r['llamada_id'] ?? 'x');
    file_put_contents("{$base}_{$nombre}_peticion.xml", (string) $r['xml_entrada']);
    file_put_contents("{$base}_{$nombre}_respuesta.xml", (string) $r['xml_salida']);
    echo "  evidencia: " . basename($base) . "_{$nombre}_{peticion,respuesta}.xml\n";
};

$resumen = static function (array $r): void {
    echo "  sys_llamadas.id = {$r['llamada_id']}\n";
    echo "  estado = {$r['estado']} · HTTP {$r['http']} · {$r['ms']} ms · {$r['bytes']} bytes\n";
    if ($r['error'] !== null) {
        echo "  error  = " . json_encode($r['error'], JSON_UNESCAPED_UNICODE) . "\n";
    }
};

/** Comparación contra el PDF de ejemplo: prima neta, pronto pago, derechos, IVA y total. */
$comparar = static function (array $primas, array $pdf): void {
    echo "  comparación contra el PDF de ejemplo (23-sep-2026):\n";
    printf("    %-28s %14s %14s   %s\n", 'concepto', 'PDF', 'QA hoy', '');
    $iguales = 0;
    foreach ([
        'Prima neta'                 => ['PrimaNeta', 0],
        'Pronto pago (en Recargo)'   => ['Recargo', 1],
        'Derecho de póliza'          => ['Derecho', 2],
        'IVA'                        => ['Impuesto', 3],
        'Total a pagar'              => ['PrimaTotal', 4],
    ] as $etq => [$campo, $i]) {
        $qa = $primas[$campo] ?? null;
        $ok = $qa !== null && abs($qa - $pdf[$i]) < 0.005;
        $iguales += $ok ? 1 : 0;
        printf("    %-28s %14s %14s   %s\n", $etq, number_format($pdf[$i], 2), $qa === null ? '—' : number_format($qa, 2), $ok ? '✓' : '✗');
    }
    echo "    {$iguales} de 5 iguales.\n";
    echo '  comisión: porcentaje = ' . ($primas['Comision'] ?? 'no disponible') . "\n";
};

/**
 * Los tres ejemplos de Qualitas: mismos datos que su XML (salvo fechas) y
 * los importes de su PDF [prima neta, pronto pago, derechos, IVA, total].
 */
$cob = static fn (int $dmDed, int $rtDed, int $gm): array => [
    ['no' => 1,  'suma' => '0',          'tipo_suma' => 0,  'deducible' => $dmDed],
    ['no' => 3,  'suma' => '0',          'tipo_suma' => 0,  'deducible' => $rtDed],
    ['no' => 4,  'suma' => '3000000',    'tipo_suma' => 0,  'deducible' => 0],
    ['no' => 5,  'suma' => (string) $gm, 'tipo_suma' => 0,  'deducible' => 0],
    ['no' => 6,  'suma' => '100000',     'tipo_suma' => 0,  'deducible' => 0],
    ['no' => 7,  'suma' => '0',          'tipo_suma' => 0,  'deducible' => 0],
    ['no' => 14, 'suma' => '0',          'tipo_suma' => 0,  'deducible' => 0],
    ['no' => 47, 'suma' => '2000000',    'tipo_suma' => 14, 'deducible' => 0],
];
$ejemplos = [
    'cotizar-captiva' => [
        'titulo' => 'Captiva 2026 (AMIS 21191, Amplia, 55%, pronto pago 14)', 'amis' => '21191',
        'datos'  => ['porcentaje_descuento' => 55], 'coberturas' => $cob(5, 10, 250000),
        'pdf'    => [8440.28, -168.81, 750.00, 1443.44, 10464.91], 'archivo' => 'cotizar_captiva',
    ],
    'cotizar-np300' => [
        'titulo' => 'NP300 2026 (AMIS 11333, carga, Amplia, 55%, pronto pago 14)', 'amis' => '11333',
        // "A|DESCRIPCION" es lo que mandó el ejemplo de Qualitas.
        'datos'  => ['porcentaje_descuento' => 55, 'uso' => '6', 'tipo_carga' => 'A', 'descripcion_carga' => 'DESCRIPCION'],
        'coberturas' => $cob(5, 20, 250000),
        'pdf'    => [14710.43, -294.21, 750.00, 2426.60, 17592.82], 'archivo' => 'cotizar_np300',
    ],
    'cotizar-vento' => [
        'titulo' => 'Vento Tornado 300 2026 (AMIS 68133, moto, Amplia, 20%, pronto pago 14)', 'amis' => '68133',
        'datos'  => ['porcentaje_descuento' => 20], 'coberturas' => $cob(10, 20, 100000),
        'pdf'    => [6234.08, -124.68, 750.00, 1097.50, 7956.90], 'archivo' => 'cotizar_vento',
    ],
];

switch ($que) {
    case 'test':
        echo "Qualitas QA · Test\n";
        $r = $cliente->prueba('Test');
        $resumen($r);
        echo '  texto  = ' . ($r['texto'] ?? '') . "\n";
        $guardar($r, 'test');
        break;

    case 'wsdl':
        echo "Qualitas QA · GET ?WSDL\n";
        $r = $cliente->wsdl();
        $resumen($r);
        $guardar($r, 'wsdl');
        break;

    case 'cotizar-captiva':
    case 'cotizar-np300':
    case 'cotizar-vento':
    case 'error-descuento-60':
    case 'cotizar-captiva-cp40':
    case 'cotizar-vento-semestral':
        $esError = $que === 'error-descuento-60';
        $esCp40  = $que === 'cotizar-captiva-cp40';
        $esSemestral = $que === 'cotizar-vento-semestral';
        $ej = $ejemplos[($esError || $esCp40) ? 'cotizar-captiva' : ($esSemestral ? 'cotizar-vento' : $que)];
        $datos = $ej['datos'] + ['estado' => '9', 'uso' => '1', 'servicio' => '1', 'forma_pago' => 'C'];
        if ($esError) {
            // Fuera del rango del negocio (0-55) a propósito, para ver el formato real de
            // <CodigoError> (id 126). Este script no usa RangoDescuento; el módulo sí lo aplica.
            $datos['porcentaje_descuento'] = 60;
        }
        if ($esSemestral) {
            // La misma Vento del ejemplo, sólo cambia FormaPago a S. Su PDF sólo ofrece contado:
            // si Qualitas la rechaza, "Ver otras formas de pago" no debe ofrecerse a motos.
            $datos['forma_pago'] = 'S';
            $ej['titulo'] .= ' en SEMESTRAL';
            $ej['archivo'] = 'cotizar_vento_semestral';
        }
        if ($esCp40) {
            // La misma Captiva, más la consideración 40 (códigos SEPOMEX de municipio y
            // colonia del CP 11590), para ver si cambia el precio ("Indicaciones Qualitas.pdf").
            // Se corre junto con cotizar-captiva, seguidas, en la misma sesión.
            $mun = (string) ($a['municipio'] ?? '');
            $col = (string) ($a['colonia'] ?? '');
            if (!preg_match('/^\d{1,6}$/', $mun) || !preg_match('/^\d{1,6}$/', $col)) {
                fwrite(STDERR, "cotizar-captiva-cp40 necesita --municipio=NNN y --colonia=NNNN (códigos SEPOMEX, sólo dígitos).\n");
                exit(1);
            }
            $datos['municipio_sepomex'] = $mun;
            $datos['colonia_sepomex']   = $col;
            $ej['titulo'] .= " + consideración 40 (municipio {$mun}, colonia {$col})";
            $ej['archivo'] = 'cotizar_captiva_cp40';
        }
        $solicitud = [
            'clave_vehiculo'    => $ej['amis'],
            'modelo'            => 2026,
            'conductor_cp'      => '11590',
            'datos_aseguradora' => $datos,
            'paquete'           => ['clave' => '1', 'coberturas' => $ej['coberturas']],
        ];
        $pct = $datos['porcentaje_descuento'];
        echo 'Qualitas QA · ' . ($esError ? "Captiva con descuento {$pct}% (error esperado)" : $ej['titulo']) . "\n";
        $xml = QualitasXml::cotizacion($solicitud, $cliente->configXml());
        $r = $cliente->cotizar($xml, null, "QUALITAS obtenerNuevaEmision TipoMovimiento=2 · AMIS {$ej['amis']} · descuento {$pct}%" . ($esCp40 ? " · consideración 40 ({$datos['municipio_sepomex']}/{$datos['colonia_sepomex']})" : ''));
        $resumen($r);
        $mov = $r['movimientos'][0] ?? null;
        if ($mov !== null) {
            echo '  NoCotizacion = ' . ($mov['no_cotizacion'] ?: '—') . ' · recibos: ' . count($mov['recibos'])
               . ' · comisión del recibo: ' . ($mov['recibos'][0]['Comision'] ?? 'no disponible') . "\n";
            if (!$esError && !$esSemestral && $r['estado'] === QualitasClient::OK) {
                $comparar($mov['primas'], $ej['pdf']);
            }
            if ($esSemestral) {
                echo '  total anual = ' . ($mov['primas']['PrimaTotal'] ?? '—') . ' (contado en el PDF: 7,956.90)' . "
";
                foreach ($mov['recibos'] as $rc) {
                    printf("  recibo %s · %s a %s · total %s
", $rc['@NoRecibo'] ?? '?', $rc['FechaInicio'] ?? '?', $rc['FechaTermino'] ?? '?', $rc['PrimaTotal'] ?? '?');
                }
            }
        }
        $guardar($r, $esError ? 'error_descuento_60' : $ej['archivo']);
        break;

    case 'cotizar-captiva-limitada':
        // Por el módulo: paquete Limitada del catálogo (código 3), sin DM (Anexo 5: N).
        echo "Qualitas QA · Captiva 2026 (AMIS 21191) en LIMITADA (código 3), 55%, pronto pago 14 — por el módulo\n";
        $pdo = Db::get();
        $idLimitada = (int) $pdo->query("SELECT id FROM cat_qua_paquetes WHERE nombre = 'Limitada'")->fetchColumn();
        $modulo = new AseguradoraQualitas($cliente, $pdo);
        $rm = $modulo->cotizar([
            'clave_vehiculo'    => '21191',
            'modelo'            => 2026,
            'conductor_cp'      => '11590',
            'datos_aseguradora' => ['estado' => '9', 'uso' => '1', 'servicio' => '1', 'porcentaje_descuento' => 55],
            'paquetes'          => [$idLimitada],
        ]);
        $res = $rm['paquetes'][0] ?? null;
        $llamadaId = $res?->conceptos['llamada_id'] ?? ($rm['errores'][0]['llamada_id'] ?? null);
        echo "  estado = {$rm['estado']}" . ($rm['error'] !== null ? ' · ' . json_encode($rm['error'], JSON_UNESCAPED_UNICODE) : '') . "\n";
        if ($llamadaId === null) {
            echo "  no salió ninguna llamada\n";
            break;
        }
        $fila = Db::uno('SELECT * FROM sys_llamadas WHERE id = ?', [$llamadaId]);
        echo "  sys_llamadas.id = {$llamadaId} · HTTP {$fila['http']} · {$fila['ms']} ms · {$fila['bytes']} bytes\n";
        if ($res !== null) {
            printf("  NoCotizacion = %s · total a pagar = %s · prima neta = %s · pronto pago (Recargo) = %s · IVA = %s\n",
                $res->conceptos['no_cotizacion'], number_format((float) $res->totalPagar, 2), number_format((float) $res->primaNeta, 2),
                number_format((float) $res->conceptos['recargo'], 2), number_format((float) $res->iva, 2));
            echo '  comisión: porcentaje = ' . ($res->conceptos['comision_porcentaje'] ?? 'no disponible')
               . ' · importe = ' . ($res->conceptos['comision_importe'] ?? 'no disponible') . "\n";
            echo '  coberturas: ' . implode(' · ', array_map(static fn (CoberturaResultado $c): string => "{$c->nombre} {$c->sumaAsegurada} {$c->deducible}", $res->coberturas)) . "\n";
            echo "  (sin PDF de referencia: sólo confirma que Limitada, código 3, cotiza)\n";
        }
        $guardar(['llamada_id' => $llamadaId, 'xml_entrada' => $fila['xml_entrada'], 'xml_salida' => $fila['xml_salida']], 'cotizar_captiva_limitada');
        break;

    default:
        fwrite(STDERR, "Uso: llamada_qa.php test|wsdl|error-descuento-60|cotizar-captiva|cotizar-np300|cotizar-vento|cotizar-captiva-limitada|cotizar-vento-semestral|cotizar-captiva-cp40 [--municipio=NNN --colonia=NNNN] --autorizado\n");
        exit(1);
}
