<?php
declare(strict_types=1);

/**
 * AseguradoraQualitas — el módulo de Qualitas visto desde la plataforma
 * (ADR-010, punto 3). Por dentro usa QualitasClient (SOAP, candado doble) y
 * QualitasXml; por fuera, los cuatro botones del contrato.
 *
 * Reglas que aplica (docs/aseguradoras/qualitas/00-estado.md):
 *
 * - Un <Movimiento> por paquete: N paquetes son N llamadas y N Resultado.
 * - Precio = PrimaTotal, que ya incluye pronto pago, derechos e IVA
 *   [CONFIRMADO, sys_llamadas.id 123].
 * - El descuento lo captura el usuario y se valida contra RangoDescuento
 *   ANTES de mandar nada. Mientras no se sepa qué dato del catálogo dice el
 *   tipo de vehículo, se usa la fila TODOS.
 * - Pronto pago: siempre la consideración 05 con QUALITAS_PRONTO_PAGO_DIAS.
 *   Llega en Recargo, en negativo [CONFIRMADO, id 123].
 * - Comisión: Primas/Comision es el porcentaje (11 en autos) y
 *   Recibos/Comision el importe (confirmado por negocio, Albert, 2026-09-28).
 *   Se pasan los dos tal como llegan; lo que falte queda null ("no
 *   disponible"). No se calcula ni se deriva nada.
 * - Por omisión se cotiza sólo contado (C). Semestral, trimestral y mensual
 *   se cotizan cuando el usuario lo pide, cada una como su propia llamada.
 */
final class AseguradoraQualitas implements CotizadorAseguradora
{
    public const FORMAS_PAGO = ['C' => 'Contado', 'S' => 'Semestral', 'T' => 'Trimestral', 'M' => 'Mensual'];

    /**
     * Anexo 1 del manual: mismo orden que INEGI. En el PDF la tabla sale
     * desalineada (Durango sin número); se usa el orden INEGI. Sólo el 9
     * (Ciudad de México) se ha visto responder (sys_llamadas.id 123).
     */
    public const ESTADOS = [
        1 => 'Aguascalientes', 2 => 'Baja California', 3 => 'Baja California Sur', 4 => 'Campeche',
        5 => 'Coahuila', 6 => 'Colima', 7 => 'Chiapas', 8 => 'Chihuahua', 9 => 'Ciudad de México',
        10 => 'Durango', 11 => 'Guanajuato', 12 => 'Guerrero', 13 => 'Hidalgo', 14 => 'Jalisco',
        15 => 'Estado de México', 16 => 'Michoacán', 17 => 'Morelos', 18 => 'Nayarit', 19 => 'Nuevo León',
        20 => 'Oaxaca', 21 => 'Puebla', 22 => 'Querétaro', 23 => 'Quintana Roo', 24 => 'San Luis Potosí',
        25 => 'Sinaloa', 26 => 'Sonora', 27 => 'Tabasco', 28 => 'Tamaulipas', 29 => 'Tlaxcala',
        30 => 'Veracruz', 31 => 'Yucatán', 32 => 'Zacatecas',
    ];

    /** Uso (Anexo 3) que el negocio 08902 cotiza: 01 normal; 06 carga, como en el ejemplo de la NP300. */
    public const USOS = ['1' => 'Normal', '6' => 'Carga'];

    /** Vigencia de la cotización: 7 días según los PDF de ejemplo [PENDIENTE: no viene en la respuesta]. */
    public const VIGENCIA_DIAS = 7;

    private readonly QualitasClient $cliente;
    private readonly PDO $pdo;

    public function __construct(?QualitasClient $cliente = null, ?PDO $pdo = null)
    {
        $this->cliente = $cliente ?? QualitasClient::desdeEnv();
        $this->pdo     = $pdo ?? Db::get();
    }

    public function clave(): string
    {
        return 'QUALITAS';
    }

