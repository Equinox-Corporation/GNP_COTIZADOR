<?php
declare(strict_types=1);

/**
 * ArmadorLibreServicio — cotiza una combinación de coberturas armada al
 * vuelo sobre un paquete base de GNP, sin que exista (o sin tocar) una
 * plantilla guardada en `cat_plantillas`. Fase 1, sólo backend — ver
 * docs/02.13-armador-libre-backend.md.
 *
 * Servicio aparte de `CotizacionServicio` y de `JuegaYCompararServicio`, a
 * propósito: no es "un paquete manual o una plantilla" (eso ya lo resuelve
 * `CotizacionServicio::cotizar()`) ni "varias plantillas ya guardadas a la
 * vez" (eso es `JuegaYCompararServicio`) — es una combinación que puede no
 * existir en ningún lado todavía, sólo en la cabeza del vendedor mientras la
 * arma. Cargarle esto a cualquiera de los otros dos habría mezclado un
 * concepto nuevo ("combinación ad-hoc, quizá nunca guardada") con lógica que
 * ya asume un origen fijo (checkbox o `cat_plantillas`).
 *
 * Reutiliza lo que ya existe, no lo repite:
 *   - `PlantillaServicio::paraAdHoc()` valida y traduce la combinación
 *     (mismas reglas que ya usa `guardar()`/`paraCotizar()` — Básica/
 *     Opcional/N-A del paquete, `cat_cobertura_valores`, exclusión mutua,
 *     antigüedad — cero reglas nuevas).
 *   - `CotizacionServicio::crearBorrador()`/`guardarResultados()`/
 *     `cliente()`/`registrarLlamada()` para hablar con GNP y persistir
 *     exactamente en las mismas tablas que el resto del cotizador.
 *   - `PlantillaServicio::guardar(null, ...)` si después se decide guardar la
 *     combinación como plantilla nueva — acción aparte, no algo que haga
 *     `cotizar()` de este servicio automáticamente.
 */
