# Qualitas — estado de la integración

Documento operativo. Última revisión: _(Claude, 2026-09-28)_.

**Estado en la plataforma:** `EN_INTEGRACION` desde el 2026-09-28 (decisión de Albert). Hay cliente con candado de emisión doble y una cotización real en QA que cuadra con el PDF de ejemplo (`sys_llamadas.id` 123). El cambio lo hace una migración idempotente en `Esquema::migrar()`, que sólo mueve a Qualitas desde `PREPARADA`. Se probó contra una copia de la base y después contra la real (respaldo `datos/cotizador_gnp.sqlite.bak_pre_en_integracion_20260928_110452`): sólo cambió la fila de Qualitas, y GNP quedó igual (40 cotizaciones, 118 llamadas).

Sigue faltando el usuario del catálogo (`cUsuario`/`cTarifa`) y la confirmación de que el negocio 08902 está dado de alta en producción. Pasa a `OPERATIVA` sólo con la lista del punto 12 de ADR-010 completa.

_Antes (hasta el 2026-09-28):_ `PREPARADA`. Pasaba a `EN_INTEGRACION` cuando existiera el cliente con su candado de emisión (ADR-010, punto 4) y se hubiera hecho la primera llamada a QA con respuesta real.

## Lo que se recibió

Carpeta `Proyectos\Qualitas_Cotizador\`.

| Archivo | Qué es | Sirve para |
|---|---|---|
| `DocumentaciónServiciosWeb.pdf` (v3.0, jun-2021) | Manual general del servicio de Cotización/Emisión (`WsEmision.asmx`): URL de pruebas y producción, métodos, WSDL (imagen) y plantilla XML | Kit #1, #2, #6 |
| `AnalisisDeEsquemaDeSistemasUsuarios.pdf` (v2.0, jun-2021) | Diccionario del XML: cada campo, "consideraciones adicionales", dígito verificador AMIS y anexos (estados, servicio, uso, coberturas, paquetes, tipo de suma) | Kit #1, #7, #9 |
| `WSTARIFAS.pdf` (v1.0.0.1, oct-2013) | Servicio de catálogo de vehículos (`wsTarifa.asmx`): `listaMarcas` y `listaTarifas` | Kit #5 |
| `WSIMPRESION.pdf` (v2.0, jul-2017) | Servicio de impresión (`QBCImpresion/Service.asmx`) | Kit #8 — **sólo imprime pólizas**, ver abajo |
| `CatalogoErroresSW.xlsx` | ≈300 códigos de error del servicio de emisión | Kit #4 |
| `RESUMEN CONSIDERACIONES IDENTIFICACIÓN DEL CLIENTE SERVICIO WEB.pdf` | Datos de identificación del cliente (Art. 492): nombre, RFC, CURP, ocupación, nacionalidad… | **Sólo emisión.** No aplica a cotizar |
| `XMLDoc_EjemploCamposEmision_CP.xml` | Plantilla XML de **emisión** (`TipoMovimiento="3"`) | Referencia de estructura. No se usa tal cual |
| `SW_EQUINOX_08902_0008810_JASM.xls` | **Formulario de alta del negocio** (condiciones comerciales). Protegido sólo contra escritura: abre normal en Excel | Condiciones del negocio 08902 |
| `Ejemplos Qualitas\` — 3 pares XML + PDF | Cotizaciones de ejemplo hechas por Qualitas el 23-sep-2026 (CP 11590): Chevrolet Captiva 2026, Nissan NP300 2026 (carga), Vento Tornado 300 2026 (moto) | Referencia de precio para la prueba de igualdad (ADR-010, punto 12) |

### Datos del negocio (formulario de alta)

| Dato | Valor | Nota |
|---|---|---|
| Negocio (`NoNegocio`) | `08902` | "Fecha de revisión de condiciones: 30/09/26". Las columnas que llena Qualitas (SIO) están vacías: **no hay constancia de que ya esté activo** |
| Agente | `08810` (en los XML: `0008810`) | |
| Tipo | Servicio Web · AG – Cartera de agente | |
| Subramos | Autos, Pickups, Camiones, Motos, Equipo pesado · nuevos y usados | |
| Uso / Servicio | 1-Normal / 1-Particular | Los ejemplos también usan Uso 6 (Carga) para la pick-up |
| Paquetes autorizados | Amplia, Limitada, Básica | "Básica" no tiene código en el Anexo 5 del manual `[PENDIENTE]` |
| Deducibles | DM 3, 5, 10 · RT 5, 10, 20 · RC 0 | |
| Tipo de suma | Valor convenido / comercial. "Valor factura primer año, subsecuentes valor comercial" | |
| Sumas aseguradas | "Abierto" | El manual dice que SISE las limita y que moverlas requiere autorización comercial |
| Formas de pago | C, S, T, M (contado, semestral, trimestral, mensual) | |
| Descuento (bonificación técnica) | 55 autos y pick-up · 30 camiones · 20 motos | **Lo manda Equinox en cada petición** (`PorcentajeDescuento`). Ver diferencias |
| Derecho de póliza | 750 | |
| Pronto pago | Sí | Los ejemplos mandan consideración 05 = 14 días |
| Tarifa | "Actualizable" con la de línea | En el XML: `LINEA` en valores, cuotas y derechos |
| Contactos de sistemas | **Vacío** | Ejecutivo solicitante: Laura Salgado (Polanco) |

## Inventario contra el kit mínimo

| # | Qué | Estado | Detalle |
|---|---|---|---|
| 1 | Manual técnico | ✅ Llegó, con huecos | Hay estructura de petición completa. **No hay ningún ejemplo de respuesta**, y el WSDL sólo viene como imagen |
| 2 | Tipo de servicio | ✅ | SOAP/XML ("WS"), ASP.NET (`.asmx`). Método `obtenerNuevaEmision` |
| 3 | Credenciales | ⚠️ Parcial — **bloqueante** | Hay `NoNegocio` 08902 y agente 0008810. El servicio de emisión no documenta usuario/contraseña (¿validan por IP?). El de catálogo **exige `cUsuario` y `cTarifa`, que no llegaron** |
| 4 | Catálogo de errores | ✅ | `CatalogoErroresSW.xlsx` + tablas de errores de Tarifas e Impresión |
| 5 | Catálogo de vehículos | ✅ documentado · ⚠️ sin acceso | `wsTarifa.asmx`, clave **AMIS** (5 dígitos). Sin `cUsuario`/`cTarifa` no se puede descargar. Sólo trae URL de producción (y en `http`) |
| 6 | Ambiente de pruebas | ✅ | `https://qa.qualitas.com.mx:8443/WsEmision/WsEmision.asmx`. Además, la consideración 04 marca pruebas (1) o producción (0) |
| 7 | Matriz de paquetes y coberturas | ✅ Parcial | Anexo 5 (qué cobertura es obligatoria/opcional por paquete) + condiciones del negocio. Falta el código de "Básica" y los rangos de suma permitidos |
| 8 | Impresión | ❌ Para cotización | `WSIMPRESION` pide **número de póliza** (`nPoliza`), no de cotización. Se entrega el PDF propio (PdfBasico) |
| 9 | Catálogo de CP / estados | ✅ | Anexo 1 (32 estados, mismo orden que INEGI) + SEPOMEX para municipio/colonia (esto último, sólo emisión) |
| 10 | Vigencia de la cotización | ✅ (por los PDF) | "La presente cotización tiene una vigencia de **7 días**". Sale de los PDF de ejemplo, no del manual |
| 11 | Contacto técnico | ❌ | El formulario lo deja en blanco |

