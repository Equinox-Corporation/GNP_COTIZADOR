<?php
declare(strict_types=1);

/**
 * ComparativoServicio — arma el "Comparativo Multi-Plan" descargable.
 *
 * GNP no genera esto: es una vista propia, pensada para presentarle al
 * cliente los paquetes cotizados lado a lado (primas y coberturas), en un
 * formato ejecutivo. Dos salidas del mismo dato:
 *
 *   pdf()  — reporte para presentar/imprimir, armado con PdfBasico (sin
 *            ninguna librería externa).
 *   csv()  — la misma tabla en un archivo que Excel abre directo, para quien
 *            prefiera revisar o manipular los números ahí.
 *
 * Las dos formas leen la MISMA extracción de datos (filasPrimas /
 * filasCoberturas) para que nunca se puedan desincronizar entre sí.
 */
final class ComparativoServicio
{
    private const VERDE  = [27, 77, 62];
    private const GRIS   = [90, 107, 128];
    private const GRIS_CLARO = [223, 229, 236];
    private const FONDO_ALT  = [244, 246, 249];
    private const FONDO_DESTACADO = [232, 242, 238];

    /** @return array{cot:array,resultados:list<array>}|null null si no hay nada que exportar */
    private static function datos(int $cotId): ?array
    {
        $cot = CotizacionServicio::obtener($cotId);
        if ($cot === null) {
            return null;
        }
        $resultados = CotizacionServicio::resultados($cotId);
        if ($resultados === []) {
            return null;
        }
        return ['cot' => $cot, 'resultados' => $resultados];
    }

    /**
     * Filas de la tabla de primas. Cada fila: [etiqueta, list<string> valores por paquete].
     * El descuento sólo aparece si algún paquete trae uno distinto de cero — igual que en pantalla.
     *
     * @param list<array> $resultados
     * @return list<array{0:string,1:list<string>}>
     */
    private static function filasPrimas(array $resultados): array
    {
        $dinero = static fn (mixed $n): string => $n === null ? '—' : '$' . number_format((float) $n, 2);
        $hayDescuento = false;
        foreach ($resultados as $r) {
            if ($r['descuento'] !== null && (float) $r['descuento'] != 0.0) {
                $hayDescuento = true;
            }
        }

        $filas = [];
        $filas[] = ['Prima neta', array_map(static fn ($r) => $dinero($r['prima_neta']), $resultados)];
        $filas[] = ['Derechos',   array_map(static fn ($r) => $dinero($r['derechos']), $resultados)];
        $filas[] = ['IVA',        array_map(static fn ($r) => $dinero($r['iva']), $resultados)];
        if ($hayDescuento) {
            $filas[] = ['Descuento', array_map(static fn ($r) => $dinero($r['descuento']), $resultados)];
        }
        $filas[] = ['TOTAL A PAGAR', array_map(static fn ($r) => $dinero($r['total_pagar']), $resultados)];
        $filas[] = ['Forma de pago', array_map(
            static fn ($r) => (int) $r['num_pagos'] === 1 ? 'Pago único anual' : ((int) $r['num_pagos'] . ' pagos'),
            $resultados
        )];
        return $filas;
    }

    /**
     * Filas de la tabla de coberturas: la unión de todas las que trae algún
     * paquete, en el orden en que GNP las devolvió. Igual que "Qué cubre cada
     * uno" en pantalla (resultado.php).
     *
     * @param list<array> $resultados
     * @return list<array{0:string,1:list<array{suma:string,ded:string}|null>}>
     */
    private static function filasCoberturas(array $resultados): array
    {
        $nombres = [];
        foreach ($resultados as $r) {
            foreach ($r['coberturas'] as $c) {
                $nombres[$c['cve_cobertura']] = $c['nombre'];
            }
        }

        $porPaquete = [];
        foreach ($resultados as $i => $r) {
            foreach ($r['coberturas'] as $c) {
                $porPaquete[$i][$c['cve_cobertura']] = $c;
            }
        }

        $filas = [];
        foreach ($nombres as $cve => $nombre) {
            $valores = [];
            foreach ($resultados as $i => $r) {
                $c = $porPaquete[$i][$cve] ?? null;
                $valores[] = $c === null ? null : ['suma' => (string) ($c['suma_asegurada'] ?: 'Amparada'), 'ded' => (string) $c['deducible']];
            }
            $filas[] = [$nombre, $valores];
        }
        return $filas;
    }

