#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_respaldo_migracion_sin_red.php — respaldo automático antes de migrar
 * (Esquema::asegurar con la ruta de la base; Albert, 2026-09-29, ADR-003).
 *
 * SIN RED y SIN tocar la base real: todo pasa en una carpeta temporal que se
 * borra al final. No carga config/.env.local.
 *
 * Comprueba:
 *   - con cambios pendientes, el respaldo se crea ANTES de migrar y está completo;
 *   - sin cambios pendientes, no hay respaldo;
 *   - si el respaldo falla, no se migra y el error lo dice;
 *   - las bases temporales (sin ruta) nunca se respaldan;
 *   - Db::get() pasa la ruta de su base.
 *
 * Uso:
 *   php app/scripts/prueba_respaldo_migracion_sin_red.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 2));
define('RUTA_APP', RUTA_BASE . '/app');
require RUTA_APP . '/core/Env.php';
require RUTA_APP . '/core/Esquema.php';
require RUTA_APP . '/core/Db.php';

$fallas = 0;
$total  = 0;
function ok(bool $cond, string $que, string $detalle = ''): void
{
    global $fallas, $total;
    $total++;
    if (!$cond) {
        $fallas++;
    }
    echo ($cond ? '  ✓ ' : '  ✗ ') . $que . (!$cond && $detalle !== '' ? "\n      {$detalle}" : '') . "\n";
}

$dir = sys_get_temp_dir() . '/prueba_respaldo_' . bin2hex(random_bytes(4));
mkdir($dir);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    @rmdir($dir);
});
$abrir = static fn (string $f): PDO => new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$respaldos = static fn (string $base): array => glob($base . '.bak_auto_pre_migracion_*') ?: [];
$tabla = static fn (PDO $p, string $t): bool => $p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $p->quote($t))->fetchColumn() > 0;
$huella = static fn (PDO $p): ?string => $tabla($p, 'sys_esquema') ? (string) $p->query('SELECT huella FROM sys_esquema')->fetchColumn() : null;

echo "\n1. Base nueva (sin tablas)\n";
$base = "{$dir}/nueva.sqlite";
$pdo = $abrir($base);
Esquema::asegurar($pdo, $base);
ok($respaldos($base) === [], 'Base vacía: no hay nada que respaldar, no se crea respaldo');
ok($huella($pdo) !== null && strlen((string) $huella($pdo)) === 64, 'Queda registrada la huella del esquema en sys_esquema');
Esquema::asegurar($pdo, $base);
ok($respaldos($base) === [], 'Segunda apertura sin cambios: ningún respaldo');

echo "\n2. Con cambios pendientes: respaldo antes de migrar\n";
// Simula una base que quedó atrás: le falta una tabla que el esquema crea y su huella es vieja.
$pdo->exec('DROP TABLE cat_qua_referencias_portal');
$pdo->exec("UPDATE sys_esquema SET huella = 'vieja'");
$pdo->exec("INSERT INTO sys_usuarios (usuario, nombre, clave_hash) VALUES ('prueba', 'Prueba', 'x')");
$filasAntes = (int) $pdo->query('SELECT COUNT(*) FROM sys_usuarios')->fetchColumn();
Esquema::asegurar($pdo, $base);
$r = $respaldos($base);
ok(count($r) === 1 && preg_match('/\.bak_auto_pre_migracion_\d{8}_\d{6}$/', $r[0] ?? '') === 1, 'Se creó un respaldo con el nombre acordado', implode(', ', $r));
$bak = $abrir($r[0] ?? "{$dir}/sin_respaldo.sqlite");   // sin respaldo: base vacía, las pruebas de abajo fallan
ok(!$tabla($bak, 'cat_qua_referencias_portal') && $huella($bak) === 'vieja', 'El respaldo es de ANTES de migrar (sin la tabla faltante, huella vieja)');
ok($tabla($pdo, 'cat_qua_referencias_portal') && $huella($pdo) !== 'vieja', 'Después se migró: la tabla volvió y la huella es la actual');
ok((string) $bak->query('PRAGMA quick_check')->fetchColumn() === 'ok' && (int) $bak->query('SELECT COUNT(*) FROM sys_usuarios')->fetchColumn() === $filasAntes, 'El respaldo está completo (quick_check ok, mismas filas)');
$bak = null;
Esquema::asegurar($pdo, $base);
ok(count($respaldos($base)) === 1, 'Ya migrada: la siguiente apertura no respalda otra vez');

