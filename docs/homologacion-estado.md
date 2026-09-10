# Homologación GNP — estado al cierre de esta etapa

Corte: **2026-08-27**. Para quien no siguió el proceso: esto es un resumen de dónde quedó, no una bitácora.

## Qué es esto

`cat_comercial.db` es el catálogo maestro de vehículos del proyecto (marcas + submarcas, independiente de cualquier aseguradora). Esta etapa conectó ese catálogo con el de GNP (`cat_vehiculos`, descargado vía API) para que el sistema sepa, para cada vehículo del catálogo maestro, cuál es su equivalente en GNP — sin ese enlace no se puede cotizar.

Alcance de esta etapa: **solo Marca y Submarca (Línea + Año)**. Versión queda fuera a propósito. Procedencia Residentes.

## Números

| | |
|---|---|
| Marcas en el catálogo maestro | 107 |
| Submarcas (Línea+Año) | 7,777 |
| **Relacionadas con GNP** | **3,461 (44.5%)** |
| Sin relación | 4,316 (55.5%) |
| Catálogo GNP (`cat_vehiculos`) | 48,155 filas, bajado 2026-08-27 |

### Relacionadas, por confianza

| Confianza | Qué significa | Cantidad |
|---|---|---|
| 100 | Dos o más fuentes (armadora/línea/versión) coinciden en la misma marca, o alias manual confirmado | 3,386 |
| 90 | Una sola fuente: el texto de línea | 30 |
| 80 | Una sola fuente: voto de versión (≥60% de las versiones de la línea) | 45 |

Confianza 80 son los únicos casos que valdría la pena que alguien confirme a mano antes de darlos por definitivos — están en el CSV de pendientes marcados `confianza_media_por_confirmar`.

## Las 4,316 sin relación — clasificadas, ninguna sin motivo

Cada submarca sin relación tiene ahora `motivo_sin_gnp` y `nota_sin_gnp` en `cat_comercial.db`, y aparece en su propia hoja de `datos/comercial_sin_gnp.xlsx`.

| Motivo | Cantidad | Qué significa | Qué hacer |
|---|---|---|---|
| **SIN_EQUIVALENTE** | 2,163 | El mejor candidato de texto quedó por debajo de 60% de parecido. GNP probablemente no tiene ese modelo. | Revisar los de mayor volumen; el resto es baja prioridad. |
| **ANIO_NO_EN_GNP** | 781 | El nombre empata 100% con una línea de GNP de la misma marca, pero GNP no tiene ese año específico. La nota dice en qué años sí lo tiene. | No requiere criterio — es un hueco real de años en el catálogo de GNP (o en el nuestro). Candidato a resolverse solo si GNP actualiza su catálogo, o a mapear manualmente al año más cercano si el negocio lo permite. |
| **CANDIDATO_DUDOSO** | 476 | Candidato con 60-79% de parecido. | Requiere que una persona decida. |
| **MARCA_NO_EN_GNP** | 347 | La marca completa no existe en GNP por ningún camino (armadora, línea ni versión). Subclasificada en la nota: LUJO / DESCONTINUADA / CHINA_RECIENTE / OTRA. | La mayoría (LUJO, DESCONTINUADA) es esperable que GNP no las asegure vía catálogo estándar. Las CHINA_RECIENTE (Baojun, Jaecoo, Neta, VGV, Wey, Wuling, Baw, Yutong) son las que vale la pena revisar cada tanto — es plausible que GNP las agregue pronto. |
| **ESCRITURA_DISTINTA** | 407 | Candidato con 80-99% de parecido — casi seguro el mismo vehículo, escrito diferente ("GIUILIETTA" vs "GIULIETTA", "300 C" vs "300C"). | Confirmar y mapear a mano; alto valor, baja incertidumbre. |
| **AMBIGUO** | 142 | Dos o más líneas de GNP compiten por esta submarca (mismo texto, mismo año, códigos internos distintos de GNP). La nota lista cuáles. | Requiere que una persona elija cuál es la correcta. |
| **FUERA_DE_ALCANCE** | 0 | Alto Valor / Clásicos / Avanzada / motocicletas. | No aplicó ninguna fila: esas categorías son cajones internos de GNP sin marca real detrás (nunca compiten por una submarca comercial), y el catálogo maestro hoy es 100% autos individuales, no motos. Queda documentado por si el catálogo maestro incorpora motos a futuro. |

