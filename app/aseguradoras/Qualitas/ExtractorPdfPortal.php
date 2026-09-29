<?php
declare(strict_types=1);

/**
 * ExtractorPdfPortal — lee las cotizaciones en PDF del portal de Qualitas
 * ("COTIZACIÓN DE AUTOMÓVILES") y llena una fila de plantilla_captura_portal.csv
 * por PDF, para que Albert la revise ANTES de importarla (ImportadorPortal).
 *
 * Sólo lectura: no modifica los PDF ni llama a ningún servicio. Usa
 * `pdftotext -table` (xpdf, viene con Git para Windows), que alinea cada prima
 * con su cobertura; el modo normal las desacomoda. Comprobado con los 3 PDF de
 * "Ejemplos Qualitas": las primas por cobertura coinciden con las que devolvió
 * el servicio (sys_llamadas 127, 128 y 129).
 *
 * Lo que el PDF NO trae y queda para revisión (en `notas`):
 * - paquete: se infiere de las coberturas (DM y RT → Amplia; RT sin DM → Limitada);
 * - estado: el PDF sólo trae el CP;
 * - tipo de vehículo;
 * - descuento: se toma de "CONDUCTO" (55, 55 y 20 en los ejemplos, igual que el
 *   PorcentajeDescuento de sus XML) `[PENDIENTE: confirmar con Qualitas]`;
 * - marca, línea y versión: el PDF da una sola línea ("CT CHEVROLET CAPTIVA
 *   PREMIER B"); se separan por posición y se quita el código de 2 letras del
 *   inicio (CT, MO…).
 */
final class ExtractorPdfPortal
{
    public function __construct(private readonly string $pdftotext = 'pdftotext')
    {
    }

    /** Texto del PDF en modo tabla, UTF-8. */
    public function texto(string $pdf): string
    {
        if (!is_file($pdf)) {
            throw new RuntimeException("No existe el PDF: {$pdf}");
        }
        $cmd = escapeshellarg($this->pdftotext) . ' -table -enc UTF-8 ' . escapeshellarg($pdf) . ' -';
        $salida = [];
        exec($cmd . ' 2>&1', $salida, $codigo);
        if ($codigo !== 0) {
            throw new RuntimeException("pdftotext falló ({$codigo}) con {$pdf}: " . implode(' ', $salida));
        }
        return implode("\n", $salida);
    }

