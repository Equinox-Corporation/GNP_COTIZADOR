#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_etapa4_sin_red.php — adaptador, resultado común, descuento
 * configurable y formas de pago. SIN NINGUNA LLAMADA y SIN tocar la base
 * real: trabaja sobre una base temporal nueva que se borra al final.
 *
 * La respuesta de Qualitas que usa el transporte falso es la REAL de la
 * Captiva en QA (sys_llamadas.id 123, docs/aseguradoras/qualitas/evidencia/),
 * no una simulada. Las variantes (sin comisión, dos recibos) se derivan de
 * ella y están marcadas como SIMULADAS.
 *
 * Uso:
 *   php app/aseguradoras/Qualitas/pruebas/prueba_etapa4_sin_red.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 4));
define('RUTA_APP', RUTA_BASE . '/app');
define('BASE_URL', '');

foreach (['core/Env', 'core/Esquema', 'core/Db', 'core/PdfBasico',
          'plataforma/CotizadorAseguradora', 'plataforma/Resultado', 'plataforma/CandadoEmision', 'plataforma/RangoDescuento',
          'aseguradoras/Qualitas/QualitasXml', 'aseguradoras/Qualitas/QualitasClient',
          'aseguradoras/Qualitas/AseguradoraQualitas', 'aseguradoras/Qualitas/QualitasServicio'] as $c) {
    require RUTA_APP . '/' . $c . '.php';
}

// Base temporal: Db::get() la crea con Esquema::asegurar(). Nunca la real.
$base = sys_get_temp_dir() . '/qualitas_etapa4_' . getmypid() . '.sqlite';
(new ReflectionProperty(Env::class, 'valores'))->setValue(null, ['DB_PATH' => $base, 'APP_ENV' => 'local']);
(new ReflectionProperty(Env::class, 'cargado'))->setValue(null, true);
register_shutdown_function(static function () use ($base): void {
    foreach (['', '-wal', '-shm'] as $s) {
        @unlink($base . $s);
    }
});

$fallas = 0;
$total  = 0;
function ok(bool $cond, string $que, string $detalle = ''): void
{
    global $fallas, $total;
    $total++;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $que . (!$cond && $detalle !== '' ? "\n      {$detalle}" : '') . "\n";
    if (!$cond) {
        $fallas++;
    }
}

