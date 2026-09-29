# Qualitas

Módulo de Qualitas, **sólo cotización**. Estado en la plataforma: `EN_INTEGRACION` (desde el 2026-09-28). Implementa `CotizadorAseguradora`.

Estado, reglas verificadas y lo que falta: [docs/aseguradoras/qualitas/00-estado.md](../../../docs/aseguradoras/qualitas/00-estado.md).

Contrato a cumplir: [ADR-010](../../../docs/02_Arquitectura/ADR-010-contrato-comun-de-modulos-por-aseguradora.md).

## Contenido

- `QualitasXml.php` — XML de cotización (`TipoMovimiento="2"` fijo), dígito verificador AMIS y consideración 40 (municipio y colonia SEPOMEX) cuando viene.
- `QualitasClient.php` — cliente SOAP con candado doble (ruta + contenido). Sólo cotiza. Evidencia de cada llamada en `sys_llamadas`.
- `AseguradoraQualitas.php` — el módulo visto desde la plataforma (contrato de ADR-010). También arma los importes y las formas de pago igual en pantalla y en PDF, y el PDF propio.
- `QualitasServicio.php` — guarda en las tablas comunes con `aseguradora='QUALITAS'`. Otras formas de pago a pedido. Token de un solo uso en "Cotizar" y en "Ver otras formas de pago".

Lo común que usa, en `app/plataforma/`: `RangoDescuento.php` (rango de descuento) y `SolicitudUnica.php` (token de un solo uso).

Pantallas, en `app/vistas/`: `qualitas_cotizar.php`, `qualitas_resultado.php`, `qualitas_procesando.php` y `descuentos.php`.

## Pruebas

Sin red. Ninguna llama a Qualitas ni toca la base real:

```
C:\xampp\php\php.exe app\aseguradoras\Qualitas\pruebas\prueba_sin_red.php
C:\xampp\php\php.exe app\aseguradoras\Qualitas\pruebas\prueba_etapa4_sin_red.php
C:\xampp\php\php.exe app\aseguradoras\Qualitas\pruebas\prueba_solicitud_unica_sin_red.php
```

- `pruebas/ejemplos/` — los 3 XML de ejemplo de Qualitas.
- `pruebas/SIMULADO_*.xml` — respuestas inventadas para probar el parseo. **No son de Qualitas.** Las pruebas de las Etapas 4 en adelante usan respuestas **reales**, tomadas de `docs/aseguradoras/qualitas/evidencia/`.

## Llamadas reales

- `pruebas/llamada_qa.php` — llamadas REALES al ambiente de pruebas (QA). **Cada corrida necesita autorización explícita** y exige `--autorizado`. Se niega a correr si el ambiente no es QA.
- La URL de producción está vacía a propósito: no se cotiza en producción hasta que Qualitas libere el negocio.
- Antes de levantar cualquier copia del sistema, correr `app/scripts/verificar_copia_sin_red.php` ([reglas de trabajo](../../../docs/aseguradoras/00-reglas-de-trabajo.md)).
