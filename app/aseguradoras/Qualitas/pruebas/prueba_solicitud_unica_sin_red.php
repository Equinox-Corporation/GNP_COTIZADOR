#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_solicitud_unica_sin_red.php — token de un solo uso en "Cotizar" de
 * Qualitas (SolicitudUnica, decisión de Albert del 2026-09-28). SIN RED y
 * SIN tocar la base real: base temporal que se borra al final.
 *
 * Cada caso cuenta cuántas llamadas llegaron al transporte (falso). La meta
 * es 1 para el primer envío de un formulario y 0 para cualquier repetición.
 * La respuesta del transporte es la REAL de la Captiva desde la pantalla
 * (sys_llamadas.id 136).
 *
 * Uso:
 *   php app/aseguradoras/Qualitas/pruebas/prueba_solicitud_unica_sin_red.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 4));
define('RUTA_APP', RUTA_BASE . '/app');
define('BASE_URL', '');

foreach (['core/Env', 'core/Esquema', 'core/Db', 'core/PdfBasico',
          'plataforma/CotizadorAseguradora', 'plataforma/Resultado', 'plataforma/CandadoEmision',
          'plataforma/RangoDescuento', 'plataforma/SolicitudUnica',
          'aseguradoras/Qualitas/QualitasXml', 'aseguradoras/Qualitas/QualitasClient',
          'aseguradoras/Qualitas/AseguradoraQualitas', 'aseguradoras/Qualitas/QualitasServicio'] as $c) {
    require RUTA_APP . '/' . $c . '.php';
}

$base = sys_get_temp_dir() . '/qualitas_solicitud_' . getmypid() . '.sqlite';
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

$pdo = Db::get();
$real = file_get_contents(glob(RUTA_BASE . '/docs/aseguradoras/qualitas/evidencia/*llamada-136_pantalla_captiva_C_respuesta.xml')[0]);

// Transporte falso que cuenta. $alLlamar permite simular algo que ocurre
// MIENTRAS la primera petición espera a Qualitas (por ejemplo, otra pestaña).
$llamadas = 0;
$modoRed = false;
$alLlamar = null;
$transporte = static function () use (&$llamadas, &$modoRed, &$alLlamar, $real): array {
    $llamadas++;
    if ($alLlamar !== null) {
        $f = $alLlamar;
        $alLlamar = null;
        $f();
    }
    return $modoRed
        ? ['http' => 0, 'cuerpo' => '', 'errno' => 7, 'error' => 'SIMULADO: sin red']
        : ['http' => 200, 'cuerpo' => $real, 'errno' => 0, 'error' => ''];
};
$config = [
    'ambiente' => 'QA', 'url_emision' => 'https://qa.prueba.invalid/WsEmision/WsEmision.asmx', 'parametro_emision' => 'xmlEmision',
    'url_tarifa' => '', 'ns_tarifa' => '', 'catalogo_usuario' => '', 'catalogo_tarifa' => '',
    'negocio' => '08902', 'agente' => '0008810', 'derechos' => '750', 'pronto_pago_dias' => '14', 'tarifa' => 'LINEA', 'timeout' => 5,
];
$modulo = new AseguradoraQualitas(new QualitasClient($config, $transporte), $pdo);
$amplia = (int) $pdo->query("SELECT id FROM cat_qua_paquetes WHERE nombre = 'Amplia'")->fetchColumn();

$USUARIO = 7;
$OTRO    = 8;
/** El POST que manda el formulario de Qualitas (Captiva, 55%). */
$post = static fn (string $token, array $cambios = []): array => $cambios + [
    'solicitud' => $token, 'clave_vehiculo' => '21191', 'modelo' => '2026', 'conductor_cp' => '11590', 'estado' => '9',
    'uso' => '1', 'porcentaje_descuento' => '55', 'paquetes' => [$amplia], 'deducibles' => [1 => '5', 3 => '10'],
];
$emitir = static fn (int $usuario = 7): string => SolicitudUnica::emitir($pdo, $usuario, 'QUALITAS', 'cotizar');
$enviar = static fn (array $p, int $usuario = 7): array => QualitasServicio::cotizarDesdeFormulario($p, $usuario, $modulo);
$cuantas = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM cot_cotizaciones WHERE aseguradora = 'QUALITAS'")->fetchColumn();
$caso = static function (string $titulo, callable $f) use (&$llamadas): void {
    $antes = $llamadas;
    $f();
    echo "      → llamadas a Qualitas en este caso: " . ($llamadas - $antes) . "\n";
};

