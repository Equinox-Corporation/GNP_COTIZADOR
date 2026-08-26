#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * importar_tablas_excel.php — Carga las dos tablas que NO existen en el API de GNP.
 *
 * Las dos salen únicamente del Excel del kit de conexión, ya convertidas a CSV:
 *
 *   datos/paquetes_gnp_matriz.csv   qué CVE_PAQUETE mandar según
 *                                   persona × paquete × procedencia × tipo de vehículo
 *   datos/coberturas_gnp.csv        qué coberturas trae cada paquete y cuáles se
 *                                   pueden agregar
 *
 * Ambas están verificadas contra respuestas reales del servicio: los conteos de
 * coberturas básicas (Amplia 9, Limitada 6, RC 5) coinciden con lo que devolvió
 * GNP el 21 de agosto.
 *
 * Idempotente. Respeta la columna "activo" que ajusta Comercial.
 *
 * Uso:  php app/scripts/importar_tablas_excel.php
 */

require __DIR__ . '/_arranque.php';

$pdo = Db::get();

// ── Matriz de paquetes ───────────────────────────────────────────────────────
$ruta = RUTA_BASE . '/datos/paquetes_gnp_matriz.csv';
if (!is_file($ruta)) {
    fwrite(STDERR, "Falta {$ruta}\n"); exit(1);
}

$orden = ['AMPLIA' => 1, 'AMPLIA TOTAL' => 2, 'LIMITADA' => 3, 'RESPONSABILIDAD CIVIL' => 4,
          'PREMIUM' => 5, 'AUTO ELITE' => 6, 'AMPLIA FLEXIBLE' => 7];

$st = $pdo->prepare(
    'INSERT INTO cat_paquetes (tipo_persona, paquete, procedencia, tipo_vehiculo, cve_paquete, disponible, orden)
     VALUES (?,?,?,?,?,?,?)
     ON CONFLICT (tipo_persona, paquete, procedencia, tipo_vehiculo) DO UPDATE SET
        cve_paquete = excluded.cve_paquete, disponible = excluded.disponible, orden = excluded.orden'
);

$fh = fopen($ruta, 'r');
fgetcsv($fh);
$n = $disp = 0;
$pdo->beginTransaction();
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) < 6) { continue; }
    [$tp, $pq, $pr, $tv, $cve, $d] = array_map('trim', $r);
    $pq = mb_strtoupper($pq, 'UTF-8');
    $st->execute([mb_strtoupper($tp, 'UTF-8'), $pq, $pr, mb_strtoupper($tv, 'UTF-8'),
                  $cve, (int) $d, $orden[$pq] ?? 99]);
    $n++; $disp += (int) $d;
}
$pdo->commit();
fclose($fh);

echo "Matriz de paquetes\n";
echo '   ' . pad('combinaciones', 22) . number_format($n) . "\n";
echo '   ' . pad('disponibles en GNP', 22) . number_format($disp) . "\n";
echo '   ' . pad('no ofrecidas', 22) . number_format($n - $disp) . "\n\n";

// ── Coberturas por paquete ───────────────────────────────────────────────────
$ruta = RUTA_BASE . '/datos/coberturas_gnp.csv';
if (!is_file($ruta)) {
    fwrite(STDERR, "Falta {$ruta}\n"); exit(1);
}

$st = $pdo->prepare(
    'INSERT INTO cat_coberturas (grupo, paquete, cve_cobertura, nombre, tipo, sa_valor, sa_unidad, ded_valor, ded_unidad)
     VALUES (?,?,?,?,?,?,?,?,?)
     ON CONFLICT (grupo, paquete, cve_cobertura) DO UPDATE SET
        nombre = excluded.nombre, tipo = excluded.tipo,
        sa_valor = excluded.sa_valor, sa_unidad = excluded.sa_unidad,
        ded_valor = excluded.ded_valor, ded_unidad = excluded.ded_unidad'
);

$fh = fopen($ruta, 'r');
fgetcsv($fh);
$n = 0;
$pdo->beginTransaction();
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) < 9) { continue; }
    $st->execute(array_map('trim', array_slice($r, 0, 9)));
    $n++;
}
$pdo->commit();
fclose($fh);

echo "Coberturas por paquete\n";
echo '   ' . pad('filas', 22) . number_format($n) . "\n\n";

foreach ($pdo->query(
    "SELECT grupo, paquete, tipo, COUNT(*) c FROM cat_coberturas GROUP BY 1,2,3 ORDER BY 1,2,3"
) as $f) {
    echo '   ' . pad($f['grupo'], 6) . pad($f['paquete'], 24) . pad($f['tipo'], 10) . $f['c'] . "\n";
}

echo "\n";
$d = CatalogoServicio::diagnostico();
echo CatalogoServicio::listoParaCotizar()
    ? "Listo para cotizar · {$d['vehiculos']} vehículos, {$d['paquetes']} paquetes.\n"
    : "Faltan vehículos: corre migrar_desde_nexo.php o etl_vehiculos.php\n";
