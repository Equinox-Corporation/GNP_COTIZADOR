<?php
declare(strict_types=1);

/**
 * Aseguradoras — el registro: qué compañías hay y en qué estado
 * (ADR-010 punto 2). El menú se arma a partir de sys_aseguradoras, no con
 * código fijo, para que agregar una compañía sea dar de alta un registro y
 * su carpeta, sin tocar el menú ni las demás.
 */
final class Aseguradoras
{
    public const PREPARADA      = 'PREPARADA';
    public const EN_INTEGRACION = 'EN_INTEGRACION';
    public const OPERATIVA      = 'OPERATIVA';
    public const SUSPENDIDA     = 'SUSPENDIDA';

    /**
     * Todas las compañías de sys_aseguradoras, en orden. Cada fila trae
     * clave, nombre, estado y orden.
     *
     * @return array<int,array{clave:string,nombre:string,estado:string,orden:int}>
     */
    public static function todas(PDO $pdo): array
    {
        $filas = $pdo->query('SELECT clave, nombre, estado, orden FROM sys_aseguradoras ORDER BY orden')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $f): array => [
            'clave'  => (string) $f['clave'],
            'nombre' => (string) $f['nombre'],
            'estado' => (string) $f['estado'],
            'orden'  => (int) $f['orden'],
        ], $filas);
    }

    /**
     * Las que se le pueden mostrar a un usuario: OPERATIVA siempre;
     * PREPARADA y EN_INTEGRACION sólo si es administrador (con la leyenda
     * "en preparación" — responsabilidad de la vista).
     *
     * @return array<int,array{clave:string,nombre:string,estado:string,orden:int}>
     */
    public static function visiblesPara(PDO $pdo, bool $esAdmin): array
    {
        return array_values(array_filter(
            self::todas($pdo),
            static fn (array $a): bool => $a['estado'] === self::OPERATIVA
                || ($esAdmin && in_array($a['estado'], [self::PREPARADA, self::EN_INTEGRACION], true))
        ));
    }

    /** El cliente de la compañía pedida. Lanza si no hay adaptador para esa clave. */
    public static function cliente(string $clave): CotizadorAseguradora
    {
        return match (strtoupper($clave)) {
            'GNP' => new AseguradoraGnp(),
            default => throw new RuntimeException("No hay adaptador de aseguradora para \"{$clave}\"."),
        };
    }
}
