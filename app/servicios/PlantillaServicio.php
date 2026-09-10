<?php
declare(strict_types=1);

/**
 * PlantillaServicio — CRUD de paquetes propios de Equinox (módulo Juega y Compara).
 *
 * Ver ADR-007. Esta pantalla arma y guarda plantillas (`cat_plantillas` +
 * `cat_plantilla_coberturas`); NO cotiza con ellas — esa conexión con
 * `CotizacionServicio` queda deliberadamente pendiente (ADR-007, Tarea C).
 *
 * `cat_coberturas` sólo guarda el valor por omisión de cada cobertura, no la
 * lista de valores permitidos (hallazgo de la Tarea A — ver ADR-007 punto 1).
 * Esa lista sí existe, cargada aparte en `cat_cobertura_valores` desde el kit
 * (`importar_valores_coberturas.php`). Con ella, la suma asegurada y el
 * deducible se ofrecen como selectores reales, no texto libre, y `guardar()`
 * valida contra esa lista antes de tocar la base.
 */
final class PlantillaServicio
{
    /** @return list<array<string,mixed>> */
    public static function listar(): array
    {
        return Db::todos(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM cat_plantilla_coberturas c WHERE c.plantilla_id = p.id) AS num_coberturas
               FROM cat_plantillas p
              ORDER BY p.nombre"
        );
    }

    public static function obtener(int $id): ?array
    {
        $p = Db::uno('SELECT * FROM cat_plantillas WHERE id = ?', [$id]);
        if ($p === null) {
            return null;
        }
        $p['coberturas'] = self::coberturasGuardadas($id);
        return $p;
    }

    /**
     * Paquetes base sobre los que se puede armar una plantilla.
     *
     * Restringido a procedencia Residentes: es la única verificada contra GNP
     * (ADR-005 punto 11). Un mismo "paquete" (ej. Amplia) tiene un CVE_PAQUETE
     * distinto por tipo de persona y tipo de vehículo — se muestran las tres
     * dimensiones para que quede claro cuál se está eligiendo.
     *
     * @return list<array{cve_paquete:string,paquete:string,tipo_persona:string,tipo_vehiculo:string}>
     */
    public static function paquetesBase(): array
    {
        return Db::todos(
            "SELECT cve_paquete, paquete, tipo_persona, tipo_vehiculo
               FROM cat_paquetes
              WHERE procedencia = 'Residentes' AND disponible = 1 AND activo = 1 AND cve_paquete != ''
              ORDER BY tipo_vehiculo, paquete, tipo_persona"
        );
    }

    /**
     * Coberturas que el paquete base de una plantilla permite tocar —
     * Básica u Opcional, tal cual las trae ese paquete (ADR-007 punto 3) —
     * con el menú real de valores permitidos de cada una (ADR-007 punto 1).
     *
     * @return list<array{cve_cobertura:string,nombre:string,tipo:string,sa_valor:string,sa_unidad:string,ded_valor:string,ded_unidad:string,grupo_excl:string,valores_suma:list<string>,valores_deducible:list<string>}>
     */
    public static function coberturasDisponibles(string $cvePaquete): array
    {
        $base = Db::uno('SELECT paquete, tipo_vehiculo FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$cvePaquete]);
        if ($base === null) {
            return [];
        }
        $grupo = CatalogoServicio::grupo($base['tipo_vehiculo']);
        $coberturas = CatalogoServicio::coberturasDe($grupo, $base['paquete']);
        foreach ($coberturas as &$c) {
            $valores = CatalogoServicio::valoresDeCobertura($grupo, $c['cve_cobertura']);
            $c['valores_suma']      = $valores['suma'];
            $c['valores_deducible'] = $valores['deducible'];
        }
        return $coberturas;
    }

    /**
     * Coberturas ya guardadas de una plantilla, con su nombre/tipo/default
     * resueltos contra el paquete base actual — para poder editarla.
     *
     * @return list<array<string,mixed>>
     */
    public static function coberturasGuardadas(int $plantillaId): array
    {
        $p = Db::uno('SELECT cve_paquete FROM cat_plantillas WHERE id = ?', [$plantillaId]);
        if ($p === null) {
            return [];
        }

        $porClave = [];
        foreach (self::coberturasDisponibles($p['cve_paquete']) as $d) {
            $porClave[$d['cve_cobertura']] = $d;
        }

        $guardadas = Db::todos(
            'SELECT cve_cobertura, suma_asegurada, deducible FROM cat_plantilla_coberturas WHERE plantilla_id = ? ORDER BY cve_cobertura',
            [$plantillaId]
        );
        foreach ($guardadas as &$g) {
            $d = $porClave[$g['cve_cobertura']] ?? null;
            $g['nombre']            = $d['nombre'] ?? '(esta cobertura ya no pertenece al paquete base actual)';
            $g['tipo']              = $d['tipo'] ?? '';
            $g['sa_unidad']         = $d['sa_unidad'] ?? '';
            $g['valores_suma']      = $d['valores_suma']      ?? [];
            $g['valores_deducible'] = $d['valores_deducible'] ?? [];
        }
        return $guardadas;
    }

    /**
     * Crea o edita una plantilla completa: datos + coberturas, en una
     * transacción. Valida localmente antes de tocar la base:
     *
     *   1. El paquete base existe en cat_paquetes.
     *   2. Cada cobertura elegida pertenece a ese paquete (Básica u Opcional)
     *      — ADR-007 punto 3, confirmado: no se puede salir de ahí.
     *   3. La suma asegurada y el deducible elegidos están en el menú real de
     *      `cat_cobertura_valores` para esa cobertura — ADR-007 punto 1.
     *   4. Ninguna combinación de coberturas elegidas es excluyente entre sí
     *      — cat_coberturas_excluyentes, mismo mecanismo que ya usa el
     *      cotizador normal.
     *
     * @param list<array{cve:string,suma?:string,deducible?:string}> $coberturas
     * @return array{ok:bool,mensaje:string,id:int}
     */
    public static function guardar(?int $id, string $nombre, string $cvePaquete, bool $activo, array $coberturas): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return ['ok' => false, 'mensaje' => 'Falta el nombre de la plantilla.', 'id' => 0];
        }

        $base = Db::uno('SELECT paquete, tipo_vehiculo FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$cvePaquete]);
        if ($base === null) {
            return ['ok' => false, 'mensaje' => 'Ese paquete base no existe o ya no está disponible.', 'id' => 0];
        }
        $grupo = CatalogoServicio::grupo($base['tipo_vehiculo']);

        $porClave = [];
        foreach (CatalogoServicio::coberturasDe($grupo, $base['paquete']) as $d) {
            $porClave[$d['cve_cobertura']] = $d;
        }

        $claves = [];
        foreach ($coberturas as $c) {
            $cve = trim((string) ($c['cve'] ?? ''));
            if ($cve === '') {
                continue;
            }
            if (!isset($porClave[$cve])) {
                return ['ok' => false, 'mensaje' =>
                    "La cobertura {$cve} no pertenece al paquete \"{$base['paquete']}\" (ni como Básica ni como Opcional). " .
                    'GNP confirmó que no se puede salir del paquete base — ver ADR-007 punto 3.', 'id' => 0];
            }

            $valores = CatalogoServicio::valoresDeCobertura($grupo, $cve);
            $suma = trim((string) ($c['suma'] ?? ''));
            $ded  = trim((string) ($c['deducible'] ?? ''));
            $nombreCob = $porClave[$cve]['nombre'];

            if ($valores['suma'] !== [] && $suma !== '' && !in_array($suma, $valores['suma'], true)) {
                return ['ok' => false, 'mensaje' =>
                    "\"{$suma}\" no es una suma asegurada permitida para \"{$nombreCob}\". " .
                    'Valores permitidos: ' . implode(', ', $valores['suma']) . '.', 'id' => 0];
            }
            if ($valores['deducible'] !== [] && $ded !== '' && !in_array($ded, $valores['deducible'], true)) {
                return ['ok' => false, 'mensaje' =>
                    "\"{$ded}\" no es un deducible permitido para \"{$nombreCob}\". " .
                    'Valores permitidos: ' . implode(', ', $valores['deducible']) . '.', 'id' => 0];
            }

            $claves[] = $cve;
        }

        if ($claves === []) {
            return ['ok' => false, 'mensaje' => 'Elige al menos una cobertura para la plantilla.', 'id' => 0];
        }

        $choque = CatalogoServicio::chocanEntreSi($claves);
        if ($choque !== '') {
            return ['ok' => false, 'mensaje' => $choque, 'id' => 0];
        }

        $duplicado = Db::valor(
            'SELECT id FROM cat_plantillas WHERE nombre = ? AND id IS NOT ?',
            [$nombre, $id ?? 0]
        );
        if ($duplicado !== null) {
            return ['ok' => false, 'mensaje' => "Ya existe otra plantilla llamada \"{$nombre}\".", 'id' => 0];
        }

        $pdo = Db::get();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                Db::ejecutar(
                    'INSERT INTO cat_plantillas (nombre, cve_paquete, activo, creado_en) VALUES (?,?,?,?)',
                    [$nombre, $cvePaquete, $activo ? 1 : 0, date('Y-m-d H:i:s')]
                );
                $id = Db::ultimoId();
            } else {
                if (self::obtener($id) === null) {
                    $pdo->rollBack();
                    return ['ok' => false, 'mensaje' => 'Esa plantilla ya no existe.', 'id' => 0];
                }
                Db::ejecutar('UPDATE cat_plantillas SET nombre = ?, cve_paquete = ?, activo = ? WHERE id = ?',
                    [$nombre, $cvePaquete, $activo ? 1 : 0, $id]);
                Db::ejecutar('DELETE FROM cat_plantilla_coberturas WHERE plantilla_id = ?', [$id]);
            }

            foreach ($coberturas as $c) {
                $cve = trim((string) ($c['cve'] ?? ''));
                if ($cve === '') {
                    continue;
                }
                Db::ejecutar(
                    'INSERT INTO cat_plantilla_coberturas (plantilla_id, cve_cobertura, suma_asegurada, deducible) VALUES (?,?,?,?)',
                    [$id, $cve, trim((string) ($c['suma'] ?? '')), trim((string) ($c['deducible'] ?? ''))]
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'mensaje' => 'No se pudo guardar: ' . $e->getMessage(), 'id' => 0];
        }

        return ['ok' => true, 'mensaje' => 'Plantilla guardada.', 'id' => $id];
    }

    /** @return string mensaje de error, o '' si quedó bien */
    public static function eliminar(int $id): string
    {
        if (self::obtener($id) === null) {
            return 'Esa plantilla ya no existe.';
        }
        Db::ejecutar('DELETE FROM cat_plantillas WHERE id = ?', [$id]);
        return '';
    }

    /** @return string mensaje de error, o '' si quedó bien */
    public static function alternarActivo(int $id): string
    {
        $p = self::obtener($id);
        if ($p === null) {
            return 'Esa plantilla ya no existe.';
        }
        Db::ejecutar('UPDATE cat_plantillas SET activo = ? WHERE id = ?', [(int) $p['activo'] === 1 ? 0 : 1, $id]);
        return '';
    }
}
