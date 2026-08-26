<?php
declare(strict_types=1);

/**
 * Esquema — todas las tablas del cotizador GNP.
 *
 * Tres familias:
 *   cat_*   catálogos que vienen de GNP o del Excel del kit (espejo local)
 *   cot_*   las cotizaciones que hace la gente y sus resultados
 *   sys_*   usuarios, sesiones y bitácora de llamadas
 */
final class Esquema
{
    public static function asegurar(PDO $pdo): void
    {
        $pdo->exec(<<<SQL

-- ═══════════════════════════════════════════════════════════════════════════
-- CATÁLOGOS
-- ═══════════════════════════════════════════════════════════════════════════

-- Catálogos planos del WSP: PAQUETE, PERIODICIDAD, OCUPACION, SUB_RAMO…
-- Estructura uniforme CLAVE / NOMBRE / VALOR tal como los devuelve GNP.
CREATE TABLE IF NOT EXISTS cat_catalogos (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo_catalogo  TEXT    NOT NULL,
    filtros        TEXT    NOT NULL DEFAULT '',   -- JSON de los filtros usados
    clave          TEXT    NOT NULL,
    nombre         TEXT    NOT NULL DEFAULT '',
    valor          TEXT    NOT NULL DEFAULT '',
    orden          INTEGER NOT NULL DEFAULT 0,
    descargado_en  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (tipo_catalogo, filtros, clave)
);
CREATE INDEX IF NOT EXISTS ix_cat_tipo ON cat_catalogos (tipo_catalogo, orden);

-- Vehículos. La unidad cotizable es CLAVEMARCA + MODELO: la clave no lleva el año.
CREATE TABLE IF NOT EXISTS cat_vehiculos (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    clavemarca        TEXT    NOT NULL,
    tipo_vehiculo     TEXT    NOT NULL,
    armadora          TEXT    NOT NULL,
    armadora_nombre   TEXT    NOT NULL DEFAULT '',
    carroceria        TEXT    NOT NULL,           -- es la LÍNEA comercial
    carroceria_nombre TEXT    NOT NULL DEFAULT '',
    version           TEXT    NOT NULL,
    version_nombre    TEXT    NOT NULL DEFAULT '',
    modelo            INTEGER NOT NULL,           -- año
    alto_valor        INTEGER NOT NULL DEFAULT 0,
    altisimo_valor    INTEGER NOT NULL DEFAULT 0,
    descargado_en     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (clavemarca, modelo)
);
CREATE INDEX IF NOT EXISTS ix_veh_marca  ON cat_vehiculos (tipo_vehiculo, armadora);
CREATE INDEX IF NOT EXISTS ix_veh_linea  ON cat_vehiculos (tipo_vehiculo, armadora, carroceria);
CREATE INDEX IF NOT EXISTS ix_veh_anio   ON cat_vehiculos (tipo_vehiculo, armadora, carroceria, modelo);
CREATE INDEX IF NOT EXISTS ix_veh_texto  ON cat_vehiculos (carroceria_nombre);

-- Control del barrido de vehículos: permite reanudar sin repetir.
CREATE TABLE IF NOT EXISTS cat_vehiculos_avance (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo_vehiculo  TEXT    NOT NULL,
    armadora       TEXT    NOT NULL,
    nivel          TEXT    NOT NULL,             -- ARMADORA | MODELO | CARROCERIA | RESUELTA
    detalle        TEXT    NOT NULL DEFAULT '',
    estado         TEXT    NOT NULL,
    registros      INTEGER NOT NULL DEFAULT 0,
    ms             INTEGER NOT NULL DEFAULT 0,
    error_desc     TEXT    NULL,
    actualizado_en TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (tipo_vehiculo, armadora, nivel, detalle)
);

-- Matriz de claves de paquete. NO existe en el API: sale del Excel del kit.
-- Dice qué CVE_PAQUETE mandar según persona × paquete × procedencia × tipo.
CREATE TABLE IF NOT EXISTS cat_paquetes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo_persona   TEXT    NOT NULL,             -- F | M
    paquete        TEXT    NOT NULL,             -- AMPLIA, LIMITADA, RESPONSABILIDAD CIVIL…
    procedencia    TEXT    NOT NULL,             -- Residentes, Legalizados, Fronterizos…
    tipo_vehiculo  TEXT    NOT NULL,             -- AUT | CA1 | CA2 | MOT
    cve_paquete    TEXT    NOT NULL DEFAULT '',  -- '' = GNP no ofrece esa combinación
    disponible     INTEGER NOT NULL DEFAULT 0,
    activo         INTEGER NOT NULL DEFAULT 1,   -- lo apaga Comercial si no se coloca
    orden          INTEGER NOT NULL DEFAULT 0,
    UNIQUE (tipo_persona, paquete, procedencia, tipo_vehiculo)
);
CREATE INDEX IF NOT EXISTS ix_paq_cve ON cat_paquetes (cve_paquete);

-- Qué coberturas trae cada paquete y cuáles se pueden agregar. También del Excel.
CREATE TABLE IF NOT EXISTS cat_coberturas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    grupo          TEXT    NOT NULL,             -- AUTO | MOTO
    paquete        TEXT    NOT NULL,
    cve_cobertura  TEXT    NOT NULL,
    nombre         TEXT    NOT NULL,
    tipo           TEXT    NOT NULL,             -- BASICA | OPCIONAL
    sa_valor       TEXT    NOT NULL DEFAULT '',
    sa_unidad      TEXT    NOT NULL DEFAULT '',
    ded_valor      TEXT    NOT NULL DEFAULT '',
    ded_unidad     TEXT    NOT NULL DEFAULT '',
    UNIQUE (grupo, paquete, cve_cobertura)
);
CREATE INDEX IF NOT EXISTS ix_cob_paq ON cat_coberturas (grupo, paquete, tipo);

-- Coberturas que no se pueden pedir juntas.
-- GNP valida esto del lado suyo y rechaza la cotización completa, así que el
-- sistema tiene que impedirlo antes de llamar.
CREATE TABLE IF NOT EXISTS cat_coberturas_excluyentes (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    grupo_excl    TEXT    NOT NULL,             -- nombre del grupo, para el mensaje
    cve_cobertura TEXT    NOT NULL,
    verificado    INTEGER NOT NULL DEFAULT 0,   -- 1 = confirmado contra el servicio
    UNIQUE (grupo_excl, cve_cobertura)
);
CREATE INDEX IF NOT EXISTS ix_excl ON cat_coberturas_excluyentes (cve_cobertura);

-- Procedencia (como la nombra el Excel) ↔ SUB_RAMO (como lo pide el XML).
-- Sólo 01 = Residentes está verificado contra el servicio; el resto es por confirmar.
CREATE TABLE IF NOT EXISTS cat_procedencias (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    procedencia  TEXT    NOT NULL UNIQUE,
    sub_ramo     TEXT    NOT NULL DEFAULT '',
    verificado   INTEGER NOT NULL DEFAULT 0,
    orden        INTEGER NOT NULL DEFAULT 0
);

-- ═══════════════════════════════════════════════════════════════════════════
-- COTIZACIONES
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS cot_cotizaciones (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    folio             TEXT    NULL,              -- NUM_COTIZACION de GNP
    estado            TEXT    NOT NULL DEFAULT 'BORRADOR',
                      -- BORRADOR | COTIZADA | ERROR
    usuario_id        INTEGER NULL,

    -- Vehículo
    tipo_vehiculo     TEXT    NOT NULL,
    clavemarca        TEXT    NOT NULL DEFAULT '',
    armadora          TEXT    NOT NULL,
    carroceria        TEXT    NOT NULL,
    version           TEXT    NOT NULL,
    modelo            INTEGER NOT NULL,
    descripcion_veh   TEXT    NOT NULL DEFAULT '',
    sub_ramo          TEXT    NOT NULL DEFAULT '01',
    procedencia       TEXT    NOT NULL DEFAULT 'Residentes',

    -- Personas
    tipo_persona      TEXT    NOT NULL DEFAULT 'F',
    contratante       TEXT    NOT NULL DEFAULT '',
    contratante_edad  INTEGER NOT NULL DEFAULT 0,
    contratante_cp    TEXT    NOT NULL DEFAULT '',
    contratante_rfc   TEXT    NOT NULL DEFAULT '',
    conductor_edad    INTEGER NOT NULL DEFAULT 0,
    conductor_cp      TEXT    NOT NULL DEFAULT '',
    conductor_sexo    TEXT    NOT NULL DEFAULT 'M',
    correo            TEXT    NOT NULL DEFAULT '',

    periodicidad      TEXT    NOT NULL DEFAULT 'A',
    vigencia_inicio   TEXT    NOT NULL DEFAULT '',
    vigencia_fin      TEXT    NOT NULL DEFAULT '',
    vence_en          TEXT    NULL,              -- 15 días naturales

    error_desc        TEXT    NULL,
    creada_en         TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS ix_cot_folio  ON cot_cotizaciones (folio);
CREATE INDEX IF NOT EXISTS ix_cot_fecha  ON cot_cotizaciones (creada_en DESC);

-- Un renglón por paquete cotizado. Aquí viven los precios.
CREATE TABLE IF NOT EXISTS cot_resultados (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    cotizacion_id  INTEGER NOT NULL REFERENCES cot_cotizaciones(id) ON DELETE CASCADE,
    cve_paquete    TEXT    NOT NULL,
    paquete        TEXT    NOT NULL,
    prima_tecnica  REAL    NULL,
    prima_neta     REAL    NULL,
    derechos       REAL    NULL,
    iva            REAL    NULL,
    descuento      REAL    NULL,
    total_pagar    REAL    NULL,                 -- ← el precio. Nunca usar prima_neta.
    num_pagos      INTEGER NULL,
    conceptos_json TEXT    NOT NULL DEFAULT '{}',
    UNIQUE (cotizacion_id, cve_paquete)
);

CREATE TABLE IF NOT EXISTS cot_resultado_coberturas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    resultado_id   INTEGER NOT NULL REFERENCES cot_resultados(id) ON DELETE CASCADE,
    cve_cobertura  TEXT    NOT NULL,
    nombre         TEXT    NOT NULL,
    suma_asegurada TEXT    NOT NULL DEFAULT '',
    deducible      TEXT    NOT NULL DEFAULT '',
    orden          INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_rescob ON cot_resultado_coberturas (resultado_id, orden);

-- Coberturas opcionales que el vendedor pidió agregar.
CREATE TABLE IF NOT EXISTS cot_opcionales (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    cotizacion_id  INTEGER NOT NULL REFERENCES cot_cotizaciones(id) ON DELETE CASCADE,
    cve_cobertura  TEXT    NOT NULL,
    suma_asegurada TEXT    NOT NULL DEFAULT '',
    UNIQUE (cotizacion_id, cve_cobertura)
);

-- PDF que devuelve GNP. El archivo va a disco; aquí sólo la ficha.
CREATE TABLE IF NOT EXISTS cot_documentos (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    cotizacion_id  INTEGER NOT NULL REFERENCES cot_cotizaciones(id) ON DELETE CASCADE,
    cve_paquete    TEXT    NOT NULL DEFAULT '',
    archivo        TEXT    NOT NULL,             -- ruta relativa dentro de datos/pdf/
    bytes          INTEGER NOT NULL DEFAULT 0,
    referencia     TEXT    NOT NULL DEFAULT '',  -- PRPL… el acuse de envío de GNP
    generado_en    TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS ix_doc_cot ON cot_documentos (cotizacion_id);

-- ═══════════════════════════════════════════════════════════════════════════
-- SISTEMA
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS sys_usuarios (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario    TEXT    NOT NULL UNIQUE,
    nombre     TEXT    NOT NULL DEFAULT '',
    clave_hash TEXT    NOT NULL,
    es_admin   INTEGER NOT NULL DEFAULT 0,   -- puede entrar al panel de usuarios
    activo     INTEGER NOT NULL DEFAULT 1,
    creado_en  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Toda llamada al WSP queda registrada, incluidas las fallidas.
-- GNP pide evidencia para certificar. La contraseña nunca se escribe aquí.
CREATE TABLE IF NOT EXISTS sys_llamadas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    cotizacion_id  INTEGER NULL,
    servicio       TEXT    NOT NULL,             -- catalogo | vehiculos | cotizar | imprimir
    detalle        TEXT    NOT NULL DEFAULT '',
    estado         TEXT    NOT NULL,
    http           INTEGER NOT NULL DEFAULT 0,
    ms             INTEGER NOT NULL DEFAULT 0,
    bytes          INTEGER NOT NULL DEFAULT 0,
    error_clave    TEXT    NULL,
    error_origen   TEXT    NULL,
    error_desc     TEXT    NULL,
    -- Evidencia literal de la conversación con GNP. La contraseña ya viene
    -- enmascarada desde GnpClient::sinPassword(). Si el cuerpo es enorme
    -- (la impresión pasa de 1 MB por el PDF) se guarda sólo una nota.
    xml_entrada    TEXT    NOT NULL DEFAULT '',
    xml_salida     TEXT    NOT NULL DEFAULT '',
    ejecutado_en   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS ix_llam_fecha ON sys_llamadas (ejecutado_en DESC);

-- ═══════════════════════════════════════════════════════════════════════════
-- VISTAS
-- ═══════════════════════════════════════════════════════════════════════════

CREATE VIEW IF NOT EXISTS v_cotizaciones AS
SELECT  c.id, c.folio, c.estado, c.creada_en, c.vence_en,
        c.descripcion_veh, c.modelo, c.tipo_persona, c.procedencia,
        c.contratante, c.conductor_edad, c.conductor_cp,
        (SELECT COUNT(*) FROM cot_resultados r WHERE r.cotizacion_id = c.id)        AS paquetes,
        (SELECT MIN(r.total_pagar) FROM cot_resultados r WHERE r.cotizacion_id = c.id) AS desde,
        (SELECT MAX(r.total_pagar) FROM cot_resultados r WHERE r.cotizacion_id = c.id) AS hasta,
        (SELECT COUNT(*) FROM cot_documentos d WHERE d.cotizacion_id = c.id)        AS pdfs,
        CASE WHEN c.vence_en IS NULL THEN NULL
             WHEN date(c.vence_en) < date('now','localtime') THEN 1 ELSE 0 END      AS vencida
FROM    cot_cotizaciones c;

SQL);

        self::migrar($pdo);
        self::semillas($pdo);
    }

    /**
     * Cambios sobre bases que ya existían.
     *
     * CREATE TABLE IF NOT EXISTS no agrega columnas nuevas a una tabla que ya
     * está creada, así que las altas posteriores se aplican aquí a mano.
     */
    private static function migrar(PDO $pdo): void
    {
        $columnas = static function (string $tabla) use ($pdo): array {
            $n = [];
            foreach ($pdo->query("PRAGMA table_info({$tabla})") as $c) {
                $n[] = (string) $c['name'];
            }
            return $n;
        };

        // 25-ago-2026: se guarda el XML de ida y vuelta para poder descargarlo.
        $hay = $columnas('sys_llamadas');
        foreach (['xml_entrada', 'xml_salida'] as $col) {
            if (!in_array($col, $hay, true)) {
                $pdo->exec("ALTER TABLE sys_llamadas ADD COLUMN {$col} TEXT NOT NULL DEFAULT ''");
            }
        }

        // 25-ago-2026: rol de administrador, para el panel de usuarios.
        if (!in_array('es_admin', $columnas('sys_usuarios'), true)) {
            $pdo->exec('ALTER TABLE sys_usuarios ADD COLUMN es_admin INTEGER NOT NULL DEFAULT 0');
            // El usuario más antiguo pasa a administrador: si no, nadie podría
            // entrar al panel nuevo para darle el rol a alguien.
            $pdo->exec('UPDATE sys_usuarios SET es_admin = 1 WHERE id = (SELECT MIN(id) FROM sys_usuarios)');
        }
    }

    /** Datos que el sistema necesita para arrancar y no vienen del API. */
    private static function semillas(PDO $pdo): void
    {
        $st = $pdo->prepare(
            'INSERT INTO cat_procedencias (procedencia, sub_ramo, verificado, orden)
             VALUES (?,?,?,?) ON CONFLICT (procedencia) DO NOTHING'
        );
        // Grupo excluyente confirmado el 25 de agosto de 2026: pedir dos de estas
        // tres devuelve 400 con clave 37 desde el origen "cotizador-eot".
        $ex = $pdo->prepare(
            'INSERT INTO cat_coberturas_excluyentes (grupo_excl, cve_cobertura, verificado)
             VALUES (?,?,?) ON CONFLICT (grupo_excl, cve_cobertura) DO NOTHING'
        );
        foreach ([
            ['Auto Sustituto / Ayuda para Pérdidas Totales', '0000001414', 1],  // Auto Sustituto
            ['Auto Sustituto / Ayuda para Pérdidas Totales', '0000001415', 1],  // Auto Sustituto Plus
            ['Auto Sustituto / Ayuda para Pérdidas Totales', '0000001348', 1],  // Ayuda para Pérdidas Totales
        ] as $g) {
            $ex->execute($g);
        }

        // Sólo Residentes está verificado contra el servicio (cotización 02.1 del 18-ago).
        // Los demás sub_ramo hay que confirmarlos con GNP antes de ofrecerlos.
        foreach ([
            ['Residentes',  '01', 1, 1],
            ['Legalizados', '',   0, 2],
            ['Fronterizos', '',   0, 3],
            ['Clásicos',    '',   0, 4],
            ['Antiguos',    '',   0, 5],
            ['Importado',   '',   0, 6],
            ['Blindado',    '',   0, 7],
        ] as $p) {
            $st->execute($p);
        }
    }
}
