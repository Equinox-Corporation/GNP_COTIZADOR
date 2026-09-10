# ADR-006 — Cada llamada guarda su evidencia, y la contraseña nunca se guarda en claro

## 📌 Estado

**Confirmado** (Beto, 2026-09-10). Producto/Negocio ✅ · TI/Arquitectura ✅.

Complementa [ADR-005](./ADR-005-reglas-verificadas-gnp.md): si aquello es lo que se aprendió, esto es el mecanismo que permitió aprenderlo.

## 🧠 Contexto

Tres necesidades distintas empujan en la misma dirección:

1. **GNP lo exige para dar soporte.** Ante cualquier error, Conectividad pide los XML de entrada y salida en `.txt`. Sin ellos no hay conversación posible.
2. **GNP lo exige para certificar.** La liberación formal requiere entregar el flujo completo de XML de una cotización.
3. **No hay ambiente de pruebas.** Todo se descubre en producción. Si una llamada se comporta raro y no quedó registrada, ese conocimiento se perdió: no se puede reproducir a voluntad.

Y una restricción que choca de frente con lo anterior: **el XML de cotización lleva la contraseña en texto plano**, dentro del propio cuerpo del mensaje. Guardar el XML tal cual sería guardar la contraseña del Portal de Intermediarios en la base, repetida en cada llamada.

## ⚖️ Decisión

### 1. Toda llamada a GNP se registra, desde la primera `[CONFIRMADO]`

`sys_llamadas` guarda, por cada petición: el servicio invocado, la cotización asociada, el XML de entrada, el XML de salida, el código HTTP, los milisegundos, el tamaño, y —si falló— la clave, el origen y la descripción del error.

No es opcional ni configurable. Una llamada sin registro es una llamada que no ocurrió.

### 2. La contraseña se enmascara **antes** de persistir `[CONFIRMADO]`

`<PASSWORD>` se sustituye por `***` antes de que el XML toque la base. No al leerlo, no al exportarlo: **antes de escribirlo**.

La diferencia importa. Enmascarar al leer deja la contraseña en el disco y confía en que todos los caminos de lectura la filtren. Enmascarar al escribir significa que nunca estuvo ahí.

### 3. La evidencia se descarga en dos archivos separados `[CONFIRMADO]`

Desde la pantalla de resultado o desde el historial:

- `?r=evidencia&id=N&parte=peticion` — lo que se le pidió a GNP
- `?r=evidencia&id=N&parte=respuesta` — lo que GNP contestó

Van separados a propósito: cuando sólo hace falta revisar un lado, abrir un archivo enorme con los dos es estorbo. Es también el formato en que GNP los pide.

### 4. De la impresión sólo se guardan los primeros 256 KB `[CONFIRMADO]`

La respuesta del servicio de impresión trae el PDF completo en base64. Guardarlo entero duplicaría el archivo en la base sin ganar nada: el PDF ya se guarda como archivo en `datos/pdf/`.

Se conservan los primeros 256 KB, que alcanzan para el encabezado, el estatus y el diagnóstico. El resto se descarta.

### 5. La evidencia contiene datos personales y se trata como confidencial `[CONFIRMADO]`

Los dos archivos traen nombre, edad, código postal y a veces RFC del cliente. No son archivos técnicos inocuos.

**Decisión:** se descargan sólo desde la sesión autenticada, y quien los comparte con GNP debe saber qué está mandando.

### 6. La bitácora es también el instrumento de análisis `[CONFIRMADO]`

`sys_llamadas` no existe sólo para el soporte de GNP. Es de donde salió la taxonomía de errores de [ADR-005](./ADR-005-reglas-verificadas-gnp.md): agrupar 56 llamadas por `error_origen` fue lo que reveló que `ldapService` es contraseña, `catalogos` es dato inexistente y `gateway` es respuesta demasiado grande.

Sin bitácora, esas reglas habrían tardado meses en aparecer.

## 🧩 Modelo de datos

```sql
sys_llamadas
  id              INTEGER PK
  cotizacion_id   INTEGER NULL     -- NULL en llamadas de catálogo
  servicio        TEXT             -- catalogo | cotizar | imprimir
  detalle         TEXT
  estado          TEXT
  http            INTEGER
  ms              INTEGER
  bytes           INTEGER
  error_clave     TEXT NULL
  error_origen    TEXT NULL        -- ldapService | catalogos | runtime | gateway | parser…
  error_desc      TEXT NULL
  ejecutado_en    TEXT
  xml_entrada     TEXT             -- ⚠️ contraseña YA enmascarada
  xml_salida      TEXT
```

## ✅ Beneficios

- Cualquier error reportado por un vendedor es reproducible y explicable.
- El paquete de certificación de GNP se arma copiando, no reconstruyendo.
- La taxonomía de errores salió de aquí, y seguirá creciendo con los datos.
- La contraseña nunca llega al disco, así que un respaldo de la base no la expone.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| **La bitácora crece sin límite** | Hoy son 56 llamadas. Con uso real, miles al mes, cada una con dos XML completos |
| **Contiene datos personales** | Un respaldo de la base es un archivo con datos de clientes |
| **No hay política de retención** | Nadie ha definido cuánto tiempo se conservan |
| **El enmascarado depende de una sola línea** | Si alguien agrega un camino de escritura que la salte, la contraseña entra a la base |

## 🛡️ Mitigaciones

- El corte de 256 KB en impresión ya contiene el caso que más pesaba.
- El enmascarado vive en el punto único por donde pasan todas las llamadas, en `GnpClient`, no repartido por los servicios.
- La descarga de evidencia exige sesión autenticada.

## 🧩 Consideraciones futuras

- **Política de retención**: cuánto se conserva la bitácora completa y cuándo se poda a sólo metadatos. Lo natural sería conservar los XML de las llamadas con error indefinidamente, y podar los exitosos pasado cierto tiempo.
- **Purga de datos personales** en llamadas viejas, conservando el esqueleto técnico.
- **Cifrado de la base** si el proyecto llega a un servidor compartido con datos reales de clientes.
- **Vista de administración de la bitácora** — hoy se consulta a mano contra SQLite.

## 👥 Aprobación

- Producto / Negocio: ✅
- TI / Arquitectura: ✅

## Pendiente `[PENDIENTE]`

- Definir política de retención y purga.
- Decidir qué pasa con la bitácora al desplegar a cPanel, donde la base convive con otros sistemas.

## Referencias

- [ADR-005 — Reglas verificadas contra GNP](./ADR-005-reglas-verificadas-gnp.md)
- `app/servicios/EvidenciaServicio.php` — armado y descarga de los dos JSON
- `app/core/GnpClient.php` — enmascarado y registro de cada llamada
- Correo `[CC033]` de Conectividad GNP — pide los XML de entrada y salida en `.txt` para soporte