echo "═══════════════════════════════════════════════════════════════════\n";
echo " Qualitas · token de un solo uso en \"Cotizar\" (SIN RED, base temporal)\n";
echo "═══════════════════════════════════════════════════════════════════\n";

echo "\n0. El token\n";
$t1 = $emitir();
$t2 = $emitir();
ok(preg_match('/^[0-9a-f]{32}$/', $t1) === 1 && $t1 !== $t2, 'Cada formulario mostrado lleva un token nuevo (32 hex)');
$v = $pdo->query("SELECT (julianday(vence_en) - julianday(creada_en)) * 24 * 60 FROM sys_solicitudes WHERE token = '{$t1}'")->fetchColumn();
ok(abs((float) $v - SolicitudUnica::VIGENCIA_MINUTOS) < 1, 'Vence ' . SolicitudUnica::VIGENCIA_MINUTOS . ' minutos después de mostrarse');

echo "\n1. Doble envío del mismo formulario (doble clic)\n";
$caso('doble', static function () use ($emitir, $enviar, $post, &$llamadas, $cuantas): void {
    $t = $emitir();
    $antes = $llamadas;
    $c0 = $cuantas();
    $r1 = $enviar($post($t));
    $r2 = $enviar($post($t));
    ok($r1['accion'] === 'REDIRIGIR' && $llamadas - $antes === 1, 'Primer envío: cotiza (1 llamada)');
    ok($r2['accion'] === 'REDIRIGIR' && $r2['cotizacion_id'] === $r1['cotizacion_id'] && $r2['aviso'] === 'Esta cotización ya se había enviado.', 'Segundo envío: 0 llamadas, va a la MISMA cotización con "Esta cotización ya se había enviado"');
    ok($cuantas() - $c0 === 1, 'Una sola cotización creada');
});

echo "\n2. Reenvío tras \"atrás\" (el navegador vuelve a mandar el formulario ya enviado)\n";
$caso('atras', static function () use ($emitir, $enviar, $post, &$llamadas): void {
    $t = $emitir();
    $r1 = $enviar($post($t));
    $antes = $llamadas;
    $r2 = $enviar($post($t, ['porcentaje_descuento' => '40']));   // aunque cambien datos, el formulario es el mismo
    ok($llamadas === $antes && $r2['accion'] === 'REDIRIGIR' && $r2['cotizacion_id'] === $r1['cotizacion_id'], 'Reenvío: 0 llamadas, redirige a la cotización ya hecha');
});

echo "\n3. Dos pestañas con el mismo formulario\n";
$caso('pestanas', static function () use ($emitir, $enviar, $post, &$llamadas, &$alLlamar): void {
    $t = $emitir();
    $segunda = null;
    // Mientras la primera pestaña espera a Qualitas, la segunda envía el mismo formulario.
    $alLlamar = static function () use (&$segunda, $enviar, $post, $t): void {
        $segunda = $enviar($post($t));
    };
    $antes = $llamadas;
    $primera = $enviar($post($t));
    ok($llamadas - $antes === 1, 'Entre las dos pestañas, 1 llamada');
    ok(($segunda['accion'] ?? '') === 'PROCESANDO', 'La segunda, mientras la primera sigue: "Tu cotización se está procesando", 0 llamadas', json_encode($segunda));
    $antes = $llamadas;
    $tercera = $enviar($post($t));
    ok($llamadas === $antes && $tercera['accion'] === 'REDIRIGIR' && $tercera['cotizacion_id'] === $primera['cotizacion_id'], 'La segunda pestaña después de terminar: 0 llamadas, a la misma cotización');
});

echo "\n4. Token de otro usuario\n";
$caso('otro', static function () use ($emitir, $enviar, $post, &$llamadas, $OTRO): void {
    $t = $emitir();
    $antes = $llamadas;
    $r = $enviar($post($t), $OTRO);
    ok($llamadas === $antes && $r['accion'] === 'FORMULARIO' && str_contains($r['error'], 'no es válido'), 'Otro usuario: 0 llamadas, "el formulario no es válido para tu sesión"');
    $antes = $llamadas;
    $r = $enviar($post($t));
    ok($llamadas - $antes === 1, 'El token sigue sirviendo a su dueño (1 llamada)');
});

