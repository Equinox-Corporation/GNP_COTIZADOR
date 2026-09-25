<?php
declare(strict_types=1);

/**
 * CotizadorAseguradora — el contrato que cumple todo módulo de aseguradora
 * (ADR-010 punto 3).
 *
 * Por dentro cada compañía habla como pida su servicio (XML, SOAP, JSON…).
 * Por fuera, todas ofrecen estos cuatro botones, para que el resto de la
 * plataforma (historial, evidencia, mensajes al usuario) funcione igual sin
 * saber cómo habla cada una.
 */
interface CotizadorAseguradora
{
    /**
     * Mismas categorías de estado que ya usa GnpClient, generalizadas para
     * cualquier compañía.
     */
    public const OK        = 'OK';
    public const E_AUTH    = 'AUTH';      // credenciales rechazadas
    public const E_DATOS   = 'DATOS';     // mandamos algo mal
    public const E_SISTEMA = 'SISTEMA';   // falla interna de la compañía
    public const E_TIMEOUT = 'TIMEOUT';
    public const E_RED     = 'RED';

    /** GNP | HDI | QUALITAS | ZURICH — debe existir en sys_aseguradoras. */
    public function clave(): string;

    /**
     * Cotiza uno o más paquetes. Recibe la solicitud en el formato común
     * (los mismos campos que ya usa cot_cotizaciones, más
     * datos_aseguradora_json para lo que la compañía pida de más) y
     * devuelve un arreglo de Resultado, uno por paquete cotizado.
     *
     * @param array<string,mixed> $solicitud
     * @return array{estado:string, paquetes:Resultado[], error:array{clave?:string,origen?:string,descripcion?:string}|null}
     */
    public function cotizar(array $solicitud): array;

    /**
     * Trae el PDF oficial de la cotización, si la compañía lo ofrece.
     *
     * @return array{estado:string, pdf?:string, referencia?:string, error:array{clave?:string,origen?:string,descripcion?:string}|null}
     */
    public function imprimir(array $cotizacion, array $paquete): array;

    /**
     * Descarga un catálogo de la compañía (vehículos, coberturas…).
     *
     * @param array<string,mixed> $filtros
     * @return array{estado:string, datos:array<int,array<string,mixed>>, error:array{clave?:string,origen?:string,descripcion?:string}|null}
     */
    public function catalogo(string $tipo, array $filtros = []): array;
}
