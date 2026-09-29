# Qualitas — estado de la integración

Documento operativo. Última revisión: _(Claude, 2026-09-28)_.

**Estado en la plataforma:** `EN_INTEGRACION` desde el 2026-09-28 (decisión de Albert). Hay cliente con candado de emisión doble y una cotización real en QA que cuadra con el PDF de ejemplo (`sys_llamadas.id` 123). El cambio lo hace una migración idempotente en `Esquema::migrar()`, que sólo mueve a Qualitas desde `PREPARADA`. Se probó contra una copia de la base y después contra la real (respaldo `datos/cotizador_gnp.sqlite.bak_pre_en_integracion_20260928_110452`): sólo cambió la fila de Qualitas, y GNP quedó igual (40 cotizaciones, 118 llamadas).

Sigue faltando el usuario del catálogo (`cUsuario`/`cTarifa`). El negocio 08902 está en el **ambiente de pruebas de Qualitas**: una vez validado en QA, hay que **solicitar su liberación** (correo de Qualitas, "Indicaciones Qualitas.pdf"). Pasa a `OPERATIVA` sólo con la lista del punto 12 de ADR-010 completa.

_Antes (hasta el 2026-09-28):_ `PREPARADA`. Pasaba a `EN_INTEGRACION` cuando existiera el cliente con su candado de emisión (ADR-010, punto 4) y se hubiera hecho la primera llamada a QA con respuesta real.

## Para retomar: EN PAUSA desde el 2026-09-29

**El proyecto está en pausa hasta que lleguen dos cosas** (Albert, 2026-09-29):

1. **Credenciales del catálogo de Qualitas** (`cUsuario`/`cTarifa` de wsTarifa).
2. **PDF de cotizaciones del portal que entrega Operaciones**, en `Proyectos\Qualitas_Cotizador\Portal\`.

Mientras tanto no se hace nada. La ruta completa está en `01-ruta-critica.md`.

- **Rama** `feature/qualitas-cotizador`, en `origin`. `main` no se ha tocado, y el tag `pre-qualitas` está en el mismo commit que `main`.
- **La fusión a `main` está bloqueada** por dos commits de Beto que cambian GNP (`a768fdc`, `0d61171`). No se tocan; lo decide Albert con Beto (`01-ruta-critica.md`, paso 11).
- **Hecho y cerrado:**
  - **Catálogo provisional** (`cat_qua_vehiculos`), con cascada en pantalla. En la base real están los 3 vehículos de "Ejemplos Qualitas", con fecha 2026-09-23.
  - **Extractor de los PDF del portal** a CSV.
  - **Respaldo automático antes de migrar**, con retención de los últimos 10 (ADR-003, punto 6).
  - **Consideración 40 confirmada en QA** (regla 18). Se manda siempre.
  - **Motos en semestral confirmadas** (regla 19). Se descartó el rechazo de formas de pago para motos.
  - **Postalia descartada**: no trae códigos de SEPOMEX.
- **Al retomar, en este orden:**
  1. **`ref_sepomex`** desde `Proyectos\Qualitas_Cotizador\SEPOMEX\sepomex_cp_20260925.csv` (159,331 asentamientos, al 2026-09-25). Ese CSV queda **fuera de git** por la licencia de Correos; ya lo ignora `.gitignore`. Luego, la captura de colonia en la pantalla, para mandar la consideración 40 con los códigos del CP.
  2. **Con los PDF de Operaciones:**
     - `extraer_pdf_portal_qualitas.php` genera el CSV;
     - Albert lo revisa;
     - se saca respaldo y se corre `importar_portal_qualitas.php --aplicar`;
     - se cotizan esas AMIS por el servicio, con llamadas autorizadas, y se comparan contra `cat_qua_referencias_portal`.
  3. **Con las credenciales:** wsTarifa (`listaMarcas`/`listaTarifas`) llena `cat_qua_vehiculos` con fuente `WSTARIFA`. "MO" en la marca corta indica moto y sirve para llenar `tipo_vehiculo`.
  4. **Pendiente de Albert, sin fecha:** probar la cascada y la protección contra llamadas repetidas (A y B) en el navegador.
- **Esperando a Qualitas:** `cUsuario`/`cTarifa`, y la liberación del negocio 08902 cuando se valide en QA.
- **Última llamada registrada de Qualitas:** `sys_llamadas.id` 146. Evidencia en `evidencia/`.
- **Pruebas sin red, todas en verde (349):**

  | Archivo | Pruebas |
  |---|---|
  | `prueba_sin_red.php` | 75 |
  | `prueba_etapa4_sin_red.php` | 130 |
  | `prueba_solicitud_unica_sin_red.php` | 42 |
  | `prueba_catalogo_sin_red.php` | 37 |
  | `prueba_extractor_pdf_sin_red.php` | 41 |
  | `app/scripts/prueba_respaldo_migracion_sin_red.php` | 24 |

- **Reglas de trabajo** (`docs/aseguradoras/00-reglas-de-trabajo.md`):
  - el `.env` nunca se imprime;
  - toda copia se verifica con `app/scripts/verificar_copia_sin_red.php` antes de levantarla;
  - con cambios de `Esquema.php`, primero copia y respaldo; se prueban en una copia, no en la carpeta que sirve XAMPP.

_Antes: "Para retomar" al 2026-09-28. Prioridad: catálogo; se esperaban el archivo de SEPOMEX y los datos del portal de los 8 vehículos. El detalle está en el historial de git de este archivo._

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
| `Indicaciones Qualitas.pdf` | Correo de Qualitas (recibido por Albert, anotado el 2026-09-28): el negocio está en ambiente de pruebas; consideración 40 (municipio y colonia SEPOMEX) para la tarifa por CP | Ver la sección "Correo de Qualitas" |
| `Ejemplos Qualitas\` — 3 pares XML + PDF | Cotizaciones de ejemplo hechas por Qualitas el 23-sep-2026 (CP 11590): Chevrolet Captiva 2026, Nissan NP300 2026 (carga), Vento Tornado 300 2026 (moto) | Referencia de precio para la prueba de igualdad (ADR-010, punto 12) |

### Datos del negocio (formulario de alta)

| Dato | Valor | Nota |
|---|---|---|
| Negocio (`NoNegocio`) | `08902` | "Fecha de revisión de condiciones: 30/09/26". Las columnas que llena Qualitas (SIO) están vacías: **no hay constancia de que ya esté activo**. _(2026-09-28)_ Qualitas confirmó que está en su **ambiente de pruebas**; hay que solicitar su liberación una vez validado |
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
| 1 | Manual técnico | ✅ Llegó, con huecos | Hay estructura de petición completa. **No hay ningún ejemplo de respuesta**, y el WSDL sólo viene como imagen. _(2026-09-28)_ Resuelto de nuestro lado: WSDL real (id 122) y respuestas reales, exitosas y con error (ids 123, 126 a 139) |
| 2 | Tipo de servicio | ✅ | SOAP/XML ("WS"), ASP.NET (`.asmx`). Método `obtenerNuevaEmision` |
| 3 | Credenciales | ⚠️ Parcial — **bloqueante** | Hay `NoNegocio` 08902 y agente 0008810. El servicio de emisión no documenta usuario/contraseña (¿validan por IP?). El de catálogo **exige `cUsuario` y `cTarifa`, que no llegaron**. _(2026-09-28)_ En QA bastó con negocio + agente (id 123); el correo de Qualitas tampoco trae `cUsuario`/`cTarifa` |
| 4 | Catálogo de errores | ✅ | `CatalogoErroresSW.xlsx` + tablas de errores de Tarifas e Impresión |
| 5 | Catálogo de vehículos | ✅ documentado · ⚠️ sin acceso | `wsTarifa.asmx`, clave **AMIS** (5 dígitos). Sin `cUsuario`/`cTarifa` no se puede descargar. Sólo trae URL de producción (y en `http`) |
| 6 | Ambiente de pruebas | ✅ | `https://qa.qualitas.com.mx:8443/WsEmision/WsEmision.asmx`. Además, la consideración 04 marca pruebas (1) o producción (0) |
| 7 | Matriz de paquetes y coberturas | ✅ Parcial | Anexo 5 (qué cobertura es obligatoria/opcional por paquete) + condiciones del negocio. Falta el código de "Básica" y los rangos de suma permitidos |
| 8 | Impresión | ❌ Para cotización | `WSIMPRESION` pide **número de póliza** (`nPoliza`), no de cotización. Se entrega el PDF propio (PdfBasico) |
| 9 | Catálogo de CP / estados | ✅ | Anexo 1 (32 estados, mismo orden que INEGI) + SEPOMEX para municipio/colonia (esto último, sólo emisión). _(2026-09-28)_ **Corrección:** Qualitas pide municipio y colonia SEPOMEX también para cotizar, en la consideración 40 ("Indicaciones Qualitas.pdf") |
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