final class ArmadorLibreServicio
{
    /**
     * Cotiza una combinación ad-hoc directo contra GNP — cotización puntual,
     * no toca `cat_plantillas`.
     *
     * @param array $f datos del formulario — mismo shape que CotizacionServicio::cotizar()
     * @param list<array{cve:string,suma?:string,deducible?:string}> $coberturas combinación COMPLETA deseada, no un diff
     * @return array{ok:bool, cotizacion_id:int, mensaje:string}
     */
    public static function cotizar(array $f, string $cvePaquete, array $coberturas): array
    {
        $inicio = new DateTimeImmutable('today');
        $fin    = $inicio->modify('+1 year');

        $ap = PlantillaServicio::paraAdHoc($cvePaquete, $coberturas, (int) $f['modelo'], (int) $inicio->format('Y'));
        if (!$ap['ok']) {
            return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' => $ap['mensaje']];
        }
        if ($ap['tipo_persona'] !== $f['tipo_persona']) {
            return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' =>
                'Ese paquete es para persona ' . ($ap['tipo_persona'] === 'F' ? 'física' : 'moral') .
                ' y el solicitante capturado es ' . ($f['tipo_persona'] === 'F' ? 'física' : 'moral') . '. Ajusta uno de los dos.'];
        }
        if ($ap['tipo_vehiculo'] !== $f['tipo_vehiculo']) {
            return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' =>
                'Ese paquete es para ' . (CatalogoServicio::TIPOS_VEHICULO[$ap['tipo_vehiculo']] ?? $ap['tipo_vehiculo']) .
                ' y el vehículo capturado es ' . (CatalogoServicio::TIPOS_VEHICULO[$f['tipo_vehiculo']] ?? $f['tipo_vehiculo']) . '. Ajusta uno de los dos.'];
        }

        $veh = CatalogoServicio::vehiculo(
            $f['tipo_vehiculo'], $f['armadora'], $f['carroceria'], $f['version'], (int) $f['modelo']
        );
        if ($veh === null) {
            return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' => 'Ese vehículo no está en el catálogo. Vuelve a elegirlo.'];
        }

        $subRamo = CatalogoServicio::subRamoDe($f['procedencia']);
        if ($subRamo === '') {
            return ['ok' => false, 'cotizacion_id' => 0,
                    'mensaje' => "La procedencia \"{$f['procedencia']}\" todavía no tiene confirmada su clave con GNP. Por ahora sólo Residentes está verificado."];
        }

        $datos = [
            'vigencia_inicio'      => $inicio->format('Ymd'),
            'vigencia_fin'         => $fin->format('Ymd'),
            'periodicidad'         => $f['periodicidad'] ?? 'A',
            'sub_ramo'             => $subRamo,
            'tipo_vehiculo'        => $veh['tipo_vehiculo'],
            'modelo'               => $veh['modelo'],
            'armadora'             => $veh['armadora'],
            'carroceria'           => $veh['carroceria'],
            'version'              => $veh['version'],
            'tipo_persona'         => $f['tipo_persona'],
            'nombres'              => $f['nombres'],
            'apellido_paterno'     => $f['apellido_paterno'],
            'apellido_materno'     => $f['apellido_materno'],
            'contratante_edad'     => (int) $f['contratante_edad'],
            'contratante_rfc'      => $f['contratante_rfc'],
            'contratante_cp'       => $f['contratante_cp'],
            'conductor_nacimiento' => $f['conductor_nacimiento'],
            'conductor_sexo'       => $f['conductor_sexo'],
            'conductor_edad'       => (int) $f['conductor_edad'],
            'conductor_cp'         => $f['conductor_cp'],
        ];

        $linea   = trim($veh['carroceria_nombre']);
        $version = trim($veh['version_nombre']);
        $descripcion = str_starts_with(mb_strtoupper($version, 'UTF-8'), mb_strtoupper($linea, 'UTF-8'))
            ? $version
            : trim($linea . ' ' . $version);

        $cotId = CotizacionServicio::crearBorrador($veh, $datos, $f, $descripcion);

        $nombrePaquete = ucwords(mb_strtolower(
            (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$cvePaquete]) ?? ''),
            'UTF-8'
        ));
        // Un solo <PAQUETE> ad-hoc — no hay riesgo de colisión de DESC_PAQUETE
        // como en JuegaYCompararServicio (ahí conviven varias plantillas).
        $paquetes = [['cve' => $cvePaquete, 'desc' => $nombrePaquete, 'opcionales' => $ap['coberturas']]];

        $r = CotizacionServicio::cliente()->cotizar($datos, $paquetes);
        CotizacionServicio::registrarLlamada($r, $cotId, 'combinación ad-hoc — armador libre');

        if ($r['estado'] !== GnpClient::OK) {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?',
                         ['ERROR', $r['error']['descripcion'] ?? $r['estado'], $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId, 'mensaje' => GnpClient::explicar($r)];
        }

        if ($r['paquetes'] === []) {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?',
                         ['ERROR', 'GNP no devolvió ningún paquete.', $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId,
                    'mensaje' => 'GNP respondió pero no devolvió ningún paquete.'];
        }

        $errGuardado = CotizacionServicio::guardarResultados($cotId, $r, null, $ap['omitidas']);
        if ($errGuardado !== '') {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?', ['ERROR', $errGuardado, $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId, 'mensaje' => $errGuardado];
        }

        Db::ejecutar(
            "UPDATE cot_cotizaciones SET estado = 'COTIZADA', folio = ?, vence_en = date('now','localtime','+"
                . CotizacionServicio::DIAS_VIGENCIA . " day') WHERE id = ?",
            [$r['folio'], $cotId]
        );

        // Nunca en silencio (docs/02.12-bug-amparada.md): una cobertura de la
        // combinación que no se transmitió (ej. por antigüedad) se avisa aquí.
        $avisos = [];
        foreach ($ap['omitidas'] as $o) {
            $avisos[] = $o['motivo'];
        }

        return ['ok' => true, 'cotizacion_id' => $cotId, 'mensaje' => implode(' ', $avisos)];
    }
}
