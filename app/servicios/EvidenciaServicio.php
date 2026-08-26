<?php
declare(strict_types=1);

/**
 * EvidenciaServicio — arma el expediente descargable de una cotización.
 *
 * Para qué sirve:
 *
 *  · Soporte. Cuando una cotización sale rara, con este archivo se ve
 *    exactamente qué se le pidió a GNP y qué contestó, sin tener que
 *    reproducir el caso.
 *  · Certificación. GNP pide evidencia de las pruebas; este archivo es la
 *    evidencia, ya armada y sin datos de acceso.
 *  · Comparación. Es el mismo contenido que se veía en Postman, pero
 *    guardado solo, en cada cotización.
 *
 * El archivo es JSON aunque GNP hable XML: adentro va el XML literal, tal
 * cual viajó, dentro de campos de texto. Así se tiene lo legible (el JSON
 * ordenado) y lo textual (el XML crudo) en un solo archivo.
 *
 * La contraseña NUNCA aparece: GnpClient la enmascara antes de guardarla.
 */
final class EvidenciaServicio
{
    /**
     * Sólo lo que el cotizador le pidió a GNP: los datos armados y el XML de
     * entrada de cada llamada. Para quien necesita ver qué se mandó, sin el
     * ruido de la respuesta.
     *
     * @return array<string,mixed>|null null si la cotización no existe
     */
    public static function peticion(int $cotId): ?array
    {
        $cot = CotizacionServicio::obtener($cotId);
        if ($cot === null) {
            return null;
        }

        return [
            'generado_por' => 'Cotizador GNP · Equinox',
            'generado_en'  => date('c'),
            'parte'        => 'peticion',
            'aviso'        => 'Lo que el cotizador le pidió a GNP. La contraseña va enmascarada.',
            'cotizacion'   => self::ficha($cot),
            'peticion'     => self::bloquePeticion($cot, $cotId),
            'llamadas'     => array_map(static fn (array $l): array => [
                'servicio'     => $l['servicio'],
                'detalle'      => $l['detalle'],
                'ejecutado_en' => $l['ejecutado_en'],
                'request_xml'  => (string) ($l['xml_entrada'] ?? ''),
            ], CotizacionServicio::llamadas($cotId)),
        ];
    }

    /**
     * Sólo lo que GNP contestó: los paquetes ya tarificados y el XML de
     * salida de cada llamada, con su estado y tiempos.
     *
     * @return array<string,mixed>|null null si la cotización no existe
     */
    public static function respuesta(int $cotId): ?array
    {
        $cot = CotizacionServicio::obtener($cotId);
        if ($cot === null) {
            return null;
        }

        return [
            'generado_por' => 'Cotizador GNP · Equinox',
            'generado_en'  => date('c'),
            'parte'        => 'respuesta',
            'aviso'        => 'Lo que GNP contestó. Puede contener datos personales del cliente: trátalo como confidencial.',
            'cotizacion'   => self::ficha($cot),
            'respuesta'    => [
                'folio'    => $cot['folio'],
                'paquetes' => self::paquetes($cotId),
            ],
            'llamadas'     => array_map(static fn (array $l): array => [
                'servicio'     => $l['servicio'],
                'ejecutado_en' => $l['ejecutado_en'],
                'estado'       => $l['estado'],
                'http'         => (int) $l['http'],
                'ms'           => (int) $l['ms'],
                'bytes'        => (int) $l['bytes'],
                'error'        => $l['error_desc'] === null ? null : [
                    'clave'       => $l['error_clave'],
                    'origen'      => $l['error_origen'],
                    'descripcion' => $l['error_desc'],
                ],
                'response_xml' => (string) ($l['xml_salida'] ?? ''),
            ], CotizacionServicio::llamadas($cotId)),
        ];
    }

    /** @return array<string,mixed> */
    private static function ficha(array $cot): array
    {
        return [
            'id'        => (int) $cot['id'],
            'folio'     => $cot['folio'],
            'estado'    => $cot['estado'],
            'creada_en' => $cot['creada_en'],
            'vence_en'  => $cot['vence_en'],
            'vencida'   => CotizacionServicio::vencida($cot),
            'error'     => $cot['error_desc'],
        ];
    }

