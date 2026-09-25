#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * homologar_marcas_submarcas.php — Empata Marca+Submarca (Línea+Año) del
 * catálogo maestro (cat_comercial.db) contra el catálogo de GNP ya
 * descargado (cotizador_gnp.sqlite → cat_vehiculos).
 *
 * Alcance de esta etapa: SOLO Marca y Submarca. Versión queda fuera a propósito.
 * Alcance documentado (no genera filtro real: cat_vehiculos no trae subramo):
 * procedencia Residentes (sub_ramo 01).
 *
 * cat_comercial.db es el DESTINO (se escribe ahí: `homologacion_gnp`,
 * `alias_marca_gnp`, `submarca_de`, y la columna `submarcas.IDmarca_gnp`).
 * cotizador_gnp.sqlite es la FUENTE: se adjunta en modo solo lectura
 * (mode=ro), jamás se toca `cat_vehiculos`.
 *
 * Se corre DESPUÉS de limpiar_cat_comercial.php (marcas fantasma/duplicadas
 * y submarcas gemelas ya fusionadas).
 *
 * ── Cómo se resuelve la marca de cada línea GNP (tipo_vehiculo+armadora+carroceria) ──
 *
 * Se calculan hasta TRES opiniones independientes:
 *   - armadora:  nombre de la armadora matcheado directo contra marcas.nombre.
 *   - linea:     prefijo más profundo de carroceria_nombre (marcaMasProfunda).
 *   - version:   voto por mayoría entre TODAS las versiones de la línea con
 *                marca detectada; solo cuenta si la ganadora llega al 60% de
 *                las versiones CON marca (si no, "ambigua entre versiones").
 *
 * 0) alias_marca_gnp — prioridad máxima. Para cuando la marca no aparece en
 *    ningún texto (ej. "GENERAL MOTORS BLAZER" -> CHEVROLET).
 *
 * Si no hay alias, se recorren version -> linea -> armadora en ese orden.
 * Armadora siempre se acepta (no hay contra quién contradecirla). version/
 * linea solo se aceptan sin más si coinciden con la armadora o si la
 * armadora no tiene opinión; si CONTRADICEN a la armadora, hace falta que
 * el par (hija, padre) esté aprobado en `submarca_de` — si no, esa fuente
 * se descarta (se prueba la siguiente) y, si nada más resuelve, la línea
 * queda pendiente con motivo "sub-marca no aprobada".
 *
 * La confianza mide ACUERDO entre fuentes, no la fuente que ganó:
 *   alias                                   -> 100  (metodo: alias)
 *   2+ fuentes coinciden en la misma marca  -> 100  (metodo: ej. "armadora+version")
 *   una sola fuente = armadora              -> 100  (metodo: armadora)
 *   una sola fuente = linea                 ->  90  (metodo: linea)
 *   una sola fuente = version (voto >=60%)  ->  80  (metodo: version)
 *
 * "Prefijo" en linea y version usa marcaMasProfunda(): si tras reconocer una
 * marca el texto restante TAMBIÉN empieza con otra marca, gana la más
 * profunda (ej. "BMW MINI COOPER" -> MINI, no BMW).
 *
 * Nunca pisa un registro ya confirmado a mano (homologacion_gnp.confirmado_por).
 *
 * Uso:
 *   php app/scripts/homologar_marcas_submarcas.php
 *   php app/scripts/homologar_marcas_submarcas.php --umbral-version=60
 *   php app/scripts/homologar_marcas_submarcas.php "ruta\cat_comercial.db" "ruta\cotizador_gnp.sqlite"
 *
 * Es idempotente: correrlo varias veces no duplica ni pisa un match confirmado.
 */

require __DIR__ . '/_arranque.php';

$args = argumentos($argv);
$posicionales = array_values(array_filter($args, static fn($k) => is_int($k), ARRAY_FILTER_USE_KEY));
$rutaComercial = $posicionales[0] ?? RUTA_APP . '/core/cat_comercial.db';
$rutaGnp       = $posicionales[1] ?? Env::get('DB_PATH', RUTA_BASE . '/datos/cotizador_gnp.sqlite');

/** % mínimo de versiones (con marca detectada) que debe tener la marca ganadora para no quedar ambigua. */
$umbralVotoVersion = (float) ($args['umbral-version'] ?? 60);

if (!is_file($rutaComercial)) {
    fwrite(STDERR, "No encuentro cat_comercial.db en:\n   {$rutaComercial}\n");
    exit(1);
}
if (!is_file($rutaGnp)) {
    fwrite(STDERR, "No encuentro cotizador_gnp.sqlite en:\n   {$rutaGnp}\n");
    exit(1);
}

echo "Destino (se escribe) : {$rutaComercial}\n";
echo "Fuente  (solo lectura): {$rutaGnp}\n";
echo "Umbral voto de versión: {$umbralVotoVersion}%\n";
echo str_repeat('═', 70), "\n";

$pdo = new PDO('sqlite:' . $rutaComercial, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("PRAGMA encoding = 'UTF-8'");
$pdo->exec('PRAGMA foreign_keys = ON');

// Se adjunta en SÓLO LECTURA: imposible tocar cat_vehiculos por accidente.
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $rutaGnp) . "?mode=ro' AS gnp");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS homologacion_gnp (
        IDsubmarca             TEXT    NOT NULL,
        gnp_tipo_vehiculo      TEXT    NOT NULL,
        gnp_armadora           TEXT    NOT NULL,
        gnp_armadora_nombre    TEXT    NOT NULL,
        gnp_carroceria         TEXT    NOT NULL,
        gnp_carroceria_nombre  TEXT    NOT NULL,
        gnp_modelo             TEXT    NOT NULL,
        confianza              INTEGER NOT NULL,
        metodo                 TEXT    NOT NULL,
        confirmado_por         TEXT,
        confirmado_en          TEXT,
        actualizado_en         TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (IDsubmarca),
        FOREIGN KEY (IDsubmarca) REFERENCES submarcas(id)
    )
");

// ── alias manuales (prioridad máxima) ────────────────────────────────────