    /** Una fila del CSV (todas las columnas de ImportadorPortal::columnas()) a partir del texto. */
    public static function extraer(string $texto, string $archivo): array
    {
        $f = array_fill_keys(ImportadorPortal::columnas(), '');
        $f['archivo_origen'] = $archivo;
        $notas = [];
        // `pdftotext -table` intercala líneas en blanco: se quitan antes de leer.
        $lineas = array_values(array_filter(
            array_map(static fn ($l) => rtrim($l), explode("\n", str_replace(["\r", "\f"], '', $texto))),
            static fn ($l) => trim($l) !== ''
        ));
        $valor = static function (string $patron) use ($texto): string {
            return preg_match($patron, $texto, $m) ? trim($m[1]) : '';
        };

        // ── Encabezado ────────────────────────────────────────────────
        $f['cp']                       = $valor('/C\.P\.:\s*(\d{5})/u');
        $f['anio']                     = $valor('/MODELO\s*:\s*(\d{4})/u');
        $f['amis']                     = $valor('/CLAVE TARIFA\s*:\s*(\d{1,6})/u');
        $f['uso']                      = $valor('/USO\s*:\s*([A-ZÁÉÍÓÚÑ]+)/u');
        $f['servicio']                 = $valor('/SERVICIO\s*:\s*([A-ZÁÉÍÓÚÑ]+)/u');
        $f['pronto_pago_dias']         = $valor('/PLAZO DE PAGO:\s*(\d+)\s*D[ÍI]AS/u');
        $f['tarifa_aplicada']          = $valor('/TARIFA APLICADA:\s*(\d+)/u');
        $f['fecha_cotizacion']         = self::fecha($valor('/FECHA DE COTIZACI[ÓO]N:\s*(\d{2}\/\d{2}\/\d{4})/u'));
        $f['numero_cotizacion_portal'] = $valor('/N[ÚU]MERO\s*\n\s*.*?(\d{5,})\s*$/mu');
        $conducto                      = $valor('/CONDUCTO\s+(\d{1,3})\b/u');
        $tipoCarga                     = $valor('/TIPO DE CARGA\s*:\s*([ABC])\b/u');
        $toneladas                     = $valor('/TONELADAS\s*:\s*(\d+)/u');

        if ($conducto !== '') {
            $f['descuento_pct'] = $conducto;
            $notas[] = 'descuento tomado de CONDUCTO (confirmar)';
        }
        if ($tipoCarga !== '' || $toneladas !== '') {
            $notas[] = "carga: tipo {$tipoCarga}" . ($toneladas !== '' ? ", {$toneladas} toneladas" : '');
        }

        // Descripción del vehículo: la línea después de "DATOS DEL VEHÍCULO".
        foreach ($lineas as $i => $l) {
            if (str_contains($l, 'DATOS DEL VEH')) {
                $f['descripcion_pdf'] = trim(preg_replace('/\s+/', ' ', $lineas[$i + 1] ?? ''));
                break;
            }
        }
        $partes = $f['descripcion_pdf'] !== '' ? explode(' ', $f['descripcion_pdf']) : [];
        if (count($partes) >= 3 && preg_match('/^[A-Z]{2}$/', $partes[0])) {
            $notas[] = "código \"{$partes[0]}\" al inicio de la descripción, quitado";
            array_shift($partes);
        }
        if (count($partes) >= 3) {
            $f['marca'] = $partes[0];
            $f['linea'] = $partes[1];
            $f['version_exacta'] = implode(' ', array_slice($partes, 2));
            $notas[] = 'marca/línea/versión separadas automáticamente (revisar)';
        }

        // ── Coberturas: de "RIESGOS" a la primera línea sin suma asegurada ──
        $nombres = [];
        foreach (ImportadorPortal::COBERTURAS as $k => [, $nombre]) {
            $nombres[$k] = self::llave($nombre);
        }
        $otras = [];
        $corte = null;   // columna (en caracteres) que separa el deducible de la prima
        foreach ($lineas as $l) {
            if (preg_match('/^\s*RIESGOS\s+SUMA ASEGURADA/u', $l)) {
                // Deducible y prima se distinguen por su posición bajo los títulos
                // "% DEDUCIBLE" y "PRIMAS $", no por la forma del número: una cobertura
                // sin deducible deja la prima sola, y 464.00 parece un deducible.
                $finDed = mb_strpos($l, 'DEDUCIBLE') + mb_strlen('DEDUCIBLE');
                $iniPri = mb_strpos($l, 'PRIMAS');
                $corte = ($finDed + $iniPri) / 2;
                continue;
            }
            if ($corte === null) {
                continue;
            }
            // Fila: nombre · suma ($ … o AMPARADO) · [deducible] · [prima]
            if (!preg_match('/^(\S.*?)\s{2,}(\$\s*[\d,]+(?:\s+POR EVENTO)?|AMPARAD[OA])/iu', $l, $m, PREG_OFFSET_CAPTURE)) {
                break;
            }
            $nombre = trim(preg_replace('/\s+/', ' ', $m[1][0]));
            $suma   = preg_match('/^AMPARAD/i', $m[2][0]) ? 'AMPARADO' : preg_replace('/[^\d]/', '', $m[2][0]);
            $ded    = '';
            $prima  = '';
            preg_match_all('/(-?[\d,]+(?:\.\d+)?)(\s*uma)?/iu', $l, $num, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $m[2][1] + strlen($m[2][0]));
            foreach ($num as $t) {
                $finCar = mb_strlen(substr($l, 0, $t[0][1] + strlen($t[0][0])));   // posición en caracteres (Ñ ocupa 2 bytes)
                if ($finCar > $corte) {
                    $prima = str_replace(',', '', $t[1][0]);
                } else {
                    $ded = self::deducible(trim($t[0][0]));
                }
            }
            $clave  = array_search(self::llave($nombre), $nombres, true);
            if ($clave === false) {
                $otras[] = "{$nombre} | {$suma} | {$ded} | {$prima}";
                continue;
            }
            [$f["{$clave}_suma"], $f["{$clave}_deducible"], $f["{$clave}_prima"]] = [$suma, $ded, $prima];
        }
        $f['otras_coberturas'] = implode('; ', $otras);
        if ($otras !== []) {
            $notas[] = count($otras) . ' cobertura(s) no reconocida(s) en otras_coberturas';
        }

        // Paquete, inferido: el PDF no lo dice.
        if ($f['dm_suma'] !== '' && $f['rt_suma'] !== '') {
            $f['paquete'] = 'AMPLIA';
        } elseif ($f['rt_suma'] !== '') {
            $f['paquete'] = 'LIMITADA';
        }
        if ($f['paquete'] !== '') {
            $notas[] = 'paquete inferido por las coberturas';
        }

        // ── Importes ─────────────────────────────────────────────────
        foreach ([
            'prima_neta' => 'PRIMA NETA', 'tasa_fin_pf' => 'TASA FIN\. P\.F\.', 'gtos_exped_pol' => 'GTOS\.EXPED\.POL\.',
            'subtotal' => 'SUBTOTAL', 'iva' => 'I\.V\.A\.', 'importe_total' => 'IMPORTE TOTAL',
        ] as $col => $etq) {
            $f[$col] = str_replace(',', '', $valor('/' . $etq . '\s+(-?[\d,]+\.\d{2})/u'));
        }

        // ── Formas de pago ───────────────────────────────────────────
        $f['forma_pago'] = 'CONTADO';
        foreach (ImportadorPortal::FORMAS as $fp) {
            if (preg_match('/^\s*' . strtoupper($fp) . '\s+([\d,]+\.\d{2})(?:\s+([\d,]+\.\d{2}))?/mu', $texto, $m)) {
                $f["{$fp}_primer"] = str_replace(',', '', $m[1]);
                $f["{$fp}_siguientes"] = str_replace(',', '', $m[2] ?? '');
            }
        }

        $notas[] = 'estado y tipo de vehículo: el PDF no los trae';
        $f['notas'] = implode('; ', $notas);
        return $f;
    }

