<?php
declare(strict_types=1);

/**
 * PdfBasico — generador de PDF mínimo, sin ninguna librería externa.
 *
 * Usa únicamente las 14 fuentes estándar de PDF (aquí: Helvetica y
 * Helvetica-Bold), que todo lector de PDF trae incluidas — así el archivo
 * nunca necesita embeber una fuente y queda 100% autocontenido en un solo
 * `require`, sin Composer ni descargar nada de internet.
 *
 * Cubre lo mínimo para armar reportes con texto, líneas y rectángulos:
 * exactamente lo que necesita ComparativoServicio. No es un reemplazo de
 * una librería de PDF completa a propósito.
 *
 * El sistema de coordenadas público mide Y desde ARRIBA de la página (más
 * intuitivo para armar un documento de arriba hacia abajo); PDF mide desde
 * abajo, así que la conversión se hace adentro.
 */
final class PdfBasico
{
    /** Ancho Helvetica, por carácter Windows-1252 (unidades de 1/1000 em). Tabla estándar Adobe. */
    private const ANCHO_REGULAR = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
        64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
        96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
        104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556,
        111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556,
        118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260,
        125 => 334, 126 => 584,
        // Acentos y símbolos en español (Windows-1252). Aproximados al ancho de la letra base:
        // visualmente imperceptible a los tamaños que usa este reporte.
        0xE1 => 556, 0xE9 => 556, 0xED => 222, 0xF3 => 556, 0xFA => 556, // á é í ó ú
        0xC1 => 667, 0xC9 => 667, 0xCD => 278, 0xD3 => 778, 0xDA => 722, // Á É Í Ó Ú
        0xF1 => 556, 0xD1 => 722, // ñ Ñ
        0xBF => 556, 0xA1 => 278, // ¿ ¡
        0xFC => 556, 0xDC => 722, // ü Ü
        0x80 => 556, // €
    ];

    /** Ancho Helvetica-Bold, misma unidad. */
    private const ANCHO_NEGRITA = [
        32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611,
        64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556,
        96 => 333, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611,
        104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611,
        111 => 611, 112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611,
        118 => 556, 119 => 778, 120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280,
        125 => 389, 126 => 584,
        0xE1 => 556, 0xE9 => 556, 0xED => 278, 0xF3 => 611, 0xFA => 611,
        0xC1 => 722, 0xC9 => 667, 0xCD => 278, 0xD3 => 778, 0xDA => 722,
        0xF1 => 611, 0xD1 => 722,
        0xBF => 611, 0xA1 => 333,
        0xFC => 611, 0xDC => 722,
        0x80 => 556,
    ];

    private const ANCHO_POR_OMISION = 556;

    /** @var list<string> contenido (stream) de cada página, sin comprimir */
    private array $paginas = [];
    private string $anchoPagina;
    private string $altoPagina;
    private float $ancho;
    private float $alto;

    private bool $negrita = false;
    private float $tam = 10;
    private array $colorTexto = [0, 0, 0];

    private string $pieIzquierda = '';
    private string $pieCentro = '';

    public function __construct(float $ancho = 792, float $alto = 612)
    {
        $this->ancho = $ancho;
        $this->alto  = $alto;
        $this->anchoPagina = self::num($ancho);
        $this->altoPagina  = self::num($alto);
    }

    public function anchoUtil(): float
    {
        return $this->ancho;
    }

    public function agregarPagina(): void
    {
        $this->paginas[] = '';
    }

    /** ¿Cuánto le queda a la página actual antes de tocar el margen inferior? */
    public function espacioRestante(float $margenInferior): float
    {
        // Se calcula afuera, contra la Y del cursor que lleva el llamador;
        // aquí sólo se expone el alto útil para que el cálculo sea consistente.
        return $this->alto - $margenInferior;
    }

    public function fuente(bool $negrita, float $tam): void
    {
        $this->negrita = $negrita;
        $this->tam     = $tam;
    }

    public function colorTexto(int $r, int $g, int $b): void
    {
        $this->colorTexto = [$r, $g, $b];
    }

    public function anchoTexto(string $s): float
    {
        $bytes = self::aWinAnsi($s);
        $tabla = $this->negrita ? self::ANCHO_NEGRITA : self::ANCHO_REGULAR;
        $total = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $total += $tabla[ord($bytes[$i])] ?? self::ANCHO_POR_OMISION;
        }
        return $total * $this->tam / 1000;
    }

    /**
     * Envuelve el texto para que quepa en $anchoMax, partiendo por palabras.
     *
     * @return list<string>
     */
    public function envolver(string $s, float $anchoMax): array
    {
        $palabras = preg_split('/\s+/u', trim($s)) ?: [];
        $lineas   = [];
        $actual   = '';
        foreach ($palabras as $p) {
            $prueba = $actual === '' ? $p : $actual . ' ' . $p;
            if ($this->anchoTexto($prueba) > $anchoMax && $actual !== '') {
                $lineas[] = $actual;
                $actual   = $p;
            } else {
                $actual = $prueba;
            }
        }
        if ($actual !== '') {
            $lineas[] = $actual;
        }
        return $lineas === [] ? [''] : $lineas;
    }

    public function texto(float $x, float $y, string $s): void
    {
        $this->escribir($x, $y, $s);
    }

    public function textoDerecha(float $xDerecha, float $y, string $s): void
    {
        $this->escribir($xDerecha - $this->anchoTexto($s), $y, $s);
    }

    public function textoCentrado(float $xCentro, float $y, string $s): void
    {
        $this->escribir($xCentro - $this->anchoTexto($s) / 2, $y, $s);
    }

    private function escribir(float $x, float $y, string $s): void
    {
        if ($s === '' || $this->paginas === []) {
            return;
        }
        $fuente = $this->negrita ? '/F2' : '/F1';
        $yPdf   = $this->alto - $y;
        [$r, $g, $b] = $this->colorTexto;
        $cmd = sprintf(
            "BT\n%s %s Tf\n%s %s %s rg\n%s %s Td\n(%s) Tj\nET\n",
            $fuente, self::num($this->tam),
            self::col($r), self::col($g), self::col($b),
            self::num($x), self::num($yPdf),
            self::escaparTexto($s)
        );
        $this->paginas[array_key_last($this->paginas)] .= $cmd;
    }

    /** $y es la esquina SUPERIOR del rectángulo (coherente con el resto de la API). */
    public function rectangulo(float $x, float $y, float $w, float $h, ?array $relleno, ?array $borde = null, float $grosor = 0.6): void
    {
        if ($this->paginas === []) {
            return;
        }
        $yPdf = $this->alto - $y - $h;
        $cmd  = '';
        if ($relleno !== null) {
            $cmd .= sprintf("%s %s %s rg\n", self::col($relleno[0]), self::col($relleno[1]), self::col($relleno[2]));
        }
        if ($borde !== null) {
            $cmd .= sprintf("%s %s %s RG\n%s w\n", self::col($borde[0]), self::col($borde[1]), self::col($borde[2]), self::num($grosor));
        }
        $cmd .= sprintf("%s %s %s %s re\n", self::num($x), self::num($yPdf), self::num($w), self::num($h));
        $cmd .= match (true) {
            $relleno !== null && $borde !== null => "B\n",
            $relleno !== null                    => "f\n",
            default                              => "S\n",
        };
        $this->paginas[array_key_last($this->paginas)] .= $cmd;
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, array $color = [0, 0, 0], float $grosor = 0.6): void
    {
        if ($this->paginas === []) {
            return;
        }
        $cmd = sprintf(
            "%s %s %s RG\n%s w\n%s %s m\n%s %s l\nS\n",
            self::col($color[0]), self::col($color[1]), self::col($color[2]),
            self::num($grosor),
            self::num($x1), self::num($this->alto - $y1),
            self::num($x2), self::num($this->alto - $y2)
        );
        $this->paginas[array_key_last($this->paginas)] .= $cmd;
    }

    /** Pie de página: se agrega solo a cada página al armar el archivo, con "Página X de Y". */
    public function establecerPie(string $izquierda, string $centro): void
    {
        $this->pieIzquierda = $izquierda;
        $this->pieCentro    = $centro;
    }

    public function bytes(): string
    {
        $total = count($this->paginas);
        $paginas = $this->paginas;

        if ($total > 0 && ($this->pieIzquierda !== '' || $this->pieCentro !== '')) {
            $this->negrita = false;
            for ($i = 0; $i < $total; $i++) {
                $y = $this->alto - 24;
                $piezas = [];
                if ($this->pieIzquierda !== '') {
                    $this->tam = 8;
                    $anchoIzq  = $this->anchoTexto($this->pieIzquierda);
                    $piezas[]  = sprintf(
                        "BT\n/F1 %s Tf\n0.35 0.42 0.5 rg\n%s %s Td\n(%s) Tj\nET\n",
                        self::num($this->tam), self::num(40), self::num($this->alto - $y), self::escaparTexto($this->pieIzquierda)
                    );
                    unset($anchoIzq);
                }
                if ($this->pieCentro !== '') {
                    $this->tam = 8;
                    $x = ($this->ancho - $this->anchoTexto($this->pieCentro)) / 2;
                    $piezas[] = sprintf(
                        "BT\n/F1 %s Tf\n0.35 0.42 0.5 rg\n%s %s Td\n(%s) Tj\nET\n",
                        self::num($this->tam), self::num($x), self::num($this->alto - $y), self::escaparTexto($this->pieCentro)
                    );
                }
                $etiqueta = "Página " . ($i + 1) . " de {$total}";
                $this->tam = 8;
                $xDer = $this->ancho - 40 - $this->anchoTexto($etiqueta);
                $piezas[] = sprintf(
                    "BT\n/F1 %s Tf\n0.35 0.42 0.5 rg\n%s %s Td\n(%s) Tj\nET\n",
                    self::num($this->tam), self::num($xDer), self::num($this->alto - $y), self::escaparTexto($etiqueta)
                );
                $paginas[$i] .= implode('', $piezas);
            }
        }

        $objetos = [];
        $objetos[0] = "<< /Type /Catalog /Pages 2 0 R >>";

        $numPrimeraPagina = 5;
        $kids = [];
        for ($i = 0; $i < $total; $i++) {
            $kids[] = ($numPrimeraPagina + $i * 2) . ' 0 R';
        }
        $objetos[1] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$total} >>";
        $objetos[2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objetos[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        for ($i = 0; $i < $total; $i++) {
            $numPagina    = $numPrimeraPagina + $i * 2;
            $numContenido = $numPagina + 1;
            $objetos[$numPagina - 1] = "<< /Type /Page /Parent 2 0 R "
                . "/MediaBox [0 0 {$this->anchoPagina} {$this->altoPagina}] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> "
                . "/Contents {$numContenido} 0 R >>";
            $objetos[$numContenido - 1] = ['stream', $paginas[$i]];
        }

        ksort($objetos);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objetos as $idx => $obj) {
            $offsets[] = strlen($out);
            $num = $idx + 1;
            if (is_array($obj)) {
                $contenido = $obj[1];
                $len = strlen($contenido);
                $out .= "{$num} 0 obj\n<< /Length {$len} >>\nstream\n{$contenido}endstream\nendobj\n";
            } else {
                $out .= "{$num} 0 obj\n{$obj}\nendobj\n";
            }
        }

        $xrefInicio = strlen($out);
        $n = count($objetos) + 1;
        $out .= "xref\n0 {$n}\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        $out .= "trailer\n<< /Size {$n} /Root 1 0 R >>\nstartxref\n{$xrefInicio}\n%%EOF";

        return $out;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function col(int $v): string
    {
        return self::num(max(0, min(255, $v)) / 255);
    }

    /** UTF-8 → Windows-1252: es lo que soporta /Encoding /WinAnsiEncoding sin embeber fuente. */
    private static function aWinAnsi(string $s): string
    {
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $r === false ? $s : $r;
    }

    private static function escaparTexto(string $s): string
    {
        $s = self::aWinAnsi($s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
}
