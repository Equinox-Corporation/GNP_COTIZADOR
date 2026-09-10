# ADR-007 — Módulo Juega y Compara: paquetes propios sobre coberturas configurables

## 📌 Estado

**Propuesto** (Claude, 2026-09-10). Producto/Negocio ⏳ · TI/Arquitectura ⏳.
Puntos 2 y 3 confirmados contra producción (CC, 2026-09-10 — ver `docs/02.6-coberturas-modificadas.md`). Sigue pendiente de aprobación formal antes de conectar el flujo real de cotización.

Se apoya en [ADR-001](../01_Generales/ADR-001-que-es-el-cotizador-gnp.md) (qué es el proyecto), [ADR-003](../02_Arquitectura/ADR-003-modelo-de-datos.md) (modelo de datos), [ADR-004](./ADR-004-catalogo-maestro-propio.md) (catálogo maestro) y [ADR-005](./ADR-005-reglas-verificadas-gnp.md) (reglas verificadas contra GNP).

> Nota de origen: este ADR lo redactó Claude a petición de Beto, a partir de la biblioteca de ADR-001 a ADR-006, del kit de conexión IC260812018 (hoja `COBERTURAS JUEGA Y COMPARA`) y del plan de trabajo original del cotizador GNP dentro de NEXO 2.0. No tuve acceso al código fuente de `cotizador-gnp` — sólo a esta documentación. Cualquier afirmación `[CONFIRMADO]` de este documento viene de otro ADR o de un archivo del kit, nunca de haber leído código directamente.

## 🧠 Contexto

El objetivo de negocio es armar paquetes propios de Equinox — "Equinox Agente de Seguros y de Fianzas" es el primero — eligiendo coberturas, sumas aseguradas y deducibles a la medida, siempre dentro de lo que GNP permite. Ya no un plan fijo tipo Amplia o Amplia Plus, sino un paquete compuesto por Equinox.

**Lo que ya existe, y no hay que rehacer:**

- `cat_coberturas` (167 filas) ya trae qué coberturas trae cada paquete (Básica/Opcional) y su valor por omisión — es, literalmente, la hoja `COBERTURAS JUEGA Y COMPARA` del kit de GNP, ya cargada en base de datos. _(CC, 2026-09-10: el menú completo de valores permitidos por cobertura no vive aquí, sino en `cat_cobertura_valores` — ver punto 1.)_
- `cat_coberturas_excluyentes` (3 filas) ya tiene codificada una exclusión mutua entre **Auto Sustituto** (`0000001414`), **Auto Sustituto Plus** (`0000001415`) y **Ayuda para Pérdidas Totales** (`0000001348`) — un trío bajo un mismo `grupo_excl`, no pares sueltos. _(CC, 2026-09-10: corregido — el ejemplo original de este ADR (Robo Parcial / Robo Parcial Plus) fue una suposición razonable sin acceso a código, pero no es lo que hay cargado realmente; verificado que las tres combinaciones del trío quedan bloqueadas por `CatalogoServicio::chocanEntreSi()`, y la exclusión de la clave 37 se confirmó contra producción el mismo día, ver ADR-005 punto 6.)_
- `cat_paquetes` (392 filas) ya tiene la matriz de paquetes por persona × procedencia × tipo de vehículo.
- El cliente GNP (`GnpClient.php`) ya sabe construir XML, enmascarar contraseña, clasificar errores por `ORIGEN` y registrar bitácora — todo lo que este módulo necesita para hablar con GNP ya está resuelto en ADR-005 y ADR-006.
- `cot_opcionales` existe como tabla — pensada, por su nombre, para guardar coberturas opcionales elegidas dentro de una cotización.

**Lo que no existe, y es la razón de este ADR:**

- `cot_opcionales` tiene **0 filas**. Nunca se ha usado. No hay evidencia de que el cotizador haya mandado alguna vez un `<COBERTURAS>` con valores distintos a los del paquete por defecto.
- No hay ninguna pantalla ni servicio que arme un paquete propio reutilizable ("Equinox Agente de Seguros y de Fianzas") — hoy sólo se cotizan los paquetes de GNP tal cual.

