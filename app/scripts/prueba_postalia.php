#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_postalia.php — UNA consulta a Postalia (SEPOMEX en línea) para ver
 * qué devuelve un código postal. Pregunta decisiva para la consideración 40
 * de Qualitas: ¿trae los CÓDIGOS de SEPOMEX de municipio y colonia (como
 * c_mnpio e id_asenta_cpcons) o sólo nombres?
 *
 * Misma forma de llamada que NEXO (ContactosController::cpBuscar):
 * GET {POSTALIA_API_URL}/codigos-postales/{cp}, "Authorization: Bearer {clave}".
 * A diferencia de NEXO en modo depuración, aquí la verificación SSL NUNCA se
 * desactiva: se manda una clave.
 *
 * - La clave nunca se imprime ni se guarda (docs/aseguradoras/00-reglas-de-trabajo.md).
 * - La URL base tampoco se guarda en la evidencia: se escribe como <POSTALIA_API_URL>.
 * - La respuesta cruda se guarda en docs/aseguradoras/qualitas/evidencia/.
 * - Es una llamada real: exige --autorizado (autorización de Albert en el chat).
 *
 * Uso:
 *   php app/scripts/prueba_postalia.php --cp=11590 --autorizado
 *
 * Sólo para probar este script sin red (no llama a nada):
 *   php app/scripts/prueba_postalia.php --cp=11590 --respuesta-simulada=archivo.json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 2));
require RUTA_BASE . '/app/core/Env.php';
Env::cargar(RUTA_BASE . '/config/.env.local');

$op = getopt('', ['cp:', 'autorizado', 'respuesta-simulada:']);
$cp = preg_replace('/\D/', '', (string) ($op['cp'] ?? '11590'));
if (strlen($cp) !== 5) {
    fwrite(STDERR, "CP inválido: debe tener 5 dígitos.\n");
    exit(1);
}

$base  = rtrim(Env::get('POSTALIA_API_URL'), '/');
$clave = Env::get('POSTALIA_API_KEY');
$ruta  = '/codigos-postales/' . $cp;
$simulada = $op['respuesta-simulada'] ?? null;

echo "Postalia · CP {$cp}" . ($simulada !== null ? ' · RESPUESTA SIMULADA (sin red)' : '') . "\n";

if ($simulada === null) {
    if (!isset($op['autorizado'])) {
        fwrite(STDERR, "Llamada real a Postalia: falta --autorizado (y la autorización en el chat).\n");
        exit(1);
    }
    if ($base === '' || $clave === '') {
        fwrite(STDERR, "Falta POSTALIA_API_URL o POSTALIA_API_KEY en config/.env.local.\n");
        exit(1);
    }
    $inicio = microtime(true);
    $ch = curl_init($base . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $clave, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,   // nunca se desactiva: viaja una clave
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $cuerpo = curl_exec($ch);
    $http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    curl_close($ch);
    $ms = (int) round((microtime(true) - $inicio) * 1000);
    $cuerpo = is_string($cuerpo) ? $cuerpo : '';
} else {
    $cuerpo = (string) file_get_contents($simulada);
    [$http, $errno, $error, $ms] = [200, 0, '', 0];
}

// Por si la respuesta repitiera la clave: nunca sale de aquí.
if ($clave !== '') {
    $cuerpo = str_replace($clave, '***', $cuerpo);
}

echo "  HTTP {$http} · {$ms} ms · " . strlen($cuerpo) . " bytes" . ($errno !== 0 ? " · error de red ({$errno}): {$error}" : '') . "\n";

// Evidencia (no para respuestas simuladas).
if ($simulada === null) {
    $dir = RUTA_BASE . '/docs/aseguradoras/qualitas/evidencia';
    $nombre = $dir . '/' . date('Ymd_His') . "_postalia_cp{$cp}";
    file_put_contents("{$nombre}_peticion.txt",
        "GET <POSTALIA_API_URL>{$ruta}\nAuthorization: Bearer ***\nAccept: application/json\n\n"
        . "HTTP {$http} · {$ms} ms" . ($errno !== 0 ? " · error de red ({$errno}): {$error}" : '') . "\n");
    file_put_contents("{$nombre}_respuesta.json", $cuerpo);
    echo '  evidencia: ' . basename($nombre) . "_{peticion.txt,respuesta.json}\n";
}

$json = json_decode($cuerpo, true);
if (!is_array($json)) {
    echo "  La respuesta no es JSON. Primeros 300 caracteres:\n  " . substr($cuerpo, 0, 300) . "\n";
    exit($errno !== 0 || $http >= 400 ? 1 : 0);
}

// 1) Todos los campos, con su ruta y tipo (los índices de listas se juntan en []).
$campos = [];
$recorrer = static function ($v, string $ruta) use (&$recorrer, &$campos): void {
    if (is_array($v)) {
        $lista = array_is_list($v);
        if ($ruta !== '') {
            $campos[$ruta] = ($lista ? 'lista' : 'objeto') . ' (' . count($v) . ')';
        }
        foreach ($v as $k => $h) {
            $recorrer($h, $ruta === '' ? (string) $k : ($lista ? "{$ruta}[]" : "{$ruta}.{$k}"));
        }
        return;
    }
    $tipo = get_debug_type($v);
    $ejemplo = is_scalar($v) ? (string) $v : '';
    $campos[$ruta] ??= "{$tipo} · p. ej. \"" . mb_substr($ejemplo, 0, 40) . '"';
};
$recorrer($json, '');
echo "\n1) Todos los campos que devuelve (" . count($campos) . "):\n";
foreach ($campos as $r => $d) {
    echo "   {$r}  →  {$d}\n";
}

// 2) Estado, municipio y colonias, con todo lo que traiga cada una.
$data = $json['data'] ?? $json;
echo "\n2) Valores del CP {$cp}:\n";
foreach ($data as $k => $v) {
    if ($k === 'colonias') {
        continue;
    }
    echo "   {$k}: " . (is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE)) . "\n";
}
$colonias = $data['colonias'] ?? [];
echo '   colonias (' . (is_countable($colonias) ? count($colonias) : 0) . "):\n";
foreach ((array) $colonias as $i => $c) {
    echo '     ' . ($i + 1) . '. ' . (is_array($c) ? json_encode($c, JSON_UNESCAPED_UNICODE) : (string) $c) . "\n";
}

// 3) Pregunta decisiva: ¿hay campos que parezcan CÓDIGOS de SEPOMEX?
$patron = '/(^|[._\[\]])(c_|id_|cve|clave|codigo|cod_|inegi|mnpio|municipio_id|colonia_id|asenta)/i';
$codigos = array_filter(array_keys($campos), static fn (string $r) => preg_match($patron, $r) === 1 && !preg_match('/(^|\.)codigo_postal$/i', $r));
echo "\n3) ¿Trae códigos de SEPOMEX (municipio y colonia)?\n";
if ($codigos === []) {
    echo "   NO se ve ningún campo de código: sólo nombres. Revisa la lista del punto 1 por si acaso.\n";
} else {
    echo "   Campos candidatos a código:\n";
    foreach ($codigos as $r) {
        echo "     {$r}  →  {$campos[$r]}\n";
    }
    echo "   Hay que confirmar cuáles son los de municipio (c_mnpio, 3 dígitos) y colonia (id_asenta_cpcons, 4 dígitos).\n";
}

exit($errno !== 0 || $http >= 400 ? 1 : 0);
