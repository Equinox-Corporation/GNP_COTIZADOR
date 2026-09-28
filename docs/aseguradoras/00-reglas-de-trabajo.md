# Reglas de trabajo con credenciales y copias del sistema

Documento operativo. Última revisión: _(Claude, 2026-09-28)_.

Nacen de un incidente de la Etapa 5 de Qualitas (2026-09-28), al armar las copias para la regresión:

- Un `grep` de verificación imprimió la contraseña de GNP en la salida de un comando.
- La copia de la versión anterior recibió por error el `.env.local` original, con las URL reales de GNP y de Qualitas. Se corrigió antes de levantar cualquier servidor, así que no hubo llamadas.

Valen para GNP y para cualquier aseguradora.

## 1. El `.env.local` nunca se imprime

Ningún comando muestra valores de `config/.env.local`, ni del real ni del de una copia: ni `cat`, ni `grep` que devuelva la línea completa, ni `echo` de una variable.

- Para comprobar una configuración se muestran **sólo nombres de llave y un veredicto** (OK/FALLA, vacía/llena, local/externa), nunca el valor.
- Si hace falta modificar un valor en una copia, se reemplaza con `sed` sin volver a leerlo en pantalla.
- Si un valor llega a imprimirse por error, se avisa en ese momento a quien corresponda, para que decida si cambia la credencial.

## 2. Verificación de puertos en copias

Antes de levantar **cualquier** copia del sistema (servidor local, regresión, pruebas de pantallas) se corre:

```
C:\xampp\php\php.exe app\scripts\verificar_copia_sin_red.php <ruta al .env.local de la copia>
```

La copia sólo se levanta si el script termina en "Copia sin red: se puede levantar". Revisa:

- Toda llave con `_URL` (servicios de GNP, Qualitas, catálogos…) está **vacía o apunta a `127.0.0.1`/`localhost`** en un puerto que está **cerrado de verdad**. Lo comprueba abriendo una conexión: si el puerto contesta, falla.
- `DB_PATH` es explícita, existe y **no es la base real**.
- El archivo revisado no es el `.env.local` real del proyecto.

Si falla, la copia no se levanta. Se corrige la copia y se vuelve a verificar.

**Por qué:** en esta máquina el cotizador llama a GNP producción. Una copia con la URL real convierte una "prueba sin llamadas" en llamadas reales, justo lo que las reglas del proyecto prohíben sin autorización.
