# ADR-007 — Módulo Juega y Compara: paquetes propios sobre coberturas configurables

## 📌 Estado

**Propuesto** (Claude, 2026-09-10). Producto/Negocio ⏳ · TI/Arquitectura ⏳.
Puntos 1, 2 y 3 confirmados contra producción (CC, 2026-09-10 — ver `docs/02.6-coberturas-modificadas.md`). El flujo de cotizar con una plantilla propia ya está conectado y probado de punta a punta, incluido el deducible (CC, 2026-09-10 — ver `docs/02.7-plantillas-conectadas.md`, `docs/02.8-deducible-en-coberturas.md` y `docs/02.9-deducible-conectado.md`).

**Los 7 puntos de este ADR están `[CONFIRMADO]`.** Punto 7 (multi-paquete + `<COBERTURAS>` modificado en una sola llamada) confirmado el 2026-09-10 contra producción — ver `docs/02.11-multipaquete-plantillas.md`. Con el negocio decidiendo separar "GNP Cotizador" (lo que ya existe, sin tocar) de un módulo nuevo "GNP Juega y Compara" (comparar plantillas Equinox entre sí), este ADR pasa de ser un módulo por construir a ser la base ya probada sobre la que se construye ese módulo nuevo.

_(CC, 2026-09-10)_ — **Los tres pendientes técnicos de más arriba ya se resolvieron** — ver `docs/02.12-bug-amparada.md`:

- **Bug `"Amparada"`: corregido y probado.** `PlantillaServicio::paraGnpClient()` (llamado sólo desde `paraCotizar()`, no desde `guardar()`) omite coberturas Básicas ya-por-default y manda las Opcionales sin la etiqueta de valor que no aplica. Las 4 plantillas reales cotizan sin error.
- **Soporte permanente en `GnpClient::cotizar()`: hecho.** Cada paquete puede traer su propio `opcionales`; probado contra producción con los mismos montos que el parche temporal de `02.11`.
- **"Siempre en Agencia" y la antigüedad del vehículo: `[CONFIRMADO]` — cerrado.** El corte real es `modelo ≥ año de vigencia − 4` (confirmado con dos líneas de vehículo distintas). **Decisión de Beto (2026-09-10): avisar y cotizar sin la cobertura, no bloquear.** Implementado de forma genérica — `cat_coberturas.antiguedad_max_anios` (nullable, poblada sólo para esta clave) más una tercera regla en `PlantillaServicio::paraGnpClient()` que omite la cobertura y regresa el motivo en `omitidas`, propagado hasta el aviso que ve el vendedor. Probado con Equinox Amplia Plus + Honda Fit 2015, `sys_llamadas.id = 94`: aceptada, "Siempre en Agencia" ausente y visible como tal, resto de coberturas intactas.

~~Lo que queda abierto: (a) la aprobación formal de Producto/TI (sección 4, ya en producción de facto); y (b) que Producto cierre el contenido real de "Equinox Agente de Seguros y de Fianzas" — la plantilla ficticia de desarrollo y las cuatro plantillas Equinox ya cargadas (Amplia Plus, Amplia, Limitada, RC/Básica) cubren las pruebas hechas hasta ahora.~~

**Punto (b) cerrado — confirmado con Beto, 2026-09-10**: "Equinox Agente de Seguros y de Fianzas" **nunca fue una quinta combinación real de negocio** — era el nombre de trabajo usado en el análisis inicial de este ADR, antes de que existiera el Excel con las cuatro plantillas reales (Amplia Plus, Amplia, Limitada, RC/Básica). No hay contenido de negocio pendiente de definir bajo ese nombre; las cuatro reales son el alcance completo. Las menciones de ese nombre más abajo en este documento (contexto original, antes de conocerse las cuatro reales) se dejan tal cual con esta nota, en vez de reescribirse — es historial de cómo se pensó el módulo al inicio, no una plantilla pendiente. Sólo queda abierta la aprobación formal de Producto/TI (sección 4, ya en producción de facto).

### Paso 2 — Módulo "GNP Juega y Compara" construido _(CC, 2026-09-10)_

Con los tres pendientes cerrados, se construyó el módulo nuevo — pantalla, rutas y servicio — sin tocar `cotizar.php`, sus rutas, el flujo manual/multi-paquete de "GNP Cotizador", ni la pantalla de administración de plantillas (`plantillas.php`):