    // ─── PDF ─────────────────────────────────────────────────────────────────

    public static function pdf(int $cotId): ?string
    {
        $d = self::datos($cotId);
        if ($d === null) {
            return null;
        }
        ['cot' => $cot, 'resultados' => $resultados] = $d;

        $pdf = new PdfBasico(792, 612); // carta, horizontal
        $margenX = 40;
        $anchoUtil = $pdf->anchoUtil() - $margenX * 2;

        $pdf->agregarPagina();
        $y = self::encabezado($pdf, $cot, $margenX);
        $y = self::ficha($pdf, $cot, $margenX, $anchoUtil, $y + 14);

        $y += 22;
        $pdf->fuente(true, 12);
        $pdf->colorTexto(...self::VERDE);
        $pdf->texto($margenX, $y, 'Comparativo de primas');
        $y += 16;
        $y = self::dibujarTablaPrimas($pdf, $resultados, $margenX, $anchoUtil, $y);

        $y += 26;
        if ($y > 500) { // no vale la pena empezar la tabla de coberturas casi al fondo
            $pdf->agregarPagina();
            $y = 40;
        }
        $pdf->fuente(true, 12);
        $pdf->colorTexto(...self::VERDE);
        $pdf->texto($margenX, $y, 'Qué cubre cada paquete');
        $y += 16;
        self::dibujarTablaCoberturas($pdf, $resultados, $margenX, $anchoUtil, $y);

        $vigencia = $cot['vence_en'] ? ('vigente hasta ' . $cot['vence_en']) : '';
        $pdf->establecerPie('Cotizador GNP · Equinox', 'Cotizar no genera póliza' . ($vigencia !== '' ? ' · ' . $vigencia : ''));

        return $pdf->bytes();
    }

    private static function encabezado(PdfBasico $pdf, array $cot, float $margenX): float
    {
        $pdf->rectangulo(0, 0, $pdf->anchoUtil(), 55, self::VERDE);
        $pdf->fuente(true, 17);
        $pdf->colorTexto(255, 255, 255);
        $pdf->texto($margenX, 34, 'Comparativo Multi-Plan');
        $pdf->fuente(false, 10);
        $pdf->textoDerecha($pdf->anchoUtil() - $margenX, 22, 'Cotizador GNP · Equinox');
        $pdf->textoDerecha($pdf->anchoUtil() - $margenX, 38, 'Folio ' . ($cot['folio'] ?: '(sin folio)'));

        $pdf->fuente(false, 9);
        $pdf->colorTexto(...self::GRIS);
        $pdf->texto($margenX, 70, 'Generado el ' . date('d/m/Y H:i') . ' · esta comparación la arma el cotizador, no GNP.');

        return 70;
    }

    private static function ficha(PdfBasico $pdf, array $cot, float $margenX, float $anchoUtil, float $y): float
    {
        $sexo = $cot['conductor_sexo'] === 'F' ? 'Femenino' : 'Masculino';
        $mismaPersona = (int) $cot['contratante_edad'] === (int) $cot['conductor_edad']
                     && (string) $cot['contratante_cp'] === (string) $cot['conductor_cp'];

        $pares = [
            ['Vehículo', trim($cot['descripcion_veh'] . ' · ' . $cot['modelo'])],
            ['Procedencia', $cot['procedencia'] . ' (subramo ' . $cot['sub_ramo'] . ')'],
            $mismaPersona
                ? ['Solicitante', trim($cot['contratante'] ?: '—') . ' · ' . $cot['conductor_edad'] . ' años · CP ' . $cot['conductor_cp'] . ' · ' . $sexo]
                : ['Conductor (fija el precio)', $cot['conductor_edad'] . ' años · CP ' . $cot['conductor_cp'] . ' · ' . $sexo],
            ['Vigencia', $cot['vence_en'] ? ('hasta ' . $cot['vence_en']) : '—'],
        ];

        $pdf->rectangulo($margenX, $y, $anchoUtil, 46, self::FONDO_ALT, self::GRIS_CLARO);
        $colAncho = $anchoUtil / 2;
        foreach ($pares as $i => [$etq, $val]) {
            $cx = $margenX + 12 + ($i % 2) * $colAncho;
            $cy = $y + 15 + intdiv($i, 2) * 20;
            $pdf->fuente(false, 7.5);
            $pdf->colorTexto(...self::GRIS);
            $pdf->texto($cx, $cy, mb_strtoupper($etq, 'UTF-8'));
            $pdf->fuente(true, 9.5);
            $pdf->colorTexto(20, 30, 40);
            $pdf->texto($cx, $cy + 11, mb_strimwidth($val, 0, 90, '…'));
        }

        return $y + 46;
    }

