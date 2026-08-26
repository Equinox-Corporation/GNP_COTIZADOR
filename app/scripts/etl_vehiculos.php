#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * etl_vehiculos.php — Descarga el catálogo VEHICULOS a la base propia.
 *
 * Cascada que desciende sola si GNP responde 504:
 *   Nivel 1  tipo + marca            ← una llamada por marca
 *   Nivel 2  tipo + marca + año
 *   Nivel 3  tipo + marca + línea
 *
 * En la corrida del 25 de agosto las 225 combinaciones se resolvieron en el
 * nivel 1: 47,542 renglones en 3.3 minutos, sin un solo 504. La cascada se
 * conserva como red de seguridad.
 *
 * Es reanudable: si se corta, se vuelve a correr y sigue donde quedó.
 *
 * Uso:
 *   php app/scripts/etl_vehiculos.php --probar=HO
 *   php app/scripts/etl_vehiculos.php --tipos=AUT,CA1,CA2,MOT
 *   php app/scripts/etl_vehiculos.php --resumen
 */

require __DIR__ . '/_arranque.php';

$args     = argumentos($argv);
$TIPOS_OK = ['AUT', 'CA1', 'CA2', 'MOT'];
$tipos    = array_values(array_filter(array_map('trim', explode(',', strtoupper($args['tipos'] ?? 'AUT')))));
$marcasAr = isset($args['armadoras'])
    ? array_values(array_filter(array_map('trim', explode(',', strtoupper($args['armadoras']))))) : [];
$desde    = (int) ($args['desde-anio'] ?? 2010);
$hasta    = (int) ($args['hasta-anio'] ?? ((int) date('Y') + 2));
$maxCarro = (int) ($args['max-carroceria'] ?? 60);
$rehacer  = isset($args['rehacer']);
$maxLlam  = (int) ($args['max-llamadas'] ?? 2000);
$pausa    = (int) ($args['pausa'] ?? 400);

if (isset($args['probar'])) { $marcasAr = [strtoupper((string) $args['probar'])]; $maxLlam = min($maxLlam, 60); }
if ($mal = array_diff($tipos, $TIPOS_OK)) { fwrite(STDERR, 'Tipo inválido: ' . implode(',', $mal) . "\n"); exit(1); }

$pdo = Db::get();