**Veredicto:** no es el caso de HDI. Aquí sí hay manual y ambiente de pruebas. Lo que falta es acceso y confirmación, no documentación de fondo.

## Encaje con el contrato de ADR-010

**Cabe sin agrandar el contrato.** Los cuatro botones y los seis estados alcanzan. Todo lo distinto se resuelve dentro de `app/aseguradoras/Qualitas/`.

| Punto | Qualitas | Dónde se resuelve |
|---|---|---|
| `cotizar()` | Un `Movimiento` = un paquete. Para N paquetes, N llamadas (o N movimientos en un envío, si el servicio lo acepta `[PENDIENTE]`). GNP resolvía N paquetes en una llamada (ADR-005, punto 8) | Dentro del módulo: arma N peticiones y devuelve N `Resultado` |
| `imprimir()` | No hay PDF oficial de cotización | Dentro del módulo: devuelve el PDF propio. El contrato ya dice "si la compañía lo ofrece" |
| `catalogo()` | Servicio aparte (`wsTarifa`), con su propio usuario | Dentro del módulo |
| Estados | Error numérico en `<CodigoError>`; vacío = éxito | Dentro del módulo: tabla código → `AUTH`/`DATOS`/`SISTEMA` (abajo) |
| **Candado de emisión (punto 4)** | **Cotizar y emitir usan el mismo método** (`obtenerNuevaEmision`). Lo que cambia es `TipoMovimiento` (2 cotiza, 3 emite, 4 endosa) dentro del XML. `CandadoEmision::validarRuta()` revisa la ruta y **aquí no protege nada**: la ruta es igual para las dos | Dentro del módulo: un segundo candado que revisa **el contenido** antes de enviar (`TipoMovimiento` = 2, `NoPoliza` vacío, método en lista permitida). No se toca el trait compartido |
| Resultado (punto 7) | Devuelve `PrimaNeta`, `Derecho`, `Recargo`, `Impuesto`, `PrimaTotal`, `Comision` | `Recargo`, `Comision` y el descuento por pronto pago van a `conceptos_json` |
| Solicitante (punto 8) | Tarifica con **Estado + CP**. No pide edad ni sexo en ningún documento | Los campos comunes alcanzan; edad/sexo simplemente no se mandan |
| Vehículo (punto 9) | Clave AMIS + modelo + dígito verificador | `clave_vehiculo` = AMIS. El dígito se calcula en el módulo |
| Datos extra | Uso, servicio, tipo de carga, consideraciones | `datos_aseguradora_json` |

Riesgo que sí vale la pena anotar en ADR-010: **el candado por ruta no sirve para compañías que cotizan y emiten por el mismo método.** Qualitas es el primer caso. Si Zurich o HDI resultan iguales, conviene subir el candado por contenido a `app/plataforma/` (agregando, sin cambiar el de ruta).

### Clasificación de errores propuesta `[PENDIENTE]` hasta verlos llegar

| Categoría | Códigos | Por qué |
|---|---|---|
| `AUTH` | 2, 3, 4, 5, 26, 36, 59, 63, 200, 207, 310 · Tarifas: 3, 4 | Negocio, agente, tarifa o permiso. No se arregla corrigiendo la captura: avisar a administración |
| `SISTEMA` | 100, 172, 231, 316, 340 | Mantenimiento, bloqueo o falla interna de Qualitas |
| `DATOS` | El resto | Algo de la captura (AMIS, CP, deducible, suma…) |

El catálogo repite códigos con textos distintos (7, 126, 233…). Igual que con GNP (ADR-005, punto 6): **el mensaje se muestra tal cual y la categoría se decide por el código, nunca por el texto.**

## Diferencias de negocio frente a GNP

Lo que el módulo de Qualitas tiene que respetar (ADR-010, punto 6):

| Tema | GNP | Qualitas |
|---|---|---|
| Paquetes | Varios por llamada; claves `PRS…` | Uno por movimiento. 01 Amplia, 02 Plus, 03 Limitada, 04 RC. Negocio: Amplia, Limitada, Básica |
| Coberturas | Dos niveles | Cuatro marcas por paquete: **S** requerida · **N** no aplica · **AD** incluida pero se puede quitar · **O** opcional |
| Sumas | Lista cerrada | "Abierta", pero la limita SISE. En los ejemplos DM y RT se mandan en **0** y Qualitas pone el valor (468,000 para la Captiva) `[PENDIENTE: de dónde sale]` |
| Tipo de suma | — | 0 convenido · 1 factura · 3 comercial, sólo para DM y RT. RC Complementaria (47) usa tipo **14**, que no está en el anexo |
| Deducibles | Por cobertura, lista | DM 3/5/10 · RT 5/10/20 · RC 0 UMA |
| Vehículo | Marca/armadora/carrocería/versión | **Clave AMIS** + modelo + **dígito verificador** (módulo 10) en la consideración 01 |
| Quién tarifica | Edad y CP del conductor | **Estado + CP**. Estado y CP tienen que corresponder (error 202) |
| Segmento | Procedencia (sólo Residentes verificada) | **Uso** (01 normal, 06 carga…) + **servicio** (01 particular) + tipo de vehículo del AMIS |
| Descuento | Viene aplicado por GNP | **Lo manda Equinox** en `PorcentajeDescuento`: tope 55 autos/pick-up, 30 camiones, 20 motos. Es decisión de negocio |
| Derecho de póliza | Lo devuelve GNP (680 observado) | **Lo manda Equinox**: 750 |
| Precio | `TOTAL_PAGAR` | `PrimaTotal` / "Importe total" (ver aritmética) |
| Formas de pago | — | El PDF muestra contado, semestral y trimestral en la misma cotización |
| Vigencia de la cotización | 15 días | **7 días** |
| PDF | Servicio de impresión (y lo manda por correo) | No hay para cotización: PDF propio |
| Comisión | No viene | **Viene en la respuesta** (`Comision`). Decidir si el usuario la ve |
| Transporte | https | Producción de emisión y de tarifas en **http** (sin cifrar). QA sí es https |

