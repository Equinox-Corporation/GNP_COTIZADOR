#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * migrar_desde_nexo.php — Trae a la base propia lo que ya se descargó en Nexo.
 *
 * El barrido de vehículos ya se hizo una vez (47,542 renglones, 225 llamadas).
 * No tiene sentido volver a pedírselo a GNP: se copia del staging de Nexo.
 *
 * Uso:
 *   php app/scripts/migrar_desde_nexo.php
 *   php app/scripts/migrar_desde_nexo.php "C:\xampp\htdocs\nexo\proyectos\nexo\app\core\staging\staging.sqlite"
 *
 * Es seguro correrlo más de una vez: no duplica.
 * Sólo LEE el archivo de Nexo; nunca lo modifica.
 */

require __DIR__ . '/_arranque.php';

$args   = argumentos($argv);
$origen = $args[0] ?? Env::get('NEXO_STAGING_PATH',
          'C:\\xampp\\htdocs\\nexo\\proyectos\\nexo\\app\\core\\staging\\staging.sqlite');

if (!is_file($origen)) {
    fwrite(STDERR, "No encuentro el staging de Nexo en:\n   {$origen}\n\n");
    fwrite(STDERR, "Pásalo como argumento o define NEXO_STAGING_PATH en config/.env.local\n");
    exit(1);
}

echo "Origen : {$origen}\n";
echo "Destino: " . Env::get('DB_PATH', RUTA_BASE . '/datos/cotizador_gnp.sqlite') . "\n";
echo str_repeat('═', 66), "\n";

$pdo = Db::get();
// Se adjunta en SÓLO LECTURA: imposible tocar la base de Nexo por accidente.
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $origen) . "?mode=ro' AS nexo");

$copiar = static function (string $sql, string $etiqueta) use ($pdo): int {
    try {
        $pdo->beginTransaction();
        $n = $pdo->exec($sql);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo '   ' . pad($etiqueta, 26) . "no se pudo: " . $e->getMessage() . "\n";
        return 0;
    }
    echo '   ' . pad($etiqueta, 26) . number_format((int) $n) . "\n";
    return (int) $n;
};

$copiar(
    "INSERT INTO cat_vehiculos
        (clavemarca, tipo_vehiculo, armadora, armadora_nombre, carroceria, carroceria_nombre,
         version, version_nombre, modelo, alto_valor, altisimo_valor)
     SELECT clavemarca, tipo_vehiculo, armadora, armadora_nombre, carroceria, carroceria_nombre,
            version, version_nombre, modelo, alto_valor, altisimo_valor
       FROM nexo.gnp_vehiculos
     WHERE true
        ON CONFLICT (clavemarca, modelo) DO UPDATE SET
           carroceria_nombre = excluded.carroceria_nombre,
           version_nombre    = excluded.version_nombre",
    'vehículos'
);

$copiar(
    "INSERT INTO cat_catalogos (tipo_catalogo, filtros, clave, nombre, valor, orden)
     SELECT tipo_catalogo, filtros, clave, nombre, valor, orden FROM nexo.gnp_catalogos
     WHERE true
        ON CONFLICT (tipo_catalogo, filtros, clave) DO UPDATE SET nombre = excluded.nombre",
    'catálogos planos'
);

$copiar(
    "INSERT INTO cat_vehiculos_avance (tipo_vehiculo, armadora, nivel, detalle, estado, registros, ms, error_desc)
     SELECT tipo_vehiculo, armadora, nivel, detalle, estado, registros, ms, error_desc
       FROM nexo.gnp_vehiculos_avance
     WHERE true
        ON CONFLICT (tipo_vehiculo, armadora, nivel, detalle) DO UPDATE SET estado = excluded.estado",
    'avance del barrido'
);

// La matriz de paquetes puede estar vacía en Nexo; si trae algo, se aprovecha.
$hay = (int) $pdo->query("SELECT COUNT(*) FROM nexo.gnp_paquetes_matriz")->fetchColumn();
if ($hay > 0) {
    $copiar(
        "INSERT INTO cat_paquetes (tipo_persona, paquete, procedencia, tipo_vehiculo, cve_paquete, disponible)
         SELECT tipo_persona, paquete, procedencia, tipo_vehiculo, cve_paquete, disponible
           FROM nexo.gnp_paquetes_matriz
         WHERE true
            ON CONFLICT (tipo_persona, paquete, procedencia, tipo_vehiculo) DO UPDATE SET
               cve_paquete = excluded.cve_paquete, disponible = excluded.disponible",
        'matriz de paquetes'
    );
} else {
    echo '   ' . pad('matriz de paquetes', 26) . "vacía en Nexo · usa importar_tablas_excel.php\n";
}

$pdo->exec('DETACH DATABASE nexo');

echo str_repeat('═', 66), "\n";
$d = CatalogoServicio::diagnostico();
foreach ($d as $k => $v) {
    echo '   ' . pad($k, 16) . number_format($v) . "\n";
}
echo "\n";
echo CatalogoServicio::listoParaCotizar()
    ? "Listo para cotizar.\n"
    : "Todavía faltan paquetes: corre importar_tablas_excel.php\n";
