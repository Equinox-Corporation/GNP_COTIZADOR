# ADR-009 — El proyecto es una plataforma de cotizadores, un módulo por aseguradora

## 📌 Estado

**Confirmado** (Albert, 2026-09-24). Producto/Negocio ✅ · TI/Arquitectura ✅.

**Reemplaza parcialmente a [ADR-001](./ADR-001-que-es-el-cotizador-gnp.md)**: sólo el punto 5 en su frase *"Habla con una sola aseguradora: GNP"* y la consideración futura *"Otras aseguradoras… sería otro proyecto y otra decisión"*. Todo lo demás de ADR-001 sigue vigente, incluida la frase *"No es un multicotizador"*, que aquí se precisa.

El diseño técnico que implementa esta decisión está en [ADR-010](../02_Arquitectura/ADR-010-contrato-comun-de-modulos-por-aseguradora.md).

## 🧠 Contexto

El proyecto nació para una sola aseguradora (GNP, kit IC260812018, agosto 2026) y se construyó con esa premisa: el cliente HTTP se llama `GnpClient`, las tablas no dicen de qué compañía son, y ADR-001 dejó escrito que no era un multicotizador.

En septiembre de 2026 llegó la documentación inicial de **HDI** (*Kit_Imp WS Autos – Tarifa Tradicional HDI (GLM)*) y ya se trabaja con **Qualitas** y **Zurich**. La pregunta pasó a ser cómo recibirlas.

Se evaluaron tres caminos (análisis del 2026-09-23):

| Camino | Resultado |
|---|---|
| **A. MultiCotizador completo** — un formulario, todas las compañías, comparativo entre ellas | Viable, pero exige resolver antes lo más caro: emparejar la versión del vehículo entre compañías y la equivalencia de coberturas. 3–4 semanas de preparación antes de la primera compañía nueva |
| **B. Un proyecto por aseguradora** (clonar el Cotizador GNP) | Descartado: cuatro bases de código que mantener, sin nada compartido |
| **C. Plataforma de cotizadores** — cada compañía es un módulo independiente dentro de la misma aplicación | **Elegido.** Aprovecha lo que ya existe, no toca lo que funciona y deja abierta la puerta al camino A |

### Qué es hoy la plataforma

Una **maqueta funcional**: una base real, conectada a producción y operable, pero todavía en crecimiento. Nació con GNP y ahora crece. El objetivo de esta decisión es que ese crecimiento sea **ordenado y controlado**, pensando en 3, 5, 10 o más compañías.

## ⚖️ Decisión

### 1. Un portal, un módulo por aseguradora `[CONFIRMADO]`

La aplicación se llama **Cotizador Equinox**. Cada aseguradora es un **módulo dentro de la misma aplicación**. No es una aplicación aparte ni una página incrustada en un marco (*iframe*).

- Mismo login, mismos usuarios, mismo historial.
- El usuario elige la compañía en el menú y trabaja en su módulo.
- El historial y los reportes comunes se separan con un **filtro por aseguradora**.

**Por qué módulos y no iframes:** un iframe significa aplicaciones separadas con su propio login, su propia base y su propio código. Justo lo que impediría compartir estructura o conectarlos después.

### 2. Cada compañía con sus datos, sus reglas y sus requerimientos `[CONFIRMADO]`

Cada módulo tiene lo suyo y no depende de los demás:

- su cliente de conexión con el servicio de la aseguradora;
- su catálogo de vehículos, tal como lo publica la compañía;
- sus paquetes, coberturas, sumas y deducibles;
- sus reglas de negocio verificadas (el equivalente de [ADR-005](../03_Decisiones/ADR-005-reglas-verificadas-gnp.md) para GNP);
- su pantalla de captura y sus credenciales.

Si una compañía falla o se retrasa, las demás siguen operando.

### 3. Se comparte la estructura, no el negocio `[CONFIRMADO]`

La plataforma comparte lo que es igual para todas: usuarios y administración, diseño y menú, historial, bitácora de evidencia ([ADR-006](../03_Decisiones/ADR-006-evidencia-y-bitacora.md)), generación de PDF y el candado de emisión. Cómo se comparte está en [ADR-010](../02_Arquitectura/ADR-010-contrato-comun-de-modulos-por-aseguradora.md).

### 4. No hay comparativo entre compañías `[CONFIRMADO]`

La frase de ADR-001 *"No es un multicotizador"* se mantiene, con esta precisión:

- **Sí** cotiza con varias compañías, cada una en su módulo.
- **No** compara compañías entre sí ni tiene un formulario único que alimente a todas.
- El Comparativo Multi-Plan existente sigue siendo **dentro de una misma compañía** (varios paquetes de GNP lado a lado).

### 5. Se cotiza, no se emite, en todas las compañías `[CONFIRMADO]`