**La conclusión objetiva:** el trabajo de datos ya está hecho. Lo que falta es (a) probar contra producción que GNP acepta y tarifica correctamente un `<COBERTURAS>` modificado, y (b) construir el mecanismo para guardar y reutilizar un paquete propio. Es menos trabajo del que parecía, pero el primer punto es una incógnita real, no un detalle.

## ⚖️ Decisión

### 1. "Juega y Compara" es selección dentro de menús cerrados, ya cargados `[CONFIRMADO]`

_(CC, 2026-09-10)_ — Es elegir, por cobertura, entre valores fijos — nunca personalización ilimitada. Con una corrección de dónde vive ese menú: **no está en `cat_coberturas`**, que sólo trae el default de cada paquete (hallazgo de la Tarea A). El menú real llegó por separado, en `docs/Auxiliares/cat_cobertura_valores_seed.csv` (225 filas del kit), y ya está cargado en la tabla nueva `cat_cobertura_valores` vía `app/scripts/importar_valores_coberturas.php`. Confirmado que cubre las 29 claves de `cat_coberturas` sin huecos.

El ejemplo original de este punto resultó exacto una vez cargado: Gastos Médicos Ocupantes admite 100,000 / 120,000 / 150,000 / 200,000 / 300,000 … hasta 1,000,000 — es, de hecho, el mismo menú contra el que se probó y confirmó el punto 2 (GNP acepta 300,000, rechaza 250,000, y 250,000 en efecto no aparece en esta lista). La pantalla de plantillas (Tarea C) ya usa este menú para selectores reales en vez de texto libre, y `PlantillaServicio::guardar()` valida contra él antes de tocar la base.

### 2. Enviar `<COBERTURAS>` modificado a GNP: `[CONFIRMADO]`

Probado contra producción el 2026-09-10 (`docs/02.6-coberturas-modificadas.md`). GNP acepta el `<COBERTURAS>` modificado dentro de un `<PAQUETE>`, tarifica de forma coherente (Gastos Médicos Ocupantes de 200,000 a 300,000 movió `TOTAL_PAGAR` en +21.03, consistente con el peso relativo de esa cobertura frente a Daños Materiales/Robo Total), y la devuelve reflejada tal cual en la respuesta. `GnpClient.php` ya soportaba esto desde el 25 de agosto (parámetro `$opcionales` de `cotizar()`) — no hizo falta tocar código, sólo ejercitarlo por primera vez.

Una combinación fuera de la lista permitida (GMO en 250,000) se rechaza con un error identificable, no un `<e>` genérico: `CLAVE 14`, `ORIGEN cotizador-eot`, mensaje que nombra la clave de cobertura exacta y el motivo. Nota aparte: `cotizador-eot` no está en la taxonomía de [ADR-005 punto 6](./ADR-005-reglas-verificadas-gnp.md) — cae en `E_DATOS` por la regla de respaldo (clave ≥ 2), así que no rompe nada, pero conviene agregarlo la próxima vez que se toque ese ADR.

**Nota de sanidad — investigada hasta donde la evidencia disponible permite, sin quedar `[CONFIRMADO]`** _(Beto + CC, 2026-09-10)_: el caso Control de esta prueba dio `TOTAL_PAGAR` 8,183.48 para el mismo vehículo/edades/CP/paquete que en la prueba del 18 de agosto dio 9,005.12 — ~9% de diferencia en tres semanas.

No se pudo diffear byte a byte porque **no existe el XML crudo de la prueba del 18 de agosto**: esa prueba se hizo en Postman, antes de que `cotizador-gnp` existiera como proyecto independiente y antes de la bitácora automática (`sys_llamadas` arranca el 25 de agosto — no tiene nada de fechas anteriores). Lo único que quedó de esa sesión es un resumen narrativo (`chat_export_GNP_POSTMAN_2026-08-18_1313.md`, en OneDrive, fuera del repo) con los parámetros de entrada en prosa y la tabla de desglose del precio — no el cuerpo XML completo.

Más allá de eso, el experimento en sí no es repetible de forma limpia: GNP tarifica con su tabla vigente al momento de la llamada, así que ninguna prueba nueva —por más que use los mismos parámetros— puede aislar "misma tarifa, distinta fecha". No es una evidencia que falte encontrar; es un experimento que no se puede correr contra un servicio en vivo sin ambiente histórico.

