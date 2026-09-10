# ADR-005 — Reglas de negocio verificadas contra el servicio de GNP

## 📌 Estado

**Confirmado** (Beto, 2026-09-10). Producto/Negocio ✅ · TI/Arquitectura ✅.

Cada regla de este documento sale de **haberla visto ocurrir contra producción**, no de leer el manual. Donde el manual y el servicio real difieren, manda el servicio real y así se anota.

## 🧠 Contexto

GNP no entregó ambiente de pruebas. Todo lo que se sabe del servicio se aprendió cotizando de verdad: 15 cotizaciones, 35 tarificaciones y 56 llamadas registradas en bitácora entre agosto y septiembre de 2026.

Varias de esas reglas **no están en el manual**, o están mal. Perderlas costaría volver a descubrirlas a golpes, y algunas —como cuál campo es el precio— tienen consecuencia directa en dinero.

Este ADR es la memoria de esos hallazgos. Cada uno viene con la decisión que se tomó a partir de él.

## ⚖️ Decisión

### 1. El precio es `TOTAL_PAGAR`, nunca `PRIMA_NETA` `[CONFIRMADO]`

La prima neta no incluye derechos ni IVA. Mostrarla al vendedor sería cotizar alrededor de **21% por debajo**.

La aritmética quedó verificada contra cuatro cotizaciones reales:

```
PRIMA_NETA  +  DERECHOS_POLIZA  +  IVA       =  TOTAL_PAGAR
 6,622.16   +      680.00       + 1,168.35   =   8,470.51   ✓
```

Y el IVA es 16% sobre **neta + derechos**: `(6,622.16 + 680) × 0.16 = 1,168.35`, exacto.

Dos observaciones que salieron de los datos:

- **`DERECHOS_POLIZA` fue 680 constante** en las 35 tarificaciones observadas. No se asume que siempre lo sea; se anota como lo visto.
- **El descuento ya viene aplicado dentro de la neta**: `PRIMA_TECNICA − descuento = PRIMA_NETA`. No hay que restarlo otra vez.

### 2. Hay conceptos económicos que el manual no documenta `[CONFIRMADO]`

La respuesta trae 15 conceptos, y **dos no aparecen en el manual**: `DESCUENTO_POAJUTEC` y un `RECARGO_DESCUENTO` que llega en negativo.

**Decisión:** se guarda el bloque completo en `cot_resultados.conceptos_json`, tal cual llega, además de las columnas desglosadas. Si mañana GNP agrega un concepto, no se pierde aunque el código no lo conozca.

### 3. Quien fija el precio es el **conductor**, no el contratante `[CONFIRMADO]`

GNP tarifica con la edad y el código postal **del conductor**. En el PDF que ve el cliente aparecen los del conductor.

**Decisión:** el formulario pide una sola persona —la sección **Solicitante**— con los campos del conductor, que son los que tarifican. El contratante hereda esos dos datos **en el servidor**, nunca en el navegador. El XML que sale a GNP sigue llevando los dos bloques que el servicio exige.

> ⚠️ Cuando se construya la pantalla de emisión hay que **separarlos otra vez**: ahí el titular sí puede ser otra persona. Está anotado en el código, en el punto exacto donde hay que tocar.

### 4. Del contratante, GNP casi no pide nada `[PENDIENTE]`

El bloque `<CONTRATANTE>` tiene que ir, pero su contenido no: el propio ejemplo de persona moral del kit manda únicamente `TIPO_PERSONA` y `CODIGO_POSTAL` —sin nombre, sin edad, sin RFC— y cotiza igual.

**Decisión:** el formulario ya no exige nombre ni RFC, y el cliente XML escribe esas etiquetas sólo cuando traen dato.

**Falta comprobarlo contra producción.** La guía de la prueba está en [`docs/02.5-contratante-minimo.md`](../02.5-contratante-minimo.md). Hasta que se corra, es una deducción del ejemplo de GNP, no un hecho.

