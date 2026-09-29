<?php
declare(strict_types=1);

/**
 * ImportadorPortal — carga plantilla_captura_portal.csv (cotizaciones hechas
 * en el portal de Qualitas) al catálogo provisional de vehículos
 * (cat_qua_vehiculos, fuente PORTAL_MANUAL) y a las referencias para las
 * pruebas reales (cat_qua_referencias_portal).
 *
 * - Valida la clave AMIS: 5 dígitos, o 6 si trae el dígito verificador al
 *   final (entonces tiene que coincidir con QualitasXml::digitoAmis()).
 * - Rechaza las filas incompletas o con datos inválidos, diciendo cuál (número
 *   de fila y vehículo) y por qué. Las demás se importan.
 * - Idempotente: volver a correr el mismo CSV no duplica nada; cuenta lo que
 *   se insertó, lo que cambió y lo que quedó igual.
 * - Por omisión sólo revisa (no escribe). Escribe con $aplicar = true.
 *
 * Esta clase es la única definición de las columnas del CSV: de aquí salen
 * la plantilla y el extractor de PDF del portal.
 */
final class ImportadorPortal
{
    /**
     * Coberturas que puede traer una cotización del portal: clave de columna →
     * [NoCobertura de Qualitas, nombre como sale en el PDF].
     */
    public const COBERTURAS = [
        'dm'                => [1,  'DANOS MATERIALES'],
        'rt'                => [3,  'ROBO TOTAL'],
        'rc'                => [4,  'RESPONSABILIDAD CIVIL POR DAÑOS A TERCEROS'],
        'rc_carga'          => [31, 'RC POR DAÑOS OCASIONADOS POR LA CARGA'],
        'rc_complementaria' => [47, 'RC Complementaria Personas'],
        'gm'                => [5,  'GASTOS MEDICOS OCUPANTES'],
        'gl'                => [7,  'GASTOS LEGALES'],
        'av'                => [14, 'ASISTENCIA VIAL QUALITAS PLUS'],
        'mc'                => [6,  'MUERTE DEL CONDUCTOR X AA'],
    ];

    /** Formas de pago fraccionadas que puede mostrar el PDF: primer pago y pagos siguientes. */
    public const FORMAS = ['semestral', 'trimestral', 'mensual'];

    /** Obligatorias para importar una fila. */
    public const OBLIGATORIAS = ['amis', 'marca', 'linea', 'version_exacta', 'anio', 'uso', 'descuento_pct', 'prima_neta', 'importe_total'];

    /** @return list<string> columnas del CSV, en orden */
    public static function columnas(): array
    {
        $c = [
            'num', 'tipo_vehiculo', 'vehiculo_sugerido',
            'amis', 'marca', 'linea', 'version_exacta', 'descripcion_pdf', 'anio', 'uso', 'servicio',
            'cp', 'estado', 'paquete', 'forma_pago', 'descuento_pct', 'pronto_pago_dias',
        ];
        foreach (array_keys(self::COBERTURAS) as $k) {
            array_push($c, "{$k}_suma", "{$k}_deducible", "{$k}_prima");
        }
        array_push($c, 'otras_coberturas', 'prima_neta', 'tasa_fin_pf', 'gtos_exped_pol', 'subtotal', 'iva', 'importe_total',
            'comision_pct', 'comision_importe');
        foreach (self::FORMAS as $f) {
            array_push($c, "{$f}_primer", "{$f}_siguientes");
        }
        array_push($c, 'tarifa_aplicada', 'numero_cotizacion_portal', 'fecha_cotizacion', 'archivo_origen', 'notas');
        return $c;
    }

