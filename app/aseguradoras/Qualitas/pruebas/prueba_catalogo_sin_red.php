#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_catalogo_sin_red.php — catálogo provisional de vehículos de Qualitas
 * (cat_qua_vehiculos), importador del CSV del portal y cascada en pantalla.
 * SIN RED y SIN tocar la base real: base temporal que se borra al final.
 *
 * El CSV de ejemplo (pruebas/ejemplos/portal_ejemplo.csv) trae los 3
 * vehículos ya probados en QA (21191 Captiva, 11333 NP300, 68133 Vento) con
 * los datos de sus PDF de Qualitas, más tres filas que deben rechazarse: AMIS
 * con letra, AMIS con dígito verificador equivocado y una fila incompleta.
 *
 * Uso:
 *   php app/aseguradoras/Qualitas/pruebas/prueba_catalogo_sin_red.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 4));
define('RUTA_APP', RUTA_BASE . '/app');
define('BASE_URL', '');

foreach (['core/Env', 'core/Esquema', 'core/Db', 'core/PdfBasico',
          'plataforma/CotizadorAseguradora', 'plataforma/Resultado', 'plataforma/CandadoEmision', 'plataforma/Aseguradoras',
          'plataforma/RangoDescuento', 'plataforma/SolicitudUnica',
          'aseguradoras/Qualitas/QualitasXml', 'aseguradoras/Qualitas/QualitasClient',
          'aseguradoras/Qualitas/AseguradoraQualitas', 'aseguradoras/Qualitas/QualitasServicio',
          'aseguradoras/Qualitas/ImportadorPortal'] as $c) {
    require RUTA_APP . '/' . $c . '.php';
}

