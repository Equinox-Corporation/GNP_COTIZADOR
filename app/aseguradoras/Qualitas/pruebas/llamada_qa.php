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
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php test            --autorizado
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php wsdl            --autorizado
 *   php app/aseguradoras/Qualitas/pruebas/llamada_qa.php cotizar-captiva --autorizado
 */

require dirname(__DIR__, 3) . '/scripts/_arranque.php';
require RUTA_APP . '/plataforma/CotizadorAseguradora.php';
require RUTA_APP . '/plataforma/CandadoEmision.php';
require RUTA_APP . '/aseguradoras/Qualitas/QualitasXml.php';
require RUTA_APP . '/aseguradoras/Qualitas/QualitasClient.php';

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
        // Mismos datos que el ejemplo uv5931410001849963aa (Captiva 2026, AMIS 21191,
        // CP 11590, Estado 9, Amplia, descuento 55, pronto pago 14). Fechas: hoy.
        $solicitud = [
            'clave_vehiculo'    => '21191',
            'modelo'            => 2026,
            'conductor_cp'      => '11590',
            'datos_aseguradora' => [
                'estado' => '9', 'uso' => '1', 'servicio' => '1', 'forma_pago' => 'C',
                'porcentaje_descuento' => 55,
            ],
            'paquete' => ['clave' => '1', 'coberturas' => [
                ['no' => 1,  'suma' => '0',       'tipo_suma' => 0,  'deducible' => 5],
                ['no' => 3,  'suma' => '0',       'tipo_suma' => 0,  'deducible' => 10],
                ['no' => 4,  'suma' => '3000000', 'tipo_suma' => 0,  'deducible' => 0],
                ['no' => 5,  'suma' => '250000',  'tipo_suma' => 0,  'deducible' => 0],
                ['no' => 6,  'suma' => '100000',  'tipo_suma' => 0,  'deducible' => 0],
                ['no' => 7,  'suma' => '0',       'tipo_suma' => 0,  'deducible' => 0],
                ['no' => 14, 'suma' => '0',       'tipo_suma' => 0,  'deducible' => 0],
                ['no' => 47, 'suma' => '2000000', 'tipo_suma' => 14, 'deducible' => 0],
            ]],
        ];
        echo "Qualitas QA · cotización Captiva 2026 (AMIS 21191, Amplia, 55%, pronto pago 14)\n";
        $xml = QualitasXml::cotizacion($solicitud, $cliente->configXml());
        $r = $cliente->cotizar($xml, null, 'QUALITAS obtenerNuevaEmision TipoMovimiento=2 · Captiva 21191 (Etapa 2)');
        $resumen($r);
        foreach ($r['movimientos'] ?? [] as $m) {
            echo '  movimiento: ' . json_encode($m, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
        $guardar($r, 'cotizar_captiva');
        break;

    default:
        fwrite(STDERR, "Uso: llamada_qa.php test|wsdl|cotizar-captiva --autorizado\n");
        exit(1);
}
