# ADR-008 — Piso mínimo de coberturas por paquete GNP

## 📌 Estado

**Confirmado** (CC, 2026-09-11) — las seis llamadas de control corrieron contra producción, y los tres hallazgos de catálogo (dos en Amplia Total, uno en Auto Elite) se investigaron y cerraron con evidencia (`sys_llamadas.id` 113, 114, 115). **Borrador pendiente de revisión de Beto antes de darse por cerrado** (pidió revisar los números antes de que quede como definitivo).

Se apoya en [ADR-007](./ADR-007-modulo-juega-y-compara.md) — el módulo "Juega y Compara" y el armador libre de coberturas (`docs/02.13`, `docs/02.14`) nacieron de la necesidad de saber, antes de dejar tocar nada, qué es negociable y qué no en cada paquete. También se apoya en [`docs/02.10-rc-accidentes-conductor.md`](../02.10-rc-accidentes-conductor.md), que ya probó el caso de Responsabilidad Civil y se cita aquí en vez de repetirse.

## 🧠 Contexto

El armador libre (ADR-007, `docs/02.13`/`02.14`) deja tocar las coberturas Opcionales de un paquete, nunca las Básicas — pero hasta ahora "las Básicas de cada paquete" era lo que decía `cat_coberturas`, nunca algo confirmado contra GNP paquete por paquete. Sólo Responsabilidad Civil tenía una llamada de control real (`docs/02.10`, motivada por otra pregunta — si Accidentes al Conductor cabía ahí — no por esta).

Antes de que Beto arme planes a la medida con el armador, necesita saber con certeza: para cada uno de los seis paquetes reales de GNP, ¿qué coberturas vienen incluidas sin pedir nada?, y ¿hay algo que esté en los seis sin excepción — un "mínimo universal" del seguro, sin importar el paquete que se elija?

## ⚖️ Decisión

### 1. El piso de cada paquete, confirmado contra producción `[CONFIRMADO]`

Misma cotización de control en los seis: Honda Fit `AUTHO0614`, persona física, contratante 50 años CP 76000, conductor 43 años CP 04200, vigencia anual — **sin ningún `<COBERTURAS>`** — para ver exactamente lo que GNP incluye por su cuenta. Cinco llamadas nuevas (`app/scripts/prueba_piso_minimo_paquetes.php`, 2026-09-11); Responsabilidad Civil ya estaba probada así en `docs/02.10` y no se repitió.

| Paquete | `cve_paquete` | `sys_llamadas.id` | Coberturas incluidas por default |
|---|---|---|---|
| **Amplia** | `PRS0009355` | 109 | Daños Materiales Pérdida Total · Daños Materiales Pérdida Parcial · Cristales · Robo Total · Responsabilidad Civil Daños a Terceros · Protección Legal · Gastos Médicos Ocupantes · Extensión de Responsabilidad Civil · Club GNP *(9)* |
| **Amplia Total** | `PRS0054748` | 110 | Daños Materiales Pérdida Total · Daños Materiales Pérdida Parcial · Cristales · Robo Total · Responsabilidad Civil Daños a Terceros · Protección Legal · Gastos Médicos Ocupantes · Extensión de Responsabilidad Civil · **Club GNP Plus** *(9 — nótese: no es "Club GNP", ver corrección abajo)* |
| **Limitada** | `PRS0009356` | 111 | Robo Total · Responsabilidad Civil Daños a Terceros · Protección Legal · Gastos Médicos Ocupantes · Extensión de Responsabilidad Civil · Club GNP *(6)* |
| **Responsabilidad Civil** | `PRP0000289` | **72** *(ya probada en `docs/02.10`, citada aquí)* | Responsabilidad Civil Daños a Terceros · Protección Legal · Gastos Médicos Ocupantes · Extensión de Responsabilidad Civil · Club GNP *(5)* |
| **Premium** | `PRS0010536` | 112 | Daños Materiales Pérdida Total · Daños Materiales Pérdida Parcial · Cristales · Robo Total · Responsabilidad Civil Daños a Terceros · Protección Legal · Gastos Médicos Ocupantes · Extensión de Responsabilidad Civil · Club GNP · Auto Sustituto Plus · Ayuda para Llantas y Rines · Llaves de Repuesto · Paga Cero · Robo Parcial Plus *(14)* |
| **Auto Elite** | `PRP0000357` | 113 | **Auto Sustituto** (`0000001414`, GNP la nombra "Auto Sustituto Pérdida Total" en este paquete — ver nota abajo) · Ayuda para Llantas y Rines · Llaves de Repuesto · Paga Cero *(4)* |