### Aritmética de los PDF de ejemplo `[PENDIENTE]` — cuadra en papel, falta verla en el servicio

```
PRIMA NETA − PRONTO PAGO (2%) + GASTOS EXP.  = SUBTOTAL   ;  SUBTOTAL × 16%  = IVA      ;  TOTAL
 8,440.28  −    168.81       +   750.00      = 9,021.47   ;                  = 1,443.44 ;  10,464.91  (Captiva, 55%)
14,710.43  −    294.21       +   750.00      = 15,166.22  ;                  = 2,426.60 ;  17,592.82  (NP300, 55%)
 6,234.08  −    124.68       +   750.00      = 6,859.40   ;                  = 1,097.50 ;   7,956.90  (Vento, 20%)
```

- La "TASA FIN. P.F." es exactamente **−2% de la prima neta** en los tres casos: descuento por pronto pago (consideración 05). Se deduce, no está documentado.
- Las primas por cobertura del PDF **no suman la prima neta** (Captiva: suman 17,844.35 contra 8,440.28). El descuento se aplica en algún punto que el documento no explica. No se usa la suma de coberturas como precio.
- Los XML de ejemplo no son la petición exacta de esos PDF (sus fechas son 29-ago/30-sep y los PDF dicen 23-sep). Sirven como modelo, no como par byte a byte.

## Lo que falta pedir a Qualitas (bloqueante)

- [ ] Confirmar que el negocio **08902** con agente **0008810** ya está habilitado para servicio web, en QA y en producción
- [ ] Cómo se autentica `WsEmision`: ¿sólo negocio + agente? ¿Registro de IP?
- [ ] **Usuario (`cUsuario`) y clave de tarifa (`cTarifa`)** para `wsTarifa` (catálogo de vehículos), y si existe URL de pruebas
- [ ] **Un ejemplo de respuesta real** de cotización (`TipoMovimiento` 2), exitosa y con error
- [ ] Contacto técnico

## Lo que hay que aclarar con Qualitas

- [ ] Código del paquete "Básica" (¿04 RC?)
- [ ] ¿Un envío acepta varios `Movimiento` (varios paquetes)?
- [ ] ¿La respuesta trae desglose por forma de pago (C/S/T/M) o hay que cotizar una por una?
- [ ] ¿`PrimaTotal` ya incluye pronto pago, derechos e IVA? ¿El pronto pago llega en `Recargo`?
- [ ] ¿Cómo se calcula la suma de DM/RT cuando se manda 0, y qué tipo de suma aplica?
- [ ] ¿Las primas por cobertura de la respuesta vienen antes o después del descuento?
- [ ] Consideración 04: ¿con la URL de QA se manda 1 y con producción 0? ¿Qué pasa si no se manda?
- [ ] ¿Hay URL **https** de producción para `WsEmision` y `wsTarifa`?
- [ ] ¿Existe impresión de **cotización** (por número de cotización)?
- [ ] Versión vigente de los manuales (son de 2013 a 2021)
- [ ] ¿Cómo se cede o ajusta la comisión por servicio web (error 179)? ¿El descuento (`PorcentajeDescuento`) sale de la comisión del agente?
- [ ] ¿Qué dato del catálogo (`cCategoria`, marca…) distingue auto, pick-up, camión y moto para aplicar el tope de descuento?
- [ ] `obtenerNuevaEmisionDXN` aparece en el WSDL y no está documentado: ¿qué es?

## Decisiones ya tomadas

- **Sólo cotización** (ADR-009, punto 5). En Qualitas eso significa **sólo `TipoMovimiento="2"`**. El 3 (emisión) y el 4 (endoso) quedan bloqueados en el cliente.
- Las consideraciones de identificación del Art. 492 y la plantilla de emisión no se implementan.

## Decisiones de negocio (Albert, 2026-09-25)

1. **Descuento capturado por el usuario.** La pantalla tiene un campo de porcentaje; lo que el usuario escribe es lo que se manda en `PorcentajeDescuento`.
   - **Rango mínimo y máximo configurable** por aseguradora y tipo de vehículo, en una tabla común de la plataforma (`sys_descuentos`) que el administrador edita desde pantalla. Sirve para cualquier compañía que reciba el descuento en la petición. Valores iniciales de Qualitas: mínimo 0; máximo 55 autos/pick-up, 30 camiones, 20 motos.
   - El usuario no puede salir del rango: se valida en pantalla y en servidor. Por qué: Qualitas rechaza fuera de rango (error 7) y conviene que el usuario lo vea antes de gastar una llamada.
   - Mientras no se sepa qué dato del catálogo dice si es auto, camión o moto `[PENDIENTE]`, se usa la fila "TODOS" de la aseguradora (0–55) y el rechazo de Qualitas cae en `DATOS` con su mensaje.
   - GNP no recibe descuento en la petición: no se le conecta.
   - El porcentaje usado se guarda en `datos_aseguradora_json` de la cotización, para que el historial explique el precio.
2. **La comisión se muestra al usuario.** "Bajo su control": hoy el único control documentado es el **descuento**. No hay campo documentado para ceder o ajustar comisión; el error 179 ("La cesión de comisiones es mayor a la comisión") indica que el mecanismo existe, pero no cómo se manda `[PENDIENTE — preguntar a Qualitas]`. Tampoco está documentado si el descuento reduce la comisión `[PENDIENTE]`. El módulo muestra la comisión que devuelva Qualitas junto al precio y no la inventa ni la calcula.
   - _(Albert, 2026-09-28)_ `Primas/Comision` es el **porcentaje** (autos 11, motos 11, pick-up 8.8, camiones 8.8; camiones aún sin ver en el servicio) y `Recibos/Comision` el **importe**. Se muestran los dos, tal como lleguen, y lo que falte dice "no disponible". Detalle en "Reglas verificadas", punto 7.
3. **Pronto pago se aplica**: consideración 05 con 14 días (máximo permitido, error 192). Valor desde configuración.

## Avance del módulo

### Etapa 1 — cliente sin red _(Claude, 2026-09-28)_

Nada de esto ha hablado con Qualitas. Todo lo que diga de la respuesta es `[PENDIENTE]`.

