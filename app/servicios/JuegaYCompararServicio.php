<?php
declare(strict_types=1);

/**
 * JuegaYCompararServicio — cotiza varias plantillas propias de Equinox a la
 * vez, lado a lado, en una sola llamada a GNP (ADR-007, Paso 2).
 *
 * Servicio aparte de `CotizacionServicio`, a propósito: `cotizar()` allá
 * está pensado para UN origen de coberturas por llamada (un paquete manual
 * a la vez, o una sola plantilla) y ya carga bastante lógica propia de esos
 * casos (mensajes de "usar plantilla propia", el flujo sin plantilla).
 * Mezclarle "N plantillas, cada una resuelta y validada por su cuenta, una
 * llamada" habría hecho ese método menos legible sin necesidad — aquí cada
 * paquete SIEMPRE viene de una plantilla, nunca de un checkbox manual, así
 * que no hay que cargar con las ramas que eso exige allá.
 *
 * Reutiliza lo que ya existe, no lo repite: `PlantillaServicio::paraCotizar()`
 * para resolver y revalidar cada plantilla, `GnpClient::cotizar()` con su
 * soporte permanente de `opcionales` por paquete (docs/02.12-bug-amparada.md),
 * y `CotizacionServicio::crearBorrador()`/`guardarResultados()`/
 * `registrarLlamada()` para persistir exactamente en las mismas tablas que
 * "GNP Cotizador" — mismo `cot_cotizaciones`/`cot_resultados`/
 * `cot_resultado_coberturas`, así que la pantalla de resultado
 * (`resultado.php`) sirve sin cambios para esta cotización también.
 */
final class JuegaYCompararServicio
{
    /**
     * @param array    $f            datos del formulario — mismo shape que CotizacionServicio::cotizar()
     * @param list<int> $plantillaIds ids de cat_plantillas a comparar, en el orden elegido
     * @return array{ok:bool, cotizacion_id:int, mensaje:string}
     */
    public static function cotizar(array $f, array $plantillaIds): array
    {
        $plantillaIds = array_values(array_unique(array_filter(
            array_map('intval', $plantillaIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($plantillaIds === []) {
            return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' => 'Elige al menos una plantilla para comparar.'];
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

        $inicio = new DateTimeImmutable('today');
        $fin    = $inicio->modify('+1 year');
        $anioVigencia = (int) $inicio->format('Y');

        // Se resuelve y revalida cada plantilla ANTES de gastar la llamada —
        // igual criterio que el flujo de plantilla única: nunca se confía en
        // lo que estaba bien el día que se guardó.
        $paquetes    = [];
        $metaPorDesc = [];
        $avisosOmitidas = [];
        foreach ($plantillaIds as $pid) {
            $ap = PlantillaServicio::paraCotizar($pid, (int) $f['modelo'], $anioVigencia);
            if (!$ap['ok']) {
                return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' => $ap['mensaje']];
            }
            if ($ap['tipo_persona'] !== $f['tipo_persona']) {
                return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' =>
                    "La plantilla \"{$ap['nombre']}\" es para persona " . ($ap['tipo_persona'] === 'F' ? 'física' : 'moral') .
                    ' y el solicitante capturado es ' . ($f['tipo_persona'] === 'F' ? 'física' : 'moral') . '. Ajusta uno de los dos.'];
            }
            if ($ap['tipo_vehiculo'] !== $f['tipo_vehiculo']) {
                return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' =>
                    "La plantilla \"{$ap['nombre']}\" es para " . (CatalogoServicio::TIPOS_VEHICULO[$ap['tipo_vehiculo']] ?? $ap['tipo_vehiculo']) .
                    ' y el vehículo capturado es ' . (CatalogoServicio::TIPOS_VEHICULO[$f['tipo_vehiculo']] ?? $f['tipo_vehiculo']) . '. Ajusta uno de los dos.'];
            }

            // DESC_PAQUETE lleva el NOMBRE de la plantilla, no el del paquete
            // base — es lo único que garantiza distinguir cada resultado al
            // volver de GNP: dos plantillas reales (Equinox Amplia Plus y
            // Equinox Amplia) comparten el mismo cve_paquete de GNP, así que
            // esa clave sola no alcanza para saber cuál es cuál. Mismo
            // criterio ya probado en `prueba_multipaquete_plantillas.php`
            // (docs/02.11) y ahora permanente aquí.
            $paquetes[] = ['cve' => $ap['cve_paquete'], 'desc' => $ap['nombre'], 'opcionales' => $ap['coberturas']];
            $metaPorDesc[$ap['nombre']] = ['plantilla_id' => $pid, 'omitidas' => $ap['omitidas']];
            foreach ($ap['omitidas'] as $o) {
                $avisosOmitidas[] = "{$ap['nombre']}: {$o['motivo']}";
            }
        }

        // Coberturas de distintas plantillas pueden chocar entre sí si GNP las
        // considera excluyentes dentro del MISMO paquete — se revisa por
        // plantilla, no mezclando las de todas: cada una vive en su propio
        // <PAQUETE>, así que una exclusión sólo aplica dentro de esa lista.
        foreach ($paquetes as $p) {
            $choque = CatalogoServicio::chocanEntreSi(array_column($p['opcionales'], 'cve'));
            if ($choque !== '') {
                return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' => "\"{$p['desc']}\": {$choque}"];
            }
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

        // Cada plantilla trae sus propias coberturas dentro de su propio
        // <PAQUETE> ('opcionales' por elemento) — no hay un $opcionales
        // compartido: mezclarlas mandaría las de una plantilla al paquete de
        // otra. Soporte permanente en GnpClient, ver docs/02.12-bug-amparada.md.
        $r = CotizacionServicio::cliente()->cotizar($datos, $paquetes);
        CotizacionServicio::registrarLlamada($r, $cotId, count($paquetes) . ' plantilla(s) — Juega y Compara');

        if ($r['estado'] !== GnpClient::OK) {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?',
                         ['ERROR', $r['error']['descripcion'] ?? $r['estado'], $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId, 'mensaje' => GnpClient::explicar($r)];
        }

        if ($r['paquetes'] === []) {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?',
                         ['ERROR', 'GNP no devolvió ningún paquete.', $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId,
                    'mensaje' => 'GNP respondió pero no devolvió ningún paquete. Revisa las plantillas elegidas.'];
        }

        // Se guarda con el mapeo por DESC_PAQUETE (nombre de la plantilla),
        // no por cve_paquete — ver el comentario de más arriba.
        $errGuardado = CotizacionServicio::guardarResultados($cotId, $r, null, [], $metaPorDesc);
        if ($errGuardado !== '') {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?', ['ERROR', $errGuardado, $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId, 'mensaje' => $errGuardado];
        }

        Db::ejecutar(
            "UPDATE cot_cotizaciones SET estado = 'COTIZADA', folio = ?, vence_en = date('now','localtime','+"
                . CotizacionServicio::DIAS_VIGENCIA . " day') WHERE id = ?",
            [$r['folio'], $cotId]
        );

        $avisos = [];
        if (count($r['paquetes']) < count($paquetes)) {
            $avisos[] = 'Ojo: se pidieron ' . count($paquetes) . ' plantillas y GNP devolvió ' . count($r['paquetes']) . '.';
        }
        // Nunca en silencio (docs/02.12-bug-amparada.md): cualquier cobertura
        // que alguna plantilla traía y no se transmitió, se avisa aquí.
        foreach ($avisosOmitidas as $motivo) {
            $avisos[] = $motivo;
        }

        return ['ok' => true, 'cotizacion_id' => $cotId, 'mensaje' => implode(' ', $avisos)];
    }
}
