# ADR-007 — Módulo Juega y Compara: paquetes propios sobre coberturas configurables

## 📌 Estado

**Propuesto** (Claude, 2026-09-10). Producto/Negocio ⏳ · TI/Arquitectura ⏳.
Puntos 1, 2 y 3 confirmados contra producción (CC, 2026-09-10 — ver `docs/02.6-coberturas-modificadas.md`). El flujo de cotizar con una plantilla propia ya está conectado y probado de punta a punta, incluido el deducible (CC, 2026-09-10 — ver `docs/02.7-plantillas-conectadas.md`, `docs/02.8-deducible-en-coberturas.md` y `docs/02.9-deducible-conectado.md`).

**Módulo funcionalmente cerrado.** El único pendiente deliberado es el punto 7 (multi-paquete + `<COBERTURAS>` modificado en una sola llamada), sin fecha porque no lo necesita nadie todavía — no bloquea nada de lo que hoy se puede hacer. Lo demás que faltaba (conectar `cot_opcionales`, cargar los valores permitidos, transmitir el deducible) ya está hecho y probado contra producción. Queda pendiente, aparte, la aprobación formal de Producto/TI (sección 4, ya en producción de facto) y que Producto cierre el contenido real de "Equinox Agente de Seguros y de Fianzas" — todo lo probado usó la plantilla ficticia de desarrollo.

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

### 2.1 `<DEDUCIBLE>` en `<COBERTURA>`: `[CONFIRMADO]` — probado y conectado

_(CC, 2026-09-10)_ — Quedó como hallazgo al conectar las plantillas (`docs/02.7-plantillas-conectadas.md`): `cot_opcionales.deducible` se guardaba pero nunca se transmitía, porque `GnpClient.php` nunca tuvo la etiqueta. Se probó primero, sin conectar nada (`docs/02.8-deducible-en-coberturas.md`): GNP acepta `<DEDUCIBLE>` en `<COBERTURA>` y lo aplica de verdad — un valor válido distinto al default cambió el deducible devuelto y movió `TOTAL_PAGAR` hacia abajo (subir el deducible baja la prima), y un valor fuera de la lista permitida se rechazó limpio (`CLAVE 13`, `ORIGEN cotizador-eot`).

Con el resultado confirmado, se conectó (`docs/02.9-deducible-conectado.md`):

- `GnpClient.php` tiene ahora, de forma permanente, la misma línea que se probó y revirtió en `02.8` — mismo patrón que ya existía para `SUMA_ASEGURADA`.
- `PlantillaServicio`/`CotizacionServicio` **ya llevaban el deducible de punta a punta** desde `02.7` (validación, resolución, `INSERT` en `cot_opcionales`) — no hizo falta escribir código nuevo ahí para la conexión en sí.
- **Hueco real encontrado al probar, y corregido:** para coberturas sin dimensión de deducible (ej. Gastos Médicos Ocupantes), la validación no corría por falta de lista contra la cual comparar, y un placeholder guardado (`"N/A"`, heredado de `cat_coberturas.ded_valor`) se transmitía tal cual — GNP lo rechazaba con un error de parseo interno confuso (`For input string: "N/A"`), no un rechazo de negocio claro. `PlantillaServicio::validarCoberturas()` ahora vacía cualquier dimensión sin lista de valores permitidos, sin importar qué texto hubiera quedado guardado.
- Probado de punta a punta contra producción con `[PRUEBA DEV] Plantilla de ejemplo` + Robo Parcial a un deducible **distinto al default del catálogo** (10%, no el 25% que ya traía `cat_cobertura_valores` como default — necesario para que la prueba fuera realmente concluyente): el deducible pedido llegó a GNP, se reflejó en la respuesta tal cual, y quedó registrado en `cot_opcionales` con su `plantilla_id`. El flujo manual (sin plantilla) se repitió sin cambios.

### 3. Salir del paquete base: `[CONFIRMADO]` — no se puede

Probado con una cobertura real de otro grupo (Asistencia Vial Moto, exclusiva de motocicletas) sobre un paquete Amplia/AUTO. GNP la rechaza: `CLAVE 12`, `ORIGEN cotizador-eot`, "La cobertura no existe. No aplica para este producto." No se probó el caso exacto de "Básica en Premium, N/A en Amplia" porque no existe hoy en los datos cargados de `cat_coberturas` — las 27 claves de AUTO aparecen en las cuatro matrices por igual, sólo cambia BASICA/OPCIONAL. La prueba hecha (cruzar tipo de vehículo) es una prueba más exigente del mismo principio, no una más débil.

