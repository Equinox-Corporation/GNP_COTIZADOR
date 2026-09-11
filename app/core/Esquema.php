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

-- Valores permitidos por cobertura (suma asegurada / deducible), por tipo de
-- vehículo. `cat_coberturas` sólo trae el default de cada paquete, NO el menú
-- de opciones que GNP realmente maneja — hallazgo de la Tarea A del módulo
-- Juega y Compara (ver ADR-007 punto 1). Esta tabla sí lo trae: semilla de 225
-- filas extraída del kit, cargada por importar_valores_coberturas.php.
CREATE TABLE IF NOT EXISTS cat_cobertura_valores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    grupo          TEXT    NOT NULL,             -- AUTO | MOTO
    cve_cobertura  TEXT    NOT NULL,
    tipo_valor     TEXT    NOT NULL,             -- SUMA_ASEGURADA | DEDUCIBLE
    valor          TEXT    NOT NULL,
    orden          INTEGER NOT NULL DEFAULT 0,   -- orden real del kit; el valor no siempre es numérico ("Amparada", "10 UMAS")
    UNIQUE (grupo, cve_cobertura, tipo_valor, valor)
);
CREATE INDEX IF NOT EXISTS ix_cobval_busqueda ON cat_cobertura_valores (grupo, cve_cobertura, tipo_valor);

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

