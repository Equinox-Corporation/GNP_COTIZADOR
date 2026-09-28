<?php
declare(strict_types=1);

/**
 * RangoDescuento — el rango de descuento que un usuario puede capturar, por
 * aseguradora y tipo de vehículo (tabla sys_descuentos, decisión de Albert
 * del 2026-09-28).
 *
 * Común a la plataforma: sirve a cualquier compañía que reciba el descuento
 * en la petición (hoy, Qualitas en PorcentajeDescuento). GNP no recibe
 * descuento y no se conecta a esto.
 *
 * Cómo se resuelve: primero aseguradora + tipo; si no hay fila, aseguradora
 * + TODOS; si tampoco hay, 0–0. Nunca "sin límite".
 */
final class RangoDescuento
{
    public const TODOS = 'TODOS';

    /** Tipos de vehículo que acepta la tabla, con su nombre para pantalla. */
    public const TIPOS = [
        'TODOS'  => 'Todos (por omisión)',
        'AUTO'   => 'Autos',
        'PICKUP' => 'Pick-up',
        'CAMION' => 'Camiones',
        'MOTO'   => 'Motos',
    ];

    /**
     * @return array{minimo:int, maximo:int, fila:?string}  fila = tipo que se usó, o null si no hubo ninguna (0–0)
     */
    public static function resolver(PDO $pdo, string $aseguradora, string $tipo = self::TODOS): array
    {
        $st = $pdo->prepare('SELECT minimo, maximo FROM sys_descuentos WHERE aseguradora = ? AND tipo_vehiculo = ?');
        foreach (array_unique([strtoupper($tipo), self::TODOS]) as $t) {
            $st->execute([strtoupper($aseguradora), $t]);
            $f = $st->fetch(PDO::FETCH_ASSOC);
            if ($f !== false) {
                return ['minimo' => (int) $f['minimo'], 'maximo' => (int) $f['maximo'], 'fila' => $t];
            }
        }
        return ['minimo' => 0, 'maximo' => 0, 'fila' => null];
    }

    /**
     * Valida el porcentaje que capturó el usuario. Devuelve null si está bien,
     * o el mensaje para el usuario (con el rango permitido) si no.
     */
    public static function validar(PDO $pdo, string $aseguradora, string $tipo, mixed $porcentaje): ?string
    {
        $r = self::resolver($pdo, $aseguradora, $tipo);
        $txt = is_int($porcentaje) ? (string) $porcentaje : trim((string) $porcentaje);

        if (!preg_match('/^\d{1,3}$/', $txt)) {
            return "El descuento tiene que ser un número entero. Rango permitido: {$r['minimo']} a {$r['maximo']}%.";
        }
        $n = (int) $txt;
        if ($n < $r['minimo'] || $n > $r['maximo']) {
            return $r['fila'] === null
                ? 'Esta aseguradora no tiene rango de descuento configurado: no se permite descuento (0%).'
                : "El descuento de {$n}% está fuera del rango permitido: {$r['minimo']} a {$r['maximo']}%.";
        }
        return null;
    }

    /** Todas las filas, para la pantalla de administración. */
    public static function todas(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT d.aseguradora, d.tipo_vehiculo, d.minimo, d.maximo, d.actualizado_por, d.actualizado_en
               FROM sys_descuentos d
          LEFT JOIN sys_aseguradoras a ON a.clave = d.aseguradora
           ORDER BY COALESCE(a.orden, 99), d.aseguradora,
                    CASE d.tipo_vehiculo WHEN 'TODOS' THEN 0 WHEN 'AUTO' THEN 1 WHEN 'PICKUP' THEN 2 WHEN 'CAMION' THEN 3 WHEN 'MOTO' THEN 4 ELSE 5 END"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Últimos cambios, para la misma pantalla. */
    public static function cambios(PDO $pdo, int $limite = 50): array
    {
        $st = $pdo->prepare('SELECT * FROM sys_descuentos_cambios ORDER BY id DESC LIMIT ?');
        $st->execute([$limite]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alta o cambio de una fila. Enteros, 0 ≤ mínimo ≤ máximo ≤ 100. El cambio
     * queda en sys_descuentos_cambios con quién y cuándo. Devuelve null si se
     * guardó, o el motivo por el que no.
     */
    public static function guardar(PDO $pdo, string $aseguradora, string $tipo, mixed $minimo, mixed $maximo, ?int $usuarioId, string $usuario): ?string
    {
        $aseguradora = strtoupper(trim($aseguradora));
        $tipo = strtoupper(trim($tipo));

        $st = $pdo->prepare('SELECT 1 FROM sys_aseguradoras WHERE clave = ?');
        $st->execute([$aseguradora]);
        if ($st->fetchColumn() === false) {
            return "La aseguradora \"{$aseguradora}\" no existe.";
        }
        if (!isset(self::TIPOS[$tipo])) {
            return "Tipo de vehículo desconocido: \"{$tipo}\".";
        }

        $min = trim((string) $minimo);
        $max = trim((string) $maximo);
        if (!preg_match('/^\d{1,3}$/', $min) || !preg_match('/^\d{1,3}$/', $max)) {
            return 'Mínimo y máximo tienen que ser números enteros.';
        }
        $min = (int) $min;
        $max = (int) $max;
        if ($min < 0 || $min > $max || $max > 100) {
            return "Rango inválido ({$min} a {$max}): tiene que cumplirse 0 ≤ mínimo ≤ máximo ≤ 100.";
        }

        $st = $pdo->prepare('SELECT minimo, maximo FROM sys_descuentos WHERE aseguradora = ? AND tipo_vehiculo = ?');
        $st->execute([$aseguradora, $tipo]);
        $antes = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($antes !== null && (int) $antes['minimo'] === $min && (int) $antes['maximo'] === $max) {
            return null; // Sin cambio: no se registra nada.
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO sys_descuentos (aseguradora, tipo_vehiculo, minimo, maximo, actualizado_por, actualizado_en)
                 VALUES (?,?,?,?,?, datetime('now','localtime'))
                 ON CONFLICT (aseguradora, tipo_vehiculo) DO UPDATE SET
                    minimo = excluded.minimo, maximo = excluded.maximo,
                    actualizado_por = excluded.actualizado_por, actualizado_en = excluded.actualizado_en"
            )->execute([$aseguradora, $tipo, $min, $max, $usuario]);

            $pdo->prepare(
                'INSERT INTO sys_descuentos_cambios (aseguradora, tipo_vehiculo, minimo_antes, maximo_antes, minimo, maximo, usuario_id, usuario)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $aseguradora, $tipo,
                $antes !== null ? (int) $antes['minimo'] : null,
                $antes !== null ? (int) $antes['maximo'] : null,
                $min, $max, $usuarioId, $usuario,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return null;
    }
}