**Decisión de alcance, ahora confirmada como la única opción viable, no sólo la más prudente:** las coberturas ofrecidas al armar un paquete propio se limitan a las que el paquete base ya trae (Básica u Opcional).

### 4. El paquete propio se guarda como plantilla reutilizable, no se arma cada vez `[CONFIRMADO — decisión formalizada, en producción de facto]`

_(CC, 2026-09-10)_ — **Opción A (plantillas administrables) queda formalizada.** No sólo por recomendación: ya está construida, conectada al flujo real de cotización y probada de punta a punta contra producción (`docs/02.7-plantillas-conectadas.md`). Falta la aprobación formal de Producto/TI en la sección de Aprobación de este ADR, pero el código y el dato ya operan bajo ese modelo — un administrador arma la plantilla una sola vez en la pantalla de "Paquetes propios", y cualquier vendedor la aplica al cotizar sin volver a capturar coberturas sueltas.

Dos formas de construirlo se habían considerado, con impacto real en cuánto había que programar:

| | Opción A — Plantillas administrables (elegida) | Opción B — Armador libre |
|---|---|---|
| Quién arma la combinación | Un administrador, una sola vez | Cualquier vendedor, en cada cotización |
| Esfuerzo de UI | Una pantalla de administración | Selectores en cascada por cada cobertura, en cada cotización |
| Consistencia | Todos cotizan el mismo "Equinox Agente" | Puede haber 20 versiones distintas del "mismo" paquete |
| Ruta de crecimiento | Base natural para comparar contra otras aseguradoras a futuro | No aporta nada adicional para eso |

Las razones que ya se anotaban como "recomendación objetiva" siguen siendo las mismas: mismo patrón que `cat_paquetes` (catálogo administrado, no capturado a mano en cada venta), menos superficie de error contra un servicio sin ambiente de pruebas, y no cierra la puerta a construir el armador libre (Opción B) después, sobre la misma tabla de valores permitidos (`cat_cobertura_valores`).

### 5. Extender el modelo de datos existente, no crear un sistema paralelo `[CONFIRMADO — construido y en uso]`

_(CC, 2026-09-10)_ — Construido tal cual, sin sistema paralelo. Siguiendo la convención `cat_*` / `cot_*` ya establecida en [ADR-003](../02_Arquitectura/ADR-003-modelo-de-datos.md):

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

`cot_opcionales` se confirmó que ya existía con `(id, cotizacion_id, cve_cobertura, suma_asegurada)` y **se extendió, no se reemplazó** — mismo patrón de alta posterior que ya usaba el proyecto para `sys_llamadas`/`sys_usuarios` (`Esquema.php::migrar()`): `deducible TEXT NOT NULL DEFAULT ''` y `plantilla_id INTEGER NULL REFERENCES cat_plantillas(id)`, `NULL` para el flujo manual que ya la usaba. Es la tabla donde, al cotizar con una plantilla, se registra qué coberturas y valores se mandaron realmente en esa cotización puntual — tal como se anticipaba aquí.

### 6. Validación local antes de cada llamada, contra las tablas que ya existen `[CONFIRMADO — implementado]`

_(CC, 2026-09-10)_ — Implementado en `PlantillaServicio::validarCoberturas()`, compartido por `guardar()` (al capturar) y `paraCotizar()` (al cotizar, revalidando otra vez — no se confía en lo validado ayer). No se manda nada a GNP sin haberlo validado contra `cat_coberturas` (pertenencia al paquete), `cat_cobertura_valores` (valor permitido, suma y deducible) y `cat_coberturas_excluyentes` (no hay dos coberturas excluyentes juntas). GNP no da un mensaje de error claro para todo esto — mejor no dejar que la llamada salga.

### 7. Convivencia con el comparativo multi-paquete: sin confirmar `[PENDIENTE]`

