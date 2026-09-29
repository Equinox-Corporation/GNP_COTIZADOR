# Qualitas — ruta crítica

Documento operativo. Se actualiza en cada reporte. Prioridad única: **Qualitas** (Albert, 2026-09-29).

El registro completo, las reglas verificadas y las decisiones están en [`00-estado.md`](./00-estado.md). Aquí sólo va el camino a `OPERATIVA` y dónde estamos.

**Última actualización:** 2026-09-29 _(Claude)_. Paro tras los puntos 0 y 1: veredicto de la clave y comando de Postalia entregados.

## Orden acordado

0 → 1 (comando para Albert) → 4 y 5 mientras Albert corre la prueba → 2 y 3 con el resultado.

| # | Paso | Estado | Qué falta / quién |
|---|---|---|---|
| 0 | `.env.example` sin valores reales | ✅ Hecho (`b94432a`) | — |
| 1 | Postalia: script de prueba del CP 11590 | ✅ Script listo (`app/scripts/prueba_postalia.php`), probado sin red | **Albert** corre el comando (1 llamada autorizada) |
| 2 | Consideración 40 en QA | ⏳ Espera el resultado del paso 1 | Si Postalia trae códigos: 2 comandos (`cotizar-captiva` y `cotizar-captiva-cp40`), autorizados, los corre Albert. Si no: archivo de Correos → `ref_sepomex` |
| 3 | Diseño Postalia + caché (`ref_sepomex`) y qué pasa si no responde | ⏳ Espera el resultado del paso 1 | Propuesta para que decida Albert |
| 4 | Catálogo provisional `cat_qua_vehiculos` (tabla, importador del CSV, cascada en pantalla, pruebas) | ⏳ Siguiente, mientras Albert prueba Postalia | Claude |
| 5 | Extracción de los PDF del portal → CSV | ⏳ Siguiente; se prueba con los 3 PDF de "Ejemplos Qualitas" | Claude. Después, Albert deja los PDF de Operaciones en `Proyectos\Qualitas_Cotizador\Portal\` |

## Paso 0 — veredicto de la clave de Postalia (2026-09-29)

Verificado sin imprimir valores:

- **La clave de Postalia no estaba en `config/.env.example`.** El archivo no tenía ninguna línea `POSTALIA_*`.
- **Nunca llegó a ningún commit.** Se buscó en todas las ramas y tags locales y de `origin` (actualizadas con `fetch`), en el stash, en el reflog y en los objetos sueltos: 0 coincidencias. Tampoco está en ningún otro archivo del proyecto ni en los `.env` de NEXO. **No hace falta rotarla por esto.**
- **Se agregaron `POSTALIA_API_URL=` y `POSTALIA_API_KEY=` vacías** a `.env.example`, como plantilla.
- **Otros valores reales que sí tenía `.env.example`:**
  - `GNP_USUARIO` (usuario del portal de GNP) y `GNP_CORREO_IMPRESION` (correo interno): **se vaciaron**. Siguen en el historial de git y en 21 archivos de evidencia de GNP (`datos/evidencia_*`, las peticiones XML; la contraseña va enmascarada). Esos archivos no se tocan (regla 3). Es un usuario, no una contraseña; ver pendiente #6 del índice.
  - Identificadores de negocio de GNP y Qualitas, URL de QA y una ruta local de NEXO: no son secretos y se dejaron.

## Paso 1 — Postalia

**Cómo la llama NEXO** (sólo lectura, `nexo/proyectos/nexo/app/modules/contactos/ContactosController.php`, `cpBuscar()`):

- `GET {POSTALIA_API_URL}/codigos-postales/{cp}`, con `Authorization: Bearer {clave}` y `Accept: application/json`.
- NEXO usa `data.estado`, `data.municipio`, `data.ciudad` y `data.colonias[].nombre`. Su comentario describe la respuesta como `{codigo_postal, estado, municipio, ciudad, zona, colonias:[{nombre,tipo}]}`, **sin códigos**. Puede que NEXO ignore campos: el script lista todos.
- ⚠️ En modo depuración, NEXO **desactiva la verificación SSL** mientras manda la clave. El script de este proyecto nunca la desactiva: usa el paquete de certificados de XAMPP, que ya está configurado en `curl.cainfo`.

**Script:** `app/scripts/prueba_postalia.php`.
- Exige `--autorizado`.
- Nunca imprime ni guarda la clave. En la evidencia la URL base se escribe como `<POSTALIA_API_URL>`.
- Guarda la respuesta cruda en `evidencia/`.
- Muestra todos los campos, los valores de estado, municipio y colonias, y responde la pregunta decisiva: **¿trae los códigos de SEPOMEX o sólo nombres?**
- Probado sin red con dos respuestas simuladas, que no se guardaron.

**Comando (1 llamada a Postalia, autorizada; lo corre Albert):**

```
C:\xampp\php\php.exe app\scripts\prueba_postalia.php --cp=11590 --autorizado
```

## Hacia `OPERATIVA`

La lista completa está en `00-estado.md`, "Lista para pasar a `OPERATIVA`". Los bloqueantes de hoy:

1. Catálogo de vehículos: provisional (portal) para probar; wsTarifa (`cUsuario`/`cTarifa` de Qualitas) para operar.
2. Consideración 40 (municipio y colonia): pasos 1 a 3.
3. Descripción del vehículo para el cliente: llega con el catálogo.
4. Liberación del negocio 08902 por Qualitas, después de validar en QA.
5. Cotización de control en producción, con autorización.
