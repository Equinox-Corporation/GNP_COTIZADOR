<?php
declare(strict_types=1);

/**
 * CatalogoServicio — todo lo que la pantalla necesita leer del espejo local.
 *
 * Ninguna de estas consultas llama a GNP: salen de la base. Por eso los
 * desplegables responden al instante.
 */
final class CatalogoServicio
{
    public const TIPOS_VEHICULO = [
        'AUT' => 'Automóvil',
        'CA1' => 'Camión ligero',
        'CA2' => 'Camión pesado',
        'MOT' => 'Motocicleta',
    ];

    /** Los paquetes de moto tienen su propia tabla de coberturas. */
    public static function grupo(string $tipoVehiculo): string
    {
        return $tipoVehiculo === 'MOT' ? 'MOTO' : 'AUTO';
    }

    /** @return list<array{clave:string,nombre:string}> */
    public static function marcas(string $tipoVehiculo): array
    {
        return Db::todos(
            'SELECT armadora AS clave, MAX(armadora_nombre) AS nombre
               FROM cat_vehiculos WHERE tipo_vehiculo = ?
              GROUP BY armadora ORDER BY nombre',
            [$tipoVehiculo]
        );
    }

    /** @return list<array{clave:string,nombre:string}> */
    public static function lineas(string $tipoVehiculo, string $armadora): array
    {
        return Db::todos(
            'SELECT carroceria AS clave, MAX(carroceria_nombre) AS nombre
               FROM cat_vehiculos WHERE tipo_vehiculo = ? AND armadora = ?
              GROUP BY carroceria ORDER BY nombre',
            [$tipoVehiculo, $armadora]
        );
    }

    /** @return list<int> */
    public static function anios(string $tipoVehiculo, string $armadora, string $carroceria): array
    {
        $f = Db::todos(
            'SELECT DISTINCT modelo FROM cat_vehiculos
              WHERE tipo_vehiculo = ? AND armadora = ? AND carroceria = ?
              ORDER BY modelo DESC',
            [$tipoVehiculo, $armadora, $carroceria]
        );
        return array_map(static fn ($r) => (int) $r['modelo'], $f);
    }

    /** @return list<array{clave:string,nombre:string,clavemarca:string}> */
    public static function versiones(string $tipoVehiculo, string $armadora, string $carroceria, int $modelo): array
    {
        return Db::todos(
            'SELECT version AS clave, version_nombre AS nombre, clavemarca
               FROM cat_vehiculos
              WHERE tipo_vehiculo = ? AND armadora = ? AND carroceria = ? AND modelo = ?
              ORDER BY version_nombre',
            [$tipoVehiculo, $armadora, $carroceria, $modelo]
        );
    }

    public static function vehiculo(string $tipoVehiculo, string $armadora, string $carroceria, string $version, int $modelo): ?array
    {
        return Db::uno(
            'SELECT * FROM cat_vehiculos
              WHERE tipo_vehiculo = ? AND armadora = ? AND carroceria = ? AND version = ? AND modelo = ?',
            [$tipoVehiculo, $armadora, $carroceria, $version, $modelo]
        );
    }

    /** Búsqueda por texto para el buscador rápido. */
    public static function buscar(string $texto, string $tipoVehiculo = 'AUT', int $limite = 30): array
    {
        $t = '%' . mb_strtoupper(trim($texto), 'UTF-8') . '%';
        return Db::todos(
            'SELECT clavemarca, armadora, armadora_nombre, carroceria, carroceria_nombre,
                    version, version_nombre, modelo
               FROM cat_vehiculos
              WHERE tipo_vehiculo = ?
                AND (UPPER(carroceria_nombre) LIKE ? OR UPPER(version_nombre) LIKE ?)
              ORDER BY modelo DESC, carroceria_nombre, version_nombre
              LIMIT ?',
            [$tipoVehiculo, $t, $t, $limite]
        );
    }

    /** @return list<array{procedencia:string,sub_ramo:string,verificado:int}> */
    public static function procedencias(): array
    {
        return Db::todos('SELECT * FROM cat_procedencias ORDER BY orden');
    }

    public static function subRamoDe(string $procedencia): string
    {
        return (string) (Db::valor('SELECT sub_ramo FROM cat_procedencias WHERE procedencia = ?', [$procedencia]) ?? '');
    }