Lo que sí se pudo comparar: los parámetros documentados de ambas pruebas coinciden (mismo vehículo, mismas edades, mismo CP, mismo paquete) — sólo cambia la vigencia, que necesariamente se mueve con la fecha de cada prueba. Con eso, la explicación más probable es actualización normal de tarifa de GNP en esas tres semanas, consistente con lo que el propio ADR-005 ya anota sobre `DERECHOS_POLIZA` ("observado constante... no se asume que siempre lo sea"): GNP mueve sus números sin aviso, y no hay ambiente de pruebas para congelarlos.

**Cierre:** no `[CONFIRMADO]` — no hay prueba dura, sólo la explicación más probable. Se cierra como investigado hasta donde la evidencia disponible permite; no se reabre a menos que aparezca una razón concreta para dudar de que sea sólo tarifa viva.

### 3. Salir del paquete base: `[CONFIRMADO]` — no se puede

Probado con una cobertura real de otro grupo (Asistencia Vial Moto, exclusiva de motocicletas) sobre un paquete Amplia/AUTO. GNP la rechaza: `CLAVE 12`, `ORIGEN cotizador-eot`, "La cobertura no existe. No aplica para este producto." No se probó el caso exacto de "Básica en Premium, N/A en Amplia" porque no existe hoy en los datos cargados de `cat_coberturas` — las 27 claves de AUTO aparecen en las cuatro matrices por igual, sólo cambia BASICA/OPCIONAL. La prueba hecha (cruzar tipo de vehículo) es una prueba más exigente del mismo principio, no una más débil.

**Decisión de alcance, ahora confirmada como la única opción viable, no sólo la más prudente:** las coberturas ofrecidas al armar un paquete propio se limitan a las que el paquete base ya trae (Básica u Opcional).

### 4. El paquete propio se guarda como plantilla reutilizable, no se arma cada vez `[PROPUESTO — requiere decisión de Producto]`

Dos formas de construirlo, con impacto real en cuánto hay que programar:

| | Opción A — Plantillas administrables | Opción B — Armador libre |
|---|---|---|
| Quién arma la combinación | Un administrador, una sola vez | Cualquier vendedor, en cada cotización |
| Esfuerzo de UI | Una pantalla de administración | Selectores en cascada por cada cobertura, en cada cotización |
| Consistencia | Todos cotizan el mismo "Equinox Agente" | Puede haber 20 versiones distintas del "mismo" paquete |
| Ruta de crecimiento | Base natural para comparar contra otras aseguradoras a futuro | No aporta nada adicional para eso |

**Recomendación objetiva: Opción A.** Es el mismo patrón que el proyecto ya sigue con `cat_paquetes` (catálogo administrado, no capturado a mano en cada venta), es menos superficie de error contra un servicio sin ambiente de pruebas, y no cierra la puerta a construir el armador libre después sobre la misma tabla de valores permitidos.

### 5. Extender el modelo de datos existente, no crear un sistema paralelo `[PROPUESTO]`

Siguiendo la convención `cat_*` / `cot_*` ya establecida en [ADR-003](../02_Arquitectura/ADR-003-modelo-de-datos.md):

```sql
-- Definición de la plantilla (catálogo administrado, "cat_")
CREATE TABLE cat_plantillas (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre        TEXT NOT NULL,           -- "Equinox Agente de Seguros y de Fianzas"
  cve_paquete   TEXT NOT NULL,           -- FK a cat_paquetes: el paquete base de GNP
  activo        INTEGER DEFAULT 1,
  creado_en     TEXT NOT NULL
);

-- Coberturas de la plantilla, con su valor elegido
CREATE TABLE cat_plantilla_coberturas (
  plantilla_id   INTEGER NOT NULL,
  cve_cobertura  TEXT NOT NULL,          -- FK a cat_coberturas
  suma_asegurada TEXT,                   -- debe existir en cat_coberturas para esa clave
  deducible      TEXT,                   -- ídem
  PRIMARY KEY (plantilla_id, cve_cobertura)
);
```

