# Índice de documentación — Cotizador GNP

Biblioteca del proyecto. Cuando necesites buscar algo, empieza aquí: cada fila te dice en qué carpeta vive la respuesta.

**Propósito:** dejar por escrito las decisiones ya tomadas, para que al momento de programar haya el mínimo de ida y vuelta — y para que quien llegue nuevo (persona o asistente) entienda el proyecto sin tener que leer el código.

---

## Mapa de la biblioteca

| Carpeta | Contenido | Archivos |
|---|---|---|
| `01_Generales/` | Qué es el proyecto, cómo nació, para qué sirve | [ADR-001-que-es-el-cotizador-gnp.md](./01_Generales/ADR-001-que-es-el-cotizador-gnp.md) |
| `02_Arquitectura/` | Stack, estructura de carpetas y modelo de datos | [ADR-002-stack-y-estructura.md](./02_Arquitectura/ADR-002-stack-y-estructura.md) · [ADR-003-modelo-de-datos.md](./02_Arquitectura/ADR-003-modelo-de-datos.md) |
| `03_Decisiones/` | Decisiones de diseño y de negocio ya tomadas | [ADR-004-catalogo-maestro-propio.md](./03_Decisiones/ADR-004-catalogo-maestro-propio.md) · [ADR-005-reglas-verificadas-gnp.md](./03_Decisiones/ADR-005-reglas-verificadas-gnp.md) · [ADR-006-evidencia-y-bitacora.md](./03_Decisiones/ADR-006-evidencia-y-bitacora.md) · [ADR-007-modulo-juega-y-compara.md](./03_Decisiones/ADR-007-modulo-juega-y-compara.md) |

Fuera de las carpetas numeradas, en la raíz de `docs/`, viven documentos operativos que **no son ADR** y por eso no se numeran con la secuencia de ADR:

| Archivo | Qué es |
|---|---|
| [`homologacion-estado.md`](./homologacion-estado.md) | Corte de resultados de la homologación al 2026-08-27. Reporte de estado, no decisión |
| [`02.5-contratante-minimo.md`](./02.5-contratante-minimo.md) | Guía de una prueba pendiente contra producción |
| [`02.6-coberturas-modificadas.md`](./02.6-coberturas-modificadas.md) | Prueba de `<COBERTURAS>` modificado contra producción — ya corrida y documentada, sustenta ADR-007 |
| [`02.7-plantillas-conectadas.md`](./02.7-plantillas-conectadas.md) | Conexión de las plantillas propias al flujo real de cotización — ya corrida y documentada |
| [`02.8-deducible-en-coberturas.md`](./02.8-deducible-en-coberturas.md) | GNP sí acepta `<DEDUCIBLE>` en `<COBERTURA>` — probado, sin conectar (paso previo a `02.9`) |
| [`02.9-deducible-conectado.md`](./02.9-deducible-conectado.md) | Deducible conectado de punta a punta (plantilla → GNP → `cot_opcionales`) — ya corrida y documentada |
| [`02.10-rc-accidentes-conductor.md`](./02.10-rc-accidentes-conductor.md) | Accidentes al Conductor sobre Responsabilidad Civil: GNP la rechaza, `cat_coberturas` es correcta — cierra el pendiente que dejó abierto `02.6` |
| [`02.11-multipaquete-plantillas.md`](./02.11-multipaquete-plantillas.md) | Dos plantillas con coberturas propias en una sola llamada: confirmado (ADR-007 punto 7). Deja dos hallazgos de datos abiertos, con impacto en las plantillas ya cargadas |
| [`02.12-bug-amparada.md`](./02.12-bug-amparada.md) | Bug "Amparada" corregido, soporte permanente de `GnpClient` hecho, límite de antigüedad de "Siempre en Agencia" investigado y **cerrado** (regla genérica `antiguedad_max_anios`, decisión de Beto aplicada: avisar y cotizar sin la cobertura) |

---

