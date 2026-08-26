<?php
declare(strict_types=1);

/**
 * Cotizador GNP — punto de entrada único.
 *
 * Todo pasa por aquí. La base de datos, la configuración y los PDF viven fuera
 * de esta carpeta, así que no son alcanzables desde el navegador.
 */

define('RUTA_BASE', dirname(__DIR__));
define('RUTA_APP',  RUTA_BASE . '/app');

require RUTA_APP . '/core/Env.php';
Env::cargar(RUTA_BASE . '/config/.env.local');

// La URL base se calcula sola: funciona en http://localhost/cotizador-gnp/public
// y también en un dominio propio.
define('BASE_URL', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'));

foreach (['core/Esquema', 'core/Db', 'core/Auth', 'core/GnpClient', 'core/PdfBasico',
          'servicios/CatalogoServicio', 'servicios/CotizacionServicio', 'servicios/ImpresionServicio',
          'servicios/EvidenciaServicio', 'servicios/UsuarioServicio', 'servicios/ComparativoServicio'] as $c) {
    require RUTA_APP . '/' . $c . '.php';
}

if (!Env::esProduccion()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

$ruta = (string) ($_GET['r'] ?? 'cotizar');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

/** Escapa para HTML. Se usa en TODAS las salidas de las vistas. */
function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dinero(?float $n): string
{
    return $n === null ? '—' : '$' . number_format($n, 2);
}

function url(string $r, array $p = []): string
{
    return BASE_URL . '/?' . http_build_query(array_merge(['r' => $r], $p));
}

function vista(string $nombre, array $datos = []): void
{
    global $ruta;
    extract($datos, EXTR_SKIP);
    $contenido = RUTA_APP . '/vistas/' . $nombre . '.php';
    require RUTA_APP . '/vistas/layout.php';
}

function json(array $d): never
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirigir(string $r, array $p = []): never
{
    header('Location: ' . url($r, $p));
    exit;
}

Auth::iniciarSesion();

try {
    Db::get();
} catch (Throwable $e) {
    http_response_code(500);
    exit('No se pudo abrir la base de datos: ' . h($e->getMessage()));
}

// ─── Alta del primer usuario ─────────────────────────────────────────────────
if (!Auth::hayUsuarios()) {
    if ($post && $ruta === 'alta') {
        $u = trim((string) ($_POST['usuario'] ?? ''));
        $n = trim((string) ($_POST['nombre'] ?? ''));
        $c = (string) ($_POST['clave'] ?? '');
        if ($u === '' || strlen($c) < 8) {
            vista('alta', ['error' => 'El usuario no puede ir vacío y la contraseña necesita al menos 8 caracteres.']);
            exit;
        }
        // El primer usuario del sistema es administrador: si no, nadie podría
        // entrar al panel de usuarios a darle el rol a alguien.
        Auth::crear($u, $n, $c, esAdmin: true);
        Auth::entrar($u, $c);
        redirigir('cotizar');
    }
    vista('alta', ['error' => '']);
    exit;
}

// ─── Rutas abiertas ──────────────────────────────────────────────────────────
if ($ruta === 'login') {
    if ($post) {
        if (Auth::entrar((string) ($_POST['usuario'] ?? ''), (string) ($_POST['clave'] ?? ''))) {
            redirigir('cotizar');
        }
        vista('login', ['error' => 'Usuario o contraseña incorrectos.']);
        exit;
    }
    vista('login', ['error' => '']);
    exit;
}

if ($ruta === 'salir') {
    Auth::salir();
    redirigir('login');
}

// ─── De aquí en adelante hace falta sesión ───────────────────────────────────
Auth::exigir();

switch ($ruta) {

    // Datos para los desplegables en cascada. Salen del espejo local, no de GNP.
    case 'api':
        $q  = (string) ($_GET['q'] ?? '');
        $tv = (string) ($_GET['tipo'] ?? 'AUT');
        json(match ($q) {
            'marcas'    => ['datos' => CatalogoServicio::marcas($tv)],
            'lineas'    => ['datos' => CatalogoServicio::lineas($tv, (string) ($_GET['armadora'] ?? ''))],
            'anios'     => ['datos' => CatalogoServicio::anios($tv, (string) ($_GET['armadora'] ?? ''), (string) ($_GET['carroceria'] ?? ''))],
            'versiones' => ['datos' => CatalogoServicio::versiones($tv, (string) ($_GET['armadora'] ?? ''), (string) ($_GET['carroceria'] ?? ''), (int) ($_GET['modelo'] ?? 0))],
            'paquetes'  => ['datos' => CatalogoServicio::paquetes((string) ($_GET['persona'] ?? 'F'), (string) ($_GET['procedencia'] ?? 'Residentes'), $tv)],
            'opcionales'=> ['datos' => CatalogoServicio::opcionalesComunes(
                                CatalogoServicio::grupo($tv),
                                array_values(array_filter(explode(',', (string) ($_GET['paquetes'] ?? '')))))],
            'buscar'    => ['datos' => CatalogoServicio::buscar((string) ($_GET['texto'] ?? ''), $tv)],
            default     => ['error' => 'consulta desconocida'],
        });

    case 'cotizar':
        if (!$post) {
            vista('cotizar', [
                'diag'         => CatalogoServicio::diagnostico(),
                'procedencias' => CatalogoServicio::procedencias(),
                'error'        => '',
                'previo'       => [],
            ]);
            exit;
        }

        if (!Auth::tokenValido($_POST['_t'] ?? null)) {
            vista('cotizar', ['diag' => CatalogoServicio::diagnostico(),
                              'procedencias' => CatalogoServicio::procedencias(),
                              'error' => 'La sesión expiró. Vuelve a enviar el formulario.', 'previo' => $_POST]);
            exit;
        }

        $f = [
            'tipo_vehiculo'    => (string) ($_POST['tipo_vehiculo'] ?? 'AUT'),
            'armadora'         => (string) ($_POST['armadora'] ?? ''),
            'carroceria'       => (string) ($_POST['carroceria'] ?? ''),
            'modelo'           => (int) ($_POST['modelo'] ?? 0),
            'version'          => (string) ($_POST['version'] ?? ''),
            'procedencia'      => (string) ($_POST['procedencia'] ?? 'Residentes'),
            'tipo_persona'     => (string) ($_POST['tipo_persona'] ?? 'F'),
            'nombres'          => trim((string) ($_POST['nombres'] ?? '')),
            'apellido_paterno' => trim((string) ($_POST['apellido_paterno'] ?? '')),
            'apellido_materno' => trim((string) ($_POST['apellido_materno'] ?? '')),
            'contratante_rfc'  => strtoupper(trim((string) ($_POST['contratante_rfc'] ?? ''))),
            'conductor_edad'   => (int) ($_POST['conductor_edad'] ?? 0),
            'conductor_cp'     => trim((string) ($_POST['conductor_cp'] ?? '')),
            'conductor_sexo'   => (string) ($_POST['conductor_sexo'] ?? 'M'),
            'conductor_nacimiento' => preg_replace('/\D/', '', (string) ($_POST['conductor_nacimiento'] ?? '')) ?: '',
            'correo'           => trim((string) ($_POST['correo'] ?? '')),
            'periodicidad'     => (string) ($_POST['periodicidad'] ?? 'A'),
        ];

        // ─────────────────────────────────────────────────────────────────────
        // El contratante hereda edad y código postal del conductor.
        //
        // Por qué: esta pantalla es de COTIZACIÓN, no de emisión. GNP pide los
        // dos bloques en el XML y los dos repiten edad y CP, pero para cotizar
        // la distinción no aporta: el precio lo fija el conductor y el
        // contratante sólo existe para el documento. Pedir el mismo dato dos
        // veces sólo abre la puerta a capturarlo mal, y ese error se paga con
        // una prima equivocada.
        //
        // Por eso el formulario muestra una sola sección —"Solicitante"— con los
        // campos del conductor, y la copia se hace AQUÍ. En el servidor, no en
        // el navegador: el navegador se puede manipular y estos dos datos son
        // los que tarifican.
        //
        // El XML que sale a GNP no cambió: sigue llevando sus dos bloques
        // completos, con los mismos valores que llevaría si se capturaran a
        // mano. Lo que se guarda en cot_cotizaciones tampoco cambió de forma.
        //
        // CUANDO SE HAGA LA PANTALLA DE EMISIÓN hay que deshacer esto: ahí el
        // titular sí puede ser otra persona, con otra edad y otro domicilio
        // fiscal. Se quitan estas dos líneas y se devuelven los campos propios
        // del contratante a la vista (están en el historial de git).
        // ─────────────────────────────────────────────────────────────────────
        $f['contratante_edad'] = $f['conductor_edad'];
        $f['contratante_cp']   = $f['conductor_cp'];

        $paquetes = array_values(array_filter((array) ($_POST['paquetes'] ?? [])));

        $faltan = [];
        if ($f['armadora'] === '' || $f['carroceria'] === '' || $f['version'] === '' || $f['modelo'] === 0) {
            $faltan[] = 'el vehículo completo (marca, línea, año y versión)';
        }
        if ($paquetes === []) {
            $faltan[] = 'al menos un paquete';
        }
        // Edad y CP del solicitante son los únicos datos de persona que se
        // exigen: son los que tarifican. El nombre y el RFC son para el
        // documento — si faltan, la cotización sale igual.
        //
        // Se exige que la edad venga capturada, no que sea mayor de 18: para
        // eso está el aviso de abajo. Ver el comentario de $avisos.
        if ($f['conductor_edad'] <= 0 || $f['conductor_cp'] === '') {
            $faltan[] = 'la edad y el código postal del solicitante — son los que determinan el precio';
        }

        if ($faltan !== []) {
            vista('cotizar', ['diag' => CatalogoServicio::diagnostico(),
                              'procedencias' => CatalogoServicio::procedencias(),
                              'error' => 'Falta ' . implode('; falta ', $faltan) . '.',
                              'previo' => $_POST]);
            exit;
        }

        // ─────────────────────────────────────────────────────────────────────
        // La EDAD manda; la fecha de nacimiento es una comodidad para calcularla.
        //
        // En una cotización el cliente no siempre da la fecha completa —muchas
        // veces sólo dice su edad—, así que el campo Edad se puede capturar solo.
        // Y si vienen los dos y no coinciden, se respeta la edad, que es lo que
        // el vendedor escribió a propósito, y la fecha se reconstruye a partir
        // de ella: GNP recibe `EDAD` y `FCH_NACIMIENTO` en el mismo XML y
        // mandarle un par contradictorio es pedirle un rechazo.
        //
        // La pantalla ya avisa cuando no empatan, así que esto no es silencioso.
        // ─────────────────────────────────────────────────────────────────────
        $nac = $f['conductor_nacimiento'];
        $coherente = strlen($nac) === 8
            && ($d = DateTimeImmutable::createFromFormat('Ymd', $nac)) !== false
            && (int) $d->diff(new DateTimeImmutable('today'))->y === $f['conductor_edad'];

        if (!$coherente) {
            $f['conductor_nacimiento'] = (string) (date('Y') - $f['conductor_edad']) . '0101';
        }

        $opcionales = [];
        foreach ((array) ($_POST['opcionales'] ?? []) as $cve) {
            $opcionales[] = ['cve' => (string) $cve, 'suma' => (string) ($_POST['suma_' . $cve] ?? '')];
        }

        // ─────────────────────────────────────────────────────────────────────
        // Avisos: cosas que no detienen la cotización pero hay que decirlas.
        //
        // Menor de 18: en México se necesita mayoría de edad para contratar un
        // seguro, pero hay excepciones —menores emancipados, casos con tutor— y
        // no le toca al sistema decidirlas. Así que no se bloquea: se avisa, se
        // cotiza, y la última palabra la tiene GNP. El aviso viaja hasta la
        // pantalla de resultado para que quede a la vista junto al precio, no
        // sólo en el formulario que ya se cerró.
        // ─────────────────────────────────────────────────────────────────────
        $avisos = [];
        if ($f['conductor_edad'] < 18) {
            $avisos[] = '¡Advertencia! El Solicitante es menor de Edad ('
                      . $f['conductor_edad'] . ' años). La edad mínima para contratar es 18: '
                      . 'si no es un caso de excepción, hay que revisar el dato antes de presentar esta cotización.';
        }

        $res = CotizacionServicio::cotizar($f, $paquetes, $opcionales);

        if (!$res['ok']) {
            vista('cotizar', ['diag' => CatalogoServicio::diagnostico(),
                              'procedencias' => CatalogoServicio::procedencias(),
                              'error' => $res['mensaje'], 'previo' => $_POST]);
            exit;
        }

        // El mensaje que trae la cotización (por ejemplo: "se pidieron 3
        // paquetes y GNP devolvió 2") va junto con los avisos de captura.
        if (($res['mensaje'] ?? '') !== '') {
            $avisos[] = $res['mensaje'];
        }

        redirigir('resultado', array_filter([
            'id'    => $res['cotizacion_id'],
            'aviso' => implode(' ', $avisos),
        ]));

    case 'resultado':
        $id  = (int) ($_GET['id'] ?? 0);
        $cot = CotizacionServicio::obtener($id);
        if ($cot === null) {
            http_response_code(404);
            exit('Cotización no encontrada.');
        }
        vista('resultado', [
            'cot'         => $cot,
            'resultados'  => CotizacionServicio::resultados($id),
            'documentos'  => ImpresionServicio::documentos($id),
            'vencida'     => CotizacionServicio::vencida($cot),
            'aviso'       => (string) ($_GET['aviso'] ?? ''),
        ]);
        exit;

    case 'imprimir':
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('historial');
        }
        $id  = (int) ($_POST['id'] ?? 0);
        $cve = (string) ($_POST['cve'] ?? '');
        $r   = ImpresionServicio::generar($id, $cve);
        redirigir('resultado', ['id' => $id, 'aviso' => $r['ok'] ? 'PDF generado.' : $r['mensaje']]);

    case 'pdf':
        ImpresionServicio::descargar((int) ($_GET['id'] ?? 0));

    // Expediente JSON de la cotización, en dos archivos separados: lo que se
    // le pidió a GNP (parte=peticion) y lo que contestó (parte=respuesta),
    // cada uno con su XML literal de ida o vuelta (sin contraseña).
    case 'evidencia':
        $parte = (string) ($_GET['parte'] ?? 'peticion') === 'respuesta' ? 'respuesta' : 'peticion';
        EvidenciaServicio::descargar((int) ($_GET['id'] ?? 0), $parte);

    // Comparativo Multi-Plan: reporte propio (GNP no lo genera) con primas y
    // coberturas de todos los paquetes cotizados, lado a lado. En PDF (armado
    // sin librerías, con PdfBasico) o en CSV que Excel abre directo.
    case 'comparativo':
        $idComp = (int) ($_GET['id'] ?? 0);
        $cotComp = CotizacionServicio::obtener($idComp);
        if ($cotComp === null) {
            http_response_code(404);
            exit('Cotización no encontrada.');
        }
        if ((string) ($_GET['formato'] ?? 'pdf') === 'excel') {
            $csv = ComparativoServicio::csv($idComp);
            if ($csv === null) {
                http_response_code(404);
                exit('Esta cotización no tiene resultados que comparar.');
            }
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . ComparativoServicio::nombreArchivo($cotComp, 'csv') . '"');
            header('Content-Length: ' . strlen($csv));
            header('X-Content-Type-Options: nosniff');
            echo $csv;
            exit;
        }
        $pdfBytes = ComparativoServicio::pdf($idComp);
        if ($pdfBytes === null) {
            http_response_code(404);
            exit('Esta cotización no tiene resultados que comparar.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . ComparativoServicio::nombreArchivo($cotComp, 'pdf') . '"');
        header('Content-Length: ' . strlen($pdfBytes));
        header('X-Content-Type-Options: nosniff');
        echo $pdfBytes;
        exit;

    case 'historial':
        vista('historial', ['filas' => CotizacionServicio::historial()]);
        exit;

    // ─── Administración de usuarios — sólo administradores ─────────────────
    case 'usuarios':
        Auth::exigirAdmin();
        vista('usuarios', [
            'filas' => UsuarioServicio::listar(),
            'error' => (string) ($_GET['error'] ?? ''),
            'ok'    => (string) ($_GET['ok'] ?? ''),
        ]);
        exit;

    case 'usuarios/crear':
        Auth::exigirAdmin();
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('usuarios');
        }
        $err = UsuarioServicio::crear(
            (string) ($_POST['usuario'] ?? ''),
            (string) ($_POST['nombre'] ?? ''),
            (string) ($_POST['clave'] ?? ''),
            !empty($_POST['es_admin'])
        );
        redirigir('usuarios', $err !== '' ? ['error' => $err] : ['ok' => 'Usuario creado.']);

    case 'usuarios/editar':
        Auth::exigirAdmin();
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('usuarios');
        }
        $err = UsuarioServicio::editar(
            (int) ($_POST['id'] ?? 0),
            (string) ($_POST['nombre'] ?? ''),
            (string) ($_POST['clave'] ?? '')
        );
        redirigir('usuarios', $err !== '' ? ['error' => $err] : ['ok' => 'Cambios guardados.']);

    case 'usuarios/estado':
        Auth::exigirAdmin();
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('usuarios');
        }
        $err = UsuarioServicio::alternarActivo((int) ($_POST['id'] ?? 0));
        redirigir('usuarios', $err !== '' ? ['error' => $err] : []);

    case 'usuarios/admin':
        Auth::exigirAdmin();
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('usuarios');
        }
        $err = UsuarioServicio::alternarAdmin((int) ($_POST['id'] ?? 0));
        redirigir('usuarios', $err !== '' ? ['error' => $err] : []);

    default:
        redirigir('cotizar');
}