- **Vista nueva** `app/vistas/juega_y_compara.php` — mismo patrón de selección de vehículo/solicitante que `cotizar.php` (duplicado a propósito, no extraído a un parcial compartido: tocar `cotizar.php` para compartir código estaba explícitamente fuera de alcance), con checkboxes de `cat_plantillas WHERE activo = 1` en vez de paquetes/opcionales sueltos.
- **Rutas nuevas, aditivas**, en `public/index.php`: `?r=juega-y-compara` (formulario) y su propio manejo de POST — ninguna reemplaza ni modifica una ruta existente. Enlace agregado al menú de navegación en `layout.php` (compartido por todas las pantallas, no exclusivo de `cotizar.php`) para que el módulo sea alcanzable.
- **Servicio nuevo** `JuegaYCompararServicio::cotizar()`, no un método más en `CotizacionServicio`. Justificación: `CotizacionServicio::cotizar()` ya carga la lógica propia de UN origen de coberturas por llamada (paquete manual o una sola plantilla) — mezclarle "N plantillas, cada una resuelta y validada por su cuenta" habría hecho ese método menos legible sin necesidad. El nuevo servicio reutiliza infraestructura existente en vez de duplicarla: `PlantillaServicio::paraCotizar()` por plantilla, el soporte permanente de `GnpClient::cotizar()` para `opcionales` por paquete, y dos métodos de `CotizacionServicio` que se volvieron `public` para compartirse (`crearBorrador()`, extraído sin cambiar su SQL; `guardarResultados()`, extendido con parámetros opcionales que no alteran su comportamiento por default).
- **Persistencia: mismas tablas, con una extensión necesaria, no cosmética.** Se reutilizan `cot_cotizaciones`/`cot_resultados`/`cot_resultado_coberturas` tal cual. Dos cambios de esquema, ambos obligados por hechos encontrados al construir, no por preferencia de diseño:
  1. **`cot_resultados.plantilla_id`** (nullable, `NULL` para "GNP Cotizador" de siempre) — se prefirió sobre `cot_opcionales.plantilla_id` (que ya existía, y la primera opción evaluada) porque éste último puede quedar sin ninguna fila para una plantilla cuyas coberturas se transmiten todas por default (sin nada que agregar a `cot_opcionales`) — dejaría esa cotización sin ninguna marca de origen. `plantilla_id` en `cot_resultados` no tiene ese hueco: se escribe siempre, por paquete cotizado, sin importar si hubo algo que transmitir aparte del paquete base.
  2. **Reconstrucción del `UNIQUE(cotizacion_id, cve_paquete)` de `cot_resultados`** (ahora sin ese `UNIQUE`) — hallazgo real al probar: **Equinox Amplia Plus y Equinox Amplia comparten el mismo `cve_paquete` de GNP** (`PRS0009355`, ya documentado desde la carga de las 4 plantillas reales). El `UNIQUE` original habría bloqueado guardar ambos resultados en la misma cotización — exactamente el caso de uso central de este módulo. Migración en `Esquema.php::migrar()` (reconstruye la tabla preservando datos e ids; probada contra la base real de producción, 41 filas antes y después, sin pérdida, `PRAGMA foreign_key_check` limpio salvo un huérfano preexistente y ajeno en `cat_plantilla_coberturas` que ya existía desde antes de este cambio).
  3. **Tabla nueva `cot_resultado_omitidas`** (hermana de `cot_resultado_coberturas`, misma forma) — necesaria porque una cobertura omitida por regla (antigüedad, o lo que sea a futuro) simplemente no aparece en la respuesta de GNP, así que no hay fila de la que "recordarla" en ningún lado existente. Sin esto, si NINGUNA de las plantillas comparadas trae esa cobertura en su respuesta, desaparecería de la tabla comparativa sin explicación — justo lo que el requisito de negocio de más abajo prohíbe.
