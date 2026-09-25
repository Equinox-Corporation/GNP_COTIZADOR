#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * construir_cat_gnp.php — Paso 1 del mapeo con GNP (cat_comercial_diseño.md):
 * construye `cat_gnp`, el catálogo de GNP tal como GNP lo publica, a nivel
 * línea + año (sin versión — ese nivel queda fuera a propósito).
 *
 * Columnas tal cual las pide el diseño:
 *   id_gnp TEXT PK  — clave interna. GNP identifica una línea+año por
 *                     tipo_vehiculo+armadora+carroceria+modelo (su
 *                     clavemarca ya trae la versión, que aquí no aplica),
 *                     así que id_gnp es esa combinación: "AUT|GM|69|2015".
 *   marca_gnp    — armadora_nombre tal cual lo manda GNP
 *   tipo_gnp     — carroceria_nombre (la línea comercial del lado GNP)
 *   modelo_gnp   — año modelo
 *   descripcion  — GNP no trae una descripción aparte a este nivel; queda NULL
 *
 * cat_comercial.db es el DESTINO (se escribe ahí). cotizador_gnp.sqlite se
 * adjunta en modo solo lectura (mode=ro): jamás se toca cat_vehiculos.
 *
 * Uso:
 *   php app/scripts/construir_cat_gnp.php
 *   php app/scripts/construir_cat_gnp.php "ruta\cat_comercial.db" "ruta\cotizador_gnp.sqlite"
 *
 * Idempotente: correrlo de nuevo actualiza los renglones existentes, no los duplica.
 */

require __DIR__ . '/_arranque.php';

$args = argumentos($argv);
$posicionales = array_values(array_filter($args, static fn($k) => is_int($k), ARRAY_FILTER_USE_KEY));
$rutaComercial = $posicionales[0] ?? RUTA_APP . '/core/cat_comercial.db';
$rutaGnp       = $posicionales[1] ?? Env::get('DB_PATH', RUTA_BASE . '/datos/cotizador_gnp.sqlite');

if (!is_file($rutaComercial)) {
    fwrite(STDERR, "No encuentro cat_comercial.db en:\n   {$rutaComercial}\n");
    exit(1);
}
if (!is_file($rutaGnp)) {
    fwrite(STDERR, "No encuentro cotizador_gnp.sqlite en:\n   {$rutaGnp}\n");
    exit(1);
}

echo "Destino (se escribe) : {$rutaComercial}\n";
echo "Fuente  (solo lectura): {$rutaGnp}\n";
echo str_repeat('═', 66), "\n";

$pdo = new PDO('sqlite:' . $rutaComercial, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("PRAGMA encoding = 'UTF-8'");
$pdo->exec('PRAGMA foreign_keys = ON');

// Se adjunta en SÓLO LECTURA: imposible tocar cat_vehiculos por accidente.
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $rutaGnp) . "?mode=ro' AS gnp");

$pdo->exec('
    CREATE TABLE IF NOT EXISTS cat_gnp (
        id_gnp      TEXT NOT NULL,
        marca_gnp   TEXT NOT NULL,
        tipo_gnp    TEXT NOT NULL,
        modelo_gnp  TEXT NOT NULL,
        descripcion TEXT,
        PRIMARY KEY (id_gnp)
    )
');

$antes = (int) $pdo->query('SELECT COUNT(*) FROM cat_gnp')->fetchColumn();

$n = $pdo->exec("
    INSERT INTO cat_gnp (id_gnp, marca_gnp, tipo_gnp, modelo_gnp, descripcion)
    SELECT DISTINCT
           tipo_vehiculo || '|' || armadora || '|' || carroceria || '|' || modelo,
           armadora_nombre,
           carroceria_nombre,
           CAST(modelo AS TEXT),
           NULL
      FROM gnp.cat_vehiculos
     WHERE true
        ON CONFLICT (id_gnp) DO UPDATE SET
           marca_gnp  = excluded.marca_gnp,
           tipo_gnp   = excluded.tipo_gnp,
           modelo_gnp = excluded.modelo_gnp
");

$pdo->exec('DETACH DATABASE gnp');

$despues = (int) $pdo->query('SELECT COUNT(*) FROM cat_gnp')->fetchColumn();

echo '   ' . pad('Renglones antes', 26) . number_format($antes) . "\n";
echo '   ' . pad('Insertados/actualizados', 26) . number_format((int) $n) . "\n";
echo '   ' . pad('Renglones ahora', 26) . number_format($despues) . "\n";
echo str_repeat('═', 66), "\n";
echo "cat_gnp listo en {$rutaComercial}\n";