| Archivo | Qué hace |
|---|---|
| `app/aseguradoras/Qualitas/QualitasXml.php` | Arma el XML de cotización (`TipoMovimiento="2"` fijo) y calcula el dígito AMIS |
| `app/aseguradoras/Qualitas/QualitasClient.php` | SOAP 1.1 contra `WsEmision.asmx` y `wsTarifa.asmx`, candado doble, clasificación de estados, evidencia en `sys_llamadas` con `aseguradora='QUALITAS'` |
| `app/aseguradoras/Qualitas/pruebas/prueba_sin_red.php` | 65 pruebas sin red: ningún cliente usa cURL, todos reciben un transporte falso |
| `app/aseguradoras/Qualitas/pruebas/ejemplos/` | Copia de los 3 XML de "Ejemplos Qualitas" |
| `app/aseguradoras/Qualitas/pruebas/SIMULADO_*.xml` | Respuestas **inventadas** para probar el parseo. No son de Qualitas |
| `config/.env.local` · `config/.env.example` | Llaves `QUALITAS_*`. URL de producción, URL y namespace de `wsTarifa`, y usuario/tarifa de catálogo **vacíos** |

**Candado doble.** Antes de cada envío: (1) método en lista permitida (`obtenerNuevaEmision`, `Test`, `HolamundoAux`, `listaMarcas`, `listaTarifas`); `EnviaMail` y `obtenerNuevaEmisionDXN` bloqueados; (2) `CandadoEmision::validarRuta()`; (3) candado por contenido: `TipoMovimiento` exactamente `"2"` en atributo o elemento, en todos los movimientos; `NoPoliza`, `NoEndoso` y `TipoEndoso` vacíos; consideración 04 presente e igual al ambiente; sin `DOCTYPE`/`ENTITY`; (4) se vuelve a revisar el parámetro dentro del sobre SOAP ya armado. La prueba comprueba que 16 variantes (entre ellas `TipoMovimiento` 3 y 4) lanzan `BLOQUEADO` y **no llegan al transporte**.

**Comprobado sin red:** el XML generado para la Captiva, el Vento y la NP300 es igual en estructura y valores a los ejemplos (la Captiva, además, byte a byte, salvo saltos de línea). Dígito AMIS: 22374→4, 21191→8, 68133→9, 11333→5.

**Supuestos del cliente** (los tres primeros ya se resolvieron en la Etapa 2, ver "Reglas verificadas"):

- ~~Nombre del parámetro~~ → **`xmlEmision`** `[CONFIRMADO]` (sys_llamadas.id 122). `QUALITAS_WS_PARAMETRO=xmlEmision`.
- ~~`SOAPAction`~~ → `http://qualitas.com.mx/obtenerNuevaEmision` `[CONFIRMADO]` (id 122, 123).
- ~~Forma de la respuesta~~ → XML escapado como texto `[CONFIRMADO]` (id 123). El cliente sigue aceptando las dos formas.
- ~~Formato de `<CodigoError>`~~ → código a 4 dígitos con ceros, `--` y el texto `[CONFIRMADO]` (id 126). El parseo lo lee bien (clave 7) y no se cambió. Sin número al inicio → `SISTEMA`, con el texto tal cual.
- **Namespace de `wsTarifa`** (`QUALITAS_TARIFAS_NS`), no documentado. El parámetro `cCategoría` viene con acento en el manual; se manda `cCategoria`.
- **`TipoRegla`** se manda `0` como en los ejemplos (el manual dice "vacío").
- **Consideración 39** (`blindado|asistencia vial plus`): por omisión `N|S`, como los tres ejemplos.
- **Cobertura 31** (daños por la carga): el ejemplo de la NP300 manda `A|DESCRIPCION`, que es la plantilla del manual sin llenar. El módulo la arma con el tipo y la descripción capturados.

### Etapa 3 — catálogo de vehículos: se salta _(Albert, 2026-09-28)_

Todavía no hay `cUsuario`/`cTarifa`. Mientras tanto, la captura pide la **clave AMIS y el modelo a mano**, con una leyenda de que el catálogo está pendiente. `catalogo()` responde `AUTH` con el mensaje "Catálogo sin credenciales configuradas" (no es un rechazo de Qualitas: no hubo llamada). Si el candado para la petición, responde `DATOS`.

### Etapa 4 — adaptador, resultado común y descuento configurable _(Claude, 2026-09-28)_

| Archivo | Qué hace |
|---|---|
| `app/aseguradoras/Qualitas/AseguradoraQualitas.php` | Implementa `CotizadorAseguradora`. Un movimiento por paquete → N `Resultado`. Registrado en `Aseguradoras::cliente()` |
| `app/aseguradoras/Qualitas/QualitasServicio.php` | Guarda en `cot_cotizaciones`, `cot_resultados` y `cot_resultado_coberturas` con `aseguradora='QUALITAS'`. Otras formas de pago, a pedido |
| `app/plataforma/RangoDescuento.php` | Rango de descuento, común a la plataforma |
| `app/vistas/descuentos.php` + rutas `descuentos` y `descuentos/guardar` en `public/index.php` | Pantalla de administración del rango (sólo administradores) |
| `app/core/Esquema.php` | Tablas nuevas `sys_descuentos`, `sys_descuentos_cambios`, `cat_qua_paquetes` y `cat_qua_coberturas` (sólo agregar; la semilla corre una vez, al crearlas) |
| `app/aseguradoras/Qualitas/pruebas/prueba_etapa4_sin_red.php` | 64 pruebas sin red, en una base temporal, usando la **respuesta real** de la Captiva (id 123) |

**Cómo arma el resultado:**

- **Precio** = `PrimaTotal` (id 123). Prima neta, derechos (`Derecho`) e IVA (`Impuesto`) van en sus columnas.
- **`conceptos_json`** guarda:
  - `recargo` (pronto pago en negativo) y `pronto_pago_dias`;
  - `porcentaje_descuento`, `forma_pago` y `no_cotizacion`;
  - `comision_porcentaje` (`Primas/Comision`) y `comision_importe` (`Recibos/Comision` cuando hay un solo recibo; con varios, cada uno queda en `recibos` sin sumarse). Lo que no llega queda `null`, que en pantalla será "no disponible";
  - las primas y los recibos crudos, y el id de la llamada.
- **Coberturas:** nombre del catálogo, suma y deducible **de la respuesta**, en texto legible ("$468,000", "5%", "0 UMA").

**Descuento:**

- `sys_descuentos` sembrada una sola vez con Qualitas: Todos 0–55, autos 0–55, pick-up 0–55, camiones 0–30 y motos 0–20. Cada cambio queda en `sys_descuentos_cambios` con quién, cuándo, antes y después.
- `RangoDescuento` busca primero aseguradora + tipo, luego aseguradora + Todos. Si no hay ninguna fila, el rango es 0–0.
- Qualitas usa la fila Todos mientras no se confirme qué dato del catálogo dice el tipo de vehículo.
- El porcentaje se valida en el servidor en dos lugares, `QualitasServicio` y el adaptador, antes de guardar o enviar nada. La validación en pantalla llega con la Etapa 5.
- GNP no se conecta a esto y no aparece en la pantalla de administración.

**Paquetes (`cat_qua_paquetes`):**

