<?php
declare(strict_types=1);

/** Arranque común de los scripts de línea de comandos. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 2));
define('RUTA_APP',  RUTA_BASE . '/app');
define('BASE_URL',  '');

require RUTA_APP . '/core/Env.php';
Env::cargar(RUTA_BASE . '/config/.env.local');

foreach (['core/Esquema', 'core/Db', 'core/GnpClient',
          'servicios/CatalogoServicio', 'servicios/CotizacionServicio'] as $c) {
    require RUTA_APP . '/' . $c . '.php';
}

/** Relleno que cuenta acentos bien. */
function pad(string $s, int $n): string
{
    return $s . str_repeat(' ', max(0, $n - mb_strlen($s)));
}

function argumentos(array $argv): array
{
    $a = [];
    foreach (array_slice($argv, 1) as $x) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $x, $m)) {
            $a[$m[1]] = $m[2] ?? '1';
        } else {
            $a[] = $x;
        }
    }
    return $a;
}

function cliente(): GnpClient
{
    try {
        return CotizacionServicio::cliente();
    } catch (Throwable $e) {
        fwrite(STDERR, "Falta configuración: {$e->getMessage()}\n");
        fwrite(STDERR, "Revisa config/.env.local\n");
        exit(1);
    }
}

function bitacora(array $r, string $detalle): void
{
    CotizacionServicio::registrarLlamada($r, null, $detalle);
}