- **Requisito de "nunca ocultar" (punto 7, requisito de Beto) implementado**, no sólo anotado: `resultado.php` y `ComparativoServicio` (PDF/CSV) arman la unión de filas de la tabla comparativa incluyendo tanto lo que GNP devolvió como lo que se omitió, y pintan la celda omitida como **"N/A"** (con el motivo completo en el `title` del HTML) — distinto de "no incluida", que sigue significando "nunca fue parte de esta plantilla". Aplica igual al flujo de plantilla única (`cotizar.php`, sin tocarlo) porque ambos comparten la misma vista de resultado.
- **Probado contra producción**, no sólo en código: `app/scripts/prueba_juega_y_comparar.php` cotiza Equinox Amplia Plus (id 75) + Equinox Amplia (id 76) —el caso exacto de la colisión de `cve_paquete`— con el Honda Fit 2015, y confirma: dos resultados guardados por separado, "Siempre en Agencia" omitida y registrada sólo en Amplia Plus, ausente (nunca ofrecida) en Amplia. Repetido también por la ruta HTTP real (`?r=juega-y-compara`, POST, con sesión y CSRF reales) hasta la pantalla de resultado y los dos formatos de comparativo (PDF y CSV) — los tres muestran la fila correctamente.
- **Regresión del escenario que el `UNIQUE` original protegía, probada explícitamente** _(Beto, 2026-09-10, pidió verificarlo antes del commit)_: el `UNIQUE(cotizacion_id, cve_paquete)` no era sólo defensivo del flujo de una plantilla — protegía también el comparativo **multi-paquete estándar** de "GNP Cotizador" (varios paquetes de GNP, sin ninguna plantilla, en una sola cotización), que llevaba semanas funcionando. Se corrió ese caso exacto por la ruta real `?r=cotizar` (Amplia + Premium + Auto Elite, sin plantilla, `cotizacion_id = 28`): **3 renglones en `cot_resultados`, todos con `plantilla_id NULL`, cero `cve_paquete` duplicados**, comparativo en pantalla correcto (Auto Elite $3,438.26 · Amplia $8,183.48 · Premium $12,094.81, sin ningún aviso de error). Confirma que quitar el `UNIQUE` no abrió una puerta a duplicados en este flujo.
  - **Matiz encontrado al pensar el caso límite, ahora cerrado** _(Beto, 2026-09-10, pidió cerrarlo antes del commit)_: el `UNIQUE` original tampoco "protegía" este escenario de forma elegante — como `guardarResultados()` no usaba `ON CONFLICT` en ese `INSERT`, una violación habría tronado con una `PDOException` sin capturar (error 500), no con un mensaje de negocio. Sin el `UNIQUE`, el hueco real era que un paquete repetido (mismo `cve_paquete`, sin plantilla que lo distinga, o la misma plantilla elegida dos veces) ya no tronaba solo — se habría guardado en silencio como dos renglones idénticos. **Cerrado con una validación explícita en `guardarResultados()`**: antes del `DELETE`+`INSERT`, revisa que la llave real (`cve_paquete` + `plantilla_id` resuelto — no `cve_paquete` solo, porque ESE ya se permite repetir entre plantillas distintas a propósito) no se repita entre los paquetes que devolvió GNP; si se repite, regresa un mensaje de negocio legible (`'ok' => false`) y no guarda nada. Antes vivía del `UNIQUE` por omisión; ahora es una regla explícita, propia del código, que no depende de que la base la detenga. Probado con un caso sintético (sin gastar una llamada real): mismo `cve_paquete` sin plantilla repetido → rechazado, cero filas guardadas; mismo `cve_paquete` con dos plantillas distintas (Amplia Plus/Amplia) → aceptado, dos filas guardadas — confirma que no se rompió el caso que este módulo existe para resolver.
- Regresión confirmada aparte: una cotización manual de "GNP Cotizador" con un solo paquete, después de estos cambios, queda con `plantilla_id NULL` y cero filas en `cot_resultado_omitidas`, igual que siempre.

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

**Matiz encontrado después, al cotizar de verdad por primera vez con las 4 plantillas reales** (ver `docs/02.12-bug-amparada.md`): para las coberturas de estatus fijo (Club GNP, Robo Parcial Plus, etc.), el único "valor permitido" que guarda `cat_cobertura_valores` es `"Amparada"` — la palabra que GNP usa para *describir* la cobertura en su respuesta, no un valor que se le pueda *mandar* como entrada. Ese menú sirve para que la pantalla de plantillas muestre y capture la elección correctamente; no significa que ese texto se transmita tal cual al cotizar. La traducción correcta (omitir si ya viene por default, mandar sin esa etiqueta si hay que activarla) vive en `PlantillaServicio::paraGnpClient()`, sólo del lado de cotizar — el guardado de la plantilla no cambió.

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

### 7. Convivencia con el comparativo multi-paquete: `[CONFIRMADO]`

