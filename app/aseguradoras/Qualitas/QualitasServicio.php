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
