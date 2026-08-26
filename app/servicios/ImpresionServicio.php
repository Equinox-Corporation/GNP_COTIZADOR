<?php
declare(strict_types=1);

/**
 * ImpresionServicio — pide a GNP el PDF oficial de la cotización.
 *
 * Dos cosas que hay que tener presentes:
 *
 *  1. GNP TAMBIÉN manda el PDF por correo, con su propio remitente. Por eso el
 *     correo que se le envía sale de la configuración (GNP_CORREO_IMPRESION) y
 *     no del formulario: así el primer contacto con el cliente lo sigue
 *     controlando Equinox. Cuando Comercial decida otra cosa, se cambia aquí.
 *
 *  2. La respuesta pesa más de un megabyte porque el PDF viene dentro. Nunca se
 *     vuelca a pantalla ni se guarda en la base: va directo a disco.
 */
final class ImpresionServicio
{
    public static function carpeta(): string
    {
        $d = RUTA_BASE . '/datos/pdf';
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    /** @return array{ok:bool, documento_id:int, mensaje:string} */
    public static function generar(int $cotizacionId, string $cvePaquete): array
    {
        $cot = CotizacionServicio::obtener($cotizacionId);
        if ($cot === null || $cot['estado'] !== 'COTIZADA' || empty($cot['folio'])) {
            return ['ok' => false, 'documento_id' => 0, 'mensaje' => 'Esa cotización no está lista para imprimirse.'];
        }

        if (CotizacionServicio::vencida($cot)) {
            return ['ok' => false, 'documento_id' => 0,
                    'mensaje' => 'La cotización venció el ' . $cot['vence_en'] . '. Hay que volver a cotizar: GNP sólo las respeta 15 días.'];
        }

        $res = Db::uno('SELECT * FROM cot_resultados WHERE cotizacion_id = ? AND cve_paquete = ?',
                       [$cotizacionId, $cvePaquete]);
        if ($res === null) {
            return ['ok' => false, 'documento_id' => 0, 'mensaje' => 'Ese paquete no está en la cotización.'];
        }

        // El correo sale de la configuración, no del formulario. Ver el comentario de arriba.
        $correo = Env::get('GNP_CORREO_IMPRESION');
        if ($correo === '') {
            return ['ok' => false, 'documento_id' => 0,
                    'mensaje' => 'Falta definir GNP_CORREO_IMPRESION en la configuración. GNP exige un correo y ahí manda el PDF.'];
        }

        $r = CotizacionServicio::cliente()->imprimir(
            (string) $cot['folio'],
            $correo,
            [['cve' => $cvePaquete, 'desc' => (string) $res['paquete']]],
            (string) $cot['periodicidad']
        );
        CotizacionServicio::registrarLlamada($r, $cotizacionId, 'paquete ' . $cvePaquete);

        if ($r['estado'] !== GnpClient::OK || $r['pdf'] === '') {
            return ['ok' => false, 'documento_id' => 0,
                    'mensaje' => GnpClient::explicar($r) ?: ($r['error']['descripcion'] ?? 'No se pudo generar el PDF.')];
        }

        $nombre = sprintf('cot-%d-%s-%s.pdf', $cotizacionId, $cvePaquete, date('Ymd-His'));
        $ruta   = self::carpeta() . '/' . $nombre;

        if (file_put_contents($ruta, $r['pdf']) === false) {
            return ['ok' => false, 'documento_id' => 0, 'mensaje' => 'No se pudo guardar el PDF en el servidor.'];
        }

        Db::ejecutar(
            'INSERT INTO cot_documentos (cotizacion_id, cve_paquete, archivo, bytes, referencia)
             VALUES (?,?,?,?,?)',
            [$cotizacionId, $cvePaquete, $nombre, strlen($r['pdf']), $r['referencia']]
        );

        return ['ok' => true, 'documento_id' => Db::ultimoId(), 'mensaje' => ''];
    }

    public static function documentos(int $cotizacionId): array
    {
        return Db::todos(
            'SELECT * FROM cot_documentos WHERE cotizacion_id = ? ORDER BY id DESC',
            [$cotizacionId]
        );
    }

    public static function documento(int $id): ?array
    {
        return Db::uno('SELECT * FROM cot_documentos WHERE id = ?', [$id]);
    }

    /** Entrega el PDF al navegador. El archivo nunca es alcanzable por URL directa. */
    public static function descargar(int $id): void
    {
        $d = self::documento($id);
        if ($d === null) {
            http_response_code(404);
            exit('Documento no encontrado.');
        }

        // basename() evita que un nombre manipulado salga de la carpeta.
        $ruta = self::carpeta() . '/' . basename((string) $d['archivo']);
        if (!is_file($ruta)) {
            http_response_code(404);
            exit('El archivo ya no está en el servidor.');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="cotizacion-gnp-' . $d['cotizacion_id'] . '.pdf"');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }
}
