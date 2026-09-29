#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prueba_extractor_pdf_sin_red.php — ExtractorPdfPortal contra los 3 PDF de
 * "Ejemplos Qualitas" (Captiva, NP300 y Vento). SIN RED y sin los PDF: usa su
 * texto ya convertido con `pdftotext -table -enc UTF-8`
 * (pruebas/ejemplos/portal_pdf_*.txt).
 *
 * Lo que se compara:
 * - primas por cobertura, prima neta, recargo, derecho, IVA y total contra lo
 *   que devolvió el servicio de Qualitas para el mismo vehículo
 *   (evidencia de sys_llamadas 127, 128 y 129);
 * - descuento contra el PorcentajeDescuento de esas mismas peticiones;
 * - que las filas son importables y son las mismas de portal_ejemplo.csv.
 *
 * Uso:
 *   php app/aseguradoras/Qualitas/pruebas/prueba_extractor_pdf_sin_red.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo desde la línea de comandos.');
}

define('RUTA_BASE', dirname(__DIR__, 4));
define('RUTA_APP', RUTA_BASE . '/app');

foreach (['aseguradoras/Qualitas/QualitasXml', 'aseguradoras/Qualitas/ImportadorPortal', 'aseguradoras/Qualitas/ExtractorPdfPortal'] as $c) {
    require RUTA_APP . '/' . $c . '.php';
}

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

$ejemplos  = __DIR__ . '/ejemplos';
$evidencia = RUTA_BASE . '/docs/aseguradoras/qualitas/evidencia';

/** Lo que devolvió el servicio para ese vehículo: primas por NoCobertura, importes y descuento pedido. */
function servicio(string $evidencia, int $llamada): array
{
    $resp = html_entity_decode((string) file_get_contents(glob("{$evidencia}/*_llamada-{$llamada}_*_respuesta.xml")[0]));
    $pet  = html_entity_decode((string) file_get_contents(glob("{$evidencia}/*_llamada-{$llamada}_*_peticion.xml")[0]));
    preg_match_all('/<Coberturas NoCobertura="(\d+)">.*?<Prima>([\d.]+)<\/Prima>/s', $resp, $m, PREG_SET_ORDER);
    $primas = [];
    foreach ($m as $c) {
        $primas[(int) $c[1]] = (float) $c[2];
    }
    $importe = static fn (string $e) => preg_match("/<{$e}>(-?[\\d.]+)<\\/{$e}>/", $resp, $x) ? (float) $x[1] : null;
    return [
        'primas'    => $primas,
        'neta'      => $importe('PrimaNeta'),
        'derecho'   => $importe('Derecho'),
        'recargo'   => $importe('Recargo'),
        'iva'       => $importe('Impuesto'),
        'total'     => $importe('PrimaTotal'),
        'descuento' => preg_match('/<PorcentajeDescuento>(\d+)<\/PorcentajeDescuento>/', $pet, $d) ? $d[1] : null,
    ];
}

$casos = [
    'captiva' => ['txt' => 'portal_pdf_captiva_uv5931410001849963aa.txt', 'llamada' => 127, 'amis' => '21191', 'uso' => 'NORMAL',
                  'marca' => 'CHEVROLET', 'linea' => 'CAPTIVA', 'version' => 'PREMIER B', 'numero' => '673106', 'formas' => ['semestral', 'trimestral']],
    'vento'   => ['txt' => 'portal_pdf_vento_uv5935890001849963aa.txt', 'llamada' => 129, 'amis' => '68133', 'uso' => 'NORMAL',
                  'marca' => 'VENTO', 'linea' => 'TORNADO', 'version' => '300 300CC', 'numero' => '674144', 'formas' => []],   // moto: el PDF sólo trae contado
    'np300'   => ['txt' => 'portal_pdf_np300_uv5941430001849963aa.txt', 'llamada' => 128, 'amis' => '11333', 'uso' => 'CARGA',
                  'marca' => 'NISSAN', 'linea' => 'NP300', 'version' => 'DOBLE CAB S 3P L4', 'numero' => '676074', 'formas' => ['semestral', 'trimestral']],
];

