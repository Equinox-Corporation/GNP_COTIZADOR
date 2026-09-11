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
     * Valida un conjunto de coberturas contra un paquete base: pertenencia
     * (ADR-007 punto 3), valores permitidos (ADR-007 punto 1) y exclusión
     * mutua. La comparten `guardar()` (al capturar la plantilla) y
     * `paraCotizar()` (al cotizar de verdad, como defensa en profundidad:
     * lo que era válido cuando se guardó la plantilla puede haber dejado de
     * serlo si GNP cambió su catálogo desde entonces — mismo criterio que ya
     * aplica el resto del proyecto).
     *
     * @param list<array{cve:string,suma?:string,deducible?:string}> $coberturas
     * @return array{ok:bool,mensaje:string,resueltas:list<array{cve:string,nombre:string,suma:string,deducible:string}>}
     */
    private static function validarCoberturas(string $grupo, string $paquete, array $coberturas): array
    {
        $porClave = [];
        foreach (CatalogoServicio::coberturasDe($grupo, $paquete) as $d) {
            $porClave[$d['cve_cobertura']] = $d;
        }

        $claves    = [];
        $resueltas = [];
        foreach ($coberturas as $c) {
            $cve = trim((string) ($c['cve'] ?? ''));
            if ($cve === '') {
                continue;
            }
            if (!isset($porClave[$cve])) {
                return ['ok' => false, 'mensaje' =>
                    "La cobertura {$cve} no pertenece al paquete \"{$paquete}\" (ni como Básica ni como Opcional). " .
                    'GNP confirmó que no se puede salir del paquete base — ver ADR-007 punto 3.', 'resueltas' => []];
            }

            $valores   = CatalogoServicio::valoresDeCobertura($grupo, $cve);
            $suma      = trim((string) ($c['suma'] ?? ''));
            $ded       = trim((string) ($c['deducible'] ?? ''));
            $nombreCob = $porClave[$cve]['nombre'];

            // Si esta cobertura no tiene menú de valores para una dimensión
            // (ej. Gastos Médicos Ocupantes no se mueve por deducible), no hay
            // nada válido que mandar por ese lado — aunque haya quedado
            // guardado un texto como "N/A" (placeholder heredado de
            // cat_coberturas.ded_valor, no un valor real de GNP). Sin esto,
            // GnpClient lo transmitiría tal cual y GNP responde con un error
            // de parseo interno confuso ("For input string: N/A") en vez de
            // un rechazo claro — encontrado al probar esto en producción.
            if ($valores['suma'] === []) {
                $suma = '';
            }
            if ($valores['deducible'] === []) {
                $ded = '';
            }

            if ($valores['suma'] !== [] && $suma !== '' && !in_array($suma, $valores['suma'], true)) {
                return ['ok' => false, 'mensaje' =>
                    "\"{$suma}\" no es una suma asegurada permitida para \"{$nombreCob}\". " .
                    'Valores permitidos: ' . implode(', ', $valores['suma']) . '.', 'resueltas' => []];
            }
            if ($valores['deducible'] !== [] && $ded !== '' && !in_array($ded, $valores['deducible'], true)) {
                return ['ok' => false, 'mensaje' =>
                    "\"{$ded}\" no es un deducible permitido para \"{$nombreCob}\". " .
                    'Valores permitidos: ' . implode(', ', $valores['deducible']) . '.', 'resueltas' => []];
            }

            $claves[]    = $cve;
            $resueltas[] = ['cve' => $cve, 'nombre' => $nombreCob, 'suma' => $suma, 'deducible' => $ded];
        }

        if ($claves === []) {
            return ['ok' => false, 'mensaje' => 'No hay ninguna cobertura válida que guardar.', 'resueltas' => []];
        }

        $choque = CatalogoServicio::chocanEntreSi($claves);
        if ($choque !== '') {
            return ['ok' => false, 'mensaje' => $choque, 'resueltas' => []];
        }

        return ['ok' => true, 'mensaje' => '', 'resueltas' => $resueltas];
    }

    /**
     * Crea o edita una plantilla completa: datos + coberturas, en una
     * transacción. Valida localmente antes de tocar la base — ver
     * `validarCoberturas()` — y además que el paquete base exista y que el
     * nombre no se repita.
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

        $v = self::validarCoberturas(CatalogoServicio::grupo($base['tipo_vehiculo']), $base['paquete'], $coberturas);
        if (!$v['ok']) {
            return ['ok' => false, 'mensaje' => $v['mensaje'] === 'No hay ninguna cobertura válida que guardar.'
                ? 'Elige al menos una cobertura para la plantilla.' : $v['mensaje'], 'id' => 0];
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

            foreach ($v['resueltas'] as $c) {
                Db::ejecutar(
                    'INSERT INTO cat_plantilla_coberturas (plantilla_id, cve_cobertura, suma_asegurada, deducible) VALUES (?,?,?,?)',
                    [$id, $c['cve'], $c['suma'], $c['deducible']]
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'mensaje' => 'No se pudo guardar: ' . $e->getMessage(), 'id' => 0];
        }

        return ['ok' => true, 'mensaje' => 'Plantilla guardada.', 'id' => $id];
    }

    /**
     * Plantillas activas, para ofrecerlas al cotizar (index.php, ruta "cotizar").
     *
     * @return list<array{id:int,nombre:string,cve_paquete:string,paquete:string,tipo_persona:string,tipo_vehiculo:string}>
     */
    public static function activas(): array
    {
        return Db::todos(
            "SELECT p.id, p.nombre, p.cve_paquete, cp.paquete, cp.tipo_persona, cp.tipo_vehiculo
               FROM cat_plantillas p
               JOIN cat_paquetes cp ON cp.cve_paquete = p.cve_paquete
              WHERE p.activo = 1
              GROUP BY p.id
              ORDER BY p.nombre"
        );
    }

    /**
     * Resuelve una plantilla para cotizar de verdad: revalida sus coberturas
     * contra el catálogo ACTUAL (`validarCoberturas()`) antes de que
     * `CotizacionServicio::cotizar()` arme el `<COBERTURAS>` — nunca se
     * confía en que lo validado al guardar la plantilla siga siendo válido.
     *
     * @param int $modeloVehiculo año-modelo del vehículo que se está cotizando
     * @param int $anioVigencia   año en que arranca la vigencia de la cotización
     * @return array{ok:bool,mensaje:string,nombre:string,cve_paquete:string,tipo_persona:string,tipo_vehiculo:string,coberturas:list<array{cve:string,nombre:string,suma:string,deducible:string}>,omitidas:list<array{cve:string,nombre:string,motivo:string}>}
     */
    public static function paraCotizar(int $id, int $modeloVehiculo, int $anioVigencia): array
    {
        $vacio = ['nombre' => '', 'cve_paquete' => '', 'tipo_persona' => '', 'tipo_vehiculo' => '', 'coberturas' => [], 'omitidas' => []];

        $p = Db::uno('SELECT * FROM cat_plantillas WHERE id = ? AND activo = 1', [$id]);
        if ($p === null) {
            return ['ok' => false, 'mensaje' => 'Esa plantilla ya no existe o está apagada.'] + $vacio;
        }

        $base = Db::uno('SELECT paquete, tipo_persona, tipo_vehiculo FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$p['cve_paquete']]);
        if ($base === null) {
            return ['ok' => false, 'mensaje' =>
                "El paquete base de \"{$p['nombre']}\" ({$p['cve_paquete']}) ya no existe en cat_paquetes."] + $vacio;
        }
        $grupo = CatalogoServicio::grupo($base['tipo_vehiculo']);

        $guardadas = Db::todos(
            'SELECT cve_cobertura, suma_asegurada, deducible FROM cat_plantilla_coberturas WHERE plantilla_id = ?',
            [$id]
        );
        $entrada = array_map(static fn (array $g): array => [
            'cve' => $g['cve_cobertura'], 'suma' => $g['suma_asegurada'], 'deducible' => $g['deducible'],
        ], $guardadas);

        $v = self::validarCoberturas($grupo, $base['paquete'], $entrada);
        if (!$v['ok']) {
            return ['ok' => false, 'mensaje' =>
                "La plantilla \"{$p['nombre']}\" ya no se puede aplicar tal cual: {$v['mensaje']} " .
                'Corrígela en Paquetes propios antes de volver a intentar.'] + $vacio;
        }

        $formateadas = self::paraGnpClient($grupo, $base['paquete'], $v['resueltas'], $modeloVehiculo, $anioVigencia);

        return [
            'ok'            => true,
            'mensaje'       => '',
            'nombre'        => $p['nombre'],
            'cve_paquete'   => $p['cve_paquete'],
            'tipo_persona'  => $base['tipo_persona'],
            'tipo_vehiculo' => $base['tipo_vehiculo'],
            'coberturas'    => $formateadas['incluidas'],
            'omitidas'      => $formateadas['omitidas'],
        ];
    }

    /**
     * Armador libre, Fase 1 (backend, ver docs/02.13-armador-libre-backend.md):
     * punto de partida editable para armar una combinación ad-hoc sobre un
     * paquete base, sin tocar ninguna plantilla guardada.
     *
     * Si se da `$plantillaId`, arranca con sus coberturas ya guardadas — deben
     * pertenecer al MISMO `$cvePaquete` que se pidió; rebasar una plantilla
     * hacia un paquete base distinto queda fuera de esta fase (una cobertura
     * válida en un paquete no necesariamente lo es en otro). Sin
     * `$plantillaId`, arranca vacío: sólo lo Básico del paquete, sin ninguna
     * Opcional agregada — igual que una plantilla nueva desde cero.
     *
     * @return array{ok:bool,mensaje:string,coberturas:list<array{cve:string,suma:string,deducible:string}>}
     */
    public static function puntoDePartida(string $cvePaquete, ?int $plantillaId = null): array
    {
        if ($plantillaId === null) {
            return ['ok' => true, 'mensaje' => '', 'coberturas' => []];
        }

        $p = self::obtener($plantillaId);
        if ($p === null) {
            return ['ok' => false, 'mensaje' => 'Esa plantilla ya no existe.', 'coberturas' => []];
        }
        if ($p['cve_paquete'] !== $cvePaquete) {
            return ['ok' => false, 'mensaje' =>
                "La plantilla \"{$p['nombre']}\" está armada sobre otro paquete base ({$p['cve_paquete']}), no sobre {$cvePaquete}. " .
                'Partir de una plantilla de otro paquete no está soportado todavía.', 'coberturas' => []];
        }

        $coberturas = array_map(static fn (array $c): array => [
            'cve' => $c['cve_cobertura'], 'suma' => (string) $c['suma_asegurada'], 'deducible' => (string) $c['deducible'],
        ], $p['coberturas']);

        return ['ok' => true, 'mensaje' => '', 'coberturas' => $coberturas];
    }

    /**
     * Armador libre, Fase 1: resuelve y valida una combinación de coberturas
     * armada al vuelo sobre un paquete base — mismo mecanismo que
     * `paraCotizar()`, sólo que entra por una lista completa de coberturas en
     * vez de un `plantilla_id`. No duplica ninguna regla: reutiliza
     * `validarCoberturas()` y `paraGnpClient()` tal cual.
     *
     * No toca `cat_plantillas` — es una cotización puntual. Guardarla como
     * plantilla nueva, si se decide después, es una acción aparte con
     * `guardar(null, ...)`, no algo que haga este método.
     *
     * @param list<array{cve:string,suma?:string,deducible?:string}> $coberturas combinación COMPLETA deseada, no un diff
     * @param int $modeloVehiculo año-modelo del vehículo que se está cotizando
     * @param int $anioVigencia   año en que arranca la vigencia de la cotización
     * @return array{ok:bool,mensaje:string,cve_paquete:string,tipo_persona:string,tipo_vehiculo:string,coberturas:list<array{cve:string,nombre:string,suma:string,deducible:string}>,omitidas:list<array{cve:string,nombre:string,motivo:string}>}
     */
    public static function paraAdHoc(string $cvePaquete, array $coberturas, int $modeloVehiculo, int $anioVigencia): array
    {
        $vacio = ['cve_paquete' => $cvePaquete, 'tipo_persona' => '', 'tipo_vehiculo' => '', 'coberturas' => [], 'omitidas' => []];

        $base = Db::uno('SELECT paquete, tipo_persona, tipo_vehiculo FROM cat_paquetes WHERE cve_paquete = ? LIMIT 1', [$cvePaquete]);
        if ($base === null) {
            return ['ok' => false, 'mensaje' => "El paquete \"{$cvePaquete}\" no existe en cat_paquetes."] + $vacio;
        }
        $grupo = CatalogoServicio::grupo($base['tipo_vehiculo']);

        $v = self::validarCoberturas($grupo, $base['paquete'], $coberturas);
        if (!$v['ok']) {
            return ['ok' => false, 'mensaje' => $v['mensaje']] + $vacio;
        }

        $formateadas = self::paraGnpClient($grupo, $base['paquete'], $v['resueltas'], $modeloVehiculo, $anioVigencia);

        return [
            'ok'            => true,
            'mensaje'       => '',
            'cve_paquete'   => $cvePaquete,
            'tipo_persona'  => $base['tipo_persona'],
            'tipo_vehiculo' => $base['tipo_vehiculo'],
            'coberturas'    => $formateadas['incluidas'],
            'omitidas'      => $formateadas['omitidas'],
        ];
    }

    /**
     * Convierte las coberturas ya validadas de una plantilla al formato que
     * de verdad conviene mandarle a GNP — sólo se usa al cotizar
     * (`paraCotizar()`), nunca al guardar la plantilla: lo que se captura en
     * pantalla y lo que se transmite en el XML son cosas distintas.
     *
     * Tres reglas, ninguna como caso especial de una clave — todas contra
     * columnas de `cat_coberturas`, encontradas al cotizar de verdad con las
     * plantillas reales por primera vez (ver docs/02.12-bug-amparada.md):
     *
     *   1. GNP espera números en `SUMA_ASEGURADA`/`DEDUCIBLE`. Un texto de
     *      estatus como "Amparada" (el único valor "permitido" que tienen
     *      las coberturas de valor fijo en `cat_cobertura_valores`, porque
     *      así es como GNP la describe en sus respuestas) no es válido como
     *      entrada — GNP responde con un error de parseo interno confuso
     *      ("For input string: Amparada"), no un rechazo de negocio claro.
     *      Se limpia aquí, nunca se transmite.
     *   2. Una cobertura Básica, una vez limpia, que se quedó sin ningún
     *      valor que aportar ya viene incluida por default en el paquete —
     *      mandarla es redundante (GNP ya la trae) y es exactamente lo que
     *      pasaba con Club GNP en las 4 plantillas reales. Se omite del
     *      todo. Una cobertura Opcional en la misma situación SÍ se manda
     *      igual (sólo `CVE_COBERTURA`/`NOMBRE`, sin valores) porque a
     *      diferencia de la Básica, si no se pide, GNP no la incluye —
     *      confirmado con Robo Parcial Plus (deducible sí configurable) y
     *      Eliminación de Deducible en Pérdidas Parciales (sin nada
     *      configurable, sólo activarla).
     *   3. Si `cat_coberturas.antiguedad_max_anios` está poblada para esa
     *      cobertura y el vehículo no cumple (`MODELO < año de vigencia −
     *      antiguedad_max_anios`), se omite de `<COBERTURAS>` — GNP la
     *      rechaza de todas formas, y bloquear la cotización completa por
     *      una sola cobertura inválida es peor que avisar y seguir sin
     *      ella (decisión de negocio de Beto, 2026-09-10). Se regresa aparte
     *      en `omitidas`, con el motivo, para que quien llame a esto lo
     *      pueda mostrar — nunca en silencio.
     *
     * @param list<array{cve:string,nombre:string,suma:string,deducible:string}> $resueltas
     * @return array{incluidas:list<array{cve:string,nombre:string,suma:string,deducible:string}>,omitidas:list<array{cve:string,nombre:string,motivo:string}>}
     */
    private static function paraGnpClient(string $grupo, string $paquete, array $resueltas, int $modeloVehiculo, int $anioVigencia): array
    {
        $porClave = [];
        foreach (CatalogoServicio::coberturasDe($grupo, $paquete) as $d) {
            $porClave[$d['cve_cobertura']] = $d;
        }

        $incluidas = [];
        $omitidas  = [];
        foreach ($resueltas as $c) {
            $info = $porClave[$c['cve']] ?? null;
            $nombreCob = $info['nombre'] ?? $c['nombre'];

            $maxAnios = $info['antiguedad_max_anios'] ?? null;
            if ($maxAnios !== null && $modeloVehiculo < ($anioVigencia - (int) $maxAnios)) {
                $omitidas[] = [
                    'cve'    => $c['cve'],
                    'nombre' => $nombreCob,
                    'motivo' => "\"{$nombreCob}\" no aplica — el vehículo no cumple la antigüedad requerida "
                              . "(máximo {$maxAnios} años; modelo {$modeloVehiculo} para una vigencia que arranca en {$anioVigencia}).",
                ];
                continue;
            }

            $suma = ($c['suma'] !== '' && is_numeric($c['suma'])) ? $c['suma'] : '';
            $ded  = ($c['deducible'] !== '' && is_numeric($c['deducible'])) ? $c['deducible'] : '';

            $tipo = $info['tipo'] ?? '';
            if ($tipo === 'BASICA' && $suma === '' && $ded === '') {
                continue; // ya viene incluida por default — mandarla es redundante
            }

            $incluidas[] = ['cve' => $c['cve'], 'nombre' => $c['nombre'], 'suma' => $suma, 'deducible' => $ded];
        }
        return ['incluidas' => $incluidas, 'omitidas' => $omitidas];
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
