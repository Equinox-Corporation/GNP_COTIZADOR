#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * exportar_comercial_sin_gnp_excel.php — Convierte datos/comercial_sin_gnp.csv
 * (Cambio 5+L de homologar_marcas_submarcas.php) a datos/comercial_sin_gnp.xlsx,
 * una hoja por motivo_sin_gnp:
 *
 *   ANIO_NO_EN_GNP · ESCRITURA_DISTINTA · CANDIDATO_DUDOSO · SIN_EQUIVALENTE
 *   MARCA_NO_EN_GNP · AMBIGUO · FUERA_DE_ALCANCE
 *   Resumen — conteo por marca y por motivo, total arriba
 *
 * Encabezado congelado, con filtros, ordenado por marca y año descendente.
 * Nada de librerías externas: el .xlsx se arma a mano (XML + ZipMinimo.php,
 * ver ese archivo para por qué no se usa ext-zip).
 *
 * Uso:
 *   php app/scripts/exportar_comercial_sin_gnp_excel.php
 *   php app/scripts/exportar_comercial_sin_gnp_excel.php "ruta\comercial_sin_gnp.csv" "ruta\salida.xlsx"
 */

require __DIR__ . '/_arranque.php';
require RUTA_APP . '/core/ZipMinimo.php';

$args = argumentos($argv);
$posicionales = array_values(array_filter($args, static fn($k) => is_int($k), ARRAY_FILTER_USE_KEY));
$rutaCsv   = $posicionales[0] ?? RUTA_BASE . '/datos/comercial_sin_gnp.csv';
$rutaXlsx  = $posicionales[1] ?? RUTA_BASE . '/datos/comercial_sin_gnp.xlsx';

if (!is_file($rutaCsv)) {
    fwrite(STDERR, "No encuentro el CSV en:\n   {$rutaCsv}\n");
    fwrite(STDERR, "Corre primero homologar_marcas_submarcas.php.\n");
    exit(1);
}

/** Orden fijo de los motivos, del más accionable al más abierto. */
$motivos = [
    'ANIO_NO_EN_GNP', 'ESCRITURA_DISTINTA', 'CANDIDATO_DUDOSO',
    'SIN_EQUIVALENTE', 'MARCA_NO_EN_GNP', 'AMBIGUO', 'FUERA_DE_ALCANCE',
];

// ── Leer el CSV ───────────────────────────────────────────────────────────

$fh = fopen($rutaCsv, 'r');
$encabezados = fgetcsv($fh);
$filas = [];
while (($f = fgetcsv($fh)) !== false) {
    $filas[] = array_combine($encabezados, $f);
}
fclose($fh);

echo 'Renglones leídos de ' . basename($rutaCsv) . ': ' . number_format(count($filas)) . "\n";

// ── Repartir en hojas, una por motivo ────────────────────────────────────

$porMotivo = array_fill_keys($motivos, []);
foreach ($filas as $f) {
    $m = $f['motivo_sin_gnp'] ?? '';
    if (!isset($porMotivo[$m])) {
        $porMotivo[$m] = []; // motivo inesperado: no se pierde, se le hace hoja propia
    }
    $porMotivo[$m][] = $f;
}

$ordenar = static function (array &$filas): void {
    usort($filas, static fn($a, $b) => $a['marca'] <=> $b['marca'] ?: (int) $b['anio'] <=> (int) $a['anio']);
};
foreach ($porMotivo as $m => &$grupo) {
    $ordenar($grupo);
    echo '   ' . str_pad($m, 20) . ': ' . number_format(count($grupo)) . "\n";
}
unset($grupo);

// ── Resumen: conteo por marca y por motivo, total arriba ────────────────

$porMarca = []; // marca => [motivo => n, ..., 'total' => n]
foreach ($filas as $f) {
    $porMarca[$f['marca']][$f['motivo_sin_gnp']] = ($porMarca[$f['marca']][$f['motivo_sin_gnp']] ?? 0) + 1;
    $porMarca[$f['marca']]['total'] = ($porMarca[$f['marca']]['total'] ?? 0) + 1;
}
uasort($porMarca, static fn($a, $b) => $b['total'] <=> $a['total']);

$resumenFilas = [];
foreach ($porMarca as $marca => $c) {
    $fila = ['marca' => $marca];
    foreach ($motivos as $m) {
        $fila[$m] = $c[$m] ?? 0;
    }
    $fila['total'] = $c['total'] ?? 0;
    $resumenFilas[] = $fila;
}

echo '   Marcas en Resumen: ' . number_format(count($resumenFilas)) . "\n";