Evidencia XML de ida y vuelta de las cinco llamadas nuevas en
`datos/evidencia_ADR-008_piso_minimo/`.

**Tres hallazgos, todos investigados hasta una causa concreta y cerrados — ninguno dejado como diferencia sin explicar:**

`cat_coberturas` (paquete `AMPLIA TOTAL`) decía que traía 10 Básicas, incluidas "Club GNP" (`0000001268`) y "Eliminación de Deducible en Pérdidas Parciales" (`0000001689`). Lo que GNP realmente devolvió en la llamada de control (`sys_llamadas.id = 110`) fueron **9** coberturas, sin ninguna de esas dos.

**Discrepancia 1 — "Club GNP" vs. "Club GNP Plus": son claves distintas, confirmado, no un renombre.** El XML crudo de la respuesta (`datos/evidencia_ADR-008_piso_minimo/amplia_total-control-respuesta.txt`) trae `<CVE_COBERTURA>0000001687</CVE_COBERTURA><NOMBRE>CLUB GNP PLUS</NOMBRE>` — una clave que no existía en absoluto en `cat_coberturas`, en ningún paquete. Antes de agregarla se probó si también aplica a otros paquetes (para no adivinar su matriz): pedida explícitamente como Opcional sobre **Amplia** (`PRS0009355`), GNP la **rechazó** con clave 12 / `cotizador-eot`, "La cobertura no existe. No aplica para este producto" (`sys_llamadas.id = 114`, script `app/scripts/prueba_club_gnp_plus.php`). Es decir: **exclusiva de Amplia Total**, no una cobertura general.

**Corregido** en `Esquema.php::semillas()`: se quitó la fila de "Club GNP" (`0000001268`) para `AMPLIA TOTAL` (nunca aplicó ahí) y se agregó `0000001687` "Club GNP Plus" como `BASICA` de `AMPLIA TOTAL`, con su valor permitido (`SUMA_ASEGURADA = Amparada`) en `cat_cobertura_valores` — mismo patrón que la fila de "Club GNP" en los otros cuatro paquetes.

**Discrepancia 2 — "Eliminación de Deducible" ausente: error de captura, no antigüedad.** Se repitió el mismo control de Amplia Total con el vehículo más nuevo disponible en el catálogo (Honda Civic I-Style `AUTHO0218`, modelo **2026** — `AUTHO0614` no tiene versión posterior a 2020, así que se usó la misma clave ya validada para acotar "Siempre en Agencia" en `docs/02.12`). **Tampoco apareció** (`sys_llamadas.id = 115`, script `app/scripts/prueba_eliminacion_deducible_amplia_total.php`) — descarta la hipótesis de antigüedad: si fuera una restricción como "Siempre en Agencia", el vehículo más nuevo posible la habría destapado.

Revisando dónde más aparece `0000001689` en `cat_coberturas`, el patrón fue claro: es **`OPCIONAL`** en Amplia, en Premium y hasta en `MOTO`/Amplia — **`AMPLIA TOTAL` era la única de sus cuatro apariciones marcada `BASICA`**. Un error de captura consistente con el resto del catálogo, no una limitación real de GNP.

