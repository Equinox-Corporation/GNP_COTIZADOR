# ADR-004 — El vehículo se identifica con un catálogo maestro propio

## 📌 Estado

**Confirmado** (Beto, 2026-09-10). Producto/Negocio ✅ · TI/Arquitectura ✅.
Homologación con GNP ejecutada; **falta conectarla al flujo de cotización** (ver Pendiente).

Es la decisión estructural más importante del proyecto. El modelo de datos que la implementa está en [ADR-003](../02_Arquitectura/ADR-003-modelo-de-datos.md).

## 🧠 Contexto

### El problema

Cada aseguradora nombra y clasifica los vehículos a su manera. No hay estándar.

GNP identifica un coche con una clave jerárquica de nueve caracteres —`AUTGM4302`— donde, además, las palabras engañan: lo que GNP llama `ARMADORA` es la marca, lo que llama `CARROCERIA` es la **línea comercial** (`43` = Chevrolet Corsa, no "sedán"), y lo que llama `VERSION` es el trim. El año viaja aparte.

SICAS, el sistema que ya usa Equinox, identifica el mismo coche con texto en cascada más una clave de versión **distinta por cada aseguradora**: la de Qualitas y la de ANA para el mismo vehículo no coinciden.

Zurich, Momento y EBC tendrán lo suyo.

### La tentación, y por qué se descartó

Lo rápido habría sido adoptar el catálogo de GNP como referencia: ya está descargado, tiene 48,155 filas y viene con claves limpias.

Se descartó por tres razones:

1. **Ata el proyecto a un proveedor.** Si mañana entra Zurich, no hay dónde colgarla: el catálogo de GNP no tiene lugar para claves ajenas.
2. **No sobrevive.** Si GNP cambia su nomenclatura o se pierde el convenio, se pierde la identidad de los vehículos.
3. **No permite comparar.** Para poner dos aseguradoras lado a lado hace falta saber que están hablando del mismo coche, y eso exige un tercero neutral.

Es además el mismo criterio que NEXO ya había adoptado para Ramo/Subramo: catálogo propio simplificado, y el mapeo hacia el sistema externo en una tabla administrable.

## ⚖️ Decisión

### 1. Existe un catálogo maestro propio, y es la referencia central `[CONFIRMADO]`

`cat_comercial.db` guarda marcas y submarcas de Equinox, **independiente de cualquier aseguradora**. Cada vehículo tiene un ID nuestro que nunca cambia.

> "El NISSAN MARCH 2024, sin importar cómo lo llame GNP, Zurich o Momento, siempre es `Sub00042` en nuestro catálogo."

**Las aseguradoras apuntan hacia el catálogo maestro, no al revés.** El modelo es radial, no lineal:

```
                    ┌──────────────────────────┐
                    │      submarcas           │  ← la referencia
                    │  Sub00042 · MARCH · 2024 │     lo que ve el vendedor
                    └────────────┬─────────────┘
                                 │
          ┌──────────────┬───────┴───────┬──────────────┐
          ▼              ▼               ▼              ▼
     homologacion   IDmarca_zurich  IDmarca_momento  IDmarca_ebc
        _gnp            (NULL)          (NULL)          (NULL)
          │
          ▼
     cat_gnp → CLAVEMARCA + MODELO
```

### 2. La unidad del catálogo es línea + año `[CONFIRMADO]`

MARCH 2023 y MARCH 2024 son dos registros distintos. No es redundancia: los catálogos de las aseguradoras los tratan como cosas distintas, y GNP no siempre tiene los mismos años que nosotros — 781 submarcas empatan perfecto por nombre pero el año no existe en GNP.

### 3. Agregar una aseguradora es aditivo `[CONFIRMADO]`

Una columna de mapeo en `submarcas` y correr el motor de homologación. No se rediseña nada, no se toca lo existente.

### 4. Toda relación lleva su nivel de confianza `[CONFIRMADO]`

El emparejamiento es **inferencia por texto**, no una llave común. `"CHEVROLET CORSA / CORSA M / 2007"` contra `"CORSA"` no coinciden por igualdad de cadena.

El motor combina hasta tres fuentes —armadora, texto de línea y voto de versión— y guarda qué tan seguro está: 100 cuando dos o más fuentes coinciden, 90 con una sola fuente de texto, 80 cuando sólo vota la versión.

Con dos mecanismos de excepción, ambos manuales y explícitos:

- **`alias_marca_gnp`** — para cuando la marca no aparece en ningún texto (`GENERAL MOTORS BLAZER` → Chevrolet).
- **`submarca_de`** — candado de aprobación para cuando línea o versión **contradicen** a la armadora (Jeep y Dodge apareciendo bajo la armadora "Chrysler", Mini bajo "BMW"). Si el par no está aprobado ahí, no se escribe nada automático.

### 5. Toda submarca sin relación tiene un motivo escrito `[CONFIRMADO]`

Ninguna queda como "no encontrada" a secas. Cada una guarda su `motivo_sin_gnp`:

| Motivo | Cantidad | Qué hacer |
|---|---|---|
| `SIN_EQUIVALENTE` | 2,163 | GNP probablemente no lo asegura. Revisar sólo los de mayor volumen |
| `ANIO_NO_EN_GNP` | 781 | Hueco real de años. No requiere criterio |
| `CANDIDATO_DUDOSO` | 476 | 60-79% de parecido. Decide una persona |
| `MARCA_NO_EN_GNP` | 347 | Subclasificada: lujo, descontinuada, china reciente. Las chinas se revisan cada tanto |
| `ESCRITURA_DISTINTA` | 407 | 80-99%: casi seguro el mismo coche mal escrito. **Alto valor, baja incertidumbre** |
| `AMBIGUO` | 142 | Varias líneas de GNP compiten. Decide una persona |

### 6. La confianza gobierna el comportamiento `[PENDIENTE]`

Regla propuesta, aún no implementada en el flujo: **una relación con confianza menor a 90 no cotiza automáticamente** — se le muestra al usuario para que verifique el vehículo antes de mandar.

Cotizar el coche equivocado es peor que no cotizar.

### 7. El alcance de hoy es marca + submarca; la versión queda fuera `[CONFIRMADO]`

La homologación llega al nivel línea + año. El trim —"A-SPEC 4P L4 2.0L AUT"— no se homologó a propósito: multiplica la complejidad y no es indispensable para cotizar.

## ✅ Beneficios

- Permite comparar cotizaciones de aseguradoras distintas sobre el mismo vehículo real.
- El catálogo es un activo de Equinox: sobrevive a este proyecto y puede migrar a NEXO.
- El robot de SICAS podría mapear al mismo registro, unificando los dos mundos.
- El trabajo manual se hace **una vez por vehículo**, no una vez por cotización.
- 44.5% del catálogo ya está resuelto, y el 55.5% restante está clasificado, no en el limbo.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| **El emparejamiento por texto puede equivocarse** | Un falso positivo cotiza un coche que no es. Es el peor escenario del proyecto |
| **Más de la mitad del catálogo no se puede cotizar** | 4,316 submarcas sin relación |
| **La homologación está desconectada del flujo** | Existe, pero el cotizador todavía no la usa |
| **`cat_comercial.db` es irrecuperable** | Concentra el trabajo humano y no tiene respaldo automático |
| **Mantenimiento continuo** | GNP actualiza su catálogo; la homologación se desactualiza sola |

## 🛡️ Mitigaciones

- La confianza explícita permite tratar distinto lo seguro de lo dudoso, en vez de confiar en todo por igual.
- `submarca_de` impide que el automatismo resuelva solo los casos contradictorios — los que más se prestan a error.
- Antes de homologar se limpió el propio catálogo maestro: una marca fantasma (X-TRAIL, que era un modelo de Nissan mal cargado), dos marcas duplicadas (Tesla/Tesla Motors, Link&Co/Lynk Co) y 150 pares de submarcas gemelas con distinta puntuación.
- Los 18 pares que se confunden por letra contra dígito (S↔5, O↔0, I↔1, B↔8) **no se fusionaron automáticamente** por ser de alto riesgo: quedaron en `gemelas_por_confirmar.csv` para revisión humana.
- El entregable `comercial_sin_gnp.xlsx` trae una hoja por motivo, ordenado para que la revisión humana ataque primero lo de mayor retorno.

## 🧩 Consideraciones futuras

- **Homologar Zurich, Momento y EBC.** Mismo motor, columnas ya reservadas.
- **Homologar a nivel versión**, si el negocio lo pide.
- **Reconciliar con el catálogo de NEXO** (`vehiculo_marcas` / `vehiculo_versiones`, ~15,000 versiones). Hoy son dos catálogos distintos en la misma empresa; a la larga uno debería absorber al otro.
- **Refresco periódico** conforme GNP actualice su catálogo, con reporte de qué cambió.

## 👥 Aprobación

- Producto / Negocio: ✅
- TI / Arquitectura: ✅

## Pendiente `[PENDIENTE]`

- **Conectar la homologación al flujo de cotización.** Es el paso que convierte todo este trabajo en valor real.
- Implementar la regla de confianza < 90 → verificación del usuario.
- Revisar los 45 casos de confianza 80 marcados `confianza_media_por_confirmar`.
- Resolver los 407 `ESCRITURA_DISTINTA` — el lote con mejor relación esfuerzo/beneficio.
- Confirmar los 18 pares de `gemelas_por_confirmar.csv`.
- Definir cada cuándo se revisan las marcas chinas recientes (Baojun, Jaecoo, Neta, VGV, Wey, Wuling, Baw, Yutong).

## Referencias

- [ADR-003 — Modelo de datos](../02_Arquitectura/ADR-003-modelo-de-datos.md)
- [`docs/homologacion-estado.md`](../homologacion-estado.md) — corte al 2026-08-27 con el detalle completo
- `app/scripts/homologar_marcas_submarcas.php` — el motor
- `app/scripts/limpiar_cat_comercial.php` — la limpieza previa
- `datos/comercial_sin_gnp.xlsx` — entregable de revisión humana
- `datos/gemelas_por_confirmar.csv` — los 18 pares de alto riesgo
