# ADR-010 — Todo módulo de aseguradora cumple un contrato común

## 📌 Estado

**Propuesto** _(CC, 2026-09-24)_. Pendiente de la revisión de Albert. Producto/Negocio ⏳ · TI/Arquitectura ⏳.

Implementa [ADR-009](../01_Generales/ADR-009-plataforma-de-cotizadores-por-aseguradora.md): la plataforma tiene un módulo por aseguradora. Aquí se fija **qué tiene que cumplir cada módulo** para que convivan sin estorbarse y para que el MultiCotizador futuro sea posible. Se apoya en [ADR-002](./ADR-002-stack-y-estructura.md) (stack) y [ADR-003](./ADR-003-modelo-de-datos.md) (datos).

## 🧠 Contexto

El código actual está amarrado a GNP en tres lugares (revisión del 2026-09-23):

1. **Cinco servicios llaman directo a `GnpClient`**: Cotización, Impresión, Juega y Compara, Armador libre y Plantillas.
2. **Ninguna tabla dice de qué compañía es cada dato.** Se da por hecho que todo es GNP.
3. **La cotización guarda el vehículo con las claves de GNP**: `clavemarca`, `armadora`, `carroceria`, `version`.

Llegan compañías cuyos servicios no se parecen entre sí. GNP habla XML; de HDI todavía no se sabe si es "WS Generales" o "API" (la casilla del kit no está marcada). Para que cada una tenga sus reglas **sin que la plataforma se vuelva un rompecabezas**, hace falta un acuerdo mínimo: lo que todo módulo debe cumplir, sin importar cómo sea la compañía por dentro.

Principio rector: **agregar, nunca reemplazar.** GNP funciona en producción y no se toca más que lo indispensable.

## ⚖️ Decisión

### 1. Cada compañía vive en su propia carpeta `[CONFIRMADO]`

```
app/
├── core/                 lo común (Db, Esquema, Auth, PdfBasico…) + GnpClient (se queda donde está)
├── servicios/            los servicios de GNP de hoy (se quedan donde están)
├── plataforma/           NUEVO · lo que comparten todos los módulos
│   ├── CotizadorAseguradora.php    el contrato (interfaz)
│   ├── Aseguradoras.php            el registro: qué compañías hay y en qué estado
│   └── Resultado.php               el formato común del resultado
└── aseguradoras/         NUEVO · una carpeta por compañía
    ├── Gnp/              adaptador delgado sobre lo que ya existe (no mueve nada)
    ├── Hdi/
    ├── Qualitas/
    └── Zurich/
```

**GNP no se muda.** `GnpClient` y sus servicios se quedan donde están. En `aseguradoras/Gnp/` sólo vive un adaptador que los envuelve para cumplir el contrato. Mover archivos que funcionan es riesgo sin beneficio.

### 2. Un registro dice qué compañías existen y en qué estado `[CONFIRMADO]`

Tabla nueva `sys_aseguradoras`. El menú se arma a partir de ella, no con código fijo.

| Estado | Qué significa | Quién lo ve |
|---|---|---|
| `PREPARADA` | La carpeta existe, pero no hay conexión todavía | Sólo administradores, con la leyenda "en preparación" |
| `EN_INTEGRACION` | Ya hay manual y credenciales y se está probando | Sólo administradores |
| `OPERATIVA` | Cotiza de verdad | Todos los usuarios |
| `SUSPENDIDA` | Se apagó (credencial vencida, convenio en pausa…) | Nadie cotiza; el historial sigue visible |

Así, agregar una compañía es dar de alta un registro y su carpeta. No hay que tocar el menú ni las demás compañías.

### 3. El contrato: todo cliente de aseguradora responde a los mismos "botones" `[CONFIRMADO]`

Por dentro, cada cliente habla como pida su compañía (XML, SOAP, JSON…). Por fuera, todos ofrecen lo mismo:

| Botón | Qué hace |
|---|---|
| `clave()` | `GNP`, `HDI`, `QUALITAS`, `ZURICH` |
| `cotizar(solicitud)` | Recibe la solicitud en formato común y devuelve un resultado en formato común |
| `imprimir(cotización, paquete)` | Trae el PDF oficial de la compañía, si lo ofrece |
| `catalogo(tipo, filtros)` | Descarga un catálogo de la compañía |

Y todos contestan con **las mismas categorías de estado**, que ya usa `GnpClient` y se generalizan:

`OK` · `AUTH` (credenciales rechazadas) · `DATOS` (mandamos algo mal) · `SISTEMA` (falla de la compañía) · `TIMEOUT` · `RED`

Por qué importa: el resto de la plataforma (historial, evidencia, mensajes al usuario) funciona igual con cualquier compañía sin saber cómo habla cada una.

### 4. Candado de emisión en cada cliente `[CONFIRMADO]`