**Corregido** en `Esquema.php::semillas()`: se cambió su `tipo` de `BASICA` a `OPCIONAL` para `AMPLIA TOTAL` — mismo valor que en los otros tres lugares donde aparece. No se borró la fila ni se reescribió el hallazgo original: se corrigió con un `UPDATE` fechado y comentado, citando ambas llamadas de evidencia.

**Verificado después de ambas correcciones:** `cat_coberturas` (BASICA) para Amplia Total ahora trae exactamente las mismas 9 claves que GNP devolvió en el control — coincidencia exacta, confirmada por consulta directa a la base ya corregida.

**Verificación 3 — "Auto Sustituto Pérdida Total" en Auto Elite: no es un hueco de catálogo, sólo un nombre más específico.** Antes de dejar ese nombre escrito en la tabla de arriba se confirmó, sin adivinar: el XML crudo (`sys_llamadas.id = 113`) trae literalmente `<CVE_COBERTURA>0000001414</CVE_COBERTURA><NOMBRE>AUTO SUSTITUTO PÉRDIDA TOTAL</NOMBRE>` — no hubo error de transcripción al armar la tabla. Esa clave **ya existía** en `cat_coberturas`, con tres filas (Amplia, Amplia Total, Auto Elite), y su clasificación como `BASICA` para Auto Elite ya era correcta — es la misma cobertura del trío excluyente Auto Sustituto/Auto Sustituto Plus/Ayuda para Pérdidas Totales, confirmado desde el 25-ago-2026. La única diferencia real: el catálogo tenía guardado el nombre corto "Auto Sustituto" para las tres filas, mientras GNP, específicamente en el contexto de Auto Elite, la nombra "Auto Sustituto Pérdida Total".

**Corregido, con el mismo cuidado que "Club GNP Plus" — sin asumir que aplica donde no se probó:** se actualizó el `nombre` a "Auto Sustituto Pérdida Total" **sólo en la fila de Auto Elite**, la única con evidencia real de ese nombre. Las filas de Amplia y Amplia Total conservan "Auto Sustituto": ahí la cobertura es Opcional y nunca se pidió explícitamente en esta investigación, así que no hay evidencia de qué nombre devolvería GNP en ese contexto — no se asume que comparten el mismo matiz sin probarlo.

De los cinco paquetes con llamada nueva, **Amplia, Limitada y Premium coincidieron exactamente** desde el principio entre lo que decía `cat_coberturas` y lo que GNP devolvió, sin necesidad de ningún ajuste. Amplia Total y Auto Elite sí requirieron correcciones — todas documentadas y verificadas arriba.

### 2. Intersección de los seis pisos: vacía `[CONFIRMADO]`

**No hay ninguna cobertura presente en los seis paquetes sin excepción.** La razón concreta: **Auto Elite** no comparte una sola clave con **Responsabilidad Civil** — Auto Elite es un paquete de asistencia (auto sustituto, llantas, llaves, paga cero), sin ninguna cobertura de daños o responsabilidad civil; RC es exactamente lo opuesto (sólo daños a terceros y coberturas legales/médicas, sin asistencia). Con esos dos ya sin nada en común, la intersección de los seis es necesariamente vacía.

**Esto es una respuesta válida, no un hueco de la prueba**: no existe un "mínimo universal" de GNP independiente del paquete elegido. Elegir Auto Elite como base en el armador libre significa que ni Responsabilidad Civil Daños a Terceros ni Gastos Médicos Ocupantes vienen incluidas de ninguna forma — hay que saberlo antes de armar un plan sobre ese paquete, no descubrirlo al cotizar.

**Hallazgo secundario, útil aunque no responde la pregunta exacta:** los **cinco paquetes de daños/responsabilidad** (Amplia, Amplia Total, Limitada, Responsabilidad Civil, Premium — todos menos Auto Elite) sí comparten un núcleo de 4 coberturas sin excepción:

- Responsabilidad Civil Daños a Terceros (`0000001273`)
- Protección Legal (`0000001285`)
- Gastos Médicos Ocupantes (`0000000906`)
- Extensión de Responsabilidad Civil (`0000000904`)

