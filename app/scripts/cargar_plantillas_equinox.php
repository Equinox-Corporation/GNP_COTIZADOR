#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cargar_plantillas_equinox.php — Reproduce las 4 plantillas reales de
 * Equinox (Amplia Plus, Amplia, Limitada, RC/Básica) a partir del cruce que
 * armó Beto (2026-09-10). Mismo patrón que importar_valores_coberturas.php:
 * CLI, idempotente.
 *
 * Usa PlantillaServicio::guardar() — NO un INSERT directo — para pasar por
 * la misma validación (cat_cobertura_valores, cat_coberturas_excluyentes,
 * pertenencia al paquete) que ya se usó al cargarlas a mano.
 *
 * Los valores de cada cobertura (suma/deducible) son una transcripción
 * exacta de lo que ya quedó vivo en `cat_plantilla_coberturas` para los ids
 * 75, 76, 77 y 80 el 2026-09-10 — leídos de la base real, no rearmados de
 * memoria, para no introducir una diferencia de transcripción.
 *
 * ADVERTENCIA — bug conocido, reproducido tal cual (ver
 * docs/02.11-multipaquete-plantillas.md): las coberturas de estatus fijo
 * (Club GNP, Robo Parcial Plus, Eliminación de Deducible en Pérdidas
 * Parciales) guardan `suma_asegurada = "Amparada"`, valor que GNP RECHAZA
 * como entrada ("For input string: Amparada"). No se corrige aquí a
 * propósito — es un cambio de datos aparte, todavía sin decidir. Cotizar
 * hoy con cualquiera de estas 4 plantillas fallará hasta que se corrija.
 *
 * Idempotente: si una plantilla con ese nombre ya existe, se actualiza en
 * vez de duplicarse (mismo id, mismos datos si no cambió nada).
 *
 * Uso:
 *   php app/scripts/cargar_plantillas_equinox.php
 */

require __DIR__ . '/_arranque.php';
require RUTA_APP . '/servicios/PlantillaServicio.php';

// cve_paquete confirmados contra cat_paquetes (F / Residentes / AUT):
// Amplia Plus NO existe como paquete propio de GNP — usa el mismo cve_paquete
// que Amplia (ver docs/02.10-rc-accidentes-conductor.md y la carga original).
$plantillas = [
    'Equinox Amplia Plus' => [
        'cve_paquete' => 'PRS0009355',
        'coberturas' => [
            ['cve' => '0000001289', 'suma' => '',        'deducible' => '3'],
            ['cve' => '0000001288', 'suma' => '',        'deducible' => '3'],
            ['cve' => '0000000916', 'suma' => '',        'deducible' => '5'],
            ['cve' => '0000001273', 'suma' => '3000000', 'deducible' => ''],
            ['cve' => '0000000906', 'suma' => '300000',  'deducible' => ''],
            ['cve' => '0000001285', 'suma' => '3000000', 'deducible' => ''],
            ['cve' => '0000001452', 'suma' => '2000000', 'deducible' => ''],
            ['cve' => '0000000893', 'suma' => '100000',  'deducible' => ''],
            ['cve' => '0000001268', 'suma' => 'Amparada','deducible' => ''],
            ['cve' => '0000001462', 'suma' => 'Amparada','deducible' => '25'],
            ['cve' => '0000001470', 'suma' => '25000',   'deducible' => '25'],
            ['cve' => '0000001689', 'suma' => 'Amparada','deducible' => ''],
            ['cve' => '0000001473', 'suma' => 'Amparada','deducible' => ''],
        ],
    ],
    'Equinox Amplia' => [
        'cve_paquete' => 'PRS0009355',
        'coberturas' => [
            ['cve' => '0000001289', 'suma' => '',        'deducible' => '3'],
            ['cve' => '0000001288', 'suma' => '',        'deducible' => '3'],
            ['cve' => '0000000916', 'suma' => '',        'deducible' => '5'],
            ['cve' => '0000001273', 'suma' => '3000000', 'deducible' => ''],
            ['cve' => '0000000906', 'suma' => '300000',  'deducible' => ''],
            ['cve' => '0000001285', 'suma' => '3000000', 'deducible' => ''],
            ['cve' => '0000001452', 'suma' => '2000000', 'deducible' => ''],
            ['cve' => '0000000893', 'suma' => '100000',  'deducible' => ''],
            ['cve' => '0000001268', 'suma' => 'Amparada','deducible' => ''],
        ],
    ],
    'Equinox Limitada' => [
        'cve_paquete' => 'PRS0009356',
        'coberturas' => [
            ['cve' => '0000000916', 'suma' => '',        'deducible' => '5'],
            ['cve' => '0000001273', 'suma' => '3000000', 'deducible' => ''],
            ['cve' => '0000000906', 'suma' => '300000',  'deducible' => ''],
            ['cve' => '0000001285', 'suma' => '3000000', 'deducible' => ''],
            ['cve' => '0000001452', 'suma' => '2000000', 'deducible' => ''],
            ['cve' => '0000000893', 'suma' => '100000',  'deducible' => ''],
            ['cve' => '0000001268', 'suma' => 'Amparada','deducible' => ''],
        ],
    ],
    // Sin Accidentes al Conductor: GNP la rechaza sobre este paquete
    // (docs/02.10-rc-accidentes-conductor.md) — cat_coberturas ya está
    // correcta, no es un dato que falte cargar.
    'Equinox RC / Básica' => [
        'cve_paquete' => 'PRP0000289',
        'coberturas' => [
            ['cve' => '0000001273', 'suma' => '3000000', 'deducible' => ''],
            ['cve' => '0000000906', 'suma' => '300000',  'deducible' => ''],
            ['cve' => '0000001285', 'suma' => '3000000', 'deducible' => ''],
            ['cve' => '0000001452', 'suma' => '2000000', 'deducible' => ''],
            ['cve' => '0000001268', 'suma' => 'Amparada','deducible' => ''],
        ],
    ],
];

echo "Carga de plantillas Equinox\n\n";

foreach ($plantillas as $nombre => $def) {
    $existente = Db::valor('SELECT id FROM cat_plantillas WHERE nombre = ?', [$nombre]);
    $id = $existente !== null ? (int) $existente : null;

    $r = PlantillaServicio::guardar($id, $nombre, $def['cve_paquete'], true, $def['coberturas']);

    if (!$r['ok']) {
        echo "   {$nombre}: RECHAZADA — {$r['mensaje']}\n";
        continue;
    }

    $accion = $id === null ? 'creada' : 'ya existía, sin cambios de fondo';
    echo '   ' . pad($nombre, 24) . "id={$r['id']}  ({$accion}, " . count($def['coberturas']) . " coberturas)\n";
}

echo "\nADVERTENCIA: estas 4 plantillas tienen suma_asegurada=\"Amparada\" en\n";
echo "coberturas de estatus fijo (Club GNP y otras) — GNP la rechaza como\n";
echo "valor de entrada. Ver docs/02.11-multipaquete-plantillas.md. No\n";
echo "cotizar con ellas hasta corregir ese dato.\n";
