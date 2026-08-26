#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * usuarios.php — Administra las cuentas del cotizador desde la línea de comandos.
 *
 * Uso:
 *   php app\scripts\usuarios.php --listar
 *   php app\scripts\usuarios.php --renombrar=beto --nombre="Alberto García"
 *   php app\scripts\usuarios.php --crear=maria --nombre="María López" --clave=miclave123
 *   php app\scripts\usuarios.php --clave=beto --nueva=otraclave123
 *   php app\scripts\usuarios.php --apagar=maria
 *   php app\scripts\usuarios.php --prender=maria
 *
 * Las contraseñas se guardan cifradas: nunca quedan legibles en la base.
 */

require __DIR__ . '/_arranque.php';
require RUTA_APP . '/core/Auth.php';

$args = argumentos($argv);
$pdo  = Db::get();

$listar = static function () use ($pdo): void {
    $filas = $pdo->query('SELECT id, usuario, nombre, activo, creado_en FROM sys_usuarios ORDER BY id')->fetchAll();
    if ($filas === []) {
        echo "No hay usuarios. Abre la página en el navegador para crear el primero,\n";
        echo "o usa --crear=usuario --nombre=\"Nombre\" --clave=…\n";
        return;
    }
    echo pad('ID', 5) . pad('USUARIO', 18) . pad('NOMBRE', 30) . pad('ESTADO', 10) . "ALTA\n";
    echo str_repeat('─', 82), "\n";
    foreach ($filas as $f) {
        echo pad((string) $f['id'], 5) . pad($f['usuario'], 18) . pad($f['nombre'] ?: '—', 30)
           . pad((int) $f['activo'] === 1 ? 'activo' : 'apagado', 10) . $f['creado_en'] . "\n";
    }
};

$existe = static function (string $u) use ($pdo): bool {
    $s = $pdo->prepare('SELECT 1 FROM sys_usuarios WHERE usuario = ?');
    $s->execute([$u]);
    return (bool) $s->fetchColumn();
};

// ── Listar ───────────────────────────────────────────────────────────────────
if (isset($args['listar']) || $args === []) {
    $listar();
    exit(0);
}

// ── Cambiar el nombre para mostrar ───────────────────────────────────────────
if (isset($args['renombrar'])) {
    $u = trim((string) $args['renombrar']);
    $n = trim((string) ($args['nombre'] ?? ''));

    if ($n === '') {
        fwrite(STDERR, "Falta el nombre nuevo. Ejemplo:\n");
        fwrite(STDERR, "   php app\\scripts\\usuarios.php --renombrar={$u} --nombre=\"Alberto García\"\n");
        exit(1);
    }
    if (!$existe($u)) {
        fwrite(STDERR, "No existe el usuario \"{$u}\".\n\n");
        $listar();
        exit(1);
    }

    $s = $pdo->prepare('UPDATE sys_usuarios SET nombre = ? WHERE usuario = ?');
    $s->execute([$n, $u]);

    echo "Listo. \"{$u}\" ahora se muestra como \"{$n}\".\n";
    echo "Cierra sesión y vuelve a entrar para verlo en la barra superior.\n";
    exit(0);
}

// ── Crear ────────────────────────────────────────────────────────────────────
if (isset($args['crear'])) {
    $u = trim((string) $args['crear']);
    $n = trim((string) ($args['nombre'] ?? ''));
    $c = (string) ($args['clave'] ?? '');

    if ($u === '' || strlen($c) < 8) {
        fwrite(STDERR, "Hace falta el usuario y una contraseña de al menos 8 caracteres.\n");
        exit(1);
    }
    if ($existe($u)) {
        fwrite(STDERR, "Ya existe el usuario \"{$u}\".\n");
        exit(1);
    }

    Auth::crear($u, $n, $c);
    echo "Usuario \"{$u}\" creado.\n";
    exit(0);
}

// ── Cambiar contraseña ───────────────────────────────────────────────────────
if (isset($args['clave']) && isset($args['nueva'])) {
    $u = trim((string) $args['clave']);
    $c = (string) $args['nueva'];

    if (strlen($c) < 8) { fwrite(STDERR, "La contraseña necesita al menos 8 caracteres.\n"); exit(1); }
    if (!$existe($u))   { fwrite(STDERR, "No existe el usuario \"{$u}\".\n"); exit(1); }

    $s = $pdo->prepare('UPDATE sys_usuarios SET clave_hash = ? WHERE usuario = ?');
    $s->execute([password_hash($c, PASSWORD_DEFAULT), $u]);

    echo "Contraseña de \"{$u}\" actualizada.\n";
    exit(0);
}

// ── Apagar / prender ─────────────────────────────────────────────────────────
foreach ([['apagar', 0, 'apagado'], ['prender', 1, 'activo']] as [$op, $valor, $texto]) {
    if (isset($args[$op])) {
        $u = trim((string) $args[$op]);
        if (!$existe($u)) { fwrite(STDERR, "No existe el usuario \"{$u}\".\n"); exit(1); }
        $s = $pdo->prepare('UPDATE sys_usuarios SET activo = ? WHERE usuario = ?');
        $s->execute([$valor, $u]);
        echo "Usuario \"{$u}\" queda {$texto}.\n";
        exit(0);
    }
}

fwrite(STDERR, "No entendí la orden. Las opciones están arriba del archivo.\n");
exit(1);