Ese núcleo de 4 sí es un piso real — pero sólo entre esos cinco, no entre los seis. Auto Elite queda fuera por diseño: es un complemento de asistencia, no un seguro de daños.

### 3. Las Básicas no son negociables en el armador libre `[CONFIRMADO]`

Aclaración explícita para el armador libre (ADR-007, `docs/02.13`/`02.14`): **las coberturas Básicas de un paquete no son "opcionales de quitar"** — son parte innegociable de elegir ese paquete como base. En cuanto se elige un `cve_paquete`, sus Básicas vienen incluidas siempre, GNP las aplica sin que se le pidan y sin que se le puedan quitar. El armador libre sólo deja tocar las **Opcionales** (`PlantillaServicio::coberturasDisponibles()` las muestra todas, Básicas y Opcionales, pero `paraGnpClient()` nunca manda una Básica como "removida" — a lo más, omite mandarla explícitamente cuando ya viene por default, que es distinto de quitarla).

Dicho de otro modo: elegir el paquete base **es** elegir su piso. La única decisión real está en las Opcionales — y, según el paquete elegido, en qué tan alto o bajo es ese piso (4 coberturas en Auto Elite, 14 en Premium).

## 🧩 Modelo de datos

No se agregó ninguna tabla ni columna. Sí se corrigieron datos de `cat_coberturas` y `cat_cobertura_valores`, en `Esquema.php::semillas()`, con nota fechada citando la evidencia (`sys_llamadas.id` 110, 113, 114, 115) — mismo patrón que las demás correcciones de catálogo de este proyecto (ej. la antigüedad de "Siempre en Agencia" o las exclusiones de `cat_coberturas_excluyentes`):

- `DELETE` de la fila `(AUTO, AMPLIA TOTAL, 0000001268)` — "Club GNP" nunca aplicó a Amplia Total.
- `INSERT` de `(AUTO, AMPLIA TOTAL, 0000001687, 'Club GNP Plus', BASICA, ...)` + su valor permitido en `cat_cobertura_valores`.
- `UPDATE` de `(AUTO, AMPLIA TOTAL, 0000001689)`: `tipo` de `BASICA` a `OPCIONAL`.
- `UPDATE` de `(AUTO, AUTO ELITE, 0000001414)`: `nombre` de `'Auto Sustituto'` a `'Auto Sustituto Pérdida Total'` — sólo esa fila, no las de Amplia/Amplia Total.

Las cuatro son idempotentes (verificado corriendo la migración dos veces seguidas).

## ✅ Beneficios

- Beto ya no arma un plan a ciegas: sabe de antemano qué trae cada paquete sin pedir nada, y que no hay un mínimo universal entre los seis.
- Los tres hallazgos (dos en Amplia Total, uno en Auto Elite) quedaron corregidos, no sólo señalados: el catálogo local ahora coincide con lo que GNP realmente aplica y nombra en los seis paquetes.
- Deja precedente del método: la próxima vez que se dude del piso de un paquete, la respuesta es una llamada de control, no una lectura de `cat_coberturas` — y cuando el control encuentra algo raro (clave distinta, nombre distinto, tipo mal marcado), se investiga hasta una causa concreta antes de escribirlo en un ADR formal, nunca se deja como "diferencia sin explicar" ni se asume sin probar a qué otros paquetes podría aplicar.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| ~~`cat_coberturas` de Amplia Total estaba desactualizado~~ | **Corregido** — ver punto 1 y "Modelo de datos" |
| ~~El nombre de "Auto Sustituto" en Auto Elite no coincidía con el que manda GNP~~ | **Corregido** — ver "Verificación 3" y "Modelo de datos" |
| Este piso puede cambiar sin aviso | GNP no notifica cambios a sus paquetes; lo confirmado aquí es válido a partir del 2026-09-11, no una garantía permanente |
| El resto del catálogo (los otros 5 paquetes) no se auditó cobertura por cobertura contra `cat_cobertura_valores`/exclusiones — sólo se comparó la lista de Básicas del control | Un error del mismo tipo (tipo mal marcado, clave faltante, nombre distinto) podría existir en una cobertura Opcional que este ADR no ejercitó; se descubriría al intentarla en el armador libre, no antes |
| El nombre "Auto Sustituto Pérdida Total" sólo se confirmó para Auto Elite | Si Amplia o Amplia Total alguna vez piden esa cobertura explícitamente, GNP podría devolverla con un nombre distinto al que hoy tienen guardado ("Auto Sustituto") — no se probó, a propósito, para no asumir sin evidencia |