### 5. GNP responde `200 OK` aunque el negocio falle `[CONFIRMADO]`

El código HTTP no dice nada. El veredicto va dentro del XML.

**Decisión:** el éxito nunca se determina por el código de respuesta. Siempre se inspecciona si el cuerpo trae `<ERROR>`.

### 6. Los errores se clasifican por el campo `ORIGEN` `[CONFIRMADO]`

El manual no publica catálogo de códigos de error. La clasificación se construyó observando lo que llegó:

| `ORIGEN` | Qué significa | Qué hacer |
|---|---|---|
| `ldapService` | Contraseña incorrecta o caducada | **No reintentar.** Avisar a administración, no al vendedor |
| `catalogos` | Dato inexistente (CP, clave de marca) | No reintentar. Corregir la captura |
| `runtime` | Falla interna de GNP | Un reintento; si persiste, registrar |
| `gateway` (504) | Respuesta demasiado grande — ver punto 7 | Pedir menos, no reintentar |
| `parser` | La respuesta no se pudo interpretar | Registrar y revisar el XML crudo |
| `impresor` / `impresion` | Problema al generar el PDF | Reintentar la impresión, la cotización sigue viva |
| `cotizador-eot` | Validación de negocio del motor de cotización: coberturas excluyentes juntas (clave 37), suma asegurada fuera de lo permitido para esa cobertura (clave 14), o cobertura que no aplica a ese producto (clave 12) | No reintentar. El mensaje ya trae la clave y el motivo — mostrarlo tal cual |

Es mucho más confiable que adivinar por palabras clave en el texto del mensaje.

_(CC, 2026-09-10)_ — Se agrega `cotizador-eot` a la tabla. Las tres claves están en `sys_llamadas`, cada una con su fila:

- **Clave 37** — `sys_llamadas.id = 62`. Pedir juntas Auto Sustituto (`0000001414`) y Auto Sustituto Plus (`0000001415`) sobre la cotización base de Honda Fit/Amplia: `LAS COBERTURAS AUTO SUSTITUTO, AUTO SUSTITUTO PLUS Y AYUDA PARA PÉRDIDAS TOTALES SON EXCLUYENTES. FAVOR DE SELECCIONAR SÓLO UNA.` Esta clave ya estaba anotada en un comentario de `app/core/Esquema.php` (línea 361-362) fechado 25-ago-2026, pero **sin fila correspondiente en `sys_llamadas`** — se repitió la llamada hoy para poder citarla como corresponde. El hallazgo del comentario resultó exacto.
- **Claves 14 y 12** — `sys_llamadas.id = 60` y `61`. Ver [`docs/02.6-coberturas-modificadas.md`](../02.6-coberturas-modificadas.md).

Cae correctamente en `E_DATOS` por la regla de respaldo de `GnpClient::clasificar()` (clave ≥ 2), así que nunca rompió nada — quedaba pendiente documentarlo, no corregirlo.

### 7. Un `504` no es un rechazo `[CONFIRMADO]`

Significa que GNP no alcanzó a armar una respuesta demasiado grande. **La solución es pedir menos, no reintentar** — reintentar lo mismo vuelve a fallar igual.

Se observó en descargas de catálogo con filtros demasiado amplios.

### 8. Una sola llamada devuelve varios paquetes `[CONFIRMADO]`

`<PAQUETES>` acepta varios. Verificado: una cotización trajo **cuatro planes** en una sola petición — Amplia, Premium, Amplia Total y Auto Elite, cada uno con su precio y sus coberturas.

**Decisión:** el comparativo entre planes de GNP no cuesta llamadas extra. Se pide una vez y se guardan N resultados. Es la razón de que `cot_cotizaciones` tenga N `cot_resultados`.

### 9. La cotización vale 15 días naturales `[CONFIRMADO]`

Pasado el plazo el sistema no deja imprimirla y lo dice en pantalla.