    /** @param list<array> $resultados */
    private static function dibujarTablaPrimas(PdfBasico $pdf, array $resultados, float $margenX, float $anchoUtil, float $y): float
    {
        $etiquetaAncho = 150;
        $n = count($resultados);
        $colAncho = ($anchoUtil - $etiquetaAncho) / $n;

        // Encabezado: nombre y clave de cada paquete. El primero ya viene más barato (resultados() ordena así).
        $altoEnc = 30;
        $pdf->rectangulo($margenX, $y, $anchoUtil, $altoEnc, self::VERDE);
        $pdf->fuente(true, 9);
        $pdf->colorTexto(255, 255, 255);
        $pdf->texto($margenX + 8, $y + 19, 'Concepto');
        foreach ($resultados as $i => $r) {
            $cx = $margenX + $etiquetaAncho + $i * $colAncho + $colAncho / 2;
            $pdf->fuente(true, 9.5);
            $pdf->textoCentrado($cx, $y + 13, mb_strimwidth((string) $r['paquete'], 0, 22, '…'));
            $pdf->fuente(false, 7.5);
            $pdf->textoCentrado($cx, $y + 24, (string) $r['cve_paquete']);
        }
        $y += $altoEnc;
        $yFilasInicio = $y;

        $filas = self::filasPrimas($resultados);
        foreach ($filas as $idx => [$etq, $valores]) {
            $esTotal = $etq === 'TOTAL A PAGAR';
            $alto = $esTotal ? 22 : 18;
            $fondo = $esTotal ? self::FONDO_DESTACADO : ($idx % 2 === 1 ? self::FONDO_ALT : null);
            if ($fondo !== null) {
                $pdf->rectangulo($margenX, $y, $anchoUtil, $alto, $fondo);
            }
            $pdf->fuente($esTotal, $esTotal ? 10 : 8.5);
            $pdf->colorTexto(20, 30, 40);
            $pdf->texto($margenX + 8, $y + $alto - 6, $etq);
            foreach ($valores as $i => $val) {
                $cx = $margenX + $etiquetaAncho + $i * $colAncho + $colAncho / 2;
                $pdf->fuente($esTotal, $esTotal ? 10.5 : 8.5);
                $pdf->textoCentrado($cx, $y + $alto - 6, $val);
            }
            $y += $alto;
        }
        $pdf->rectangulo($margenX, $yFilasInicio, $anchoUtil, $y - $yFilasInicio, null, self::GRIS_CLARO);

        return $y;
    }

