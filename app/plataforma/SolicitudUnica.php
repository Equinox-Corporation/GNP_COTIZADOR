<?php
declare(strict_types=1);

/**
 * SolicitudUnica — token de un solo uso por formulario (decisión de Albert,
 * 2026-09-28). Evita que un doble clic, un reenvío tras "atrás" o dos
 * pestañas con el mismo formulario repitan llamadas a la aseguradora: en
 * producción cada llamada cuenta.
 *
 * Cada vez que se muestra un formulario se emite un token nuevo, ligado al
 * usuario. Al recibir el envío, tomar() lo marca EN_CURSO de forma atómica
 * (un solo UPDATE condicionado): sólo la primera petición con ese token
 * llama; las demás reciben el motivo y no llaman.
 *
 * Estados: EMITIDO (formulario mostrado) → EN_CURSO → TERMINADA | FALLIDA,
 * o RECHAZADA si la captura no pasó la validación (no hubo llamada).
 *
 * Un token FALLIDA no permite reintentar: el reintento es abrir el formulario
 * otra vez, para que siempre sea una decisión consciente.
 *
 * Común a la plataforma; hoy sólo lo usa "Cotizar" de Qualitas. GNP no se
 * conecta a esto (decisión aparte, con su regresión).
 */
final class SolicitudUnica
{
    /** El formulario vale 2 horas desde que se muestra. */
    public const VIGENCIA_MINUTOS = 120;

    /** Una petición EN_CURSO de más de 10 minutos se da por interrumpida (60 s por llamada, hasta 3 paquetes). */
    public const EN_CURSO_MAX_MINUTOS = 10;

    /** Limpieza: tokens nunca usados, 1 día después de vencer; los demás, a los 30 días. */
    public const LIMPIAR_EMITIDOS_DIAS = 1;
    public const LIMPIAR_USADOS_DIAS = 30;

    public const OK         = 'OK';          // primera vez: se puede llamar
    public const TERMINADA  = 'TERMINADA';   // ya se envió y terminó bien
    public const FALLIDA    = 'FALLIDA';     // ya se envió y falló (error o red)
    public const RECHAZADA  = 'RECHAZADA';   // ya se envió y la captura no pasó (no hubo llamada)
    public const EN_CURSO   = 'EN_CURSO';    // la primera petición sigue en proceso
    public const INTERRUMPIDA = 'INTERRUMPIDA'; // quedó EN_CURSO más de lo razonable
    public const VENCIDO    = 'VENCIDO';     // el formulario venció sin usarse
    public const INVALIDO   = 'INVALIDO';    // no existe, es de otro usuario o de otra acción

    /** Emite un token nuevo para un formulario que se va a mostrar. Aprovecha para limpiar los viejos. */
    public static function emitir(PDO $pdo, ?int $usuarioId, string $aseguradora, string $accion): string
    {
        self::limpiar($pdo);
        $token = bin2hex(random_bytes(16));
        $pdo->prepare(
            "INSERT INTO sys_solicitudes (token, usuario_id, aseguradora, accion, estado, vence_en)
             VALUES (?, ?, ?, ?, 'EMITIDO', datetime('now','localtime', ?))"
        )->execute([$token, $usuarioId, strtoupper($aseguradora), $accion, '+' . self::VIGENCIA_MINUTOS . ' minutes']);
        return $token;
    }

    /**
     * Intenta usar el token. Sólo una petición puede recibir OK por token.
     *
     * @return array{resultado:string, cotizacion_id:?int}
     */
    public static function tomar(PDO $pdo, string $token, ?int $usuarioId, string $aseguradora, string $accion): array
    {
        $aseguradora = strtoupper($aseguradora);
        if (preg_match('/^[0-9a-f]{32}$/', $token)) {
            // Atómico: si dos peticiones llegan juntas, sólo una cambia la fila.
            $st = $pdo->prepare(
                "UPDATE sys_solicitudes SET estado = 'EN_CURSO', usada_en = datetime('now','localtime')
                  WHERE token = ? AND usuario_id IS ? AND aseguradora = ? AND accion = ?
                    AND estado = 'EMITIDO' AND vence_en > datetime('now','localtime')"
            );
            $st->execute([$token, $usuarioId, $aseguradora, $accion]);
            if ($st->rowCount() === 1) {
                return ['resultado' => self::OK, 'cotizacion_id' => null];
            }
        }

        $st = $pdo->prepare('SELECT * FROM sys_solicitudes WHERE token = ?');
        $st->execute([$token]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if ($f === false || $f['usuario_id'] !== $usuarioId || $f['aseguradora'] !== $aseguradora || $f['accion'] !== $accion) {
            return ['resultado' => self::INVALIDO, 'cotizacion_id' => null];
        }
        $cot = $f['cotizacion_id'] !== null ? (int) $f['cotizacion_id'] : null;

        $resultado = match ($f['estado']) {
            'EMITIDO'   => self::VENCIDO,
            'TERMINADA' => self::TERMINADA,
            'FALLIDA'   => self::FALLIDA,
            'RECHAZADA' => self::RECHAZADA,
            'EN_CURSO'  => self::enCursoDemasiado($pdo, $token) ? self::INTERRUMPIDA : self::EN_CURSO,
            default     => self::INVALIDO,
        };
        return ['resultado' => $resultado, 'cotizacion_id' => $cot];
    }

    /**
     * Cierra el token con el desenlace de la primera petición.
     *
     * @param 'TERMINADA'|'FALLIDA'|'RECHAZADA' $estado
     */
    public static function cerrar(PDO $pdo, string $token, string $estado, ?int $cotizacionId): void
    {
        if (!in_array($estado, [self::TERMINADA, self::FALLIDA, self::RECHAZADA], true)) {
            throw new InvalidArgumentException("Estado de cierre inválido: {$estado}");
        }
        $pdo->prepare("UPDATE sys_solicitudes SET estado = ?, cotizacion_id = ? WHERE token = ? AND estado = 'EN_CURSO'")
            ->execute([$estado, $cotizacionId, $token]);
    }

    /**
     * Borra tokens viejos. Sólo toca sys_solicitudes: las cotizaciones y su
     * bitácora no se tocan nunca.
     */
    public static function limpiar(PDO $pdo): int
    {
        $n = $pdo->exec(
            "DELETE FROM sys_solicitudes
              WHERE (estado = 'EMITIDO' AND vence_en < datetime('now','localtime', '-" . self::LIMPIAR_EMITIDOS_DIAS . " days'))
                 OR creada_en < datetime('now','localtime', '-" . self::LIMPIAR_USADOS_DIAS . " days')"
        );
        return (int) $n;
    }

    private static function enCursoDemasiado(PDO $pdo, string $token): bool
    {
        $st = $pdo->prepare(
            "SELECT usada_en < datetime('now','localtime', ?) FROM sys_solicitudes WHERE token = ?"
        );
        $st->execute(['-' . self::EN_CURSO_MAX_MINUTOS . ' minutes', $token]);
        return (bool) $st->fetchColumn();
    }
}
