# ADR-003 — Modelo de datos: dos bases SQLite con responsabilidades distintas

## 📌 Estado

**Confirmado** (Beto, 2026-09-10). TI/Arquitectura ✅ · Producto/Negocio ✅.

Detalla el stack de [ADR-002](./ADR-002-stack-y-estructura.md) por el lado de los datos. La decisión de *por qué* existe un catálogo maestro propio está en [ADR-004](../03_Decisiones/ADR-004-catalogo-maestro-propio.md); aquí se documenta *cómo* está armado.

## 🧠 Contexto

El proyecto maneja dos clases de información que no tienen nada que ver entre sí:

- **La operación:** los catálogos de GNP, las cotizaciones que se hacen, los resultados que devuelve, los usuarios, la bitácora. Todo esto es específico de GNP y se regenera bajando el catálogo otra vez.
- **El catálogo maestro de vehículos:** la lista de marcas y submarcas de Equinox, independiente de cualquier aseguradora. Es un activo de la empresa, no de este proyecto, y su valor está en el trabajo humano de homologación acumulado encima.

Mezclarlas en un solo archivo habría sido más simple de escribir, pero habría atado el catálogo maestro al ciclo de vida del cotizador — precisamente lo contrario de lo que se busca.

## ⚖️ Decisión

### 1. Dos archivos SQLite, no uno `[CONFIRMADO]`

| Archivo | Qué guarda | Ciclo de vida |
|---|---|---|
| `datos/cotizador_gnp.sqlite` | Operación: catálogos de GNP, cotizaciones, resultados, usuarios, bitácora | Se puede regenerar. Si se pierde, se vuelve a bajar de GNP |
| `app/core/cat_comercial.db` | Catálogo maestro de vehículos + su homologación con GNP | **No se puede regenerar.** Contiene decisiones humanas irrecuperables |

La distinción operativa: si mañana se borra el primero, se recupera con un par de scripts. Si se borra el segundo, se pierden semanas de homologación.

### 2. Tres familias de tablas en la base de operación `[CONFIRMADO]`

Prefijo que dice de un vistazo qué es cada cosa:

- **`cat_*`** — espejo de los catálogos de GNP
- **`cot_*`** — cotizaciones y sus resultados
- **`sys_*`** — usuarios y bitácora de llamadas

```
cat_catalogos              55,697   catálogos planos del API (periodicidad, uso, ocupación…)
cat_vehiculos              48,155   el catálogo de vehículos de GNP con su CLAVEMARCA
cat_vehiculos_avance        1,187   checkpoint del ETL — qué combinaciones ya se bajaron
cat_paquetes                  392   los planes, por persona × procedencia × tipo de vehículo
cat_coberturas                167   qué coberturas trae cada paquete (Básica/Opcional) y su valor por omisión ⚠️ ver nota
cat_coberturas_excluyentes      3   pares que no pueden ir juntos (ej. Robo Parcial / Plus)
cat_cobertura_valores          225   el menú real de valores permitidos por cobertura (agregada 2026-09-10, módulo Juega y Compara)
cat_procedencias                7   Residentes, Legalizados, Fronterizos… sólo 1 verificada
cat_plantillas                   5   paquetes propios de Equinox (agregada 2026-09-10, módulo Juega y Compara) ⚠️ ver nota
cat_plantilla_coberturas        37   coberturas de cada plantilla, con su valor elegido ⚠️ ver nota

cot_cotizaciones               15   una por cotización hecha
cot_resultados                 35   una por paquete tarificado dentro de una cotización
cot_resultado_coberturas      308   el desglose de coberturas de cada resultado
cot_opcionales                  0   coberturas opcionales elegidas
cot_documentos                  2   los PDF traídos de GNP

sys_usuarios                    1   con su rol de administrador
sys_llamadas                   56   XML de ida y vuelta de cada llamada
```

> ⚠️ _(CC, 2026-09-10)_ — La fila de `cat_coberturas` de arriba decía antes "catálogo de coberturas con sus valores permitidos". No es exacto: `cat_coberturas` sólo trae **un** valor por combinación (grupo, paquete, cobertura) — el default de ese paquete, no el menú de opciones. El menú real vive aparte, en la tabla nueva `cat_cobertura_valores` (ver [ADR-007](../03_Decisiones/ADR-007-modulo-juega-y-compara.md) punto 1). Se corrige aquí con nota fechada, sin reescribir la tabla de arriba — el resto de los conteos sigue siendo el corte original.