El punto 3 de ADR-001 aplica a cada módulo: se cotiza y se imprime; no se emite, no se cancela, no se cobra. Cada cliente de conexión **no conoce** las rutas de emisión de su compañía y las bloquea si alguien las intenta usar.

Que el formulario de alta de HDI tenga marcada la *Emisión directa* no cambia esto. Esa marca se deja como está para no cerrar la puerta con la aseguradora, pero la plataforma no la construye. Construir la emisión exige un ADR propio, un ambiente de pruebas de la compañía y resolver sus validaciones (en HDI, las "Validaciones 492": RFC, SAT, lista negra y riesgo alto).

### 6. El MultiCotizador es un alcance futuro probable `[CONFIRMADO]`

Un MultiCotizador real (un formulario, todas las compañías, comparativo) **no está en el alcance de hoy**. Es una posibilidad con buena probabilidad y ambiciosa.

La plataforma se construye siguiendo las convenciones de [ADR-010](../02_Arquitectura/ADR-010-contrato-comun-de-modulos-por-aseguradora.md) para que ese paso sea "conectar lo que existe" y no "rehacerlo". Si con el tiempo esas convenciones no alcanzan, no es un fracaso de este ADR: lo aprendido sirve para un desarrollo con ese objetivo específico, cuando exista.

### 7. Se habla de "usuarios" `[CONFIRMADO]`

Quien opera la plataforma es un **usuario**, no un "vendedor". Documentación, pantallas y código nuevo usan ese término. Lo ya escrito se corrige conforme se toque, sin campaña de reescritura.

## ✅ Beneficios

- **Cada compañía sale cuando está lista.** HDI no espera a Qualitas ni Qualitas a Zurich.
- **GNP casi no se toca.** Lo que funciona en producción sigue igual.
- **Se evita por ahora lo más caro**, que sólo hace falta para comparar: emparejar la versión del vehículo entre compañías y la equivalencia de coberturas.
- **Un solo lugar para usuarios, evidencia e historial**, en vez de uno por compañía.
- **La puerta al MultiCotizador queda abierta** sin pagar hoy su costo.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| **Islas** | Que cada módulo se construya distinto y el MultiCotizador futuro no se pueda armar sobre ellos |
| **Código copiado** | La tentación de clonar el módulo de GNP para cada compañía nueva |
| **Captura repetida** | Para cotizar en tres compañías, el usuario llena tres formularios |
| **El problema difícil sólo se aplaza** | Versión del vehículo y equivalencia de coberturas siguen sin resolver para el futuro |
| **Conocimiento concentrado** | Más módulos, más reglas que saber, y hoy lo entiende una persona |

## 🛡️ Mitigaciones

- **Islas y código copiado:** las convenciones comunes de [ADR-010](../02_Arquitectura/ADR-010-contrato-comun-de-modulos-por-aseguradora.md), obligatorias para todo módulo nuevo. Aun así, se acepta el riesgo residual: si el MultiCotizador no se alcanza así, lo aprendido es útil (punto 6).
- **Captura repetida:** opción de reutilizar los datos del último solicitante entre módulos. Es barato y no requiere comparativo.
- **Problema aplazado:** queda documentado aquí como deuda consciente, no como olvido. Sólo se paga si se decide construir el MultiCotizador.
- **Conocimiento:** cada compañía tiene su carpeta de documentación en `docs/aseguradoras/` con su estado y sus reglas verificadas.

## 🧩 Consideraciones futuras

- **MultiCotizador:** formulario único, emparejamiento de versión de vehículo, catálogo maestro de coberturas y comparativo entre compañías.
- **Permisos por compañía:** que un usuario sólo vea ciertas aseguradoras.
- **Emisión:** por compañía, con ADR propio, cada una cuando tenga ambiente de pruebas.
- **Renombrar el repositorio/carpeta** `cotizador-gnp`: cosmético. Se decide cuando haya despliegue formal, no antes.

## 👥 Aprobación

- Producto / Negocio: ✅
- TI / Arquitectura: ✅

## Referencias

- [ADR-001 — Qué es el Cotizador GNP](./ADR-001-que-es-el-cotizador-gnp.md) (parcialmente reemplazado)
- [ADR-004 — Catálogo maestro propio](../03_Decisiones/ADR-004-catalogo-maestro-propio.md): su diseño radial es la base del MultiCotizador futuro
- [ADR-010 — Contrato común de los módulos por aseguradora](../02_Arquitectura/ADR-010-contrato-comun-de-modulos-por-aseguradora.md)
- [`docs/aseguradoras/`](../aseguradoras/00-kit-minimo-por-aseguradora.md): estado por compañía
- Kit HDI: `Proyectos\HDI_Cotizador\Kit_Imp WS Autos - TARIFA TRADICIONAL HDI (GLM).xlsx` (solicitud del 2026-09-01)
