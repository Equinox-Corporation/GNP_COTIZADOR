#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * limpiar_cat_comercial.php — Corrige datos rotos en cat_comercial.db
 * detectados al construir la cascada de homologación con GNP:
 *
 *   A) M105 "X-TRAIL" es un modelo de Nissan mal cargado como marca.
 *      Nissan (M001) ya tiene sus propias submarcas X-TRAIL (2012-2027).
 *      Se fusiona M105 -> M001.
 *
 *   B) M094 "TESLA MOTORS" es un duplicado de M093 "TESLA" (que además es
 *      como GNP la nombra). Se fusiona M094 -> M093, conservando "TESLA".
 *
 *   C) M061 "LINK&CO" es un duplicado de M063 "LYNK CO". Se fusiona
 *      M061 -> M063.
 *
 *   E) Dentro de una misma marca, el mismo vehículo puede estar cargado dos
 *      veces con distinta grafía ("300 C" / "300C", "TT" / "T T"). Se
 *      agrupan por (marca, nombre sin espacios ni signos, año); el grupo
 *      con más de un registro se fusiona: sobrevive el que ya tenga
 *      IDmarca_gnp (si hay empate o ninguno lo tiene, el de id menor). La
 *      grafía descartada NO se pierde: se guarda en `submarca_alias` para
 *      que la búsqueda futura por ese nombre siga encontrando el registro.
 *
 * En cada fusión (A, B, C, E): las submarcas de la marca hija que NO tengan ya un
 * equivalente (mismo nombre normalizado + mismo año) bajo la marca padre
 * se reasignan; las que sí, se descartan por duplicadas. Si alguna fila
 * descartada tenía un renglón en homologacion_gnp, se migra al
 * sobreviviente (o se avisa fuerte si hay choque con una confirmación
 * manual). Al final se borra la marca hija (ya sin submarcas que la
 * referencien).
 *
 * Antes de escribir nada se hace un respaldo aparte de cat_comercial.db
 * (no toca cat_comercial.db.bak_pre_cascada, que ya existe de una corrida
 * anterior).
 *
 * Uso:
 *   php app/scripts/limpiar_cat_comercial.php
 *   php app/scripts/limpiar_cat_comercial.php "ruta\cat_comercial.db"
 *
 * Idempotente: si se vuelve a correr después de una fusión ya hecha, la
 * marca hija ya no existe y ese paso se omite sin error.
 */

require __DIR__ . '/_arranque.php';

$args = argumentos($argv);
$posicionales = array_values(array_filter($args, static fn($k) => is_int($k), ARRAY_FILTER_USE_KEY));
$rutaComercial = $posicionales[0] ?? RUTA_APP . '/core/cat_comercial.db';

if (!is_file($rutaComercial)) {
    fwrite(STDERR, "No encuentro cat_comercial.db en:\n   {$rutaComercial}\n");
    exit(1);
}

$rutaBackup = $rutaComercial . '.bak_pre_limpieza_' . date('Ymd_His');
copy($rutaComercial, $rutaBackup);
echo "Respaldo creado: {$rutaBackup}\n";
echo str_repeat('═', 70), "\n";

$pdo = new PDO('sqlite:' . $rutaComercial, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("PRAGMA encoding = 'UTF-8'");
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS submarca_alias (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        IDsubmarca     TEXT NOT NULL,
        nombre_alterno TEXT NOT NULL,
        origen         TEXT NOT NULL,
        creado_en      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (IDsubmarca) REFERENCES submarcas(id)
    )
");

function normaliza(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U', 'Ç' => 'C',
    ]);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string) $s);
}

// Comparación laxa para detectar duplicados: "X-TRAIL"/"X TRAIL", "UP"/"UP!",
// "A-200"/"A_200" son el mismo vehículo aunque cambie el signo de puntuación.
// Se quita CUALQUIER carácter que no sea letra o número, no solo guiones.
function claveComparable(string $nombre, string $modelo): string
{
    $n = normaliza($nombre);
    $n = preg_replace('/[^A-Z0-9]/', '', $n);
    return $n . '|' . $modelo;
}