if (isset($args['resumen'])) {
    $t = (int) $pdo->query('SELECT COUNT(*) FROM cat_vehiculos')->fetchColumn();
    if ($t === 0) { echo "Todavía no hay vehículos.\n"; exit(0); }
    echo 'Vehículos: ' . number_format($t) . "\n" . str_repeat('═', 76) . "\n";
    echo pad('TIPO', 6) . pad('MARCA', 8) . pad('NOMBRE', 26) . pad('LÍNEAS', 8) . pad('CLAVES', 8) . "AÑOS\n";
    foreach ($pdo->query(
        'SELECT tipo_vehiculo, armadora, MAX(armadora_nombre) nom, COUNT(DISTINCT carroceria) li,
                COUNT(DISTINCT clavemarca) cl, MIN(modelo) a1, MAX(modelo) a2
           FROM cat_vehiculos GROUP BY 1,2 ORDER BY 1,3') as $f) {
        echo pad($f['tipo_vehiculo'], 6) . pad($f['armadora'], 8) . pad(mb_strimwidth($f['nom'], 0, 25), 26)
           . pad((string) $f['li'], 8) . pad((string) $f['cl'], 8) . "{$f['a1']}–{$f['a2']}\n";
    }
    foreach ($pdo->query("SELECT detalle, COUNT(*) n FROM cat_vehiculos_avance WHERE nivel='RESUELTA' GROUP BY 1") as $f) {
        echo "\n   resueltas por " . pad($f['detalle'], 14) . $f['n'];
    }
    echo "\n"; exit(0);
}

$gnp = cliente();
$llam = 0; $guard = 0; $avisos = [];

$insVeh = $pdo->prepare(
    'INSERT INTO cat_vehiculos (clavemarca, tipo_vehiculo, armadora, armadora_nombre, carroceria,
        carroceria_nombre, version, version_nombre, modelo, alto_valor, altisimo_valor)
     VALUES (:cm,:tv,:ar,:arn,:cc,:ccn,:ve,:ven,:mo,:av,:aav)
     ON CONFLICT (clavemarca, modelo) DO UPDATE SET
        armadora_nombre = excluded.armadora_nombre, carroceria_nombre = excluded.carroceria_nombre,
        version_nombre = excluded.version_nombre, descargado_en = datetime(\'now\',\'localtime\')'
);
$insAv = $pdo->prepare(
    'INSERT INTO cat_vehiculos_avance (tipo_vehiculo, armadora, nivel, detalle, estado, registros, ms, error_desc)
     VALUES (:tv,:ar,:ni,:de,:es,:re,:ms,:err)
     ON CONFLICT (tipo_vehiculo, armadora, nivel, detalle) DO UPDATE SET
        estado = excluded.estado, registros = excluded.registros, ms = excluded.ms,
        actualizado_en = datetime(\'now\',\'localtime\')'
);

$hecho = function (string $tv, string $ar, string $ni, string $de) use ($pdo, $rehacer): bool {
    if ($rehacer) { return false; }
    $s = $pdo->prepare("SELECT 1 FROM cat_vehiculos_avance WHERE tipo_vehiculo=? AND armadora=? AND nivel=? AND detalle=? AND estado IN ('OK','VACIO')");
    $s->execute([$tv, $ar, $ni, $de]);
    return (bool) $s->fetchColumn();
};
$resuelta = function (string $tv, string $ar) use ($pdo, $rehacer): ?string {
    if ($rehacer) { return null; }
    $s = $pdo->prepare("SELECT detalle FROM cat_vehiculos_avance WHERE tipo_vehiculo=? AND armadora=? AND nivel='RESUELTA' AND estado='OK'");
    $s->execute([$tv, $ar]);
    $d = $s->fetchColumn();
    return $d === false ? null : (string) $d;
};
$nivelOk = function (string $tv, string $ar, string $ni) use ($pdo): bool {
    $s = $pdo->prepare("SELECT 1 FROM cat_vehiculos_avance WHERE tipo_vehiculo=? AND armadora=? AND nivel=? AND estado='OK' LIMIT 1");
    $s->execute([$tv, $ar, $ni]);
    return (bool) $s->fetchColumn();
};

$registrar = function (array $r, string $tv, string $ar, string $ni, string $de)
             use ($pdo, $insVeh, $insAv, &$guard): int {
    $n = count($r['vehiculos'] ?? []);
    if ($n > 0) {
        $pdo->beginTransaction();
        foreach ($r['vehiculos'] as $v) {
            $insVeh->execute([':cm' => $v['clavemarca'], ':tv' => $v['tipo_vehiculo'], ':ar' => $v['armadora'],
                ':arn' => $v['armadora_nombre'], ':cc' => $v['carroceria'], ':ccn' => $v['carroceria_nombre'],
                ':ve' => $v['version'], ':ven' => $v['version_nombre'], ':mo' => $v['modelo'],
                ':av' => $v['alto_valor'], ':aav' => $v['altisimo_valor']]);
        }
        $pdo->commit();
        $guard += $n;
    }
    $estado = $r['estado'] === GnpClient::OK ? ($n > 0 ? 'OK' : 'VACIO')
            : ($r['estado'] === GnpClient::E_TIMEOUT ? 'TIMEOUT' : 'ERROR');
    $insAv->execute([':tv' => $tv, ':ar' => $ar, ':ni' => $ni, ':de' => $de, ':es' => $estado,
                     ':re' => $n, ':ms' => $r['ms'], ':err' => $r['error']['descripcion'] ?? null]);
    return $n;
};
$marcar = function (string $tv, string $ar, string $por, int $filas) use ($insAv): void {
    $insAv->execute([':tv' => $tv, ':ar' => $ar, ':ni' => 'RESUELTA', ':de' => $por,
                     ':es' => 'OK', ':re' => $filas, ':ms' => 0, ':err' => null]);
};
$abortar = function (array $r): void {
    if ($r['estado'] === GnpClient::E_AUTH) {
        echo "\nGNP rechazó las credenciales. Se detiene el barrido.\n"; exit(2);
    }
};

$marcasDe = function (string $tipo) use ($pdo, $gnp, $marcasAr, &$llam, &$avisos): array {
    if ($marcasAr !== []) { return array_map(fn ($a) => ['clave' => $a, 'nombre' => ''], $marcasAr); }
    $s = $pdo->prepare("SELECT clave, nombre FROM cat_catalogos WHERE tipo_catalogo='ARMADORA_VEHICULO' ORDER BY orden");
    $s->execute();
    if ($f = $s->fetchAll()) { return $f; }

    echo "Descargando la lista de armadoras…\n";
    $r = $gnp->catalogo('ARMADORA_VEHICULO', ['TIPO_VEHICULO' => $tipo]);
    $llam++;
    if ($r['estado'] !== GnpClient::OK) {
        $avisos[] = 'No pude obtener las armadoras: ' . ($r['error']['descripcion'] ?? $r['estado']);
        return [];
    }
    $i = $pdo->prepare("INSERT INTO cat_catalogos (tipo_catalogo, filtros, clave, nombre, valor, orden)
                        VALUES ('ARMADORA_VEHICULO','',?,?,?,?) ON CONFLICT DO NOTHING");
    $pdo->beginTransaction();
    foreach ($r['elementos'] as $k => $e) { $i->execute([$e['clave'], $e['nombre'], $e['valor'], $k]); }
    $pdo->commit();
    return array_map(fn ($e) => ['clave' => $e['clave'], 'nombre' => $e['nombre']], $r['elementos']);
};

echo 'ETL VEHICULOS · tipos ' . implode(',', $tipos) . " · años {$desde}–{$hasta}\n";
echo str_repeat('═', 78), "\n";
echo pad('MARCA', 8) . pad('NIVEL', 12) . pad('DETALLE', 10) . pad('ESTADO', 10) . pad('FILAS', 8) . "ms\n";
echo str_repeat('─', 78), "\n";

foreach ($tipos as $tipo) {
    $marcas = $marcasDe($tipo);
    if ($marcas === []) { $avisos[] = "Sin armadoras para {$tipo}."; continue; }
    echo "── {$tipo} · " . count($marcas) . " armadoras\n";

    foreach ($marcas as $m) {
        $ar = $m['clave'];
        if ($llam >= $maxLlam) { $avisos[] = "Freno: {$maxLlam} llamadas. Vuelve a correrlo."; break 2; }

        if (($por = $resuelta($tipo, $ar)) !== null) {
            echo pad($ar, 8) . pad('—', 12) . pad('—', 10) . pad('(hecha)', 10) . pad('', 8) . "por {$por}\n";
            continue;
        }

        $r = $gnp->vehiculos(['TIPO_VEHICULO' => $tipo, 'ARMADORA' => $ar]);
        $llam++; $abortar($r);
        $n = $registrar($r, $tipo, $ar, 'ARMADORA', '');
        echo pad($ar, 8) . pad('ARMADORA', 12) . pad(mb_strimwidth($m['nombre'], 0, 9), 10)
           . pad($r['estado'], 10) . pad((string) $n, 8) . $r['ms'] . "\n";
        if ($pausa) { usleep($pausa * 1000); }

        if ($r['estado'] === GnpClient::OK) { $marcar($tipo, $ar, 'ARMADORA', $n); continue; }
        if ($r['estado'] !== GnpClient::E_TIMEOUT) {
            $avisos[] = "{$tipo}/{$ar}: " . ($r['error']['descripcion'] ?? $r['estado']); continue;
        }

        echo "        ↓ 504 · bajando a nivel MODELO\n";
        $sirve = null; $f2 = 0;
        for ($a = $desde; $a <= $hasta; $a++) {
            if ($llam >= $maxLlam || $hecho($tipo, $ar, 'MODELO', (string) $a)) { continue; }
            $r2 = $gnp->vehiculos(['TIPO_VEHICULO' => $tipo, 'ARMADORA' => $ar, 'MODELO' => (string) $a]);
            $llam++; $abortar($r2);
            if ($sirve === null) {
                $sirve = $r2['estado'] === GnpClient::OK;
                if (!$sirve) {
                    echo "        ↓ MODELO no acotó ({$r2['estado']}) · bajando a CARROCERIA\n";
                    $avisos[] = "{$tipo}/{$ar}: MODELO no sirve como filtro; se usó CARROCERIA.";
                    $registrar($r2, $tipo, $ar, 'MODELO', (string) $a);
                    break;
                }
            }
            $f2 += $registrar($r2, $tipo, $ar, 'MODELO', (string) $a);
            if ($pausa) { usleep($pausa * 1000); }
        }
        if ($sirve === null && $nivelOk($tipo, $ar, 'MODELO')) { $sirve = true; }
        if ($sirve === true) { echo "        ✓ resuelto por MODELO · {$f2} filas\n"; $marcar($tipo, $ar, 'MODELO', $f2); continue; }

        $vacios = 0; $f3 = 0;
        for ($c = 1; $c <= $maxCarro; $c++) {
            if ($llam >= $maxLlam) { break; }
            $cc = str_pad((string) $c, 2, '0', STR_PAD_LEFT);
            if ($hecho($tipo, $ar, 'CARROCERIA', $cc)) { continue; }
            $r3 = $gnp->vehiculos(['TIPO_VEHICULO' => $tipo, 'ARMADORA' => $ar, 'CARROCERIA' => $cc]);
            $llam++; $abortar($r3);
            $n3 = $registrar($r3, $tipo, $ar, 'CARROCERIA', $cc);
            $f3 += $n3;
            if ($n3 > 0) { $vacios = 0; echo pad('', 8) . pad('CARROCERIA', 12) . pad($cc, 10) . pad($r3['estado'], 10) . pad((string) $n3, 8) . $r3['ms'] . "\n"; }
            elseif (++$vacios >= 15) { break; }
            if ($pausa) { usleep($pausa * 1000); }
        }
        echo "        ✓ resuelto por CARROCERIA · {$f3} filas\n";
        if ($f3 > 0 || $nivelOk($tipo, $ar, 'CARROCERIA')) { $marcar($tipo, $ar, 'CARROCERIA', $f3); }
        else { $avisos[] = "{$tipo}/{$ar}: ningún nivel funcionó. Requiere revisión."; }
    }
}

echo str_repeat('═', 78), "\n";
echo 'Llamadas: ' . $llam . ' · filas guardadas: ' . number_format($guard) . "\n";
echo 'Total en la base: ' . number_format((int) $pdo->query('SELECT COUNT(*) FROM cat_vehiculos')->fetchColumn()) . "\n";
if ($avisos !== []) {
    echo "\nAvisos (" . count($avisos) . "):\n";
    foreach (array_unique($avisos) as $a) { echo "   · {$a}\n"; }
}
echo "\nDetalle:  php app/scripts/etl_vehiculos.php --resumen\n";
exit($avisos === [] ? 0 : 1);