    /** Lee el CSV (con o sin BOM). Devuelve [número de fila del archivo => fila con nombre de columna]. */
    public static function leer(string $archivo): array
    {
        $fh = fopen($archivo, 'rb');
        if ($fh === false) {
            throw new RuntimeException("No se pudo abrir {$archivo}");
        }
        $enc = fgetcsv($fh, 0, ',', '"', '');
        if ($enc === false) {
            throw new RuntimeException('El CSV está vacío.');
        }
        $enc[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $enc[0]);
        $enc = array_map('trim', $enc);
        $filas = [];
        $n = 1;
        while (($l = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $n++;
            if ($l === [null] || implode('', $l) === '') {
                continue;
            }
            $filas[$n] = array_combine($enc, array_pad(array_map(static fn ($v) => trim((string) $v), $l), count($enc), ''));
        }
        fclose($fh);
        return $filas;
    }

    /** "8,440.28" · "$ 468,000" · "-168.81" → float. '' → null. Otra cosa → false. */
    public static function numero(string $v): float|false|null
    {
        $v = trim(str_replace(['$', ',', ' '], '', $v));
        if ($v === '') {
            return null;
        }
        return is_numeric($v) ? (float) $v : false;
    }

    /**
     * Normaliza y valida la AMIS. Devuelve [amis de 5 dígitos, error o null].
     * 6 dígitos = AMIS + dígito verificador, que tiene que coincidir.
     */
    public static function amis(string $v): array
    {
        $d = preg_replace('/[\s-]/', '', $v);
        if (!preg_match('/^\d+$/', $d)) {
            return ['', "AMIS inválida (\"{$v}\"): sólo dígitos"];
        }
        if (strlen($d) === 6) {
            $base = substr($d, 0, 5);
            $esperado = QualitasXml::digitoAmis($base);
            if ((int) $d[5] !== $esperado) {
                return ['', "AMIS {$base}: el dígito verificador {$d[5]} no corresponde (debe ser {$esperado})"];
            }
            return [$base, null];
        }
        if (strlen($d) < 1 || strlen($d) > 5) {
            return ['', "AMIS inválida (\"{$v}\"): debe tener 5 dígitos, o 6 con el verificador"];
        }
        return [str_pad($d, 5, '0', STR_PAD_LEFT), null];
    }

    /** Errores de una fila (vacío = importable). */
    public static function validar(array $f): array
    {
        $err = [];
        $faltan = array_values(array_filter(self::OBLIGATORIAS, static fn ($c) => trim((string) ($f[$c] ?? '')) === ''));
        if ($faltan !== []) {
            $err[] = 'faltan ' . implode(', ', $faltan);
        }
        if (trim((string) ($f['amis'] ?? '')) !== '') {
            [, $e] = self::amis((string) $f['amis']);
            if ($e !== null) {
                $err[] = $e;
            }
        }
        $anio = (string) ($f['anio'] ?? '');
        if ($anio !== '' && (!preg_match('/^\d{4}$/', $anio) || (int) $anio < 1950 || (int) $anio > (int) date('Y') + 2)) {
            $err[] = "año inválido (\"{$anio}\")";
        }
        $desc = (string) ($f['descuento_pct'] ?? '');
        if ($desc !== '' && (!preg_match('/^\d{1,3}$/', $desc) || (int) $desc > 100)) {
            $err[] = "descuento inválido (\"{$desc}\"): entero de 0 a 100";
        }
        foreach (self::columnas() as $c) {
            if (preg_match('/_(suma|prima|primer|siguientes)$|^(prima_neta|tasa_fin_pf|gtos_exped_pol|subtotal|iva|importe_total|comision_pct|comision_importe)$/', $c)
                && ($f[$c] ?? '') !== '' && self::numero((string) $f[$c]) === false
                && !preg_match('/^amparad[ao]$/i', trim((string) $f[$c]))) {
                $err[] = "{$c} no es un número (\"{$f[$c]}\")";
            }
        }
        return $err;
    }

    /**
     * Revisa y, si $aplicar, importa. Todo en una transacción.
     *
     * @return array{insertados:int, actualizados:int, sin_cambios:int, rechazadas:list<array{fila:int, vehiculo:string, motivos:list<string>}>}
     */
    public static function importar(PDO $pdo, string $archivo, bool $aplicar = false, ?string $fechaFuente = null): array
    {
        $r = ['insertados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'rechazadas' => []];
        $filas = self::leer($archivo);
        $fechaFuente ??= date('Y-m-d');

        $pdo->beginTransaction();
        try {
            foreach ($filas as $n => $f) {
                $err = self::validar($f);
                if ($err !== []) {
                    $r['rechazadas'][] = [
                        'fila' => $n,
                        'vehiculo' => trim(($f['vehiculo_sugerido'] ?? '') !== '' ? $f['vehiculo_sugerido'] : (($f['marca'] ?? '') . ' ' . ($f['linea'] ?? ''))) ?: '(sin nombre)',
                        'motivos' => $err,
                    ];
                    continue;
                }
                $estado = self::guardar($pdo, $f, $n, basename($archivo), $fechaFuente);
                $r[$estado]++;
            }
            $aplicar ? $pdo->commit() : $pdo->rollBack();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $r;
    }

    /** @return 'insertados'|'actualizados'|'sin_cambios' */
    private static function guardar(PDO $pdo, array $f, int $n, string $archivo, string $fechaFuente): string
    {
        [$amis] = self::amis($f['amis']);
        $modelo = (int) $f['anio'];
        $num = static fn (string $c): ?float => ($v = self::numero((string) ($f[$c] ?? ''))) === false ? null : $v;

        // ── cat_qua_vehiculos ──────────────────────────────────────────
        $veh = [
            'marca' => mb_strtoupper($f['marca']), 'linea' => mb_strtoupper($f['linea']), 'version' => mb_strtoupper($f['version_exacta']),
        ];
        $st = $pdo->prepare("SELECT id, marca, linea, version FROM cat_qua_vehiculos WHERE amis = ? AND modelo = ? AND fuente = 'PORTAL_MANUAL'");
        $st->execute([$amis, $modelo]);
        $previo = $st->fetch(PDO::FETCH_ASSOC);
        $cambioVeh = false;
        if ($previo === false) {
            $pdo->prepare("INSERT INTO cat_qua_vehiculos (amis, modelo, marca, linea, version, fuente, fecha_fuente) VALUES (?,?,?,?,?, 'PORTAL_MANUAL', ?)")
                ->execute([$amis, $modelo, $veh['marca'], $veh['linea'], $veh['version'], $fechaFuente]);
        } elseif ([$previo['marca'], $previo['linea'], $previo['version']] !== [$veh['marca'], $veh['linea'], $veh['version']]) {
            $pdo->prepare('UPDATE cat_qua_vehiculos SET marca = ?, linea = ?, version = ?, fecha_fuente = ?, activo = 1 WHERE id = ?')
                ->execute([$veh['marca'], $veh['linea'], $veh['version'], $fechaFuente, $previo['id']]);
            $cambioVeh = true;
        }

        // ── cat_qua_referencias_portal ─────────────────────────────────
        $coberturas = [];
        foreach (self::COBERTURAS as $k => [$no, $nombre]) {
            $s = (string) ($f["{$k}_suma"] ?? '');
            $d = (string) ($f["{$k}_deducible"] ?? '');
            $p = (string) ($f["{$k}_prima"] ?? '');
            if ($s === '' && $d === '' && $p === '') {
                continue;
            }
            $coberturas[] = ['cobertura' => $no, 'clave' => $k, 'nombre' => $nombre, 'suma' => $s, 'deducible' => $d, 'prima' => ($v = self::numero($p)) === false ? null : $v];
        }
        $formas = [];
        foreach (self::FORMAS as $fp) {
            if (($f["{$fp}_primer"] ?? '') !== '' || ($f["{$fp}_siguientes"] ?? '') !== '') {
                $formas[$fp] = ['primer' => $num("{$fp}_primer"), 'siguientes' => $num("{$fp}_siguientes")];
            }
        }
        $clave = [$amis, $modelo, mb_strtoupper($f['uso']), mb_strtoupper((string) ($f['paquete'] ?? '')), mb_strtoupper((string) ($f['forma_pago'] ?? '')), (string) (int) $f['descuento_pct'], (string) ($f['cp'] ?? '')];
        $datos = [
            'estado' => (string) ($f['estado'] ?? ''), 'pronto_pago_dias' => (string) ($f['pronto_pago_dias'] ?? ''),
            'coberturas_json' => json_encode($coberturas, JSON_UNESCAPED_UNICODE),
            'prima_neta' => $num('prima_neta'), 'tasa_fin_pf' => $num('tasa_fin_pf'), 'gtos_exped_pol' => $num('gtos_exped_pol'),
            'subtotal' => $num('subtotal'), 'iva' => $num('iva'), 'importe_total' => $num('importe_total'),
            'comision_pct' => $num('comision_pct'), 'comision_importe' => $num('comision_importe'),
            'formas_pago_json' => json_encode((object) $formas, JSON_UNESCAPED_UNICODE),
            'tarifa_aplicada' => (string) ($f['tarifa_aplicada'] ?? ''), 'numero_cotizacion_portal' => (string) ($f['numero_cotizacion_portal'] ?? ''),
            'fecha_cotizacion' => (string) ($f['fecha_cotizacion'] ?? ''), 'notas' => (string) ($f['notas'] ?? ''),
        ];
        $st = $pdo->prepare('SELECT * FROM cat_qua_referencias_portal WHERE amis = ? AND modelo = ? AND uso = ? AND paquete = ? AND forma_pago = ? AND descuento_pct = ? AND cp = ?');
        $st->execute($clave);
        $ref = $st->fetch(PDO::FETCH_ASSOC);
        $origen = [($f['archivo_origen'] ?? '') !== '' ? $f['archivo_origen'] : $archivo, $n];

        if ($ref === false) {
            $cols = array_merge(['amis', 'modelo', 'uso', 'paquete', 'forma_pago', 'descuento_pct', 'cp'], array_keys($datos), ['archivo_origen', 'fila_origen']);
            $pdo->prepare('INSERT INTO cat_qua_referencias_portal (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_merge($clave, array_values($datos), $origen));
            return $previo === false ? 'insertados' : 'actualizados';
        }
        $igual = true;
        foreach ($datos as $c => $v) {
            if ((string) $ref[$c] !== (string) $v && !(is_float($v) && $ref[$c] !== null && abs((float) $ref[$c] - $v) < 0.0001)) {
                $igual = false;
                break;
            }
        }
        if (!$igual) {
            $sets = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($datos)));
            $pdo->prepare("UPDATE cat_qua_referencias_portal SET {$sets}, archivo_origen = ?, fila_origen = ?, importado_en = datetime('now','localtime') WHERE id = ?")
                ->execute(array_merge(array_values($datos), $origen, [$ref['id']]));
        }
        if ($previo === false) {
            return 'insertados';
        }
        return ($cambioVeh || !$igual) ? 'actualizados' : 'sin_cambios';
    }
}