/**
 * Fusiona la marca $idHija dentro de $idPadre. Reasigna lo que no está
 * duplicado, descarta lo que sí, migra homologacion_gnp cuando aplica,
 * y borra la marca hija al final.
 */
function fusionarMarca(PDO $pdo, string $idHija, string $idPadre, string $etiqueta): array
{
    $stats = ['reasignadas' => 0, 'descartadas_duplicadas' => 0, 'homologacion_migrada' => 0, 'confirmaciones_en_conflicto' => []];

    $existeHija = (bool) $pdo->query("SELECT 1 FROM marcas WHERE id = " . $pdo->quote($idHija))->fetchColumn();
    if (!$existeHija) {
        echo "-- {$etiqueta}: {$idHija} ya no existe, se omite (ya corrida antes) --\n";
        return $stats;
    }

    echo "-- {$etiqueta} ({$idHija} -> {$idPadre}) --\n";

    // Índice de lo que YA existe bajo el padre: nombreNormalizado|modelo => IDsubmarca
    $idxPadre = [];
    foreach ($pdo->query('SELECT id, nombre, modelo FROM submarcas WHERE IDmarcacomercial = ' . $pdo->quote($idPadre)) as $r) {
        $idxPadre[claveComparable($r['nombre'], $r['modelo'])] = $r['id'];
    }

    $hijas = $pdo->query('SELECT id, nombre, modelo FROM submarcas WHERE IDmarcacomercial = ' . $pdo->quote($idHija))->fetchAll();

    $reasignar = $pdo->prepare('UPDATE submarcas SET IDmarcacomercial = :padre WHERE id = :id');
    $borrarSub = $pdo->prepare('DELETE FROM submarcas WHERE id = :id');
    $migrarHomologacion = $pdo->prepare('UPDATE homologacion_gnp SET IDsubmarca = :padreId WHERE IDsubmarca = :hijaId');
    $borrarHomologacion = $pdo->prepare('DELETE FROM homologacion_gnp WHERE IDsubmarca = :id');

    foreach ($hijas as $h) {
        $clave = claveComparable($h['nombre'], $h['modelo']);

        if (!isset($idxPadre[$clave])) {
            // No hay equivalente bajo el padre: se reasigna tal cual.
            $reasignar->execute([':padre' => $idPadre, ':id' => $h['id']]);
            $stats['reasignadas']++;
            echo "   reasignada  {$h['id']} \"{$h['nombre']}\" {$h['modelo']}\n";
            continue;
        }

        // Ya existe un equivalente bajo el padre: la de la hija se descarta.
        $idPadreEquiv = $idxPadre[$clave];
        $homHija  = $pdo->query('SELECT * FROM homologacion_gnp WHERE IDsubmarca = ' . $pdo->quote($h['id']))->fetch();
        $homPadre = $pdo->query('SELECT * FROM homologacion_gnp WHERE IDsubmarca = ' . $pdo->quote($idPadreEquiv))->fetch();

        if ($homHija !== false) {
            if ($homPadre === false) {
                $migrarHomologacion->execute([':padreId' => $idPadreEquiv, ':hijaId' => $h['id']]);
                $stats['homologacion_migrada']++;
                echo "   duplicada   {$h['id']} \"{$h['nombre']}\" {$h['modelo']}  (homologacion_gnp migrada a {$idPadreEquiv})\n";
            } else {
                if (!empty($homHija['confirmado_por']) && empty($homPadre['confirmado_por'])) {
                    $stats['confirmaciones_en_conflicto'][] = "{$h['id']} (confirmado por {$homHija['confirmado_por']}) choca con {$idPadreEquiv} (sin confirmar) — revisar a mano";
                    echo "   AVISO: {$h['id']} tenía confirmado_por={$homHija['confirmado_por']} y se va a descartar; revisar a mano.\n";
                }
                $borrarHomologacion->execute([':id' => $h['id']]);
                echo "   duplicada   {$h['id']} \"{$h['nombre']}\" {$h['modelo']}  (ya existía homologacion_gnp en {$idPadreEquiv}, se descarta la de la hija)\n";
            }
        } else {
            echo "   duplicada   {$h['id']} \"{$h['nombre']}\" {$h['modelo']}  (== {$idPadreEquiv})\n";
        }

        $borrarSub->execute([':id' => $h['id']]);
        $stats['descartadas_duplicadas']++;
    }

    $pdo->exec('DELETE FROM marcas WHERE id = ' . $pdo->quote($idHija));
    echo "   marca {$idHija} eliminada\n";
    echo "   Total: reasignadas={$stats['reasignadas']}  descartadas={$stats['descartadas_duplicadas']}  homologacion migrada={$stats['homologacion_migrada']}\n\n";

    return $stats;
}

