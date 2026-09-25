# ADR-001 — Qué es el Cotizador GNP, cómo nació y para qué existe

## 📌 Estado

**Confirmado** (Beto, 2026-09-10). Producto/Negocio ✅ · TI/Arquitectura ✅.

Es el ADR fundacional: fija la identidad y el alcance del proyecto. Todo lo demás se apoya aquí.

> ⚠️ _(CC, 2026-09-24)_ — **Reemplazado parcialmente por [ADR-009](./ADR-009-plataforma-de-cotizadores-por-aseguradora.md).** Sólo cambia el punto 5 en la frase *"Habla con una sola aseguradora: GNP"* y la consideración futura *"Otras aseguradoras… sería otro proyecto y otra decisión"*: el proyecto pasa a ser una plataforma con un módulo por aseguradora, dentro de la misma aplicación. *"No es un multicotizador"* se mantiene (no hay comparativo entre compañías) y *"cotizar, no emitir"* aplica a todas. El resto de este ADR sigue vigente y no se reescribe.

## 🧠 Contexto

### De dónde viene

En agosto de 2026, GNP entregó a Equinox el **kit de conexión IC260812018** del Web Service Preferente — el canal que permite cotizar y emitir pólizas de autos por API, sin pasar por el portal de nadie.

El kit llegó en el contexto de **NEXO 2.0**, el CRM de Equinox. La pregunta original era si NEXO podía cotizar con GNP desde adentro. El análisis dijo que sí, y quedó documentado ahí como una integración más del CRM.

Al aterrizarlo apareció una tensión: NEXO es un CRM grande, con su pipeline comercial, su buzón de operaciones, sus roles y su conexión a SICAS. Meter ahí un cotizador que apenas se estaba descubriendo significaba arrastrar todo ese peso —migraciones sobre una base en producción, permisos, estados de oportunidad— para poder probar una llamada XML.

### Por qué se separó

Se decidió construirlo **como proyecto independiente**. Vive en su propia carpeta, con su propia base, su propia configuración y su propio repositorio (`Equinox-Corporation/GNP_COTIZADOR`).

Las razones, en orden de peso:

1. **Velocidad de aprendizaje.** GNP no dio ambiente de pruebas: todo se descubre contra producción. Iterar en un proyecto de siete carpetas es mucho más rápido que en un CRM con usuarios trabajando.
2. **Riesgo acotado.** Un error aquí no toca la base de NEXO ni las oportunidades reales de los vendedores.
3. **El conocimiento se gana igual.** Cada regla descubierta —cómo tarifica GNP, cómo se ve un error de contraseña, cuánto vive una cotización— sirve idéntico si mañana se integra al CRM.
4. **Puede valer por sí mismo.** Un cotizador web que funcione y sea rápido tiene valor para el vendedor aunque nunca se integre a nada.

> **La separación no es un divorcio.** Existe un puente en un sentido: `app/scripts/migrar_desde_nexo.php` lee el staging de NEXO para no volver a descargar de GNP lo que ya se había descargado. Sólo lee ese archivo, nunca lo modifica.

## ⚖️ Decisión

### 1. El Cotizador GNP es un proyecto independiente `[CONFIRMADO]`

Repositorio propio, base propia, despliegue propio, ciclo de vida propio. No es un módulo de NEXO ni depende de que NEXO exista.

### 2. Qué hace `[CONFIRMADO]`

Una aplicación web donde un vendedor de Equinox:

1. Elige un vehículo del catálogo local de GNP.
2. Captura al **solicitante** en una sola sección — su edad y su código postal son los que fijan el precio.
3. Marca los paquetes que quiere comparar.
4. Recibe, en **una sola llamada a GNP**, todos los paquetes con su precio y sus coberturas, lado a lado.
5. Con un botón se trae el PDF oficial de GNP.
6. Descarga un **Comparativo Multi-Plan** propio, en PDF o Excel, listo para el cliente.
7. Deja todo en el historial, con su vigencia de 15 días y su evidencia descargable.

### 3. Alcance: cotizar, no emitir `[CONFIRMADO]`

Se cotiza y se imprime. **No se emite, no se cancela, no se cobra.**

