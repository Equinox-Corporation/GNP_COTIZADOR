# cat_comercial.db — Catálogo maestro de vehículos

## Qué diseñamos

Un catálogo propio de vehículos, independiente de cualquier aseguradora.

El problema de fondo es que cada aseguradora nombra y clasifica los vehículos a su manera: GNP le llama diferente a lo que Zurich llama diferente a lo que Momento llama. No hay un estándar. Si el sistema depende del catálogo de una aseguradora, estamos atados a su nomenclatura.

La solución: **crear nuestro propio catálogo comercial** como punto de referencia central. Las aseguradoras apuntan hacia aquí, no al revés.

---

## Qué buscamos

Que el sistema pueda decir:

> "El NISSAN MARCH 2024, sin importar cómo lo llame GNP, Zurich o Momento, siempre es `Sub00042` en nuestro catálogo."

Con eso logramos:
- Comparar cotizaciones entre aseguradoras sobre el mismo vehículo real.
- Que el robot de cotización (Easycot) pueda navegar cualquier catálogo de aseguradora y mapear al registro correcto.
- Que si mañana entra una aseguradora nueva, solo se agrega una columna de mapeo, no se rediseña nada.

---

## Qué tenemos hasta ahora

**Base de datos:** `cat_comercial.db` (SQLite, portable, sin servidor)

### Tabla `marcas`
Un registro por fabricante.

| id   | nombre   |
|------|----------|
| M001 | NISSAN   |
| M002 | ACURA    |
| M003 | ALFA ROMEO |
| …    | …        |

**Total: 110 marcas.**

### Tabla `submarcas`
Un registro por combinación de **línea + año modelo**.
MARCH 2023 y MARCH 2024 son dos registros distintos porque los catálogos de aseguradoras los tratan diferente.

| Campo | Ejemplo | Notas |
|-------|---------|-------|
| `id` | Sub00042 | ID propio, nunca cambia |
| `nombre` | MARCH | Nombre comercial de la línea |
| `tipo` | individual | Hoy todos son `individual`. A futuro: `automoviles`, `motos`, `transporte`, `carga` |
| `modelo` | 2024 | Año modelo |
| `IDmarcacomercial` | M001 | FK → marcas |
| `IDmarca_gnp` | NULL | Pendiente de mapear |
| `IDmarca_zurich` | NULL | Pendiente de mapear |
| `IDmarca_momento` | NULL | Pendiente de mapear |
| `IDmarca_ebc` | NULL | Pendiente de mapear |

**Total: 7,941 submarcas** (fuente: catálogo del robot Easycot, basado en ANA, MOMENTO, QUALITAS, SURA, ZURICH).

---

## Qué hace Beto — mapeo con GNP

El siguiente paso es conectar el catálogo de GNP con este catálogo maestro.

### Paso 1 — Construir `cat_gnp`

Crear una tabla (puede ser en esta misma BD o en una separada) con el catálogo tal como GNP lo publica:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id_gnp` | TEXT PK | Clave que GNP usa internamente |
| `marca_gnp` | TEXT | Nombre de la marca según GNP |
| `tipo_gnp` | TEXT | Nombre de la línea según GNP |
| `modelo_gnp` | TEXT | Año modelo según GNP |
| `descripcion` | TEXT | Descripción adicional si GNP la provee |

### Paso 2 — Mapear hacia cat_comercial

Una vez cargado `cat_gnp`, recorrer cada registro y encontrar su equivalente en `submarcas`. Cuando se encuentra la correspondencia, llenar el campo `IDmarca_gnp` en `submarcas` con el `id_gnp`.

```
cat_gnp.id_gnp  →  submarcas.IDmarca_gnp
```

El mapeo puede ser automático (por similitud de nombre + año) o manual para los casos donde GNP usa un nombre distinto al comercial.

### Paso 3 — Repetir por aseguradora

La misma lógica aplica para Zurich (`IDmarca_zurich`), Momento (`IDmarca_momento`) y EBC (`IDmarca_ebc`).

---

## Archivos

| Archivo | Descripción |
|---------|-------------|
| `data/catalogo.db` | BD del robot Easycot — fuente original |
| `data/cat_comercial.db` | Este catálogo maestro |
| `data/cat_comercial_diseño.md` | Este documento |