/**
 * E) Fusiona submarcas gemelas dentro de la MISMA marca: mismo nombre sin
 * espacios ni signos, mismo año. Sobrevive la que ya tenga IDmarca_gnp
 * (empate o ninguna -> id menor); las demás se guardan en submarca_alias
 * antes de borrarlas, para no perder la grafía.
 * @return array{0: array, 1: list<string>} [stats, muestra de hasta 10 decisiones]
 */
function fusionarGemelas(PDO $pdo, array $nombresMarcas): array
{
    $stats = ['grupos' => 0, 'eliminadas' => 0, 'homologacion_migrada' => 0, 'confirmaciones_en_conflicto' => []];
    $muestra = [];

    $grupos = [];
    foreach ($pdo->query('SELECT id, nombre, modelo, IDmarcacomercial FROM submarcas') as $f) {
        $clave = $f['IDmarcacomercial'] . '|' . claveComparable($f['nombre'], $f['modelo']);
        $grupos[$clave][] = $f;
    }

    $tieneHomologacion = [];
    foreach ($pdo->query('SELECT IDsubmarca FROM homologacion_gnp') as $h) {
        $tieneHomologacion[$h['IDsubmarca']] = true;
    }

    $insertarAlias        = $pdo->prepare('INSERT INTO submarca_alias (IDsubmarca, nombre_alterno, origen) VALUES (:id, :nombre, :origen)');
    $borrarSub            = $pdo->prepare('DELETE FROM submarcas WHERE id = :id');
    $migrarHomologacion   = $pdo->prepare('UPDATE homologacion_gnp SET IDsubmarca = :sobreviviente WHERE IDsubmarca = :perdedor');
    $borrarHomologacion   = $pdo->prepare('DELETE FROM homologacion_gnp WHERE IDsubmarca = :id');

    foreach ($grupos as $miembros) {
        if (count($miembros) < 2) {
            continue;
        }
        $stats['grupos']++;

        usort($miembros, static function ($a, $b) use ($tieneHomologacion) {
            $aTiene = isset($tieneHomologacion[$a['id']]);
            $bTiene = isset($tieneHomologacion[$b['id']]);
            if ($aTiene !== $bTiene) {
                return $bTiene <=> $aTiene; // el que tiene homologación va primero
            }
            return strcmp($a['id'], $b['id']); // id menor primero
        });

        $sobreviviente = $miembros[0];
        $perdedores    = array_slice($miembros, 1);

        foreach ($perdedores as $p) {
            $insertarAlias->execute([':id' => $sobreviviente['id'], ':nombre' => $p['nombre'], ':origen' => 'fusion_gemela']);

            if (isset($tieneHomologacion[$p['id']])) {
                if (!isset($tieneHomologacion[$sobreviviente['id']])) {
                    $migrarHomologacion->execute([':sobreviviente' => $sobreviviente['id'], ':perdedor' => $p['id']]);
                    $tieneHomologacion[$sobreviviente['id']] = true;
                    $stats['homologacion_migrada']++;
                } else {
                    $homPerdedor      = $pdo->query('SELECT confirmado_por FROM homologacion_gnp WHERE IDsubmarca = ' . $pdo->quote($p['id']))->fetch();
                    $homSobreviviente = $pdo->query('SELECT confirmado_por FROM homologacion_gnp WHERE IDsubmarca = ' . $pdo->quote($sobreviviente['id']))->fetch();
                    if (!empty($homPerdedor['confirmado_por']) && empty($homSobreviviente['confirmado_por'])) {
                        $stats['confirmaciones_en_conflicto'][] = "{$p['id']} (confirmado por {$homPerdedor['confirmado_por']}) choca con {$sobreviviente['id']} (sin confirmar) — revisar a mano";
                    }
                    $borrarHomologacion->execute([':id' => $p['id']]);
                }
                unset($tieneHomologacion[$p['id']]);
            }

            $borrarSub->execute([':id' => $p['id']]);
            $stats['eliminadas']++;
        }

        if (count($muestra) < 10) {
            $marca = $nombresMarcas[$sobreviviente['IDmarcacomercial']] ?? $sobreviviente['IDmarcacomercial'];
            $muestra[] = sprintf(
                '%s %s  sobrevive %s "%s"  <- descartadas: %s',
                $marca, $sobreviviente['modelo'], $sobreviviente['id'], $sobreviviente['nombre'],
                implode(', ', array_map(static fn($p) => "{$p['id']} \"{$p['nombre']}\"", $perdedores))
            );
        }
    }

    return [$stats, $muestra];
}

