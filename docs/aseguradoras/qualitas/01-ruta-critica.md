# Qualitas — ruta crítica

Documento operativo. Se actualiza en cada reporte. Prioridad única: **Qualitas** (Albert, 2026-09-29).

El registro completo, las reglas verificadas y las decisiones están en [`00-estado.md`](./00-estado.md). Aquí sólo va el camino a `OPERATIVA` y dónde estamos.

**Última actualización:** 2026-09-29 _(Claude)_.

> **⏸ EN PAUSA desde el 2026-09-29** (Albert), hasta que lleguen las credenciales del catálogo de Qualitas (`cUsuario`/`cTarifa`) y los PDF de Operaciones. Al retomar: `ref_sepomex` (paso 3), después los PDF (paso 5) y wsTarifa. Detalle en `00-estado.md`, "Para retomar".

## Orden acordado

0 → 1 (comando para Albert) → 4 y 5 mientras Albert corre la prueba → 2 y 3 con el resultado.

_(2026-09-29, tras el paso 1)_ Postalia no trae códigos → 6 (archivo de Correos) → 2 (las 2 llamadas) → 3 (`ref_sepomex` y pantalla, sólo después de ver el resultado de 2). En paralelo: 7, 8 y 9.

| # | Paso | Estado | Qué falta / quién |
|---|---|---|---|
| 0 | `.env.example` sin valores reales | ✅ Hecho (`b94432a`) | — |
| 1 | Postalia: script de prueba del CP 11590 | ✅ Corrido por Albert: HTTP 200, **sin códigos de SEPOMEX** (sólo nombres). Evidencia `20260929_143148_postalia_cp11590_*` | — **Postalia descartada para Qualitas** (Albert, 2026-09-29) |
| 2 | Consideración 40 en QA | ✅ **Confirmada** (id 145): Qualitas acepta `016` / `1838` sin error; el total del 11590 no cambia (10,464.91). El control del mismo día (144) falló por la red local, no por Qualitas. Regla 18 | **Se manda siempre** (Albert). No se sabe qué pasa en CP con varias colonias |
| 3 | `ref_sepomex` y su pantalla | ✅ **Cerrado como decisión**: fuente, el CSV de Correos (fuera de git); la consideración 40 se manda siempre. **Construirla es lo primero al retomar** | Fuente: archivo oficial de Correos de México, no Postalia. Ya no hace falta decidir qué pasa si Postalia no responde: es un archivo local |
| 4 | Catálogo provisional `cat_qua_vehiculos` (tabla, importador del CSV, cascada en pantalla, pruebas) | ✅ Hecho, 35 pruebas sin red. Tablas en la base real, **vacías** | Importar los vehículos cuando Albert apruebe el CSV extraído (paso 5) |
| 5 | Extracción de los PDF del portal → CSV | ✅ Hecho, 41 pruebas: los 3 PDF de ejemplo cuadran con el servicio (ids 127–129) | **Albert** deja los PDF de Operaciones en `Proyectos\Qualitas_Cotizador\Portal\`; Claude extrae el CSV; Albert lo revisa; se importa |
| 6 | Archivo oficial de SEPOMEX (Correos de México) | ✅ `Proyectos\Qualitas_Cotizador\SEPOMEX\sepomex_cp_20260925.csv`, leído en sólo lectura: 159,331 asentamientos, al 2026-09-25. 11590 = `09` / `016` / `1838` (Anzures, único). Fuera de git por licencia (`.gitignore`, `583174c`) | Será la fuente de `ref_sepomex` (paso 3). _Antes:_ ⏳ **Albert** lo deja en `Proyectos\Qualitas_Cotizador\SEPOMEX\` (puede venir en .zip) | Claude lo lee en sólo lectura: nombre, fecha, codificación, filas, campos; para el 11590, `c_estado`, `c_mnpio` e `id_asenta_cpcons` con su formato exacto. _2026-09-29:_ apareció `docs/Auxiliares/CP_CONS.ZIP`, sin versionar. **No es el archivo esperado**: trae programas de DOS (`CP_CONS.EXE`, `SISTEMA3.EXE`, un `.BAT` que borra archivos en `C:\CP_CONS`). Los datos (`CODIGO09.DBF`, `MUNICIPI.DBF`, `ASENTAMI.DBF`…) vienen dentro de un autoextraíble LHa. No se ejecutó nada ni se versiona. Se espera el archivo de texto oficial de Correos |
| 7 | Respaldo automático antes de migrar (causa del incidente del paso 4) | ✅ Hecho, con retención de los últimos 10 (`6256c5b`): 24 pruebas sin red; regresión por HTTP en 2 copias verificadas, 30 de 30 pantallas iguales. El primer respaldo automático de la base real ya salió (`…_20260929_145917`) | Anotado en ADR-003 (punto 6) |
| 8 | Catálogo provisional con los 3 vehículos de "Ejemplos Qualitas" | ✅ Aprobado e importado en la base real, con respaldo previo: 3 vehículos, fecha 2026-09-23. Dos fallas de fecha del importador, corregidas (`b850d34`) | **Albert** prueba la cascada en el navegador |
| 9 | Moto en semestral (Vento, AMIS 68133, 20%) | ✅ **Cerrado** (id 146): Qualitas la acepta, +4.0%, 2 recibos, misma comisión (685.74). Regla 19. El rechazo de formas de pago para motos **queda descartado** | Si Qualitas la rechaza: se construyen los pasos 1–3 aprobados (guardar el rechazo, mostrarlo, no volver a pedirlo). El paso 4 (no ofrecer a motos) espera al catálogo; "MO" = código de marca de motos en wsTarifa |
| 11 | **Fusión de `feature/qualitas-cotizador` a `main`** | 🔒 **Bloqueada** | Antes de fusionar hay que decidir qué pasa con los commits de Beto en esta rama (ver abajo) |

## Bloqueante del paso 11: commits de Beto en esta rama _(Albert, 2026-09-29)_

La rama trae dos commits de Beto del 2026-09-29 que **cambian GNP**, no Qualitas. Ya están en `origin`:

| Commit | Qué hace | Archivos |
|---|---|---|
| `a768fdc` | refactor(cotizar): quita el selector de plantilla de GNP Cotizador | `app/vistas/cotizar.php`, `public/index.php`, `docs/02.7-plantillas-conectadas.md`, ADR-007 |
| `0d61171` | fix(catalogo): el menú de coberturas opcionales salía vacío | `app/servicios/CatalogoServicio.php`, `docs/02.7-plantillas-conectadas.md`, ADR-003 |

- **No se tocan**: ni revert ni cherry-pick. Albert lo habla con Beto.
- **Antes de fusionar a `main` hay que decidir**:
  - o se quedan en esta rama, y entonces hace falta una **regresión completa de GNP**;
  - o se mueven a su propia rama.
- **Mientras tanto, el trabajo de Qualitas evita esos archivos** para no chocar con ellos. El catálogo provisional (paso 4) no agrega rutas a `public/index.php`: los datos los arma `QualitasServicio` y van en la pantalla de Qualitas. Las tablas nuevas se documentan en `00-estado.md` y no en ADR-003. Si algún paso llegara a necesitar esos archivos, Claude se detiene y avisa.

## Pasos 4 y 5 — catálogo provisional y PDF del portal (2026-09-29)

Detalle en `00-estado.md`, "Catálogo provisional: construido" y "Extracción de los PDF del portal".

- **Sin choque con los archivos de Beto**: no se tocó `public/index.php` (el catálogo va dentro de la pantalla de Qualitas) ni ADR-003 (las tablas nuevas se documentan en `00-estado.md`; se pasan a ADR-003 al fusionar).
- **Migración**: la base real recibió las dos tablas nuevas **antes** del respaldo y de la prueba en copia, porque un script de revisión abrió la base con `Db::get()`. Sólo agregó tablas vacías; respaldo posterior `bak_pre_importar_portal_20260929_131126`, y la prueba en copia se hizo después. Detalle en `00-estado.md`.
- **Siguiente con el catálogo**: los PDF de Operaciones → CSV → revisión de Albert → `importar_portal_qualitas.php --aplicar` (con respaldo antes) → cotizar esas AMIS por el servicio, con llamadas autorizadas, y comparar contra `cat_qua_referencias_portal`.

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

**Resultado (2026-09-29, lo corrió Albert):** HTTP 200, 187 bytes. Trae `codigo_postal`, `estado`, `municipio`, `ciudad`, `zona` y `colonias[]` con `nombre` y `tipo`. Para el 11590: Miguel Hidalgo, colonia Anzures (1 colonia). **Sin códigos de SEPOMEX.** Evidencia: `evidencia/20260929_143148_postalia_cp11590_peticion.txt` y `_respuesta.json`, con la clave enmascarada. **Decisión de Albert: Postalia queda descartada para Qualitas; la fuente es el archivo oficial de Correos de México, en `ref_sepomex`** (pasos 6, 2 y 3).

## Hacia `OPERATIVA`

La lista completa está en `00-estado.md`, "Lista para pasar a `OPERATIVA`". Los bloqueantes de hoy:

1. Catálogo de vehículos: provisional (portal) construido, falta cargar los vehículos de Operaciones; wsTarifa (`cUsuario`/`cTarifa` de Qualitas) para operar.
2. Consideración 40 (municipio y colonia): pasos 6, 2 y 3 (Postalia descartada).
3. Descripción del vehículo para el cliente: llega con el catálogo.
4. Liberación del negocio 08902 por Qualitas, después de validar en QA.
5. Cotización de control en producción, con autorización.