## 🛡️ Mitigaciones

- Las tres correcciones ya están aplicadas y verificadas (`cat_coberturas` coincide exactamente con la respuesta real de GNP tras el `DELETE`/`INSERT`/`UPDATE`, confirmado con la migración corrida dos veces).
- El riesgo restante (coberturas Opcionales no auditadas, nombre de Auto Sustituto en otros paquetes) se mitiga igual que el resto del proyecto: cada vez que el armador libre o una plantilla nueva tropiece con un rechazo o un dato inesperado de GNP, se documenta con el mismo método de este ADR, no se adivina.

## 🧩 Consideraciones futuras

- Repetir este mismo control cuando GNP actualice su catálogo de coberturas (no hay aviso automático — sólo se sabría si algo empieza a fallar o alguien lo vuelve a probar).
- Si Producto quiere ofrecer un paquete "todo incluido" en el armador, el núcleo de 4 coberturas del punto 2 es el piso real a comunicar (con la salvedad de que no aplica si el paquete base es Auto Elite).
- Considerar, en algún momento, un control similar para las coberturas Opcionales de cada paquete (no sólo las Básicas) — este ADR sólo auditó el piso por default, no el menú completo de cada paquete.

## 👥 Aprobación

- Producto / Negocio: ⏳ (Beto pidió revisar los números antes de cerrar)
- TI / Arquitectura: ⏳

## Pendiente `[PENDIENTE]`

- Que Beto confirme los seis pisos, la intersección vacía y las tres correcciones de catálogo (Amplia Total ×2, Auto Elite) antes de que este ADR se marque `Confirmado` de forma definitiva.

## Referencias

- [ADR-007 — Módulo Juega y Compara](./ADR-007-modulo-juega-y-compara.md)
- [`docs/02.10-rc-accidentes-conductor.md`](../02.10-rc-accidentes-conductor.md) — prueba de control de Responsabilidad Civil (`sys_llamadas.id = 72`), citada aquí en vez de repetida
- [`docs/02.12-bug-amparada.md`](../02.12-bug-amparada.md) — mismo mecanismo de antigüedad (`antiguedad_max_anios`) descartado aquí para "Eliminación de Deducible" tras confirmar que no aplica; también el trío excluyente Auto Sustituto/Auto Sustituto Plus/Ayuda para Pérdidas Totales confirmado el 25-ago-2026, misma clave `0000001414` de la Verificación 3
- [`docs/02.13-armador-libre-backend.md`](../02.13-armador-libre-backend.md) y [`docs/02.14-armador-libre-pantallas.md`](../02.14-armador-libre-pantallas.md) — el armador libre que motivó esta pregunta
- `app/scripts/prueba_piso_minimo_paquetes.php` — script de las cinco llamadas de control
- `app/scripts/prueba_club_gnp_plus.php` — script que acota "Club GNP Plus" a Amplia Total
- `app/scripts/prueba_eliminacion_deducible_amplia_total.php` — script que descarta la hipótesis de antigüedad para "Eliminación de Deducible"
- `datos/evidencia_ADR-008_piso_minimo/` — XML de ida y vuelta de las siete llamadas de esta investigación (la Verificación 3 reutilizó la respuesta ya guardada de `sys_llamadas.id = 113`, sin llamada nueva)