`cot_opcionales` **no se toca en su diseño** hasta confirmar su esquema real — no estaba a la vista en lo que revisé, sólo su nombre y que tiene 0 filas. Es razonable que sea la tabla donde, al cotizar con una plantilla, se registre qué coberturas y valores se mandaron realmente en esa cotización puntual (el "qué se pidió", instancia — mismo patrón que `cot_resultados` guarda el "qué contestó GNP"). **Antes de escribir código:** confirmar sus columnas actuales y decidir si se reutiliza tal cual o se ajusta.

### 6. Validación local antes de cada llamada, contra las tablas que ya existen `[PROPUESTO]`

No se manda nada a GNP sin haberlo validado contra `cat_coberturas` (valor permitido) y `cat_coberturas_excluyentes` (no hay dos coberturas excluyentes juntas). GNP no da un mensaje de error claro para esto — mejor no dejar que la llamada salga.

### 7. Convivencia con el comparativo multi-paquete: sin confirmar `[PENDIENTE]`

ADR-005 punto 8 confirma que una sola llamada puede traer varios **paquetes tal cual de GNP** (Amplia, Premium, Amplia Total, Auto Elite) comparados lado a lado. No hay evidencia de que un paquete con `<COBERTURAS>` modificado pueda ir **dentro de la misma llamada** junto con los paquetes estándar. Si no se puede, el comparativo "mi paquete Equinox vs. los paquetes de GNP" necesita dos llamadas en vez de una — afecta tiempos de respuesta y hay que diseñar la pantalla para eso desde ahora, no como sorpresa después.

## 🧩 Modelo de datos

Ver punto 5. Se apoya en, sin modificar su estructura: `cat_coberturas`, `cat_coberturas_excluyentes`, `cat_paquetes`, y en la tabla nueva `cat_cobertura_valores` (el menú real de valores permitidos, ver punto 1).

## ✅ Beneficios

- La mayor parte del trabajo de datos (extraer y cargar los valores permitidos por GNP) ya está hecho — este módulo es el último tramo, no el proyecto completo.
- Una plantilla administrada evita que la calidad de "Equinox Agente de Seguros y de Fianzas" dependa de que cada vendedor arme bien la combinación.
- Reutilizar `cat_coberturas`/`cat_coberturas_excluyentes` significa que si GNP actualiza sus valores permitidos, el ETL que ya existe (o su equivalente) alimenta este módulo sin tocarlo.
- Sienta la base para, más adelante, comparar el paquete propio de Equinox contra otras aseguradoras — mismo objetivo de crecimiento que ya tiene reservado el catálogo maestro (columnas `IDmarca_zurich`, `IDmarca_momento`, `IDmarca_ebc`).

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| ~~`<COBERTURAS>` modificado nunca se ha probado contra GNP~~ | **Resuelto 2026-09-10** — probado y confirmado, ver punto 2 |
| ~~No se sabe si se puede salir del paquete base~~ | **Resuelto 2026-09-10** — probado y confirmado que no se puede, ver punto 3 |
| ~~`cat_coberturas` no trae los valores permitidos~~ | **Resuelto 2026-09-10** — el menú real vive en `cat_cobertura_valores` (CSV del kit), ver punto 1 |
| **Sin ambiente de pruebas** | Cada prueba de combinación es una llamada real a GNP, igual que el resto del proyecto |
| **`cot_opcionales` con esquema desconocido para mí** | Diseñar sobre una tabla que no se ha inspeccionado puede chocar con lo que ya está ahí. Sigue bloqueando la conexión al flujo real de cotización |
| **Multi-paquete + coberturas modificadas en una sola llamada: sin confirmar** | Puede obligar a dos llamadas donde se esperaba una, con impacto en tiempo de respuesta. No bloquea nada hoy — sólo importa al diseñar la pantalla de comparativo final, después de resolver lo de `cot_opcionales` |

## 🛡️ Mitigaciones

- No construir ninguna pantalla antes de correr la prueba del punto 2. Es barata (una llamada) y resuelve la incertidumbre más cara del módulo.
- Empezar por Opción A (plantillas administradas): reduce la superficie de combinaciones posibles a probar, comparado con dejar que cualquier vendedor arme lo que quiera.
- Validar siempre localmente contra `cat_coberturas`/`cat_coberturas_excluyentes`/`cat_cobertura_valores` antes de llamar a GNP — las tres ya existen y `PlantillaServicio::guardar()` ya las usa (paquete base, exclusión, y ahora también suma asegurada/deducible contra el menú real).
- Confirmar el esquema real de `cot_opcionales` antes de decidir si se extiende o se reemplaza.