| Paquete | `<Paquete>` | Estado |
|---|---|---|
| Amplia | `1` | Cotizada en QA (id 123) |
| Limitada | `3` | Habilitada; nunca se ha cotizado `[PENDIENTE]` |
| Básica | sin código | Deshabilitada: "todavía no" |

Las coberturas (`cat_qua_coberturas`) salen del Anexo 5 (S/N/AD/O) y del juego del ejemplo (1, 3, 4, 5, 6, 7, 14, 47). Limitada no manda DM (Anexo 5: N). Deducibles elegibles: DM 3/5/10 y RT 5/10/20; cualquier otro valor se rechaza sin llamar.

**Formas de pago:** por omisión sólo contado. `QualitasServicio::otraFormaDePago()` cotiza semestral, trimestral o mensual del mismo paquete **sólo cuando el usuario lo pide**. Cada forma es su propia llamada registrada, y el resultado se guarda en `conceptos_json.formas_pago`; el precio de contado no cambia. El botón en pantalla es de la Etapa 5.

**`imprimir()`:** PDF propio con `PdfBasico`, que aclara que no lo emite Qualitas. Vigencia de 7 días `[PENDIENTE]`.

**Aplicado a la base real** el 2026-09-28, antes probado contra una copia. Respaldo: `datos/cotizador_gnp.sqlite.bak_pre_qualitas_etapa4_20260928_111345`. Resultado: 4 tablas nuevas; cotizaciones, resultados, llamadas, usuarios y tablas `cat_*` de GNP sin cambio; segunda corrida sin cambios.

### Etapa 5 — pantallas y regresión _(Claude, 2026-09-28)_

**Pantallas** (rutas en `public/index.php`, vistas en `app/vistas/`):

| Ruta | Qué es | Quién |
|---|---|---|
| `?r=qualitas` | Captura: clave AMIS y modelo **a mano** con la leyenda "Catálogo de vehículos pendiente"; CP, estado (Anexo 1), uso (normal/carga, con tipo y descripción de carga), paquetes (Básica deshabilitada, "todavía no"), deducibles DM/RT, descuento con el **rango visible** y validado en pantalla y en servidor | Admin mientras no esté `OPERATIVA` |
| `?r=qualitas/resultado` | Precio (total con derechos e IVA), desglose, **comisión en porcentaje e importe** tal como llegan ("no disponible" si falta), formas de pago, coberturas, PDF y evidencia | Igual |
| `?r=qualitas/formas-pago` | Botón **"Ver otras formas de pago"**: semestral, trimestral y mensual, sólo cuando el usuario lo pide. Una llamada registrada por forma; las que ya están cotizadas no se repiten | Igual |
| `?r=qualitas/pdf` | PDF propio (`PdfBasico`) | Igual |
| `?r=descuentos` | Administración del rango de descuento. Enlace en el menú y acceso al cotizador de Qualitas | Sólo admin; no-admin recibe 403 |

**Cambios en lo compartido:**

- **`layout.php`: un solo cambio**, el enlace "Descuentos" dentro del bloque de administradores. El nombre "Qualitas (en preparación)" del menú sigue sin ser enlace; al cotizador se entra desde "Descuentos".
- **`layout.php`, segundo cambio (Albert, 2026-09-28):** en el bloque de administradores, "Qualitas (en preparación)" pasa a ser enlace a `?r=qualitas`; HDI y Zurich siguen como texto. Regresión con 16 pantallas × 2 usuarios, contra la versión anterior al cambio, con copias verificadas por `verificar_copia_sin_red.php`: mismos códigos HTTP. Ignorando la sangría, la única diferencia en el HTML del admin es ese `<span>` que pasa a `<a>`; el no-admin ve el HTML idéntico y recibe 403 en `?r=qualitas` y `?r=descuentos`. Cero errores PHP. **Pendiente:** cuando Qualitas pase a `OPERATIVA`, sale de este bloque (que sólo lista las que no cotizan) y habrá que darle un lugar en el menú para todos.
- `public/index.php`, además de las rutas nuevas, lleva tres guardas, **aprobadas por Albert el 2026-09-28**:
  1. `resultado` manda las cotizaciones de Qualitas a `qualitas/resultado`. La pantalla de GNP tiene un botón de imprimir que llama a GNP.
  2. `imprimir` rechaza toda cotización que no sea de GNP. Sin esta guarda, `ImpresionServicio` habría llamado a **GNP producción** con el folio de Qualitas.
  3. `historial`: un no administrador no ve cotizaciones de compañías que no estén `OPERATIVA` o `SUSPENDIDA`. Las filas de GNP no cambian.

**Regresión sin llamadas (2026-09-28):**

- `php -l`: 66 archivos de `app/` y `public/`, sin errores.
- **Entorno:** dos copias del sistema en servidores locales, una del tag `pre-qualitas` y otra la actual. Cada una con su copia de la base y dos usuarios de prueba (admin y no-admin). En las dos, las URL de GNP y de Qualitas apuntaban a `127.0.0.1:9`, un puerto cerrado: ninguna petición podía salir del equipo.
- **GNP, 12 pantallas × 2 usuarios** (cotizar, historial con y sin filtro, juega y compara, armador, plantillas, usuarios, resultado, evidencia de petición y de respuesta, comparativo, api de marcas):
  - Mismos códigos HTTP antes y después.
  - HTML **idéntico** para el no-admin.
  - Para el admin, la única diferencia es la línea del enlace "Descuentos".
  - Plantillas y usuarios siguen dando 403 al no-admin.
  - Cero errores PHP.
- **Qualitas y descuentos:**
  - Admin: todas las pantallas cargan con 200. La cotización de prueba se armó con la respuesta real id 123 y mostró total $10,464.91 y comisión 11% / $928.43.
  - No-admin: 403 en `qualitas`, `qualitas/resultado`, `qualitas/pdf`, `qualitas/formas-pago` y `descuentos` (también el POST de guardar). En el historial no ve la cotización de Qualitas.
  - Descuento de 60%: el formulario vuelve con "fuera del rango permitido: 0 a 55%", sin guardar ni llamar.
  - Guardado del rango: 40–30 se rechaza; GNP se rechaza; 0–50 se guarda y queda registrado con el nombre del admin.
  - `imprimir` de GNP con la cotización de Qualitas: rechazado, 0 llamadas. Con una cotización de GNP sigue llegando a su flujo de siempre.
  - Sin servidor de Qualitas (puerto cerrado): cada forma de pago y cada paquete quedó como su propia fila `RED` en `sys_llamadas`, y la cotización quedó en `ERROR` con el mensaje en pantalla.
- **La base real no cambió:** 40 cotizaciones de GNP y 118 llamadas de GNP antes y después. Las copias se borraron al terminar.

