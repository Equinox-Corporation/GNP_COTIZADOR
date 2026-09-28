# Qualitas

Carpeta reservada para el adaptador de Qualitas (implementa `CotizadorAseguradora`).

Estado y lo que falta: [docs/aseguradoras/qualitas/00-estado.md](../../../docs/aseguradoras/qualitas/00-estado.md).

Contrato a cumplir: [ADR-010](../../../docs/02_Arquitectura/ADR-010-contrato-comun-de-modulos-por-aseguradora.md).

## Contenido

- `QualitasXml.php` — XML de cotización (`TipoMovimiento="2"`) y dígito verificador AMIS.
- `QualitasClient.php` — cliente SOAP con candado doble (ruta + contenido). Sólo cotiza.
- `pruebas/prueba_sin_red.php` — pruebas sin ninguna llamada: `php app/aseguradoras/Qualitas/pruebas/prueba_sin_red.php`.
- `pruebas/SIMULADO_*.xml` — respuestas inventadas para probar el parseo. **No son de Qualitas.**
- `AseguradoraQualitas.php` — el módulo visto desde la plataforma (contrato de ADR-010).
- `QualitasServicio.php` — guarda en las tablas comunes con `aseguradora='QUALITAS'`; otras formas de pago a pedido.
- `pruebas/prueba_etapa4_sin_red.php` — adaptador, resultado, descuento y formas de pago, sin red y en una base temporal.
- `pruebas/llamada_qa.php` — llamadas REALES a QA. Cada corrida necesita autorización explícita.