$totalMarcasAntes = (int) $pdo->query('SELECT COUNT(*) FROM marcas')->fetchColumn();
$nombresMarcas = array_column($pdo->query('SELECT id, nombre FROM marcas')->fetchAll(), 'nombre', 'id');

$pdo->beginTransaction();

$r1 = fusionarMarca($pdo, 'M105', 'M001', 'A) X-TRAIL -> NISSAN');
$r2 = fusionarMarca($pdo, 'M094', 'M093', 'B) TESLA MOTORS -> TESLA');
$r3 = fusionarMarca($pdo, 'M061', 'M063', 'C) LINK&CO -> LYNK CO');

echo "-- E) Submarcas gemelas dentro de la misma marca --\n";
[$r4, $muestraGemelas] = fusionarGemelas($pdo, $nombresMarcas);
echo "   Total: grupos={$r4['grupos']}  eliminadas={$r4['eliminadas']}  homologacion migrada={$r4['homologacion_migrada']}\n\n";

$pdo->commit();

$totalMarcasDespues = (int) $pdo->query('SELECT COUNT(*) FROM marcas')->fetchColumn();

echo str_repeat('═', 70), "\n";
echo "Resumen A/B/C — fusión de marcas fantasma/duplicadas\n";
echo '   ' . pad('Marcas antes', 30) . "{$totalMarcasAntes}\n";
echo '   ' . pad('Marcas después', 30) . "{$totalMarcasDespues}\n";
echo '   ' . pad('Reasignadas (total)', 30) . ($r1['reasignadas'] + $r2['reasignadas'] + $r3['reasignadas']) . "\n";
echo '   ' . pad('Descartadas por duplicadas', 30) . ($r1['descartadas_duplicadas'] + $r2['descartadas_duplicadas'] + $r3['descartadas_duplicadas']) . "\n";
echo '   ' . pad('homologacion_gnp migrada', 30) . ($r1['homologacion_migrada'] + $r2['homologacion_migrada'] + $r3['homologacion_migrada']) . "\n";

echo "\nResumen E — submarcas gemelas dentro de la misma marca\n";
echo '   ' . pad('Grupos con gemelas', 30) . "{$r4['grupos']}\n";
echo '   ' . pad('Submarcas eliminadas', 30) . "{$r4['eliminadas']}\n";
echo '   ' . pad('homologacion_gnp migrada', 30) . "{$r4['homologacion_migrada']}\n";
if ($muestraGemelas !== []) {
    echo "\n   Muestra de decisiones:\n";
    foreach ($muestraGemelas as $m) {
        echo "      {$m}\n";
    }
}

$conflictos = array_merge(
    $r1['confirmaciones_en_conflicto'], $r2['confirmaciones_en_conflicto'],
    $r3['confirmaciones_en_conflicto'], $r4['confirmaciones_en_conflicto']
);
if ($conflictos !== []) {
    echo "\n   AVISO — confirmaciones manuales en conflicto, revisar a mano:\n";
    foreach ($conflictos as $c) {
        echo "      {$c}\n";
    }
} else {
    echo "\n   Sin conflictos con confirmaciones manuales.\n";
}

$totalSubmarcas = (int) $pdo->query('SELECT COUNT(*) FROM submarcas')->fetchColumn();
$totalAlias     = (int) $pdo->query('SELECT COUNT(*) FROM submarca_alias')->fetchColumn();
echo "\n   Submarcas totales después de la limpieza: {$totalSubmarcas}\n";
echo "   Alias guardados en submarca_alias: {$totalAlias}\n";