> ⚠️ _(CC, 2026-09-10)_ — **`cat_plantillas` y `cat_plantilla_coberturas` son la excepción dentro de esta base.** Todo lo demás en `cotizador_gnp.sqlite` es `cat_*` (espejo de GNP, se regenera bajando el catálogo otra vez), `cot_*` (instancias de cotización) o `sys_*` (usuarios y bitácora) — nada de eso es irrecuperable si se pierde el archivo, sólo hay que volver a correr los scripts de carga contra GNP. Estas dos tablas no: son **contenido de negocio propio** (las plantillas de paquetes de Equinox — Amplia Plus, Amplia, Limitada, RC/Básica — y la que arme Producto para "Equinox Agente de Seguros y de Fianzas"), con el mismo perfil de riesgo que este ADR ya describe para `cat_comercial.db` en la sección de Riesgos: si se pierde el archivo sin respaldo, no se "vuelve a bajar de GNP" — se pierde el trabajo de captura. La diferencia es que, a diferencia de `cat_comercial.db` (sin script de reproducción, sólo respaldos manuales), estas dos **sí tienen script de reproducción**: `app/scripts/cargar_plantillas_equinox.php`, idempotente, que reconstruye las 4 plantillas reales desde su definición en código. Vale la pena tenerlo presente si en el futuro se decide una política de respaldo automático: qué tan seguido respaldar cada tabla depende de si su contenido se puede reconstruir corriendo un script, o no.

**Una cotización tiene N resultados.** Es la consecuencia directa de que GNP acepte varios paquetes en una sola llamada: se pide una vez y se guardan todos los planes tarificados, comparables entre sí.

### 3. El catálogo maestro y su homologación `[CONFIRMADO]`

```
marcas                        107   un registro por fabricante  (M001 = NISSAN)
submarcas                   7,777   un registro por línea + año (Sub00042 = MARCH 2024)
cat_gnp                    16,097   el catálogo de GNP tal como lo publica
homologacion_gnp            3,461   el puente: submarca nuestra ↔ línea de GNP
alias_marca_gnp                 9   excepciones donde la marca no aparece en ningún texto
submarca_de                     8   candado de aprobación manual para contradicciones
submarca_alias                150   pares de submarcas gemelas ya fusionados
```

**`submarcas` es por línea + año, no sólo por línea.** MARCH 2023 y MARCH 2024 son registros distintos, porque los catálogos de las aseguradoras los tratan distinto y porque GNP no siempre tiene los mismos años que nosotros.

La tabla `submarcas` reserva además las columnas `IDmarca_zurich`, `IDmarca_momento` e `IDmarca_ebc`, hoy en NULL. Agregar una aseguradora es agregar una columna de mapeo y correr su homologación, no rediseñar nada.

### 4. Cada relación guarda su nivel de confianza `[CONFIRMADO]`

La homologación no es exacta: empatar "CHEVROLET CORSA" con "CORSA" es inferencia por texto. Por eso cada fila de `homologacion_gnp` lleva su confianza:

| Confianza | Qué significa | Cantidad |
|---|---|---|
| **100** | Dos o más fuentes coinciden (armadora, línea, versión), o alias manual confirmado | 3,386 |
| **90** | Una sola fuente: el texto de la línea | 30 |
| **80** | Una sola fuente: voto de versión (≥60% de las versiones de la línea) | 45 |

Y cada submarca **sin** relación guarda el motivo en `motivo_sin_gnp` y `nota_sin_gnp`. Ninguna queda sin explicación: `SIN_EQUIVALENTE`, `ANIO_NO_EN_GNP`, `CANDIDATO_DUDOSO`, `MARCA_NO_EN_GNP`, `ESCRITURA_DISTINTA`, `AMBIGUO`.

> Que un vehículo no se pueda cotizar es una respuesta legítima del sistema. Lo que no es aceptable es no saber por qué.

### 5. El catálogo maestro todavía NO alimenta la cotización `[PENDIENTE]`

`CatalogoServicio` y `CotizacionServicio` hoy leen directo de `cat_vehiculos` — el catálogo de GNP. La homologación existe pero está desconectada del flujo.

Es deliberado: se separó para poder terminar la homologación sin arriesgar el cotizador, que ya funciona. **Conectarla es un paso propio y todavía no hecho.**

## 🧩 Modelo de datos

El corazón del asunto es esta cadena:

```
   submarcas (Sub00042 · MARCH · 2024)          ← lo que el vendedor elegirá
        │
        │  homologacion_gnp   (confianza 100)
        ▼
   cat_gnp / cat_vehiculos                       ← lo que GNP entiende
        │
        │  CLAVEMARCA + MODELO
        ▼
   <VEHICULO> del XML de cotización              ← lo que se manda a tarificar
```