    /**
     * Paquetes que GNP ofrece para esta combinación y que Equinox tiene activos.
     *
     * @return list<array{cve_paquete:string,paquete:string}>
     */
    public static function paquetes(string $tipoPersona, string $procedencia, string $tipoVehiculo): array
    {
        return Db::todos(
            'SELECT cve_paquete, paquete FROM cat_paquetes
              WHERE tipo_persona = ? AND procedencia = ? AND tipo_vehiculo = ?
                AND disponible = 1 AND activo = 1
              ORDER BY orden, paquete',
            [$tipoPersona, $procedencia, $tipoVehiculo]
        );
    }

    /** @return list<array{cve_cobertura:string,nombre:string,sa_valor:string,sa_unidad:string}> */
    public static function opcionalesDe(string $grupo, string $paquete): array
    {
        return Db::todos(
            "SELECT cve_cobertura, nombre, sa_valor, sa_unidad
               FROM cat_coberturas
              WHERE grupo = ? AND paquete = ? AND tipo = 'OPCIONAL'
              ORDER BY nombre",
            [$grupo, $paquete]
        );
    }

    /**
     * Opcionales comunes a TODOS los paquetes elegidos.
     * Sólo se ofrecen ésas: una cobertura que no aplique a algún paquete
     * haría fallar (o desviar) la cotización de ese paquete.
     *
     * @param list<string> $paquetes
     */
    public static function opcionalesComunes(string $grupo, array $paquetes): array
    {
        if ($paquetes === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($paquetes), '?'));
        return Db::todos(
            "SELECT c.cve_cobertura,
                    MAX(c.nombre)    nombre,
                    MAX(c.sa_valor)  sa_valor,
                    MAX(c.sa_unidad) sa_unidad,
                    COALESCE(MAX(x.grupo_excl), '') grupo_excl
               FROM cat_coberturas c
               LEFT JOIN cat_coberturas_excluyentes x ON x.cve_cobertura = c.cve_cobertura
              WHERE c.grupo = ? AND c.tipo = 'OPCIONAL' AND c.paquete IN ({$marcas})
              GROUP BY c.cve_cobertura
             HAVING COUNT(DISTINCT c.paquete) = ?
              ORDER BY nombre",
            array_merge([$grupo], $paquetes, [count($paquetes)])
        );
    }

    /**
     * Grupos de coberturas que no se pueden pedir juntas.
     *
     * @return array<string,list<string>> nombre del grupo => claves
     */
    public static function gruposExcluyentes(): array
    {
        $g = [];
        foreach (Db::todos('SELECT grupo_excl, cve_cobertura FROM cat_coberturas_excluyentes ORDER BY grupo_excl') as $f) {
            $g[$f['grupo_excl']][] = $f['cve_cobertura'];
        }
        return $g;
    }

    /**
     * Revisa que no se hayan elegido dos coberturas del mismo grupo excluyente.
     *
     * @param list<string> $claves
     * @return string mensaje para el usuario, o '' si todo está bien
     */
    public static function chocanEntreSi(array $claves): string
    {
        foreach (self::gruposExcluyentes() as $grupo => $delGrupo) {
            $elegidas = array_values(array_intersect($claves, $delGrupo));
            if (count($elegidas) < 2) {
                continue;
            }
            $nombres = [];
            foreach ($elegidas as $c) {
                $n = Db::valor('SELECT nombre FROM cat_coberturas WHERE cve_cobertura = ? LIMIT 1', [$c]);
                $nombres[] = $n !== null ? (string) $n : $c;
            }
            return 'GNP no permite pedir juntas estas coberturas: ' . implode(' y ', $nombres)
                 . '. Son excluyentes — hay que elegir sólo una.';
        }
        return '';
    }

    /** ¿Está listo el espejo para cotizar? */
    public static function diagnostico(): array
    {
        return [
            'vehiculos'    => (int) Db::valor('SELECT COUNT(*) FROM cat_vehiculos'),
            'marcas'       => (int) Db::valor('SELECT COUNT(DISTINCT armadora) FROM cat_vehiculos'),
            'paquetes'     => (int) Db::valor('SELECT COUNT(*) FROM cat_paquetes WHERE disponible = 1'),
            'coberturas'   => (int) Db::valor('SELECT COUNT(*) FROM cat_coberturas'),
            'catalogos'    => (int) Db::valor('SELECT COUNT(DISTINCT tipo_catalogo) FROM cat_catalogos'),
            'cotizaciones' => (int) Db::valor('SELECT COUNT(*) FROM cot_cotizaciones'),
        ];
    }

    public static function listoParaCotizar(): bool
    {
        $d = self::diagnostico();
        return $d['vehiculos'] > 0 && $d['paquetes'] > 0;
    }
}