// ── G) Lista de revisión: gemelas por confusión letra/dígito (solo reporte, no escribe) ──
// No se fusiona en automático: un cambio de un solo carácter puede ser un
// vehículo real distinto (A-200 vs A-250). Se junta por (marca, año, nombre
// alfanumérico con S->5, O->0, I->1, B->8) y se lista para revisión humana.

function soloAlfanumerico(string $normText): string
{
    return preg_replace('/[^A-Z0-9]/', '', $normText);
}

function tipoSospecha(string $a, string $b): string
{
    $sortedA = str_split($a);
    $sortedB = str_split($b);
    sort($sortedA);
    sort($sortedB);
    if ($sortedA === $sortedB) {
        return 'transposición';
    }
    $pares = [];
    if (strlen($a) === strlen($b)) {
        $mapa = ['S' => '5', 'O' => '0', 'I' => '1', 'B' => '8'];
        for ($i = 0; $i < strlen($a); $i++) {
            if ($a[$i] === $b[$i]) {
                continue;
            }
            foreach ($mapa as $letra => $digito) {
                if (($a[$i] === $letra && $b[$i] === $digito) || ($a[$i] === $digito && $b[$i] === $letra)) {
                    $pares["{$letra}↔{$digito}"] = true;
                }
            }
        }
    }
    return $pares !== [] ? implode(',', array_keys($pares)) : 'otra';
}

$gruposSospecha = [];
foreach ($pdo->query('SELECT id, nombre, modelo, IDmarcacomercial FROM submarcas') as $r) {
    $alfa = soloAlfanumerico(normaliza($r['nombre']));
    $clave = strtr($alfa, ['S' => '5', 'O' => '0', 'I' => '1', 'B' => '8']);
    $gruposSospecha[$r['IDmarcacomercial'] . '|' . $r['modelo'] . '|' . $clave][] = $r + ['alfa' => $alfa];
}

$tieneHomologacionG = [];
foreach ($pdo->query('SELECT IDsubmarca FROM homologacion_gnp') as $h) {
    $tieneHomologacionG[$h['IDsubmarca']] = true;
}

$filasSospecha = [];
foreach ($gruposSospecha as $miembros) {
    if (count($miembros) < 2) {
        continue;
    }
    for ($i = 0; $i < count($miembros); $i++) {
        for ($j = $i + 1; $j < count($miembros); $j++) {
            $a = $miembros[$i];
            $b = $miembros[$j];
            $filasSospecha[] = [
                'marca' => $nombresMarcas[$a['IDmarcacomercial']] ?? $a['IDmarcacomercial'],
                'nombre_A' => $a['nombre'], 'id_A' => $a['id'], 'tiene_gnp_A' => isset($tieneHomologacionG[$a['id']]) ? 'si' : 'no',
                'nombre_B' => $b['nombre'], 'id_B' => $b['id'], 'tiene_gnp_B' => isset($tieneHomologacionG[$b['id']]) ? 'si' : 'no',
                'anio' => $a['modelo'],
                'tipo_sospecha' => tipoSospecha($a['alfa'], $b['alfa']),
            ];
        }
    }
}

$rutaSospecha = RUTA_BASE . '/datos/gemelas_por_confirmar.csv';
$fh = fopen($rutaSospecha, 'w');
fputcsv($fh, ['marca', 'nombre_A', 'id_A', 'tiene_gnp_A', 'nombre_B', 'id_B', 'tiene_gnp_B', 'anio', 'tipo_sospecha']);
foreach ($filasSospecha as $fila) {
    fputcsv($fh, array_values($fila));
}
fclose($fh);

echo "\nResumen G — gemelas por confusión letra/dígito (solo reporte, no se tocó la base)\n";
echo '   Grupos encontrados: ' . number_format(count(array_filter($gruposSospecha, static fn($g) => count($g) > 1))) . "\n";
echo '   Filas en CSV: ' . number_format(count($filasSospecha)) . "\n";
echo "   Archivo: {$rutaSospecha}\n";
