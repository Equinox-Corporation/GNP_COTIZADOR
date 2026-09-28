<?php
declare(strict_types=1);

/**
 * QualitasXml — arma el XML de COTIZACIÓN (TipoMovimiento="2") de Qualitas
 * y calcula el dígito verificador de la clave AMIS.
 *
 * Sólo cotización: no hay forma de pedirle a esta clase un movimiento 3
 * (emisión) o 4 (endoso). El TipoMovimiento va fijo en la plantilla, y aun
 * así QualitasClient vuelve a revisar el contenido antes de enviar.
 *
 * La forma del XML sigue los ejemplos que mandó Qualitas (carpeta
 * "Ejemplos Qualitas", 23-sep-2026) y el manual AnalisisDeEsquemaDe-
 * SistemasUsuarios.pdf. Todo es [PENDIENTE] hasta verlo responder en QA
 * (docs/aseguradoras/qualitas/00-estado.md).
 *
 * No inventa coberturas: las del paquete llegan en la solicitud (en la
 * Etapa 4 saldrán de cat_qua_coberturas).
 */
final class QualitasXml
{
    /** Los ejemplos de Qualitas mandan 0 en TipoRegla; el manual dice "vacío". Se sigue el ejemplo. */
    private const TIPO_REGLA = '0';

    /**
     * Dígito verificador módulo 10 de la clave AMIS (manual, págs. 11-12):
     * se rellena a 5 dígitos por la izquierda, (suma de posiciones impares × 3)
     * + suma de posiciones pares, y el dígito es lo que falta para el siguiente
     * múltiplo de 10.
     */
    public static function digitoAmis(string $clave): int
    {
        $clave = trim($clave);
        if (!preg_match('/^\d{1,5}$/', $clave)) {
            throw new InvalidArgumentException("La clave AMIS debe tener de 1 a 5 dígitos: \"{$clave}\".");
        }
        $clave = str_pad($clave, 5, '0', STR_PAD_LEFT);

        $impares = 0;
        $pares   = 0;
        for ($i = 0; $i < 5; $i++) {
            // Posición 1 (i=0) es impar.
            if ($i % 2 === 0) {
                $impares += (int) $clave[$i];
            } else {
                $pares += (int) $clave[$i];
            }
        }
        $total = $impares * 3 + $pares;

        return (10 - $total % 10) % 10;
    }