## Cómo se resolvió (para quien quiera el detalle técnico)

Cada línea de GNP se resuelve a una marca combinando hasta tres fuentes — armadora, texto de línea, y voto de versión — con dos mecanismos de excepción:

- **`alias_marca_gnp`**: para los pocos casos donde la marca no aparece en ningún texto (ej. "GENERAL MOTORS BLAZER" → Chevrolet) o GNP nombra la marca distinto (ej. antes de fusionar duplicados, Tesla).
- **`submarca_de`**: candado de aprobación manual para cuando línea o versión CONTRADICEN a la armadora (ej. Jeep/Dodge apareciendo bajo la armadora "Chrysler", Mini bajo "BMW"). Si el par no está aprobado ahí, no se escribe nada automático.

Antes de esto se corrigieron datos rotos en el propio catálogo maestro: una marca fantasma (X-TRAIL, que en realidad era un modelo de Nissan mal cargado), dos marcas duplicadas (Tesla/Tesla Motors, Link&Co/Lynk Co), y 150 pares de submarcas gemelas con distinta puntuación ("300 C" / "300C").

## Qué falta (fuera de esta etapa)

- **`etl_vehiculos.php` hace más llamadas de las necesarias.** `cat_catalogos` guarda la lista de armadoras dos veces (una global sin filtrar, 237 claves; una filtrada por tipo_vehiculo+sub_ramo, 225 claves). El script usa la lista global para los 4 tipos de vehículo, en vez de la filtrada — eso multiplica las llamadas por 4 sin necesidad. El arreglo (leer la lista filtrada por tipo) queda pendiente; no afecta los datos ya bajados, solo la eficiencia de la próxima corrida. De paso, limpiar las ~710 filas en estado `ERROR` de `cat_vehiculos_avance` que dejaron las combinaciones tipo+armadora inválidas de esta corrida.
- **Zurich, Momento, EBC**: mismo patrón, sin empezar (columnas `IDmarca_zurich`, `IDmarca_momento`, `IDmarca_ebc` en `submarcas`, todas en NULL).
- Este catálogo de homologación **no está conectado al flujo de cotización** todavía (`CatalogoServicio`, `CotizacionServicio` no lo tocan). Conectarlo es un paso aparte, deliberadamente no incluido aquí.

## Archivos

| Archivo | Qué es |
|---|---|
| `app/scripts/limpiar_cat_comercial.php` | Corrige marcas fantasma/duplicadas y submarcas gemelas en el catálogo maestro. |
| `app/scripts/homologar_marcas_submarcas.php` | El motor: resuelve marca+submarca por línea de GNP, escribe `homologacion_gnp` y `motivo_sin_gnp`/`nota_sin_gnp`. |
| `app/scripts/exportar_comercial_sin_gnp_excel.php` | Convierte el reporte de pendientes a `.xlsx` con una hoja por motivo. |
| `datos/comercial_sin_gnp.xlsx` | El entregable para revisión humana. |
| `datos/pendientes_homologacion_gnp.csv` | Lado GNP → Comercial: líneas de GNP que no encontraron submarca, más los casos de confianza 80 a confirmar. |
| `datos/gemelas_por_confirmar.csv` | 18 pares de submarcas con nombres que se confunden por letra/dígito (S↔5, O↔0, I↔1, B↔8, transposición) — no se fusionaron solos por ser de alto riesgo. |