    /** Escribe las filas en un CSV con BOM (Excel respeta los acentos). */
    public static function escribirCsv(array $filas, string $salida): void
    {
        $fh = fopen($salida, 'wb');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ImportadorPortal::columnas(), ',', '"', '');
        foreach ($filas as $i => $f) {
            $f['num'] = $f['num'] !== '' ? $f['num'] : (string) ($i + 1);
            fputcsv($fh, array_map(static fn (string $c) => (string) ($f[$c] ?? ''), ImportadorPortal::columnas()), ',', '"', '');
        }
        fclose($fh);
    }

    /** "23/09/2026" → "2026-09-23". */
    private static function fecha(string $dmy): string
    {
        return preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dmy, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : '';
    }

    /** "5.00" → "5" · "0 uma" → "0 UMA". */
    private static function deducible(string $v): string
    {
        $corto = static function (string $n): string {
            $f = (float) $n;
            return $f == floor($f) ? (string) (int) $f : rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
        };
        $v = trim($v);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*uma$/i', $v, $m)) {
            return $corto($m[1]) . ' UMA';
        }
        return is_numeric($v) ? $corto($v) : $v;
    }

    /** Nombre de cobertura comparable: sin acentos, espacios ni mayúsculas. */
    private static function llave(string $nombre): string
    {
        $n = strtr(mb_strtoupper($nombre), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
        return preg_replace('/[^A-Z0-9]/', '', $n);
    }
}
