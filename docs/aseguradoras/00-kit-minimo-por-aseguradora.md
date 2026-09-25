# Kit mínimo que se pide a cada aseguradora

Documento operativo (no es ADR). Es la lista de lo que hace falta para integrar una compañía a la plataforma ([ADR-009](../01_Generales/ADR-009-plataforma-de-cotizadores-por-aseguradora.md), [ADR-010](../02_Arquitectura/ADR-010-contrato-comun-de-modulos-por-aseguradora.md)).

**Lección de HDI:** un formulario de alta con la tarifa **no es** la documentación para conectarse. Hay que pedir todo esto desde el primer correo.

## Bloqueante: sin esto no se programa

| # | Qué | Por qué |
|---|---|---|
| 1 | **Manual técnico del servicio de cotización** con direcciones (URL), formato de petición y respuesta, y ejemplos reales | Es lo que se programa |
| 2 | **Tipo de servicio:** SOAP/XML ("WS"), REST/JSON ("API") u otro | Cambia cómo se escribe el cliente |
| 3 | **Credenciales** y la clave de agente o intermediario con que se cotiza | Sin ellas no hay ni una llamada |
| 4 | **Catálogo de errores**: códigos y qué significan | Para clasificar en AUTH / DATOS / SISTEMA (ADR-010, punto 3) |
| 5 | **Cómo se obtiene el catálogo de vehículos**: servicio o archivo, y qué clave identifica al vehículo | La cotización se manda con esa clave |

## Muy importante

| # | Qué | Por qué |
|---|---|---|
| 6 | **Ambiente de pruebas (UAT)** | Con GNP todo se probó en producción. No repetirlo |
| 7 | **Matriz de paquetes y coberturas** con sus claves, sumas y deducibles permitidos | Para ofrecer sólo lo que la compañía acepta |
| 8 | **Servicio de impresión** del PDF de la cotización, si existe | Si no, se entrega el reporte propio |
| 9 | **Catálogo de CP / estados**: el suyo o SEPOMEX | Tarifican por CP |
| 10 | **Vigencia de la cotización** (días) | Se muestra y se bloquea al vencer |
| 11 | **Contacto técnico** para dudas de integración | Soporte durante la prueba |

## Aclarar siempre

- Que **por ahora sólo se cotiza**. Si el formulario de la compañía trae marcada la emisión, se puede dejar, pero la plataforma no la construye (ADR-009, punto 5).
- Qué dato decide el segmento: uso, tipo de vehículo, procedencia.

## Estado por compañía

| Compañía | Estado en la plataforma | Detalle |
|---|---|---|
| GNP | `OPERATIVA` | [ADR-005](../03_Decisiones/ADR-005-reglas-verificadas-gnp.md) |
| HDI | `PREPARADA` | [hdi/00-estado.md](./hdi/00-estado.md) |
| Qualitas | `PREPARADA` | [qualitas/00-estado.md](./qualitas/00-estado.md) |
| Zurich | `PREPARADA` | [zurich/00-estado.md](./zurich/00-estado.md) |