_(2026-09-28)_ El formato de `<CodigoError>` ya se vio: `0007-- texto`, y el 7 cayó en `DATOS` como se esperaba (id 126; "Reglas verificadas", punto 11). Los códigos de `AUTH` y `SISTEMA` siguen sin verse.

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
| Sumas | Lista cerrada | "Abierta", pero la limita SISE. En los ejemplos DM y RT se mandan en **0** y Qualitas pone el valor (468,000 para la Captiva) `[PENDIENTE: de dónde sale]`. Regresa `TipoSuma` 2, que no está en el Anexo 6. Gastos Legales y Asistencia Vial también regresan con suma aunque se manden en 0 ("Reglas verificadas", punto 14). Las sumas por omisión pueden depender del tipo de vehículo (Etapa 3) |
| Tipo de suma | — | 0 convenido · 1 factura · 3 comercial, sólo para DM y RT. RC Complementaria (47) usa tipo **14**, que no está en el anexo |
| Deducibles | Por cobertura, lista | DM 3/5/10 · RT 5/10/20 · RC 0 UMA |
| Vehículo | Marca/armadora/carrocería/versión | **Clave AMIS** + modelo + **dígito verificador** (módulo 10) en la consideración 01 |
| Quién tarifica | Edad y CP del conductor | **Estado + CP**. Estado y CP tienen que corresponder (error 202) |
| Segmento | Procedencia (sólo Residentes verificada) | **Uso** (01 normal, 06 carga…) + **servicio** (01 particular) + tipo de vehículo del AMIS |
| Descuento | Viene aplicado por GNP | **Lo manda Equinox** en `PorcentajeDescuento`: tope 55 autos/pick-up, 30 camiones, 20 motos. Es decisión de negocio |
| Derecho de póliza | Lo devuelve GNP (680 observado) | **Lo manda Equinox**: 750 |
| Precio | `TOTAL_PAGAR` | `PrimaTotal` = "Importe total" `[CONFIRMADO]` (ids 123, 127 a 129: 15/15 contra los PDF de Qualitas) |
| Formas de pago | — | El PDF muestra contado, semestral y trimestral en la misma cotización. El servicio responde **una forma de pago por llamada** `[CONFIRMADO]` (ids 136 a 139); semestral y trimestral iguales al PDF |
| Vigencia de la cotización | 15 días | **7 días** |
| PDF | Servicio de impresión (y lo manda por correo) | No hay para cotización: PDF propio |
| Comisión | No viene | **Viene en la respuesta** (`Comision`). Decidido (Albert): el usuario administrador la ve en pantalla, en porcentaje e importe, tal como llega; **nunca en el PDF** ("Reglas verificadas", puntos 7 y 17) |
| Transporte | https | Producción de emisión y de tarifas en **http** (sin cifrar). QA sí es https |

### Aritmética de los PDF de ejemplo — cuadra en papel y en el servicio `[CONFIRMADO]` (ids 127, 128, 129)

_(2026-09-28)_ Las tres cotizaciones de ejemplo, repetidas en QA, dan los cinco conceptos iguales al centavo ("Reglas verificadas", punto 12). El SUBTOTAL 9,021.47 de la Captiva también cuadra.

```
PRIMA NETA − PRONTO PAGO (2%) + GASTOS EXP.  = SUBTOTAL   ;  SUBTOTAL × 16%  = IVA      ;  TOTAL
 8,440.28  −    168.81       +   750.00      = 9,021.47   ;                  = 1,443.44 ;  10,464.91  (Captiva, 55%)
14,710.43  −    294.21       +   750.00      = 15,166.22  ;                  = 2,426.60 ;  17,592.82  (NP300, 55%)
 6,234.08  −    124.68       +   750.00      = 6,859.40   ;                  = 1,097.50 ;   7,956.90  (Vento, 20%)
```

- La "TASA FIN. P.F." es exactamente **−2% de la prima neta** en los tres casos: descuento por pronto pago (consideración 05). Se deduce, no está documentado.
- Las primas por cobertura del PDF **no suman la prima neta** (Captiva: suman 17,844.35 contra 8,440.28). El descuento se aplica en algún punto que el documento no explica. No se usa la suma de coberturas como precio.
- Los XML de ejemplo no son la petición exacta de esos PDF (sus fechas son 29-ago/30-sep y los PDF dicen 23-sep). Sirven como modelo, no como par byte a byte.

## Correo de Qualitas: "Indicaciones Qualitas.pdf" _(recibido por Albert; anotado el 2026-09-28)_

