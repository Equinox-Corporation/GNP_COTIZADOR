<?php
declare(strict_types=1);

/**
 * CotizacionServicio — arma la petición, la manda a GNP y guarda el resultado.
 *
 * Aquí vive la regla más importante del sistema:
 *   el precio que se muestra es TOTAL_PAGAR, nunca PRIMA_NETA.
 * Usar la neta sería cobrar alrededor de 21% por debajo del precio real.
 */
final class CotizacionServicio
{
    /** Una cotización de GNP vale 15 días naturales. Está impreso en el PDF. */
    public const DIAS_VIGENCIA = 15;

    public static function cliente(): GnpClient
    {
        return new GnpClient(
            Env::requerir('GNP_BASE_URL'),
            Env::requerir('GNP_USUARIO'),
            Env::requerir('GNP_PASSWORD'),
            Env::requerir('GNP_ID_UNIDAD_OPERABLE'),
            Env::get('GNP_INTERMEDIARIO'),
            (int) Env::get('GNP_TIMEOUT', '60')
        );
    }

    /**
     * @param array        $f          datos del formulario ya validados
     * @param list<string> $cvePaquetes claves de paquete a cotizar (se ignora si se da $plantillaId)
     * @param list<array{cve:string,suma?:string,deducible?:string}> $opcionales (se ignora si se da $plantillaId)
     * @param int|null $plantillaId  si se da, se cotiza con esa plantilla propia (ADR-007):
     *        un solo paquete —el de la plantilla— con sus coberturas ya revalidadas contra
     *        el catálogo actual (`PlantillaServicio::paraCotizar()`). No convive con el
     *        comparativo multi-paquete: eso es ADR-007 punto 7, todavía sin resolver.
     *
     * @return array{ok:bool, cotizacion_id:int, mensaje:string}
     */
    public static function cotizar(array $f, array $cvePaquetes, array $opcionales = [], ?int $plantillaId = null): array
    {
        // Se calcula antes de resolver la plantilla: paraCotizar() necesita el
        // año de vigencia para aplicar reglas de antigüedad del vehículo
        // (ej. "Siempre en Agencia" — docs/02.12-bug-amparada.md).
        $inicio = new DateTimeImmutable('today');
        $fin    = $inicio->modify('+1 year');

        $avisosOmitidas = [];
        if ($plantillaId !== null) {
            $aplicada = PlantillaServicio::paraCotizar($plantillaId, (int) $f['modelo'], (int) $inicio->format('Y'));
            if (!$aplicada['ok']) {
                return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' => $aplicada['mensaje']];
            }
            if ($aplicada['tipo_persona'] !== $f['tipo_persona']) {
                return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' =>
                    'Esa plantilla es para persona ' . ($aplicada['tipo_persona'] === 'F' ? 'física' : 'moral') .
                    ' y el solicitante capturado es ' . ($f['tipo_persona'] === 'F' ? 'física' : 'moral') . '. Ajusta uno de los dos.'];
            }
            if ($aplicada['tipo_vehiculo'] !== $f['tipo_vehiculo']) {
                return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' =>
                    'Esa plantilla es para ' . (CatalogoServicio::TIPOS_VEHICULO[$aplicada['tipo_vehiculo']] ?? $aplicada['tipo_vehiculo']) .
                    ' y el vehículo capturado es ' . (CatalogoServicio::TIPOS_VEHICULO[$f['tipo_vehiculo']] ?? $f['tipo_vehiculo']) . '. Ajusta uno de los dos.'];
            }
            $cvePaquetes = [$aplicada['cve_paquete']];
            $opcionales  = $aplicada['coberturas'];
            // Nunca en silencio: una cobertura omitida (por antigüedad u otra
            // razón futura) se avisa igual que cualquier otro aviso de esta cotización.
            foreach ($aplicada['omitidas'] as $o) {
                $avisosOmitidas[] = $o['motivo'];
            }
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