## Cómo se escribe un ADR aquí

Un **ADR** (Architecture Decision Record) documenta una decisión: qué se decidió, por qué, y qué consecuencias trae. No es un manual ni una bitácora.

- Se usa la plantilla de [`00-plantilla-adr.md`](./00-plantilla-adr.md).
- La numeración es **una sola secuencia continua** (ADR-001, ADR-002…) sin importar en qué carpeta caiga. Así las referencias cruzadas nunca se rompen.
- Un ADR **no se borra ni se reescribe** cuando cambia de opinión el proyecto: se marca como reemplazado y se escribe uno nuevo que lo sustituye. El historial es parte del valor.
- Cuando se agrega o modifica contenido, se identifica quién lo escribió con una nota al párrafo: `_(Beto, 2026-09-10)_`.
- Las afirmaciones llevan etiqueta de firmeza: `[CONFIRMADO]` cuando está probado contra el servicio real, `[PENDIENTE]` cuando falta comprobarlo.

> La distinción entre `[CONFIRMADO]` y `[PENDIENTE]` es la más importante del proyecto. GNP no dio ambiente de pruebas: todo lo que no se ha visto responder de verdad es una suposición, por razonable que parezca.

---

## Decisiones clave ya tomadas

- **ADR-001 — Qué es el Cotizador GNP:** aplicación web independiente para cotizar autos directamente con GNP, con su propia base de catálogos. Nació dentro del análisis de NEXO y se separó como proyecto propio. Detalle en [01_Generales/ADR-001](./01_Generales/ADR-001-que-es-el-cotizador-gnp.md).
- **ADR-002 — Stack y estructura:** PHP 8.2 puro, SQLite, sin Composer ni npm, sin dependencias de terceros. Una sola carpeta pública. Detalle en [02_Arquitectura/ADR-002](./02_Arquitectura/ADR-002-stack-y-estructura.md).
- **ADR-003 — Modelo de datos:** dos bases SQLite con responsabilidades distintas — `cotizador_gnp.sqlite` (operación) y `cat_comercial.db` (catálogo maestro). Detalle en [02_Arquitectura/ADR-003](./02_Arquitectura/ADR-003-modelo-de-datos.md).
- **ADR-004 — Catálogo maestro propio:** el vehículo se identifica con un ID nuestro, no con el de ninguna aseguradora. Las aseguradoras apuntan hacia el catálogo maestro, no al revés. Detalle en [03_Decisiones/ADR-004](./03_Decisiones/ADR-004-catalogo-maestro-propio.md).
- **ADR-005 — Reglas verificadas contra GNP:** el precio es `TOTAL_PAGAR`, quien tarifica es el conductor, un `504` no es rechazo, la cotización vive 15 días. Todo comprobado contra el servicio real. Detalle en [03_Decisiones/ADR-005](./03_Decisiones/ADR-005-reglas-verificadas-gnp.md).
- **ADR-006 — Evidencia y bitácora:** cada llamada guarda su XML de ida y vuelta con la contraseña enmascarada. Es requisito de GNP para soporte y certificación. Detalle en [03_Decisiones/ADR-006](./03_Decisiones/ADR-006-evidencia-y-bitacora.md).

---

## Propuestas en curso

- **ADR-007 — Módulo Juega y Compara:** paquetes propios de Equinox armados sobre coberturas configurables de GNP. **Propuesto**, pendiente sólo de la firma formal de Producto/TI — sus **7 puntos** están `[CONFIRMADO]` contra producción, y el módulo "GNP Juega y Compara" (comparar varias plantillas a la vez, separado de "GNP Cotizador") **ya está construido y probado contra producción**: vista y rutas nuevas (`?r=juega-y-compara`), servicio nuevo `JuegaYCompararServicio`, y dos ajustes de esquema encontrados al construir (`cot_resultados.plantilla_id`, y su `UNIQUE` reconstruido porque dos plantillas reales comparten `cve_paquete`). Ya no queda ningún pendiente técnico. Detalle en [03_Decisiones/ADR-007](./03_Decisiones/ADR-007-modulo-juega-y-compara.md), sección "Paso 2"; las pruebas que lo sustentan en [`02.6`](./02.6-coberturas-modificadas.md) a [`02.12`](./02.12-bug-amparada.md).