En la carpeta `Proyectos\Qualitas_Cotizador\`. Acompaña la matriz del negocio, las cotizaciones de ejemplo y los XML.

1. **El negocio 08902 está en el ambiente de pruebas.** Texto de Qualitas: "una vez validado, favor de solicitar su liberación".
   - Deja de ser una pregunta y pasa a ser un **paso del plan**: validar en QA → solicitar la liberación.
   - **No trae `cUsuario`/`cTarifa`**: el catálogo de vehículos sigue bloqueado.
2. **Consideración 40 para la tarifa por CP**, en `DatosAsegurado`, además del código postal:
   - `NoConsideracion="40"`, `TipoRegla` **7** → código de **municipio** del domicilio legal;
   - `NoConsideracion="40"`, `TipoRegla` **8** → código de **colonia** del domicilio legal.

   Los valores salen del catálogo de SEPOMEX (Correos de México). La plantilla de emisión (`XMLDoc_EjemploCamposEmision_CP.xml`) muestra la forma exacta: `<ConsideracionesAdicionalesDA NoConsideracion="40">` después de `<Agrupador/>`, con los `TipoRegla` 1 a 8. Del 1 al 6 son datos de identificación del cliente, que sólo aplican a emisión.

   **Hoy no se mandan.** No se ha comprobado si cambian el precio: los 15/15 contra los PDF de Qualitas salieron sin ellas `[PENDIENTE]`.
3. **Dato:** "en el ambiente de producción deben cotizar para cuadrar costos y la emisión como impresión de póliza deben realizarla en desarrollo".
   - No cambia nada: este sistema no emite ni imprime pólizas.
   - **No se cotiza en producción hasta que el negocio esté liberado.**

## Pendientes con Qualitas

Lo que **sólo Qualitas puede resolver**. Todavía no se les escribe (Albert, 2026-09-28): primero se agotan las pruebas de nuestro lado. Lo que resolvamos nosotros sale de esta lista y queda anotado abajo, en "Resuelto de nuestro lado".

**Bloquean el paso a `OPERATIVA`:**

- ~~Confirmar que el negocio 08902 está habilitado en producción~~ → **ya no es pregunta**: Qualitas informó que está en el **ambiente de pruebas** y que, una vez validado, hay que solicitar su liberación ("Indicaciones Qualitas.pdf"). Pasa a ser un paso del plan; ver "Lista para pasar a `OPERATIVA`".
- [ ] Cómo se autentica `WsEmision` en **producción**: ¿sólo negocio + agente, o también registro de IP? En QA bastó con negocio + agente (id 123).
- [ ] **Usuario (`cUsuario`) y clave de tarifa (`cTarifa`)** de `wsTarifa` (catálogo de vehículos). También: namespace del servicio y si existe URL de pruebas.
- [ ] ¿Hay URL **https** de producción para `WsEmision` y `wsTarifa`? El manual sólo da `http`.

**No bloquean, pero sólo ellos lo saben:**

- [ ] Código del paquete **"Básica"**. No está en el Anexo 5; hoy está deshabilitado.
- [ ] Qué dato del catálogo (`cCategoria`, marca…) dice si el vehículo es auto, pick-up, camión o moto. Lo necesitan el tope de descuento por tipo y el aviso de comisión anómala (Etapa 3).
- [ ] Vigencia de la cotización: el PDF dice 7 días y la respuesta no trae el dato.
- [ ] Suma de DM/RT: se manda 0 y Qualitas regresa el valor (468,000 para la Captiva) con **`TipoSuma` 2**, que no está en el Anexo 6. ¿Qué significa?
- [ ] Primas por cobertura: suman 17,844.35 contra una prima neta de 8,440.28 (id 123). ¿Vienen antes del descuento?
- [ ] Consideración 04: con QA y `1` funciona (ids 123 a 130). ¿Qué pasa si no se manda? ¿Producción exige `0`?
- [ ] Comisión: ¿cómo se cede o ajusta por servicio web (error 179)? ¿El descuento sale de la comisión del agente?
- [ ] ¿Existe impresión de **cotización**, por número de cotización? `WSIMPRESION` pide número de póliza.
- [ ] `obtenerNuevaEmisionDXN` aparece en el WSDL del manual y no está documentado: ¿qué es? `Test` y `HolamundoAux` no existen en el WSDL de QA (id 122): ¿existen en producción?
- [ ] Versión vigente de los manuales (son de 2013 a 2021).
- [ ] Contacto técnico. El formulario de alta lo deja en blanco.

### Lo que todavía probamos nosotros (no se les pregunta)

- ¿Un envío acepta varios `<Movimiento>`, es decir, varios paquetes? Se puede probar con una llamada autorizada.
- Una cotización de un camión que no sea pick-up, para ver su comisión.

### Resuelto de nuestro lado (sale de la lista de Qualitas)

- Ejemplo de respuesta real, exitosa y con error: ids 123 y 126.
- Qué trae `PrimaTotal`: incluye pronto pago, derechos e IVA; el pronto pago llega en `Recargo` (ids 123 y 127 a 129).
- Desglose por forma de pago: la respuesta trae sólo la forma de pago pedida; hay que cotizar una por una (id 123).
- Semestral, trimestral y mensual cotizadas desde la pantalla; semestral y trimestral iguales al PDF de Qualitas (ids 137–139).
- Formato de `<CodigoError>`: id 126.
- Tope de descuento de autos, 55: lo dice el propio servicio (id 126).

## Decisiones ya tomadas

- **Sólo cotización** (ADR-009, punto 5). En Qualitas eso significa **sólo `TipoMovimiento="2"`**. El 3 (emisión) y el 4 (endoso) quedan bloqueados en el cliente.
- Las consideraciones de identificación del Art. 492 y la plantilla de emisión no se implementan.

## Decisiones de negocio (Albert, 2026-09-25)

1. **Descuento capturado por el usuario.** La pantalla tiene un campo de porcentaje; lo que el usuario escribe es lo que se manda en `PorcentajeDescuento`.
   - **Rango mínimo y máximo configurable** por aseguradora y tipo de vehículo, en una tabla común de la plataforma (`sys_descuentos`) que el administrador edita desde pantalla. Sirve para cualquier compañía que reciba el descuento en la petición. Valores iniciales de Qualitas: mínimo 0; máximo 55 autos/pick-up, 30 camiones, 20 motos.
   - **La agrupación del descuento no es la de la comisión** (Albert, 2026-09-28). Para el **tope de descuento**, el formulario del negocio pone pick-up con **autos** (55). Para la **comisión**, pick-up cuenta como **camión** (8.8; "Reglas verificadas", punto 7). En `sys_descuentos`, pick-up es un tipo propio (`PICKUP`) con 0–55, nunca 0–30. `prueba_etapa4_sin_red.php` falla si la semilla deja a pick-up con el tope de camiones.
   - **Pendiente de validación de negocio, para revisión a detalle** (Albert, 2026-09-28): pick-up cuenta como camión para la comisión (8.8), pero el formulario del negocio lo agrupa con autos para el tope de descuento (55). **No está resuelto.** Por ahora queda como está (0–55, con su prueba); Albert lo va a validar con Operaciones o con Qualitas.
   - El usuario no puede salir del rango: se valida en pantalla y en servidor. Por qué: Qualitas rechaza fuera de rango (error 7) y conviene que el usuario lo vea antes de gastar una llamada.
   - Mientras no se sepa qué dato del catálogo dice si es auto, camión o moto `[PENDIENTE]`, se usa la fila "TODOS" de la aseguradora (0–55) y el rechazo de Qualitas cae en `DATOS` con su mensaje.
   - GNP no recibe descuento en la petición: no se le conecta.
   - El porcentaje usado se guarda en `datos_aseguradora_json` de la cotización, para que el historial explique el precio.
2. **La comisión se muestra al usuario.** "Bajo su control": hoy el único control documentado es el **descuento**. No hay campo documentado para ceder o ajustar comisión; el error 179 ("La cesión de comisiones es mayor a la comisión") indica que el mecanismo existe, pero no cómo se manda `[PENDIENTE — preguntar a Qualitas]`. Tampoco está documentado si el descuento reduce la comisión `[PENDIENTE]`. El módulo muestra la comisión que devuelva Qualitas junto al precio y no la inventa ni la calcula.
   - _(Albert, 2026-09-28)_ `Primas/Comision` es el **porcentaje** (autos 11, motos 11, camiones 8.8 — pick-up cuenta como camión para la comisión) y `Recibos/Comision` el **importe**. Se muestran los dos, tal como lleguen, y lo que falte dice "no disponible". Detalle en "Reglas verificadas", punto 7.
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


#### Catálogo: diagnóstico de las fuentes propias _(Claude, 2026-09-28)_

Se revisó, sólo en lectura y sin llamadas, si los datos de Equinox servían como catálogo provisional de Qualitas.

| Fuente | Qué trae | ¿Claves de Qualitas? | ¿Tipo de vehículo? | Fecha |
|---|---|---|---|---|
| `app/core/cat_comercial.db` (este proyecto) | 7,777 combinaciones marca + línea + año, 1976–2027; sin versión | **No** (sólo el mapeo a GNP) | No: todas "individual" | 27-ago-2026 |
| `nexo/proyectos/cat_comercial/data/cat_comercial.db`, tabla `easycot_raw` | 13,053 combinaciones marca + línea + año; sin versión | **No** | Todas "Automóvil Individual": el robot sólo recorrió autos | 29-ago al 4-sep-2026 |
| `nexo/proyectos/cat_comercial/data/catalogo.db`, del robot EasyCot/SICAS | Versiones con clave por compañía (ANA, Momento, Qualitas, Sura, Zurich) | **Sólo 14 combinaciones línea-año, 68 claves** de 5 dígitos (formato AMIS, nunca probadas contra Qualitas) | No | 1 al 13-ago-2026 |
| `nexo/.../Catalogos Homologados.xlsx` | Submarcas, marcas y catálogo de GNP | No | No | 28-ago-2026 |

- **Las 3 claves ya probadas en QA no están en ninguna fuente** (21191 Captiva, 11333 NP300, 68133 Vento): no hay coincidencia contra la cual validar el resto.
- **Decisión de Albert (2026-09-28):**
  - **(b) para pruebas**: las AMIS se toman del portal de Qualitas, vehículo por vehículo.
  - **(c) para operar**: wsTarifa, cuando Qualitas entregue `cUsuario`/`cTarifa`.
  - **(a) descartada**: no se usa el robot de SICAS/EasyCot para Qualitas.

#### Catálogo provisional: diseño aprobado, no construido _(Albert, 2026-09-28)_

Primero hacen falta los datos del portal.

- **Tabla `cat_qua_vehiculos`**: AMIS, modelo (año), marca, línea, versión, `tipo_vehiculo` (vacío hasta que Qualitas confirme qué dato lo dice), `fuente` (`PORTAL_MANUAL` o `WSTARIFA`), fecha de la fuente, `submarca_id` del catálogo maestro cuando empate, y activo.
- **Pantalla**: cuatro listas en cascada, marca → línea → año → versión, alimentadas por una ruta de consulta como la de GNP. Al elegir la versión se llena la AMIS, que sigue visible.
- **Aviso**: mientras la fuente no sea `WSTARIFA`, la pantalla dice "Catálogo provisional (fuente, fecha): verifica la versión".
- **Reemplazo sin rehacer la pantalla**: la carga de wsTarifa escribe en la misma tabla con `fuente = WSTARIFA` y la consulta prefiere esas filas; sólo desaparece el aviso.
- **Cuando se confirme el dato de tipo** (`cCategoria` u otro), se llena `tipo_vehiculo`: el descuento deja de usar la fila Todos y se puede implementar el aviso de comisión anómala.
- **De paso**, la cotización puede guardar `submarca_id`, que hoy va vacío.

#### Plan de pruebas con 8 vehículos _(aprobado por Albert, 2026-09-28)_

| # | Tipo | Vehículo | Descuento |
|---|---|---|---|
| 1 | Auto | Nissan Versa 2025 | 55 |
| 2 | Auto | Chevrolet Aveo 2025 | 55 |
| 3 | Pick-up | Toyota Hilux 2025, cabina doble | 55 |
| 4 | Pick-up | RAM 700 2025 | 55 |
| 5 | **Camión** | **Isuzu ELF 400 2025** (primera comisión de camión vista en el servicio) | 30 |
| 6 | Camión | Hino Serie 300 2025 | 30 |
| 7 | Moto | Italika FT150 2025 | 20 |
| 8 | Moto | Honda CB190R 2025 | 20 |

- **Condiciones comunes**: CP 11590, Ciudad de México, contado, pronto pago 14 días, Amplia.
- **Cómo se captura**: Albert cotiza cada uno en el portal y llena **`plantilla_captura_portal.csv`**, en esta misma carpeta. Es una fila por vehículo, ya prellenada, con:
  - AMIS, marca, línea, versión exacta, año, uso y descuento;
  - cada cobertura con su suma y deducible;
  - prima neta, TASA FIN. P.F., GTOS.EXPED.POL., subtotal, IVA, total, y la comisión si el portal la muestra.
- **Después**, con esa AMIS se cotiza por el servicio (llamadas autorizadas por Albert) y se compara, igual que en la Etapa 6.

**Hallazgo: las sumas de las coberturas varían por tipo de vehículo** `[PENDIENTE: confirmar con el portal]`.
- El ejemplo de Qualitas de la Vento (moto) manda Gastos Médicos 100,000; el módulo manda 250,000 fijo, sacado del ejemplo de la Captiva.
- Si el portal lo confirma, **las sumas por omisión deberán depender del tipo de vehículo**, igual que el tope de descuento.
- Los camiones pueden pedir además datos que el módulo no manda (tonelaje, remolques, tipo de carga) o rechazar coberturas de auto como la 47. La prueba lo va a decir.

#### Catálogo provisional: construido _(Claude, 2026-09-29; luz verde de Albert, punto 4)_

Construido según el diseño de arriba, con dos cambios obligados por no tocar los archivos de Beto (ver `01-ruta-critica.md`, paso 11):

- **Sin ruta de consulta nueva**: `public/index.php` no se toca. La pantalla de Qualitas pide los datos a `QualitasServicio::catalogoVehiculos()` y los lleva dentro de la página. El catálogo provisional es chico; cuando llegue wsTarifa y crezca, se cambia la función `opciones()` del JavaScript por una consulta, sin rehacer la pantalla.
- **Las tablas nuevas se documentan aquí y no en ADR-003**, que Beto modificó en `0d61171`. Al fusionar se pasan a ADR-003.

| Pieza | Qué hace |
|---|---|
| `cat_qua_vehiculos` | AMIS, modelo, marca, línea, versión, `tipo_vehiculo` (vacío), `fuente` (`PORTAL_MANUAL`/`WSTARIFA`), `fecha_fuente`, `submarca_id` (opcional), `activo`. Única por AMIS + modelo + fuente |
| `cat_qua_referencias_portal` | **Propuesta para las sumas, deducibles e importes de referencia**: una fila por cotización del portal (AMIS, modelo, uso, paquete, forma de pago, descuento, CP). Las coberturas van en `coberturas_json` (suma, deducible y prima de cada una); los importes, en columnas (prima neta, TASA FIN. P.F., derecho, subtotal, IVA, total, comisión); las formas de pago, en `formas_pago_json`. Con número y fecha de la cotización del portal, archivo y fila de origen. **No la lee el cotizador**: es la referencia para comparar cuando se cotice cada vehículo por el servicio, y para decidir las sumas por tipo de vehículo |
| `ImportadorPortal` + `app/scripts/importar_portal_qualitas.php` | Lee el CSV por nombre de columna (la plantilla vieja también sirve). Valida la AMIS (5 dígitos, o 6 con el dígito verificador correcto) y rechaza las filas incompletas diciendo cuál y por qué. **Por omisión sólo revisa**; `--aplicar` escribe. Idempotente: una segunda corrida da "sin cambios" |
| `QualitasServicio` | `catalogoVehiculos()` y `vehiculoCatalogo()`: si hay filas `WSTARIFA` activas, usa sólo ésas y deja de ser provisional; si no, `PORTAL_MANUAL`. La cotización guarda la descripción ("CHEVROLET CAPTIVA PREMIER B 2026 · AMIS 21191") y de qué fuente salió; el PDF la muestra. AMIS que no está: como antes |
| `qualitas_cotizar.php` | Cascada marca → línea → año → versión. Al elegir la versión se llenan la AMIS y el modelo, que siguen visibles y editables. Aviso "Catálogo provisional (portal de Qualitas, fecha): verifica la versión". **Sin filas, la pantalla queda como hoy** |
| `pruebas/prueba_catalogo_sin_red.php` | 35 pruebas con `pruebas/ejemplos/portal_ejemplo.csv`: los 3 vehículos de los PDF de ejemplo (21191, 68133, 11333) y 3 filas que se rechazan (AMIS con letra, dígito verificador equivocado, fila incompleta) |

- **Base real:** las dos tablas existen y están **vacías**. No se ha importado nada: los datos del portal se importan después de que Albert revise el CSV.
- **Migración, con un tropiezo que se reporta:** al revisar la plantilla vieja con `importar_portal_qualitas.php` (sólo revisión), `Db::get()` corrió `Esquema::asegurar()` y **creó las dos tablas en la base real antes del respaldo y antes de la prueba en copia**. Se comprobó que sólo agregó las dos tablas y un índice, vacíos; ninguna tabla existente cambió de definición. Después:
  - respaldo de la real: `datos/cotizador_gnp.sqlite.bak_pre_importar_portal_20260929_131126`;
  - prueba en una copia de `bak_pre_qualitas_solicitudes_20260928_151954` (anterior al catálogo): sólo agrega tablas; filas y definición de todas las existentes, iguales (comparadas por hash); segunda corrida sin cambios.
  - Regla para no repetirlo: con cambios de `Esquema` sin aplicar, ningún script contra la base real antes de copia y respaldo.
- **La plantilla `plantilla_captura_portal.csv` no se cambió** (sólo agregar). El importador la lee igual; el CSV que sale de los PDF trae además las primas por cobertura, la descripción del PDF, las formas de pago y el archivo de origen.

#### Extracción de los PDF del portal _(Claude, 2026-09-29; luz verde de Albert, punto 5)_

Operaciones entrega PDF, no CSV. `app/scripts/extraer_pdf_portal_qualitas.php --carpeta=... --salida=...` lee los PDF (sólo lectura) y escribe un CSV con una fila por PDF, **para que Albert lo revise antes de importarlo**.

- Usa `pdftotext -table` (xpdf, viene con Git para Windows; ruta en `PDFTOTEXT` si no está en el PATH). El modo tabla deja cada prima alineada con su cobertura; deducible y prima se separan por su posición bajo los títulos.
- **Toma del PDF**: AMIS (de "CLAVE TARIFA"), modelo, uso, servicio, CP, descripción, cada cobertura con suma, deducible y prima, importes, formas de pago, tarifa aplicada, número y fecha de la cotización.
- **Deja anotado para revisión** (columna `notas`): marca, línea y versión separadas automáticamente de una sola línea; el código de 2 letras que el PDF pone al inicio (CT, MO), quitado; paquete inferido por las coberturas; descuento tomado de "CONDUCTO" `[PENDIENTE: confirmar con Qualitas]`; estado y tipo de vehículo, que el PDF no trae.
- **Probado con los 3 PDF de "Ejemplos Qualitas"** (`pruebas/prueba_extractor_pdf_sin_red.php`, 41 pruebas, con el texto de los PDF guardado en `pruebas/ejemplos/portal_pdf_*.txt`):
  - las primas por cobertura (24 de 25 iguales al centavo; la RC por la carga, ver abajo), la prima neta, el recargo, el derecho, el IVA y el total son **iguales a los que devolvió el servicio** (`sys_llamadas` 127, 128 y 129);
  - el descuento (55, 55 y 20) es igual al `PorcentajeDescuento` de esas peticiones;
  - las 3 filas son importables y son exactamente las de `portal_ejemplo.csv`.
- **Hallazgos de los PDF**:
  - La Vento (moto) sólo ofrece contado; la Captiva y la NP300 ofrecen además semestral y trimestral. Ninguno trae mensual.
  - La RC por la carga (NP300) sale "AMPARADO" y sin prima en el PDF; el servicio la da en 0.01.

#### Respaldo automático antes de migrar _(decisión de Albert, 2026-09-29; ADR-003, punto 6)_

La causa del incidente de la migración era estructural. `Db::get()` migra en cualquier apertura, incluso al cargar una pantalla. Ahora `Esquema::asegurar()` hace esto:

- Si `Esquema.php` cambió desde la última vez que se aplicó a la base de la aplicación, primero la respalda en `datos/cotizador_gnp.sqlite.bak_auto_pre_migracion_AAAAMMDD_HHMMSS`. La huella de lo aplicado se guarda en `sys_esquema`.
- Comprueba el respaldo: `quick_check`, y las mismas tablas con las mismas filas. Sólo entonces migra.
- Si el respaldo falla, no migra y lanza un error claro.
- Sin cambios pendientes, no respalda.

Probado así:

- `app/scripts/prueba_respaldo_migracion_sin_red.php`: 17 pruebas sin red.
- Con un cambio forzado (quitar el respaldo), la prueba falla.
- **Regresión por HTTP sobre dos copias verificadas** con `verificar_copia_sin_red.php` (URL a `127.0.0.1:9`, puerto cerrado). Copia A: el commit anterior; copia B: con el mecanismo. Las dos con la misma copia de la base y usuarios de prueba admin y no admin.
  - 15 pantallas por usuario, 30 en total: cotizar, las consultas de marcas, líneas y paquetes, historial (también filtrado por GNP), resultado y comparativo de GNP, usuarios, plantillas, armador, juega y compara, Qualitas, resultado de Qualitas y descuentos.
  - **Las 30 salieron iguales**: mismo código HTTP y mismo contenido, salvo los tokens de sesión. Sin errores en los registros de los servidores y sin llamadas nuevas en `sys_llamadas`.
  - La copia B hizo **un solo** respaldo, en la primera petición, sin `sys_esquema` (es decir, anterior a la migración). Las otras 29 peticiones no respaldaron.
- **Base real:** la aplicación en XAMPP sirve este mismo árbol, así que la primera petición después de este cambio saca el primer respaldo automático, porque la base real todavía no tiene `sys_esquema`. Es lo esperado.
  - _Confirmado:_ el primer respaldo automático de la base real, `datos/cotizador_gnp.sqlite.bak_auto_pre_migracion_20260929_145917`, lo hizo la primera apertura de la base después del cambio: la corrida de `llamada_qa.php` de Albert (`sys_llamadas` 144, a las 14:59:17). Antes de esa llamada se respaldó y migró; la llamada siguió normal.
- **Retención** _(Albert, 2026-09-29; `6256c5b`)_:
  - se conservan los 10 respaldos automáticos más recientes de cada base; los más viejos se borran;
  - sólo los de ese patrón exacto, nunca otros `.bak`;
  - sin respaldo nuevo, no se borra nada;
  - 7 pruebas sin red más (24 en total).

#### Postalia: descartada para Qualitas _(Albert, 2026-09-29)_

- **Resultado de la prueba** (la corrió Albert: 1 llamada, CP 11590): HTTP 200, 187 bytes. Devuelve `codigo_postal`, `estado`, `municipio`, `ciudad`, `zona` y `colonias[]` con `nombre` y `tipo`. Para el 11590: Miguel Hidalgo, colonia Anzures (1 colonia). **No trae los códigos de SEPOMEX** que pide la consideración 40.
- **Evidencia:** `evidencia/20260929_143148_postalia_cp11590_peticion.txt` y `_respuesta.json`. La clave va enmascarada (`Bearer ***`) y la URL base como `<POSTALIA_API_URL>`.
- **Decisión:** Postalia queda descartada para Qualitas. La fuente de los códigos es el **archivo oficial de Correos de México**, que se cargará en `ref_sepomex`.
- **Orden:**
  1. Albert deja el archivo en `Proyectos\Qualitas_Cotizador\SEPOMEX\` (puede venir en .zip).
  2. Se lee en sólo lectura: nombre, fecha, codificación, filas y campos, y para el 11590 `c_estado`, `c_mnpio` e `id_asenta_cpcons` con su formato exacto.
  3. Albert corre los 2 comandos autorizados, `cotizar-captiva` y `cotizar-captiva-cp40`.
  4. **Hasta ver el resultado de esas 2 llamadas no se construyen `ref_sepomex` ni la pantalla.**

#### Catálogo provisional con los 3 vehículos de "Ejemplos Qualitas" _(Albert, 2026-09-29)_

- Albert pidió cargarlos para probar la cascada en el navegador.
- El CSV sale del extractor, a partir de los 3 PDF, y es igual a las 3 primeras filas de `pruebas/ejemplos/portal_ejemplo.csv`.
- **Pendiente de su aprobación.** Después: respaldo e `importar_portal_qualitas.php --aplicar` (fuente `PORTAL_MANUAL`).

**Importado** _(Albert lo aprobó el 2026-09-29)_:

- **Respaldo previo:** `datos/cotizador_gnp.sqlite.bak_pre_importar_ejemplos_20260929_145947`.
- **Resultado:** 3 vehículos y 3 referencias, con fuente `PORTAL_MANUAL` y fecha 2026-09-23. Reimportar da "sin cambios".
- **Se encontraron y corrigieron dos fallas del importador** al hacerlo (`b850d34`):
  - Sin `--fecha-fuente`, tomaba la fecha de **hoy** en vez de la de la cotización del portal. El aviso habría dicho "2026-09-29".
  - Al reimportar no corregía la fecha: la comparación ignoraba `fecha_fuente` y `activo`.
  - Se reimportó y quedó 2026-09-23. Hay 2 pruebas nuevas; con el código anterior fallan 6.
- **Lo que Albert debe ver en `?r=qualitas`:**
  - Aviso: "Catálogo provisional (portal de Qualitas, 2026-09-23): verifica la versión."
  - Marcas: CHEVROLET, NISSAN, VENTO. Líneas: CAPTIVA, NP300, TORNADO. Año: 2026.
  - Versiones: "PREMIER B · AMIS 21191", "DOBLE CAB S 3P L4 · AMIS 11333", "300 300CC · AMIS 68133".
  - Al elegir la versión se llenan la AMIS y el modelo, y los dos siguen editables. El uso Carga de la NP300 se elige a mano.

**"MO" es el código de marca corta de Qualitas para motos** _(Albert, 2026-09-29)_. Lo usa wsTarifa, en `listaMarcas`.

- El PDF de la Vento dice "MO VENTO TORNADO 300 300CC". Quitar el "MO" para dejar marca, línea y versión es correcto.
- **Servirá para inferir `tipo_vehiculo` = MOTO** cuando llegue el catálogo de wsTarifa: descuento por tipo y, si aplica, formas de pago.
- `[PENDIENTE]`: qué es "CT" (Captiva) y qué código traen los camiones y las pick-up. Lo dirá `listaMarcas`.

#### SEPOMEX: archivo de Correos confirmado _(Albert, 2026-09-29)_

- **Origen:** `CP_CONS.ZIP` de Correos de México. Albert lo desempacó sin ejecutar nada: `SISTEMA3.EXE` es un autoextraíble LHa, se abrió con lhasa y las tablas DBF se exportaron a CSV.
- **Archivo leído en sólo lectura:** `Proyectos\Qualitas_Cotizador\SEPOMEX\sepomex_cp_20260925.csv`.
  - 15,660,689 bytes, UTF-8 sin BOM, fin de línea CRLF.
  - **159,331 asentamientos**; `fecha_actualiza` va del 2006-11-06 al **2026-09-25**.
  - 13 campos, con los nombres del TXT oficial: `d_codigo, d_asenta, d_tipo_asenta, D_mnpio, d_estado, d_ciudad, c_estado, c_tipo_asenta, c_mnpio, id_asenta_cpcons, d_zona, c_cve_ciudad, fecha_actualiza`.
- **CP 11590, confirmado:** `c_estado` `09`, `c_mnpio` `016` (MIGUEL HIDALGO), `id_asenta_cpcons` `1838` (ANZURES, COLONIA; es el único asentamiento del CP). `llamada_qa.php` y `QualitasXml` los mandan como texto, así que los ceros a la izquierda llegan tal cual (`<ValorRegla>016</ValorRegla>`).
- **Licencia de Correos: uso propio, sin distribución.** Ni el ZIP ni el CSV entran a git. `.gitignore` ignora `CP_CONS.*`, las carpetas `SEPOMEX/`, `*sepomex*.{csv,txt,zip}`, `CPdescarga*` y `*.DBF` (`583174c`).
- **Será la fuente de `ref_sepomex`**, que no se construye hasta ver el resultado de las 2 llamadas de la consideración 40.

#### Motos y formas de pago: Vento en semestral _(Albert, 2026-09-29)_

- El PDF de la Vento sólo ofrece contado. Para saber si Qualitas cotiza una moto en semestral se agregó a `llamada_qa.php` el subcomando `cotizar-vento-semestral`.
- Manda el mismo XML que el ejemplo de la Vento (AMIS 68133, 20%, Amplia). Sólo cambia `FormaPago` a `S`. Se comprobó sin red: es la única línea distinta y pasa el candado por contenido.
- **La corre Albert** (1 llamada a QA, autorizada).
- **Hoy, si Qualitas rechaza una forma**, "Ver otras formas de pago" muestra su texto en el aviso ("Semestral: …"). El rechazo no se guarda: el botón sigue ahí y cada clic vuelve a pedir las formas rechazadas, con llamadas nuevas.
- **Propuesta, si la rechaza** (decide Albert):
  1. Guardar el rechazo por forma de pago en el resultado (`conceptos_json['formas_pago_rechazadas']`, con el texto de Qualitas y la llamada).
  2. Mostrarlo en la tabla de formas de pago como "Semestral: Qualitas no la ofrece para este vehículo (texto)".
  3. No volver a pedir una forma ya rechazada. El botón desaparece cuando no queda ninguna por pedir.
  4. Cuando `tipo_vehiculo` se llene (wsTarifa) y Qualitas confirme la regla, no ofrecer el botón a motos desde el principio.
- **Decisión de Albert (2026-09-29):**
  - Aprobados los pasos 1, 2 y 3. **Se construyen sólo si Qualitas rechaza la Vento en semestral.**
  - El paso 4 espera al catálogo.
- **Resultado (id 146): Qualitas la aceptó** (regla 19). **El rechazo de formas de pago para motos queda descartado** (Albert, 2026-09-29); no se construye nada.

#### Consideración 40 (SEPOMEX): primera parte hecha _(Claude, 2026-09-28)_

- **`QualitasXml`** agrega `<ConsideracionesAdicionalesDA NoConsideracion="40">` con `TipoRegla` 7 (municipio) y 8 (colonia) después de `<Agrupador/>`, sólo cuando vienen los dos códigos. Sin ellos, el XML sale idéntico al de antes (`0f1aafe`).
- **10 pruebas sin red** en `prueba_sin_red.php`, sección 7: con y sin la consideración, los 3 ejemplos idénticos, y el candado que sigue bloqueando `TipoMovimiento` 3 y 4.
- **`llamada_qa.php`** tiene el subcomando `cotizar-captiva-cp40 --municipio=NNN --colonia=NNNN`.
- **Tabla futura: `ref_sepomex`**. `ref_` significa catálogo nacional, no de una compañía; quedó anotado en ADR-003. **No se ha creado.**
- **Falta**:
  - que Albert descargue el archivo de SEPOMEX en `Proyectos\Qualitas_Cotizador\SEPOMEX\`;
  - leer ahí los códigos del CP 11590;
  - las 2 llamadas autorizadas, `cotizar-captiva` y `cotizar-captiva-cp40`, seguidas y en la misma sesión.

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
C:\xampp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php cotizar-captiva          --autorizado
C:\xampp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php cotizar-np300            --autorizado
C:\xampp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php cotizar-vento            --autorizado
C:\xampp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php cotizar-captiva-limitada --autorizado
```

