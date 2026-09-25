<?php
declare(strict_types=1);

/**
 * CandadoEmision — el mismo candado que ya usa GnpClient (su lista
 * PROHIBIDAS), generalizado para que cualquier cliente nuevo lo reutilice
 * en vez de reinventarlo (ADR-010 punto 4).
 *
 * Este sistema es sólo de cotización: ningún cliente de aseguradora puede
 * tocar una ruta de emisión, cobro o cancelación. Es condición para que un
 * módulo pase a EN_INTEGRACION.
 *
 * Uso: el cliente de la compañía hace `use CandadoEmision;` y llama
 * `$this->validarRuta($ruta)` antes de cada llamada HTTP, igual que
 * GnpClient::enviar() ya hace con su propia lista.
 */
trait CandadoEmision
{
    /** Palabras que no puede contener ninguna ruta que este sistema llame. */
    private const RUTAS_PROHIBIDAS = ['emisor', 'emitir', 'cancelacion', 'cobro', 'recibofiscal', 'ecommerce', 'previopago'];

    private function validarRuta(string $ruta): void
    {
        foreach (self::RUTAS_PROHIBIDAS as $p) {
            if (str_contains(strtolower($ruta), $p)) {
                throw new RuntimeException(
                    "BLOQUEADO: la ruta contiene \"{$p}\". Este sistema es sólo de cotización."
                );
            }
        }
    }
}
