<?php
declare(strict_types=1);

/**
 * AseguradoraGnp — adaptador DELGADO que envuelve GnpClient para que
 * cumpla el contrato de la plataforma (ADR-010).
 *
 * No mueve ni renombra nada: GnpClient y su forma de hablar con GNP se
 * quedan exactamente donde están. Esta clase sólo traduce entre el formato
 * común de la plataforma y las llamadas que GnpClient ya sabe hacer.
 */
final class AseguradoraGnp implements CotizadorAseguradora
{
    private readonly GnpClient $cliente;

    public function __construct()
    {
        $this->cliente = new GnpClient(
            Env::requerir('GNP_BASE_URL'),
            Env::requerir('GNP_USUARIO'),
            Env::requerir('GNP_PASSWORD'),
            Env::requerir('GNP_ID_UNIDAD_OPERABLE'),
            Env::get('GNP_INTERMEDIARIO'),
            (int) Env::get('GNP_TIMEOUT', '60')
        );
    }

    public function clave(): string
    {
        return 'GNP';
    }

    /**
     * @param array{datos:array<string,mixed>, paquetes:list<array{cve:string,desc:string,opcionales?:list<array>}>, opcionales?:list<array>} $solicitud
     */
    public function cotizar(array $solicitud): array
    {
        $r = $this->cliente->cotizar(
            $solicitud['datos'],
            $solicitud['paquetes'],
            $solicitud['opcionales'] ?? []
        );

        $paquetes = [];
        foreach ($r['paquetes'] ?? [] as $p) {
            $c = $p['conceptos'] ?? [];

            $coberturas = [];
            foreach ($p['coberturas'] ?? [] as $cb) {
                $coberturas[] = new CoberturaResultado($cb['cve'], $cb['nombre'], $cb['suma'], $cb['ded']);
            }

            $paquetes[] = new Resultado(
                cvePaquete: $p['cve'],
                paquete: $p['desc'],
                primaTecnica: isset($c['PRIMA_TECNICA']) ? (float) $c['PRIMA_TECNICA'] : null,
                primaNeta: isset($c['PRIMA_NETA']) ? (float) $c['PRIMA_NETA'] : null,
                derechos: isset($c['DERECHOS_POLIZA']) ? (float) $c['DERECHOS_POLIZA'] : null,
                iva: isset($c['IVA']) ? (float) $c['IVA'] : null,
                descuento: isset($c['DESCUENTO']) ? (float) $c['DESCUENTO'] : null,
                totalPagar: isset($c['TOTAL_PAGAR']) ? (float) $c['TOTAL_PAGAR'] : null,
                numPagos: isset($c['NUM_PAGOS']) ? (int) $c['NUM_PAGOS'] : null,
                coberturas: $coberturas,
                conceptos: $c,
            );
        }

        return [
            'estado'   => $r['estado'],
            'paquetes' => $paquetes,
            'error'    => $r['estado'] !== CotizadorAseguradora::OK ? ($r['error'] ?? null) : null,
        ];
    }

    /**
     * @param array{folio:string, correo:string, periodicidad?:string} $cotizacion
     * @param array{paquetes:list<array{cve:string,desc:string}>} $paquete
     */
    public function imprimir(array $cotizacion, array $paquete): array
    {
        $r = $this->cliente->imprimir(
            $cotizacion['folio'],
            $cotizacion['correo'],
            $paquete['paquetes'],
            $cotizacion['periodicidad'] ?? 'A'
        );

        return [
            'estado'     => $r['estado'],
            'pdf'        => $r['pdf'] ?? '',
            'referencia' => $r['referencia'] ?? '',
            'error'      => $r['estado'] !== CotizadorAseguradora::OK ? ($r['error'] ?? null) : null,
        ];
    }

    public function catalogo(string $tipo, array $filtros = []): array
    {
        $r = $tipo === 'VEHICULOS'
            ? $this->cliente->vehiculos($filtros)
            : $this->cliente->catalogo($tipo, $filtros);

        return [
            'estado' => $r['estado'],
            'datos'  => $r['vehiculos'] ?? $r['elementos'] ?? [],
            'error'  => $r['estado'] !== CotizadorAseguradora::OK ? ($r['error'] ?? null) : null,
        ];
    }
}
