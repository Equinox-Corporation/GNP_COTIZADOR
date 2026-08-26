<?php
declare(strict_types=1);

/**
 * Env — lee la configuración de config/.env.local.
 *
 * Las credenciales de GNP viven aquí y NUNCA salen al navegador.
 */
final class Env
{
    private static bool $cargado = false;
    /** @var array<string,string> */
    private static array $valores = [];

    public static function cargar(string $ruta): void
    {
        if (self::$cargado) {
            return;
        }
        self::$cargado = true;

        if (!is_file($ruta)) {
            return;
        }

        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $linea, 2), 2, '');
            $k = trim($k);
            if ($k !== '') {
                self::$valores[$k] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }

    public static function get(string $clave, string $porOmision = ''): string
    {
        $v = self::$valores[$clave] ?? '';
        return $v !== '' ? $v : $porOmision;
    }

    public static function requerir(string $clave): string
    {
        $v = self::get($clave);
        if ($v === '') {
            throw new RuntimeException("Falta la variable {$clave} en config/.env.local");
        }
        return $v;
    }

    public static function esProduccion(): bool
    {
        return self::get('APP_ENV', 'local') === 'produccion';
    }
}