    /**
     * @param array{
     *   cotizacion_id?:int|null,
     *   clave_vehiculo:string, modelo:int|string, conductor_cp:string, vigencia_inicio?:string,
     *   datos_aseguradora:array<string,mixed>,
     *   paquetes:list<int>,
     *   deducibles?:array<int|string,string|int>
     * } $solicitud  paquetes = ids de cat_qua_paquetes; deducibles por número de cobertura (1 DM, 3 RT)
     */
    public function cotizar(array $solicitud): array
    {
        $d = $solicitud['datos_aseguradora'] ?? [];

        // Descuento: se valida aquí, en el servidor, aunque la pantalla ya lo haya hecho.
        $msg = RangoDescuento::validar($this->pdo, 'QUALITAS', RangoDescuento::TODOS, $d['porcentaje_descuento'] ?? '');
        if ($msg !== null) {
            return $this->fallo(self::E_DATOS, $msg, 'rango-descuento');
        }
        $d['porcentaje_descuento'] = (int) $d['porcentaje_descuento'];
        $d['forma_pago'] = (string) ($d['forma_pago'] ?? 'C');
        if (!isset(self::FORMAS_PAGO[$d['forma_pago']])) {
            return $this->fallo(self::E_DATOS, "Forma de pago desconocida: \"{$d['forma_pago']}\".", 'captura');
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($solicitud['paquetes'] ?? []))));
        if ($ids === []) {
            return $this->fallo(self::E_DATOS, 'Elige al menos un paquete.', 'captura');
        }

        $resultados = [];
        $errores    = [];
        foreach ($ids as $id) {
            $paquete = $this->paquete($id);
            if ($paquete === null) {
                $errores[] = ['paquete' => "#{$id}", 'estado' => self::E_DATOS, 'descripcion' => 'Paquete inexistente.'];
                continue;
            }
            if ((int) $paquete['activo'] !== 1 || $paquete['codigo'] === '') {
                // "Todavía no": no se adivina un código que Qualitas no ha confirmado.
                $errores[] = ['paquete' => $paquete['nombre'], 'estado' => self::E_DATOS,
                              'descripcion' => "{$paquete['nombre']}: todavía no se puede cotizar. {$paquete['nota']}"];
                continue;
            }

            try {
                $coberturas = $this->coberturas((int) $paquete['id'], (array) ($solicitud['deducibles'] ?? []));
                $xml = QualitasXml::cotizacion([
                    'clave_vehiculo'    => (string) $solicitud['clave_vehiculo'],
                    'modelo'            => $solicitud['modelo'],
                    'conductor_cp'      => (string) $solicitud['conductor_cp'],
                    'vigencia_inicio'   => (string) ($solicitud['vigencia_inicio'] ?? ''),
                    'datos_aseguradora' => $d,
                    'paquete'           => ['clave' => $paquete['codigo'], 'coberturas' => $coberturas],
                ], $this->cliente->configXml());
            } catch (InvalidArgumentException $e) {
                // Error de captura: no sale nada.
                return $this->fallo(self::E_DATOS, $e->getMessage(), 'captura');
            }

            $r = $this->cliente->cotizar(
                $xml,
                isset($solicitud['cotizacion_id']) ? (int) $solicitud['cotizacion_id'] : null,
                "QUALITAS cotizar · {$paquete['nombre']} · {$d['forma_pago']} · descuento {$d['porcentaje_descuento']}%"
            );

            $mov = $r['movimientos'][0] ?? null;
            if ($r['estado'] !== self::OK || $mov === null) {
                $errores[] = ['paquete' => $paquete['nombre'], 'estado' => $r['estado'],
                              'descripcion' => QualitasClient::explicar($r), 'llamada_id' => $r['llamada_id'] ?? null];
                continue;
            }
            $resultados[] = $this->resultado($paquete, $mov, $d, (int) ($r['llamada_id'] ?? 0));
        }

        if ($resultados === []) {
            $primero = $errores[0] ?? ['estado' => self::E_SISTEMA, 'descripcion' => 'Sin resultados.'];
            return [
                'estado'   => $primero['estado'],
                'paquetes' => [],
                'error'    => ['descripcion' => implode(' · ', array_column($errores, 'descripcion')), 'origen' => 'qualitas'],
                'errores'  => $errores,
            ];
        }

        return ['estado' => self::OK, 'paquetes' => $resultados, 'error' => null, 'errores' => $errores];
    }

    /**
     * PDF propio de la cotización (Qualitas no imprime cotizaciones: su
     * servicio de impresión pide número de póliza).
     *
     * @param array{folio?:string, creada_en?:string, vence_en?:string, clave_vehiculo:string, modelo:int|string, conductor_cp:string, datos_aseguradora:array} $cotizacion
     * @param array{paquete:string, total_pagar:?float, prima_neta:?float, derechos:?float, iva:?float, conceptos:array, coberturas:list<array{nombre:string,suma_asegurada:string,deducible:string}>} $paquete
     */
    public function imprimir(array $cotizacion, array $paquete): array
    {
        // Documento para el cliente: la comisión NUNCA aparece aquí (Albert, 2026-09-28).
        $c = $paquete['conceptos'] ?? [];
        $d = $cotizacion['datos_aseguradora'] ?? [];
        $num = static fn ($n): ?float => $n === null || $n === '' ? null : (float) $n;

        $pdf = new PdfBasico(612, 792); // carta, vertical
        $pdf->agregarPagina();
        $x = 50;
        $y = 60;

        $pdf->fuente(true, 16);
        $pdf->texto($x, $y, 'Cotización de automóvil · Qualitas');
        $y += 22;
        $pdf->fuente(false, 9);
        $pdf->colorTexto(90, 90, 90);
        $pdf->texto($x, $y, 'Documento generado por el cotizador de Equinox, no por Qualitas. Cotizar no genera póliza.');
        $pdf->colorTexto(0, 0, 0);
        $y += 26;

        $pdf->fuente(false, 10);
        foreach ([
            'Número de cotización Qualitas' => (string) ($c['no_cotizacion'] ?? $cotizacion['folio'] ?? ''),
            'Fecha'                         => (string) ($cotizacion['creada_en'] ?? date('Y-m-d H:i')),
            'Vigencia de la cotización'     => self::VIGENCIA_DIAS . ' días' . (!empty($cotizacion['vence_en']) ? ' (hasta ' . $cotizacion['vence_en'] . ')' : ''),
            // Del catálogo (marca, línea, versión) cuando el vehículo está ahí; si no, la clave AMIS.
            'Vehículo'                      => (string) ($cotizacion['descripcion_veh'] ?? '') !== ''
                ? (string) $cotizacion['descripcion_veh']
                : 'Clave AMIS ' . $cotizacion['clave_vehiculo'] . ' · modelo ' . $cotizacion['modelo'],
            'Código postal · estado'        => $cotizacion['conductor_cp'] . ' · ' . (self::ESTADOS[(int) ($d['estado'] ?? 0)] ?? 'no disponible'),
            'Paquete'                       => (string) $paquete['paquete'],
            'Forma de pago'                 => self::FORMAS_PAGO[(string) ($c['forma_pago'] ?? 'C')] ?? (string) ($c['forma_pago'] ?? ''),
            'Descuento aplicado'            => isset($d['porcentaje_descuento']) || isset($c['porcentaje_descuento'])
                ? (int) ($d['porcentaje_descuento'] ?? $c['porcentaje_descuento']) . '%'
                : 'no disponible',
        ] as $etq => $val) {
            $pdf->fuente(true, 10);
            $pdf->texto($x, $y, $etq);
            $pdf->fuente(false, 10);
            $pdf->texto($x + 190, $y, $val);
            $y += 15;
        }

        $y += 12;
        $pdf->fuente(true, 11);
        $pdf->texto($x, $y, 'Coberturas');
        $y += 16;
        $pdf->fuente(true, 9);
        $pdf->texto($x, $y, 'Cobertura');
        $pdf->texto($x + 300, $y, 'Suma asegurada');
        $pdf->texto($x + 430, $y, 'Deducible');
        $y += 4;
        $pdf->linea($x, $y, 562, $y, [180, 180, 180]);
        $y += 12;
        $pdf->fuente(false, 9);
        foreach ($paquete['coberturas'] ?? [] as $cb) {
            $pdf->texto($x, $y, (string) $cb['nombre']);
            $pdf->texto($x + 300, $y, (string) $cb['suma_asegurada']);
            $pdf->texto($x + 430, $y, (string) $cb['deducible']);
            $y += 13;
        }

        // Importes: mismos nombres, orden y formato que los PDF de Qualitas, y la
        // misma función que usa la pantalla de resultado.
        $y += 14;
        $pdf->fuente(true, 11);
        $pdf->texto($x, $y, 'Importes');
        $y += 16;
        foreach (self::importes(
            $num($paquete['prima_neta'] ?? null),
            $num($c['recargo'] ?? null),
            $num($paquete['derechos'] ?? null),
            $num($paquete['iva'] ?? null),
            $num($paquete['total_pagar'] ?? null)
        ) as [$etq, $val]) {
            $total = $etq === 'IMPORTE TOTAL';
            if ($total) {
                $pdf->linea($x, $y - 10, 562, $y - 10, [180, 180, 180]);
                $y += 2;
            }
            $pdf->fuente($total, $total ? 12 : 10);
            $pdf->texto($x, $y, $etq);
            $pdf->textoDerecha(562, $y, $val);
            $y += 15;
        }

        // Otras formas de pago, sólo si el usuario las pidió. Sin comisión.
        $formas = self::formasDePago($paquete);
        if (count($formas) > 1) {
            $y += 14;
            $pdf->fuente(true, 11);
            $pdf->texto($x, $y, 'Formas de pago');
            $y += 16;
            $pdf->fuente(true, 9);
            $pdf->texto($x, $y, 'Forma');
            $pdf->textoDerecha($x + 210, $y, 'Total');
            $pdf->textoDerecha($x + 310, $y, 'Primer pago');
            $pdf->textoDerecha($x + 420, $y, 'Pagos siguientes');
            $pdf->textoDerecha(562, $y, 'Pagos');
            $y += 4;
            $pdf->linea($x, $y, 562, $y, [180, 180, 180]);
            $y += 12;
            $pdf->fuente(false, 9);
            foreach ($formas as $fp) {
                $pdf->texto($x, $y, $fp['nombre']);
                $pdf->textoDerecha($x + 210, $y, self::monto($fp['total']));
                $pdf->textoDerecha($x + 310, $y, self::monto($fp['primer']));
                $pdf->textoDerecha($x + 420, $y, $fp['siguientes'] !== null ? self::monto($fp['siguientes']) : '—');
                $pdf->textoDerecha(562, $y, $fp['pagos'] !== null ? (string) $fp['pagos'] : '—');
                $y += 13;
            }
        }

        $y += 24;
        $pdf->fuente(false, 8);
        $pdf->colorTexto(90, 90, 90);
        foreach ($pdf->envolver(
            'Plazo de pago: ' . ($c['pronto_pago_dias'] ?? '') . ' días (descuento por pronto pago). '
            . 'En caso de modificación de cualquiera de los datos, se requiere una nueva cotización.',
            512
        ) as $l) {
            $pdf->texto($x, $y, $l);
            $y += 11;
        }

        $pdf->establecerPie('Cotizador Equinox · Qualitas', 'Cotizar no genera póliza');

        return ['estado' => self::OK, 'pdf' => $pdf->bytes(), 'referencia' => (string) ($c['no_cotizacion'] ?? ''), 'error' => null];
    }

    /**
     * MARCAS → listaMarcas · VEHICULOS → listaTarifas (filtrada por marca y
     * modelo). Hoy no hay cUsuario/cTarifa: responde AUTH con el motivo, sin
     * llamar.
     */
    public function catalogo(string $tipo, array $filtros = []): array
    {
        try {
            $r = match (strtoupper($tipo)) {
                'MARCAS'    => $this->cliente->listaMarcas(),
                'VEHICULOS' => $this->cliente->listaTarifas($filtros),
                default     => throw new InvalidArgumentException("Catálogo desconocido: \"{$tipo}\"."),
            };
        } catch (InvalidArgumentException $e) {
            return ['estado' => self::E_DATOS, 'datos' => [], 'error' => ['descripcion' => $e->getMessage(), 'origen' => 'captura']];
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'BLOQUEADO')) {
                // El candado paró la petición (p. ej. listaTarifas sin marca y modelo).
                return ['estado' => self::E_DATOS, 'datos' => [], 'error' => ['descripcion' => $e->getMessage(), 'origen' => 'candado']];
            }
            // No hubo llamada ni rechazo de Qualitas: falta configurar el acceso al catálogo.
            // AUTH porque se arregla en administración, no en la captura.
            return ['estado' => self::E_AUTH, 'datos' => [], 'error' => [
                'descripcion' => 'Catálogo sin credenciales configuradas. ' . $e->getMessage(),
                'origen'      => 'configuracion',
            ]];
        }
        return [
            'estado' => $r['estado'],
            'datos'  => $r['datos'] ?? [],
            'error'  => $r['estado'] !== self::OK ? ($r['error'] ?? null) : null,
        ];
    }

    /** Paquetes para la pantalla: los activos y los que todavía no (con su nota). */
    public function paquetes(): array
    {
        return $this->pdo->query('SELECT id, codigo, nombre, activo, nota FROM cat_qua_paquetes ORDER BY orden')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Deducibles que el usuario puede elegir, por número de cobertura. */
    public function deduciblesElegibles(): array
    {
        $r = [];
        foreach ($this->pdo->query("SELECT DISTINCT no_cobertura, nombre, deducible, deducibles_permitidos FROM cat_qua_coberturas WHERE deducibles_permitidos <> '' ORDER BY no_cobertura") as $f) {
            $r[(int) $f['no_cobertura']] = [
                'nombre'      => (string) $f['nombre'],
                'por_omision' => (string) $f['deducible'],
                'opciones'    => explode(',', (string) $f['deducibles_permitidos']),
            ];
        }
        return $r;
    }

    // ─────────────────────────────────────────────────────────────────────

    private function paquete(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM cat_qua_paquetes WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Coberturas que se mandan (enviar = 1) con el deducible elegido, si es de los permitidos. */
    private function coberturas(int $paqueteId, array $deducibles): array
    {
        $st = $this->pdo->prepare('SELECT * FROM cat_qua_coberturas WHERE paquete_id = ? AND enviar = 1 ORDER BY no_cobertura');
        $st->execute([$paqueteId]);

        $salida = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $no  = (int) $c['no_cobertura'];
            $ded = (string) $c['deducible'];
            if (isset($deducibles[$no]) && (string) $deducibles[$no] !== '') {
                $pedido = (string) $deducibles[$no];
                $permitidos = array_filter(explode(',', (string) $c['deducibles_permitidos']));
                if (!in_array($pedido, $permitidos, true)) {
                    throw new InvalidArgumentException(
                        "Deducible de {$c['nombre']} no autorizado para el negocio: {$pedido}. Permitidos: " . implode(', ', $permitidos) . '.'
                    );
                }
                $ded = $pedido;
            }
            $salida[] = ['no' => $no, 'suma' => (string) $c['suma'], 'tipo_suma' => (string) $c['tipo_suma'], 'deducible' => $ded];
        }
        return $salida;
    }

    /** Un movimiento de la respuesta → Resultado común (ADR-010, punto 7). */
    private function resultado(array $paquete, array $mov, array $d, int $llamadaId): Resultado
    {
        $p = $mov['primas'];

        // Nombres, unidades y presentación salen del catálogo; suma y deducible, de la respuesta.
        $st = $this->pdo->prepare('SELECT no_cobertura, nombre, unidad_deducible, presentacion_suma FROM cat_qua_coberturas WHERE paquete_id = ?');
        $st->execute([(int) $paquete['id']]);
        $cat = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $cat[(int) $f['no_cobertura']] = $f;
        }

        $coberturas = [];
        foreach ($mov['coberturas'] as $cb) {
            $no  = (int) $cb['no'];
            $uni = (string) ($cat[$no]['unidad_deducible'] ?? '');
            $sumaTxt = self::textoSuma((string) $cb['suma'], (string) ($cat[$no]['presentacion_suma'] ?? 'MONTO'));
            $dedNum = ltrim((string) $cb['deducible'], '0');
            $dedTxt = $dedNum === '' ? ($uni === 'UMA' ? '0 UMA' : '—') : $dedNum . ($uni === '%' ? '%' : ($uni !== '' ? " {$uni}" : ''));

            $coberturas[] = new CoberturaResultado(
                (string) $no,
                (string) ($cat[$no]['nombre'] ?? "Cobertura {$no}"),
                $sumaTxt,
                $dedTxt,
            );
        }

        $recibos = $mov['recibos'];
        $num = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;

        return new Resultado(
            cvePaquete: (string) $paquete['codigo'],
            paquete: (string) $paquete['nombre'],
            primaTecnica: null,
            primaNeta: $p['PrimaNeta'],
            derechos: $p['Derecho'],
            iva: $p['Impuesto'],
            descuento: null, // Qualitas no devuelve el importe del descuento; el porcentaje va en conceptos.
            totalPagar: $p['PrimaTotal'],
            numPagos: $recibos !== [] ? count($recibos) : null,
            coberturas: $coberturas,
            conceptos: [
                'aseguradora'          => 'QUALITAS',
                'paquete_id'           => (int) $paquete['id'],
                'no_cotizacion'        => (string) $mov['no_cotizacion'],
                'forma_pago'           => (string) $d['forma_pago'],
                'porcentaje_descuento' => (int) $d['porcentaje_descuento'],
                'pronto_pago_dias'     => (int) $this->cliente->configXml()['pronto_pago_dias'],
                // Recargo trae el descuento por pronto pago en negativo (id 123).
                'recargo'              => $p['Recargo'],
                // Comisión tal como llega. null = "no disponible" en pantalla.
                'comision_porcentaje'  => $p['Comision'],
                'comision_importe'     => count($recibos) === 1 ? $num($recibos[0]['Comision'] ?? null) : null,
                'recibos'              => $recibos,
                'primas'               => $p,
                // Suma, tipo de suma y deducible tal como los devolvió Qualitas, por
                // cobertura: la pantalla puede decir "Amparada", pero el dato no se pierde.
                'coberturas_crudas'    => $mov['coberturas'],
                'llamada_id'           => $llamadaId,
            ],
        );
    }

    /**
     * Formato de montos igual al de los PDF de Qualitas: sin signo de pesos,
     * con separador de miles y dos decimales; el negativo como -168.81.
     * null → "no disponible".
     */
    public static function monto(?float $n): string
    {
        return $n === null ? 'no disponible' : number_format($n, 2);
    }

    /**
     * Bloque de importes con los mismos nombres y el mismo orden que los PDF
     * de Qualitas. Lo usan la pantalla de resultado y el PDF propio, para que
     * nunca digan cosas distintas (Albert, 2026-09-28).
     *
     * TASA FIN. P.F. es `Recargo` tal como llega. SUBTOTAL = prima neta +
     * TASA FIN. P.F. + GTOS.EXPED.POL., y sólo se muestra si cuadra al centavo
     * con lo que devolvió Qualitas (SUBTOTAL + I.V.A. = IMPORTE TOTAL); si no,
     * se omite en lugar de mostrar un número que Qualitas no respalda.
     *
     * @return list<array{0:string, 1:string}>  [etiqueta, monto]
     */
    public static function importes(?float $primaNeta, ?float $recargo, ?float $derechos, ?float $iva, ?float $total): array
    {
        $filas = [
            ['PRIMA NETA', self::monto($primaNeta)],
            ['TASA FIN. P.F.', self::monto($recargo)],
            ['GTOS.EXPED.POL.', self::monto($derechos)],
        ];
        if ($primaNeta !== null && $recargo !== null && $derechos !== null && $iva !== null && $total !== null) {
            $subtotal = round($primaNeta + $recargo + $derechos, 2);
            if (abs(round($subtotal + $iva, 2) - $total) < 0.005) {
                $filas[] = ['SUBTOTAL', self::monto($subtotal)];
            }
        }
        $filas[] = ['I.V.A.', self::monto($iva)];
        $filas[] = ['IMPORTE TOTAL', self::monto($total)];
        return $filas;
    }

    /**
     * Formas de pago de un resultado guardado (contado + las que el usuario
     * pidió), en el orden C, S, T, M. Primer pago y pagos siguientes son los
     * `PrimaTotal` de los recibos 1 y 2 tal como llegan. La comisión va
     * aparte (porcentaje y la de cada recibo) para que quien no deba verla,
     * como el PDF, simplemente no la use.
     *
     * @param array{total_pagar:mixed, num_pagos:mixed, conceptos:array} $fila
     * @return list<array{clave:string, nombre:string, total:?float, primer:?float, siguientes:?float, pagos:?int, comision_porcentaje:mixed, comision_recibos:list<?float>}>
     */
    public static function formasDePago(array $fila): array
    {
        $c = $fila['conceptos'] ?? [];
        $formas = ['C' => [
            'total_pagar' => $fila['total_pagar'] ?? null, 'num_pagos' => $fila['num_pagos'] ?? null, 'recibos' => $c['recibos'] ?? [],
            'comision_porcentaje' => $c['comision_porcentaje'] ?? null,
        ]] + (array) ($c['formas_pago'] ?? []);

        $num = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;
        $salida = [];
        foreach (self::FORMAS_PAGO as $clave => $nombre) {
            if (!isset($formas[$clave])) {
                continue;
            }
            $fp = $formas[$clave];
            $recibos = array_values((array) ($fp['recibos'] ?? []));
            $salida[] = [
                'clave'               => $clave,
                'nombre'              => $nombre,
                'total'               => $num($fp['total_pagar'] ?? null),
                'primer'              => $num($recibos[0]['PrimaTotal'] ?? ($clave === 'C' ? ($fp['total_pagar'] ?? null) : null)),
                'siguientes'          => $num($recibos[1]['PrimaTotal'] ?? null),
                'pagos'               => isset($fp['num_pagos']) && $fp['num_pagos'] !== null ? (int) $fp['num_pagos'] : ($recibos !== [] ? count($recibos) : null),
                'comision_porcentaje' => $fp['comision_porcentaje'] ?? null,
                'comision_recibos'    => array_map(static fn ($r) => $num($r['Comision'] ?? null), $recibos),
            ];
        }
        return $salida;
    }

    /**
     * Cómo se lee la suma de una cobertura. Una sola regla para la pantalla y
     * para el PDF (Albert, 2026-09-28):
     *
     * - AMPARADA (Gastos Legales, Asistencia Vial, RC por la carga): "Amparada",
     *   como en los PDF de Qualitas, aunque el servicio devuelva un importe.
     * - MONTO con suma mayor que 0: el importe ("$468,000").
     * - MONTO con suma 0 o vacía: "—". No se inventa "Amparada".
     * - Cualquier otro texto: tal cual.
     */
    public static function textoSuma(string $suma, string $presentacion): string
    {
        if ($presentacion === 'AMPARADA') {
            return 'Amparada';
        }
        $suma = trim($suma);
        if (is_numeric($suma)) {
            return (float) $suma > 0 ? '$' . number_format((float) $suma, 0) : '—';
        }
        return $suma === '' ? '—' : $suma;
    }

    private function fallo(string $estado, string $mensaje, string $origen): array
    {
        return ['estado' => $estado, 'paquetes' => [], 'error' => ['descripcion' => $mensaje, 'origen' => $origen], 'errores' => []];
    }
}