ADR-005 punto 8 confirma que una sola llamada puede traer varios **paquetes tal cual de GNP** (Amplia, Premium, Amplia Total, Auto Elite) comparados lado a lado. No hay evidencia de que un paquete con `<COBERTURAS>` modificado pueda ir **dentro de la misma llamada** junto con los paquetes estándar. Si no se puede, el comparativo "mi paquete Equinox vs. los paquetes de GNP" necesita dos llamadas en vez de una — afecta tiempos de respuesta y hay que diseñar la pantalla para eso desde ahora, no como sorpresa después.

**Requisito de negocio** _(Beto, 2026-09-10)_ — capturado a partir del caso real de "Equinox RC" sin Accidentes al Conductor (`docs/02.10-rc-accidentes-conductor.md`): cuando se construya este comparativo, cualquier cobertura que sea N/A para un paquete o plantilla debe imprimirse literalmente como **"N/A"** en su celda correspondiente — nunca en blanco ni omitida. Aplica tanto a comparar plantillas Equinox entre sí como a comparar una plantilla Equinox contra los paquetes estándar de GNP. _(CC, 2026-09-10 — nota capturada; no se tocó `ComparativoServicio` ni `PdfBasico` todavía.)_

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
| ~~`cot_opcionales` con esquema desconocido para mí~~ | **Resuelto 2026-09-10** — extendida (`deducible`, `plantilla_id`) sin tocar el flujo manual existente, ver `docs/02.7-plantillas-conectadas.md` |
| **Multi-paquete + coberturas modificadas en una sola llamada: sin confirmar** | Puede obligar a dos llamadas donde se esperaba una, con impacto en tiempo de respuesta. No bloquea nada hoy — es la **única** pieza que le falta al módulo, aparte de la aprobación formal y del contenido real de la primera plantilla |
| ~~El `deducible` de una plantilla no se transmite a GNP~~ | **Resuelto 2026-09-10** — probado (`docs/02.8`) y conectado (`docs/02.9-deducible-conectado.md`): `GnpClient.php` ya transmite `<DEDUCIBLE>`, revalidado contra `cat_cobertura_valores` en `paraCotizar()`, registrado en `cot_opcionales` |

## 🛡️ Mitigaciones

- No construir ninguna pantalla antes de correr la prueba del punto 2. Es barata (una llamada) y resuelve la incertidumbre más cara del módulo.
- Empezar por Opción A (plantillas administradas): reduce la superficie de combinaciones posibles a probar, comparado con dejar que cualquier vendedor arme lo que quiera.
- Validar siempre localmente contra `cat_coberturas`/`cat_coberturas_excluyentes`/`cat_cobertura_valores` antes de llamar a GNP — las tres ya existen y tanto `PlantillaServicio::guardar()` como `paraCotizar()` las usan (paquete base, exclusión, y suma asegurada/deducible contra el menú real) — `paraCotizar()` revalida otra vez al momento de cotizar, no confía en lo validado al guardar.
- ~~Confirmar el esquema real de `cot_opcionales` antes de decidir si se extiende o se reemplaza~~ Hecho — se extendió, no se reemplazó.

## 🧩 Consideraciones futuras

- Si la Opción A funciona bien, evaluar el armador libre (Opción B) como capa opcional sobre la misma tabla de valores permitidos.
- Cuando se conecte el catálogo maestro al flujo de cotización (pendiente de [ADR-004](./ADR-004-catalogo-maestro-propio.md)), las plantillas de este módulo deberían poder aplicarse sin importar qué aseguradora esté detrás — mismo espíritu de "catálogo propio, aseguradoras apuntan hacia él".
- Extender `cat_plantillas` a otras aseguradoras cuando el catálogo maestro homologue Zurich, Momento o EBC.

## 👥 Aprobación

- Producto / Negocio: ⏳
- TI / Arquitectura: ⏳

## Pendiente `[PENDIENTE]`