_(CC, 2026-09-10)_ — **Probado contra producción:** GNP acepta varios `<PAQUETE>`, cada uno con su propio `<COBERTURAS>` modificado, en una sola llamada — sin contaminación cruzada entre ellos. Ver [`docs/02.11-multipaquete-plantillas.md`](../02.11-multipaquete-plantillas.md). ADR-005 punto 8 ya confirmaba esto para paquetes **estándar** de GNP; ahora queda confirmado también cuando cada paquete trae coberturas propias distintas.

Contexto actualizado de negocio: en vez de mezclar plantillas y paquetes estándar en la misma pantalla, se separan en dos módulos dentro de la misma aplicación — "GNP Cotizador" (lo que ya existe, sin tocar) y "GNP Juega y Compara" (módulo nuevo, comparar varias plantillas Equinox entre sí). Este punto 7 responde a lo que necesita el módulo nuevo: sí puede pedir todas las plantillas seleccionadas en una sola llamada, no hace falta una llamada por plantilla.

**Soporte permanente: hecho** _(CC, 2026-09-10, ver `docs/02.12-bug-amparada.md`)_. `GnpClient::cotizar()` ya acepta, por elemento de `$paquetes`, un `opcionales` propio — mismo patrón "parchar, probar, hacer permanente" que `<DEDUCIBLE>` en `02.8`/`02.9`. Probado repitiendo la llamada de `02.11` (Amplia Plus + Limitada): **mismos montos** ($10,958.55 / $8,170.36) que con el parche temporal.

**Dos hallazgos aparte, encontrados al aislar la prueba de este punto — estado actual:**

