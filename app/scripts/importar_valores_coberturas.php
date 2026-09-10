#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * importar_valores_coberturas.php — Carga el menú real de valores permitidos
 * por cobertura (módulo Juega y Compara).
 *
 * `cat_coberturas` sólo trae el valor por omisión de cada cobertura dentro de
 * un paquete — no el menú completo que GNP maneja. Eso quedó confirmado al
 * escribir la Tarea A del módulo (ver ADR-007 punto 1 y
 * docs/02.6-coberturas-modificadas.md). Esta semilla, extraída del kit, sí
 * trae el menú: 225 filas, una por valor permitido de cada cobertura.
 *
 * Fuente: docs/Auxiliares/cat_cobertura_valores_seed.csv
 *   cve_cobertura, nombre_cobertura, aplica_a (AUT_CA1_CA2 | MOT),
 *   tipo_valor (SUMA_ASEGURADA | DEDUCIBLE), valor
 *
 * `aplica_a` se traduce a `grupo` con el mismo criterio que ya usa
 * CatalogoServicio::grupo(): AUT/CA1/CA2 son AUTO, MOT es MOTO.
 *
 * Idempotente: se puede correr las veces que haga falta.
 *
 * Uso:  php app/scripts/importar_valores_coberturas.php
 */

require __DIR__ . '/_arranque.php';

$ruta = RUTA_BASE . '/docs/Auxiliares/cat_cobertura_valores_seed.csv';
if (!is_file($ruta)) {
    fwrite(STDERR, "Falta {$ruta}\n");
    exit(1);
}

$grupoDe = static fn (string $aplicaA): string => $aplicaA === 'MOT' ? 'MOTO' : 'AUTO';

$pdo = Db::get();
$st = $pdo->prepare(
    'INSERT INTO cat_cobertura_valores (grupo, cve_cobertura, tipo_valor, valor, orden)
     VALUES (?,?,?,?,?)
     ON CONFLICT (grupo, cve_cobertura, tipo_valor, valor) DO UPDATE SET orden = excluded.orden'
);

$fh = fopen($ruta, 'r');
$encabezado = fgetcsv($fh);
if ($encabezado === false || array_slice($encabezado, 0, 5) !== ['cve_cobertura', 'nombre_cobertura', 'aplica_a', 'tipo_valor', 'valor']) {
    fwrite(STDERR, "El CSV no tiene el encabezado esperado (cve_cobertura,nombre_cobertura,aplica_a,tipo_valor,valor).\n");
    exit(1);
}

$n = 0;
$ordenPorGrupo = []; // orden consecutivo dentro de cada (grupo, cve_cobertura, tipo_valor)
$pdo->beginTransaction();
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) < 5) {
        continue;
    }
    [$cve, , $aplicaA, $tipoValor, $valor] = array_map('trim', $r);
    $grupo = $grupoDe($aplicaA);
    $clave = $grupo . '|' . $cve . '|' . $tipoValor;
    $ordenPorGrupo[$clave] = ($ordenPorGrupo[$clave] ?? 0) + 1;

    $st->execute([$grupo, $cve, $tipoValor, $valor, $ordenPorGrupo[$clave]]);
    $n++;
}
$pdo->commit();
fclose($fh);

echo "Valores permitidos por cobertura\n";
echo '   ' . pad('filas leídas', 22) . number_format($n) . "\n";
echo '   ' . pad('coberturas distintas', 22) . number_format((int) Db::valor('SELECT COUNT(DISTINCT cve_cobertura) FROM cat_cobertura_valores')) . "\n\n";

foreach ($pdo->query(
    "SELECT grupo, tipo_valor, COUNT(*) c FROM cat_cobertura_valores GROUP BY 1,2 ORDER BY 1,2"
) as $f) {
    echo '   ' . pad($f['grupo'], 8) . pad($f['tipo_valor'], 18) . $f['c'] . "\n";
}

// Aviso si alguna cobertura de cat_coberturas se quedó sin valores cargados:
// en ese caso la pantalla de plantillas cae de vuelta a texto libre para ella.
$sinValores = Db::todos(
    "SELECT DISTINCT c.grupo, c.cve_cobertura, c.nombre
       FROM cat_coberturas c
      WHERE NOT EXISTS (
            SELECT 1 FROM cat_cobertura_valores v
             WHERE v.grupo = c.grupo AND v.cve_cobertura = c.cve_cobertura
      )
      ORDER BY c.grupo, c.nombre"
);
if ($sinValores !== []) {
    echo "\nSin valores cargados (quedan como texto libre en la pantalla):\n";
    foreach ($sinValores as $s) {
        echo '   ' . pad($s['grupo'], 6) . $s['cve_cobertura'] . ' · ' . $s['nombre'] . "\n";
    }
} else {
    echo "\nTodas las coberturas de cat_coberturas tienen valores cargados.\n";
}
