<?php
declare(strict_types=1);

/**
 * QualitasServicio — guarda las cotizaciones de Qualitas en las tablas
 * comunes (cot_cotizaciones, cot_resultados, cot_resultado_coberturas) con
 * aseguradora='QUALITAS' (ADR-010, puntos 5, 7, 8 y 9).
 *
 * Es el equivalente de CotizacionServicio para Qualitas, sin tocarlo: GNP
 * sigue guardando por su propio camino.
 *
 * Mientras no haya catálogo de vehículos (Etapa 3, falta cUsuario/cTarifa),
 * la captura trae la clave AMIS y el modelo escritos a mano.
 */
final class QualitasServicio
{
    /**
     * Cotiza y guarda. Por omisión sólo contado (C).
     *
     * @param array{
     *   clave_vehiculo:string, modelo:int|string, conductor_cp:string, estado:string,
     *   uso?:string, servicio?:string, porcentaje_descuento:int|string,
     *   paquetes:list<int>, deducibles?:array<int,string>,
     *   tipo_carga?:string, descripcion_carga?:string
     * } $captura
     * @return array{ok:bool, cotizacion_id?:int, mensaje:string}
     */
    public static function cotizar(array $captura, ?int $usuarioId, ?AseguradoraQualitas $modulo = null): array
    {
        $pdo = Db::get();

        // Lo que se puede revisar sin llamar: si falla, no se guarda ni se envía nada.
        $amis   = trim((string) ($captura['clave_vehiculo'] ?? ''));
        $modelo = trim((string) ($captura['modelo'] ?? ''));
        $cp     = trim((string) ($captura['conductor_cp'] ?? ''));
        $estado = trim((string) ($captura['estado'] ?? ''));
        $faltan = [];
        if (!preg_match('/^\d{1,5}$/', $amis)) {
            $faltan[] = 'la clave AMIS (hasta 5 dígitos)';
        }
        if (!preg_match('/^\d{4}$/', $modelo)) {
            $faltan[] = 'el modelo (año de 4 dígitos)';
        }
        if (!preg_match('/^\d{5}$/', $cp)) {
            $faltan[] = 'el código postal (5 dígitos)';
        }
        if (!preg_match('/^\d{1,2}$/', $estado) || (int) $estado < 1 || (int) $estado > 32) {
            $faltan[] = 'el estado';
        }
        if (array_filter((array) ($captura['paquetes'] ?? [])) === []) {
            $faltan[] = 'al menos un paquete';
        }
        if ($faltan !== []) {
            return ['ok' => false, 'mensaje' => 'Falta ' . implode('; falta ', $faltan) . '.'];
        }
        $msg = RangoDescuento::validar($pdo, 'QUALITAS', RangoDescuento::TODOS, $captura['porcentaje_descuento'] ?? '');
        if ($msg !== null) {
            return ['ok' => false, 'mensaje' => $msg];
        }

        $modulo ??= new AseguradoraQualitas(null, $pdo);
        $rango = RangoDescuento::resolver($pdo, 'QUALITAS', RangoDescuento::TODOS);

        $datos = array_filter([
            'estado'               => $estado,
            'uso'                  => (string) ($captura['uso'] ?? '1'),
            'servicio'             => (string) ($captura['servicio'] ?? '1'),
            'forma_pago'           => 'C',
            'porcentaje_descuento' => (int) $captura['porcentaje_descuento'],
            'tipo_carga'           => strtoupper(trim((string) ($captura['tipo_carga'] ?? ''))),
            'descripcion_carga'    => trim((string) ($captura['descripcion_carga'] ?? '')),
        ], static fn ($v): bool => $v !== '');
        $deducibles = array_filter(array_map('strval', (array) ($captura['deducibles'] ?? [])), static fn ($v): bool => $v !== '');

        $hoy = new DateTimeImmutable('today');
        $pdo->prepare(
            "INSERT INTO cot_cotizaciones
                (estado, usuario_id, tipo_vehiculo, armadora, carroceria, version, modelo, descripcion_veh, procedencia,
                 conductor_cp, contratante_cp, periodicidad, vigencia_inicio, vigencia_fin, vence_en,
                 aseguradora, clave_vehiculo, datos_aseguradora_json)
             VALUES ('BORRADOR', ?, '', '', '', '', ?, ?, '', ?, ?, 'C', ?, ?, ?, 'QUALITAS', ?, ?)"
        )->execute([
            $usuarioId, (int) $modelo, "Clave AMIS {$amis} · {$modelo}",
            $cp, $cp,
            $hoy->format('Y-m-d'), $hoy->modify('+1 year')->format('Y-m-d'),
            $hoy->modify('+' . AseguradoraQualitas::VIGENCIA_DIAS . ' days')->format('Y-m-d'),
            $amis,
            json_encode($datos + [
                'deducibles'     => $deducibles,
                'paquetes'       => array_values(array_map('intval', (array) $captura['paquetes'])),
                // Para que el historial explique el precio: qué rango regía al cotizar.
                'rango_descuento' => $rango,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $cotId = (int) $pdo->lastInsertId();

        $r = $modulo->cotizar([
            'cotizacion_id'     => $cotId,
            'clave_vehiculo'    => $amis,
            'modelo'            => $modelo,
            'conductor_cp'      => $cp,
            'datos_aseguradora' => $datos,
            'paquetes'          => (array) $captura['paquetes'],
            'deducibles'        => $deducibles,
        ]);

        self::guardarResultados($cotId, $r['paquetes']);

        $avisos = array_column($r['errores'] ?? [], 'descripcion');
        if ($r['estado'] !== CotizadorAseguradora::OK) {
            $mensaje = (string) ($r['error']['descripcion'] ?? 'Qualitas no cotizó.');
            $pdo->prepare("UPDATE cot_cotizaciones SET estado = 'ERROR', error_desc = ? WHERE id = ?")->execute([$mensaje, $cotId]);
            return ['ok' => false, 'cotizacion_id' => $cotId, 'mensaje' => $mensaje];
        }

        $folio = (string) ($r['paquetes'][0]->conceptos['no_cotizacion'] ?? '');
        $pdo->prepare("UPDATE cot_cotizaciones SET estado = 'COTIZADA', folio = ?, error_desc = ? WHERE id = ?")
            ->execute([$folio !== '' ? $folio : null, $avisos !== [] ? implode(' · ', $avisos) : null, $cotId]);

        return ['ok' => true, 'cotizacion_id' => $cotId, 'mensaje' => implode(' ', $avisos)];
    }

    /** Captura de la pantalla de Qualitas a partir del POST (lo que antes armaba la ruta). */
    public static function capturaDesdePost(array $post): array
    {
        $uso = (string) ($post['uso'] ?? '1');
        return [
            'clave_vehiculo'       => trim((string) ($post['clave_vehiculo'] ?? '')),
            'modelo'               => trim((string) ($post['modelo'] ?? '')),
            'conductor_cp'         => trim((string) ($post['conductor_cp'] ?? '')),
            'estado'               => (string) ($post['estado'] ?? ''),
            'uso'                  => isset(AseguradoraQualitas::USOS[$uso]) ? $uso : '1',
            'servicio'             => '1',
            'porcentaje_descuento' => trim((string) ($post['porcentaje_descuento'] ?? '')),
            'paquetes'             => array_map('intval', (array) ($post['paquetes'] ?? [])),
            'deducibles'           => array_map('strval', (array) ($post['deducibles'] ?? [])),
            'tipo_carga'           => $uso === '6' ? (string) ($post['tipo_carga'] ?? '') : '',
            'descripcion_carga'    => $uso === '6' ? (string) ($post['descripcion_carga'] ?? '') : '',
        ];
    }

    /**
     * "Cotizar" desde el formulario, con token de un solo uso (SolicitudUnica).
     * Sólo el primer envío de cada formulario llama a Qualitas.
     *
     * @return array{accion:'REDIRIGIR'|'FORMULARIO'|'PROCESANDO', cotizacion_id?:int, aviso?:string, error?:string}
     */
    public static function cotizarDesdeFormulario(array $post, ?int $usuarioId, ?AseguradoraQualitas $modulo = null): array
    {
        $pdo = Db::get();
        $token = (string) ($post['solicitud'] ?? '');
        $t = SolicitudUnica::tomar($pdo, $token, $usuarioId, 'QUALITAS', 'cotizar');

        switch ($t['resultado']) {
            case SolicitudUnica::OK:
                try {
                    $r = self::cotizar(self::capturaDesdePost($post), $usuarioId, $modulo);
                } catch (Throwable $e) {
                    SolicitudUnica::cerrar($pdo, $token, SolicitudUnica::FALLIDA, null);
                    throw $e;
                }
                if (!$r['ok'] && !isset($r['cotizacion_id'])) {
                    // Error de captura: no salió nada. El formulario vuelve con un token nuevo.
                    SolicitudUnica::cerrar($pdo, $token, SolicitudUnica::RECHAZADA, null);
                    return ['accion' => 'FORMULARIO', 'error' => $r['mensaje']];
                }
                SolicitudUnica::cerrar($pdo, $token, $r['ok'] ? SolicitudUnica::TERMINADA : SolicitudUnica::FALLIDA, (int) $r['cotizacion_id']);
                return ['accion' => 'REDIRIGIR', 'cotizacion_id' => (int) $r['cotizacion_id'], 'aviso' => $r['mensaje']];

            case SolicitudUnica::TERMINADA:
                return ['accion' => 'REDIRIGIR', 'cotizacion_id' => (int) $t['cotizacion_id'], 'aviso' => 'Esta cotización ya se había enviado.'];

            case SolicitudUnica::FALLIDA:
                $msg = 'Esta cotización ya se había enviado y no se completó. Para intentarlo de nuevo, abre el formulario otra vez.';
                return $t['cotizacion_id'] !== null
                    ? ['accion' => 'REDIRIGIR', 'cotizacion_id' => (int) $t['cotizacion_id'], 'aviso' => $msg]
                    : ['accion' => 'FORMULARIO', 'error' => $msg];

            case SolicitudUnica::EN_CURSO:
                return ['accion' => 'PROCESANDO'];

            case SolicitudUnica::RECHAZADA:
                return ['accion' => 'FORMULARIO', 'error' => 'Este formulario ya se había enviado y los datos no pasaron la revisión. Revísalos y vuelve a enviar.'];

            case SolicitudUnica::INTERRUMPIDA:
                return ['accion' => 'FORMULARIO', 'error' => 'La cotización anterior de este formulario se interrumpió. Revisa el historial antes de volver a cotizar.'];

            case SolicitudUnica::VENCIDO:
                return ['accion' => 'FORMULARIO', 'error' => 'El formulario venció (vale ' . (SolicitudUnica::VIGENCIA_MINUTOS / 60) . ' horas). Revisa los datos y vuelve a enviar.'];

            default:
                return ['accion' => 'FORMULARIO', 'error' => 'El formulario no es válido para tu sesión. Revisa los datos y vuelve a enviar.'];
        }
    }

    /**
     * "Ver otras formas de pago": cotiza el MISMO paquete en semestral,
     * trimestral o mensual, sólo cuando el usuario lo pide. Cada forma es su
     * propia llamada, registrada. El resultado se agrega en
     * conceptos_json['formas_pago'][forma]; el precio de contado no cambia.
     *
     * @return array{ok:bool, mensaje:string}
     */
    public static function otraFormaDePago(int $resultadoId, string $forma, ?AseguradoraQualitas $modulo = null): array
    {
        $pdo = Db::get();
        if (!in_array($forma, ['S', 'T', 'M'], true)) {
            return ['ok' => false, 'mensaje' => 'Forma de pago desconocida.'];
        }

        $st = $pdo->prepare(
            "SELECT r.id, r.cotizacion_id, r.conceptos_json, c.clave_vehiculo, c.modelo, c.conductor_cp, c.datos_aseguradora_json, c.vence_en
               FROM cot_resultados r JOIN cot_cotizaciones c ON c.id = r.cotizacion_id
              WHERE r.id = ? AND r.aseguradora = 'QUALITAS'"
        );
        $st->execute([$resultadoId]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        if ($fila === false) {
            return ['ok' => false, 'mensaje' => 'Resultado de Qualitas no encontrado.'];
        }
        if ($fila['vence_en'] !== null && $fila['vence_en'] < date('Y-m-d')) {
            return ['ok' => false, 'mensaje' => 'La cotización ya venció: hay que cotizar de nuevo.'];
        }

        $conceptos = json_decode((string) $fila['conceptos_json'], true) ?: [];
        if (isset($conceptos['formas_pago'][$forma])) {
            return ['ok' => true, 'mensaje' => 'Esa forma de pago ya estaba cotizada.'];
        }
        $datos = json_decode((string) $fila['datos_aseguradora_json'], true) ?: [];
        $deducibles = (array) ($datos['deducibles'] ?? []);
        unset($datos['deducibles'], $datos['paquetes'], $datos['rango_descuento']);
        $datos['forma_pago'] = $forma;

        $modulo ??= new AseguradoraQualitas(null, $pdo);
        $r = $modulo->cotizar([
            'cotizacion_id'     => (int) $fila['cotizacion_id'],
            'clave_vehiculo'    => (string) $fila['clave_vehiculo'],
            'modelo'            => (string) $fila['modelo'],
            'conductor_cp'      => (string) $fila['conductor_cp'],
            'datos_aseguradora' => $datos,
            'paquetes'          => [(int) ($conceptos['paquete_id'] ?? 0)],
            'deducibles'        => $deducibles,
        ]);

        $res = $r['paquetes'][0] ?? null;
        if ($r['estado'] !== CotizadorAseguradora::OK || $res === null) {
            return ['ok' => false, 'mensaje' => (string) ($r['error']['descripcion'] ?? 'Qualitas no cotizó esa forma de pago.')];
        }

        $conceptos['formas_pago'][$forma] = [
            'total_pagar'         => $res->totalPagar,
            'prima_neta'          => $res->primaNeta,
            'derechos'            => $res->derechos,
            'iva'                 => $res->iva,
            'num_pagos'           => $res->numPagos,
            'recargo'             => $res->conceptos['recargo'] ?? null,
            'recibos'             => $res->conceptos['recibos'] ?? [],
            'comision_porcentaje' => $res->conceptos['comision_porcentaje'] ?? null,
            'comision_importe'    => $res->conceptos['comision_importe'] ?? null,
            'no_cotizacion'       => $res->conceptos['no_cotizacion'] ?? '',
            'llamada_id'          => $res->conceptos['llamada_id'] ?? null,
            'cotizado_en'         => date('Y-m-d H:i:s'),
        ];
        $pdo->prepare('UPDATE cot_resultados SET conceptos_json = ? WHERE id = ?')
            ->execute([json_encode($conceptos, JSON_UNESCAPED_UNICODE), $resultadoId]);

        return ['ok' => true, 'mensaje' => AseguradoraQualitas::FORMAS_PAGO[$forma] . ' cotizado.'];
    }

    /**
     * Todo lo que necesita la pantalla de resultado. Lo usan la ruta
     * qualitas/resultado y las pruebas, para que no puedan diferir.
     *
     * Ojo: la clave es `datosAseg`, no `datos`. vista() recibe su arreglo en
     * una variable llamada $datos y hace extract(EXTR_SKIP): una clave `datos`
     * se saltaba y la vista veía el contexto entero (así se perdían el
     * descuento y el estado en pantalla).
     *
     * @return array{cot:array, datosAseg:array, resultados:array, vencida:bool}|null
     */
    public static function contextoResultado(int $cotId, ?int $usuarioId = null): ?array
    {
        $cot = Db::uno("SELECT * FROM cot_cotizaciones WHERE id = ? AND aseguradora = 'QUALITAS'", [$cotId]);
        if ($cot === null) {
            return null;
        }
        $vencida = !empty($cot['vence_en']) && $cot['vence_en'] < date('Y-m-d');
        $resultados = self::resultados($cotId);

        // Cada botón "Ver otras formas de pago" que se muestra lleva su propio
        // token de un solo uso, ligado a su resultado (SolicitudUnica).
        foreach ($resultados as &$r) {
            $faltan = array_diff(['S', 'T', 'M'], array_keys((array) ($r['conceptos']['formas_pago'] ?? [])));
            $r['solicitud_formas'] = ($faltan !== [] && !$vencida)
                ? SolicitudUnica::emitir(Db::get(), $usuarioId, 'QUALITAS', self::accionFormasPago((int) $r['id']))
                : '';
        }
        unset($r);

        return [
            'cot'        => $cot,
            'datosAseg'  => json_decode((string) $cot['datos_aseguradora_json'], true) ?: [],
            'resultados' => $resultados,
            'vencida'    => $vencida,
        ];
    }

    /** La acción del token de "Ver otras formas de pago" incluye el resultado: un token no sirve para otro. */
    public static function accionFormasPago(int $resultadoId): string
    {
        return 'formas_pago:' . $resultadoId;
    }

    /**
     * "Ver otras formas de pago" desde el botón, con token de un solo uso.
     * Misma regla que "Cotizar": sólo el primer envío de cada botón llama; si
     * falló, el reenvío no reintenta: el usuario recarga el resultado (botón
     * nuevo) y pulsa otra vez, y entonces sólo se cotizan las formas que faltan.
     *
     * @return array{cotizacion_id:int, aviso:string}|null  null si el resultado no existe
     */
    public static function formasPagoDesdeFormulario(array $post, ?int $usuarioId, ?AseguradoraQualitas $modulo = null): ?array
    {
        $pdo = Db::get();
        $resultadoId = (int) ($post['resultado_id'] ?? 0);
        $cotId = Db::valor("SELECT cotizacion_id FROM cot_resultados WHERE id = ? AND aseguradora = 'QUALITAS'", [$resultadoId]);
        if ($cotId === null) {
            return null;
        }
        $cotId = (int) $cotId;
        $token = (string) ($post['solicitud'] ?? '');
        $t = SolicitudUnica::tomar($pdo, $token, $usuarioId, 'QUALITAS', self::accionFormasPago($resultadoId));

        switch ($t['resultado']) {
            case SolicitudUnica::OK:
                $mensajes = [];
                $todoBien = true;
                try {
                    foreach (['S', 'T', 'M'] as $forma) {
                        $f = self::otraFormaDePago($resultadoId, $forma, $modulo);
                        $todoBien = $todoBien && $f['ok'];
                        $mensajes[] = $f['ok'] ? $f['mensaje'] : AseguradoraQualitas::FORMAS_PAGO[$forma] . ': ' . $f['mensaje'];
                        if (!$f['ok'] && str_contains($f['mensaje'], 'venció')) {
                            break;
                        }
                    }
                } catch (Throwable $e) {
                    SolicitudUnica::cerrar($pdo, $token, SolicitudUnica::FALLIDA, $cotId);
                    throw $e;
                }
                SolicitudUnica::cerrar($pdo, $token, $todoBien ? SolicitudUnica::TERMINADA : SolicitudUnica::FALLIDA, $cotId);
                return ['cotizacion_id' => $cotId, 'aviso' => implode(' ', $mensajes)];

            case SolicitudUnica::TERMINADA:
                return ['cotizacion_id' => $cotId, 'aviso' => 'Las otras formas de pago ya se habían pedido.'];

            case SolicitudUnica::FALLIDA:
                return ['cotizacion_id' => $cotId, 'aviso' => 'Este botón ya se había usado y alguna forma de pago no se completó. Para intentarlo de nuevo, recarga la página y pulsa otra vez.'];

            case SolicitudUnica::EN_CURSO:
                return ['cotizacion_id' => $cotId, 'aviso' => 'Las otras formas de pago se están cotizando. Recarga la página en unos segundos.'];

            default:
                return ['cotizacion_id' => $cotId, 'aviso' => 'Este botón ya no es válido. Recarga la página y pulsa otra vez.'];
        }
    }

    /** Resultados de una cotización de Qualitas, de más barato a más caro, con conceptos y coberturas. */
    public static function resultados(int $cotId): array
    {
        $filas = Db::todos(
            "SELECT * FROM cot_resultados WHERE cotizacion_id = ? AND aseguradora = 'QUALITAS' ORDER BY total_pagar IS NULL, total_pagar",
            [$cotId]
        );
        foreach ($filas as &$f) {
            $f['conceptos']  = json_decode((string) $f['conceptos_json'], true) ?: [];
            $f['coberturas'] = Db::todos('SELECT * FROM cot_resultado_coberturas WHERE resultado_id = ? ORDER BY orden', [(int) $f['id']]);
        }
        unset($f);
        return $filas;
    }

    /** @param Resultado[] $resultados */
    public static function guardarResultados(int $cotId, array $resultados): void
    {
        $pdo = Db::get();
        $res = $pdo->prepare(
            "INSERT INTO cot_resultados
                (cotizacion_id, cve_paquete, paquete, prima_tecnica, prima_neta, derechos, iva, descuento, total_pagar, num_pagos, conceptos_json, aseguradora)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, 'QUALITAS')"
        );
        $cob = $pdo->prepare(
            'INSERT INTO cot_resultado_coberturas (resultado_id, cve_cobertura, nombre, suma_asegurada, deducible, orden) VALUES (?,?,?,?,?,?)'
        );
        foreach ($resultados as $r) {
            $res->execute([
                $cotId, $r->cvePaquete, $r->paquete, $r->primaTecnica, $r->primaNeta, $r->derechos, $r->iva,
                $r->descuento, $r->totalPagar, $r->numPagos, json_encode($r->conceptos, JSON_UNESCAPED_UNICODE),
            ]);
            $rid = (int) $pdo->lastInsertId();
            foreach ($r->coberturas as $i => $c) {
                $cob->execute([$rid, $c->cveCobertura, $c->nombre, $c->sumaAsegurada, $c->deducible, $i]);
            }
        }
    }
}