### Etapa 6 — prueba de igualdad (corrida por Albert el 2026-09-28: `sys_llamadas` 127 a 130)

Cuatro cotizaciones en QA:

- las 3 de "Ejemplos Qualitas": Captiva y NP300 con 55%, Vento con 20%, pronto pago 14;
- **una de Limitada (código 3) con la Captiva**, para confirmar el paquete (Albert, 2026-09-28).

**Resultado:** las cuatro salieron OK. Captiva, NP300 y Vento dan 5 de 5 conceptos iguales al PDF de Qualitas (15/15), y la Limitada cotiza. Detalle en "Reglas verificadas", puntos 12 a 14.

La llamada de error con descuento de 60% ya se hizo: la corrió Albert desde su terminal (id 126, ver "Reglas verificadas", punto 11).

Comandos, para correr desde la terminal de Albert (cada uno es **una** llamada a QA):

```
C:
mpp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php cotizar-captiva          --autorizado
C:
mpp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php cotizar-np300            --autorizado
C:
mpp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php cotizar-vento            --autorizado
C:
mpp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php cotizar-captiva-limitada --autorizado
```

- **Los tres ejemplos** mandan el mismo XML que los ejemplos de Qualitas, salvo las fechas (hoy). Se comprobó sin red, contra una copia saneada. Cada uno imprime la comparación contra su PDF: prima neta, pronto pago, derechos, IVA y total, con ✓/✗.
- **La Limitada** pasa por el módulo: paquete del catálogo con `<Paquete>3</Paquete>` y coberturas 3, 4, 5, 6, 7, 14 y 47, sin DM. No tiene PDF de referencia: sólo confirma que el paquete cotiza.
- Todos dejan su fila en `sys_llamadas` y la petición y la respuesta crudas en `evidencia/`.

## Lista para pasar a `OPERATIVA` (ADR-010, punto 12) _(Claude, 2026-09-28)_

| Condición de ADR-010 | Estado | Base |
|---|---|---|
| Cumple el contrato (punto 3) y tiene el candado de emisión (punto 4) | ✅ | `AseguradoraQualitas` implementa `CotizadorAseguradora`. Candado doble (ruta + contenido); 16 variantes bloqueadas en prueba sin red |
| Guarda el resultado en el formato común (punto 7) | ✅ | Precio = `PrimaTotal`, en `cot_resultados`/`cot_resultado_coberturas` con `aseguradora='QUALITAS'` (pruebas de la Etapa 4) |
| Deja evidencia de cada llamada, con credenciales enmascaradas (ADR-006) | ✅ | `sys_llamadas` 120 a 130 con `aseguradora='QUALITAS'`. `cUsuario`/`cTarifa` enmascarados; el servicio de emisión no lleva contraseña |
| Reglas verificadas documentadas | ✅ | Esta sección y "Reglas verificadas", puntos 1 a 14 |
| Las cotizaciones de prueba dan el mismo precio que la compañía | ✅ en QA · ❌ en producción | 15/15 contra los PDF de Qualitas (ids 127 a 129). Falta la cotización de control en producción |
| GNP sigue funcionando igual | ✅ | Regresión sin llamadas de las Etapas 5 y del menú: HTML de GNP idéntico salvo el menú de administradores; imprimir de GNP no acepta cotizaciones de Qualitas |

**Falta para `OPERATIVA`:**

- [ ] **Catálogo de vehículos:** `cUsuario`/`cTarifa` de Qualitas. Sin él, la clave AMIS se escribe a mano y no se sabe el tipo de vehículo, así que el descuento usa la fila Todos y no se puede comparar la comisión contra su tipo.
- [ ] **Confirmación de Qualitas** de que el negocio 08902 / agente 0008810 está habilitado en **producción**.
- [ ] **Cotización de control en producción**, con autorización. Requiere poner `QUALITAS_URL_PRODUCCION` y comprobar que la consideración 04 en `0` funciona; nunca se ha probado.
- [ ] **Producción va por `http` sin cifrar** según el manual. Preguntar a Qualitas si hay `https` antes de mandar datos reales.
- [ ] **Gastos Legales y Asistencia Vial:** hoy se muestran con la suma que devuelve Qualitas ($3,000,000 y $20,000), donde su PDF dice AMPARADO. Pendiente de decidir la presentación (punto 14).
- [ ] **Una cotización de punta a punta desde la pantalla contra QA.** Las cuatro de la Etapa 6 salieron del script; la pantalla y `QualitasServicio` sólo se han probado con el transporte falso.
- [ ] **Otras formas de pago** (semestral, trimestral, mensual): nunca se han cotizado contra el servicio.
- [ ] **Menú para todos los usuarios:** al pasar a `OPERATIVA`, Qualitas sale del bloque "en preparación" de los administradores y hoy no hay enlace para los demás. Hay que darle un lugar en `layout.php` antes del cambio de estado.
- [ ] Sin bloquear, pero pendientes: paquete Básica sin código; vigencia de 7 días sin confirmar; comisión de camiones sin ver en el servicio; los códigos `AUTH` y `SISTEMA` de la tabla de errores no se han visto llegar.
## Reglas verificadas contra el servicio de Qualitas

Como ADR-005 para GNP: `[CONFIRMADO]` sólo lo que se vio responder de verdad, con su `sys_llamadas.id`. Lo que sale de documentos o de los PDF de ejemplo sigue `[PENDIENTE]`. La petición y la respuesta crudas de cada llamada están también en `docs/aseguradoras/qualitas/evidencia/`.

### Llamadas de la Etapa 2 (QA, 2026-09-28, autorizadas por Albert: `Test`, WSDL y una cotización de la Captiva)

| `sys_llamadas.id` | Qué | Resultado |
|---|---|---|
| 120 | `Test` | `RED`: "Could not resolve host". La petición **no salió del equipo**: la terminal Bash corre en un entorno aislado sin DNS. Windows sí resuelve `qa.qualitas.com.mx` (45.60.68.6, detrás de Imperva). Las llamadas siguientes se hicieron desde PowerShell |
| 121 | `Test` | HTTP 500 con la página genérica de IIS ("The page cannot be displayed because an internal server error has occurred"). Explicado por la 122 |
| 122 | `GET …/WsEmision.asmx?WSDL` | OK, 3,237 bytes |
| 123 | `obtenerNuevaEmision`, Captiva 2026, AMIS 21191, CP 11590, Estado 9, Amplia, descuento 55, pronto pago 14 | **OK**, `NoCotizacion` 1219390564, 1,265 ms |
| 124 | Misma Captiva con `PorcentajeDescuento=60`, para ver el formato de un error (autorizada por Albert el 2026-09-28; se esperaba el error 7) | `RED`: "Could not resolve host". **No llegó a Qualitas.** Windows sí resolvía el nombre, pero el PHP de la sesión no, desde ninguna de las dos terminales. Por la instrucción de no reintentar, no se repitió. El formato de `<CodigoError>` sigue `[PENDIENTE]` |
| 125 | La misma llamada que la 124, repetida una sola vez desde PowerShell por indicación de Albert (la 124 no había salido, así que no cuenta como reintento) | `RED`: "Could not resolve host" otra vez. **No llegó a Qualitas.** Queda para que Albert la corra desde su terminal: `C:
| 126 | La misma llamada, **corrida por Albert desde su terminal** | `DATOS`, HTTP 200, 1,484 ms. `<CodigoError>0007-- Descuento fuera de Rango, rango valido 0 a 55</CodigoError>`. Ver punto 11 |
mppphpphp.exe appseguradorasQualitaspruebasllamada_qa.php error-descuento-60 --autorizado` |

