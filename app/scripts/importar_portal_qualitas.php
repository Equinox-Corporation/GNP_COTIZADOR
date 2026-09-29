#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * importar_portal_qualitas.php — carga plantilla_captura_portal.csv al
 * catálogo provisional de Qualitas (cat_qua_vehiculos, fuente PORTAL_MANUAL)
 * y a las referencias del portal (cat_qua_referencias_portal).
 *
 * Por omisión SÓLO REVISA: dice qué se insertaría, qué cambiaría y qué filas
 * se rechazan y por qué, sin escribir nada. Con --aplicar escribe.
 * Idempotente: correrlo dos veces con el mismo CSV no duplica nada.
 * No llama a ningún servicio.
 *
 * Uso:
 *   php app/scripts/importar_portal_qualitas.php --archivo=docs/aseguradoras/qualitas/plantilla_captura_portal.csv
 *   php app/scripts/importar_portal_qualitas.php --archivo=... --aplicar [--fecha-fuente=AAAA-MM-DD]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 2));
define('RUTA_APP', RUTA_BASE . '/app');
foreach (['core/Env', 'core/Esquema', 'core/Db', 'aseguradoras/Qualitas/QualitasXml', 'aseguradoras/Qualitas/ImportadorPortal'] as $c) {
    require RUTA_APP . '/' . $c . '.php';
}
Env::cargar(RUTA_BASE . '/config/.env.local');

$op = getopt('', ['archivo:', 'aplicar', 'fecha-fuente:']);
$archivo = (string) ($op['archivo'] ?? '');
if ($archivo === '' || !is_file($archivo)) {
    fwrite(STDERR, "Uso: importar_portal_qualitas.php --archivo=ruta.csv [--aplicar] [--fecha-fuente=AAAA-MM-DD]\n");
    exit(1);
}
$fecha = isset($op['fecha-fuente']) ? (string) $op['fecha-fuente'] : null;
if ($fecha !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    fwrite(STDERR, "--fecha-fuente debe ser AAAA-MM-DD.\n");
    exit(1);
}
$aplicar = isset($op['aplicar']);

$r = ImportadorPortal::importar(Db::get(), $archivo, $aplicar, $fecha);

echo ($aplicar ? 'IMPORTADO' : 'REVISIÓN (no se escribió nada; usa --aplicar para importar)') . ' · ' . basename($archivo) . "\n";
echo "  insertados: {$r['insertados']} · actualizados: {$r['actualizados']} · sin cambios: {$r['sin_cambios']} · rechazadas: " . count($r['rechazadas']) . "\n";
foreach ($r['rechazadas'] as $x) {
    echo "  ✗ fila {$x['fila']} ({$x['vehiculo']}): " . implode('; ', $x['motivos']) . "\n";
}
exit(0);