-- Paquete propio de Equinox: un paquete base de GNP + coberturas elegidas a la
-- medida (módulo Juega y Compara). Catálogo administrado, no capturado a mano
-- en cada venta — mismo espíritu que cat_paquetes. Ver ADR-007 punto 5.
CREATE TABLE IF NOT EXISTS cat_plantillas (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre        TEXT    NOT NULL UNIQUE,      -- "Equinox Agente de Seguros y de Fianzas"
    cve_paquete   TEXT    NOT NULL,             -- el paquete base de GNP (cat_paquetes.cve_paquete)
    activo        INTEGER NOT NULL DEFAULT 1,
    creado_en     TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Coberturas de la plantilla, con el valor elegido para cada una.
-- ADR-007 punto 3 (confirmado contra GNP el 2026-09-10): sólo se pueden tomar
-- coberturas que el paquete base YA trae como Básica u Opcional — no se puede
-- salir de ahí. La capa que arma la plantilla es responsable de no ofrecer nada
-- fuera de ese conjunto.
CREATE TABLE IF NOT EXISTS cat_plantilla_coberturas (
    plantilla_id   INTEGER NOT NULL REFERENCES cat_plantillas(id) ON DELETE CASCADE,
    cve_cobertura  TEXT    NOT NULL,            -- cat_coberturas.cve_cobertura
    suma_asegurada TEXT,                        -- debe existir en cat_coberturas para esa clave
    deducible      TEXT,
    PRIMARY KEY (plantilla_id, cve_cobertura)
);
CREATE INDEX IF NOT EXISTS ix_plancob_plantilla ON cat_plantilla_coberturas (plantilla_id);

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

-- Coberturas de una plantilla que NO se transmitieron a GNP para este
-- resultado (ej. "Siempre en Agencia" fuera de rango de antigüedad — ver
-- docs/02.12-bug-amparada.md). Hermana de cot_resultado_coberturas, para el
-- caso contrario: nunca se le oculta al vendedor que algo se dejó fuera,
-- aunque ningún otro paquete comparado la traiga (módulo "Juega y Compara").
CREATE TABLE IF NOT EXISTS cot_resultado_omitidas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    resultado_id   INTEGER NOT NULL REFERENCES cot_resultados(id) ON DELETE CASCADE,
    cve_cobertura  TEXT    NOT NULL,
    nombre         TEXT    NOT NULL,
    motivo         TEXT    NOT NULL,
    orden          INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_resomi ON cot_resultado_omitidas (resultado_id, orden);

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

        // 10-sep-2026: módulo Juega y Compara (ADR-007) — cotizar con una
        // plantilla propia sin perder el deducible elegido, ni de dónde salió
        // la cobertura. `plantilla_id` queda NULL para el flujo manual que
        // `cotizar.php` ya usaba: no cambia su comportamiento.
        $hay = $columnas('cot_opcionales');
        if (!in_array('deducible', $hay, true)) {
            $pdo->exec("ALTER TABLE cot_opcionales ADD COLUMN deducible TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('plantilla_id', $hay, true)) {
            $pdo->exec('ALTER TABLE cot_opcionales ADD COLUMN plantilla_id INTEGER NULL REFERENCES cat_plantillas(id)');
        }

        // 10-sep-2026: regla genérica de antigüedad máxima, no un caso
        // especial de "Siempre en Agencia" — cualquier cobertura puede tener
        // un límite de antigüedad del vehículo; hoy sólo una lo usa.
        // NULL = sin restricción de antigüedad (todas las demás coberturas).
        // Regla confirmada contra producción (docs/02.12-bug-amparada.md):
        // se acepta si MODELO >= año de vigencia − antiguedad_max_anios.
        if (!in_array('antiguedad_max_anios', $columnas('cat_coberturas'), true)) {
            $pdo->exec('ALTER TABLE cat_coberturas ADD COLUMN antiguedad_max_anios INTEGER NULL');
        }

        // 10-sep-2026: módulo "GNP Juega y Compara" (Paso 2, ADR-007) —
        // comparar varias plantillas en una sola cotización. `plantilla_id`
        // dice de qué plantilla salió cada renglón de `cot_resultados`; NULL
        // para "GNP Cotizador" de siempre (flujo manual), que no cambia.
        //
        // Requiere reconstruir la tabla: dos de las cuatro plantillas Equinox
        // reales (Amplia Plus y Amplia) comparten el mismo `cve_paquete` de
        // GNP (ver docs/02-carga de plantillas), así que el
        // UNIQUE(cotizacion_id, cve_paquete) original ya no puede sostenerse
        // tal cual — bloquearía comparar esas dos plantillas en la misma
        // cotización. No se reemplaza por un UNIQUE de tres columnas porque
        // SQLite trata cada NULL como distinto entre sí en un índice único
        // (`plantilla_id` es NULL en todo el flujo manual), así que un
        // UNIQUE(cotizacion_id, cve_paquete, plantilla_id) no protegería nada
        // ahí — y esta unicidad nunca se aprovechó con `ON CONFLICT` en
        // `CotizacionServicio::guardarResultados()`, sólo era una red de
        // seguridad. La duplicidad de paquete dentro de una misma cotización
        // la evita la pantalla (no se puede marcar el mismo paquete dos
        // veces), no la base.
        if (!in_array('plantilla_id', $columnas('cot_resultados'), true)) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            // v_cotizaciones cuenta sobre cot_resultados por subconsulta: si no se
            // quita antes de tirar la tabla, queda una vista colgando de una tabla
            // que ya no existe y el RENAME de abajo truena al validar el esquema.
            $pdo->exec('DROP VIEW IF EXISTS v_cotizaciones');
            $pdo->exec(<<<'SQL'
CREATE TABLE cot_resultados_nuevo (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    cotizacion_id  INTEGER NOT NULL REFERENCES cot_cotizaciones(id) ON DELETE CASCADE,
    cve_paquete    TEXT    NOT NULL,
    paquete        TEXT    NOT NULL,
    prima_tecnica  REAL    NULL,
    prima_neta     REAL    NULL,
    derechos       REAL    NULL,
    iva            REAL    NULL,
    descuento      REAL    NULL,
    total_pagar    REAL    NULL,
    num_pagos      INTEGER NULL,
    conceptos_json TEXT    NOT NULL DEFAULT '{}',
    plantilla_id   INTEGER NULL REFERENCES cat_plantillas(id)
)
SQL);
            $pdo->exec(
                'INSERT INTO cot_resultados_nuevo
                    (id, cotizacion_id, cve_paquete, paquete, prima_tecnica, prima_neta,
                     derechos, iva, descuento, total_pagar, num_pagos, conceptos_json)
                 SELECT id, cotizacion_id, cve_paquete, paquete, prima_tecnica, prima_neta,
                        derechos, iva, descuento, total_pagar, num_pagos, conceptos_json
                   FROM cot_resultados'
            );
            $pdo->exec('DROP TABLE cot_resultados');
            $pdo->exec('ALTER TABLE cot_resultados_nuevo RENAME TO cot_resultados');
            $pdo->exec(
                "CREATE VIEW v_cotizaciones AS
                 SELECT  c.id, c.folio, c.estado, c.creada_en, c.vence_en,
                         c.descripcion_veh, c.modelo, c.tipo_persona, c.procedencia,
                         c.contratante, c.conductor_edad, c.conductor_cp,
                         (SELECT COUNT(*) FROM cot_resultados r WHERE r.cotizacion_id = c.id)        AS paquetes,
                         (SELECT MIN(r.total_pagar) FROM cot_resultados r WHERE r.cotizacion_id = c.id) AS desde,
                         (SELECT MAX(r.total_pagar) FROM cot_resultados r WHERE r.cotizacion_id = c.id) AS hasta,
                         (SELECT COUNT(*) FROM cot_documentos d WHERE d.cotizacion_id = c.id)        AS pdfs,
                         CASE WHEN c.vence_en IS NULL THEN NULL
                              WHEN date(c.vence_en) < date('now','localtime') THEN 1 ELSE 0 END      AS vencida
                 FROM    cot_cotizaciones c"
            );
            $pdo->exec('PRAGMA foreign_keys = ON');
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
            // Confirmado el 11-sep-2026 al probar el armador libre (docs/02.13-
            // armador-libre-backend.md, caso 1): pedir Robo Parcial junto con
            // Robo Parcial Plus devuelve clave 37 desde "cotizador-eot" ("No es
            // posible contratar la cobertura de Robo Parcial y Robo Parcial Plus
            // porque son excluyentes"). No estaba en el kit original.
            ['Robo Parcial / Robo Parcial Plus', '0000001461', 1],  // Robo Parcial
            ['Robo Parcial / Robo Parcial Plus', '0000001462', 1],  // Robo Parcial Plus
        ] as $g) {
            $ex->execute($g);
        }

        // Antigüedad máxima confirmada contra producción el 10-sep-2026
        // (docs/02.12-bug-amparada.md): "Siempre en Agencia" se acepta si
        // MODELO >= año de vigencia − 4; con 3 (2021 rechazado) ya no.
        // Aparece en 3 paquetes (Amplia, Premium, Amplia Total) — misma
        // clave, misma regla en los tres.
        $pdo->exec("UPDATE cat_coberturas SET antiguedad_max_anios = 4 WHERE cve_cobertura = '0000001473'");

        // ADR-008 (piso mínimo por paquete) — dos correcciones confirmadas
        // contra producción el 11-sep-2026 al hacer el control de Amplia Total
        // sin <COBERTURAS> (sys_llamadas.id = 110):
        //
        // 1. "Club GNP" (0000001268) NUNCA aplica a Amplia Total — GNP
        //    devuelve ahí una cobertura distinta, "Club GNP Plus"
        //    (0000001687), que no existía en el catálogo. Confirmado que es
        //    exclusiva de Amplia Total: GNP la rechaza (clave 12) si se pide
        //    sobre Amplia (sys_llamadas.id = 114) — no se agrega a ningún
        //    otro paquete sin evidencia de que aplique ahí.
        if ((int) ($pdo->query(
            "SELECT COUNT(*) FROM cat_coberturas WHERE grupo='AUTO' AND paquete='AMPLIA TOTAL' AND cve_cobertura='0000001268'"
        )->fetchColumn()) > 0) {
            $pdo->exec("DELETE FROM cat_coberturas WHERE grupo='AUTO' AND paquete='AMPLIA TOTAL' AND cve_cobertura='0000001268'");
        }
        $pdo->exec(
            "INSERT INTO cat_coberturas (grupo, paquete, cve_cobertura, nombre, tipo, sa_valor, sa_unidad, ded_valor, ded_unidad)
             VALUES ('AUTO','AMPLIA TOTAL','0000001687','Club GNP Plus','BASICA','Amparada','N/A','N/A','N/A')
             ON CONFLICT (grupo, paquete, cve_cobertura) DO NOTHING"
        );
        $pdo->exec(
            "INSERT INTO cat_cobertura_valores (grupo, cve_cobertura, tipo_valor, valor, orden)
             VALUES ('AUTO','0000001687','SUMA_ASEGURADA','Amparada',1)
             ON CONFLICT (grupo, cve_cobertura, tipo_valor, valor) DO NOTHING"
        );

        // 2. "Eliminación de Deducible en Pérdidas Parciales" (0000001689)
        //    estaba marcada BASICA para Amplia Total — la única de sus cuatro
        //    apariciones en el catálogo (Amplia, Premium, Amplia Total,
        //    MOTO/Amplia) con ese tipo; en las otras tres es OPCIONAL.
        //    Descartada la hipótesis de antigüedad (el mismo control con el
        //    vehículo más nuevo disponible, modelo 2026, tampoco la trae por
        //    default — sys_llamadas.id = 115): es un error de captura, no una
        //    restricción como "Siempre en Agencia". Se corrige a OPCIONAL,
        //    igual que en los otros tres — no se borra el dato, se corrige.
        $pdo->exec(
            "UPDATE cat_coberturas SET tipo = 'OPCIONAL'
              WHERE grupo='AUTO' AND paquete='AMPLIA TOTAL' AND cve_cobertura='0000001689' AND tipo = 'BASICA'"
        );

        // 3. "Auto Sustituto" (0000001414) — NO es un hueco de catálogo como
        //    los dos anteriores: la clave y su tipo (BASICA para Auto Elite)
        //    ya estaban correctos. Lo único distinto es el nombre: en el
        //    control de Auto Elite (sys_llamadas.id = 113) GNP la devolvió
        //    como "AUTO SUSTITUTO PÉRDIDA TOTAL", más específico que el
        //    "Auto Sustituto" ya guardado. Se corrige el nombre SÓLO para la
        //    fila de Auto Elite, que es la única con evidencia real — las
        //    filas de Amplia y Amplia Total conservan "Auto Sustituto" porque
        //    ahí es Opcional y nunca se pidió para ver qué nombre devuelve
        //    GNP en ese contexto; no se asume que comparten el mismo matiz.
        $pdo->exec(
            "UPDATE cat_coberturas SET nombre = 'Auto Sustituto Pérdida Total'
              WHERE grupo='AUTO' AND paquete='AUTO ELITE' AND cve_cobertura='0000001414'"
        );

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

        // Una plantilla ficticia sólo para desarrollo — nunca en producción.
        // La primera plantilla real ("Equinox Agente de Seguros y de Fianzas")
        // la define Producto; esto es sólo para tener algo que ver en la
        // pantalla de administración mientras tanto. Ver ADR-007 punto 5.
        if (!Env::esProduccion()) {
            $pdo->prepare(
                'INSERT INTO cat_plantillas (nombre, cve_paquete, activo)
                 VALUES (?,?,?) ON CONFLICT (nombre) DO NOTHING'
            )->execute(['[PRUEBA DEV] Plantilla de ejemplo', 'PRS0009355', 1]);

            $idDev = $pdo->query(
                "SELECT id FROM cat_plantillas WHERE nombre = '[PRUEBA DEV] Plantilla de ejemplo'"
            )->fetchColumn();

            if ($idDev !== false) {
                $pc = $pdo->prepare(
                    'INSERT INTO cat_plantilla_coberturas (plantilla_id, cve_cobertura, suma_asegurada, deducible)
                     VALUES (?,?,?,?) ON CONFLICT (plantilla_id, cve_cobertura) DO NOTHING'
                );
                // Amplia (PRS0009355): GMO a 300,000 — valor ya confirmado contra
                // GNP en la prueba de docs/02.6-coberturas-modificadas.md.
                // Deducible '' (no 'N/A'): esta cobertura no tiene esa
                // dimensión en cat_cobertura_valores — un texto ahí se
                // transmitiría tal cual a GNP y lo rechaza (docs/02.9).
                $pc->execute([(int) $idDev, '0000000906', '300000', '']);
                // Accidentes al Conductor, opcional en Amplia, con su default.
                $pc->execute([(int) $idDev, '0000000893', '100000', '']);
            }
        }
    }
}
