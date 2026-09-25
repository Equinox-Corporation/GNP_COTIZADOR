<?php
declare(strict_types=1);

/**
 * Resultado — el formato común en que se guarda la respuesta de cualquier
 * aseguradora (ADR-010 punto 7), venga como venga del lado de la compañía.
 *
 * Un Resultado es un paquete cotizado: se guarda tal cual en cot_resultados
 * y cot_resultado_coberturas. Precio siempre es total a pagar, con derechos
 * e IVA — nunca la prima neta, en ninguna compañía (regla de ADR-005
 * generalizada).
 */
final class Resultado
{
    /** @param CoberturaResultado[] $coberturas */
    public function __construct(
        public readonly string $cvePaquete,
        public readonly string $paquete,
        public readonly ?float $primaTecnica,
        public readonly ?float $primaNeta,
        public readonly ?float $derechos,
        public readonly ?float $iva,
        public readonly ?float $descuento,
        public readonly ?float $totalPagar,
        public readonly ?int $numPagos,
        public readonly array $coberturas,
        /** @var array<string,mixed> lo que no tenga lugar en las columnas de arriba */
        public readonly array $conceptos = [],
    ) {
    }
}

/** Una cobertura del resultado: nombre, suma y deducible como texto legible. */
final class CoberturaResultado
{
    public function __construct(
        /** La clave es la de la compañía, no una clave común. */
        public readonly string $cveCobertura,
        public readonly string $nombre,
        public readonly string $sumaAsegurada,
        public readonly string $deducible,
    ) {
    }
}