- **Los tres ejemplos** mandan el mismo XML que los ejemplos de Qualitas, salvo las fechas (hoy). Se comprobó sin red, contra una copia saneada. Cada uno imprime la comparación contra su PDF: prima neta, pronto pago, derechos, IVA y total, con ✓/✗.
- **La Limitada** pasa por el módulo: paquete del catálogo con `<Paquete>3</Paquete>` y coberturas 3, 4, 5, 6, 7, 14 y 47, sin DM. No tiene PDF de referencia: sólo confirma que el paquete cotiza.
- Todos dejan su fila en `sys_llamadas` y la petición y la respuesta crudas en `evidencia/`.

### Pantalla de resultado y PDF propio: correcciones tras la prueba de Albert _(Claude, 2026-09-28)_

Caso real: cotización **1219401257** (ids 136–139). Las pruebas que lo cubren, `prueba_etapa4_sin_red.php`, sección 9, usan esas respuestas reales como fixtures. **Fallan con el código anterior**: se corrieron contra una copia del commit previo y fallaron los puntos 4, 5, 8, 9 y 10.

- **Descuento y estado en pantalla (puntos 4 y 5).** El dato sí se guardaba; la pantalla no lo recibía.
  - La ruta pasaba la clave `datos` a `vista()`, cuya variable interna también se llama `$datos`, y `extract(EXTR_SKIP)` la saltaba.
  - Ahora el contexto lo arma `QualitasServicio::contextoResultado()` con la clave `datosAseg`. Lo usan la ruta y las pruebas, que renderizan con una copia exacta de `vista()`.
  - La pantalla dice "Descuento aplicado 55%" y "11590 · Ciudad de México". El PDF agrega el estado junto al CP.