    /**
     * XML de cotización para un paquete.
     *
     * @param array{
     *   clave_vehiculo:string, modelo:int|string, conductor_cp:string,
     *   vigencia_inicio?:string, fecha_emision?:string,
     *   datos_aseguradora:array<string,mixed>,
     *   paquete:array{clave:string, coberturas:list<array{no:int|string, suma:string|int, tipo_suma?:int|string, deducible?:int|string}>}
     * } $solicitud
     * @param array{negocio:string, agente:string, derechos:string, pronto_pago_dias:string, tarifa:string, ambiente_pruebas:bool} $config
     */
    public static function cotizacion(array $solicitud, array $config): string
    {
        $d = $solicitud['datos_aseguradora'] ?? [];

        $amis = trim((string) ($solicitud['clave_vehiculo'] ?? ''));
        $digito = self::digitoAmis($amis);

        $modelo = trim((string) ($solicitud['modelo'] ?? ''));
        if (!preg_match('/^\d{4}$/', $modelo)) {
            throw new InvalidArgumentException("Modelo inválido: \"{$modelo}\".");
        }

        $cp = trim((string) ($solicitud['conductor_cp'] ?? ''));
        if (!preg_match('/^\d{5}$/', $cp)) {
            throw new InvalidArgumentException("Código postal inválido: \"{$cp}\".");
        }

        // Estado del Anexo 1 (1-32). Tiene que corresponder con el CP: si no,
        // Qualitas responde el error 202 y cae en DATOS.
        $estado = trim((string) ($d['estado'] ?? ''));
        if (!preg_match('/^\d{1,2}$/', $estado) || (int) $estado < 1 || (int) $estado > 32) {
            throw new InvalidArgumentException("Estado inválido (Anexo 1, 1 a 32): \"{$estado}\".");
        }

        $descuento = $d['porcentaje_descuento'] ?? null;
        if (!is_int($descuento) && !(is_string($descuento) && preg_match('/^\d{1,3}$/', $descuento))) {
            throw new InvalidArgumentException('Falta el porcentaje de descuento (entero).');
        }
        $descuento = (int) $descuento;
        if ($descuento < 0 || $descuento > 100) {
            throw new InvalidArgumentException("Porcentaje de descuento fuera de 0-100: {$descuento}.");
        }

        $fechaEmision = (string) ($solicitud['fecha_emision'] ?? date('Y-m-d'));
        $inicio       = (string) (($solicitud['vigencia_inicio'] ?? '') !== '' ? $solicitud['vigencia_inicio'] : $fechaEmision);
        $termino      = (new DateTimeImmutable($inicio))->modify('+1 year')->format('Y-m-d');

        $uso       = (string) ($d['uso'] ?? '1');
        $servicio  = (string) ($d['servicio'] ?? '1');
        $formaPago = (string) ($d['forma_pago'] ?? 'C');
        if (!in_array($formaPago, ['C', 'S', 'T', 'M'], true)) {
            throw new InvalidArgumentException("Forma de pago no autorizada para el negocio: \"{$formaPago}\".");
        }

        // Consideración 39 (nivel inciso): blindado | asistencia vial plus.
        // Los tres ejemplos de Qualitas mandan "N|S" [PENDIENTE].
        $blindado = (string) ($d['blindado'] ?? 'N');
        $avPlus   = (string) ($d['asistencia_vial_plus'] ?? 'S');

        // Consideración 40 (nivel asegurado): códigos SEPOMEX del domicilio legal
        // para la tarifa por CP — TipoRegla 7 municipio, 8 colonia ("Indicaciones
        // Qualitas.pdf" y plantilla XMLDoc_EjemploCamposEmision_CP.xml). Van las
        // dos o ninguna; sin ellas el XML sale idéntico al de antes. Formato
        // exacto (ceros a la izquierda) [PENDIENTE hasta verlo en QA].
        $municipio = trim((string) ($d['municipio_sepomex'] ?? ''));
        $colonia   = trim((string) ($d['colonia_sepomex'] ?? ''));
        if (($municipio === '') !== ($colonia === '')) {
            throw new InvalidArgumentException('La consideración 40 lleva municipio y colonia juntos (SEPOMEX).');
        }
        foreach (['municipio' => $municipio, 'colonia' => $colonia] as $nombre => $codigo) {
            if ($codigo !== '' && !preg_match('/^\d{1,6}$/', $codigo)) {
                throw new InvalidArgumentException("Código de {$nombre} SEPOMEX inválido (sólo dígitos): \"{$codigo}\".");
            }
        }

        $coberturas = self::coberturas($solicitud['paquete']['coberturas'] ?? [], $d);

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $x  = "<Movimientos>\n";
        $x .= "\t<Movimiento TipoMovimiento=\"2\" NoPoliza=\"\" NoCotizacion=\"\" NoEndoso=\"\" TipoEndoso=\"\" NoOTra=\"\" NoNegocio=\"{$e($config['negocio'])}\">\n";
        $x .= "\t\t<DatosAsegurado NoAsegurado=\"\">\n";
        $x .= "\t\t\t<Nombre/>\n\t\t\t<Direccion/>\n\t\t\t<Colonia/>\n\t\t\t<Poblacion/>\n";
        $x .= "\t\t\t<Estado>{$e($estado)}</Estado>\n";
        $x .= "\t\t\t<CodigoPostal>{$e($cp)}</CodigoPostal>\n";
        $x .= "\t\t\t<NoEmpleado/>\n\t\t\t<Agrupador/>\n";
        if ($municipio !== '') {
            $x .= self::consideracion('DA', '40', $municipio, "\t\t\t", '7');
            $x .= self::consideracion('DA', '40', $colonia, "\t\t\t", '8');
        }
        $x .= "\t\t</DatosAsegurado>\n";
        $x .= "\t\t<DatosVehiculo NoInciso=\"1\">\n";
        $x .= "\t\t\t<ClaveAmis>{$e($amis)}</ClaveAmis>\n";
        $x .= "\t\t\t<Modelo>{$e($modelo)}</Modelo>\n";
        $x .= "\t\t\t<DescripcionVehiculo/>\n";
        $x .= "\t\t\t<Uso>{$e($uso)}</Uso>\n";
        $x .= "\t\t\t<Servicio>{$e($servicio)}</Servicio>\n";
        $x .= "\t\t\t<Paquete>{$e((string) $solicitud['paquete']['clave'])}</Paquete>\n";
        $x .= "\t\t\t<Motor/>\n\t\t\t<Serie/>\n";
        foreach ($coberturas as $c) {
            $x .= "\t\t\t<Coberturas NoCobertura=\"{$e($c['no'])}\">\n";
            $x .= "\t\t\t\t<SumaAsegurada>{$e($c['suma'])}</SumaAsegurada>\n";
            $x .= "\t\t\t\t<TipoSuma>{$e($c['tipo_suma'])}</TipoSuma>\n";
            $x .= "\t\t\t\t<Deducible>{$e($c['deducible'])}</Deducible>\n";
            $x .= "\t\t\t\t<Prima>0</Prima>\n";
            $x .= "\t\t\t</Coberturas>\n";
        }
        $x .= self::consideracion('DV', '39', "{$blindado}|{$avPlus}", "\t\t\t");
        $x .= "\t\t</DatosVehiculo>\n";
        $x .= "\t\t<DatosGenerales>\n";
        $x .= "\t\t\t<FechaEmision>{$e($fechaEmision)}</FechaEmision>\n";
        $x .= "\t\t\t<FechaInicio>{$e($inicio)}</FechaInicio>\n";
        $x .= "\t\t\t<FechaTermino>{$e($termino)}</FechaTermino>\n";
        $x .= "\t\t\t<Moneda>0</Moneda>\n";
        $x .= "\t\t\t<Agente>{$e($config['agente'])}</Agente>\n";
        $x .= "\t\t\t<FormaPago>{$e($formaPago)}</FormaPago>\n";
        $x .= "\t\t\t<TarifaValores>{$e($config['tarifa'])}</TarifaValores>\n";
        $x .= "\t\t\t<TarifaCuotas>{$e($config['tarifa'])}</TarifaCuotas>\n";
        $x .= "\t\t\t<TarifaDerechos>{$e($config['tarifa'])}</TarifaDerechos>\n";
        $x .= "\t\t\t<Plazo/>\n\t\t\t<Agencia/>\n\t\t\t<Contrato/>\n";
        $x .= "\t\t\t<PorcentajeDescuento>{$descuento}</PorcentajeDescuento>\n";
        // 01 dígito verificador AMIS · 04 ambiente (1 pruebas, 0 producción) · 05 días de pronto pago.
        $x .= self::consideracion('DG', '1', (string) $digito, "\t\t\t");
        $x .= self::consideracion('DG', '4', $config['ambiente_pruebas'] ? '1' : '0', "\t\t\t");
        $x .= self::consideracion('DG', '5', (string) (int) $config['pronto_pago_dias'], "\t\t\t");
        $x .= "\t\t</DatosGenerales>\n";
        $x .= "\t\t<Primas>\n";
        $x .= "\t\t\t<PrimaNeta/>\n";
        $x .= "\t\t\t<Derecho>{$e($config['derechos'])}</Derecho>\n";
        $x .= "\t\t\t<Recargo/>\n\t\t\t<Impuesto/>\n\t\t\t<PrimaTotal/>\n\t\t\t<Comision/>\n";
        $x .= "\t\t</Primas>\n";
        $x .= "\t\t<CodigoError/>\n";
        $x .= "\t</Movimiento>\n";
        $x .= "</Movimientos>";

        return $x;
    }