### 1. El servicio en QA sólo tiene `obtenerNuevaEmision` `[CONFIRMADO]` (id 122, 121)

El WSDL de QA no publica `Test`, `HolamundoAux`, `EnviaMail` ni `obtenerNuevaEmisionDXN`, que sí aparecen en la imagen del manual. Llamar a `Test` en QA devuelve un 500 de IIS, no un SOAP Fault. La conexión se comprueba con el WSDL, no con `Test`. Si esos métodos existen en producción no se sabe `[PENDIENTE]`.

### 2. Firma del método `[CONFIRMADO]` (id 122, 123)

- Parámetro único **`xmlEmision`** (cadena), namespace `http://qualitas.com.mx/`, SOAP 1.1 *document/literal*, `SOAPAction: "http://qualitas.com.mx/obtenerNuevaEmision"`.
- La respuesta llega en **`obtenerNuevaEmisionResult` como texto**: el XML de movimientos escapado, con su propia declaración `<?xml …?>`.
- El WSDL anuncia la dirección `https://qa.qualitas.com.mx/WsEmision/WsEmision.asmx`, **sin el puerto 8443** del manual. La llamada se hizo por `:8443` y funcionó. No se ha probado sin el puerto.

### 3. Éxito = `<CodigoError/>` vacío `[CONFIRMADO]` (id 123)

La cotización exitosa trae `<CodigoError/>` vacío y HTTP 200. El error de negocio también llega con HTTP 200 (id 126, punto 11).

### 4. La respuesta regresa el movimiento completo, con datos cambiados `[CONFIRMADO]` (id 123)

Qualitas devuelve el mismo `<Movimiento>` que se le mandó, rellenado. Además cambia varios valores:

| Campo | Se mandó | Regresó |
|---|---|---|
| `NoCotizacion` | vacío | `1219390564` |
| `NoOTra` | vacío | `3292805212P` |
| `TipoEndoso` | vacío | **`21`** |
| `NoNegocio` / `Agente` | `08902` / `0008810` | `8902` / `8810` (sin ceros) |
| `NoInciso` | `1` | `0001` |
| `TarifaValores/Cuotas/Derechos` | `LINEA` | `2608` |
| DM y RT `SumaAsegurada` / `TipoSuma` | `0` / `0` | `468000` / **`2`** (el Anexo 6 no tiene tipo 2) |
| Deducibles | `5`, `10` | `0005`, `00010` |
| Cobertura 7 (GL) · 14 (AV) | suma `0` | `3000000` · `20000` |
| Consideraciones DG | 1, 4, 5 | 1, 4, 5 **y 55, 56 vacías** |

Consecuencia: **una respuesta no se puede reenviar como petición.** Trae `TipoEndoso="21"`, y el candado por contenido la bloquearía. Así debe ser.

### 5. Dónde viene el precio `[CONFIRMADO]` (id 123)

`<Primas>` trae el desglose, y la aritmética cuadra exacta:

```
PrimaNeta  +  Recargo  +  Derecho  +  Impuesto  =  PrimaTotal
 8,440.28  −   168.81  +   750.00  +  1,443.44  =  10,464.91  ✓
Impuesto = 16% × (8,440.28 − 168.81 + 750.00) = 1,443.4352 → 1,443.44  ✓
```

- **Precio = `PrimaTotal`**: ya incluye pronto pago, derechos e IVA.
- `PrimaTotal`, `PrimaNeta`, `Derecho`, `Impuesto` y el pronto pago son **idénticos al PDF de ejemplo de la Captiva** (23-sep): Importe total 10,464.91, prima neta 8,440.28, tasa fin. P.F. −168.81, gastos de expedición 750.00, IVA 1,443.44. Primera coincidencia para el punto 12 de ADR-010. Falta la comparación completa de la Etapa 6.

### 6. El pronto pago llega en `Recargo`, en negativo `[CONFIRMADO]` (id 123)

Con la consideración 05 = 14 días, `Recargo` = **−168.81** = −2% de la prima neta (−168.8056). No hay campo propio para el pronto pago: viene mezclado en `Recargo`, que el manual describe como "recargo por forma de pago fraccionada". Con una forma de pago fraccionada, `Recargo` podría traer las dos cosas juntas `[PENDIENTE]`.

### 7. Comisión: dónde viene y cuánto es por tipo de vehículo `[CONFIRMADO dónde viene]` (ids 123, 127–130) · qué significa y los porcentajes: **confirmado por negocio (Albert, 2026-09-28)**

**Qué significa cada campo**, confirmado por negocio (Albert, 2026-09-28). No es una confirmación del servicio: el manual dice que `Primas/Comision` es "la comisión total".

- `Primas/Comision` es el **porcentaje** de comisión.
- `Recibos/Comision` es el **importe**.

**Porcentaje por tipo de vehículo:**

| Tipo | Confirmado por negocio (Albert, 2026-09-28) | Visto en el servicio | Llamada | Prima neta | `Recibos/Comision` |
|---|---|---|---|---|---|
| Auto | 11 | **11** `[CONFIRMADO]` | 123, 127 (Captiva, Amplia) | 8,440.28 | 928.43 |
| Auto | 11 | **11** `[CONFIRMADO]` | 130 (Captiva, Limitada) | 4,801.43 | 528.15 |
| Moto | 11 | **11** `[CONFIRMADO]` | 129 (Vento) | 6,234.08 | 685.74 |
| Pick-up | 8.8 | **8.8** `[CONFIRMADO]` | 128 (NP300, uso carga) | 14,710.43 | 1,294.51 |
| Camión | 8.8 | sin observar: ninguna cotización de camión todavía | — | — | — |

Camiones queda **confirmado por negocio, pendiente de ver en el servicio**.

**Importe = porcentaje × prima neta** (antes del pronto pago) en las cinco respuestas, **truncado a centavos, no redondeado**:

- 928.4308 → 928.43
- 528.1573 → 528.15
- 685.7488 → 685.74
- 1,294.5178 → 1,294.51 (redondeado habría sido 1,294.52)