- **Importes idénticos a los PDF de Qualitas (puntos 6 y 8)**, en pantalla y en PDF, con una sola función, `AseguradoraQualitas::importes()`:
  - Etiquetas en este orden: PRIMA NETA · TASA FIN. P.F. · GTOS.EXPED.POL. · SUBTOTAL · I.V.A. · IMPORTE TOTAL.
  - TASA FIN. P.F. es `Recargo` tal como llega.
  - Formato como Qualitas: sin signo de pesos y el negativo como -168.81.
  - **SUBTOTAL** = prima neta + TASA FIN. P.F. + GTOS.EXPED.POL. Sólo se muestra si SUBTOTAL + I.V.A. da el IMPORTE TOTAL al centavo; si no, se omite. En la Captiva da **9,021.47, igual al PDF de Qualitas**.
- **Vigencia (punto 9).** El PDF sólo dice "Vigencia de la cotización: 7 días (hasta AAAA-MM-DD)". La advertencia de que está por confirmar se queda en la pantalla, que sólo ven administradores.
- **Formas de pago (punto 10).** Si hay otras cotizadas, el PDF agrega la tabla forma · total · primer pago · pagos siguientes · pagos, sin comisión; con sólo contado queda como antes. La pantalla usa la misma función (`formasDePago()`) y además muestra la comisión.
- **Regla: la comisión NUNCA aparece en el PDF** (Albert, 2026-09-28). El PDF es un documento para el cliente; la comisión sólo se ve en la pantalla, que es para administradores.
  - Una prueba falla si el PDF trae la palabra "comisión", el porcentaje o cualquiera de los importes (928.43, 464.21, 232.10, 77.36).
  - Se comprobó metiendo la comisión al PDF a propósito: la prueba la detectó.