    /** @param list<array> $resultados */
    private static function dibujarTablaCoberturas(PdfBasico $pdf, array $resultados, float $margenX, float $anchoUtil, float $y): void
    {
        $etiquetaAncho = 210;
        $n = count($resultados);
        $colAncho = ($anchoUtil - $etiquetaAncho) / $n;
        $margenInferior = 46; // deja aire para el pie de página

        $dibujarEncabezado = function () use ($pdf, $margenX, $anchoUtil, $etiquetaAncho, $colAncho, $resultados, &$y): void {
            $pdf->rectangulo($margenX, $y, $anchoUtil, 22, self::VERDE);
            $pdf->fuente(true, 9);
            $pdf->colorTexto(255, 255, 255);
            $pdf->texto($margenX + 8, $y + 15, 'Cobertura');
            foreach ($resultados as $i => $r) {
                $cx = $margenX + $etiquetaAncho + $i * $colAncho + $colAncho / 2;
                $pdf->textoCentrado($cx, $y + 15, mb_strimwidth((string) $r['paquete'], 0, 22, '…'));
            }
            $y += 22;
        };

        $dibujarEncabezado();

        foreach (self::filasCoberturas($resultados) as $idx => [$nombre, $valores]) {
            $pdf->fuente(false, 8);
            $lineasNombre = $pdf->envolver($nombre, $etiquetaAncho - 16);
            $alto = max(18, count($lineasNombre) * 10 + 6);

            if (612 - $y - $alto < $margenInferior) {
                $pdf->agregarPagina();
                $y = 40;
                $dibujarEncabezado();
            }

            if ($idx % 2 === 1) {
                $pdf->rectangulo($margenX, $y, $anchoUtil, $alto, self::FONDO_ALT);
            }

            $pdf->fuente(false, 8);
            $pdf->colorTexto(20, 30, 40);
            foreach ($lineasNombre as $li => $linea) {
                $pdf->texto($margenX + 8, $y + 12 + $li * 10, $linea);
            }

            foreach ($valores as $i => $v) {
                $cx = $margenX + $etiquetaAncho + $i * $colAncho + $colAncho / 2;
                if ($v === null) {
                    $pdf->fuente(false, 7.5);
                    $pdf->colorTexto(...self::GRIS);
                    $pdf->textoCentrado($cx, $y + 12, 'No incluida');
                } else {
                    $pdf->fuente(true, 8);
                    $pdf->colorTexto(20, 30, 40);
                    $pdf->textoCentrado($cx, $y + 12, mb_strimwidth($v['suma'], 0, 20, '…'));
                    if (trim($v['ded']) !== '') {
                        $pdf->fuente(false, 7);
                        $pdf->colorTexto(...self::GRIS);
                        $pdf->textoCentrado($cx, $y + 22, 'ded. ' . mb_strimwidth($v['ded'], 0, 16, '…'));
                    }
                }
            }
            $y += $alto;
        }
    }

    // ─── Excel / CSV ─────────────────────────────────────────────────────────

    public static function csv(int $cotId): ?string
    {
        $d = self::datos($cotId);
        if ($d === null) {
            return null;
        }
        ['cot' => $cot, 'resultados' => $resultados] = $d;

        $fh = fopen('php://temp', 'w+');
        // BOM UTF-8: sin esto Excel abre los acentos como basura.
        fwrite($fh, "\xEF\xBB\xBF");

        fputcsv($fh, ['Comparativo Multi-Plan · Cotizador GNP · Equinox']);
        fputcsv($fh, ['Folio', $cot['folio'] ?: '(sin folio)']);
        fputcsv($fh, ['Vehículo', trim($cot['descripcion_veh'] . ' · ' . $cot['modelo'])]);
        fputcsv($fh, ['Conductor (fija el precio)', $cot['conductor_edad'] . ' años · CP ' . $cot['conductor_cp']]);
        fputcsv($fh, ['Vigencia', $cot['vence_en'] ? ('hasta ' . $cot['vence_en']) : '—']);
        fputcsv($fh, []);

        fputcsv($fh, array_merge(['Primas'], array_map(static fn ($r) => $r['paquete'] . ' (' . $r['cve_paquete'] . ')', $resultados)));
        foreach (self::filasPrimas($resultados) as [$etq, $valores]) {
            fputcsv($fh, array_merge([$etq], $valores));
        }
        fputcsv($fh, []);

        fputcsv($fh, array_merge(['Cobertura'], array_map(static fn ($r) => $r['paquete'], $resultados)));
        foreach (self::filasCoberturas($resultados) as [$nombre, $valores]) {
            $fila = [$nombre];
            foreach ($valores as $v) {
                $fila[] = $v === null ? 'No incluida' : $v['suma'] . (trim($v['ded']) !== '' ? ' (ded. ' . $v['ded'] . ')' : '');
            }
            fputcsv($fh, $fila);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv === false ? '' : $csv;
    }

    // ─── Nombres de archivo ─────────────────────────────────────────────────

    public static function nombreArchivo(array $cot, string $ext): string
    {
        $folio = (string) ($cot['folio'] ?? '');
        $clave = $folio !== '' ? $folio : 'id' . $cot['id'];
        return 'comparativo-' . preg_replace('/[^A-Za-z0-9_-]/', '', $clave) . '.' . $ext;
    }
}
