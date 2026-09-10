# ADR-002 — Stack técnico y estructura del proyecto

## 📌 Estado

**Confirmado** (Beto, 2026-09-10). Producto/Negocio ✅ · TI/Arquitectura ✅.

Se apoya en [ADR-001](../01_Generales/ADR-001-que-es-el-cotizador-gnp.md), que fija qué es el proyecto.

## 🧠 Contexto

El proyecto nació con tres condiciones que acotaron las opciones desde el principio:

1. **Servidor compartido, sin terminal.** El destino de producción es cPanel: no hay forma de correr `composer install` ni de compilar nada.
2. **Equipo mínimo.** Una persona desarrollando. Cada dependencia añadida es algo que alguien tiene que mantener, actualizar y entender.
3. **Descubrimiento contra producción.** Como no hay ambiente de pruebas, el ciclo editar→probar tiene que ser inmediato. Cualquier paso de build entre el cambio y la prueba es fricción pura.

El proyecto anterior de la casa (NEXO) ya había resuelto esta misma ecuación con PHP puro sin frameworks, y funciona en producción. No había razón para experimentar.

## ⚖️ Decisión

### 1. PHP 8.2 puro, cero dependencias externas `[CONFIRMADO]`

| Capa | Tecnología | Detalle |
|---|---|---|
| Backend | **PHP 8.2** | `declare(strict_types=1)`, sin framework |
| HTTP a GNP | **cURL** | Extensión incluida en PHP |
| XML | **SimpleXML / DOM** | Extensiones incluidas |
| Base de datos | **SQLite vía PDO** | Un archivo, sin servidor |
| Frontend | **HTML + CSS + JS vanilla** | Sin React, sin jQuery, sin npm |
| Servidor local | **XAMPP** (Windows) | Apache + PHP |

**Sin usar:** Composer, npm, Vite, Webpack, Laravel, Symfony, ningún ORM, ninguna librería de terceros.

### 2. La única excepción es propia, no ajena `[CONFIRMADO]`

`app/core/PdfBasico.php` es un generador de PDF escrito para este proyecto.

El Comparativo Multi-Plan necesita un PDF con tablas y buen acabado, y PHP no trae nada nativo. En vez de sumar una dependencia externa, se escribió un generador mínimo que usa sólo **las 14 fuentes estándar de PDF** (Helvetica) — así nunca embebe una tipografía y el archivo sale autocontenido con un solo `require`.

No pretende ser una librería de PDF: cubre exactamente lo que pide `ComparativoServicio` (texto, líneas, rectángulos, salto de página) y nada más. Lo mismo aplica a `app/core/ZipMinimo.php`, escrito para empaquetar entregables sin depender de nada.

> El criterio detrás: **es preferible 300 líneas propias que se entienden completas, que una dependencia de 50,000 líneas que nadie va a leer.** Aplica sólo cuando el alcance necesario es realmente pequeño; para algo grande, la respuesta sería otra.

### 3. Una sola carpeta pública `[CONFIRMADO]`

```
cotizador-gnp/
├── config/     configuración y credenciales   · NO alcanzable desde el navegador
├── datos/      base SQLite, CSV, PDF generados · NO alcanzable
├── docs/       esta biblioteca                 · NO alcanzable
├── app/
│   ├── core/       Env · Db · Esquema · GnpClient · Auth · PdfBasico · ZipMinimo
│   ├── servicios/  Catalogo · Cotizacion · Impresion · Evidencia · Usuario · Comparativo
│   ├── vistas/     las pantallas
│   └── scripts/    carga y mantenimiento de catálogos (CLI)
└── public/     ← ÚNICA carpeta pública. index.php y el CSS
```

Apache sólo sirve `public/`. Las demás carpetas llevan además su propio `.htaccess` de negación, como segunda red por si el servidor se configura mal.

### 4. Tres capas, con la lógica separada de la pantalla `[CONFIRMADO]`

```
public/index.php        front controller · rutas · saneo de entrada
      └── app/servicios/   la lógica de negocio
              └── app/core/    infraestructura (HTTP, BD, PDF, sesión)
```

Las vistas sólo pintan. **Ninguna vista llama a GNP ni toca la base directamente.**

La razón es concreta, no estética: cuando llegue la versión pública para el cliente final, se escribe una pantalla nueva sobre los mismos servicios, sin tocar nada más.

### 5. Los scripts de catálogo son CLI e idempotentes `[CONFIRMADO]`

