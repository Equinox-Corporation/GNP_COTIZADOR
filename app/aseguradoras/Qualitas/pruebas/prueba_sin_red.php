#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_sin_red.php — pruebas del módulo Qualitas SIN NINGUNA LLAMADA.
 *
 * Ningún cliente de esta prueba usa cURL: todos reciben un transporte falso.
 * El que se llama "prohibido" truena si alguien intenta usarlo; el
 * "simulado" regresa respuestas SIMULADAS (archivos SIMULADO_*.xml) y
 * cuenta cuántas veces lo invocaron. La bitácora tampoco toca la base.
 *
 * Qué comprueba:
 *   1. Dígito verificador AMIS (manual pág. 11 y los 3 ejemplos).
 *   2. El XML generado para los 3 ejemplos de Qualitas coincide en estructura
 *      y valores con los XML de ejemplo, salvo las fechas.
 *   3. Candado doble: un XML con TipoMovimiento "3" o "4" (y otras variantes)
 *      NUNCA llega al transporte. Métodos bloqueados y fuera de lista, igual.
 *   4. Parseo de respuestas SIMULADAS y clasificación por código.
 *   5. cUsuario/cTarifa enmascarados en la evidencia.
 *   6. La URL de producción vacía impide construir el cliente de producción.
 *
 * Uso:
 *   php app/aseguradoras/Qualitas/pruebas/prueba_sin_red.php [--mostrar=captiva|vento|np300]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 4));
define('RUTA_APP', RUTA_BASE . '/app');

require RUTA_APP . '/core/Env.php';
require RUTA_APP . '/plataforma/CotizadorAseguradora.php';
require RUTA_APP . '/plataforma/CandadoEmision.php';
require RUTA_APP . '/aseguradoras/Qualitas/QualitasXml.php';
require RUTA_APP . '/aseguradoras/Qualitas/QualitasClient.php';

$opciones = getopt('', ['mostrar:']);