echo "\n3. Si el respaldo falla, no se migra\n";
$pdo->exec('DROP TABLE cat_qua_referencias_portal');
$pdo->exec("UPDATE sys_esquema SET huella = 'vieja'");
$error = '';
try {
    Esquema::asegurar($pdo, $base, "{$dir}/no_existe");
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
ok(str_contains($error, 'No se aplicó la migración') && str_contains($error, 'respaldo automático') && str_contains($error, 'sin cambios'), 'Lanza un error claro', $error);
ok(!$tabla($pdo, 'cat_qua_referencias_portal') && $huella($pdo) === 'vieja', 'La base quedó sin migrar (ni la tabla ni la huella cambiaron)');
// Nombre ocupado (otra base, para no chocar con el respaldo del punto 2 en el mismo segundo):
// no sobrescribe el archivo que ya estaba y no migra.
$otra = "{$dir}/ocupada.sqlite";
$po = $abrir($otra);
Esquema::asegurar($po, $otra);
$po->exec('DROP TABLE cat_qua_referencias_portal');
$po->exec("UPDATE sys_esquema SET huella = 'vieja'");
$ocupados = [];
for ($s = 0; $s < 3; $s++) {
    $ocupados[] = $o = $otra . '.bak_auto_pre_migracion_' . date('Ymd_His', time() + $s);
    file_put_contents($o, 'no tocar');
}
$error = '';
try {
    Esquema::asegurar($po, $otra);
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
$intactos = array_filter($ocupados, static fn ($o) => file_get_contents($o) === 'no tocar');
ok($error === '' && count($intactos) === 3 && $tabla($po, 'cat_qua_referencias_portal'),
    'Si el nombre ya existe: no lo sobrescribe, usa el siguiente (_2) y migra', $error);
ok(is_file($ocupados[0] . '_2') || is_file($ocupados[1] . '_2') || is_file($ocupados[2] . '_2'), 'El respaldo quedó como …_2');
// Todos los nombres ocupados (…, _2 … _9): no sobrescribe ninguno y no migra.
$po->exec('DROP TABLE cat_qua_referencias_portal');
$po->exec("UPDATE sys_esquema SET huella = 'vieja'");
foreach ($ocupados as $o) {
    for ($n = 2; $n <= 9; $n++) {
        if (!is_file("{$o}_{$n}")) {
            file_put_contents("{$o}_{$n}", 'no tocar');
        }
    }
}
$error = '';
try {
    Esquema::asegurar($po, $otra);
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
ok(str_contains($error, 'ya existen') && file_get_contents($ocupados[0]) === 'no tocar' && !$tabla($po, 'cat_qua_referencias_portal') && $huella($po) === 'vieja',
    'Sin nombre libre: error, nada sobrescrito y sin migrar', $error);
$po = null;
Esquema::asegurar($pdo, $base);
ok($tabla($pdo, 'cat_qua_referencias_portal') && $huella($pdo) !== 'vieja', 'Con la carpeta disponible, respalda y migra');
echo "\n4. Bases temporales de las pruebas (sin ruta)\n";
$tmp = "{$dir}/temporal.sqlite";
$pt = $abrir($tmp);
Esquema::asegurar($pt);
Esquema::asegurar($pt);
ok($respaldos($tmp) === [] && $huella($pt) === null, 'Sin ruta: ni respaldo ni huella, como antes');

echo "\n5. Db::get() pasa la ruta de su base\n";
// La base temporal del punto 4 ya tiene tablas y no tiene huella: está pendiente.
file_put_contents("{$dir}/.env.prueba", "DB_PATH={$tmp}\n");
Env::cargar("{$dir}/.env.prueba");
$pt = null;
Db::get();
ok(count($respaldos($tmp)) === 1, 'Al abrir por Db::get() con migración pendiente, respalda primero');
ok($huella(Db::get()) !== null, 'y registra la huella');

echo "\n───────────────────────────────────────────────────────────────────\n";
echo $fallas === 0 ? " {$total} pruebas, todas bien.\n" : " {$fallas} de {$total} pruebas FALLARON.\n";
exit($fallas === 0 ? 0 : 1);