// ─── Transporte falso con la respuesta REAL de la Captiva (id 123) ─────
$real = file_get_contents(RUTA_BASE . '/docs/aseguradoras/qualitas/evidencia/20260928_102542_llamada-123_cotizar_captiva_respuesta.xml');
$respuesta = $real;
$envios = [];
$transporte = static function (string $url, string $cuerpo) use (&$envios, &$respuesta): array {
    $envios[] = $cuerpo;
    return ['http' => 200, 'cuerpo' => $respuesta, 'errno' => 0, 'error' => ''];
};
/** El XML de movimientos que salió en el envío $i (desescapado). */
function enviado(array $envios, int $i): string
{
    preg_match('#<xmlEmision>(.*)</xmlEmision>#s', $envios[$i], $m);
    return html_entity_decode($m[1] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$config = [
    'ambiente' => 'QA', 'url_emision' => 'https://qa.prueba.invalid/WsEmision/WsEmision.asmx', 'parametro_emision' => 'xmlEmision',
    'url_tarifa' => '', 'ns_tarifa' => '', 'catalogo_usuario' => '', 'catalogo_tarifa' => '',
    'negocio' => '08902', 'agente' => '0008810', 'derechos' => '750', 'pronto_pago_dias' => '14', 'tarifa' => 'LINEA', 'timeout' => 5,
];
$pdo = Db::get();
// Bitácora por omisión (INSERT en sys_llamadas de la base TEMPORAL).
$cliente = new QualitasClient($config, $transporte);
$modulo  = new AseguradoraQualitas($cliente, $pdo);

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Qualitas · Etapa 4 SIN RED (base temporal, respuesta real id 123)\n";
echo "═══════════════════════════════════════════════════════════════════\n";

// ─── 1. Esquema ────────────────────────────────────────────────────────
echo "\n1. Tablas nuevas en una base vacía\n";
ok((int) $pdo->query("SELECT COUNT(*) FROM sys_descuentos WHERE aseguradora='QUALITAS'")->fetchColumn() === 5, 'sys_descuentos: 5 filas sembradas para Qualitas');
ok((int) $pdo->query('SELECT COUNT(*) FROM sys_descuentos_cambios')->fetchColumn() === 5, 'La semilla quedó registrada en sys_descuentos_cambios');
$paq = $pdo->query('SELECT nombre, codigo, activo FROM cat_qua_paquetes ORDER BY orden')->fetchAll(PDO::FETCH_ASSOC);
ok(array_column($paq, 'nombre') === ['Amplia', 'Limitada', 'Básica'], 'cat_qua_paquetes: Amplia, Limitada, Básica');
ok($paq[2]['activo'] === 0 && $paq[2]['codigo'] === '', 'Básica: sin código y deshabilitada');
$ids = array_column($pdo->query('SELECT nombre, id FROM cat_qua_paquetes')->fetchAll(PDO::FETCH_ASSOC), 'id', 'nombre');
Esquema::asegurar($pdo);
ok($pdo->query("SELECT estado FROM sys_aseguradoras WHERE clave='QUALITAS'")->fetchColumn() === 'EN_INTEGRACION', 'Qualitas queda EN_INTEGRACION a la segunda conexión');
ok((int) $pdo->query('SELECT COUNT(*) FROM sys_descuentos_cambios')->fetchColumn() === 5, 'Repetir el esquema no vuelve a sembrar');

// ─── 2. RangoDescuento ─────────────────────────────────────────────────
echo "\n2. RangoDescuento\n";
ok(RangoDescuento::resolver($pdo, 'QUALITAS', 'TODOS') === ['minimo' => 0, 'maximo' => 55, 'fila' => 'TODOS'], 'Qualitas TODOS → 0–55');
ok(RangoDescuento::resolver($pdo, 'QUALITAS', 'CAMION')['maximo'] === 30 && RangoDescuento::resolver($pdo, 'QUALITAS', 'MOTO')['maximo'] === 20, 'Camiones 0–30, motos 0–20');
$pdo->exec("DELETE FROM sys_descuentos WHERE aseguradora='QUALITAS' AND tipo_vehiculo='PICKUP'");
ok(RangoDescuento::resolver($pdo, 'QUALITAS', 'PICKUP')['fila'] === 'TODOS', 'Sin fila del tipo → cae en TODOS');
ok(RangoDescuento::resolver($pdo, 'HDI', 'AUTO') === ['minimo' => 0, 'maximo' => 0, 'fila' => null], 'Sin ninguna fila → 0–0, nunca "sin límite"');
ok(RangoDescuento::validar($pdo, 'QUALITAS', 'TODOS', 55) === null, '55% se acepta');
$m = (string) RangoDescuento::validar($pdo, 'QUALITAS', 'TODOS', 56);
ok(str_contains($m, '0 a 55'), '56% se rechaza y dice el rango permitido', $m);
foreach (['5.5', '-1', '', 'abc'] as $malo) {
    ok(RangoDescuento::validar($pdo, 'QUALITAS', 'TODOS', $malo) !== null, "\"{$malo}\" se rechaza");
}
ok(str_contains((string) RangoDescuento::validar($pdo, 'HDI', 'TODOS', 10), 'no se permite descuento'), 'Aseguradora sin rango: no se permite descuento');
foreach ([['40', '30'], ['0', '101'], ['a', '10'], ['-5', '10']] as [$mi, $ma]) {
    ok(RangoDescuento::guardar($pdo, 'QUALITAS', 'AUTO', $mi, $ma, 1, 'prueba') !== null, "guardar {$mi}–{$ma} se rechaza");
}
ok(RangoDescuento::guardar($pdo, 'QUALITAS', 'OTRO', 0, 10, 1, 'prueba') !== null, 'Tipo de vehículo desconocido se rechaza');
ok(RangoDescuento::guardar($pdo, 'NOEXISTE', 'AUTO', 0, 10, 1, 'prueba') !== null, 'Aseguradora inexistente se rechaza');
ok(RangoDescuento::guardar($pdo, 'QUALITAS', 'AUTO', '5', '50', 7, 'Admin de prueba') === null, 'guardar 5–50 se acepta');
$c = $pdo->query('SELECT * FROM sys_descuentos_cambios ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($c['usuario'] === 'Admin de prueba' && (int) $c['usuario_id'] === 7 && (int) $c['minimo_antes'] === 0 && (int) $c['maximo_antes'] === 55 && (int) $c['maximo'] === 50, 'El cambio quedó registrado con quién, antes y después');
$n = (int) $pdo->query('SELECT COUNT(*) FROM sys_descuentos_cambios')->fetchColumn();
RangoDescuento::guardar($pdo, 'QUALITAS', 'AUTO', '5', '50', 7, 'Admin de prueba');
ok((int) $pdo->query('SELECT COUNT(*) FROM sys_descuentos_cambios')->fetchColumn() === $n, 'Guardar lo mismo no registra un cambio');

// ─── 3. Adaptador ──────────────────────────────────────────────────────
echo "\n3. AseguradoraQualitas::cotizar()\n";
$sol = static fn (array $d = [], ?array $paquetes = null, array $ded = []): array => [
    'clave_vehiculo' => '21191', 'modelo' => 2026, 'conductor_cp' => '11590',
    'datos_aseguradora' => $d + ['estado' => '9', 'uso' => '1', 'servicio' => '1', 'porcentaje_descuento' => 55],
    'paquetes' => $paquetes ?? [$ids['Amplia']],
    'deducibles' => $ded,
];

$antes = count($envios);
$r = $modulo->cotizar($sol(['porcentaje_descuento' => 56]));
ok($r['estado'] === 'DATOS' && str_contains($r['error']['descripcion'], '0 a 55') && count($envios) === $antes, 'Descuento fuera de rango: DATOS con el rango y no sale nada');

$r = $modulo->cotizar($sol([], [$ids['Básica']]));
ok($r['estado'] === 'DATOS' && str_contains($r['error']['descripcion'], 'todavía no') && count($envios) === $antes, 'Básica: "todavía no" y no sale nada');

$r = $modulo->cotizar($sol([], null, [1 => '7']));
ok($r['estado'] === 'DATOS' && str_contains($r['error']['descripcion'], 'Permitidos: 3, 5, 10') && count($envios) === $antes, 'Deducible DM 7 (no autorizado): no sale nada');

$r = $modulo->cotizar($sol(['porcentaje_descuento' => 40]));
ok(count($envios) === $antes + 1, 'Amplia: una llamada');
$x = enviado($envios, count($envios) - 1);
ok(str_contains($x, '<PorcentajeDescuento>40</PorcentajeDescuento>'), 'Lo capturado (40) es lo que va en PorcentajeDescuento');
ok(str_contains($x, "NoConsideracion=\"5\">\n\t\t\t\t<TipoRegla>0</TipoRegla>\n\t\t\t\t<ValorRegla>14</ValorRegla>"), 'Siempre va la consideración 05 con 14 días');
ok(str_contains($x, '<FormaPago>C</FormaPago>'), 'Por omisión, contado');
/** @var Resultado $res */
$res = $r['paquetes'][0] ?? null;
ok($r['estado'] === 'OK' && $res instanceof Resultado, 'Respuesta real id 123 → OK y un Resultado');
ok($res->totalPagar === 10464.91 && $res->primaNeta === 8440.28 && $res->derechos === 750.0 && $res->iva === 1443.44, 'Precio = PrimaTotal 10,464.91; neta, derechos e IVA en sus columnas');
ok($res->conceptos['recargo'] === -168.81 && $res->conceptos['pronto_pago_dias'] === 14, 'Recargo (pronto pago) −168.81 y días de pronto pago en conceptos');
ok($res->conceptos['comision_porcentaje'] === 11.0 && $res->conceptos['comision_importe'] === 928.43, 'Comisión tal como llega: 11 (porcentaje) y 928.43 (importe)');
ok($res->conceptos['no_cotizacion'] === '1219390564' && $res->numPagos === 1, 'Número de cotización de Qualitas y un pago');
$dm = $res->coberturas[0] ?? null;
ok($dm !== null && $dm->nombre === 'Daños Materiales' && $dm->sumaAsegurada === '$468,000' && $dm->deducible === '5%', 'Cobertura legible: Daños Materiales · $468,000 · 5%', json_encode($dm, JSON_UNESCAPED_UNICODE));
ok(($res->coberturas[2]->deducible ?? '') === '0 UMA', 'RC: deducible 0 UMA');

$antes = count($envios);
$r = $modulo->cotizar($sol([], [$ids['Amplia'], $ids['Limitada']], [1 => '10', 3 => '20']));
ok(count($envios) === $antes + 2 && count($r['paquetes']) === 2, 'Amplia + Limitada: dos llamadas, dos Resultado');
$xa = enviado($envios, $antes);
$xl = enviado($envios, $antes + 1);
ok(str_contains($xa, '<Paquete>1</Paquete>') && str_contains($xl, '<Paquete>3</Paquete>'), 'Cada llamada lleva su paquete (1 y 3)');
ok(str_contains($xa, "<Coberturas NoCobertura=\"1\">\n\t\t\t\t<SumaAsegurada>0</SumaAsegurada>\n\t\t\t\t<TipoSuma>0</TipoSuma>\n\t\t\t\t<Deducible>10</Deducible>"), 'Deducible DM elegido (10) va en la petición');
ok(!str_contains($xl, 'NoCobertura="1"'), 'Limitada no manda Daños Materiales (Anexo 5: N)');

// Variantes SIMULADAS derivadas de la respuesta real.
$respuesta = str_replace('&lt;Comision&gt;11&lt;/Comision&gt;', '&lt;Comision /&gt;', $real);
$respuesta = preg_replace('#&lt;Recibos NoRecibo="1"&gt;.*?&lt;/Recibos&gt;#s', '', $respuesta);
$r = $modulo->cotizar($sol());
ok($r['paquetes'][0]->conceptos['comision_porcentaje'] === null && $r['paquetes'][0]->conceptos['comision_importe'] === null, 'SIMULADO sin comisión ni recibo: los dos quedan null ("no disponible"), sin valor por omisión');
preg_match('#&lt;Recibos NoRecibo="1"&gt;.*?&lt;/Recibos&gt;#s', $real, $rec);
$respuesta = str_replace($rec[0], $rec[0] . str_replace('NoRecibo="1"', 'NoRecibo="2"', $rec[0]), $real);
$r = $modulo->cotizar($sol());
ok($r['paquetes'][0]->numPagos === 2 && $r['paquetes'][0]->conceptos['comision_importe'] === null && count($r['paquetes'][0]->conceptos['recibos']) === 2, 'SIMULADO dos recibos: importe por recibo en "recibos", sin sumarlos');
$respuesta = $real;

// ─── 4. QualitasServicio: guardar en las tablas comunes ────────────────
echo "\n4. QualitasServicio (tablas comunes, aseguradora = QUALITAS)\n";
$cotAntes = (int) $pdo->query('SELECT COUNT(*) FROM cot_cotizaciones')->fetchColumn();
$envAntes = count($envios);
$s = QualitasServicio::cotizar(['clave_vehiculo' => '21191', 'modelo' => '2026', 'conductor_cp' => '11590', 'estado' => '9', 'porcentaje_descuento' => '60', 'paquetes' => [$ids['Amplia']]], 1, $modulo);
ok(!$s['ok'] && str_contains($s['mensaje'], '0 a 55') && count($envios) === $envAntes && (int) $pdo->query('SELECT COUNT(*) FROM cot_cotizaciones')->fetchColumn() === $cotAntes, 'Fuera de rango en servidor: no se guarda ni se envía nada');

$s = QualitasServicio::cotizar(['clave_vehiculo' => '21191', 'modelo' => '2026', 'conductor_cp' => '11590', 'estado' => '9', 'porcentaje_descuento' => '55', 'paquetes' => [$ids['Amplia']]], 1, $modulo);
ok($s['ok'], 'Cotización guardada', $s['mensaje']);
$cot = $pdo->query('SELECT * FROM cot_cotizaciones WHERE id = ' . (int) $s['cotizacion_id'])->fetch(PDO::FETCH_ASSOC);
ok($cot['aseguradora'] === 'QUALITAS' && $cot['estado'] === 'COTIZADA' && $cot['folio'] === '1219390564' && $cot['clave_vehiculo'] === '21191', 'cot_cotizaciones: QUALITAS, COTIZADA, folio y clave AMIS');
$da = json_decode($cot['datos_aseguradora_json'], true);
ok($da['porcentaje_descuento'] === 55 && $da['rango_descuento']['maximo'] === 55, 'datos_aseguradora_json guarda el porcentaje usado y el rango vigente');
ok($cot['vence_en'] === (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'), 'Vence en 7 días [PENDIENTE]');
$res = $pdo->query('SELECT * FROM cot_resultados WHERE cotizacion_id = ' . (int) $s['cotizacion_id'])->fetch(PDO::FETCH_ASSOC);
ok($res['aseguradora'] === 'QUALITAS' && (float) $res['total_pagar'] === 10464.91, 'cot_resultados: total_pagar 10,464.91, aseguradora QUALITAS');
ok((int) $pdo->query('SELECT COUNT(*) FROM cot_resultado_coberturas WHERE resultado_id = ' . (int) $res['id'])->fetchColumn() === 8, 'cot_resultado_coberturas: 8 coberturas');
$ll = $pdo->query('SELECT aseguradora, cotizacion_id FROM sys_llamadas ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($ll['aseguradora'] === 'QUALITAS' && (int) $ll['cotizacion_id'] === (int) $s['cotizacion_id'], 'sys_llamadas ligada a la cotización, aseguradora QUALITAS');

// ─── 5. Otras formas de pago, sólo a pedido ────────────────────────────
echo "\n5. Otras formas de pago\n";
ok(count($envios) === $envAntes + 1, 'Cotizar hizo UNA llamada (sólo contado, no las cuatro)');
$f = QualitasServicio::otraFormaDePago((int) $res['id'], 'S', $modulo);
ok($f['ok'] && count($envios) === $envAntes + 2, 'Semestral: una llamada más, a pedido', $f['mensaje']);
ok(str_contains(enviado($envios, count($envios) - 1), '<FormaPago>S</FormaPago>'), 'La llamada lleva FormaPago S');
$conc = json_decode((string) $pdo->query('SELECT conceptos_json FROM cot_resultados WHERE id = ' . (int) $res['id'])->fetchColumn(), true);
ok(isset($conc['formas_pago']['S']['total_pagar'], $conc['formas_pago']['S']['llamada_id']), 'Se guarda en conceptos_json.formas_pago.S con su llamada');
ok((float) $pdo->query('SELECT total_pagar FROM cot_resultados WHERE id = ' . (int) $res['id'])->fetchColumn() === 10464.91, 'El precio de contado no cambia');
QualitasServicio::otraFormaDePago((int) $res['id'], 'S', $modulo);
ok(count($envios) === $envAntes + 2, 'Pedir otra vez semestral no repite la llamada');
ok(!QualitasServicio::otraFormaDePago((int) $res['id'], 'C', $modulo)['ok'], 'Sólo S, T o M');

// ─── 6. imprimir() y catalogo() ────────────────────────────────────────
echo "\n6. imprimir() y catalogo()\n";
$p = $modulo->imprimir(
    ['clave_vehiculo' => '21191', 'modelo' => 2026, 'conductor_cp' => '11590', 'datos_aseguradora' => ['porcentaje_descuento' => 55], 'vence_en' => $cot['vence_en']],
    ['paquete' => 'Amplia', 'total_pagar' => 10464.91, 'prima_neta' => 8440.28, 'derechos' => 750.0, 'iva' => 1443.44,
     'conceptos' => $conc, 'coberturas' => [['nombre' => 'Daños Materiales', 'suma_asegurada' => '$468,000', 'deducible' => '5%']]]
);
ok($p['estado'] === 'OK' && str_starts_with($p['pdf'], '%PDF'), 'imprimir(): PDF propio');
$antes = count($envios);
$cat = $modulo->catalogo('MARCAS');
ok($cat['estado'] === 'AUTH' && str_starts_with($cat['error']['descripcion'], 'Catálogo sin credenciales configuradas') && count($envios) === $antes, 'catalogo(): sin cUsuario/cTarifa responde AUTH, "catálogo sin credenciales configuradas", y no llama', $cat['error']['descripcion']);
ok(!str_contains(strtolower($cat['error']['descripcion']), 'rechaz'), 'El mensaje no dice que las credenciales fueron rechazadas');

// ─── 7. Error real de Qualitas (sys_llamadas.id 126) ───────────────────
echo "\n7. Error de negocio REAL (id 126: descuento 60, \"0007-- Descuento fuera de Rango\")\n";
// Se manda 55 (válido para el rango) y el transporte falso contesta con la
// respuesta real del error: lo que se prueba es cómo se trata esa respuesta.
$respuesta = file_get_contents(RUTA_BASE . '/docs/aseguradoras/qualitas/evidencia/20260928_121028_llamada-126_error_descuento_60_respuesta.xml');
$textoQualitas = '0007-- Descuento fuera de Rango, rango valido 0 a 55';

$r = $modulo->cotizar($sol());
ok($r['estado'] === 'DATOS' && $r['paquetes'] === [], 'Adaptador: DATOS y ningún Resultado');
ok(str_contains($r['error']['descripcion'], $textoQualitas), 'El texto de Qualitas llega tal cual', $r['error']['descripcion']);

$cotAntes = (int) $pdo->query('SELECT COUNT(*) FROM cot_cotizaciones')->fetchColumn();
$s = QualitasServicio::cotizar(['clave_vehiculo' => '21191', 'modelo' => '2026', 'conductor_cp' => '11590', 'estado' => '9', 'porcentaje_descuento' => '55', 'paquetes' => [$ids['Amplia']]], 1, $modulo);
$cotErr = $pdo->query('SELECT * FROM cot_cotizaciones WHERE id = ' . (int) ($s['cotizacion_id'] ?? 0))->fetch(PDO::FETCH_ASSOC);
ok(!$s['ok'] && $cotErr !== false && $cotErr['estado'] === 'ERROR' && str_contains((string) $cotErr['error_desc'], $textoQualitas), 'La cotización queda en ERROR con el mensaje de Qualitas');
ok((int) $pdo->query('SELECT COUNT(*) FROM cot_resultados WHERE cotizacion_id = ' . (int) $cotErr['id'])->fetchColumn() === 0, 'cot_resultados: ningún renglón (ni el Derecho 750 como si fuera precio)');
ok($cotErr['folio'] === null, 'Sin folio: el error no trae NoCotizacion');
$vh = $pdo->query('SELECT desde, hasta, paquetes FROM v_cotizaciones WHERE id = ' . (int) $cotErr['id'])->fetch(PDO::FETCH_ASSOC);
ok($vh['desde'] === null && $vh['hasta'] === null && (int) $vh['paquetes'] === 0, 'El historial no muestra precio (desde/hasta vacíos)');

// La pantalla de resultado, renderizada sin servidor con las mismas funciones que public/index.php.
if (!function_exists('h')) {
    function h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function dinero(?float $n): string { return $n === null ? '—' : '$' . number_format($n, 2); }
    function url(string $r, array $p = []): string { return '/?' . http_build_query(array_merge(['r' => $r], $p)); }
}
$vista = (static function (array $cot, array $datos, array $resultados, bool $vencida, string $aviso, bool $puedeCotizar): string {
    ob_start();
    require RUTA_APP . '/vistas/qualitas_resultado.php';
    return (string) ob_get_clean();
})($cotErr, json_decode((string) $cotErr['datos_aseguradora_json'], true) ?: [], [], false, '', true);
ok(str_contains($vista, h($textoQualitas)), 'Pantalla: muestra el mensaje de Qualitas tal cual');
ok(!str_contains($vista, 'class="precio"') && !str_contains($vista, '750'), 'Pantalla: ningún precio, ni el 750');
$respuesta = $real;

echo "\n───────────────────────────────────────────────────────────────────\n";
echo $fallas === 0 ? " {$total} pruebas, todas bien.\n" : " {$fallas} de {$total} pruebas FALLARON.\n";
exit($fallas === 0 ? 0 : 1);
