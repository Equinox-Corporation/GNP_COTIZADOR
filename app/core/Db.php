<?php
declare(strict_types=1);

/**
 * Db — la base SQLite propia del cotizador.
 *
 * Un solo archivo, sin servidor, versionable. Vive en datos/ y NO es alcanzable
 * desde el navegador: la raíz pública es public/, la base está un nivel arriba.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $ruta = Env::get('DB_PATH', RUTA_BASE . '/datos/cotizador_gnp.sqlite');
        $dir  = dirname($ruta);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        self::$pdo = new PDO('sqlite:' . $ruta, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        self::$pdo->exec("PRAGMA encoding = 'UTF-8'");
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo->exec('PRAGMA busy_timeout = 5000');

        Esquema::asegurar(self::$pdo);

        return self::$pdo;
    }

    /** @return list<array<string,mixed>> */
    public static function todos(string $sql, array $params = []): array
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function uno(string $sql, array $params = []): ?array
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        $f = $st->fetch();
        return $f === false ? null : $f;
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function ejecutar(string $sql, array $params = []): int
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function ultimoId(): int
    {
        return (int) self::get()->lastInsertId();
    }
}