Cada cliente declara su lista de rutas prohibidas (emisión, cobro, cancelación), como ya hace `GnpClient` con `PROHIBIDAS`, y la valida antes de cada llamada. Es condición para que un módulo pase a `EN_INTEGRACION`. Se apoya en ADR-009, punto 5.

### 5. Las tablas comunes llevan la columna `aseguradora` `[PENDIENTE]`

`cot_cotizaciones`, `cot_resultados`, `cot_documentos` y `sys_llamadas` reciben:

```sql
aseguradora TEXT NOT NULL DEFAULT 'GNP'
```

Se agrega con el mecanismo que ya usa `Esquema::migrar()`: columna nueva con valor por omisión. **Todo lo existente queda marcado como GNP sin que nadie lo toque**, y el flujo actual no cambia. Pendiente hasta aplicarlo y comprobar GNP de punta a punta.

### 6. Los catálogos son propios de cada compañía `[CONFIRMADO]`

Cada compañía estructura distinto sus catálogos. Ejemplos: HDI tiene tres niveles de cobertura (Obligatoria, Obligatoria opcional y Opcional) y sumas aseguradas por rango y escalón; GNP tiene dos niveles y una lista cerrada de valores. Forzarlas a una sola tabla deformaría a todas.

- **Las compañías nuevas** usan su propio prefijo: `cat_hdi_*`, `cat_qua_*`, `cat_zur_*`.
- **Las tablas `cat_*` existentes son de GNP.** Se quedan con su nombre para no romper nada, y así queda anotado: el prefijo `cat_` sin compañía significa GNP, por historia.

### 7. El resultado se guarda igual para todas `[CONFIRMADO]`

Es la convención más importante para el MultiCotizador futuro. Venga como venga la respuesta, se guarda en `cot_resultados` y `cot_resultado_coberturas`:

- **Precio = total a pagar, con derechos e IVA.** Es la regla de ADR-005 generalizada. Nunca la prima neta, en ninguna compañía.
- Prima neta, derechos, IVA, descuento y número de pagos, en sus columnas. Lo que no tenga lugar va a `conceptos_json`.
- Coberturas: nombre, suma asegurada y deducible, como texto legible. La clave de cobertura es **la de la compañía**.

### 8. Los datos del solicitante usan los mismos nombres `[CONFIRMADO]`

Tipo de persona, edad, CP, sexo y fecha de nacimiento del conductor, y los datos del contratante, con los nombres que ya tiene `cot_cotizaciones`. Lo que una compañía pida de más (uso del vehículo, por ejemplo, para la Pick-up Comercial de HDI) va en una columna nueva `datos_aseguradora_json` `[PENDIENTE]`.

Por qué: el día que exista un formulario único, sólo tiene que saber llenar estos campos una vez.

### 9. El vehículo: la clave de la compañía siempre, la del catálogo maestro cuando se sepa `[PENDIENTE]`

`cot_cotizaciones` recibe dos columnas nuevas:

- `clave_vehiculo`: la clave del vehículo **según la compañía**, obligatoria para las compañías nuevas. Las columnas de GNP (`armadora`, `carroceria`, `version`, `clavemarca`) se quedan para GNP.
- `submarca_id`: el ID del catálogo maestro ([ADR-004](../03_Decisiones/ADR-004-catalogo-maestro-propio.md)), **puede quedar vacía**. No bloquea nada hoy y mañana es el puente para decir "este es el mismo coche".

Homologar el catálogo de cada compañía contra el maestro es trabajo deseable, **no requisito** para que el módulo opere.

### 10. Configuración separada por compañía `[CONFIRMADO]`

En `config/.env.local`, cada compañía con su prefijo: `GNP_*` (ya existe), `HDI_*`, `QUALITAS_*`, `ZURICH_*`. Una credencial vencida sólo apaga su módulo.

### 11. Documentación por compañía `[CONFIRMADO]`

`docs/aseguradoras/<compañía>/` guarda su estado, lo que falta pedir y sus reglas verificadas contra su servicio real. Es el equivalente de ADR-005 para cada una. La lista de lo que se pide a toda compañía nueva está en [`docs/aseguradoras/00-kit-minimo-por-aseguradora.md`](../aseguradoras/00-kit-minimo-por-aseguradora.md).

### 12. Cuándo un módulo pasa a `OPERATIVA` `[CONFIRMADO]`

Tiene que cumplir todo esto:

- [ ] Cumple el contrato (punto 3) y tiene el candado de emisión (punto 4)
- [ ] Guarda el resultado en el formato común (punto 7)
- [ ] Deja evidencia de cada llamada en `sys_llamadas`, con la contraseña enmascarada ([ADR-006](../03_Decisiones/ADR-006-evidencia-y-bitacora.md))
- [ ] Tiene sus reglas verificadas documentadas en `docs/aseguradoras/<compañía>/`
- [ ] Las cotizaciones de prueba comparadas contra el portal oficial de la compañía dan el mismo precio
- [ ] GNP sigue funcionando igual (pruebas de regresión)