- ~~Bloqueante para conectar el flujo real de cotización: revisar el esquema real de `cot_opcionales`~~ **Hecho 2026-09-10** — extendida con `deducible` y `plantilla_id`, conectada a `CotizacionServicio::cotizar()`, probada de punta a punta contra producción sin cambiar el flujo manual existente. Ver `docs/02.7-plantillas-conectadas.md`.
- ~~`cat_coberturas` no trae los valores permitidos~~ **Hecho 2026-09-10** — cargados en `cat_cobertura_valores` desde `docs/Auxiliares/cat_cobertura_valores_seed.csv` (225 filas, cubre las 29 claves sin huecos) vía `app/scripts/importar_valores_coberturas.php`. La pantalla de plantillas ya usa selectores reales contra este menú, con validación en `PlantillaServicio::guardar()` y de nuevo en `paraCotizar()`. `ADR-003` tiene una línea desactualizada al respecto (dice que `cat_coberturas` trae "sus valores permitidos") — pendiente corregirla ahí con nota fechada, sin reescribir la tabla.
- **Único punto que sigue abierto de todo el módulo:** ADR-007 punto 7 (multi-paquete + `<COBERTURAS>` modificado en una sola llamada). Ya no depende de `cot_opcionales` (resuelto arriba) — es tarea aparte, sin fecha, sólo relevante cuando se diseñe el comparativo "mi paquete Equinox vs. los de GNP en una sola llamada".
- ~~El `deducible` de una plantilla no se transmite a GNP (hallado en `02.7`)~~ **Hecho 2026-09-10** — probado (`docs/02.8-deducible-en-coberturas.md`) y conectado (`docs/02.9-deducible-conectado.md`): `GnpClient.php` transmite `<DEDUCIBLE>` de forma permanente, `PlantillaServicio`/`CotizacionServicio` ya lo llevaban de punta a punta, y se corrigió de paso un hueco real (un placeholder `"N/A"` que se habría transmitido tal cual para coberturas sin dimensión de deducible).
- ~~Confirmar si la diferencia de ~9% en `TOTAL_PAGAR` de control entre el 18 de agosto y el 10 de septiembre es sólo actualización de tarifa de GNP~~ **Cerrado 2026-09-10** — no queda `[CONFIRMADO]` (no existe el XML crudo del 18 de agosto para diffear, y el experimento no es repetible contra una tarifa viva), pero sí investigado a fondo: parámetros documentados coinciden salvo vigencia, explicación más probable es tarifa actualizada de GNP. Ver nota del punto 2.
- ~~Agregar `cotizador-eot` a la taxonomía de `ORIGEN` de ADR-005~~ **Hecho 2026-09-10**, ver [ADR-005 punto 6](./ADR-005-reglas-verificadas-gnp.md).
- ~~Decisión de negocio: Opción A (plantillas) vs Opción B (armador libre) — sección 4~~ **Formalizada 2026-09-10** — Opción A, ya en producción de facto. Falta sólo la firma formal de Producto/TI en Aprobación, no una decisión técnica pendiente.
- Una vez cargados los valores permitidos, definir con Producto el contenido exacto de la primera plantilla ("Equinox Agente de Seguros y de Fianzas": qué coberturas, qué valores) — sigue siendo lo único que falta para dejar de usar la plantilla de desarrollo.

## Referencias

- [ADR-001 — Qué es el Cotizador GNP](../01_Generales/ADR-001-que-es-el-cotizador-gnp.md)
- [ADR-003 — Modelo de datos](../02_Arquitectura/ADR-003-modelo-de-datos.md)
- [ADR-004 — Catálogo maestro propio](./ADR-004-catalogo-maestro-propio.md)
- [ADR-005 — Reglas verificadas contra GNP](./ADR-005-reglas-verificadas-gnp.md)
- [ADR-006 — Evidencia y bitácora](./ADR-006-evidencia-y-bitacora.md)
- [`docs/02.5-contratante-minimo.md`](../02.5-contratante-minimo.md) — formato de referencia para documentar la prueba del punto 2
- [`docs/02.6-coberturas-modificadas.md`](../02.6-coberturas-modificadas.md) — prueba de fuego de `<COBERTURAS>` modificado (puntos 1-3)
- [`docs/02.7-plantillas-conectadas.md`](../02.7-plantillas-conectadas.md) — conexión de las plantillas al flujo real de cotización
- [`docs/02.8-deducible-en-coberturas.md`](../02.8-deducible-en-coberturas.md) — GNP sí acepta `<DEDUCIBLE>` en `<COBERTURA>`, probado sin conectar
- [`docs/02.9-deducible-conectado.md`](../02.9-deducible-conectado.md) — deducible conectado de punta a punta, probado contra producción
- Kit de conexión GNP **IC260812018**, hoja `COBERTURAS JUEGA Y COMPARA`