### 10. GNP manda el PDF por correo, con su remitente y su plantilla `[CONFIRMADO]`

El servicio de impresión no sólo devuelve el PDF: **también se lo envía por correo** a la dirección que se le indique.

**Decisión:** ese correo sale de la variable `GNP_CORREO_IMPRESION` y **no** del formulario. Si ahí se pusiera el correo del cliente, GNP le escribiría directo y el vendedor se enteraría después. Mientras Comercial no decida otra cosa, va un buzón interno de Equinox — el primer contacto con el cliente lo sigue controlando el vendedor.

### 11. Sólo la procedencia Residentes está verificada `[CONFIRMADO]`

De las siete procedencias (Residentes, Legalizados, Fronterizos, Clásicos, Antiguos, Importado, Blindado), **sólo `01` (Residentes) se ha probado contra el servicio**.

Las otras seis están en `cat_procedencias` con la clave **vacía**, esperando que GNP la confirme.

**Decisión:** el sistema las **rechaza con un mensaje claro** en vez de mandar un dato inventado. Es preferible decir "todavía no" que cotizar con una clave adivinada.

> Aquí está el ejemplo más claro de por qué importa distinguir lo verificado de lo supuesto: las claves del Excel del kit (10 Legalizado, 03 Fronterizo…) parecían buenas, y resulta que no están comprobadas.

## ✅ Beneficios

- Cada regla evita un error concreto que ya costó descubrir.
- La clasificación por `ORIGEN` permite reaccionar distinto ante cada falla: avisar a administración, corregir la captura o registrar.
- Guardar el JSON crudo de conceptos protege contra cambios futuros de GNP.
- El sistema es honesto sobre lo que no sabe, en vez de inventar.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| **`DERECHOS_POLIZA` podría no ser siempre 680** | Se observó constante en 35 tarificaciones. No es garantía |
| **La taxonomía de `ORIGEN` es incompleta** | Sólo cubre lo que se ha visto fallar. Aparecerán orígenes nuevos |
| **El punto 4 no está comprobado** | Si GNP rechaza el contratante mínimo, hay que revertir la simplificación del formulario |
| **Seis de siete procedencias bloqueadas** | Limita el catálogo comercializable hasta que GNP responda |
| **Todo esto se aprendió en producción** | Cada descubrimiento futuro tiene el mismo costo |

## 🛡️ Mitigaciones

- Se guarda el XML de ida y vuelta de cada llamada (ver [ADR-006](./ADR-006-evidencia-y-bitacora.md)): cualquier comportamiento nuevo queda registrado y es analizable después.
- El origen desconocido cae en una categoría propia que se registra en vez de tratarse como éxito.
- Solicitar ambiente de pruebas a GNP sigue abierto.

## 🧩 Consideraciones futuras

- Correr la prueba `02.5` y cerrar el punto 4.
- Pedir a Conectividad GNP las claves de las seis procedencias faltantes.
- Ampliar la taxonomía de `ORIGEN` conforme aparezcan casos.
- Preguntar a GNP por el bloque "Contacta a tu agente" del PDF, que viene vacío. No bloquea el desarrollo, sí la salida a cliente.

## 👥 Aprobación

- Producto / Negocio: ✅
- TI / Arquitectura: ✅

## Pendiente `[PENDIENTE]`

- Prueba `02.5` — contratante mínimo contra producción.
- Claves de las seis procedencias no verificadas.
- Bloque "Contacta a tu agente" vacío en el PDF de GNP.
- Confirmar si `DERECHOS_POLIZA` varía por producto o procedencia.

## Referencias

- [ADR-006 — Evidencia y bitácora](./ADR-006-evidencia-y-bitacora.md)
- [`docs/02.5-contratante-minimo.md`](../02.5-contratante-minimo.md)
- `app/core/GnpClient.php` — el cliente donde viven estas reglas
- Manual técnico WS Preferente v8.7.9 · kit `IC260812018`
