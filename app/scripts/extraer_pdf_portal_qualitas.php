#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * extraer_pdf_portal_qualitas.php — lee los PDF de cotización del portal de
 * Qualitas de una carpeta (sólo lectura) y escribe un CSV con una fila por PDF,
 * con las columnas de plantilla_captura_portal.csv, para REVISARLO antes de
 * importarlo con importar_portal_qualitas.php. No llama a ningún servicio.
 *
 * Uso:
 *   php app/scripts/extraer_pdf_portal_qualitas.php --carpeta="C:\...\Qualitas_Cotizador\Portal" --salida=portal_extraido.csv
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 2));
define('RUTA_APP', RUTA_BASE . '/app');
foreach (['core/Env', 'aseguradoras/Qualitas/QualitasXml', 'aseguradoras/Qualitas/ImportadorPortal', 'aseguradoras/Qualitas/ExtractorPdfPortal'] as $c) {
    require RUTA_APP . '/' . $c . '.php';
}
Env::cargar(RUTA_BASE . '/config/.env.local');

$op = getopt('', ['carpeta:', 'salida:']);
$carpeta = rtrim((string) ($op['carpeta'] ?? ''), '/\\');
$salida  = (string) ($op['salida'] ?? '');
if ($carpeta === '' || !is_dir($carpeta) || $salida === '') {
    fwrite(STDERR, "Uso: extraer_pdf_portal_qualitas.php --carpeta=CARPETA_CON_PDF --salida=archivo.csv\n");
    exit(1);
}

$pdfs = glob($carpeta . '/*.[pP][dD][fF]') ?: [];
sort($pdfs);
if ($pdfs === []) {
    fwrite(STDERR, "No hay PDF en {$carpeta}\n");
    exit(1);
}

$extractor = new ExtractorPdfPortal(Env::get('PDFTOTEXT', 'pdftotext'));
$filas = [];
foreach ($pdfs as $pdf) {
    $f = ExtractorPdfPortal::extraer($extractor->texto($pdf), basename($pdf));
    $errores = ImportadorPortal::validar($f);
    printf("%s · AMIS %s · %s %s %s · %s · total %s%s\n",
        basename($pdf), $f['amis'] ?: '?', $f['marca'], $f['linea'], $f['anio'], $f['uso'], $f['importe_total'] ?: '?',
        $errores !== [] ? ' · ✗ ' . implode('; ', $errores) : ' · ✓ importable');
    $filas[] = $f;
}
ExtractorPdfPortal::escribirCsv($filas, $salida);
echo "\nCSV para revisar: {$salida} (" . count($filas) . " filas). Revisa las notas de cada fila antes de importar.\n";