    /** @return array<string,mixed> */
    private static function bloquePeticion(array $cot, int $cotId): array
    {
        // La pantalla de cotización pide una sola persona ("Solicitante") y el
        // contratante hereda de ahí la edad y el CP. Se deja constancia en el
        // expediente: quien lo lea dentro de seis meses no tiene por qué saberlo.
        $heredado = (int) $cot['contratante_edad'] === (int) $cot['conductor_edad']
                 && (string) $cot['contratante_cp'] === (string) $cot['conductor_cp'];

        return [
            'como_se_capturo' => $heredado
                ? 'En una sola sección, "Solicitante". El contratante heredó la edad y el código postal del conductor; sólo el nombre y el RFC se capturaron aparte.'
                : 'Contratante y conductor se capturaron por separado, con edad o código postal distintos.',
            'vehiculo' => [
                'clavemarca'    => $cot['clavemarca'],
                'descripcion'   => $cot['descripcion_veh'],
                'tipo_vehiculo' => $cot['tipo_vehiculo'],
                'armadora'      => $cot['armadora'],
                'carroceria'    => $cot['carroceria'],
                'version'       => $cot['version'],
                'modelo'        => (int) $cot['modelo'],
                'procedencia'   => $cot['procedencia'],
                'sub_ramo'      => $cot['sub_ramo'],
            ],
            'contratante' => [
                'tipo_persona'  => $cot['tipo_persona'],
                'nombre'        => $cot['contratante'],
                'edad'          => (int) $cot['contratante_edad'],
                'codigo_postal' => $cot['contratante_cp'],
                'rfc'           => $cot['contratante_rfc'],
                'nota'          => 'Identifica al titular. No interviene en el cálculo de la prima.'
                                 . ($heredado ? ' Edad y código postal heredados del conductor.' : ''),
            ],
            'conductor' => [
                'edad'          => (int) $cot['conductor_edad'],
                'codigo_postal' => $cot['conductor_cp'],
                'sexo'          => $cot['conductor_sexo'],
                'nota'          => 'Es el que determina el precio: GNP tarifica con estos datos y son los que salen impresos en el PDF.',
            ],
            'vigencia' => [
                'inicio'       => $cot['vigencia_inicio'],
                'fin'          => $cot['vigencia_fin'],
                'periodicidad' => $cot['periodicidad'],
            ],
            'opcionales_solicitadas' => CotizacionServicio::opcionales($cotId),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function paquetes(int $cotId): array
    {
        $paquetes = [];
        foreach (CotizacionServicio::resultados($cotId) as $r) {
            $paquetes[] = [
                'cve_paquete'   => $r['cve_paquete'],
                'paquete'       => $r['paquete'],
                'total_pagar'   => $r['total_pagar'] !== null ? (float) $r['total_pagar'] : null,
                'prima_neta'    => $r['prima_neta']  !== null ? (float) $r['prima_neta']  : null,
                'derechos'      => $r['derechos']    !== null ? (float) $r['derechos']    : null,
                'iva'           => $r['iva']         !== null ? (float) $r['iva']         : null,
                'descuento'     => $r['descuento']   !== null ? (float) $r['descuento']   : null,
                'num_pagos'     => $r['num_pagos']   !== null ? (int)   $r['num_pagos']   : null,
                'conceptos'     => json_decode((string) $r['conceptos_json'], true) ?: [],
                'coberturas'    => array_map(static fn (array $c): array => [
                    'cve_cobertura'  => $c['cve_cobertura'],
                    'nombre'         => $c['nombre'],
                    'suma_asegurada' => $c['suma_asegurada'],
                    'deducible'      => $c['deducible'],
                ], $r['coberturas']),
            ];
        }
        return $paquetes;
    }

    /** Nombre de archivo estable y ordenable. */
    public static function nombreArchivo(array $exp, string $sufijo = ''): string
    {
        $folio = (string) ($exp['cotizacion']['folio'] ?? '');
        $clave = $folio !== '' ? $folio : 'id' . $exp['cotizacion']['id'];
        $suf   = $sufijo !== '' ? '-' . $sufijo : '';
        return 'gnp-cotizacion-' . preg_replace('/[^A-Za-z0-9_-]/', '', $clave) . $suf . '.json';
    }

    /**
     * Entrega una parte del expediente como descarga.
     *
     * @param 'peticion'|'respuesta' $parte
     */
    public static function descargar(int $cotId, string $parte = 'peticion'): never
    {
        $exp = $parte === 'respuesta' ? self::respuesta($cotId) : self::peticion($cotId);
        if ($exp === null) {
            http_response_code(404);
            exit('Cotización no encontrada.');
        }

        $json = json_encode($exp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . self::nombreArchivo($exp, $exp['parte']) . '"');
        header('Content-Length: ' . strlen((string) $json));
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }
}
