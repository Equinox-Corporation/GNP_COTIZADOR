# Cotizador GNP

Página web para cotizar seguros de auto **directamente con GNP**, con su propia base de datos de catálogos.

Proyecto independiente de NEXO. Vive en `C:\xampp\htdocs\cotizador-gnp\`, tiene su propia base, su propia configuración y su propio despliegue.

---

## Qué hace

1. El vendedor elige un vehículo del catálogo local de GNP (47,542 versiones, 168 marcas).
2. Captura al **solicitante**: una sola sección. La edad y el código postal que ahí se piden son los que fijan el precio.
3. Marca los paquetes que quiere comparar.
4. Una sola llamada a GNP devuelve todos con su precio y sus coberturas, lado a lado.
5. Con un botón se trae el PDF oficial de GNP.
6. Todo queda en el historial, con su vigencia de 15 días.
7. De cada cotización se pueden bajar dos **JSON de evidencia** por separado: uno con lo que se le pidió a GNP, otro con lo que contestó, XML incluido.
8. Y un **Comparativo Multi-Plan**, en PDF o en Excel: primas y coberturas de todos los paquetes cotizados, lado a lado, listo para el cliente. Esto no lo genera GNP — es un reporte propio del cotizador.
9. Los administradores tienen su propio panel para dar de alta, apagar o cambiarle la contraseña a otros usuarios (`?r=usuarios`).

---

## Instalación

### 1. Configuración

```
copy config\.env.example config\.env.local
```

Abre `config\.env.local` y llena **`GNP_PASSWORD`** con la contraseña del Portal de Intermediarios. Es lo único que no se puede dejar escrito de antemano.

> `.env.local` nunca se sube a git. Ahí vive la contraseña.

### 2. Cargar los catálogos

Los vehículos ya se descargaron una vez desde NEXO. En vez de volver a pedírselos a GNP, se copian:

```
C:\xampp\php\php.exe app\scripts\migrar_desde_nexo.php
```

Después, las dos tablas que sólo existen en el Excel del kit:

```
C:\xampp\php\php.exe app\scripts\importar_tablas_excel.php
```

Y opcionalmente los catálogos planos del API (paquetes, ocupaciones, periodicidades…):

```
C:\xampp\php\php.exe app\scripts\etl_catalogos.php
```

### 3. Abrir

```
http://localhost/cotizador-gnp/public/
```

La primera vez pide crear un usuario. De ahí en adelante, login normal.

---

## Los scripts

| Script | Para qué |
|---|---|
| `migrar_desde_nexo.php` | Copia vehículos y catálogos del staging de NEXO. Sólo lee ese archivo, nunca lo modifica |
| `importar_tablas_excel.php` | Carga la matriz de paquetes y la de coberturas desde los CSV |
| `etl_catalogos.php` | Descarga los catálogos planos del API (`--lista` para verlos) |
| `etl_vehiculos.php` | Barrido completo del catálogo de vehículos (`--resumen` para ver qué hay) |

Todos son idempotentes: se pueden correr las veces que haga falta.

---

## Cómo está armado

```
cotizador-gnp/
├── config/     configuración · NO alcanzable desde el navegador
├── datos/      la base SQLite, los CSV y los PDF · NO alcanzable
├── app/
│   ├── core/       Env · Db · Esquema · GnpClient · Auth · PdfBasico
│   ├── servicios/  Catalogo · Cotizacion · Impresion · Evidencia · Usuario · Comparativo
│   ├── vistas/     las pantallas
│   └── scripts/    carga de catálogos
└── public/     ← única carpeta pública. index.php y el CSS
```

Sin Composer, sin npm, sin librerías de terceros. PHP 8.2 con cURL y SimpleXML, que ya vienen incluidos.

**Única excepción, y es propia: `app/core/PdfBasico.php`.** El Comparativo Multi-Plan necesita un PDF con tablas y buen acabado, y PHP no trae nada nativo para generar PDF. En vez de sumar una dependencia externa (Composer, un vendor, algo bajado de internet), es un generador mínimo escrito para este proyecto: usa sólo las 14 fuentes estándar de PDF (Helvetica), así que nunca embebe una fuente y el archivo sale autocontenido con un solo `require`. No es una librería de PDF completa — cubre exactamente lo que pide `ComparativoServicio` (texto, líneas, rectángulos, salto de página) y nada más.

La lógica está separada de las pantallas a propósito: cuando llegue la versión pública, se abre la pantalla de cotizar sin tocar nada más.

### La base

Un archivo, `datos/cotizador_gnp.sqlite`. Tres familias de tablas:

- **`cat_*`** — el espejo de los catálogos de GNP
- **`cot_*`** — las cotizaciones y sus resultados
- **`sys_*`** — usuarios (con su rol de administrador) y bitácora de llamadas, con el XML de ida y vuelta

---

## Cosas que hay que saber

Estas no son opiniones: salen de probar contra el servicio real.

**El precio es `TOTAL_PAGAR`, nunca `PRIMA_NETA`.** La neta no trae derechos ni IVA. Usarla sería cobrar alrededor de 21% por debajo.

**Quien fija el precio es el conductor.** GNP tarifica con su edad y su código postal, no con los del contratante. En el PDF que ve el cliente aparecen los del conductor. Por eso son los únicos datos de persona que el formulario exige.

**La pantalla pide una sola persona; el XML lleva dos.** GNP exige los bloques `CONTRATANTE` y `CONDUCTOR`, y los dos repiten edad y código postal. Como ésta es una pantalla de cotización —no de emisión— se pide una sola vez, en la sección **Solicitante**, con los campos del conductor, que son los que tarifican. El contratante hereda esos dos datos **en el servidor** (`public/index.php`, ruta `cotizar`), nunca en el navegador. El XML que sale a GNP no cambió. Cuando se haga la pantalla de emisión hay que separarlos otra vez: ahí el titular sí puede ser otra persona. Está anotado en el código, en el punto exacto donde hay que tocar.

**Del contratante GNP casi no pide nada.** El bloque tiene que ir, pero su contenido no: el propio ejemplo de persona moral de GNP manda únicamente `TIPO_PERSONA` y `CODIGO_POSTAL` —sin nombre, sin edad y sin RFC— y cotiza igual. El nombre y el RFC son para el documento, no para el precio. Por eso el formulario ya no los exige y el cliente XML escribe esas etiquetas sólo cuando traen dato. Ver `docs/02.5-contratante-minimo.md`: la forma corta está deducida del ejemplo de GNP y falta comprobarla contra el servicio.

**Cada cotización guarda su evidencia, en dos archivos.** El XML de ida y vuelta de cada llamada queda en `sys_llamadas` con la contraseña enmascarada. Se baja como JSON desde la pantalla de resultado o desde el historial: `?r=evidencia&id=N&parte=peticion` (lo que se le pidió a GNP) o `parte=respuesta` (lo que contestó). Van separados a propósito, para no tener que abrir un solo archivo enorme cuando sólo hace falta revisar un lado. De la impresión sólo se guardan los primeros 256 KB: el resto es el PDF en base64, que ya está guardado como archivo. **Los dos traen datos personales del cliente** — trátalos como confidenciales.

**El Comparativo Multi-Plan es un reporte propio, no de GNP.** Junta las primas y todas las coberturas de los paquetes ya cotizados —la misma tabla que se ve en pantalla— en un PDF o un CSV (`?r=comparativo&id=N&formato=pdf|excel`). No hace ninguna llamada nueva al servicio: sólo lee lo que ya está guardado en `cot_resultados`.

**El panel de usuarios no deja que el sistema se quede sin administrador.** `UsuarioServicio` bloquea apagar o quitarle el rol al último admin activo, y bloquea que alguien apague su propia sesión. El primer usuario que se da de alta (pantalla de arranque) siempre queda como admin; a los demás se les da el rol desde el propio panel (`?r=usuarios`).

**GNP contesta `200 OK` aunque el negocio falle.** El veredicto va dentro del XML. El cliente lo clasifica por el campo `ORIGEN`: `ldapService` es contraseña mala, `catalogos` es dato inexistente, `runtime` es falla de ellos.

**Un `504` no es un rechazo.** Significa que GNP no alcanzó a armar una respuesta demasiado grande. La solución es pedir menos, no reintentar.

**GNP también manda el PDF por correo**, con su remitente y su plantilla. Por eso el correo que se le envía sale de `GNP_CORREO_IMPRESION` y no del formulario: así el primer contacto con el cliente lo sigue controlando Equinox. Cuando Comercial decida otra cosa, se cambia esa línea.

**La cotización vale 15 días naturales.** Pasado ese plazo el sistema no deja imprimirla y lo dice en pantalla.

**Estamos en producción.** GNP no dio ambiente de pruebas. Cotizar e imprimir **no** generan póliza; el cliente no conoce ninguna ruta de emisión y además las bloquea si alguien las escribe.

---

## Lo que falta

- **Procedencias distintas de Residentes.** Sólo el subramo `01` está verificado contra el servicio. Los otros seis están en la tabla `cat_procedencias` esperando que GNP confirme su clave; mientras tanto el sistema los rechaza con un mensaje claro en vez de mandar un dato inventado.
- **Contratante mínimo.** Falta correr la prueba `02.5` contra producción para confirmar que GNP acepta la forma corta. La guía está en `docs/02.5-contratante-minimo.md`.
- **El bloque "Contacta a tu agente" del PDF viene vacío.** Es pregunta abierta con Conectividad GNP. No bloquea el desarrollo, sí la salida a cliente.
- **Versión pública.** La arquitectura ya la contempla; falta decidir el flujo y resolver lo del correo.