## 🧩 Modelo de datos

```
sys_aseguradoras  (NUEVA)
    clave TEXT PK · nombre · estado · orden · creada_en

cot_cotizaciones   + aseguradora DEFAULT 'GNP' · + clave_vehiculo · + submarca_id NULL · + datos_aseguradora_json
cot_resultados     + aseguradora DEFAULT 'GNP'
cot_documentos     + aseguradora DEFAULT 'GNP'
sys_llamadas       + aseguradora DEFAULT 'GNP'

cat_*              ← GNP (sin cambio de nombre)
cat_hdi_*          ← HDI (paquetes, coberturas, valores, catálogos)
cat_qua_* · cat_zur_*  ← cuando lleguen
```

Nota sobre `sys_llamadas`: las columnas se llaman `xml_entrada` y `xml_salida` por historia. Guardan el cuerpo de ida y vuelta **en el formato que use la compañía** (XML o JSON). No se renombran para no tocar la evidencia existente.

## ✅ Beneficios

- **Agregar una compañía es aditivo**: un registro, una carpeta y sus tablas con prefijo. No se toca a las demás.
- **GNP no se mueve.** El riesgo de romperlo queda limitado a la migración de columnas, que se hace con un mecanismo ya probado tres veces en este proyecto.
- **La plataforma se construye sin esperar documentación**: registro, contrato, columnas y menú no dependen de ninguna compañía.
- **El MultiCotizador futuro encuentra la mitad del camino hecho**: resultado común, solicitante común y puente al catálogo maestro.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| **Contrato diseñado con pocos ejemplos** | Hoy se conoce a fondo sólo GNP. HDI aún no tiene manual. El contrato podría no encajar con una compañía que se comporte muy distinto |
| **Migración sobre la base en uso** | Agregar columnas a `cot_*` toca la base que ya tiene cotizaciones reales |
| **Dos convenciones de nombre conviviendo** | `cat_*` (GNP, por historia) y `cat_hdi_*` (nuevas) pueden confundir a alguien nuevo |
| **Pruebas contra producción** | Las pruebas de regresión de GNP (`app/scripts/prueba_*.php`) cotizan en producción. No generan póliza, pero son llamadas reales |

## 🛡️ Mitigaciones

- **Contrato mínimo a propósito:** cuatro botones y seis estados. Se ajusta cuando llegue el manual de HDI, antes de programar su cliente; por eso este ADR está en *Propuesto*. Lo que no encaje se resuelve dentro del módulo, no agrandando el contrato.
- **Migración:** respaldo fechado de `cotizador_gnp.sqlite` antes de aplicarla (mismo patrón `.bak_pre_*` de siempre) y marca de versión en git.
- **Nombres:** esta convención queda escrita aquí y en [ADR-003](./ADR-003-modelo-de-datos.md).
- **Regresión:** se corren sólo las pruebas mínimas necesarias, registradas en la bitácora como cualquier llamada.

## 🧩 Consideraciones futuras

- Mover GNP físicamente a `aseguradoras/Gnp/` cuando haya una razón concreta (por ejemplo, cuando se toque a fondo), no antes.
- Consultar varias compañías en paralelo (`curl_multi`), cuando llegue el MultiCotizador.
- Catálogo maestro de coberturas, para el comparativo entre compañías.
- Permisos de usuario por compañía.

## 👥 Aprobación

- Producto / Negocio: ⏳
- TI / Arquitectura: ⏳

## Pendiente `[PENDIENTE]`

- Revisión de Albert.
- Validar el contrato (punto 3) contra el manual técnico de HDI en cuanto llegue.
- Aplicar las migraciones de los puntos 5, 8 y 9 y comprobar GNP de punta a punta.
- Anotar en [ADR-003](./ADR-003-modelo-de-datos.md) la convención de prefijos del punto 6.

## Referencias

- [ADR-009 — Plataforma de cotizadores por aseguradora](../01_Generales/ADR-009-plataforma-de-cotizadores-por-aseguradora.md)
- [ADR-003 — Modelo de datos](./ADR-003-modelo-de-datos.md)
- [ADR-005 — Reglas verificadas contra GNP](../03_Decisiones/ADR-005-reglas-verificadas-gnp.md)
- [ADR-006 — Evidencia y bitácora](../03_Decisiones/ADR-006-evidencia-y-bitacora.md)
- `app/core/GnpClient.php`: estados y lista `PROHIBIDAS` que se generalizan
- `app/core/Esquema.php`: `migrar()`, mecanismo de columnas nuevas