$pdo->exec("
    CREATE TABLE IF NOT EXISTS alias_marca_gnp (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        gnp_tipo_vehiculo  TEXT NOT NULL,
        gnp_armadora       TEXT NOT NULL,
        gnp_carroceria     TEXT,
        IDmarcacomercial   TEXT NOT NULL,
        nota               TEXT,
        creado_en          TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (IDmarcacomercial) REFERENCES marcas(id)
    )
");
$pdo->exec("
    CREATE UNIQUE INDEX IF NOT EXISTS ux_alias_marca_gnp
        ON alias_marca_gnp (gnp_tipo_vehiculo, gnp_armadora, COALESCE(gnp_carroceria, ''))
");

$sembrarAlias = $pdo->prepare("
    INSERT INTO alias_marca_gnp (gnp_tipo_vehiculo, gnp_armadora, gnp_carroceria, IDmarcacomercial, nota)
    VALUES (:tipo, :armadora, :carroceria, :marca, :nota)
    ON CONFLICT (gnp_tipo_vehiculo, gnp_armadora, COALESCE(gnp_carroceria, '')) DO UPDATE SET
        IDmarcacomercial = excluded.IDmarcacomercial,
        nota             = excluded.nota
");
foreach ([
    // tipo, armadora, carroceria, IDmarcacomercial, nota
    // TESLA ya NO necesita alias: M093/M094 se fusionaron en limpiar_cat_comercial.php.
    ['AUT', 'GM', '63', 'M038', 'GENERAL MOTORS YUKON -> GMC (la marca no aparece en el texto)'],
    ['AUT', 'GM', '67', 'M038', 'GENERAL MOTORS ACADIA -> GMC'],
    ['AUT', 'GM', '01', 'M020', 'GENERAL MOTORS BLAZER -> CHEVROLET'],
    ['AUT', 'GM', '24', 'M020', 'GENERAL MOTORS CAMARO -> CHEVROLET'],
    ['AUT', 'GM', '58', 'M020', 'MATIZ G2 -> CHEVROLET'],
    ['AUT', 'GM', '45', 'M020', 'VECTRA -> CHEVROLET'],
    ['AUT', 'GM', '37', 'M020', 'GENERAL MOTORS SONORA -> CHEVROLET'],
    // GREAT WALL MOTORS es multi-marca como GM: el alias de armadora completa
    // solo fija la OPINIÓN de armadora (para líneas como POER que no revelan
    // sub-marca en el texto); HAVAL/ORA/TANK la contradicen vía submarca_de
    // y ganan igual. OJO: la armadora "TANK" (TK) es un cajón de responsabilidad
    // civil, NO la marca — jamás se le pone alias.
    ['AUT', 'GW', null, 'M039', 'GREAT WALL MOTORS -> GWM (nombre distinto, cubre líneas sin sub-marca en el texto)'],
    ['CA1', 'GW', null, 'M039', 'GREAT WALL MOTORS -> GWM (nombre distinto, cubre líneas sin sub-marca en el texto)'],
] as [$tipo, $armadora, $carroceria, $marca, $nota]) {
    $sembrarAlias->execute([
        ':tipo' => $tipo, ':armadora' => $armadora, ':carroceria' => $carroceria,
        ':marca' => $marca, ':nota' => $nota,
    ]);
}

// $aliasLinea (una línea exacta) es prioridad ABSOLUTA: bypass total de la cascada.
// $aliasArmadora (armadora completa) NO es bypass: solo fija la opinión de
// "armadora" que entra a la cascada, para que línea/versión la puedan seguir
// contradiciendo vía submarca_de (GREAT WALL MOTORS es multi-marca, como GM).
$aliasLinea    = []; // "tipo|armadora|carroceria" => IDmarcacomercial
$aliasArmadora = []; // "tipo|armadora"            => IDmarcacomercial
foreach ($pdo->query('SELECT gnp_tipo_vehiculo, gnp_armadora, gnp_carroceria, IDmarcacomercial FROM alias_marca_gnp') as $r) {
    if ($r['gnp_carroceria'] === null) {
        $aliasArmadora["{$r['gnp_tipo_vehiculo']}|{$r['gnp_armadora']}"] = $r['IDmarcacomercial'];
    } else {
        $aliasLinea["{$r['gnp_tipo_vehiculo']}|{$r['gnp_armadora']}|{$r['gnp_carroceria']}"] = $r['IDmarcacomercial'];
    }
}

// ── Cambio C: submarca_de — candado para cuando línea/versión CONTRADICE a la armadora ──
// La fuente profunda solo puede ganarle a la armadora si el par está aprobado aquí.
// "CHEVROLET/BUICK/CADILLAC/GMC/PONTIAC dentro de GENERAL MOTORS" NO se siembra:
// "GENERAL MOTORS" no es una marca registrada en el catálogo (armadora_nombre
// "GENERAL MOTORS" no matchea nada), así que la armadora nunca tiene opinión ahí
// y no hay nada que contradecir — esas líneas ya resuelven solas por línea
// (confianza 90, fuente única) sin necesitar candado.

$pdo->exec("
    CREATE TABLE IF NOT EXISTS submarca_de (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        IDmarca_hija   TEXT NOT NULL,
        IDmarca_padre  TEXT NOT NULL,
        aprobado       INTEGER NOT NULL DEFAULT 0,
        nota           TEXT,
        creado_en      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (IDmarca_hija)  REFERENCES marcas(id),
        FOREIGN KEY (IDmarca_padre) REFERENCES marcas(id)
    )
");
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_submarca_de ON submarca_de (IDmarca_hija, IDmarca_padre)');

$sembrarSubmarcaDe = $pdo->prepare('
    INSERT INTO submarca_de (IDmarca_hija, IDmarca_padre, aprobado, nota)
    VALUES (:hija, :padre, 1, :nota)
    ON CONFLICT (IDmarca_hija, IDmarca_padre) DO UPDATE SET aprobado = 1, nota = excluded.nota
');
foreach ([
    ['M050', 'M022', 'JEEP dentro de CHRYSLER'],
    ['M026', 'M022', 'DODGE dentro de CHRYSLER'],
    ['M060', 'M033', 'LINCOLN dentro de FORD'],
    ['M069', 'M033', 'MERCURY dentro de FORD'],
    ['M071', 'M013', 'MINI dentro de BMW'],
    ['M040', 'M039', 'HAVAL dentro de GWM'],
    ['M077', 'M039', 'ORA dentro de GWM'],
    ['M092', 'M039', 'TANK dentro de GWM'],
] as [$hija, $padre, $nota]) {
    $sembrarSubmarcaDe->execute([':hija' => $hija, ':padre' => $padre, ':nota' => $nota]);
}

$submarcaDeAprobado = []; // "hija|padre" => true
foreach ($pdo->query('SELECT IDmarca_hija, IDmarca_padre FROM submarca_de WHERE aprobado = 1') as $r) {
    $submarcaDeAprobado["{$r['IDmarca_hija']}|{$r['IDmarca_padre']}"] = true;
}

// ── Cambio 4: "armadoras" que en realidad son cajones genéricos, no marcas ──

$categoriasExcluidas = [
    'AUT|AV' => 'ALTO VALOR',
    'AUT|CC' => 'AUT CLASICO',
    'CA1|CC' => 'CA1 CLASICO',
    'CA2|CC' => 'CA2 CLASICO',
    'MOT|AZ' => 'AVANZADA',
];

// ── Normalización ────────────────────────────────────────────────────────

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

// Cambio F: comparación laxa = quitar CUALQUIER carácter no alfanumérico
// (no solo guion/punto/coma). Misma función que limpiar_cat_comercial.php,
// para que las dos limpiezas no se vuelvan a separar.
function soloAlfanumerico(string $normText): string
{
    return preg_replace('/[^A-Z0-9]/', '', $normText);
}

// ── Catálogo de marcas + índice de prefijos (soporta marcas de 1..N palabras) ──

$marcasSet        = [];  // normalizado => id
$marcasPorPalabras = [];  // nPalabras => [normalizado => id]
$nombresMarcas    = [];  // id => nombre (para reportes legibles)
foreach ($pdo->query('SELECT id, nombre FROM marcas') as $r) {
    $n  = normaliza($r['nombre']);
    $np = count(explode(' ', $n));
    $marcasSet[$n] = $r['id'];
    $marcasPorPalabras[$np][$n] = $r['id'];
    $nombresMarcas[$r['id']] = $r['nombre'];
}
$maxPalabrasMarca = max(array_keys($marcasPorPalabras));

/**
 * Busca el prefijo más largo de $textoNorm que sea una marca conocida.
 * @return array{0:string,1:string}|null [IDmarca, texto del prefijo]
 */
function prefijoMarca(string $textoNorm, array $marcasPorPalabras, int $maxPalabras): ?array
{
    $palabras = explode(' ', $textoNorm);
    for ($n = $maxPalabras; $n >= 1; $n--) {
        if ($n > count($palabras)) {
            continue;
        }
        $candidato = implode(' ', array_slice($palabras, 0, $n));
        if (isset($marcasPorPalabras[$n][$candidato])) {
            return [$marcasPorPalabras[$n][$candidato], $candidato];
        }
    }
    return null;
}

function quitarPrefijo(string $textoNorm, string $prefijo): string
{
    $resto = trim(mb_substr($textoNorm, mb_strlen($prefijo)));
    return $resto !== '' ? $resto : $textoNorm;
}

/**
 * Si tras reconocer una marca el texto restante TAMBIÉN empieza con otra
 * marca, gana la más profunda (ej. "BMW MINI COOPER" -> MINI, no BMW).
 * Misma función para línea, versión y armadora.
 * @return array{0:string,1:string}|null [IDmarca más profunda, resto final]
 */
function marcaMasProfunda(string $textoNorm, array $marcasPorPalabras, int $maxPalabras): ?array
{
    $marcaActual = null;
    $restoActual = $textoNorm;
    while (true) {
        $pref = prefijoMarca($restoActual, $marcasPorPalabras, $maxPalabras);
        if ($pref === null) {
            break;
        }
        $siguienteResto = quitarPrefijo($restoActual, $pref[1]);
        if ($siguienteResto === $restoActual) {
            $marcaActual = $pref[0];
            break;
        }
        $marcaActual = $pref[0];
        $restoActual = $siguienteResto;
    }
    return $marcaActual !== null ? [$marcaActual, $restoActual] : null;
}

function quitarPrefijoLiteral(string $textoNorm, string $prefijoNorm): string
{
    $tp = explode(' ', $prefijoNorm);
    $tt = explode(' ', $textoNorm);
    $n  = count($tp);
    if (count($tt) > $n && array_slice($tt, 0, $n) === $tp) {
        return implode(' ', array_slice($tt, $n));
    }
    return $textoNorm;
}

/** Texto a comparar contra submarcas.nombre para una línea ya resuelta a $marcaObjetivo. */
function calcularResto(
    string $carroceriaNombreNorm,
    string $marcaObjetivo,
    string $armadoraNombreNorm,
    array $marcasPorPalabras,
    int $maxPalabras
): string {
    $profunda = marcaMasProfunda($carroceriaNombreNorm, $marcasPorPalabras, $maxPalabras);
    if ($profunda !== null && $profunda[0] === $marcaObjetivo) {
        return $profunda[1];
    }
    $sinArmadora = quitarPrefijoLiteral($carroceriaNombreNorm, $armadoraNombreNorm);
    if ($sinArmadora !== $carroceriaNombreNorm) {
        return $sinArmadora;
    }
    return $carroceriaNombreNorm;
}

/**
 * Cambio C+D: decide qué marca gana para una línea a partir de hasta tres
 * opiniones (armadora/linea/version) y calcula confianza+método por acuerdo.
 * @return array{marca:string,confianza:int,metodo:string}|array{rechazo:string}|null
 */
function resolverLinea(
    ?string $armadoraMarca, ?string $lineaMarca, ?string $versionMarca, array $submarcaDeAprobado
): ?array {
    $candidatos = [];
    if ($versionMarca !== null) {
        $candidatos[] = ['fuente' => 'version', 'marca' => $versionMarca];
    }
    if ($lineaMarca !== null) {
        $candidatos[] = ['fuente' => 'linea', 'marca' => $lineaMarca];
    }
    if ($armadoraMarca !== null) {
        $candidatos[] = ['fuente' => 'armadora', 'marca' => $armadoraMarca];
    }

    $ganador = null;
    $rechazo = null;
    foreach ($candidatos as $c) {
        if ($c['fuente'] === 'armadora' || $armadoraMarca === null || $c['marca'] === $armadoraMarca) {
            $ganador = $c;
            break;
        }
        if (isset($submarcaDeAprobado["{$c['marca']}|{$armadoraMarca}"])) {
            $ganador = $c;
            break;
        }
        $rechazo ??= "{$c['marca']} dentro de {$armadoraMarca}";
    }

    if ($ganador === null) {
        return $rechazo !== null ? ['rechazo' => $rechazo] : null;
    }

    $marcaFinal = $ganador['marca'];
    $fuentesQueCoinciden = [];
    foreach (['armadora' => $armadoraMarca, 'linea' => $lineaMarca, 'version' => $versionMarca] as $fuente => $m) {
        if ($m === $marcaFinal) {
            $fuentesQueCoinciden[] = $fuente;
        }
    }

    if (count($fuentesQueCoinciden) >= 2) {
        return ['marca' => $marcaFinal, 'confianza' => 100, 'metodo' => implode('+', $fuentesQueCoinciden)];
    }
    $confianzaPorFuente = ['armadora' => 100, 'linea' => 90, 'version' => 80];
    return ['marca' => $marcaFinal, 'confianza' => $confianzaPorFuente[$ganador['fuente']], 'metodo' => $ganador['fuente']];
}

// ── Índice de submarcas: estricto + alfanumérico puro (Cambio F) ────────

$submarcasIdx      = []; // [marca][modelo][normaliza(nombre)]        => IDsubmarca
$submarcasIdxAlfa  = []; // [marca][modelo][soloAlfanumerico(nombre)] => IDsubmarca
$submarcasInfo     = []; // IDsubmarca => ['nombre'=>,'modelo'=>,'marca'=>]
$totalPorMarca     = []; // IDmarcacomercial => total de submarcas
$ambiguedadOrigen  = []; // "marca|modelo|nombreNormalizado" duplicado dentro de submarcas

foreach ($pdo->query('SELECT id, nombre, modelo, IDmarcacomercial FROM submarcas') as $r) {
    $marca  = $r['IDmarcacomercial'];
    $modelo = (string) $r['modelo'];
    $nombre = normaliza($r['nombre']);
    $nombreAlfa = soloAlfanumerico($nombre);

    if (isset($submarcasIdx[$marca][$modelo][$nombre])) {
        $ambiguedadOrigen["{$marca}|{$modelo}|{$nombre}"] = true;
    }
    $submarcasIdx[$marca][$modelo][$nombre] = $r['id'];
    $submarcasIdxAlfa[$marca][$modelo][$nombreAlfa] ??= $r['id'];
    $submarcasInfo[$r['id']] = ['nombre' => $r['nombre'], 'modelo' => $modelo, 'marca' => $marca];
    $totalPorMarca[$marca] = ($totalPorMarca[$marca] ?? 0) + 1;
}

function buscarSubmarca(string $marca, string $modelo, string $restoNorm, array $submarcasIdx, array $submarcasIdxAlfa): ?string
{
    if (isset($submarcasIdx[$marca][$modelo][$restoNorm])) {
        return $submarcasIdx[$marca][$modelo][$restoNorm];
    }
    $alfa = soloAlfanumerico($restoNorm);
    return $submarcasIdxAlfa[$marca][$modelo][$alfa] ?? null;
}

// ── Un solo barrido de cat_vehiculos: arma líneas, años y versiones ─────

$lineaInfo         = []; // key "tipo|armadora|carroceria" => datos de la línea
$versionesPorLinea = []; // key => [version_nombre, ...]  (para el voto de via_version)
$lineasAnio        = []; // [ ['key'=>, 'modelo'=>], ... ]
$vistoLineaAnio    = [];

$stmt = $pdo->query('
    SELECT tipo_vehiculo, armadora, armadora_nombre, carroceria, carroceria_nombre, version_nombre, modelo
      FROM gnp.cat_vehiculos
');
foreach ($stmt as $r) {
    $key = "{$r['tipo_vehiculo']}|{$r['armadora']}|{$r['carroceria']}";
    $lineaInfo[$key] ??= [
        'tipo_vehiculo' => $r['tipo_vehiculo'],
        'armadora' => $r['armadora'],
        'armadora_nombre' => $r['armadora_nombre'],
        'armadora_nombre_norm' => normaliza($r['armadora_nombre']),
        'carroceria' => $r['carroceria'],
        'carroceria_nombre' => $r['carroceria_nombre'],
    ];
    $versionesPorLinea[$key][] = $r['version_nombre'];

    $modelo    = (string) $r['modelo'];
    $keyModelo = "{$key}|{$modelo}";
    if (!isset($vistoLineaAnio[$keyModelo])) {
        $vistoLineaAnio[$keyModelo] = true;
        $lineasAnio[] = ['key' => $key, 'modelo' => $modelo];
    }
}

echo 'Líneas GNP distintas: ' . number_format(count($lineaInfo)) . "   ";
echo 'Líneas+año: ' . number_format(count($lineasAnio)) . "\n\n";

// ── Resolución de marca por línea ─────────────────────────────────────────

$resolucionLinea  = []; // key => ['marca'=>,'metodo'=>,'confianza'=>]
$excluidas        = []; // key => nombre de la categoría (Cambio 4)
$ambiguasVersion  = []; // key => [marcaLider, pct]  (informativo)
$rechazosNoAprobados = []; // key => "hija dentro de padre"

foreach ($lineaInfo as $key => $info) {
    $tipoArmadora = "{$info['tipo_vehiculo']}|{$info['armadora']}";

    if (isset($categoriasExcluidas[$tipoArmadora])) {
        $excluidas[$key] = $categoriasExcluidas[$tipoArmadora];
        continue;
    }

    // alias de LINEA exacta — prioridad absoluta, bypass total de la cascada
    if (isset($aliasLinea[$key])) {
        $resolucionLinea[$key] = ['marca' => $aliasLinea[$key], 'metodo' => 'alias', 'confianza' => 100];
        continue;
    }

    // opinión de ARMADORA — un alias de armadora completa la reemplaza, pero
    // sigue siendo solo "una opinión más": línea/versión la pueden contradecir
    // vía submarca_de exactamente igual que si viniera de marcasSet.
    $armadoraViaAlias = isset($aliasArmadora[$tipoArmadora]);
    $armadoraMarca = $aliasArmadora[$tipoArmadora]
        ?? $marcasSet[$info['armadora_nombre_norm']]
        ?? (marcaMasProfunda($info['armadora_nombre_norm'], $marcasPorPalabras, $maxPalabrasMarca)[0] ?? null);

    // opinión de LINEA
    $profundaLinea = marcaMasProfunda(normaliza($info['carroceria_nombre']), $marcasPorPalabras, $maxPalabrasMarca);
    $lineaMarca    = $profundaLinea[0] ?? null;

    // opinión de VERSION (voto por mayoría entre las que SÍ detectan marca)
    $votos = [];
    foreach ($versionesPorLinea[$key] as $versionNombre) {
        $m = marcaMasProfunda(normaliza($versionNombre), $marcasPorPalabras, $maxPalabrasMarca);
        if ($m !== null) {
            $votos[$m[0]] = ($votos[$m[0]] ?? 0) + 1;
        }
    }
    $versionMarca = null;
    if ($votos !== []) {
        arsort($votos);
        $marcaLider = array_key_first($votos);
        $pctLider   = $votos[$marcaLider] / array_sum($votos) * 100;
        if ($pctLider >= $umbralVotoVersion) {
            $versionMarca = $marcaLider;
        } else {
            $ambiguasVersion[$key] = [$marcaLider, round($pctLider, 1)];
        }
    }

    $res = resolverLinea($armadoraMarca, $lineaMarca, $versionMarca, $submarcaDeAprobado);
    if ($res === null) {
        continue; // sin ninguna opinión: "armadora sin marca resuelta" (o ambigua) al procesar línea+año
    }
    if (isset($res['rechazo'])) {
        $rechazosNoAprobados[$key] = $res['rechazo'];
        continue;
    }
    if ($armadoraViaAlias) {
        // deja rastro de que la opinión de "armadora" en realidad vino de un alias de nombre.
        $res['metodo'] = str_replace('armadora', 'alias', $res['metodo']);
    }
    $resolucionLinea[$key] = $res;
}

$marcasConRelacion = array_values(array_unique(array_map(static fn($r) => $r['marca'], $resolucionLinea)));

// ── Resolución línea+año → submarca ──────────────────────────────────────

$resueltos          = [];
$conflictosSubmarca = [];
$pendientes         = [];
$fueraDeAlcance     = [];

foreach ($lineasAnio as $la) {
    $key    = $la['key'];
    $modelo = $la['modelo'];
    $info   = $lineaInfo[$key];

    if (isset($excluidas[$key])) {
        $fueraDeAlcance[] = ['categoria' => $excluidas[$key], 'fila' => $info + ['modelo' => $modelo]];
        continue;
    }

    $res = $resolucionLinea[$key] ?? null;
    if ($res === null) {
        if (isset($rechazosNoAprobados[$key])) {
            $motivo = "sub-marca no aprobada: {$rechazosNoAprobados[$key]}";
        } elseif (isset($ambiguasVersion[$key])) {
            $motivo = "marca ambigua entre versiones (mejor candidato: {$ambiguasVersion[$key][0]}, {$ambiguasVersion[$key][1]}%)";
        } else {
            $motivo = 'armadora sin marca resuelta';
        }
        $pendientes[] = [
            'estado' => 'pendiente', 'motivo' => $motivo,
            'confianza' => '', 'metodo' => '', 'marca_objetivo' => '', 'resto_intentado' => '',
            'fila' => $info + ['modelo' => $modelo],
        ];
        continue;
    }

    $resto = calcularResto(
        normaliza($info['carroceria_nombre']), $res['marca'], $info['armadora_nombre_norm'],
        $marcasPorPalabras, $maxPalabrasMarca
    );

    $idSubmarca = buscarSubmarca($res['marca'], $modelo, $resto, $submarcasIdx, $submarcasIdxAlfa);
    if ($idSubmarca === null) {
        $pendientes[] = [
            'estado' => $res['confianza'] >= 90 ? 'pendiente' : 'confianza_media_sin_match',
            'motivo' => 'submarca sin match de nombre/año',
            'confianza' => $res['confianza'], 'metodo' => $res['metodo'],
            'marca_objetivo' => $res['marca'], 'resto_intentado' => $resto,
            'fila' => $info + ['modelo' => $modelo],
        ];
        continue;
    }

    $candidato = [
        'IDsubmarca' => $idSubmarca,
        'gnp_tipo_vehiculo' => $info['tipo_vehiculo'],
        'gnp_armadora' => $info['armadora'],
        'gnp_armadora_nombre' => $info['armadora_nombre'],
        'gnp_carroceria' => $info['carroceria'],
        'gnp_carroceria_nombre' => $info['carroceria_nombre'],
        'gnp_modelo' => $modelo,
        'confianza' => $res['confianza'],
        'metodo' => $res['metodo'],
    ];

    if (!isset($resueltos[$idSubmarca])) {
        $resueltos[$idSubmarca] = $candidato;
        continue;
    }
    $previo = $resueltos[$idSubmarca];
    $mismaLinea = $previo['gnp_tipo_vehiculo'] === $candidato['gnp_tipo_vehiculo']
        && $previo['gnp_armadora'] === $candidato['gnp_armadora']
        && $previo['gnp_carroceria'] === $candidato['gnp_carroceria'];
    if (!$mismaLinea) {
        $conflictosSubmarca[$idSubmarca][] = $previo;
        $conflictosSubmarca[$idSubmarca][] = $candidato;
    }
}

foreach ($conflictosSubmarca as $idSubmarca => $variantes) {
    unset($resueltos[$idSubmarca]);
    $vistas = [];
    foreach ($variantes as $v) {
        $clave = $v['gnp_tipo_vehiculo'] . '|' . $v['gnp_armadora'] . '|' . $v['gnp_carroceria'];
        if (isset($vistas[$clave])) {
            continue;
        }
        $vistas[$clave] = true;
        $pendientes[] = [
            'estado' => 'pendiente', 'motivo' => "ambiguo: varias líneas GNP apuntan a {$idSubmarca}",
            'confianza' => $v['confianza'], 'metodo' => $v['metodo'],
            'marca_objetivo' => '', 'resto_intentado' => '',
            'fila' => [
                'tipo_vehiculo' => $v['gnp_tipo_vehiculo'], 'armadora' => $v['gnp_armadora'],
                'armadora_nombre' => $v['gnp_armadora_nombre'], 'carroceria' => $v['gnp_carroceria'],
                'carroceria_nombre' => $v['gnp_carroceria_nombre'], 'modelo' => $v['gnp_modelo'],
            ],
        ];
    }
}

// ── Snapshot ANTES de escribir (para el resumen comparativo) ────────────

$antesPorMetodo = [];
foreach ($pdo->query('SELECT metodo, COUNT(*) c FROM homologacion_gnp GROUP BY metodo') as $r) {
    $antesPorMetodo[$r['metodo']] = (int) $r['c'];
}
$antesPorConfianza = [];
foreach ($pdo->query('SELECT confianza, COUNT(*) c FROM homologacion_gnp GROUP BY confianza') as $r) {
    $antesPorConfianza[(int) $r['confianza']] = (int) $r['c'];
}
$marcasAntes = array_column(
    $pdo->query('SELECT DISTINCT s.IDmarcacomercial m FROM homologacion_gnp h JOIN submarcas s ON s.id = h.IDsubmarca')->fetchAll(),
    'm'
);
$totalAntes = (int) $pdo->query('SELECT COUNT(*) FROM homologacion_gnp')->fetchColumn();

// ── Escritura (idempotente, respeta confirmado_por) ─────────────────────

$upsert = $pdo->prepare("
    INSERT INTO homologacion_gnp
        (IDsubmarca, gnp_tipo_vehiculo, gnp_armadora, gnp_armadora_nombre,
         gnp_carroceria, gnp_carroceria_nombre, gnp_modelo, confianza, metodo, actualizado_en)
    VALUES
        (:IDsubmarca, :gnp_tipo_vehiculo, :gnp_armadora, :gnp_armadora_nombre,
         :gnp_carroceria, :gnp_carroceria_nombre, :gnp_modelo, :confianza, :metodo, datetime('now','localtime'))
    ON CONFLICT (IDsubmarca) DO UPDATE SET
        gnp_tipo_vehiculo     = excluded.gnp_tipo_vehiculo,
        gnp_armadora          = excluded.gnp_armadora,
        gnp_armadora_nombre   = excluded.gnp_armadora_nombre,
        gnp_carroceria        = excluded.gnp_carroceria,
        gnp_carroceria_nombre = excluded.gnp_carroceria_nombre,
        gnp_modelo            = excluded.gnp_modelo,
        confianza             = excluded.confianza,
        metodo                = excluded.metodo,
        actualizado_en        = excluded.actualizado_en
    WHERE homologacion_gnp.confirmado_por IS NULL
");

$pdo->beginTransaction();

// Cambio H: la idempotencia solo debe proteger lo CONFIRMADO A MANO. Un
// registro automático (confirmado_por IS NULL) que esta corrida ya NO
// resuelve igual (ej. porque ahora es ambiguo entre dos líneas GNP, o el
// candado ya no lo deja pasar) es sedimento de una corrida anterior: se
// borra aquí para que no quede pisando el resultado fresco. Se sincroniza
// después con el UPDATE de abajo (deja IDmarca_gnp en NULL para esos casos).
$idsResueltos = array_keys($resueltos);
if ($idsResueltos !== []) {
    $marcadores = implode(',', array_fill(0, count($idsResueltos), '?'));
    $borrarObsoletos = $pdo->prepare("DELETE FROM homologacion_gnp WHERE confirmado_por IS NULL AND IDsubmarca NOT IN ({$marcadores})");
    $borrarObsoletos->execute($idsResueltos);
    $obsoletosBorrados = $borrarObsoletos->rowCount();
} else {
    $obsoletosBorrados = $pdo->exec('DELETE FROM homologacion_gnp WHERE confirmado_por IS NULL');
}

$escritos = 0;
$saltadosConfirmados = 0;
$confirmados = (int) $pdo->query('SELECT COUNT(*) FROM homologacion_gnp WHERE confirmado_por IS NOT NULL')->fetchColumn();

foreach ($resueltos as $idSubmarca => $r) {
    $upsert->execute([
        ':IDsubmarca' => $idSubmarca,
        ':gnp_tipo_vehiculo' => $r['gnp_tipo_vehiculo'],
        ':gnp_armadora' => $r['gnp_armadora'],
        ':gnp_armadora_nombre' => $r['gnp_armadora_nombre'],
        ':gnp_carroceria' => $r['gnp_carroceria'],
        ':gnp_carroceria_nombre' => $r['gnp_carroceria_nombre'],
        ':gnp_modelo' => $r['gnp_modelo'],
        ':confianza' => $r['confianza'],
        ':metodo' => $r['metodo'],
    ]);
    if ($upsert->rowCount() > 0) {
        $escritos++;
    } else {
        $saltadosConfirmados++;
    }
}

// Sin filtro WHERE a propósito: también debe dejar en NULL las submarcas
// cuyo homologacion_gnp se acaba de borrar por obsoleto (Cambio H).
$pdo->exec("
    UPDATE submarcas
       SET IDmarca_gnp = (
           SELECT h.gnp_tipo_vehiculo || '|' || h.gnp_armadora || '|' || h.gnp_carroceria
             FROM homologacion_gnp h
            WHERE h.IDsubmarca = submarcas.id
       )
");

$pdo->commit();

$despuesPorMetodo = [];
foreach ($pdo->query('SELECT metodo, COUNT(*) c FROM homologacion_gnp GROUP BY metodo') as $r) {
    $despuesPorMetodo[$r['metodo']] = (int) $r['c'];
}
$despuesPorConfianza = [];
foreach ($pdo->query('SELECT confianza, COUNT(*) c FROM homologacion_gnp GROUP BY confianza') as $r) {
    $despuesPorConfianza[(int) $r['confianza']] = (int) $r['c'];
}
$marcasDespues = array_column(
    $pdo->query('SELECT DISTINCT s.IDmarcacomercial m FROM homologacion_gnp h JOIN submarcas s ON s.id = h.IDsubmarca')->fetchAll(),
    'm'
);
$marcasNuevas = array_values(array_diff($marcasDespues, $marcasAntes));
$totalDespues = (int) $pdo->query('SELECT COUNT(*) FROM homologacion_gnp')->fetchColumn();

// ── Cambio 5: reporte inverso — Comercial sin GNP ────────────────────────

$lineasPorMarca = [];
$aniosPorMarcaYResto = []; // marca => alfaResto => [años...] (para ANIO_NO_EN_GNP: match exacto en OTRO año)
foreach ($lineasAnio as $la) {
    $key = $la['key'];
    $res = $resolucionLinea[$key] ?? null;
    if ($res === null) {
        continue;
    }
    $info = $lineaInfo[$key];
    $resto = calcularResto(
        normaliza($info['carroceria_nombre']), $res['marca'], $info['armadora_nombre_norm'],
        $marcasPorPalabras, $maxPalabrasMarca
    );
    $lineasPorMarca[$res['marca']][] = [
        'resto' => $resto, 'modelo' => $la['modelo'],
        'armadora' => $info['armadora'], 'carroceria' => $info['carroceria'],
        'armadora_nombre' => $info['armadora_nombre'], 'carroceria_nombre' => $info['carroceria_nombre'],
    ];
    $aniosPorMarcaYResto[$res['marca']][soloAlfanumerico($resto)][] = $la['modelo'];
}

function mejorCandidato(string $nombreNorm, string $modelo, array $candidatos): array
{
    $mejor = null;
    $mejorPct = -1.0;
    foreach ($candidatos as $c) {
        $mismoAnio = $c['modelo'] === $modelo;
        if (!$mismoAnio && mb_substr($nombreNorm, 0, 2) !== mb_substr($c['resto'], 0, 2)) {
            continue;
        }
        similar_text($nombreNorm, $c['resto'], $pct);
        if ($mismoAnio) {
            $pct = min(100.0, $pct + 5.0);
        }
        if ($pct > $mejorPct) {
            $mejorPct = $pct;
            $mejor = $c;
        }
    }
    return $mejor !== null ? [$mejor, round($mejorPct, 1)] : [null, 0.0];
}

$submarcasYaMapeadas = array_flip(array_column(
    $pdo->query('SELECT id FROM submarcas WHERE IDmarca_gnp IS NOT NULL')->fetchAll(),
    'id'
));

// ── Cambio L: motivo_sin_gnp / nota_sin_gnp — clasificación exacta y accionable ──
// Orden de evaluación: AMBIGUO (dato estructural, no depende del texto) ->
// MARCA_NO_EN_GNP (si la marca no existe por ningún camino, los % no aplican) ->
// ANIO_NO_EN_GNP (match exacto en otro año) -> ESCRITURA_DISTINTA (80-99%) ->
// CANDIDATO_DUDOSO (60-79%) -> SIN_EQUIVALENTE (<60%).
// FUERA_DE_ALCANCE (Alto Valor/Clásicos/Avanzada/motos) no tiene camino de
// llegada desde el lado comercial: esas son cajones de GNP sin marca real
// detrás (Cambio 4), nunca aparecen como candidato de ninguna marca comercial,
// y el catálogo maestro hoy es 100% tipo=individual (autos). Cero filas caen
// ahí — se deja documentado, no forzado.

foreach (['motivo_sin_gnp', 'nota_sin_gnp'] as $columna) {
    $existe = false;
    foreach ($pdo->query('PRAGMA table_info(submarcas)') as $c) {
        if ($c['name'] === $columna) {
            $existe = true;
            break;
        }
    }
    if (!$existe) {
        $pdo->exec("ALTER TABLE submarcas ADD COLUMN {$columna} TEXT");
    }
}

/** Subclasificación de MARCA_NO_EN_GNP, verificada a mano contra las marcas reales sin relación. */
$subclasificacionMarca = [
    'ASTON MARTIN' => 'LUJO', 'BENTLEY' => 'LUJO', 'FERRARI' => 'LUJO', 'LAMBORGHINI' => 'LUJO',
    'MASERATI' => 'LUJO', 'MAYBACH' => 'LUJO', 'MCLAREN' => 'LUJO', 'ROLLS ROYCE' => 'LUJO',
    'LOTUS' => 'LUJO', 'MORGAN' => 'LUJO', 'VUHL' => 'LUJO',
    'OLDSMOBILE' => 'DESCONTINUADA', 'VAM' => 'DESCONTINUADA',
    'BAW' => 'CHINA_RECIENTE', 'BAOJUN' => 'CHINA_RECIENTE', 'JAECOO' => 'CHINA_RECIENTE',
    'NETA' => 'CHINA_RECIENTE', 'VGV' => 'CHINA_RECIENTE', 'WEY' => 'CHINA_RECIENTE',
    'WULING' => 'CHINA_RECIENTE', 'YUTONG' => 'CHINA_RECIENTE',
];

$comercialSinGnp = [];
$actualizarMotivo = $pdo->prepare('UPDATE submarcas SET motivo_sin_gnp = :motivo, nota_sin_gnp = :nota WHERE id = :id');
$limpiarMotivo = $pdo->prepare('UPDATE submarcas SET motivo_sin_gnp = NULL, nota_sin_gnp = NULL WHERE id = :id');

$conteoMotivos = [];

foreach ($submarcasInfo as $idSubmarca => $sub) {
    if (isset($resueltos[$idSubmarca]) || isset($submarcasYaMapeadas[$idSubmarca])) {
        $limpiarMotivo->execute([':id' => $idSubmarca]);
        continue;
    }

    $marca = $sub['marca'];
    $marcaNombre = $nombresMarcas[$marca] ?? $marca;
    $tieneRelacion = in_array($marca, $marcasConRelacion, true);
    $candidatoTxt = '';
    $pct = 0.0;

    if (isset($conflictosSubmarca[$idSubmarca])) {
        $lineasConflicto = [];
        $vistas = [];
        foreach ($conflictosSubmarca[$idSubmarca] as $v) {
            $clave = "{$v['gnp_armadora']}/{$v['gnp_carroceria']} \"{$v['gnp_carroceria_nombre']}\" {$v['gnp_modelo']}";
            if (!isset($vistas[$clave])) {
                $vistas[$clave] = true;
                $lineasConflicto[] = $clave;
            }
        }
        $motivo = 'AMBIGUO';
        $nota = 'Compiten: ' . implode(' | ', $lineasConflicto);
    } elseif (!$tieneRelacion) {
        $motivo = 'MARCA_NO_EN_GNP';
        $sub2 = $subclasificacionMarca[$marcaNombre] ?? 'OTRA';
        $nota = "La marca {$marcaNombre} no aparece en GNP por armadora, línea ni versión. Categoría: {$sub2}.";
    } else {
        $alfaSubmarca = soloAlfanumerico(normaliza($sub['nombre']));
        $aniosExactos = $aniosPorMarcaYResto[$marca][$alfaSubmarca] ?? [];
        if ($aniosExactos !== []) {
            $motivo = 'ANIO_NO_EN_GNP';
            sort($aniosExactos);
            $nota = "GNP SÍ tiene \"{$sub['nombre']}\" de {$marcaNombre} en: " . implode(', ', $aniosExactos)
                . ". No tiene el año {$sub['modelo']}.";
            $pct = 100.0;
            $candidatoTxt = "{$marcaNombre} \"{$sub['nombre']}\" " . implode('/', $aniosExactos);
        } else {
            [$cand, $pct] = isset($lineasPorMarca[$marca])
                ? mejorCandidato(normaliza($sub['nombre']), $sub['modelo'], $lineasPorMarca[$marca])
                : [null, 0.0];
            if ($cand !== null) {
                $candidatoTxt = "{$cand['armadora']}/{$cand['carroceria']} \"{$cand['carroceria_nombre']}\" {$cand['modelo']}";
            }
            $motivo = match (true) {
                $pct >= 80 => 'ESCRITURA_DISTINTA',
                $pct >= 60 => 'CANDIDATO_DUDOSO',
                default => 'SIN_EQUIVALENTE',
            };
            $nota = $cand !== null
                ? "Mejor candidato: {$candidatoTxt} ({$pct}% parecido)."
                : "Sin ningún candidato de texto razonable dentro de {$marcaNombre}.";
        }
    }

    $conteoMotivos[$motivo] = ($conteoMotivos[$motivo] ?? 0) + 1;
    $actualizarMotivo->execute([':motivo' => $motivo, ':nota' => $nota, ':id' => $idSubmarca]);

    $comercialSinGnp[] = [
        'IDsubmarca' => $idSubmarca,
        'marca' => $marcaNombre,
        'submarca' => $sub['nombre'],
        'anio' => $sub['modelo'],
        'total_submarcas_de_esa_marca' => $totalPorMarca[$marca] ?? 0,
        'marca_tiene_alguna_relacion' => $tieneRelacion ? 'si' : 'no',
        'mejor_candidato_gnp' => $candidatoTxt,
        'parecido' => $pct,
        'motivo_sin_gnp' => $motivo,
        'nota_sin_gnp' => $nota,
    ];
}

usort($comercialSinGnp, static fn($a, $b) => $a['marca'] <=> $b['marca'] ?: $b['anio'] <=> $a['anio']);

$rutaComercialSinGnp = RUTA_BASE . '/datos/comercial_sin_gnp.csv';
$fh = fopen($rutaComercialSinGnp, 'w');
fputcsv($fh, [
    'IDsubmarca', 'marca', 'submarca', 'anio', 'total_submarcas_de_esa_marca',
    'marca_tiene_alguna_relacion', 'mejor_candidato_gnp', 'parecido', 'motivo_sin_gnp', 'nota_sin_gnp',
]);
foreach ($comercialSinGnp as $row) {
    fputcsv($fh, array_values($row));
}
fclose($fh);

// ── Reporte por consola ──────────────────────────────────────────────────

echo str_repeat('═', 70), "\n";
echo "Resumen por método (antes de esta corrida -> después)\n";
$metodosVistos = array_unique(array_merge(array_keys($antesPorMetodo), array_keys($despuesPorMetodo)));
sort($metodosVistos);
foreach ($metodosVistos as $m) {
    $a = $antesPorMetodo[$m] ?? 0;
    $d = $despuesPorMetodo[$m] ?? 0;
    echo '   ' . pad($m, 24) . "{$a}  ->  {$d}\n";
}
echo '   ' . pad('TOTAL', 24) . "{$totalAntes}  ->  {$totalDespues}\n";

echo "\nResumen por confianza (antes -> después)\n";
foreach ([100, 90, 80] as $c) {
    $a = $antesPorConfianza[$c] ?? 0;
    $d = $despuesPorConfianza[$c] ?? 0;
    echo '   ' . pad("confianza {$c}", 24) . "{$a}  ->  {$d}\n";
}

echo "\n";
echo '   ' . pad('Escritos/actualizados en esta corrida', 40) . "{$escritos}\n";
echo '   ' . pad('Obsoletos borrados (Cambio H, sedimento)', 40) . "{$obsoletosBorrados}\n";
echo '   ' . pad('Saltados (ya confirmados a mano)', 40) . "{$saltadosConfirmados}\n";
echo '   ' . pad('Confirmados a mano (histórico)', 40) . "{$confirmados}\n";

$pendMarca      = count(array_filter($pendientes, static fn($p) => $p['motivo'] === 'armadora sin marca resuelta'));
$pendAmbiguaVer = count(array_filter($pendientes, static fn($p) => str_starts_with($p['motivo'], 'marca ambigua entre versiones')));
$pendNoAprobada = count(array_filter($pendientes, static fn($p) => str_starts_with($p['motivo'], 'sub-marca no aprobada')));
$pendSubmarca   = count(array_filter($pendientes, static fn($p) => $p['motivo'] === 'submarca sin match de nombre/año'));
$pendConflicto  = count(array_filter($pendientes, static fn($p) => str_starts_with($p['motivo'], 'ambiguo')));

echo "\nPendientes por motivo (sin contar fuera de alcance)\n";
echo '   ' . pad('armadora sin marca resuelta', 40) . "{$pendMarca}\n";
echo '   ' . pad('marca ambigua entre versiones', 40) . "{$pendAmbiguaVer}\n";
echo '   ' . pad('sub-marca no aprobada', 40) . "{$pendNoAprobada}\n";
echo '   ' . pad('submarca sin match de nombre/año', 40) . "{$pendSubmarca}\n";
echo '   ' . pad('ambiguo (varias líneas GNP)', 40) . "{$pendConflicto}\n";
echo '   ' . pad('fuera de alcance por ahora (Cambio 4)', 40) . count($fueraDeAlcance) . " (aparte, no cuenta)\n";

if ($rechazosNoAprobados !== []) {
    echo "\nLíneas detenidas por 'sub-marca no aprobada' (pares distintos):\n";
    $pares = [];
    foreach ($rechazosNoAprobados as $r) {
        $pares[$r] = ($pares[$r] ?? 0) + 1;
    }
    arsort($pares);
    foreach ($pares as $par => $n) {
        echo "   {$par}: {$n} línea(s)\n";
    }
}

echo "\nMarcas que pasaron de sin relación a con relación: " . count($marcasNuevas) . "\n";
if ($marcasNuevas !== []) {
    sort($marcasNuevas);
    foreach ($marcasNuevas as $m) {
        echo "   {$m} " . ($nombresMarcas[$m] ?? '?') . "\n";
    }
}

$totalMapeadas  = (int) $pdo->query('SELECT COUNT(*) FROM submarcas WHERE IDmarca_gnp IS NOT NULL')->fetchColumn();
$totalSubmarcas = (int) $pdo->query('SELECT COUNT(*) FROM submarcas')->fetchColumn();
echo "\n   " . pad('Submarcas con IDmarca_gnp (total)', 40) . "{$totalMapeadas} / {$totalSubmarcas}\n";

echo "\nCambio L — motivo_sin_gnp (cada submarca sin relación, clasificada)\n";
foreach (['ANIO_NO_EN_GNP', 'ESCRITURA_DISTINTA', 'CANDIDATO_DUDOSO', 'SIN_EQUIVALENTE', 'MARCA_NO_EN_GNP', 'AMBIGUO', 'FUERA_DE_ALCANCE'] as $m) {
    echo '   ' . pad($m, 24) . ($conteoMotivos[$m] ?? 0) . "\n";
}
echo '   ' . pad('TOTAL clasificado', 24) . array_sum($conteoMotivos) . "\n";

if ($ambiguedadOrigen !== []) {
    echo "\n   AVISO: " . count($ambiguedadOrigen) . " combinaciones marca+modelo+nombre están duplicadas\n";
    echo "          dentro de la propia tabla submarcas (mismo nombre normalizado).\n";
}

// Cambio H: confirma que no quede sedimento de esquemas de metodo anteriores
// (ej. marca_directa / multimarca_extraida de antes del Cambio D).
$metodosValidos = ['alias', 'armadora', 'linea', 'version'];
$metodosFuera = [];
foreach ($pdo->query('SELECT DISTINCT metodo FROM homologacion_gnp') as $r) {
    $partes = explode('+', $r['metodo']);
    if (array_diff($partes, $metodosValidos) !== []) {
        $metodosFuera[] = $r['metodo'];
    }
}
echo "\n   Métodos fuera del esquema nuevo (marca_directa/multimarca_extraida/etc): "
    . ($metodosFuera === [] ? 'ninguno' : implode(', ', $metodosFuera)) . "\n";

$pdo->exec('DETACH DATABASE gnp');

// ── CSV 1: pendientes GNP -> Comercial (incluye confianza media para confirmar) ──

$rutaCsv = RUTA_BASE . '/datos/pendientes_homologacion_gnp.csv';
$fh = fopen($rutaCsv, 'w');
fputcsv($fh, [
    'estado', 'motivo', 'confianza', 'metodo', 'marca_objetivo', 'resto_intentado',
    'tipo_vehiculo', 'armadora', 'armadora_nombre', 'carroceria', 'carroceria_nombre', 'modelo',
]);
foreach ($pendientes as $p) {
    fputcsv($fh, [
        $p['estado'], $p['motivo'], $p['confianza'], $p['metodo'], $p['marca_objetivo'], $p['resto_intentado'],
        $p['fila']['tipo_vehiculo'], $p['fila']['armadora'], $p['fila']['armadora_nombre'],
        $p['fila']['carroceria'], $p['fila']['carroceria_nombre'], $p['fila']['modelo'],
    ]);
}
foreach ($resueltos as $idSubmarca => $r) {
    if ($r['confianza'] >= 90) {
        continue;
    }
    fputcsv($fh, [
        'confianza_media_por_confirmar', "resuelta y escrita como IDsubmarca={$idSubmarca}; confirmar antes de dar por buena",
        $r['confianza'], $r['metodo'], '', '',
        $r['gnp_tipo_vehiculo'], $r['gnp_armadora'], $r['gnp_armadora_nombre'],
        $r['gnp_carroceria'], $r['gnp_carroceria_nombre'], $r['gnp_modelo'],
    ]);
}
fclose($fh);

// ── CSV 2: fuera de alcance por ahora (Cambio 4) ─────────────────────────

$rutaFuera = RUTA_BASE . '/datos/fuera_de_alcance_homologacion_gnp.csv';
$fh = fopen($rutaFuera, 'w');
fputcsv($fh, ['categoria', 'tipo_vehiculo', 'armadora', 'armadora_nombre', 'carroceria', 'carroceria_nombre', 'modelo']);
foreach ($fueraDeAlcance as $f) {
    fputcsv($fh, [
        $f['categoria'], $f['fila']['tipo_vehiculo'], $f['fila']['armadora'], $f['fila']['armadora_nombre'],
        $f['fila']['carroceria'], $f['fila']['carroceria_nombre'], $f['fila']['modelo'],
    ]);
}
fclose($fh);

echo "\nCSV pendientes GNP -> Comercial : {$rutaCsv}\n";
echo "CSV fuera de alcance (Cambio 4) : {$rutaFuera}\n";
echo "CSV Comercial -> GNP (Cambio 5) : {$rutaComercialSinGnp}\n";
echo '   Filas: ' . number_format(count($comercialSinGnp)) . "\n";
