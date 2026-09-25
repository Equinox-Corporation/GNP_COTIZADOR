<?php
declare(strict_types=1);

/**
 * ZipMinimo — escritor de archivos .zip sin la extensión zip de PHP.
 *
 * La extensión `zip` no viene habilitada en este XAMPP (php_zip.dll existe
 * pero no está en php.ini) y el proyecto es sin librerías externas, así que
 * en vez de depender de configuración de máquina, esto arma el .zip a mano:
 * cabeceras locales + directorio central + fin de directorio central,
 * comprimiendo cada entrada con gzdeflate() (deflate crudo, método 8),
 * que es parte del core de PHP.
 *
 * Uso:
 *   $zip = new ZipMinimo();
 *   $zip->agregar('xl/workbook.xml', $xmlWorkbook);
 *   $zip->guardar($ruta);
 */
final class ZipMinimo
{
    /** @var list<array{nombre:string,datos:string}> */
    private array $entradas = [];

    public function agregar(string $nombreEnZip, string $contenido): void
    {
        $this->entradas[] = ['nombre' => $nombreEnZip, 'datos' => $contenido];
    }

    public function guardar(string $ruta): void
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($this->entradas as $e) {
            $nombre = $e['nombre'];
            $datos  = $e['datos'];
            $crc    = crc32($datos);
            $sinComprimir = strlen($datos);
            $comprimido   = gzdeflate($datos, 6);
            $comprimidoLen = strlen($comprimido);

            $cabeceraLocal = pack('VvvvvvVVVvv',
                0x04034b50, // firma cabecera local
                20,         // version needed
                0,          // flags
                8,          // metodo: deflate
                0,          // mod time
                0,          // mod date
                $crc,
                $comprimidoLen,
                $sinComprimir,
                strlen($nombre),
                0           // extra length
            );

            $local .= $cabeceraLocal . $nombre . $comprimido;

            $cabeceraCentral = pack('VvvvvvvVVVvvvvvVV',
                0x02014b50, // firma directorio central
                20,         // version made by
                20,         // version needed
                0,          // flags
                8,          // metodo
                0,          // mod time
                0,          // mod date
                $crc,
                $comprimidoLen,
                $sinComprimir,
                strlen($nombre),
                0,          // extra length
                0,          // comment length
                0,          // disk number start
                0,          // internal attrs
                0x20,       // external attrs (archivo normal)
                $offset
            );

            $central .= $cabeceraCentral . $nombre;
            $offset += strlen($cabeceraLocal) + strlen($nombre) + $comprimidoLen;
        }

        $finCentral = pack('VvvvvVVv',
            0x06054b50,
            0, 0,
            count($this->entradas),
            count($this->entradas),
            strlen($central),
            $offset,
            0
        );

        file_put_contents($ruta, $local . $central . $finCentral);
    }
}