Todo lo que carga o repara catálogos vive en `app/scripts/` y se corre por línea de comandos, nunca desde el navegador. Todos se pueden correr las veces que haga falta sin romper nada.

| Script | Para qué |
|---|---|
| `migrar_desde_nexo.php` | Copia vehículos y catálogos del staging de NEXO. Sólo lee, nunca modifica ese archivo |
| `importar_tablas_excel.php` | Carga la matriz de paquetes y la de coberturas desde los CSV del kit |
| `etl_catalogos.php` | Descarga los catálogos planos del API |
| `etl_vehiculos.php` | Barrido completo del catálogo de vehículos de GNP |
| `construir_cat_gnp.php` | Arma la tabla `cat_gnp` del catálogo maestro |
| `limpiar_cat_comercial.php` | Corrige marcas fantasma, duplicadas y submarcas gemelas |
| `homologar_marcas_submarcas.php` | El motor de homologación GNP ↔ catálogo maestro |
| `exportar_comercial_sin_gnp_excel.php` | Genera el entregable de revisión humana |
| `usuarios.php` | Alta y mantenimiento de usuarios desde consola |

### 6. La configuración vive en `.env.local`, fuera de git `[CONFIRMADO]`

`config/.env.example` es la plantilla versionada; `config/.env.local` es el archivo real y está en `.gitignore`. La contraseña de GNP **sólo** existe ahí.

## ✅ Beneficios

- **Cero supply chain.** No hay paquete de terceros que pueda romperse, quedar sin mantenimiento o introducir una vulnerabilidad.
- **Despliegue trivial.** Copiar archivos. Sin build, sin `install`, sin nada que compilar.
- **Ciclo inmediato.** Editar y recargar. Contra un servicio que sólo se puede descubrir probando, esto vale más de lo que parece.
- **Mismo patrón que NEXO.** Quien sepa mover uno, se mueve en el otro.
- **Se lee completo.** Un desarrollador nuevo puede leer todo el código del proyecto en una jornada.

## ⚠️ Riesgos

| Riesgo | Detalle |
|---|---|
| **Sin ORM, las consultas se escriben a mano** | Más superficie para errores de SQL y para N+1 |
| **`PdfBasico` es código propio que hay que mantener** | Si el Comparativo necesita algo que hoy no hace (imágenes, tipografías), alguien lo tiene que programar |
| **Sin framework, no hay convenciones impuestas** | La disciplina de las tres capas depende de las personas, no del andamio |
| **SQLite no soporta escritura concurrente pesada** | Hoy irrelevante; con muchos usuarios simultáneos habría que revisarlo |
| **`index.php` puede crecer** | Ya va en ~19 KB y concentra todas las rutas |

## 🛡️ Mitigaciones

- Consultas siempre con **sentencias preparadas** de PDO, nunca concatenando.
- `PdfBasico` está deliberadamente limitado a lo que necesita `ComparativoServicio`; si algún día hace falta más, la decisión correcta es reevaluar, no seguir estirándolo.
- La regla de las tres capas está escrita aquí y anotada en el código donde importa.
- Si `index.php` sigue creciendo, se separan las rutas por módulo en archivos incluidos.

## 🧩 Consideraciones futuras

- **Despliegue a cPanel.** Hoy todo corre en XAMPP local. Falta el procedimiento formal de subida y decidir qué se hace con la base SQLite en el servidor.
- **Migrar a MySQL** si el volumen o la concurrencia lo piden. El acceso ya pasa por PDO, así que el cambio estaría acotado a `Db.php` y al SQL específico.
- **Separar rutas** de `index.php` cuando el archivo pase de lo razonable.

## 👥 Aprobación

- Producto / Negocio: ✅
- TI / Arquitectura: ✅

## Pendiente `[PENDIENTE]`

- Procedimiento de despliegue a producción (cPanel) sin acceso a terminal.
- `etl_vehiculos.php` hace cuatro veces las llamadas necesarias: usa la lista global de armadoras (237 claves) en vez de la filtrada por tipo de vehículo (225). No afecta los datos ya bajados, sólo la eficiencia de la próxima corrida. De paso, limpiar las ~710 filas en estado `ERROR` que dejó en `cat_vehiculos_avance`.

## Referencias

- [ADR-001 — Qué es el Cotizador GNP](../01_Generales/ADR-001-que-es-el-cotizador-gnp.md)
- [ADR-003 — Modelo de datos](./ADR-003-modelo-de-datos.md)
- `config/.env.example` — todas las variables de configuración con su explicación