// ─── Mini arnés ────────────────────────────────────────────────────────
$fallas = 0;
$total  = 0;
function ok(bool $cond, string $que, string $detalle = ''): void
{
    global $fallas, $total;
    $total++;
    if ($cond) {
        echo "  ✓ {$que}\n";
    } else {
        $fallas++;
        echo "  ✗ {$que}" . ($detalle !== '' ? "\n      {$detalle}" : '') . "\n";
    }
}
function lanza(callable $f): ?string
{
    try {
        $f();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

// ─── Transportes y bitácora falsos ─────────────────────────────────────
$envios = [];
$transporteProhibido = static function (): never {
    throw new LogicException('ALGO INTENTÓ SALIR A LA RED EN UNA PRUEBA SIN RED.');
};
$respuestaSimulada = '';
$transporteSimulado = static function (string $url, string $cuerpo, array $enc, int $t) use (&$envios, &$respuestaSimulada): array {
    $envios[] = ['url' => $url, 'cuerpo' => $cuerpo, 'encabezados' => $enc];
    return ['http' => 200, 'cuerpo' => $respuestaSimulada, 'errno' => 0, 'error' => ''];
};
$registros = [];
$bitacora = static function (array $r) use (&$registros): int {
    $registros[] = $r;
    return count($registros);
};

/** Configuración igual a la de los ejemplos de Qualitas. */
function config(string $ambiente = 'QA'): array
{
    return [
        'ambiente'          => $ambiente,
        'url_emision'       => 'https://qa.qualitas.com.mx:8443/WsEmision/WsEmision.asmx',
        // Valor SÓLO de prueba: el nombre real no se conoce [PENDIENTE].
        'parametro_emision' => 'PARAMETRO_DE_PRUEBA',
        'url_tarifa'        => 'http://tarifa.prueba.invalid/wsTarifa.asmx',
        'ns_tarifa'         => 'http://ns.prueba.invalid/',
        'catalogo_usuario'  => 'usuario-secreto',
        'catalogo_tarifa'   => 'tarifa-secreta',
        'negocio'           => '08902',
        'agente'            => '0008810',
        'derechos'          => '750',
        'pronto_pago_dias'  => '14',
        'tarifa'            => 'LINEA',
        'timeout'           => 5,
    ];
}

/** Las tres solicitudes de "Ejemplos Qualitas" (23-sep-2026, CP 11590, Ciudad de México). */
function escenarios(): array
{
    $cob = static fn (int $dmDed, int $rtDed, int $gm): array => [
        ['no' => 1,  'suma' => '0',       'tipo_suma' => 0,  'deducible' => $dmDed],
        ['no' => 3,  'suma' => '0',       'tipo_suma' => 0,  'deducible' => $rtDed],
        ['no' => 4,  'suma' => '3000000', 'tipo_suma' => 0,  'deducible' => 0],
        ['no' => 5,  'suma' => (string) $gm, 'tipo_suma' => 0, 'deducible' => 0],
        ['no' => 6,  'suma' => '100000',  'tipo_suma' => 0,  'deducible' => 0],
        ['no' => 7,  'suma' => '0',       'tipo_suma' => 0,  'deducible' => 0],
        ['no' => 14, 'suma' => '0',       'tipo_suma' => 0,  'deducible' => 0],
        ['no' => 47, 'suma' => '2000000', 'tipo_suma' => 14, 'deducible' => 0],
    ];
    $base = static fn (string $amis, array $d, array $coberturas): array => [
        'clave_vehiculo'    => $amis,
        'modelo'            => 2026,
        'conductor_cp'      => '11590',
        'fecha_emision'     => '2026-08-29',
        'vigencia_inicio'   => '2026-09-30',
        'datos_aseguradora' => $d + ['estado' => '9', 'uso' => '1', 'servicio' => '1', 'forma_pago' => 'C'],
        'paquete'           => ['clave' => '1', 'coberturas' => $coberturas],
    ];

    return [
        'captiva' => [
            'archivo'   => 'captiva_21191_uv5931410001849963aa.xml',
            'solicitud' => $base('21191', ['porcentaje_descuento' => 55], $cob(5, 10, 250000)),
        ],
        'vento' => [
            'archivo'   => 'vento_68133_uv5935890001849963aa.xml',
            'solicitud' => $base('68133', ['porcentaje_descuento' => 20], $cob(10, 20, 100000)),
        ],
        'np300' => [
            'archivo'   => 'np300_11333_uv5941430001849963aa.xml',
            // El ejemplo manda literalmente "A|DESCRIPCION": es la plantilla del
            // manual sin llenar. Se reproduce tal cual para comparar.
            'solicitud' => $base('11333', ['porcentaje_descuento' => 55, 'uso' => '6', 'tipo_carga' => 'A', 'descripcion_carga' => 'DESCRIPCION'], $cob(5, 20, 250000)),
        ],
    ];
}

/**
 * Compara dos árboles XML: nombres, atributos, orden de hijos y texto.
 * Ignora el texto de FechaEmision/FechaInicio/FechaTermino.
 * Devuelve la lista de diferencias (vacía = iguales).
 */
function diferencias(DOMNode $a, DOMNode $b, string $ruta = ''): array
{
    $dif = [];
    $ruta .= '/' . $a->nodeName;
    if ($a->nodeName !== $b->nodeName) {
        return ["{$ruta}: elemento {$a->nodeName} ≠ {$b->nodeName}"];
    }
    $attrs = static function (DOMNode $n): array {
        $r = [];
        foreach ($n->attributes ?? [] as $x) {
            $r[$x->nodeName] = $x->nodeValue;
        }
        ksort($r);
        return $r;
    };
    if ($attrs($a) !== $attrs($b)) {
        $dif[] = "{$ruta}: atributos " . json_encode($attrs($a)) . ' ≠ ' . json_encode($attrs($b));
    }
    $hijos = static fn (DOMNode $n): array => array_values(array_filter(
        iterator_to_array($n->childNodes),
        static fn (DOMNode $h): bool => $h instanceof DOMElement
    ));
    $ha = $hijos($a);
    $hb = $hijos($b);
    if ($ha === [] && $hb === []) {
        if (!in_array($a->nodeName, ['FechaEmision', 'FechaInicio', 'FechaTermino'], true)
            && trim($a->textContent) !== trim($b->textContent)) {
            $dif[] = "{$ruta}: \"" . trim($a->textContent) . '" ≠ "' . trim($b->textContent) . '"';
        }
        return $dif;
    }
    if (count($ha) !== count($hb)) {
        $dif[] = "{$ruta}: " . count($ha) . ' hijos ≠ ' . count($hb);
    }
    for ($i = 0, $n = min(count($ha), count($hb)); $i < $n; $i++) {
        $dif = array_merge($dif, diferencias($ha[$i], $hb[$i], $ruta));
    }
    return $dif;
}

function dom(string $xml): DOMDocument
{
    $d = new DOMDocument();
    $d->preserveWhiteSpace = false;
    if (!$d->loadXML($xml)) {
        throw new RuntimeException('XML ilegible');
    }
    return $d;
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Qualitas · pruebas SIN RED (ninguna llamada a Qualitas)\n";
echo "═══════════════════════════════════════════════════════════════════\n";

// ─── 1. Dígito AMIS ────────────────────────────────────────────────────
echo "\n1. Dígito verificador AMIS (módulo 10)\n";
foreach (['22374' => 4, '21191' => 8, '68133' => 9, '11333' => 5] as $amis => $esperado) {
    $d = QualitasXml::digitoAmis((string) $amis);
    ok($d === $esperado, "{$amis} → {$esperado}", "salió {$d}");
}
ok(QualitasXml::digitoAmis('7003') === QualitasXml::digitoAmis('07003'), 'Rellena con ceros a la izquierda (7003 = 07003)');
ok(lanza(static fn () => QualitasXml::digitoAmis('12A45')) !== null, 'Rechaza una clave con letras');

// ─── 2. XML de los 3 ejemplos ──────────────────────────────────────────
echo "\n2. XML generado contra los ejemplos de Qualitas (salvo fechas)\n";
$cliente = new QualitasClient(config(), $transporteProhibido, $bitacora);
$generados = [];
foreach (escenarios() as $nombre => $esc) {
    $xml = QualitasXml::cotizacion($esc['solicitud'], $cliente->configXml());
    $generados[$nombre] = $xml;
    $ejemplo = file_get_contents(__DIR__ . '/ejemplos/' . $esc['archivo']);
    $dif = diferencias(dom($xml)->documentElement, dom($ejemplo)->documentElement);
    ok($dif === [], "{$nombre}: estructura y valores iguales al ejemplo", implode("\n      ", $dif));

    $f = dom($xml);
    $x = new DOMXPath($f);
    ok($x->evaluate('string(//FechaTermino)') === '2027-09-30', "{$nombre}: FechaTermino = inicio + 1 año");
}
$hoy = QualitasXml::cotizacion(array_diff_key(escenarios()['captiva']['solicitud'], ['fecha_emision' => 1, 'vigencia_inicio' => 1]), $cliente->configXml());
ok(str_contains($hoy, '<FechaEmision>' . date('Y-m-d') . '</FechaEmision>'), 'Sin fechas en la solicitud, emisión e inicio = hoy');
ok(lanza(static fn () => QualitasXml::cotizacion(['datos_aseguradora' => ['estado' => '9']] + escenarios()['captiva']['solicitud'], config() + ['ambiente_pruebas' => true])) !== null, 'Sin porcentaje de descuento no se arma el XML');
$prod = QualitasXml::cotizacion(escenarios()['captiva']['solicitud'], (new QualitasClient(config('PRODUCCION'), $transporteProhibido, $bitacora))->configXml());
ok(str_contains($prod, "NoConsideracion=\"4\">\n\t\t\t\t<TipoRegla>0</TipoRegla>\n\t\t\t\t<ValorRegla>0</ValorRegla>"), 'Con ambiente PRODUCCION la consideración 04 sale en 0');

// ─── 3. Candado doble ──────────────────────────────────────────────────
echo "\n3. Candado: nada que no sea cotización sale\n";
$clienteQa = new QualitasClient(config(), $transporteSimulado, $bitacora);
$captiva = $generados['captiva'];

$variantes = [
    'TipoMovimiento="3" (emisión)'                  => str_replace('TipoMovimiento="2"', 'TipoMovimiento="3"', $captiva),
    'TipoMovimiento="4" (endoso)'                   => str_replace('TipoMovimiento="2"', 'TipoMovimiento="4"', $captiva),
    'TipoMovimiento="02"'                           => str_replace('TipoMovimiento="2"', 'TipoMovimiento="02"', $captiva),
    'TipoMovimiento=" 2"'                           => str_replace('TipoMovimiento="2"', 'TipoMovimiento=" 2"', $captiva),
    'sin TipoMovimiento'                            => str_replace('TipoMovimiento="2" ', '', $captiva),
    'NoPoliza con valor'                            => str_replace('NoPoliza=""', 'NoPoliza="1234567"', $captiva),
    'NoEndoso con valor'                            => str_replace('NoEndoso=""', 'NoEndoso="1"', $captiva),
    'TipoEndoso con valor'                          => str_replace('TipoEndoso=""', 'TipoEndoso="A"', $captiva),
    'segundo <Movimiento> con TipoMovimiento="3"'   => str_replace("\t</Movimiento>\n</Movimientos>", "\t</Movimiento>\n" . str_replace(['<Movimientos>', '</Movimientos>', 'TipoMovimiento="2"'], ['', '', 'TipoMovimiento="3"'], $captiva) . '</Movimientos>', $captiva),
    'elemento <TipoMovimiento>3</TipoMovimiento>'   => str_replace('<CodigoError/>', '<CodigoError/><TipoMovimiento>3</TipoMovimiento>', $captiva),
    'elemento <NoPoliza>123</NoPoliza>'             => str_replace('<CodigoError/>', '<CodigoError/><NoPoliza>123</NoPoliza>', $captiva),
    'consideración 04 = 0 contra QA'                => str_replace("<ValorRegla>1</ValorRegla>\n\t\t\t</ConsideracionesAdicionalesDG>\n\t\t\t<ConsideracionesAdicionalesDG NoConsideracion=\"5\">", "<ValorRegla>0</ValorRegla>\n\t\t\t</ConsideracionesAdicionalesDG>\n\t\t\t<ConsideracionesAdicionalesDG NoConsideracion=\"5\">", $captiva),
    'sin consideración 04'                          => preg_replace('#\t\t\t<ConsideracionesAdicionalesDG NoConsideracion="4">.*?</ConsideracionesAdicionalesDG>\n#s', '', $captiva),
    'con DOCTYPE'                                   => "<!DOCTYPE Movimientos [<!ENTITY t \"3\">]>\n" . str_replace('TipoMovimiento="2"', 'TipoMovimiento="&t;"', $captiva),
    'XML roto'                                      => substr($captiva, 0, 200),
    'raíz distinta'                                 => str_replace(['<Movimientos>', '</Movimientos>'], ['<Otra>', '</Otra>'], $captiva),
];
foreach ($variantes as $que => $xml) {
    $antes = count($envios);
    $msg = lanza(static fn () => $clienteQa->cotizar($xml));
    ok($msg !== null && str_starts_with($msg, 'BLOQUEADO') && count($envios) === $antes, "Bloquea y no envía: {$que}", (string) $msg);
}

// El mismo XML válido de QA, contra un cliente de PRODUCCIÓN: la 04 dice 1.
$clienteProd = new QualitasClient(['url_emision' => 'https://produccion.prueba.invalid/WsEmision.asmx'] + config('PRODUCCION'), $transporteProhibido, $bitacora);
ok(str_starts_with((string) lanza(static fn () => $clienteProd->cotizar($captiva)), 'BLOQUEADO'), 'Bloquea un XML de pruebas (04=1) dirigido a producción');

$m = new ReflectionMethod(QualitasClient::class, 'enviar');
foreach (['EnviaMail', 'obtenerNuevaEmisionDXN', 'obtenerNuevaEmisionX', 'cancelarPoliza'] as $metodo) {
    $antes = count($envios);
    $msg = lanza(static fn () => $m->invoke($clienteQa, $metodo, [], 'x', null, 'x'));
    ok($msg !== null && str_starts_with($msg, 'BLOQUEADO') && count($envios) === $antes, "Bloquea el método {$metodo}", (string) $msg);
}
ok(str_starts_with((string) lanza(static fn () => $clienteQa->prueba('EnviaMail')), 'BLOQUEADO'), 'prueba() sólo acepta Test y HolamundoAux');
$antes = count($envios);
ok(lanza(static fn () => $clienteQa->listaTarifas(['marca' => 'VW', 'modelo' => ''])) !== null && count($envios) === $antes, 'listaTarifas sin modelo no sale (nunca el catálogo completo)');
$sinParametro = new QualitasClient(['parametro_emision' => ''] + config(), $transporteProhibido, $bitacora);
ok(str_contains((string) lanza(static fn () => $sinParametro->cotizar($captiva)), 'QUALITAS_WS_PARAMETRO'), 'Sin nombre de parámetro SOAP no sale la cotización');

// Control positivo: el XML válido SÍ llega al transporte (falso), una sola vez.
$respuestaSimulada = file_get_contents(__DIR__ . '/SIMULADO_respuesta_cotizacion_ok.xml');
$antes = count($envios);
$r = $clienteQa->cotizar($captiva);
ok(count($envios) === $antes + 1, 'Control: el XML de cotización válido pasa el candado (transporte falso)');
$ultimo = end($envios);
ok(in_array('SOAPAction: "http://qualitas.com.mx/obtenerNuevaEmision"', $ultimo['encabezados'], true), 'SOAPAction = http://qualitas.com.mx/obtenerNuevaEmision');
ok(str_contains($ultimo['cuerpo'], '<PARAMETRO_DE_PRUEBA>&lt;Movimientos&gt;'), 'El XML de movimientos va escapado dentro del parámetro');

// ─── 4. Respuestas SIMULADAS ───────────────────────────────────────────
echo "\n4. Parseo de respuestas SIMULADAS y clasificación\n";
ok($r['estado'] === QualitasClient::OK, 'SIMULADO ok: CodigoError vacío → OK');
$mv = $r['movimientos'][0] ?? [];
ok(($mv['primas']['PrimaTotal'] ?? null) === 10464.91 && ($mv['primas']['Comision'] ?? null) === 999.99, 'SIMULADO ok: lee PrimaTotal y Comision');
ok(($mv['no_cotizacion'] ?? '') === 'SIMULADO-0001' && count($mv['coberturas'] ?? []) === 2, 'SIMULADO ok: número de cotización y primas por cobertura');
ok(($registros[count($registros) - 1]['estado'] ?? '') === 'OK', 'La llamada quedó en la bitácora');

$plantillaError = file_get_contents(__DIR__ . '/SIMULADO_respuesta_cotizacion_error.xml');
$casos = [
    '7 | Descuento fuera de rango, rango valido: 0 a 55'     => QualitasClient::E_DATOS,
    '202 | El Estado no corresponde con el Código Postal'     => QualitasClient::E_DATOS,
    '2 | No existe el Negocio Registrado en el sistema'       => QualitasClient::E_AUTH,
    '0310--Codigo de Zona No Autorizado para este agente'     => QualitasClient::E_AUTH,
    '100 | Sin acceso por mantenimiento o cierre'             => QualitasClient::E_SISTEMA,
    '0340--No es posible procesar su solicitud'               => QualitasClient::E_SISTEMA,
    'Mensaje sin número'                                      => QualitasClient::E_SISTEMA,
];
foreach ($casos as $codigo => $esperado) {
    $x = QualitasClient::interpretarRespuesta(str_replace('__CODIGO__', htmlspecialchars(htmlspecialchars($codigo, ENT_XML1), ENT_XML1), $plantillaError), 'obtenerNuevaEmision');
    ok($x['estado'] === $esperado && ($x['error']['descripcion'] ?? '') === $codigo, "\"{$codigo}\" → {$esperado}, mensaje intacto", json_encode($x['error'] ?? null, JSON_UNESCAPED_UNICODE));
}

// El código HTTP no decide: un 500 con CodigoError vacío es OK; un 200 con error, no.
$okCon500 = QualitasClient::interpretarRespuesta($respuestaSimulada, 'obtenerNuevaEmision', 500);
ok($okCon500['estado'] === QualitasClient::OK, 'HTTP 500 con CodigoError vacío sigue siendo OK (el HTTP no decide)');
$fault = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>SIMULADO: falla</faultstring></soap:Fault></soap:Body></soap:Envelope>';
ok(QualitasClient::interpretarRespuesta($fault, 'obtenerNuevaEmision', 500)['estado'] === QualitasClient::E_SISTEMA, 'SOAP Fault → SISTEMA');
ok(QualitasClient::interpretarRespuesta('<html>Gateway Timeout</html', 'obtenerNuevaEmision', 504)['estado'] === QualitasClient::E_TIMEOUT, 'HTTP 504 sin XML → TIMEOUT');
ok(QualitasClient::interpretarRespuesta('no es xml', 'obtenerNuevaEmision', 200)['estado'] === QualitasClient::E_SISTEMA, 'Respuesta ilegible → SISTEMA');

foreach ([[28, QualitasClient::E_TIMEOUT], [7, QualitasClient::E_RED]] as [$errno, $esperado]) {
    $c = new QualitasClient(config(), static fn (): array => ['http' => 0, 'cuerpo' => '', 'errno' => $errno, 'error' => "SIMULADO errno {$errno}"], $bitacora);
    ok($c->cotizar($captiva)['estado'] === $esperado, "Error de cURL {$errno} → {$esperado}");
}

// ─── 5. Enmascarado ────────────────────────────────────────────────────
echo "\n5. Credenciales del catálogo enmascaradas en la evidencia\n";
$respuestaSimulada = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><listaTarifasResponse><listaTarifasResult>&lt;salida&gt;&lt;datos&gt;&lt;Elemento&gt;&lt;cTarifa&gt;1102&lt;/cTarifa&gt;&lt;CAMIS&gt;07003&lt;/CAMIS&gt;&lt;cCategoria&gt;100&lt;/cCategoria&gt;&lt;/Elemento&gt;&lt;/datos&gt;&lt;retorno&gt;&lt;codigo&gt;0&lt;/codigo&gt;&lt;descripcion&gt;1&lt;/descripcion&gt;&lt;/retorno&gt;&lt;/salida&gt;</listaTarifasResult></listaTarifasResponse></soap:Body></soap:Envelope>';
$t = $clienteQa->listaTarifas(['marca' => 'VW', 'modelo' => '2005']);
$reg = end($registros);
ok(!str_contains($reg['xml_entrada'], 'usuario-secreto') && !str_contains($reg['xml_entrada'], 'tarifa-secreta'), 'La evidencia no trae cUsuario ni cTarifa');
ok(str_contains($reg['xml_entrada'], '<cUsuario>***</cUsuario>'), 'cUsuario queda como ***');
ok(str_contains(end($envios)['cuerpo'], 'usuario-secreto'), 'Lo que sale sí lleva la credencial (sólo se enmascara la evidencia)');
ok($t['estado'] === QualitasClient::OK && ($t['datos'][0]['CAMIS'] ?? '') === '07003', 'SIMULADO listaTarifas: lee los elementos');

// ─── 6. Producción vacía ───────────────────────────────────────────────
echo "\n6. Configuración\n";
$valores = new ReflectionProperty(Env::class, 'valores');
$valores->setValue(null, ['QUALITAS_AMBIENTE' => 'PRODUCCION', 'QUALITAS_URL_PRODUCCION' => '', 'QUALITAS_NEGOCIO' => '08902', 'QUALITAS_AGENTE' => '0008810']);
ok(str_contains((string) lanza(static fn () => QualitasClient::desdeEnv($transporteProhibido, $bitacora)), 'QUALITAS_URL_PRODUCCION está vacía'), 'Con URL de producción vacía no hay cliente de producción');
$valores->setValue(null, ['QUALITAS_URL_QA' => 'https://qa.prueba.invalid/', 'QUALITAS_NEGOCIO' => '08902', 'QUALITAS_AGENTE' => '0008810', 'QUALITAS_PRONTO_PAGO_DIAS' => '15']);
ok(str_contains((string) lanza(static fn () => QualitasClient::desdeEnv($transporteProhibido, $bitacora)), '1 a 14'), 'Pronto pago de 15 días se rechaza (error 192)');

echo "\n───────────────────────────────────────────────────────────────────\n";
echo $fallas === 0 ? " {$total} pruebas, todas bien.\n" : " {$fallas} de {$total} pruebas FALLARON.\n";

if (isset($opciones['mostrar'], $generados[$opciones['mostrar']])) {
    echo "\n── XML generado: {$opciones['mostrar']} ──\n" . $generados[$opciones['mostrar']] . "\n";
}

exit($fallas === 0 ? 0 : 1);