Es otra razón para no calcularlo nunca.

**Cómo se usa (Etapas 4 y 5; las reglas no cambian):**

- **Al usuario se le muestran los dos**, porcentaje e importe, tal como vengan en cada respuesta. No se calculan ni se derivan: ni el importe a partir del porcentaje, ni al revés.
- **Nunca se asume el porcentaje por tipo de vehículo.** La tabla sirve para **detectar anomalías, no para llenar huecos**.
- **Si una respuesta trae un porcentaje distinto al esperado para su tipo**, se muestra tal cual y se anota un aviso en la bitácora.
- **Si no llega el porcentaje o el importe, se muestra "no disponible"**, nunca un valor por omisión.

### 8. Formas de pago: una por llamada `[CONFIRMADO para contado · PENDIENTE las demás]` (id 123)

Con `FormaPago` C llegó **un solo** `<Recibos NoRecibo="1">`, con los mismos importes que `<Primas>`. La respuesta no trae el desglose semestral ni trimestral que muestra el PDF. Para tener S/T/M habría que mandar otra cotización con esa forma de pago; no se ha probado.

### 9. Primas por cobertura `[CONFIRMADO que vienen · no suman la neta]` (id 123)

Cada `<Coberturas>` trae su `<Prima>`, y suman 17,844.35 contra una prima neta de 8,440.28, igual que en el PDF. **No se usa la suma de coberturas como precio.** Dónde se aplica el descuento sigue `[PENDIENTE]`.

### 10. Lo que la respuesta no trae

La vigencia de la cotización (7 días en el PDF) no viene en la respuesta. Sigue `[PENDIENTE]`.

### 11. Error de negocio: formato y trato `[CONFIRMADO]` (id 126)

Captiva 2026 con `PorcentajeDescuento=60` (el tope del negocio es 55), corrida por Albert el 2026-09-28.

- **Formato de `<CodigoError>`** `[CONFIRMADO]`: código a **4 dígitos con ceros**, `--` y el texto. Aquí: `0007-- Descuento fuera de Rango, rango valido 0 a 55`. El parseo actual lo lee bien (clave `7`, estado `DATOS`, mensaje intacto) y **no se cambió**. Coincide con el formato del catálogo (`0310--…`, `0340--…`).
- **Error de negocio con HTTP 200** `[CONFIRMADO]`: el éxito lo decide `<CodigoError>`, no el código HTTP. Es la misma regla que GNP ([ADR-005, punto 5](../../03_Decisiones/ADR-005-reglas-verificadas-gnp.md)).
- **Tope de descuento de autos: 55** `[CONFIRMADO]`: lo dice el propio servicio ("rango valido 0 a 55"). La semilla de `sys_descuentos` para autos (0–55) queda respaldada por el servicio. Camiones (30) y motos (20) siguen respaldados **sólo por el formulario del negocio**.
- **Con error, Qualitas devuelve el `Derecho` que se le mandó (750) y el resto de las primas vacías**: sin `NoCotizacion`, sin coberturas y sin recibos. El 750 no es un precio. `prueba_etapa4_sin_red.php` (sección 7) usa esta respuesta real y comprueba que no se guarda ningún renglón en `cot_resultados`, que el historial no muestra precio y que la pantalla muestra el mensaje tal cual, sin precio. Ya se comportaba así; sólo se agregó la prueba.
- Otras diferencias contra la respuesta exitosa (id 123), sólo anotadas: `TipoEndoso` regresó `6` (antes `21`), `Moneda` `1` (se mandó `0`) y `Plazo` `1` (se mandó vacío).

### 12. Prueba de igualdad contra los PDF de Qualitas `[CONFIRMADO]` (ids 127, 128, 129)

Mismos datos que "Ejemplos Qualitas" (fechas: hoy). Los cinco conceptos de cada PDF, contra la respuesta de QA:

| Concepto | Captiva (127) | NP300 (128) | Vento (129) |
|---|---|---|---|
| Prima neta | 8,440.28 ✓ | 14,710.43 ✓ | 6,234.08 ✓ |
| Pronto pago (en `Recargo`) | −168.81 ✓ | −294.21 ✓ | −124.68 ✓ |
| Derecho de póliza | 750.00 ✓ | 750.00 ✓ | 750.00 ✓ |
| IVA | 1,443.44 ✓ | 2,426.60 ✓ | 1,097.50 ✓ |
| Total a pagar | 10,464.91 ✓ | 17,592.82 ✓ | 7,956.90 ✓ |

**15 de 15 iguales.** La Captiva repitió exacto el total de la id 123 (10,464.91), cinco días después del PDF (23-sep) y el mismo día de la 123.

### 13. Limitada (código 3) cotiza `[CONFIRMADO]` (id 130)

Captiva 2026, AMIS 21191, 55%, pronto pago 14, por el módulo (paquete del catálogo, `<Paquete>3</Paquete>`). Sin PDF de referencia.

- Total **6,328.26**. Prima neta 4,801.43, `Recargo` −96.03, derecho 750, IVA 872.86. Comisión 11 / 528.15.
- **Sin DM**: no se mandó y no regresó. **RT con deducible 10** (`00010`), suma 468,000.
- **La aritmética es la misma que en los demás:**

  ```
  4,801.43 × 2% = 96.0286 → 96.03
  4,801.43 − 96.03 + 750.00 = 5,455.40
  5,455.40 × 16% = 872.864 → 872.86
  5,455.40 + 872.86 = 6,328.26 ✓
  ```

### 14. Gastos Legales y Asistencia Vial: la suma la pone Qualitas `[CONFIRMADO]` (ids 123, 127–130)

En la petición mandamos `SumaAsegurada` **0** para Gastos Legales (7) y Asistencia Vial (14). Qualitas las regresa llenas en las cinco respuestas:

| Llamada | Vehículo | GL (7) | AV (14) | RC por la carga (31) |
|---|---|---|---|---|
| 123, 127 | Captiva, Amplia | 3,000,000 | 20,000 | — |
| 128 | NP300, carga | 3,000,000 | 20,000 | 0 (mandamos `A\|DESCRIPCION`) |
| 129 | Vento, moto | 3,000,000 | **15,000** | — |
| 130 | Captiva, Limitada | 3,000,000 | 20,000 | — |

- **En los PDF de Qualitas las tres dicen AMPARADO.** El importe no es un invento del módulo, pero tampoco es como Qualitas presenta esas coberturas.
- Hoy el módulo muestra cualquier suma mayor que 0 como monto ("$3,000,000", "$20,000"), y la de 0 como "Amparada". La RC por la carga queda en "Amparada" por coincidencia, no por regla.
- **La presentación está pendiente de decidir**; no se ha corregido.
