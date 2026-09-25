# HDI — estado de la integración

Documento operativo. Última revisión: _(CC, 2026-09-24)_.

**Estado en la plataforma:** `PREPARADA`. Hay tarifa, pero no hay manual técnico ni credenciales.

## Lo que se recibió

`Proyectos\HDI_Cotizador\Kit_Imp WS Autos - TARIFA TRADICIONAL HDI (GLM).xlsx`, solicitud del 2026-09-01. Es el **formulario de alta** del web service más la tarifa. No es el manual técnico.

| Hoja | Contenido |
|---|---|
| Solicitud – Datos generales | Tipo: Nueva implementación · Alcance: **Cotización** y **Emisión directa** · Proveedor: Propio del agente · Tipo: Multicotizador interno |
| Datos tecnológicos | Tecnología: **PHP** · Contacto de sistemas: Manuel García · Catálogo vehicular: **consumo directo del servicio** · Pre-armado de paquetes: **Sí** · Validaciones 492 (33040–33045) |
| Condiciones de negocio | Condiciones generales, derechos y cláusulas vigentes en la tarifa; los descuentos se gestionan con el suscriptor |
| Tarifa 1 | Tarifa Tradicional **GLM**, ID 1, responsable de suscripción: Jose Alberto Lopez |
| Catálogos | Plataformas certificadas: APRO, SEGUNTREND, COPSIS, SEGURO COTIZAS, SICAS, MIURABOX |

### Paquetes de la tarifa

| Segmento | Amplia | Limitada | Básico | iDriving |
|---|---|---|---|---|
| Autos residentes | 19 | 21 | 22 | 2529 |
| Pick-up "Familiar" | 23 | 24 | 25 | 2530 |
| Pick-up "Comercial" | 23 | 24 | 25 | — |
| Motos residentes | 29 | 30 | 31 | — |

Cada cobertura trae: ID, tipo (Obligatoria / Obligatoria opcional / Opcional), deducibles permitidos, rango de suma asegurada, escalón y valor por omisión.

**Diferencias con GNP que la plataforma debe respetar en el módulo HDI** (ADR-010, punto 6):

- Tres niveles de cobertura, no dos.
- Suma asegurada por **rango + escalón** ("$750,000 a $5,500,000, de $50,000 en $50,000"), no lista cerrada. Se genera la lista a partir del rango.

## Lo que falta pedir a HDI (bloqueante)

- [ ] Manual técnico del servicio de cotización
- [ ] Tipo de servicio: la casilla "WS Generales / API" no está marcada
- [ ] Credenciales y clave de agente para WS (los datos generales del formulario están vacíos)
- [ ] Documentación del servicio de catálogo de vehículos
- [ ] Ambiente de pruebas

## Lo que hay que aclarar con HDI

- [ ] Pick-up Comercial usa los mismos IDs de paquete (23, 24, 25) que la Familiar, con coberturas distintas: ¿qué dato decide cuál aplica? (probablemente el uso)
- [ ] Catálogo de Estados/CP: la "X" quedó entre "HDI" y "SEPOMEX". ¿Cuál se eligió?
- [ ] Cobertura 753 "Pérdida total preferencial" aparece duplicada en Autos
- [ ] ¿Los IDs de paquete y cobertura de la tarifa son los mismos que usa el servicio?
- [ ] Si se mantiene "Emisión directa" en la solicitud, ¿la certificación exige las Validaciones 492 aunque sólo cotizemos?
- [ ] ¿Hay servicio de impresión del PDF de cotización? ("Impresión" no quedó marcada)

## Decisiones ya tomadas

- **Sólo cotización.** La marca de "Emisión directa" se deja en la solicitud, pero la plataforma no construye la emisión ([ADR-009](../../01_Generales/ADR-009-plataforma-de-cotizadores-por-aseguradora.md), punto 5).

## Reglas verificadas contra el servicio de HDI

_Ninguna todavía._ Aquí se irán anotando, como ADR-005 para GNP, con etiqueta `[CONFIRMADO]` sólo cuando se hayan visto responder de verdad.