- ~~Las cuatro plantillas Equinox cargadas tienen `suma_asegurada = "Amparada"` guardado para coberturas de estatus fijo~~ **Corregido 2026-09-10** — ver ADR-007 punto 1 y `docs/02.12-bug-amparada.md`. Las 4 plantillas reales ya cotizan sin error.
- ~~"Siempre en Agencia" no aplica para vehículos viejos~~ **Cerrado 2026-09-10** — regla genérica (`cat_coberturas.antiguedad_max_anios`), decisión de Beto aplicada (avisar y cotizar sin la cobertura). Probado con Amplia Plus + Honda Fit 2015 (`sys_llamadas.id = 94`). Ver `docs/02.12-bug-amparada.md`.

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
| ~~Multi-paquete + coberturas modificadas en una sola llamada: sin confirmar~~ | **Resuelto 2026-09-10** — probado y confirmado, ver punto 7 y `docs/02.11-multipaquete-plantillas.md` |
| ~~El `deducible` de una plantilla no se transmite a GNP~~ | **Resuelto 2026-09-10** — probado (`docs/02.8`) y conectado (`docs/02.9-deducible-conectado.md`): `GnpClient.php` ya transmite `<DEDUCIBLE>`, revalidado contra `cat_cobertura_valores` en `paraCotizar()`, registrado en `cot_opcionales` |
| ~~`GnpClient::cotizar()` no soporta coberturas distintas por paquete en una misma llamada~~ | **Resuelto 2026-09-10** — soporte permanente, probado con los mismos montos que el parche temporal. Ver `docs/02.12-bug-amparada.md` |
| ~~`suma_asegurada = "Amparada"` en coberturas de estatus fijo, en las cuatro plantillas Equinox ya cargadas~~ | **Resuelto 2026-09-10** — corregido en `PlantillaServicio::paraGnpClient()`. Las 4 plantillas reales ya cotizan sin error. Ver `docs/02.12-bug-amparada.md` |
| ~~"Siempre en Agencia" no aplica para vehículos viejos~~ | **Resuelto 2026-09-10** — límite exacto `modelo ≥ año de vigencia − 4`, regla genérica (`cat_coberturas.antiguedad_max_anios`) aplicada: se omite y se avisa, no se bloquea. Probado con Amplia Plus + Honda Fit 2015 (`sys_llamadas.id = 94`). Ver `docs/02.12-bug-amparada.md` |

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
- ~~Único punto que sigue abierto de todo el módulo: ADR-007 punto 7 (multi-paquete + `<COBERTURAS>` modificado en una sola llamada)~~ **Hecho 2026-09-10** — probado y `[CONFIRMADO]`, ver `docs/02.11-multipaquete-plantillas.md`.
- ~~Hacer permanente en `GnpClient::cotizar()` el soporte a coberturas distintas por `<PAQUETE>` en una misma llamada~~ **Hecho 2026-09-10** — probado con los mismos montos que el parche temporal de `02.11`. Ver `docs/02.12-bug-amparada.md`.
- ~~Corregir `suma_asegurada = "Amparada"` en las coberturas de estatus fijo de las cuatro plantillas Equinox~~ **Hecho 2026-09-10** — corregido del lado de cotizar (`PlantillaServicio::paraGnpClient()`), no del lado de guardar. Las 4 plantillas reales ya cotizan sin error. Ver `docs/02.12-bug-amparada.md`.
- ~~Sigue abierto, decisión de negocio, no técnica: qué hacer cuando una plantilla incluye una cobertura inválida para el vehículo cotizado — caso concreto: "Siempre en Agencia"~~ **Hecho 2026-09-10** — decisión de Beto (avisar y cotizar sin la cobertura) implementada de forma genérica (`cat_coberturas.antiguedad_max_anios` + tercera regla en `PlantillaServicio::paraGnpClient()`), probada con Amplia Plus + Honda Fit 2015 (`sys_llamadas.id = 94`). Ver `docs/02.12-bug-amparada.md`.
- ~~El `deducible` de una plantilla no se transmite a GNP (hallado en `02.7`)~~ **Hecho 2026-09-10** — probado (`docs/02.8-deducible-en-coberturas.md`) y conectado (`docs/02.9-deducible-conectado.md`): `GnpClient.php` transmite `<DEDUCIBLE>` de forma permanente, `PlantillaServicio`/`CotizacionServicio` ya lo llevaban de punta a punta, y se corrigió de paso un hueco real (un placeholder `"N/A"` que se habría transmitido tal cual para coberturas sin dimensión de deducible).
- ~~Confirmar si la diferencia de ~9% en `TOTAL_PAGAR` de control entre el 18 de agosto y el 10 de septiembre es sólo actualización de tarifa de GNP~~ **Cerrado 2026-09-10** — no queda `[CONFIRMADO]` (no existe el XML crudo del 18 de agosto para diffear, y el experimento no es repetible contra una tarifa viva), pero sí investigado a fondo: parámetros documentados coinciden salvo vigencia, explicación más probable es tarifa actualizada de GNP. Ver nota del punto 2.
- ~~Agregar `cotizador-eot` a la taxonomía de `ORIGEN` de ADR-005~~ **Hecho 2026-09-10**, ver [ADR-005 punto 6](./ADR-005-reglas-verificadas-gnp.md).
- ~~Decisión de negocio: Opción A (plantillas) vs Opción B (armador libre) — sección 4~~ **Formalizada 2026-09-10** — Opción A, ya en producción de facto. Falta sólo la firma formal de Producto/TI en Aprobación, no una decisión técnica pendiente.
- ~~Una vez cargados los valores permitidos, definir con Producto el contenido exacto de la primera plantilla ("Equinox Agente de Seguros y de Fianzas": qué coberturas, qué valores) — sigue siendo lo único que falta para dejar de usar la plantilla de desarrollo.~~ **Cerrado 2026-09-10, confirmado con Beto**: no era una plantilla pendiente de definir — era el nombre de trabajo del análisis inicial, anterior a las cuatro plantillas reales. No hay contenido de negocio que definir bajo ese nombre. La plantilla ficticia de desarrollo (`[PRUEBA DEV] Plantilla de ejemplo`) se mantiene sólo como fixture de pruebas, nunca de negocio — ver decisión técnica en `docs/02.12-bug-amparada.md`.

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
- [`docs/02.10-rc-accidentes-conductor.md`](../02.10-rc-accidentes-conductor.md) — Accidentes al Conductor sobre Responsabilidad Civil: GNP lo rechaza, catálogo correcto
- [`docs/02.11-multipaquete-plantillas.md`](../02.11-multipaquete-plantillas.md) — dos plantillas con coberturas propias en una sola llamada: confirmado, más dos hallazgos de datos
- [`docs/02.12-bug-amparada.md`](../02.12-bug-amparada.md) — bug "Amparada" corregido, soporte permanente de `GnpClient` hecho, límite de antigüedad de "Siempre en Agencia" investigado y cerrado (regla genérica, decisión de Beto aplicada)
- Kit de conexión GNP **IC260812018**, hoja `COBERTURAS JUEGA Y COMPARA`