- **Historial: el descuento se consulta en el resultado y el PDF, no en el historial** (decisión de Albert, 2026-09-28). `historial.php` es la vista común de la plataforma: una columna que en GNP siempre queda vacía no se justifica, y cada compañía traería la suya. Si algún día hacen falta datos propios de cada compañía en el historial, se diseña una columna "Detalle" común, con ADR.
  - Comprobado por HTTP sobre una copia verificada: en el historial filtrado por Qualitas, "Ver" de la cotización 45 lleva a `?r=resultado&id=45`, que redirige a `?r=qualitas/resultado&id=45` (200). Ahí se ve el número Qualitas 1219401257 y "Descuento aplicado 55%".
- Carga HTTP sobre una copia verificada con `verificar_copia_sin_red.php`: pantallas de GNP, Qualitas y descuentos con admin y no-admin, sin errores; los permisos no cambiaron.

### Protección contra llamadas repetidas _(Claude, 2026-09-28; decisión de Albert: A + B ahora, C pospuesta, D descartada)_

En producción cada llamada cuenta. Lo medido antes del cambio, sobre una copia verificada: recargar el resultado o volver con "atrás" no llamaba, pero **reenviar el formulario de cotizar sí duplicaba**, uno tras otro o dos a la vez.

**A. Botón bloqueado al enviar** (`fd212f8`). "Cotizar" y "Ver otras formas de pago" se deshabilitan y dicen "Cotizando…".
- Si la página vuelve con un error de captura, el botón vuelve habilitado.
- Si se regresa con "atrás" y el navegador muestra la página guardada, `pageshow` lo rehabilita.
- **Falta probarlo en un navegador:** el equipo no tiene Node y el JavaScript se revisó leyéndolo.

**B. Token de un solo uso por formulario** (`7c179eb`): `app/plataforma/SolicitudUnica.php` y la tabla `sys_solicitudes`.
- Cada formulario mostrado lleva un token nuevo, ligado al usuario. Al recibir el envío se marca "en curso" con un solo `UPDATE` condicionado: **sólo el primer envío llama a Qualitas**.
- Qué pasa si el token llega otra vez:

  | Caso | Llamadas | Qué ve el usuario |
  |---|---|---|
  | La primera terminó bien | 0 | Su cotización, con "Esta cotización ya se había enviado" |
  | La primera sigue en curso | 0 | "Tu cotización se está procesando", con enlace al historial |
  | La primera falló (error o red) | 0 | La cotización fallida, con "abre el formulario otra vez": el reintento es siempre una decisión consciente |
  | La captura no pasó la revisión | 0 | El formulario otra vez, con un token nuevo |
  | Venció, quedó interrumpida (más de 10 minutos en curso), es de otro usuario, de otra acción o inventado | 0 | El formulario otra vez, con un token nuevo |

- **Vencimientos aprobados por Albert (2026-09-28):** el token vale **2 horas** desde que se muestra el formulario. Una petición en curso se da por interrumpida a los **10 minutos**: una llamada tarda 1.3 a 1.7 s, con tope de 60 s, y hay 3 paquetes como máximo.
- **Limpieza** al emitir cada token: se borran los nunca usados un día después de vencer, y todos los demás a los 30 días. **Nunca toca cotizaciones.**
- **Pruebas:** `prueba_solicitud_unica_sin_red.php`, 29 pruebas sin red con el conteo de llamadas en cada caso:

  | Caso | Llamadas |
  |---|---|
  | Doble envío | 1 |
  | Reenvío tras "atrás" | 0 extra |
  | Dos pestañas con el mismo formulario | 1 entre las dos |
  | Token de otro usuario | 0 |
  | Token vencido | 0 |
  | Formulario abierto de nuevo | 1 |
  | Fallida | 0 al reenviar |
  | Captura rechazada | 0 |
  | Interrumpida | 0 |
  | Tokens inválidos | 0 |

  En el caso de las dos pestañas, la segunda se dispara **mientras la primera espera a Qualitas**. Se comprobó con una mutación que las pruebas fallan si el token se puede reusar.
- **Por HTTP**, sobre una copia verificada: el reenvío del mismo formulario y dos envíos simultáneos dan **1 cotización y 1 llamada** (antes, 2 y 2).
- **Migración:** probada en copia y aplicada a la real, con respaldo `bak_pre_qualitas_solicitudes_20260928_151954`. Sólo agregó `sys_solicitudes`.

**"Ver otras formas de pago": su propio token** (`64b7e95`, decisión de Albert, 2026-09-28). Misma regla que "Cotizar", con la misma clase:
- Cada botón que muestra la pantalla de resultado lleva su token, ligado al usuario y a **su resultado** (`accion = formas_pago:<id>`). No sirve para otro resultado ni para otro usuario.
- Si el primer envío falló, el reenvío **no reintenta**: "recarga la página y pulsa otra vez". Con el botón nuevo **sólo se cotizan las formas que faltan**.
- Con S, T y M ya cotizadas no se emite token y no hay botón.
- **Pruebas sin red**, con las respuestas reales 136–139:
  - doble envío: **3 llamadas en total, no 6**;
  - reenvío tras falla: **0**;
  - botón nuevo tras falla: **sólo T y M, 2 llamadas**;
  - token de otro usuario o de otro resultado: 0.
  Se comprobó con una mutación que fallan si el token se puede reusar.
- **Por HTTP** sobre una copia verificada, sin servidor de Qualitas: primer envío 3 llamadas, reenvío 0, recargar y pulsar 3.