        // Antes de gastar una llamada: GNP rechaza la cotización entera si se le
        // piden dos coberturas excluyentes. Mejor decirlo aquí, con nombres.
        $choque = CatalogoServicio::chocanEntreSi(array_column($opcionales, 'cve'));
        if ($choque !== '') {
            return ['ok' => false, 'cotizacion_id' => 0, 'mensaje' => $choque];
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

        // GNP repite el nombre de la línea dentro del de la versión
        // ("SUZUKI SWIFT" + "SUZUKI SWIFT GLS L4 1.2 AUT"): se evita el eco.
        $linea   = trim($veh['carroceria_nombre']);
        $version = trim($veh['version_nombre']);
        $descripcion = str_starts_with(mb_strtoupper($version, 'UTF-8'), mb_strtoupper($linea, 'UTF-8'))
            ? $version
            : trim($linea . ' ' . $version);

        // Se guarda ANTES de llamar: si GNP falla, queda el rastro de lo que se pidió.
        $cotId = self::crearBorrador($veh, $datos, $f, $descripcion);

        // GNP quiere el NOMBRE de la cobertura junto con su clave. Y si el
        // vendedor no capturó suma asegurada, se usa la de omisión del catálogo:
        // mandarla vacía haría que GNP tarifique con otro valor sin avisar.
        $grupo = CatalogoServicio::grupo($veh['tipo_vehiculo']);
        foreach ($opcionales as $i => $o) {
            $cat = Db::uno(
                'SELECT nombre, sa_valor FROM cat_coberturas WHERE grupo = ? AND cve_cobertura = ? LIMIT 1',
                [$grupo, $o['cve']]
            );
            $opcionales[$i]['nombre'] = $cat['nombre'] ?? '';
            if (($o['suma'] ?? '') === '' && is_numeric(str_replace(',', '', (string) ($cat['sa_valor'] ?? '')))) {
                $opcionales[$i]['suma'] = str_replace(',', '', (string) $cat['sa_valor']);
            }
        }

        // deducible y plantilla_id son NULL/'' por default para el flujo manual
        // (sin plantilla): no cambia lo que cotizar.php ya guardaba antes de esto.
        foreach ($opcionales as $o) {
            Db::ejecutar(
                'INSERT INTO cot_opcionales (cotizacion_id, cve_cobertura, suma_asegurada, deducible, plantilla_id) VALUES (?,?,?,?,?)
                 ON CONFLICT (cotizacion_id, cve_cobertura) DO NOTHING',
                [$cotId, $o['cve'], $o['suma'] ?? '', $o['deducible'] ?? '', $plantillaId]
            );
        }

        // Los tres paquetes van en UNA sola llamada: es el comparativo de GNP.
        $paquetes = [];
        foreach ($cvePaquetes as $cve) {
            $nombre = (string) (Db::valor('SELECT paquete FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$cve]) ?? '');
            $paquetes[] = ['cve' => $cve, 'desc' => ucwords(mb_strtolower($nombre, 'UTF-8'))];
        }

        $r = self::cliente()->cotizar($datos, $paquetes, $opcionales);
        self::registrarLlamada($r, $cotId, count($paquetes) . ' paquete(s)');

        if ($r['estado'] !== GnpClient::OK) {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?',
                         ['ERROR', $r['error']['descripcion'] ?? $r['estado'], $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId, 'mensaje' => GnpClient::explicar($r)];
        }

        if ($r['paquetes'] === []) {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?',
                         ['ERROR', 'GNP no devolvió ningún paquete.', $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId,
                    'mensaje' => 'GNP respondió pero no devolvió ningún paquete. Revisa la combinación elegida.'];
        }

        // Sólo hay UNA plantilla en este flujo (el de "usar plantilla propia"
        // de cotizar.php): todo el paquete cotizado es de ella, o de ninguna.
        $errGuardado = self::guardarResultados($cotId, $r, $plantillaId, $plantillaId !== null ? ($aplicada['omitidas'] ?? []) : []);
        if ($errGuardado !== '') {
            Db::ejecutar('UPDATE cot_cotizaciones SET estado = ?, error_desc = ? WHERE id = ?', ['ERROR', $errGuardado, $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId, 'mensaje' => $errGuardado];
        }

        Db::ejecutar(
            "UPDATE cot_cotizaciones SET estado = 'COTIZADA', folio = ?, vence_en = date('now','localtime','+" . self::DIAS_VIGENCIA . " day') WHERE id = ?",
            [$r['folio'], $cotId]
        );

        $avisos = [];
        if (count($r['paquetes']) < count($paquetes)) {
            $avisos[] = 'Ojo: se pidieron ' . count($paquetes) . ' paquetes y GNP devolvió ' . count($r['paquetes']) . '.';
        }
        // Nunca en silencio (ver docs/02.12-bug-amparada.md): una cobertura de
        // la plantilla omitida por antigüedad u otra razón se avisa aquí.
        foreach ($avisosOmitidas as $motivo) {
            $avisos[] = $motivo;
        }

        return ['ok' => true, 'cotizacion_id' => $cotId, 'mensaje' => implode(' ', $avisos)];
    }

    /**
     * Inserta el borrador de la cotización — ANTES de llamar a GNP, para que
     * quede el rastro de lo que se pidió aunque la llamada falle. Compartido
     * por el flujo manual/plantilla única de aquí y por
     * `JuegaYCompararServicio` (ADR-007, Paso 2): arman los mismos campos,
     * sólo cambia cómo se resuelven los paquetes a cotizar.
     */
    public static function crearBorrador(array $veh, array $datos, array $f, string $descripcion): int
    {
        Db::ejecutar(
            'INSERT INTO cot_cotizaciones
                (estado, usuario_id, tipo_vehiculo, clavemarca, armadora, carroceria, version, modelo,
                 descripcion_veh, sub_ramo, procedencia, tipo_persona, contratante, contratante_edad,
                 contratante_cp, contratante_rfc, conductor_edad, conductor_cp, conductor_sexo, correo,
                 periodicidad, vigencia_inicio, vigencia_fin)
             VALUES (:es,:us,:tv,:cm,:ar,:cc,:ve,:mo,:dv,:sr,:pr,:tp,:co,:ce,:cp,:rf,:de,:dc,:ds,:em,:pe,:vi,:vf)',
            [
                ':es' => 'BORRADOR', ':us' => Auth::id(), ':tv' => $veh['tipo_vehiculo'],
                ':cm' => $veh['clavemarca'], ':ar' => $veh['armadora'], ':cc' => $veh['carroceria'],
                ':ve' => $veh['version'], ':mo' => $veh['modelo'], ':dv' => $descripcion,
                ':sr' => $datos['sub_ramo'], ':pr' => $f['procedencia'], ':tp' => $f['tipo_persona'],
                ':co' => trim($f['nombres'] . ' ' . $f['apellido_paterno'] . ' ' . $f['apellido_materno']),
                ':ce' => (int) $f['contratante_edad'], ':cp' => $f['contratante_cp'], ':rf' => $f['contratante_rfc'],
                ':de' => (int) $f['conductor_edad'], ':dc' => $f['conductor_cp'], ':ds' => $f['conductor_sexo'],
                ':em' => $f['correo'], ':pe' => $datos['periodicidad'],
                ':vi' => $datos['vigencia_inicio'], ':vf' => $datos['vigencia_fin'],
            ]
        );
        return (int) Db::ultimoId();
    }

    /**
     * @param int|null $plantillaId de dónde salió TODO lo cotizado en esta
     *        llamada, cuando es un solo origen (flujo de "usar plantilla
     *        propia" de cotizar.php). NULL para "GNP Cotizador" manual —
     *        no cambia su comportamiento — y también NULL cuando se usa
     *        $porPaquete (varias plantillas, ver abajo).
     * @param list<array{cve:string,nombre:string,motivo:string}> $omitidas
     *        coberturas omitidas de esa única plantilla, cuando $plantillaId
     *        no es NULL. Ver `PlantillaServicio::paraGnpClient()`.
     * @param array<string,array{plantilla_id:int,omitidas:list<array{cve:string,nombre:string,motivo:string}>}> $porPaquete
     *        para el módulo "Juega y Compara" (varias plantillas a la vez):
     *        metadatos por paquete, indexados por **DESC_PAQUETE** — nunca
     *        por CVE_PAQUETE, porque dos plantillas reales (Equinox Amplia
     *        Plus y Equinox Amplia) comparten la misma clave de paquete de
     *        GNP; el nombre de la plantilla sí es único y GNP lo devuelve
     *        igual que se le mandó (mismo criterio ya usado en
     *        `prueba_multipaquete_plantillas.php`, docs/02.11).
     * @return string mensaje de error de negocio, o '' si se guardó bien.
     *        `cot_resultados` ya no tiene un `UNIQUE(cotizacion_id,
     *        cve_paquete)` (10-sep-2026, ver Esquema.php::migrar()): dos
     *        plantillas reales comparten `cve_paquete`, así que esa regla ya
     *        no describe una duplicidad real. La que sí lo es —el mismo
     *        (`cve_paquete`, `plantilla_id`) repetido, sea por un paquete
     *        estándar marcado dos veces en 'GNP Cotizador' o por la misma
     *        plantilla elegida dos veces aquí— ya no la detiene ningún
     *        índice de la base: se valida aquí, explícitamente, antes del
     *        INSERT, para que sea un error de negocio legible y no un
     *        renglón duplicado silencioso ni una excepción de PDO sin capturar.
     */
    public static function guardarResultados(int $cotId, array $r, ?int $plantillaId = null, array $omitidas = [], array $porPaquete = []): string
    {
        $vistos = [];
        foreach ($r['paquetes'] as $p) {
            $meta  = $porPaquete[$p['desc']] ?? null;
            $plId  = $meta['plantilla_id'] ?? $plantillaId;
            $llave = $p['cve'] . '|' . ($plId ?? '');
            if (isset($vistos[$llave])) {
                return 'GNP devolvió el paquete "' . $p['desc'] . '" (' . $p['cve'] . ') más de una vez en la misma '
                     . 'cotización — no se guarda, para no dejar un resultado duplicado. Revisa que no se haya '
                     . 'marcado el mismo paquete o elegido la misma plantilla dos veces.';
            }
            $vistos[$llave] = true;
        }

        Db::ejecutar('DELETE FROM cot_resultados WHERE cotizacion_id = ?', [$cotId]);

        foreach ($r['paquetes'] as $p) {
            $c = $p['conceptos'];
            $meta   = $porPaquete[$p['desc']] ?? null;
            $plId   = $meta['plantilla_id'] ?? $plantillaId;
            $omidas = $meta['omitidas'] ?? $omitidas;

            // Siempre por NOMBRE, nunca por posición.
            Db::ejecutar(
                'INSERT INTO cot_resultados
                    (cotizacion_id, cve_paquete, paquete, prima_tecnica, prima_neta, derechos, iva,
                     descuento, total_pagar, num_pagos, conceptos_json, plantilla_id)
                 VALUES (:co,:cv,:pa,:pt,:pn,:de,:iv,:ds,:tp,:np,:js,:pl)',
                [
                    ':co' => $cotId, ':cv' => $p['cve'], ':pa' => $p['desc'],
                    ':pt' => $c['PRIMA_TECNICA']   ?? null,
                    ':pn' => $c['PRIMA_NETA']      ?? null,
                    ':de' => $c['DERECHOS_POLIZA'] ?? null,
                    ':iv' => $c['IVA']             ?? null,
                    ':ds' => $c['DESCUENTO']       ?? null,
                    ':tp' => $c['TOTAL_PAGAR']     ?? null,
                    ':np' => isset($c['NUM_PAGOS']) ? (int) $c['NUM_PAGOS'] : null,
                    ':js' => json_encode($c, JSON_UNESCAPED_UNICODE),
                    ':pl' => $plId,
                ]
            );
            $resId = Db::ultimoId();

            foreach ($p['coberturas'] as $i => $cb) {
                Db::ejecutar(
                    'INSERT INTO cot_resultado_coberturas (resultado_id, cve_cobertura, nombre, suma_asegurada, deducible, orden)
                     VALUES (?,?,?,?,?,?)',
                    [$resId, $cb['cve'], $cb['nombre'], $cb['suma'], $cb['ded'], $i]
                );
            }

            foreach ($omidas as $i => $o) {
                Db::ejecutar(
                    'INSERT INTO cot_resultado_omitidas (resultado_id, cve_cobertura, nombre, motivo, orden) VALUES (?,?,?,?,?)',
                    [$resId, $o['cve'], $o['nombre'], $o['motivo'], $i]
                );
            }
        }

        return '';
    }

    /**
     * Tope de lo que se guarda de cada XML.
     *
     * La respuesta de impresión trae el PDF en base64 y pasa de un megabyte:
     * guardarla entera inflaría la base sin aportar nada, porque el PDF ya se
     * guarda como archivo. 256 KB alcanzan de sobra para cualquier cotización.
     */
    public const TOPE_EVIDENCIA = 262144;

    public static function registrarLlamada(array $r, ?int $cotId, string $detalle): void
    {
        Db::ejecutar(
            'INSERT INTO sys_llamadas (cotizacion_id, servicio, detalle, estado, http, ms, bytes, error_clave, error_origen, error_desc, xml_entrada, xml_salida)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $cotId, $r['servicio'] ?? '?', $detalle, $r['estado'],
                $r['http'] ?? 0, $r['ms'] ?? 0, $r['bytes'] ?? 0,
                $r['error']['clave'] ?? null, $r['error']['origen'] ?? null, $r['error']['descripcion'] ?? null,
                self::recortar((string) ($r['xml_entrada'] ?? '')),
                self::recortar((string) ($r['xml_salida']  ?? '')),
            ]
        );
    }

    private static function recortar(string $xml): string
    {
        if (strlen($xml) <= self::TOPE_EVIDENCIA) {
            return $xml;
        }
        return substr($xml, 0, self::TOPE_EVIDENCIA)
             . "\n<!-- recortado: el original tiene " . strlen($xml) . " caracteres -->";
    }

    /** Bitácora de llamadas de una cotización, con el XML de ida y vuelta. */
    public static function llamadas(int $cotId): array
    {
        return Db::todos('SELECT * FROM sys_llamadas WHERE cotizacion_id = ? ORDER BY id', [$cotId]);
    }

    /** Coberturas opcionales que se pidieron en esta cotización. */
    public static function opcionales(int $cotId): array
    {
        return Db::todos(
            'SELECT o.cve_cobertura, o.suma_asegurada,
                    COALESCE((SELECT c.nombre FROM cat_coberturas c
                               WHERE c.cve_cobertura = o.cve_cobertura LIMIT 1), "") AS nombre
               FROM cot_opcionales o WHERE o.cotizacion_id = ? ORDER BY o.id',
            [$cotId]
        );
    }

    public static function obtener(int $id): ?array
    {
        return Db::uno('SELECT * FROM cot_cotizaciones WHERE id = ?', [$id]);
    }

    /** Resultados ordenados de más barato a más caro. */
    public static function resultados(int $cotId): array
    {
        $res = Db::todos(
            'SELECT * FROM cot_resultados WHERE cotizacion_id = ? ORDER BY total_pagar ASC',
            [$cotId]
        );
        foreach ($res as &$r) {
            $r['coberturas'] = Db::todos(
                'SELECT * FROM cot_resultado_coberturas WHERE resultado_id = ? ORDER BY orden',
                [$r['id']]
            );
            // Nunca en silencio (docs/02.12-bug-amparada.md): coberturas de la
            // plantilla que no se transmitieron para este paquete puntual —
            // la pantalla las muestra igual que "no incluida".
            $r['omitidas'] = Db::todos(
                'SELECT * FROM cot_resultado_omitidas WHERE resultado_id = ? ORDER BY orden',
                [$r['id']]
            );
        }
        return $res;
    }

    public static function historial(int $limite = 50): array
    {
        return Db::todos('SELECT * FROM v_cotizaciones ORDER BY id DESC LIMIT ?', [$limite]);
    }

    public static function vencida(?array $cot): bool
    {
        if ($cot === null || empty($cot['vence_en'])) {
            return false;
        }
        return $cot['vence_en'] < date('Y-m-d');
    }
}
