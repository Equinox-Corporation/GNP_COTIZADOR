#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verificar_copia_sin_red.php — revisa el .env.local de una COPIA del sistema
 * antes de levantarla para pruebas de regresión. Si algo falla, no se
 * levanta el servidor.
 *
 * Reglas de trabajo (docs/aseguradoras/00-reglas-de-trabajo.md):
 *   1. El .env nunca se imprime: aquí sólo salen nombres de llave y OK/FALLA,
 *      jamás un valor.
 *   2. Verificación de puertos en copias: toda URL de servicio de una
 *      aseguradora (llaves *_URL*) tiene que estar vacía o apuntar a
 *      127.0.0.1/localhost en un puerto que esté CERRADO de verdad; y la base
 *      (DB_PATH) no puede ser la real.
 *
 * Uso:
 *   php app/scripts/verificar_copia_sin_red.php <ruta al .env.local de la copia>
 * Sale con 0 si todo está bien, 1 si algo falla.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

$ruta = $argv[1] ?? '';
if ($ruta === '' || !is_file($ruta)) {
    fwrite(STDERR, "Uso: verificar_copia_sin_red.php <.env.local de la copia>\n");
    exit(1);
}

$real = realpath(dirname(__DIR__, 2) . '/config/.env.local');
if ($real !== false && realpath($ruta) === $real) {
    echo "FALLA  el archivo es el .env.local REAL del proyecto, no el de una copia\n";
    exit(1);
}

$valores = [];
foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
    $linea = trim($linea);
    if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $linea, 2);
    $valores[trim($k)] = trim($v, " \t\"'");
}

$fallas = 0;
$informe = static function (bool $ok, string $llave, string $motivo) use (&$fallas): void {
    if (!$ok) {
        $fallas++;
    }
    printf("%-6s %-28s %s\n", $ok ? 'OK' : 'FALLA', $llave, $motivo);
};

foreach ($valores as $k => $v) {
    if (!preg_match('/_URL(_|$)/', $k)) {
        continue;
    }
    if ($v === '') {
        $informe(true, $k, 'vacía');
        continue;
    }
    $p = parse_url($v);
    $host = strtolower((string) ($p['host'] ?? ''));
    if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
        $informe(false, $k, 'apunta fuera del equipo');
        continue;
    }
    $puerto = (int) ($p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80));
    $s = @fsockopen('127.0.0.1', $puerto, $errno, $errstr, 1.0);
    if ($s !== false) {
        fclose($s);
        $informe(false, $k, "local, pero el puerto {$puerto} está ABIERTO");
        continue;
    }
    $informe(true, $k, "local, puerto {$puerto} cerrado");
}

$db = (string) ($valores['DB_PATH'] ?? '');
$dbReal = realpath(dirname(__DIR__, 2) . '/datos/cotizador_gnp.sqlite');
if ($db === '') {
    $informe(false, 'DB_PATH', 'vacía: la copia usaría una base junto a su código; se exige ruta explícita');
} elseif ($dbReal !== false && realpath($db) === $dbReal) {
    $informe(false, 'DB_PATH', 'es la base REAL');
} else {
    $informe(is_file($db), 'DB_PATH', is_file($db) ? 'copia, existe' : 'no existe');
}

echo $fallas === 0 ? "Copia sin red: se puede levantar.\n" : "{$fallas} falla(s): NO levantar esta copia.\n";
exit($fallas === 0 ? 0 : 1);