$filas = [];
foreach ($casos as $nombre => $c) {
    echo "\n{$nombre} (contra sys_llamadas {$c['llamada']})\n";
    $archivo = str_replace('.txt', '.pdf', substr($c['txt'], strrpos($c['txt'], '_') + 1));
    $f = ExtractorPdfPortal::extraer((string) file_get_contents("{$ejemplos}/{$c['txt']}"), $archivo);
    $ws = servicio($evidencia, $c['llamada']);
    $filas[] = $f;

    ok($f['amis'] === $c['amis'] && $f['anio'] === '2026' && $f['uso'] === $c['uso'] && $f['cp'] === '11590',
        "AMIS {$c['amis']} (de \"CLAVE TARIFA\"), modelo 2026, uso {$c['uso']}, CP 11590",
        "{$f['amis']} {$f['anio']} {$f['uso']} {$f['cp']}");
    ok($f['marca'] === $c['marca'] && $f['linea'] === $c['linea'] && $f['version_exacta'] === $c['version'],
        "{$c['marca']} / {$c['linea']} / {$c['version']}", "{$f['marca']} / {$f['linea']} / {$f['version_exacta']}");
    ok($f['descuento_pct'] === $ws['descuento'], "Descuento {$ws['descuento']}, igual al PorcentajeDescuento de la petición", $f['descuento_pct']);
    ok($f['numero_cotizacion_portal'] === $c['numero'] && $f['fecha_cotizacion'] === '2026-09-23' && $f['archivo_origen'] === $archivo,
        "Número {$c['numero']}, fecha 2026-09-23, archivo de origen",
        "{$f['numero_cotizacion_portal']} {$f['fecha_cotizacion']} {$f['archivo_origen']}");

    // Primas por cobertura: todas las del servicio, con el mismo importe.
    $difs = [];
    foreach (ImportadorPortal::COBERTURAS as $k => [$no]) {
        $pdf = $f["{$k}_prima"];
        $esp = $ws['primas'][$no] ?? null;
        if ($esp === null && $f["{$k}_suma"] === '') {
            continue;                                   // ni el servicio ni el PDF la traen
        }
        if ($no === 31 && $pdf === '' && $esp !== null && $esp < 0.02) {
            continue;                                   // RC por la carga: el servicio da 0.01 y el PDF la deja en blanco
        }
        if ($esp === null || $pdf === '' || abs((float) $pdf - $esp) > 0.005) {
            $difs[] = "{$k} ({$no}): PDF " . ($pdf === '' ? '—' : $pdf) . ' / servicio ' . ($esp ?? '—');
        }
    }
    ok($difs === [], 'Primas por cobertura iguales a las del servicio (' . count($ws['primas']) . ' coberturas)', implode('; ', $difs));
    ok($f['otras_coberturas'] === '', 'Ninguna cobertura quedó sin reconocer', $f['otras_coberturas']);

    $iguales = abs((float) $f['prima_neta'] - $ws['neta']) < 0.005 && abs((float) $f['tasa_fin_pf'] - $ws['recargo']) < 0.005
        && abs((float) $f['gtos_exped_pol'] - $ws['derecho']) < 0.005 && abs((float) $f['iva'] - $ws['iva']) < 0.005
        && abs((float) $f['importe_total'] - $ws['total']) < 0.005;
    ok($iguales, "Prima neta, recargo, derecho, IVA y total iguales a los del servicio (total {$f['importe_total']})",
        json_encode([$f['prima_neta'], $f['tasa_fin_pf'], $f['gtos_exped_pol'], $f['iva'], $f['importe_total']]) . ' vs ' . json_encode($ws));
    ok(abs((float) $f['subtotal'] - ((float) $f['prima_neta'] + (float) $f['tasa_fin_pf'] + (float) $f['gtos_exped_pol'])) < 0.005,
        'Subtotal = prima neta + recargo + derecho');
    $conPagos = array_values(array_filter(ImportadorPortal::FORMAS, static fn ($fp) => $f["{$fp}_primer"] !== '' && $f["{$fp}_siguientes"] !== ''));
    ok($f['forma_pago'] === 'CONTADO' && $conPagos === $c['formas'],
        'Contado' . ($c['formas'] !== [] ? ' y ' . implode(', ', $c['formas']) . ' (primer pago y siguientes)' : ' solamente, como el PDF'), implode(', ', $conPagos));
    ok($f['paquete'] === 'AMPLIA' && str_contains($f['notas'], 'paquete inferido') && str_contains($f['notas'], 'estado y tipo de vehículo'),
        'Paquete AMPLIA inferido, y la nota dice qué revisar', $f['notas']);
    ok(ImportadorPortal::validar($f) === [], 'La fila es importable', implode('; ', ImportadorPortal::validar($f)));
}

echo "\nDeducibles y sumas\n";
[$cap, $ven, $np] = $filas;   // en el orden de los archivos, como los lee el script
ok($cap['dm_deducible'] === '5' && $cap['rt_deducible'] === '10' && $cap['rc_deducible'] === '0 UMA', 'Captiva: DM 5, RT 10, RC "0 UMA"',
    "{$cap['dm_deducible']} {$cap['rt_deducible']} {$cap['rc_deducible']}");
ok($cap['gl_suma'] === 'AMPARADO' && $cap['gl_deducible'] === '' && $cap['gl_prima'] === '464.00', 'Gastos legales: AMPARADO, sin deducible, prima 464.00 (no se confunde con deducible)');
ok($np['rc_carga_suma'] === 'AMPARADO' && $np['rc_carga_deducible'] === '0 UMA', 'NP300: RC por la carga AMPARADO, deducible 0 UMA');
ok($ven['dm_suma'] === '35200' && $ven['dm_deducible'] === '10' && $ven['rt_deducible'] === '20', 'Vento: DM 35,200 · 10%, RT 20%');
ok(str_contains($cap['notas'], 'código "CT"') && str_contains($ven['notas'], 'código "MO"') && !str_contains($np['notas'], 'código'),
    'Quita el código de 2 letras (CT, MO) y lo anota; NISSAN no lo trae');

echo "\nCSV\n";
$tmp = tempnam(sys_get_temp_dir(), 'qua_pdf_') . '.csv';
ExtractorPdfPortal::escribirCsv($filas, $tmp);
$crudo = (string) file_get_contents($tmp);
$leidas = array_values(ImportadorPortal::leer($tmp));
@unlink($tmp);
ok(str_starts_with($crudo, "\xEF\xBB\xBF"), 'Con BOM (Excel respeta los acentos)');
ok(count($leidas) === 3 && $leidas[0]['amis'] === '21191' && $leidas[1]['version_exacta'] === '300 300CC', 'Lo lee el importador tal cual');
$ejemplo = array_values(ImportadorPortal::leer("{$ejemplos}/portal_ejemplo.csv"));
$mismo = true;
foreach ([0, 1, 2] as $i) {
    $mismo = $mismo && array_diff_key($ejemplo[$i], ['num' => 1]) == array_diff_key($filas[$i], ['num' => 1]);
}
ok($mismo, 'Las 3 filas de portal_ejemplo.csv son exactamente lo que extrae de los PDF');

echo "\n───────────────────────────────────────────────────────────────────\n";
echo $fallas === 0 ? " {$total} pruebas, todas bien.\n" : " {$fallas} de {$total} pruebas FALLARON.\n";
exit($fallas === 0 ? 0 : 1);