echo "\n5. Token vencido\n";
$caso('vencido', static function () use ($emitir, $enviar, $post, &$llamadas, $pdo): void {
    $t = $emitir();
    $pdo->exec("UPDATE sys_solicitudes SET vence_en = datetime('now','localtime','-1 minutes') WHERE token = '{$t}'");
    $antes = $llamadas;
    $r = $enviar($post($t));
    ok($llamadas === $antes && $r['accion'] === 'FORMULARIO' && str_contains($r['error'], 'venció'), 'Vencido: 0 llamadas, "el formulario venció"');
});

echo "\n6. Cotización nueva legítima (formulario abierto de nuevo)\n";
$r1 = $enviar($post($emitir()));            // una cotización ya hecha con el formulario anterior
$caso('nueva', static function () use ($emitir, $enviar, $post, &$llamadas, $cuantas, $r1): void {
    $b = $emitir();                         // abrir el formulario otra vez = token nuevo
    $antes = $llamadas;
    $c0 = $cuantas();
    $r2 = $enviar($post($b));
    ok($llamadas - $antes === 1 && $r2['cotizacion_id'] !== $r1['cotizacion_id'] && $cuantas() - $c0 === 1, 'Formulario nuevo: 1 llamada y una cotización nueva');
});

echo "\n7. La primera petición falló (sin red): el mismo token no reintenta\n";
$caso('fallo', static function () use ($emitir, $enviar, $post, &$llamadas, &$modoRed, $pdo): void {
    $t = $emitir();
    $modoRed = true;
    $antes = $llamadas;
    $r1 = $enviar($post($t));
    $modoRed = false;
    ok($llamadas - $antes === 1 && $r1['accion'] === 'REDIRIGIR', 'Primer envío sin red: 1 llamada (RED), queda la cotización en ERROR');
    ok($pdo->query("SELECT estado FROM sys_solicitudes WHERE token = '{$t}'")->fetchColumn() === 'FALLIDA', 'El token queda FALLIDA');
    $antes = $llamadas;
    $r2 = $enviar($post($t));
    ok($llamadas === $antes && $r2['cotizacion_id'] === $r1['cotizacion_id'] && str_contains($r2['aviso'], 'abre el formulario otra vez'), 'Reenvío: 0 llamadas; "para intentarlo de nuevo, abre el formulario otra vez"');
});

echo "\n8. Error de captura (descuento 60, fuera del rango 0–55)\n";
$caso('captura', static function () use ($emitir, $enviar, $post, &$llamadas, $pdo): void {
    $t = $emitir();
    $antes = $llamadas;
    $r1 = $enviar($post($t, ['porcentaje_descuento' => '60']));
    ok($llamadas === $antes && $r1['accion'] === 'FORMULARIO' && str_contains($r1['error'], '0 a 55'), 'Primer envío: 0 llamadas, vuelve al formulario con el rango');
    ok($pdo->query("SELECT estado FROM sys_solicitudes WHERE token = '{$t}'")->fetchColumn() === 'RECHAZADA', 'El token queda RECHAZADA (el formulario vuelve con uno nuevo)');
    $antes = $llamadas;
    $r2 = $enviar($post($t, ['porcentaje_descuento' => '55']));
    ok($llamadas === $antes && $r2['accion'] === 'FORMULARIO', 'El token viejo, aun con datos corregidos: 0 llamadas (hay que usar el formulario nuevo)');
});

echo "\n9. Petición interrumpida (quedó EN_CURSO más de " . SolicitudUnica::EN_CURSO_MAX_MINUTOS . " minutos)\n";
$caso('interrumpida', static function () use ($emitir, $enviar, $post, &$llamadas, $pdo): void {
    $t = $emitir();
    SolicitudUnica::tomar($pdo, $t, 7, 'QUALITAS', 'cotizar');     // la primera "se cayó" sin cerrar
    $pdo->exec("UPDATE sys_solicitudes SET usada_en = datetime('now','localtime','-11 minutes') WHERE token = '{$t}'");
    $antes = $llamadas;
    $r = $enviar($post($t));
    ok($llamadas === $antes && $r['accion'] === 'FORMULARIO' && str_contains($r['error'], 'interrumpió'), 'Interrumpida: 0 llamadas, "revisa el historial antes de volver a cotizar"');
});