## 🧩 Consideraciones futuras

- Si la Opción A funciona bien, evaluar el armador libre (Opción B) como capa opcional sobre la misma tabla de valores permitidos.
- Cuando se conecte el catálogo maestro al flujo de cotización (pendiente de [ADR-004](./ADR-004-catalogo-maestro-propio.md)), las plantillas de este módulo deberían poder aplicarse sin importar qué aseguradora esté detrás — mismo espíritu de "catálogo propio, aseguradoras apuntan hacia él".
- Extender `cat_plantillas` a otras aseguradoras cuando el catálogo maestro homologue Zurich, Momento o EBC.

## 👥 Aprobación

- Producto / Negocio: ⏳
- TI / Arquitectura: ⏳

## Pendiente `[PENDIENTE]`

- **Bloqueante para conectar el flujo real de cotización:** revisar el esquema real de `cot_opcionales` antes de decidir si se reutiliza o se ajusta. Sin esto, las plantillas quedan construidas pero sin poder usarse para cotizar de verdad.
- ~~`cat_coberturas` no trae los valores permitidos~~ **Hecho 2026-09-10** — cargados en `cat_cobertura_valores` desde `docs/Auxiliares/cat_cobertura_valores_seed.csv` (225 filas, cubre las 29 claves sin huecos) vía `app/scripts/importar_valores_coberturas.php`. La pantalla de plantillas ya usa selectores reales contra este menú, con validación en `PlantillaServicio::guardar()`. `ADR-003` tiene una línea desactualizada al respecto (dice que `cat_coberturas` trae "sus valores permitidos") — pendiente corregirla ahí con nota fechada, sin reescribir la tabla.
- **Punto 7 (multi-paquete + `<COBERTURAS>` modificado en una sola llamada) se deja deliberadamente para después de resolver `cot_opcionales`** — decisión de secuencia de Beto, 2026-09-10: no bloquea nada hoy, sólo importa al diseñar la pantalla de comparativo final.
- ~~Confirmar si la diferencia de ~9% en `TOTAL_PAGAR` de control entre el 18 de agosto y el 10 de septiembre es sólo actualización de tarifa de GNP~~ **Cerrado 2026-09-10** — no queda `[CONFIRMADO]` (no existe el XML crudo del 18 de agosto para diffear, y el experimento no es repetible contra una tarifa viva), pero sí investigado a fondo: parámetros documentados coinciden salvo vigencia, explicación más probable es tarifa actualizada de GNP. Ver nota del punto 2.
- ~~Agregar `cotizador-eot` a la taxonomía de `ORIGEN` de ADR-005~~ **Hecho 2026-09-10**, ver [ADR-005 punto 6](./ADR-005-reglas-verificadas-gnp.md).
- Decisión de negocio: Opción A (plantillas) vs Opción B (armador libre) — sección 4. *(En la práctica ya se avanzó con Opción A al construir la pantalla de administración; falta la confirmación formal de Producto.)*
- Una vez cargados los valores permitidos, definir con Producto el contenido exacto de la primera plantilla ("Equinox Agente de Seguros y de Fianzas": qué coberturas, qué valores).

## Referencias

- [ADR-001 — Qué es el Cotizador GNP](../01_Generales/ADR-001-que-es-el-cotizador-gnp.md)
- [ADR-003 — Modelo de datos](../02_Arquitectura/ADR-003-modelo-de-datos.md)
- [ADR-004 — Catálogo maestro propio](./ADR-004-catalogo-maestro-propio.md)
- [ADR-005 — Reglas verificadas contra GNP](./ADR-005-reglas-verificadas-gnp.md)
- [ADR-006 — Evidencia y bitácora](./ADR-006-evidencia-y-bitacora.md)
- [`docs/02.5-contratante-minimo.md`](../02.5-contratante-minimo.md) — formato de referencia para documentar la prueba del punto 2
- Kit de conexión GNP **IC260812018**, hoja `COBERTURAS JUEGA Y COMPARA`
