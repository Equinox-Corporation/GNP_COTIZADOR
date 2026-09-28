# Qualitas — estado de la integración

Documento operativo. Última revisión: _(Claude, 2026-09-25)_.

**Estado en la plataforma:** `PREPARADA`. A diferencia de HDI, **sí llegó el manual técnico** y trae ambiente de pruebas. Faltan datos de acceso (usuario del servicio de catálogo, confirmación de que el negocio 08902 está dado de alta para servicio web) y un ejemplo de respuesta. Alcanza para construir contra pruebas (QA); no alcanza para operar.

Pasa a `EN_INTEGRACION` cuando exista el cliente con su candado de emisión (ADR-010, punto 4) y se haya hecho la primera llamada a QA con respuesta real.

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
3. **Pronto pago se aplica**: consideración 05 con 14 días (máximo permitido, error 192). Valor desde configuración.

## Reglas verificadas contra el servicio de Qualitas

_Ninguna todavía._ Aquí se irán anotando, como ADR-005 para GNP, con etiqueta `[CONFIRMADO]` sólo cuando se hayan visto responder de verdad. Lo de arriba sale de documentos y PDF de ejemplo: es `[PENDIENTE]` hasta verlo en QA.