$base = sys_get_temp_dir() . '/qualitas_catalogo_' . getmypid() . '.sqlite';
(new ReflectionProperty(Env::class, 'valores'))->setValue(null, ['DB_PATH' => $base, 'APP_ENV' => 'local']);
(new ReflectionProperty(Env::class, 'cargado'))->setValue(null, true);
$temporales = [$base, $base . '-wal', $base . '-shm'];
register_shutdown_function(static function () use (&$temporales): void {
    foreach ($temporales as $t) {
        @unlink($t);
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

// Lo mínimo de public/index.php para dibujar la vista como el navegador.
final class Auth
{
    public static function token(): string { return 'TOKEN-PRUEBA'; }
}
function h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $r, array $p = []): string { return '/?' . http_build_query(array_merge(['r' => $r], $p)); }
function vistaComoIndex(string $nombre, array $datos = []): string
{
    extract($datos, EXTR_SKIP);
    ob_start();
    require RUTA_APP . '/vistas/' . $nombre . '.php';
    return (string) ob_get_clean();
}
function pantallaCaptura(): string
{
    $m = new AseguradoraQualitas(new QualitasClient($GLOBALS['config'], static fn (): array => throw new LogicException('sin red')), Db::get());
    return vistaComoIndex('qualitas_cotizar', [
        'error' => '', 'previo' => [], 'paquetes' => $m->paquetes(), 'deducibles' => $m->deduciblesElegibles(),
        'rango' => RangoDescuento::resolver(Db::get(), 'QUALITAS', RangoDescuento::TODOS),
        'estadoAseg' => 'EN_INTEGRACION', 'solicitud' => str_repeat('a', 32),
    ]);
}

$pdo = Db::get();
$csv = __DIR__ . '/ejemplos/portal_ejemplo.csv';
$config = [
    'ambiente' => 'QA', 'url_emision' => 'https://qa.prueba.invalid/WsEmision/WsEmision.asmx', 'parametro_emision' => 'xmlEmision',
    'url_tarifa' => '', 'ns_tarifa' => '', 'catalogo_usuario' => '', 'catalogo_tarifa' => '',
    'negocio' => '08902', 'agente' => '0008810', 'derechos' => '750', 'pronto_pago_dias' => '14', 'tarifa' => 'LINEA', 'timeout' => 5,
];
$cuenta = static fn (string $t): int => (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Qualitas · catálogo provisional de vehículos (SIN RED, base temporal)\n";
echo "═══════════════════════════════════════════════════════════════════\n";

echo "\n1. Tablas\n";
ok($cuenta('cat_qua_vehiculos') === 0 && $cuenta('cat_qua_referencias_portal') === 0, 'cat_qua_vehiculos y cat_qua_referencias_portal existen y empiezan vacías');

echo "\n2. Clave AMIS\n";
foreach ([['21191', '21191', null], ['211918', '21191', null], ['21191-8', '21191', null], ['7003', '07003', null]] as [$ent, $esp]) {
    [$a, $e] = ImportadorPortal::amis($ent);
    ok($a === $esp && $e === null, "\"{$ent}\" → {$esp}");
}
[, $e] = ImportadorPortal::amis('211917');
ok($e !== null && str_contains($e, 'debe ser 8'), '"211917": dígito verificador equivocado (debe ser 8)', (string) $e);
[, $e] = ImportadorPortal::amis('2119X');
ok($e !== null && str_contains($e, 'sólo dígitos'), '"2119X": sólo dígitos', (string) $e);
[, $e] = ImportadorPortal::amis('1234567');
ok($e !== null, '"1234567": demasiados dígitos');

echo "\n3. Importar: primero sólo revisar\n";
$r = ImportadorPortal::importar($pdo, $csv, false, '2026-09-23');
ok($r['insertados'] === 3 && count($r['rechazadas']) === 3, 'Revisión: 3 se importarían y 3 se rechazan', json_encode($r, JSON_UNESCAPED_UNICODE));
$motivos = [];
foreach ($r['rechazadas'] as $x) {
    $motivos[$x['fila']] = implode('; ', $x['motivos']);
    echo "      fila {$x['fila']} ({$x['vehiculo']}): {$motivos[$x['fila']]}\n";
}
ok((bool) array_filter($motivos, static fn ($m) => str_contains($m, 'sólo dígitos')), 'Dice cuál fila y por qué: AMIS con letra');
ok((bool) array_filter($motivos, static fn ($m) => str_contains($m, 'dígito verificador')), 'Dice cuál fila y por qué: dígito verificador');
ok((bool) array_filter($motivos, static fn ($m) => str_contains($m, 'faltan amis')), 'Dice cuál fila y por qué: fila incompleta (qué campos faltan)');
ok($cuenta('cat_qua_vehiculos') === 0, 'La revisión no escribe nada');

echo "\n4. Importar de verdad (y otra vez: idempotente)\n";
$r = ImportadorPortal::importar($pdo, $csv, true, '2026-09-23');
ok($r['insertados'] === 3 && $cuenta('cat_qua_vehiculos') === 3 && $cuenta('cat_qua_referencias_portal') === 3, '3 vehículos y 3 referencias');
$veh = $pdo->query("SELECT * FROM cat_qua_vehiculos WHERE amis = '21191'")->fetch(PDO::FETCH_ASSOC);
ok($veh['marca'] === 'CHEVROLET' && $veh['linea'] === 'CAPTIVA' && $veh['version'] === 'PREMIER B' && (int) $veh['modelo'] === 2026 && $veh['fuente'] === 'PORTAL_MANUAL' && $veh['fecha_fuente'] === '2026-09-23' && $veh['tipo_vehiculo'] === null, 'Captiva: marca, línea, versión, modelo, fuente PORTAL_MANUAL, fecha; tipo vacío');
$ref = $pdo->query("SELECT * FROM cat_qua_referencias_portal WHERE amis = '21191'")->fetch(PDO::FETCH_ASSOC);
$cobs = array_column(json_decode($ref['coberturas_json'], true), null, 'cobertura');
ok((float) $ref['importe_total'] === 10464.91 && (float) $ref['subtotal'] === 9021.47 && (float) $ref['tasa_fin_pf'] === -168.81, 'Referencia Captiva: total 10,464.91, subtotal 9,021.47, TASA FIN. P.F. -168.81');
ok(($cobs[1]['prima'] ?? null) === 8086.34 && ($cobs[1]['suma'] ?? '') === '468000' && ($cobs[1]['deducible'] ?? '') === '5', 'Referencia Captiva: DM 468,000 · 5% · prima 8,086.34');
ok(json_decode($ref['formas_pago_json'], true)['semestral']['primer'] === 5882.85, 'Referencia Captiva: semestral primer pago 5,882.85');
$r2 = ImportadorPortal::importar($pdo, $csv, true, '2026-09-23');
ok($r2['insertados'] === 0 && $r2['sin_cambios'] === 3 && $cuenta('cat_qua_vehiculos') === 3 && $cuenta('cat_qua_referencias_portal') === 3, 'Segunda corrida: 0 insertados, 3 sin cambios, nada duplicado');
$copia = sys_get_temp_dir() . '/portal_cambiado_' . getmypid() . '.csv';
$temporales[] = $copia;
file_put_contents($copia, str_replace('PREMIER B', 'PREMIER B AWD', (string) file_get_contents($csv)));
$r3 = ImportadorPortal::importar($pdo, $copia, true, '2026-09-24');
ok($r3['actualizados'] === 1 && $r3['sin_cambios'] === 2 && $pdo->query("SELECT version FROM cat_qua_vehiculos WHERE amis = '21191'")->fetchColumn() === 'PREMIER B AWD', 'Si cambia un dato: 1 actualizado, sin duplicar');
ImportadorPortal::importar($pdo, $csv, true, '2026-09-23');

echo "\n5. Catálogo para la pantalla\n";
$cat = QualitasServicio::catalogoVehiculos();
ok($cat['fuente'] === 'PORTAL_MANUAL' && $cat['provisional'] === true && count($cat['vehiculos']) === 3, 'Fuente PORTAL_MANUAL, provisional, 3 vehículos');
ok(array_values(array_unique(array_column($cat['vehiculos'], 'marca'))) === ['CHEVROLET', 'NISSAN', 'VENTO'], 'Marcas en orden: CHEVROLET, NISSAN, VENTO');
$pdo->exec("INSERT INTO cat_qua_vehiculos (amis, modelo, marca, linea, version, fuente, fecha_fuente) VALUES ('21191', 2026, 'CHEVROLET', 'CAPTIVA', 'PREMIER (WSTARIFA)', 'WSTARIFA', '2026-10-01')");
$cat = QualitasServicio::catalogoVehiculos();
ok($cat['fuente'] === 'WSTARIFA' && $cat['provisional'] === false && count($cat['vehiculos']) === 1, 'Con filas de wsTarifa, la pantalla usa sólo WSTARIFA y deja de ser provisional');
ok(QualitasServicio::vehiculoCatalogo('21191', 2026)['fuente'] === 'WSTARIFA', 'Para la misma AMIS y modelo, prefiere WSTARIFA');
$pdo->exec("DELETE FROM cat_qua_vehiculos WHERE fuente = 'WSTARIFA'");
ok(QualitasServicio::vehiculoCatalogo('21191', 2026)['fuente'] === 'PORTAL_MANUAL' && QualitasServicio::vehiculoCatalogo('99999', 2026) === null, 'Sin wsTarifa: PORTAL_MANUAL; AMIS que no está: null');

echo "\n6. La cotización guarda la descripción del catálogo\n";
$real = file_get_contents(glob(RUTA_BASE . '/docs/aseguradoras/qualitas/evidencia/*llamada-136_pantalla_captiva_C_respuesta.xml')[0]);
$modulo = new AseguradoraQualitas(new QualitasClient($config, static fn (): array => ['http' => 200, 'cuerpo' => $real, 'errno' => 0, 'error' => '']), $pdo);
$amplia = (int) $pdo->query("SELECT id FROM cat_qua_paquetes WHERE nombre = 'Amplia'")->fetchColumn();
$s = QualitasServicio::cotizar(['clave_vehiculo' => '21191', 'modelo' => '2026', 'conductor_cp' => '11590', 'estado' => '9', 'porcentaje_descuento' => '55', 'paquetes' => [$amplia]], 1, $modulo);
$cot = $pdo->query('SELECT * FROM cot_cotizaciones WHERE id = ' . (int) $s['cotizacion_id'])->fetch(PDO::FETCH_ASSOC);
ok($cot['descripcion_veh'] === 'CHEVROLET CAPTIVA PREMIER B 2026 · AMIS 21191', 'Vehículo del catálogo: "CHEVROLET CAPTIVA PREMIER B 2026 · AMIS 21191"', (string) $cot['descripcion_veh']);
ok((json_decode($cot['datos_aseguradora_json'], true)['vehiculo_catalogo']['fuente'] ?? '') === 'PORTAL_MANUAL', 'datos_aseguradora_json dice de qué fuente salió');
$ctx = QualitasServicio::contextoResultado((int) $s['cotizacion_id']);
$pdf = $modulo->imprimir($ctx['cot'] + ['datos_aseguradora' => $ctx['datosAseg']], $ctx['resultados'][0])['pdf'];
ok(str_contains($pdf, '(CHEVROLET CAPTIVA PREMIER B 2026 · AMIS 21191)') || str_contains(mb_convert_encoding($pdf, 'UTF-8', 'Windows-1252'), 'CHEVROLET CAPTIVA PREMIER B 2026'), 'El PDF muestra marca, línea y versión');
$s2 = QualitasServicio::cotizar(['clave_vehiculo' => '11111', 'modelo' => '2026', 'conductor_cp' => '11590', 'estado' => '9', 'porcentaje_descuento' => '55', 'paquetes' => [$amplia]], 1, $modulo);
ok($pdo->query('SELECT descripcion_veh FROM cot_cotizaciones WHERE id = ' . (int) $s2['cotizacion_id'])->fetchColumn() === 'Clave AMIS 11111 · 2026', 'AMIS que no está en el catálogo: "Clave AMIS 11111 · 2026", como antes');

echo "\n7. Pantalla de captura\n";
$html = pantallaCaptura();
ok(str_contains($html, 'id="cascada-vehiculo"') && str_contains($html, 'id="cat-marca"') && str_contains($html, 'id="cat-version"'), 'Con catálogo: cascada marca → línea → año → versión');
ok(str_contains($html, 'Catálogo provisional (portal de Qualitas, 2026-09-23): verifica la versión.'), 'Aviso: "Catálogo provisional (portal de Qualitas, 2026-09-23): verifica la versión"');
ok(str_contains($html, '"amis":"21191"') || str_contains($html, '"amis":"21191"'), 'Los 3 vehículos van en la página (21191)');
ok(preg_match('/<input name="clave_vehiculo" id="clave_vehiculo" required[^>]*>/', $html, $m) === 1 && !str_contains($m[0], 'readonly') && !str_contains($m[0], 'disabled'), 'La AMIS sigue visible y editable');
ok(!str_contains($html, 'Catálogo de vehículos pendiente'), 'Ya no dice "catálogo pendiente"');
$pdo->exec('UPDATE cat_qua_vehiculos SET activo = 0');
$html = pantallaCaptura();
ok(!str_contains($html, 'cascada-vehiculo') && str_contains($html, 'Catálogo de vehículos pendiente') && str_contains($html, 'name="clave_vehiculo"'), 'Sin filas cargadas: la pantalla funciona como hoy (AMIS a mano)');

echo "\n───────────────────────────────────────────────────────────────────\n";
echo $fallas === 0 ? " {$total} pruebas, todas bien.\n" : " {$fallas} de {$total} pruebas FALLARON.\n";
exit($fallas === 0 ? 0 : 1);