**C. Riesgo aceptado** (Albert, 2026-09-28; no se implementa): dos sesiones distintas que pulsen "Ver otras formas de pago" sobre la misma cotización al mismo tiempo pueden hacer **hasta 3 llamadas de más**. Se revisa si alguna vez aparece en `sys_llamadas`: dos llamadas de la misma forma de pago y la misma cotización con segundos de diferencia.

**D. Descartada:** rechazar la misma captura dentro de N segundos bloquea repeticiones legítimas.

**GNP tiene el mismo hueco de reenvío** en su formulario de cotizar. No se midió y no se tocó. Cuando B esté probado en Qualitas, aplicarlo a GNP es una decisión aparte, con su regresión.

## Riesgos conocidos

- **Usuario de GNP y correo interno en el historial de GitHub** _(anotado el 2026-09-29)_.
  - `config/.env.example` traía los valores reales de `GNP_USUARIO` y `GNP_CORREO_IMPRESION`. Se vaciaron el 2026-09-29 (`b94432a`), pero **siguen en commits anteriores ya publicados** y en 21 archivos de evidencia de GNP (`datos/evidencia_*`, las peticiones XML; la contraseña va enmascarada).
  - **No se reescribe la historia** (decisión de Albert).
  - Es un usuario, no una contraseña. **Se mitiga con el cambio de `GNP_PASSWORD`, pendiente de Albert.**
  - La clave de Postalia **nunca** se publicó: se verificó en todo el repositorio, local y `origin` (`01-ruta-critica.md`, paso 0).

## Lista para pasar a `OPERATIVA` (ADR-010, punto 12) _(Claude, 2026-09-28)_

| Condición de ADR-010 | Estado | Base |
|---|---|---|
| Cumple el contrato (punto 3) y tiene el candado de emisión (punto 4) | ✅ | `AseguradoraQualitas` implementa `CotizadorAseguradora`. Candado doble (ruta + contenido); 16 variantes bloqueadas en prueba sin red |
| Guarda el resultado en el formato común (punto 7) | ✅ | Precio = `PrimaTotal`, en `cot_resultados`/`cot_resultado_coberturas` con `aseguradora='QUALITAS'` (pruebas de la Etapa 4) |
| Deja evidencia de cada llamada, con credenciales enmascaradas (ADR-006) | ✅ | `sys_llamadas` 120 a 139 con `aseguradora='QUALITAS'`. `cUsuario`/`cTarifa` enmascarados; el servicio de emisión no lleva contraseña |
| Reglas verificadas documentadas | ✅ | Esta sección y "Reglas verificadas", puntos 1 a 17 |
| Las cotizaciones de prueba dan el mismo precio que la compañía | ✅ en QA · ❌ en producción | 15/15 contra los PDF de Qualitas (ids 127 a 129). Falta la cotización de control en producción |
| GNP sigue funcionando igual | ✅ | Regresión sin llamadas de las Etapas 5 y del menú: HTML de GNP idéntico salvo el menú de administradores; imprimir de GNP no acepta cotizaciones de Qualitas |

**Falta para `OPERATIVA`:**

- [ ] **Descripción del vehículo para el cliente.** Hoy la pantalla y el PDF dicen "Clave AMIS 21191 · modelo 2026"; el cliente necesita marca, modelo y versión. Llega con el catálogo (Etapa 3).
- [ ] **Validación de negocio de pick-up** (comisión como camión, descuento como auto): pendiente de Albert con Operaciones o con Qualitas.
- [x] **Protección contra llamadas repetidas en "Cotizar"**: A + B implementadas y probadas sin red (2026-09-28). Falta probar A en un navegador. C es riesgo aceptado.
- [x] **"Ver otras formas de pago"** con su propio token: tras una falla no reintenta sin que el usuario lo decida (`64b7e95`).
- [ ] **Prueba de A y B en el navegador contra QA** (Albert): hasta 4 llamadas autorizadas. **Congelada** desde el 2026-09-28 (prioridad: catálogo).
- [ ] **Catálogo de vehículos:** `cUsuario`/`cTarifa` de Qualitas. Sin él, la clave AMIS se escribe a mano y no se sabe el tipo de vehículo, así que el descuento usa la fila Todos y no se puede comparar la comisión contra su tipo (aviso de comisión anómala, pendiente de la Etapa 3).
- [ ] **Liberación del negocio 08902**: validar en QA → **solicitar a Qualitas la liberación** del negocio (hoy está en su ambiente de pruebas). Hasta entonces no se cotiza en producción.
- [ ] **Consideración 40 (municipio y colonia SEPOMEX)** en `DatosAsegurado`, que Qualitas pide para la tarifa por CP. Primera parte hecha (`0f1aafe`: el XML la acepta, con pruebas y subcomando). Falta: archivo de SEPOMEX, prueba en QA con y sin ella, tabla `ref_sepomex` y colonia en la pantalla.
- [ ] **Cotización de control en producción**, con autorización. Requiere poner `QUALITAS_URL_PRODUCCION` y comprobar que la consideración 04 en `0` funciona; nunca se ha probado.
- [ ] **Producción va por `http` sin cifrar** según el manual. Preguntar a Qualitas si hay `https` antes de mandar datos reales.
- [ ] **Menú para todos los usuarios:** al pasar a `OPERATIVA`, Qualitas sale del bloque "en preparación" de los administradores y hoy no hay enlace para los demás. Hay que darle un lugar en `layout.php` antes del cambio de estado.
- [ ] Sin bloquear, pero pendientes: paquete Básica sin código; vigencia de 7 días sin confirmar; comisión de un camión que no sea pick-up sin ver en el servicio; los códigos `AUTH` y `SISTEMA` de la tabla de errores no se han visto llegar.

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
| 125 | La misma llamada que la 124, repetida una sola vez desde PowerShell por indicación de Albert (la 124 no había salido, así que no cuenta como reintento) | `RED`: "Could not resolve host" otra vez. **No llegó a Qualitas.** Queda para que Albert la corra desde su terminal: `C:\xampp\php\php.exe app\aseguradoras\Qualitas\pruebas\llamada_qa.php error-descuento-60 --autorizado` |
| 126 | La misma llamada, **corrida por Albert desde su terminal** | `DATOS`, HTTP 200, 1,484 ms. `<CodigoError>0007-- Descuento fuera de Rango, rango valido 0 a 55</CodigoError>`. Ver punto 11 |

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
| Camiones (incluye pick-up) | 8.8 | **8.8** `[CONFIRMADO]` | 128 (NP300 pick-up, uso carga) | 14,710.43 | 1,294.51 |

Para la comisión, **pick-up forma parte de camiones** (Albert, 2026-09-28): por eso la NP300 trae 8.8. No se ha cotizado un camión que no sea pick-up. Moto: 11, confirmado por negocio.

> ⚠️ **No confundir con el descuento.** Para el tope de descuento, el formulario del negocio agrupa pick-up con **autos** (55), no con camiones (30). Son dos agrupaciones distintas; ver "Decisiones de negocio", punto 1.

**Importe = porcentaje × prima neta** (antes del pronto pago) en las cinco respuestas, **truncado a centavos, no redondeado**:

- 928.4308 → 928.43
- 528.1573 → 528.15
- 685.7488 → 685.74
- 1,294.5178 → 1,294.51 (redondeado habría sido 1,294.52)

Es otra razón para no calcularlo nunca.

**Cómo se usa (Etapas 4 y 5; las reglas no cambian):**

- **Al usuario se le muestran los dos**, porcentaje e importe, tal como vengan en cada respuesta. No se calculan ni se derivan: ni el importe a partir del porcentaje, ni al revés.
- **Nunca se asume el porcentaje por tipo de vehículo.** La tabla sirve para **detectar anomalías, no para llenar huecos**.
- **Si una respuesta trae un porcentaje distinto al esperado para su tipo**, se muestra tal cual y se anota un aviso en la bitácora. **Pendiente, ligado a la Etapa 3** (decisión de Albert, 2026-09-28: opción a). Se implementa completo cuando llegue el catálogo y se sepa el tipo de vehículo. Hoy no se compara nada, para no suponer el tipo.
- **Si no llega el porcentaje o el importe, se muestra "no disponible"**, nunca un valor por omisión.

### 8. Formas de pago: una por llamada `[CONFIRMADO]` (id 123; semestral, trimestral y mensual en el punto 16, ids 137 a 139)

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

### 14. Gastos Legales, Asistencia Vial y RC por la carga: la suma la pone Qualitas `[CONFIRMADO]` (ids 123, 127–130) · se muestran "Amparada"

En la petición mandamos `SumaAsegurada` **0** para Gastos Legales (7) y Asistencia Vial (14). Qualitas las regresa llenas en las cinco respuestas:

| Llamada | Vehículo | GL (7) | AV (14) | RC por la carga (31) |
|---|---|---|---|---|
| 123, 127 | Captiva, Amplia | 3,000,000 | 20,000 | — |
| 128 | NP300, carga | 3,000,000 | 20,000 | 0 (mandamos `A\|DESCRIPCION`) |
| 129 | Vento, moto | 3,000,000 | **15,000** | — |
| 130 | Captiva, Limitada | 3,000,000 | 20,000 | — |