---

## Estado del proyecto — 2026-09-10

| | |
|---|---|
| Cotizaciones hechas contra producción | 26 (47 tarificaciones) |
| Catálogo GNP descargado | 48,155 vehículos · 392 paquetes · 167 coberturas |
| Catálogo maestro comercial | 107 marcas · 7,777 submarcas |
| Homologadas con GNP | 3,461 (44.5%) |
| Procedencias verificadas | 1 de 7 (sólo Residentes, `01`) |
| Llamadas registradas en bitácora | 97 |

---

## Pendientes detectados al armar esta biblioteca

_(Beto, 2026-09-10)_ — Hallazgos de la revisión del repositorio. No son decisiones; son cosas que hay que atender.

| # | Pendiente | Riesgo |
|---|---|---|
| 1 | **`.gitignore` no cubre `app/core/*.db`.** `cat_comercial.db` (2.7 MB) y sus **seis respaldos** (~13 MB) están dentro del árbol y no ignorados. Sólo `datos/*.sqlite` lo está | 🔴 Alto — infla el repositorio de forma permanente; git no olvida un binario ya commiteado |
| 2 | **`datos/*.xlsx`, `*.csv` y `*.zip` tampoco están ignorados** — hay ~7 MB entre el catálogo en Excel, los pendientes de homologación y el ZIP de entrega | 🔴 Alto — mismo problema |
| 3 | **Trabajo sin subir.** El último push fue el 2026-08-26; hay objetos de git posteriores sin llegar a `main` | 🟡 Medio — la homologación completa vive sólo en este equipo |
| 4 | **`README.md` desactualizado.** Habla de 47,542 versiones (hoy 48,155) y no menciona `cat_comercial.db` ni la homologación | 🟡 Medio |
| 5 | **`cat_comercial_diseño.md` desactualizado.** Dice 110 marcas y 7,941 submarcas con `IDmarca_gnp` en NULL; la realidad es 107, 7,777 y 3,461 homologadas. Sus rutas apuntan a `data/`, que no existe (es `app/core/`) | 🟡 Medio — es el documento que alguien nuevo leería primero |
| 6 | **`.env.example` trae el usuario real de GNP** (`AESPIN870946`) y un correo interno, en un repositorio ya publicado | 🟢 Bajo — no es la contraseña, pero conviene dejarlo como marcador |
| 7 | **`etl_vehiculos.php` hace 4× las llamadas necesarias** — usa la lista global de armadoras en vez de la filtrada por tipo de vehículo. Además dejó ~710 filas en estado `ERROR` en `cat_vehiculos_avance` | 🟢 Bajo — sólo eficiencia; los datos ya bajados están bien |

---

Última actualización: 2026-09-10 — se crea la biblioteca con ADR-001 a ADR-006; se agrega ADR-007 (módulo Juega y Compara, propuesto) y las pruebas `02.6` a `02.12`. Con `02.12` se corrige el bug de `"Amparada"`, se hace permanente el soporte de `GnpClient` a coberturas por paquete, y se cierra de forma genérica el límite de antigüedad de "Siempre en Agencia" (se omite y se avisa, decisión de Beto). Con los tres pendientes cerrados de verdad, se construye el módulo nuevo "GNP Juega y Compara" (Paso 2 de ADR-007): vista y rutas propias, servicio `JuegaYCompararServicio`, y un ajuste de esquema en `cot_resultados` (columna `plantilla_id` + `UNIQUE` reconstruido) encontrado al comprobar que dos plantillas reales comparten `cve_paquete` de GNP.