// ── Construcción del .xlsx (XML a mano, sin librerías) ──────────────────

function xmlEsc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function colLetra(int $n): string
{
    $s = '';
    while ($n > 0) {
        $r = ($n - 1) % 26;
        $s = chr(65 + $r) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

/**
 * Arma el XML de una hoja. $columnas = [ [clave, encabezado, ancho, tipo], ... ]
 * tipo: 'texto' | 'numero'. $filas = list<array<clave,valor>>.
 */
function hojaXml(array $columnas, array $filas): string
{
    $numCols = count($columnas);
    $numFilasTotal = count($filas) + 1;

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

    $xml .= '<cols>';
    foreach ($columnas as $i => $col) {
        $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $col[2] . '" customWidth="1"/>';
    }
    $xml .= '</cols>';

    $xml .= '<sheetData>';

    // Encabezado (negrita: s="1")
    $xml .= '<row r="1">';
    foreach ($columnas as $i => $col) {
        $ref = colLetra($i + 1) . '1';
        $xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . xmlEsc($col[1]) . '</t></is></c>';
    }
    $xml .= '</row>';

    // Datos
    foreach ($filas as $fi => $fila) {
        $r = $fi + 2;
        $xml .= '<row r="' . $r . '">';
        foreach ($columnas as $i => $col) {
            [$clave, , , $tipo] = $col;
            $ref = colLetra($i + 1) . $r;
            $valor = $fila[$clave] ?? '';
            if ($tipo === 'numero') {
                $num = is_numeric($valor) ? $valor : 0;
                $xml .= '<c r="' . $ref . '"><v>' . $num . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . xmlEsc((string) $valor) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';

    $rango = 'A1:' . colLetra($numCols) . $numFilasTotal;
    $xml .= '<autoFilter ref="' . $rango . '"/>';
    $xml .= '</worksheet>';

    return $xml;
}

$columnasDatos = [
    ['marca', 'Marca', 22, 'texto'],
    ['submarca', 'Submarca', 20, 'texto'],
    ['anio', 'Año', 8, 'numero'],
    ['total_submarcas_de_esa_marca', 'Total submarcas de la marca', 12, 'numero'],
    ['mejor_candidato_gnp', 'Mejor candidato en GNP', 46, 'texto'],
    ['parecido', 'Parecido (%)', 12, 'numero'],
    ['nota_sin_gnp', 'Detalle (autosuficiente)', 60, 'texto'],
    ['IDsubmarca', 'ID submarca', 12, 'texto'],
];

$columnasResumen = [['marca', 'Marca', 24, 'texto']];
foreach ($motivos as $m) {
    $columnasResumen[] = [$m, $m, 14, 'numero'];
}
$columnasResumen[] = ['total', 'Total', 10, 'numero'];

$hojas = [];
foreach ($motivos as $m) {
    // El nombre de hoja en Excel no admite más de 31 caracteres; los 7 motivos ya caben.
    $hojas[$m] = hojaXml($columnasDatos, $porMotivo[$m]);
}
$hojas['Resumen'] = hojaXml($columnasResumen, $resumenFilas);

// ── Paquete .xlsx ─────────────────────────────────────────────────────────

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
$i = 1;
foreach ($hojas as $nombre => $xmlHoja) {
    $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $i++;
}
$contentTypes .= '</Types>';

$relsRaiz = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$workbookSheets = '';
$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
$i = 1;
foreach ($hojas as $nombre => $xmlHoja) {
    $workbookSheets .= '<sheet name="' . xmlEsc($nombre) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
    $workbookRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    $i++;
}
$workbookRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
$workbookRels .= '</Relationships>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets>' . $workbookSheets . '</sheets>'
    . '</workbook>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
    . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="2">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '</cellXfs>'
    . '</styleSheet>';

$zip = new ZipMinimo();
$zip->agregar('[Content_Types].xml', $contentTypes);
$zip->agregar('_rels/.rels', $relsRaiz);
$zip->agregar('xl/workbook.xml', $workbook);
$zip->agregar('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->agregar('xl/styles.xml', $styles);
$i = 1;
foreach ($hojas as $nombre => $xmlHoja) {
    $zip->agregar('xl/worksheets/sheet' . $i . '.xml', $xmlHoja);
    $i++;
}
$zip->guardar($rutaXlsx);

echo "\nArchivo generado: {$rutaXlsx}\n";
echo '   Tamaño: ' . number_format((int) filesize($rutaXlsx) / 1024, 1) . " KB\n";