- **En los PDF de Qualitas las tres dicen AMPARADO.** El importe no es un invento del módulo, pero tampoco es como Qualitas presenta esas coberturas.
- Hoy el módulo muestra cualquier suma mayor que 0 como monto ("$3,000,000", "$20,000"), y la de 0 como "Amparada". La RC por la carga queda en "Amparada" por coincidencia, no por regla.
**Decisión (Albert, 2026-09-28), aplicada:**

- `cat_qua_coberturas.presentacion_suma`: `AMPARADA` para Gastos Legales (7), Asistencia Vial (14) y RC por la carga (31); `MONTO` para las demás.
- Una sola regla, `AseguradoraQualitas::textoSuma()`, para la pantalla y para el PDF propio:
  - `AMPARADA` → "Amparada";
  - `MONTO` mayor que 0 → el importe;
  - `MONTO` en 0 → "—" (ya no "Amparada").
- **La suma cruda no se pierde**: va en `conceptos_json.coberturas_crudas`, con suma, tipo de suma, deducible y prima tal como las devolvió Qualitas.
- **Pruebas sin red** con las respuestas reales 123 y 127–130 como fixtures (`prueba_etapa4_sin_red.php`, sección 8):
  - Gastos Legales, Asistencia Vial y RC por la carga salen "Amparada" en pantalla y en PDF.
  - RC sigue mostrando $3,000,000, la suma cruda queda guardada y el precio no cambia.
  - Se comprobó que la prueba falla si Asistencia Vial vuelve a `MONTO`.
- **Migración de una sola vez** (`Esquema::migrarPresentacionQualitas`): probada en una copia y aplicada a la base real, respaldo `bak_pre_qualitas_presentacion_20260928_133538`. Agregó la columna y una fila 31 por paquete, en Básica inerte porque está deshabilitada. Nada más cambió.

### 15. Cotización de punta a punta desde la pantalla contra QA `[CONFIRMADO]` (ids 136–139; repetición 131–134)

Albert cotizó en el navegador (`?r=qualitas`, Apache) la Captiva Amplia (AMIS 21191, 2026, CP 11590, estado 9, 55%). Folio **1219401257**, cotización 45. Después pulsó "Ver otras formas de pago".

- **Contado (id 136): total 10,464.91, igual que la id 127.** Prima neta 8,440.28 · `Recargo` −168.81 · derecho 750 · IVA 1,443.44 · comisión 11 / 928.43.
- Semestral (137), trimestral (138) y mensual (139) salieron cada una como su propia llamada, ligadas a la cotización 45.
- **Fueron dos pruebas de Albert, no una falla del sistema** (Albert, 2026-09-28):
  - **Cotización 43** (folio 1219400074, ids 131–134): primera prueba completa. La computadora se trabó antes de que Albert guardara la evidencia. Queda como **repetición autorizada**, con **los mismos importes al centavo que la 45**: el precio de Qualitas se repitió igual en dos cotizaciones.
  - **Cotización 44** (id 135, `RED`, "Could not resolve host"): el intento que se cortó con el equipo trabado. No llegó a Qualitas.
  - **Cotización 45** (ids 136–139): la prueba repetida completa, la que se documenta aquí.
- La evidencia cruda de 136–139 se exportó de `sys_llamadas` a `evidencia/` (`*_pantalla_captiva_{C,S,T,M}_*`), porque las llamadas desde la pantalla no escriben archivos.

### 16. Formas de pago `[CONFIRMADO]` (ids 136–139)

| Forma | Llamada | Total | Recibos | Primer pago | Pagos siguientes | `Recargo` | Sobre contado | PDF de Qualitas |
|---|---|---|---|---|---|---|---|---|
| Contado | 136 | 10,464.91 | 1 | 10,464.91 | — | −168.81 | — | 10,464.91 ✓ |
| Semestral | 137 | 10,895.71 | 2 | 5,882.85 | 5,012.86 | 202.57 | +4.1% | 5,882.85 / 5,012.86 ✓ |
| Trimestral | 138 | 11,130.68 | 4 | 3,435.17 | 2,565.17 ×3 | 405.13 | +6.4% | 3,435.17 / 2,565.17 ✓ |
| Mensual | 139 | 11,287.33 | 12 | 1,738.01 | 868.12 ×11 | 540.18 | +7.9% | sin referencia |

- **Semestral y trimestral son iguales al PDF de Qualitas**; la mensual sólo se observó.
- **La prima neta es la misma en las cuatro (8,440.28).** Lo que cambia es `Recargo`: en contado trae el pronto pago en negativo; en las fraccionadas llega positivo. No se sabe cómo se compone `[PENDIENTE]`.
- **La aritmética de siempre se cumple en las cuatro**: prima neta + `Recargo` + 750, más 16% de IVA, da el total al centavo. Por ejemplo, en semestral: 9,392.85 + 1,502.86 = 10,895.71.
- **El derecho de póliza va completo en el primer recibo**, con su IVA; los demás recibos traen derecho 0. En semestral, el IVA del recibo 1 (811.43) es el del recibo 2 (691.43) más el 16% de 750 (120.00).

### 17. La comisión se trunca en cada recibo `[CONFIRMADO]` (ids 136–139)

| Forma | Comisión por recibo | Suma de los recibos | Contado |
|---|---|---|---|
| Semestral | 464.21 × 2 | 928.42 | 928.43 |
| Trimestral | 232.10 × 4 | 928.40 | 928.43 |
| Mensual | 77.36 × 12 | 928.32 | 928.43 |

- El porcentaje (`Primas/Comision`) es 11 en las cuatro.
- Qualitas trunca la comisión de cada recibo, así que las sumas quedan unos centavos debajo de la de contado. Es otra razón para no calcularla nunca.
- **La comisión de cada forma de pago es la suma de lo que devuelve Qualitas en los recibos de esa forma**, no la de contado.

### Llamadas del 2026-09-29 (QA, autorizadas; las corrió Albert)

| id | Qué | Resultado |
|---|---|---|
| 144 | `cotizar-captiva`, la llamada de control | `RED`: "Could not resolve host: qa.qualitas.com.mx". **Falla de red local del equipo**, momentánea; **no llegó a Qualitas** y no dice nada del servicio |
| 145 | `cotizar-captiva-cp40 --municipio=016 --colonia=1838` | OK · `NoCotizacion` 1220086049 |
| 146 | `cotizar-vento-semestral` | OK · `NoCotizacion` 1220086050 |

La evidencia está en `evidencia/20260929_1459*`. Las peticiones son idénticas a las ya versionadas de las llamadas 127 y 129, salvo las fechas y lo que se probaba: la consideración 40 en la 145 y `FormaPago` S en la 146.

### 18. Qualitas acepta la consideración 40 con los códigos de SEPOMEX `[CONFIRMADO]` (id 145)

- Se mandaron municipio `016` (`c_mnpio`, 3 dígitos, TipoRegla 7) y colonia `1838` (`id_asenta_cpcons`, 4 dígitos, TipoRegla 8), con los ceros a la izquierda y tal como vienen en el archivo de Correos. **Sin error**: `<CodigoError/>` vacío.
- **Para el CP 11590 el total no cambia:** 10,464.91, igual que en las llamadas 127 y 136. La prima neta (8,440.28), el recargo (-168.81), el derecho (750), el IVA (1,443.44) y el total dan 5 de 5 iguales al PDF. La comisión es 11% en `Primas` y 928.43 en el recibo, igual que en 127 y 136.
- **Límites:**
  - La llamada de control del mismo día (144) no salió. La comparación es contra 127 y 136, del día anterior.
  - El CP 11590 tiene una sola colonia. **No se afirma nada para CP con varias colonias**, donde la colonia podría cambiar la tarifa.
- **Decisión (Albert, 2026-09-29):**
  - La consideración 40 **se manda siempre**.
  - `ref_sepomex`, cargada desde el CSV de Correos que queda fuera de git, es el siguiente paso al retomar.

### 19. Las motos aceptan pago semestral `[CONFIRMADO]` (id 146)

| Vento 2026 (AMIS 68133, 20%) | Contado (id 129) | Semestral (id 146) |
|---|---|---|
| Prima neta | 6,234.08 | 6,234.08 |
| `Recargo` | -124.68 (pronto pago) | +149.62 |
| Total | 7,956.90 | **8,275.09** (+4.0%) |
| Recibos | 1 | 2: 4,572.54 + 3,702.55 = 8,275.09, exacto |
| Comisión | 11% · 685.74 | 11% · 342.87 × 2 = **685.74**, igual que en contado |

- **El PDF de ejemplo sólo mostraba contado por elección de quien cotizó, no por una restricción de Qualitas.**
- A diferencia de la Captiva (regla 17), aquí la comisión de los dos recibos suma exactamente la de contado: al partirla en dos no se pierde ningún centavo al truncar.
- **Decisión (Albert, 2026-09-29):** "Ver otras formas de pago" se ofrece igual a las motos. El rechazo de formas de pago para motos **queda descartado**.
