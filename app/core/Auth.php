<?php
declare(strict_types=1);

/**
 * Auth — login sencillo para la etapa interna.
 *
 * Deliberadamente simple: usuario y contraseña contra la tabla sys_usuarios.
 * Está aislado para que, cuando llegue la versión pública, se pueda dejar
 * abierta la pantalla de cotizar sin tocar nada más.
 */
final class Auth
{
    public static function iniciarSesion(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
            session_start();
        }
    }

    public static function entrar(string $usuario, string $clave): bool
    {
        self::iniciarSesion();

        $u = Db::uno('SELECT * FROM sys_usuarios WHERE usuario = ? AND activo = 1', [trim($usuario)]);

        // Se verifica siempre, exista o no el usuario, para no delatar cuáles existen.
        $hash = $u['clave_hash'] ?? '$2y$12$notarealhashnotarealhashno.tarealhashnotarealhashnotare';
        if (!password_verify($clave, $hash) || $u === null) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id']     = (int) $u['id'];
        $_SESSION['usuario_nombre'] = $u['nombre'] !== '' ? $u['nombre'] : $u['usuario'];
        $_SESSION['usuario_admin']  = (int) $u['es_admin'] === 1;
        return true;
    }

    public static function salir(): void
    {
        self::iniciarSesion();
        $_SESSION = [];
        session_destroy();
    }

    public static function id(): ?int
    {
        self::iniciarSesion();
        return isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
    }

    public static function nombre(): string
    {
        self::iniciarSesion();
        return (string) ($_SESSION['usuario_nombre'] ?? '');
    }

    public static function dentro(): bool
    {
        return self::id() !== null;
    }

    /** Corta la ejecución si no hay sesión. */
    public static function exigir(): void
    {
        if (!self::dentro()) {
            header('Location: ' . BASE_URL . '/?r=login');
            exit;
        }
    }

    public static function esAdmin(): bool
    {
        self::iniciarSesion();
        return (bool) ($_SESSION['usuario_admin'] ?? false);
    }

    /** Corta la ejecución si no hay sesión o si la sesión no es de administrador. */
    public static function exigirAdmin(): void
    {
        self::exigir();
        if (!self::esAdmin()) {
            http_response_code(403);
            exit('No tienes permiso para entrar aquí: hace falta el rol de administrador.');
        }
    }

    /** ¿Ya hay alguien dado de alta? Si no, se muestra el alta inicial. */
    public static function hayUsuarios(): bool
    {
        return (int) Db::valor('SELECT COUNT(*) FROM sys_usuarios') > 0;
    }

    public static function crear(string $usuario, string $nombre, string $clave, bool $esAdmin = false): void
    {
        Db::ejecutar(
            'INSERT INTO sys_usuarios (usuario, nombre, clave_hash, es_admin) VALUES (?,?,?,?)',
            [trim($usuario), trim($nombre), password_hash($clave, PASSWORD_DEFAULT), $esAdmin ? 1 : 0]
        );
    }

    // ── Protección del formulario contra envíos desde otro sitio ────────────

    public static function token(): string
    {
        self::iniciarSesion();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function tokenValido(?string $t): bool
    {
        self::iniciarSesion();
        return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
    }
}