echo "\n10. Tokens que no sirven\n";
$caso('invalidos', static function () use ($enviar, $post, &$llamadas): void {
    $antes = $llamadas;
    $sin  = $enviar($post(''));
    $inv  = $enviar($post(str_repeat('a', 32)));
    $raro = $enviar($post("' OR 1=1 --"));
    ok($llamadas === $antes && $sin['accion'] === 'FORMULARIO' && $inv['accion'] === 'FORMULARIO' && $raro['accion'] === 'FORMULARIO', 'Sin token, inventado o con texto raro: 0 llamadas');
});
$tOtraAccion = SolicitudUnica::emitir($pdo, 7, 'QUALITAS', 'formas_pago');
ok(SolicitudUnica::tomar($pdo, $tOtraAccion, 7, 'QUALITAS', 'cotizar')['resultado'] === SolicitudUnica::INVALIDO, 'Un token de otra acción no sirve para "cotizar"');
$tOtraAseg = SolicitudUnica::emitir($pdo, 7, 'HDI', 'cotizar');
ok(SolicitudUnica::tomar($pdo, $tOtraAseg, 7, 'QUALITAS', 'cotizar')['resultado'] === SolicitudUnica::INVALIDO, 'Un token de otra aseguradora no sirve para Qualitas');

echo "\n11. Limpieza\n";
$cotAntes = $cuantas();
$resAntes = (int) $pdo->query('SELECT COUNT(*) FROM cot_resultados')->fetchColumn();
// emitir() también limpia: primero se emiten los tokens y DESPUÉS se envejecen las filas.
$viejoEmitido = $emitir();
$recienVencido = $emitir();
$pdo->exec("UPDATE sys_solicitudes SET creada_en = datetime('now','localtime','-31 days')
             WHERE token IN (SELECT token FROM sys_solicitudes WHERE estado = 'TERMINADA' LIMIT 2)");
$pdo->exec("UPDATE sys_solicitudes SET vence_en = datetime('now','localtime','-2 days') WHERE token = '{$viejoEmitido}'");
$pdo->exec("UPDATE sys_solicitudes SET vence_en = datetime('now','localtime','-1 hours') WHERE token = '{$recienVencido}'");
$filas = (int) $pdo->query('SELECT COUNT(*) FROM sys_solicitudes')->fetchColumn();
$borradas = SolicitudUnica::limpiar($pdo);
ok($borradas === 3 && (int) $pdo->query('SELECT COUNT(*) FROM sys_solicitudes')->fetchColumn() === $filas - 3, 'Borra los usados de más de 30 días y los emitidos vencidos hace más de 1 día (3 filas)', "borradas: {$borradas}");
ok((int) $pdo->query("SELECT COUNT(*) FROM sys_solicitudes WHERE token = '{$recienVencido}'")->fetchColumn() === 1, 'Un formulario vencido hace una hora se conserva (todavía responde "venció")');
ok($cuantas() === $cotAntes && (int) $pdo->query('SELECT COUNT(*) FROM cot_resultados')->fetchColumn() === $resAntes, 'Las cotizaciones y sus resultados no se tocan');

echo "\n12. A: el botón se bloquea al enviar (revisión del código de las vistas)\n";
$vCot = file_get_contents(RUTA_APP . '/vistas/qualitas_cotizar.php');
$vRes = file_get_contents(RUTA_APP . '/vistas/qualitas_resultado.php');
ok(str_contains($vCot, 'name="solicitud" value="<?= h($solicitud) ?>"'), 'El formulario de cotizar manda su token');
ok(str_contains($vCot, "btn.disabled = true") && str_contains($vCot, "'Cotizando…'") && str_contains($vCot, "'pageshow'"), '"Cotizar": se bloquea al enviar y se rehabilita al volver');
ok(str_contains($vRes, 'class="form-un-envio"') && str_contains($vRes, "btn.disabled = true") && str_contains($vRes, "'pageshow'"), '"Ver otras formas de pago": igual');

echo "\n───────────────────────────────────────────────────────────────────\n";
echo "Llamadas totales a Qualitas en toda la prueba: {$llamadas}\n";
echo $fallas === 0 ? " {$total} pruebas, todas bien.\n" : " {$fallas} de {$total} pruebas FALLARON.\n";
exit($fallas === 0 ? 0 : 1);