    /**
     * Normaliza y ordena las coberturas por número. Si el uso es carga y
     * trae tipo de carga, agrega la 31 (Daños por la carga): su "suma" es
     * Tipo [A|B|C] | descripción de la carga (Anexo 4).
     *
     * @return list<array{no:string, suma:string, tipo_suma:string, deducible:string}>
     */
    private static function coberturas(array $lista, array $d): array
    {
        $salida = [];
        foreach ($lista as $c) {
            $no = trim((string) ($c['no'] ?? ''));
            if (!preg_match('/^\d{1,3}$/', $no)) {
                throw new InvalidArgumentException("Número de cobertura inválido: \"{$no}\".");
            }
            $salida[(int) $no] = [
                'no'        => (string) (int) $no,
                'suma'      => (string) ($c['suma'] ?? '0'),
                'tipo_suma' => (string) ($c['tipo_suma'] ?? '0'),
                'deducible' => (string) ($c['deducible'] ?? '0'),
            ];
        }

        $tipoCarga = strtoupper(trim((string) ($d['tipo_carga'] ?? '')));
        if ($tipoCarga !== '') {
            if (!in_array($tipoCarga, ['A', 'B', 'C'], true)) {
                throw new InvalidArgumentException("Tipo de carga inválido (A, B o C): \"{$tipoCarga}\".");
            }
            $suma = $tipoCarga . '|' . trim((string) ($d['descripcion_carga'] ?? ''));
            if (trim((string) ($d['remolques'] ?? '')) !== '') {
                $suma .= '|' . trim((string) $d['remolques']);
            }
            $salida[31] = ['no' => '31', 'suma' => $suma, 'tipo_suma' => '0', 'deducible' => '0'];
        }

        if ($salida === []) {
            throw new InvalidArgumentException('El paquete no trae coberturas.');
        }
        ksort($salida);

        return array_values($salida);
    }

    private static function consideracion(string $nivel, string $no, string $valor, string $sangria, string $tipoRegla = self::TIPO_REGLA): string
    {
        $v = htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return "{$sangria}<ConsideracionesAdicionales{$nivel} NoConsideracion=\"{$no}\">\n"
             . "{$sangria}\t<TipoRegla>{$tipoRegla}</TipoRegla>\n"
             . "{$sangria}\t<ValorRegla>{$v}</ValorRegla>\n"
             . "{$sangria}</ConsideracionesAdicionales{$nivel}>\n";
    }
}