No es sólo una lista de tareas pendientes: es una decisión de diseño con consecuencia en el código. El cliente HTTP no conoce las rutas de emisión y las bloquea si alguien las escribe. Mientras GNP no entregue ambiente de pruebas, emitir por error significa una póliza real con recibo y comisión reales.

### 4. A quién sirve `[CONFIRMADO]`

Hoy, a los vendedores de Equinox, con usuario y contraseña propios y un panel de administración para altas y bajas.

La arquitectura ya contempla una **versión pública** —una pantalla de cotización abierta al cliente final— pero no está construida y falta decidir el flujo.

### 5. Qué NO es `[CONFIRMADO]`

- **No es un multicotizador.** Habla con una sola aseguradora: GNP. _(Reemplazado parcialmente por ADR-009: hoy hay un módulo por aseguradora, sin comparativo entre ellas.)_ Comparar entre aseguradoras es problema de NEXO y del robot de SICAS.
- **No es un sistema de pólizas.** La vida de la póliza vive en SICAS.
- **No es un CRM.** No hay prospectos, ni pipeline, ni seguimiento comercial.

## ✅ Beneficios

- Permitió descubrir en semanas el comportamiento real del servicio de GNP, con cotizaciones verdaderas contra producción, sin arriesgar el CRM.
- Cada regla aprendida queda documentada y es reutilizable si se integra a NEXO más adelante.
- El vendedor gana una herramienta funcional aunque la integración con el CRM tarde.
- Al ser chico, cualquier persona nueva lo entiende completo en una tarde.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| **Dos catálogos de vehículos en la empresa** | Este proyecto tiene el suyo y NEXO tiene el suyo. Si se desincronizan, un mismo coche se llama distinto en cada lado |
| **La contraseña es la personal del agente** | Es la del Portal de Intermediarios. Si expira o alguien la cambia, el cotizador deja de funcionar sin aviso |
| **Todo es producción** | No hay UAT. Cada prueba es real |
| **Duplicar esfuerzo con NEXO** | Si mañana NEXO cotiza GNP por su cuenta, habría dos implementaciones del mismo cliente XML |
| **Conocimiento concentrado** | Hoy lo entiende una persona |

## 🛡️ Mitigaciones

- El catálogo maestro (ver [ADR-004](../03_Decisiones/ADR-004-catalogo-maestro-propio.md)) está diseñado justamente para ser la referencia común y evitar la divergencia de catálogos.
- El error de autenticación se detecta como categoría propia por el campo `ORIGEN` del XML (ver [ADR-005](../03_Decisiones/ADR-005-reglas-verificadas-gnp.md)).
- El cliente HTTP no conoce las rutas de emisión — no es disciplina, es ausencia física.
- Esta biblioteca de ADR existe precisamente para que el conocimiento no viva en una sola cabeza.

## 🧩 Consideraciones futuras

- **Versión pública** para el cliente final. La arquitectura la contempla; falta definir el flujo y resolver a qué correo llega el PDF que manda GNP.
- **Reintegración con NEXO.** Si se decide integrarlo, lo natural es que este proyecto sea el servicio y NEXO el consumidor, no reescribir el cliente XML dentro del CRM.
- **Otras aseguradoras.** El catálogo maestro ya reserva columnas para Zurich, Momento y EBC. Convertir esto en multicotizador es posible, pero sería otro proyecto y otra decisión.
- **Emisión.** Requiere ambiente de pruebas de GNP y el servicio de cancelación NTU, que GNP pide expresamente para pólizas del mismo día.

## 👥 Aprobación

- Producto / Negocio: ✅
- TI / Arquitectura: ✅

## Referencias

- [ADR-002 — Stack y estructura](../02_Arquitectura/ADR-002-stack-y-estructura.md)
- [ADR-004 — Catálogo maestro propio](../03_Decisiones/ADR-004-catalogo-maestro-propio.md)
- [ADR-005 — Reglas verificadas contra GNP](../03_Decisiones/ADR-005-reglas-verificadas-gnp.md)
- `README.md` — instalación y operación del día a día
- Kit de conexión GNP **IC260812018** · correo `[CC033]` de Conectividad GNP, 2026-08-12
- Manual técnico WS Preferente v8.7.9 (99 páginas)