`CLAVEMARCA` son nueve caracteres que concatenan `TIPO_VEHICULO + ARMADORA + CARROCERIA + VERSION` (`AUTGM4302`). **El año no está adentro**: viaja aparte en `<MODELO>`. La misma clave sirve para varios años, por eso la unidad cotizable es la pareja clave + año.

## ✅ Beneficios

- El catálogo maestro sobrevive a este proyecto: puede migrar a NEXO o a otro sistema sin arrastrar nada de GNP.
- Borrar y rebajar el catálogo de GNP es una operación segura, no un riesgo.
- La confianza explícita permite decidir por regla: lo de 100 cotiza solo, lo de 80 se revisa.
- Agregar una aseguradora es aditivo.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| **`cat_comercial.db` no tiene respaldo automático** | Sólo respaldos manuales con sufijo de fecha. Es el archivo irrecuperable del proyecto |
| **Seis respaldos manuales conviviendo en `app/core/`** | ~13 MB de `.bak_pre_*` sin política de retención ni de limpieza |
| **`app/core/*.db` no está en `.gitignore`** | Sólo `datos/*.sqlite` lo está. El catálogo maestro y sus respaldos pueden acabar commiteados |
| **55.5% del catálogo maestro sin homologar** | 4,316 submarcas que hoy no se pueden cotizar en GNP |
| **`cat_comercial_diseño.md` está desactualizado** | Dice 110 marcas, 7,941 submarcas y mapeo en NULL. Es lo primero que leería alguien nuevo |
| **`cat_plantillas`/`cat_plantilla_coberturas` viven en `cotizador_gnp.sqlite`, que "se puede regenerar" — pero ellas no** | Son contenido de negocio, no espejo de GNP. Mitigado por tener script de reproducción (`app/scripts/cargar_plantillas_equinox.php`), a diferencia de `cat_comercial.db` — ver nota del punto 2 |

## 🛡️ Mitigaciones

- Los respaldos manuales existen y están fechados; falta formalizarlos como rutina.
- Cada submarca sin homologar tiene motivo y nota, y sale exportada a `datos/comercial_sin_gnp.xlsx` con una hoja por motivo, listo para revisión humana.
- El grueso del faltante (`SIN_EQUIVALENTE`, `MARCA_NO_EN_GNP`) es esperable: son vehículos que GNP probablemente no asegura. El trabajo con retorno real está en `ESCRITURA_DISTINTA` (407 casos, alta certeza) y `AMBIGUO` (142, decisión humana).

## 🧩 Consideraciones futuras

- **Conectar la homologación al flujo de cotización** — el paso que falta para que el catálogo maestro sirva de verdad.
- **Homologar Zurich, Momento y EBC** con el mismo motor; las columnas ya están.
- **Versión de vehículo.** La homologación de hoy llega a marca + submarca (línea + año). La versión —el trim— quedó fuera a propósito.
- **Respaldo automático** de `cat_comercial.db` con retención definida — al definirlo, considerar también `cat_plantillas`/`cat_plantilla_coberturas` (ver nota del punto 2): mismo perfil de riesgo, distinto mecanismo de mitigación (script de reproducción en vez de respaldo de archivo).

## 👥 Aprobación

- Producto / Negocio: ✅
- TI / Arquitectura: ✅

## Pendiente `[PENDIENTE]`

- Conectar `homologacion_gnp` a `CatalogoServicio` y `CotizacionServicio`.
- Agregar `app/core/*.db` y `app/core/*.bak_*` al `.gitignore`, y decidir qué hacer con lo que ya esté en el historial de git.
- Política de respaldo y retención de `cat_comercial.db`.
- Actualizar `cat_comercial_diseño.md` con los números reales y las rutas correctas.
- Revisar los 45 casos de confianza 80 y los 18 pares de `gemelas_por_confirmar.csv`.

## Referencias

- [ADR-002 — Stack y estructura](./ADR-002-stack-y-estructura.md)
- [ADR-004 — Catálogo maestro propio](../03_Decisiones/ADR-004-catalogo-maestro-propio.md)
- [`docs/homologacion-estado.md`](../homologacion-estado.md) — corte de resultados al 2026-08-27
- `app/core/cat_comercial_diseño.md` — diseño original del catálogo maestro ⚠️ desactualizado
- `app/core/Esquema.php` — creación y migración del esquema de la base de operación
