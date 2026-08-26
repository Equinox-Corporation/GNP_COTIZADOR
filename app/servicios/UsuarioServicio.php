<?php
declare(strict_types=1);

/**
 * UsuarioServicio — administración de cuentas desde la pantalla.
 *
 * Mismas reglas que ya traía app/scripts/usuarios.php por línea de comandos,
 * más las que hacen falta al ser un panel web: no se puede dejar el sistema
 * sin al menos un administrador activo, para no quedar sin nadie que pueda
 * entrar aquí a arreglarlo.
 */
final class UsuarioServicio
{
    private const CLAVE_MINIMA = 8;

    /** @return list<array<string,mixed>> */
    public static function listar(): array
    {
        return Db::todos(
            'SELECT id, usuario, nombre, es_admin, activo, creado_en FROM sys_usuarios ORDER BY id'
        );
    }

    public static function obtener(int $id): ?array
    {
        return Db::uno('SELECT * FROM sys_usuarios WHERE id = ?', [$id]);
    }

    private static function existe(string $usuario): bool
    {
        return (int) Db::valor('SELECT COUNT(*) FROM sys_usuarios WHERE usuario = ?', [$usuario]) > 0;
    }

    private static function administradoresActivos(): int
    {
        return (int) Db::valor('SELECT COUNT(*) FROM sys_usuarios WHERE es_admin = 1 AND activo = 1');
    }

    /** @return string mensaje de error, o '' si quedó bien */
    public static function crear(string $usuario, string $nombre, string $clave, bool $esAdmin): string
    {
        $usuario = trim($usuario);
        if ($usuario === '' || strlen($clave) < self::CLAVE_MINIMA) {
            return 'El usuario no puede ir vacío y la contraseña necesita al menos ' . self::CLAVE_MINIMA . ' caracteres.';
        }
        if (self::existe($usuario)) {
            return "Ya existe el usuario \"{$usuario}\".";
        }
        Auth::crear($usuario, $nombre, $clave, $esAdmin);
        return '';
    }

    /** Cambia el nombre para mostrar y, si se manda, la contraseña. */
    public static function editar(int $id, string $nombre, string $claveNueva): string
    {
        if (self::obtener($id) === null) {
            return 'Ese usuario ya no existe.';
        }
        if ($claveNueva !== '' && strlen($claveNueva) < self::CLAVE_MINIMA) {
            return 'La contraseña necesita al menos ' . self::CLAVE_MINIMA . ' caracteres.';
        }

        Db::ejecutar('UPDATE sys_usuarios SET nombre = ? WHERE id = ?', [trim($nombre), $id]);
        if ($claveNueva !== '') {
            Db::ejecutar(
                'UPDATE sys_usuarios SET clave_hash = ? WHERE id = ?',
                [password_hash($claveNueva, PASSWORD_DEFAULT), $id]
            );
        }
        return '';
    }

    /** Enciende o apaga la cuenta. No deja apagar al último administrador activo ni tu propia sesión. */
    public static function alternarActivo(int $id): string
    {
        $u = self::obtener($id);
        if ($u === null) {
            return 'Ese usuario ya no existe.';
        }

        $vaAApagarse = (int) $u['activo'] === 1;
        if ($vaAApagarse && $id === Auth::id()) {
            return 'No puedes apagar tu propia cuenta mientras tienes la sesión abierta.';
        }
        if ($vaAApagarse && (int) $u['es_admin'] === 1 && self::administradoresActivos() <= 1) {
            return 'No puedes apagar al último administrador: nadie más podría entrar a este panel.';
        }

        Db::ejecutar('UPDATE sys_usuarios SET activo = ? WHERE id = ?', [$vaAApagarse ? 0 : 1, $id]);
        return '';
    }

    /** Da o quita el rol de administrador. No deja al sistema sin ninguno. */
    public static function alternarAdmin(int $id): string
    {
        $u = self::obtener($id);
        if ($u === null) {
            return 'Ese usuario ya no existe.';
        }

        $esAdminHoy = (int) $u['es_admin'] === 1;
        if ($esAdminHoy && self::administradoresActivos() <= 1) {
            return 'No puedes quitarle el rol al último administrador.';
        }

        Db::ejecutar('UPDATE sys_usuarios SET es_admin = ? WHERE id = ?', [$esAdminHoy ? 0 : 1, $id]);
        return '';
    }
}
