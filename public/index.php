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
          'plataforma/CotizadorAseguradora', 'plataforma/Resultado', 'plataforma/Aseguradoras', 'plataforma/CandadoEmision',
          'aseguradoras/Gnp/AseguradoraGnp',
          'servicios/CatalogoServicio', 'servicios/CotizacionServicio', 'servicios/ImpresionServicio',
          'servicios/EvidenciaServicio', 'servicios/UsuarioServicio', 'servicios/ComparativoServicio',
          'servicios/PlantillaServicio', 'servicios/JuegaYCompararServicio', 'servicios/ArmadorLibreServicio'] as $c) {
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

/**
 * Contexto compartido de la vista `armador` (ADR-007, docs/02.14): lo usan
 * la ruta GET y las dos rutas POST cuando hay que volver a mostrar el
 * formulario (con error, o después de guardar). `$sumaGuardada`/
 * `$dedGuardada` no se recalculan aquí a propósito — quien llama decide si
 * vienen del punto de partida real (`PlantillaServicio::puntoDePartida()`)
 * o de lo que el vendedor acababa de marcar cuando falló el envío.
 */
function armadorContexto(string $modo, ?array $plantilla, string $cvePaquete, array $sumaGuardada, array $dedGuardada): array
{
    return [
        'modo'                => $modo,
        'plantilla'           => $plantilla,
        'paquetesBase'        => CatalogoServicio::paquetes('F', 'Residentes', 'AUT'),
        'cvePaqueteInicial'   => $cvePaquete,
        'coberturasIniciales' => $cvePaquete !== '' ? PlantillaServicio::coberturasDisponibles($cvePaquete) : [],
        'sumaGuardada'        => $sumaGuardada,
        'dedGuardada'         => $dedGuardada,
        'procedencias'        => CatalogoServicio::procedencias(),
    ];
}

/** Reconstruye la combinación de coberturas tal como llegó en el POST — mismo formato que ya usa 'plantillas/guardar'. */
function coberturasDesdePost(array $post): array
{
    $coberturas = [];
    foreach ((array) ($post['coberturas'] ?? []) as $cve) {
        $cve = (string) $cve;
        $coberturas[] = [
            'cve'       => $cve,
            'suma'      => (string) ($post['suma_' . $cve] ?? ''),
            'deducible' => (string) ($post['ded_' . $cve] ?? ''),
        ];
    }
    return $coberturas;
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
                'plantillas'   => PlantillaServicio::activas(),
                'error'        => '',
                'previo'       => [],
            ]);
            exit;
        }

        if (!Auth::tokenValido($_POST['_t'] ?? null)) {
            vista('cotizar', ['diag' => CatalogoServicio::diagnostico(),
                              'procedencias' => CatalogoServicio::procedencias(),
                              'plantillas' => PlantillaServicio::activas(),
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

        $paquetes    = array_values(array_filter((array) ($_POST['paquetes'] ?? [])));
        // Elegir una plantilla (ADR-007) reemplaza la selección manual de
        // paquete: cotiza con el paquete y las coberturas de la plantilla, no
        // con lo marcado abajo. 0 = sin plantilla, flujo manual de siempre.
        $plantillaId = (int) ($_POST['plantilla_id'] ?? 0);

        $faltan = [];
        if ($f['armadora'] === '' || $f['carroceria'] === '' || $f['version'] === '' || $f['modelo'] === 0) {
            $faltan[] = 'el vehículo completo (marca, línea, año y versión)';
        }
        if ($plantillaId <= 0 && $paquetes === []) {
            $faltan[] = 'al menos un paquete (o elige una plantilla propia)';
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
                              'plantillas' => PlantillaServicio::activas(),
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

        // Coberturas sueltas a mano sólo aplican al flujo sin plantilla: al
        // elegir una plantilla, CotizacionServicio::cotizar() arma las suyas
        // propias (revalidadas) y éstas se ignoran, para no mezclar dos
        // fuentes de <COBERTURAS> en la misma llamada.
        $opcionales = [];
        if ($plantillaId <= 0) {
            foreach ((array) ($_POST['opcionales'] ?? []) as $cve) {
                $opcionales[] = ['cve' => (string) $cve, 'suma' => (string) ($_POST['suma_' . $cve] ?? '')];
            }
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

        $res = CotizacionServicio::cotizar($f, $paquetes, $opcionales, $plantillaId > 0 ? $plantillaId : null);

        if (!$res['ok']) {
            vista('cotizar', ['diag' => CatalogoServicio::diagnostico(),
                              'procedencias' => CatalogoServicio::procedencias(),
                              'plantillas' => PlantillaServicio::activas(),
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
        $aseguradoraFiltro = (string) ($_GET['aseguradora'] ?? '');
        vista('historial', [
            'filas'       => CotizacionServicio::historial(50, $aseguradoraFiltro),
            'aseguradora' => $aseguradoraFiltro,
            'aseguradoras' => Aseguradoras::visiblesPara(Db::get(), Auth::esAdmin()),
        ]);
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

    // ─── Módulo Juega y Compara: plantillas propias — sólo administradores ──
    //
    // Construida la pantalla y el CRUD (ADR-007, Tarea C), pero deliberadamente
    // SIN conectar a CotizacionServicio: no se puede cotizar todavía con una
    // plantilla. Esa conexión es una decisión aparte, pendiente de que Producto
    // cierre el contenido de la primera plantilla real.
    case 'plantillas':
        Auth::exigirAdmin();
        vista('plantillas', [
            'filas'        => PlantillaServicio::listar(),
            'paquetesBase' => PlantillaServicio::paquetesBase(),
            'error'        => (string) ($_GET['error'] ?? ''),
            'ok'           => (string) ($_GET['ok'] ?? ''),
            'editarId'     => (int) ($_GET['editar'] ?? 0),
        ]);
        exit;

    case 'plantillas/guardar':
        Auth::exigirAdmin();
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('plantillas');
        }
        $idPlant = (int) ($_POST['id'] ?? 0);
        $r = PlantillaServicio::guardar(
            $idPlant > 0 ? $idPlant : null,
            (string) ($_POST['nombre'] ?? ''),
            (string) ($_POST['cve_paquete'] ?? ''),
            !empty($_POST['activo']),
            coberturasDesdePost($_POST)
        );
        redirigir('plantillas', $r['ok']
            ? ['ok' => $r['mensaje']]
            : ['error' => $r['mensaje'], 'editar' => $idPlant]);

    case 'plantillas/eliminar':
        Auth::exigirAdmin();
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('plantillas');
        }
        $err = PlantillaServicio::eliminar((int) ($_POST['id'] ?? 0));
        redirigir('plantillas', $err !== '' ? ['error' => $err] : ['ok' => 'Plantilla eliminada.']);

    case 'plantillas/estado':
        Auth::exigirAdmin();
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('plantillas');
        }
        $err = PlantillaServicio::alternarActivo((int) ($_POST['id'] ?? 0));
        redirigir('plantillas', $err !== '' ? ['error' => $err] : []);

    // Coberturas disponibles del paquete base elegido, para el selector en
    // cascada. Admin-only, como el resto del módulo.
    case 'plantillas/api-coberturas':
        Auth::exigirAdmin();
        json(['datos' => PlantillaServicio::coberturasDisponibles((string) ($_GET['cve_paquete'] ?? ''))]);

    // ─── Módulo "GNP Juega y Compara" — comparar varias plantillas a la vez ──
    //
    // Ruta nueva y aparte de 'cotizar' (ADR-007, Paso 2): "GNP Cotizador" (la
    // ruta 'cotizar', con su selección manual de paquetes y su "usar
    // plantilla propia") no se toca. Aquí SIEMPRE se eligen una o más
    // plantillas — nunca paquetes sueltos — y se cotizan todas en una sola
    // llamada, lado a lado. Comparte pantalla de resultado con 'cotizar'
    // (misma tabla comparativa, mismo cot_cotizaciones/cot_resultados).
    case 'juega-y-compara':
        if (!$post) {
            vista('juega_y_compara', [
                'diag'         => CatalogoServicio::diagnostico(),
                'procedencias' => CatalogoServicio::procedencias(),
                'plantillas'   => PlantillaServicio::activas(),
                'error'        => '',
                'previo'       => [],
            ]);
            exit;
        }

        if (!Auth::tokenValido($_POST['_t'] ?? null)) {
            vista('juega_y_compara', [
                'diag' => CatalogoServicio::diagnostico(), 'procedencias' => CatalogoServicio::procedencias(),
                'plantillas' => PlantillaServicio::activas(),
                'error' => 'La sesión expiró. Vuelve a enviar el formulario.', 'previo' => $_POST,
            ]);
            exit;
        }

        // Mismo shape de $f que la ruta 'cotizar' — JuegaYCompararServicio lo
        // espera igual. Se repite aquí en vez de compartir código con esa
        // ruta a propósito: 'cotizar' no se toca (ver comentario de arriba).
        $fjc = [
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
        // El contratante hereda edad y CP del conductor — mismo criterio y
        // misma razón que en 'cotizar' (ver el comentario grande de esa ruta).
        $fjc['contratante_edad'] = $fjc['conductor_edad'];
        $fjc['contratante_cp']   = $fjc['conductor_cp'];

        $plantillaIdsJc = array_map('intval', array_values(array_filter((array) ($_POST['plantillas'] ?? []))));

        $faltanJc = [];
        if ($fjc['armadora'] === '' || $fjc['carroceria'] === '' || $fjc['version'] === '' || $fjc['modelo'] === 0) {
            $faltanJc[] = 'el vehículo completo (marca, línea, año y versión)';
        }
        if ($plantillaIdsJc === []) {
            $faltanJc[] = 'al menos una plantilla para comparar';
        }
        if ($fjc['conductor_edad'] <= 0 || $fjc['conductor_cp'] === '') {
            $faltanJc[] = 'la edad y el código postal del solicitante — son los que determinan el precio';
        }
        if ($faltanJc !== []) {
            vista('juega_y_compara', [
                'diag' => CatalogoServicio::diagnostico(), 'procedencias' => CatalogoServicio::procedencias(),
                'plantillas' => PlantillaServicio::activas(),
                'error' => 'Falta ' . implode('; falta ', $faltanJc) . '.', 'previo' => $_POST,
            ]);
            exit;
        }

        // Misma lógica de edad-vs-fecha que 'cotizar' (ver el comentario
        // grande de esa ruta): la EDAD manda, la fecha es sólo comodidad.
        $nacJc = $fjc['conductor_nacimiento'];
        $coherenteJc = strlen($nacJc) === 8
            && ($dJc = DateTimeImmutable::createFromFormat('Ymd', $nacJc)) !== false
            && (int) $dJc->diff(new DateTimeImmutable('today'))->y === $fjc['conductor_edad'];
        if (!$coherenteJc) {
            $fjc['conductor_nacimiento'] = (string) (date('Y') - $fjc['conductor_edad']) . '0101';
        }

        $avisosJc = [];
        if ($fjc['conductor_edad'] < 18) {
            $avisosJc[] = '¡Advertencia! El Solicitante es menor de Edad ('
                        . $fjc['conductor_edad'] . ' años). La edad mínima para contratar es 18: '
                        . 'si no es un caso de excepción, hay que revisar el dato antes de presentar esta cotización.';
        }

        $resJc = JuegaYCompararServicio::cotizar($fjc, $plantillaIdsJc);

        if (!$resJc['ok']) {
            vista('juega_y_compara', [
                'diag' => CatalogoServicio::diagnostico(), 'procedencias' => CatalogoServicio::procedencias(),
                'plantillas' => PlantillaServicio::activas(),
                'error' => $resJc['mensaje'], 'previo' => $_POST,
            ]);
            exit;
        }
        if (($resJc['mensaje'] ?? '') !== '') {
            $avisosJc[] = $resJc['mensaje'];
        }

        redirigir('resultado', array_filter([
            'id'    => $resJc['cotizacion_id'],
            'aviso' => implode(' ', $avisosJc),
        ]));

    // ─── Armador libre de coberturas — Fase 2 (ADR-007, docs/02.14) ─────────
    //
    // Dos entradas a la MISMA pantalla: "Personalizar" trae un plantilla_id
    // de partida (sus coberturas guardadas, editables, sin tocar la
    // plantilla oficial); "Armar desde cero" no trae ninguno (empieza vacío
    // sobre el paquete base que se elija). No toca cotizar.php ni la lógica
    // ya construida en PlantillaServicio/ArmadorLibreServicio — sólo la capa
    // de pantalla, reutilizando puntoDePartida()/paraAdHoc()/cotizar() tal cual.
    case 'armador':
        $plantillaIdGet = (int) ($_GET['plantilla_id'] ?? 0);
        $modo = $plantillaIdGet > 0 ? 'plantilla' : 'libre';
        $plantillaGet = $modo === 'plantilla' ? PlantillaServicio::obtener($plantillaIdGet) : null;
        if ($modo === 'plantilla' && $plantillaGet === null) {
            redirigir('juega-y-compara', ['error' => 'Esa plantilla ya no existe.']);
        }
        $cvePaqueteGet = $modo === 'plantilla' ? $plantillaGet['cve_paquete'] : (string) ($_GET['cve_paquete'] ?? '');

        $partidaGet = PlantillaServicio::puntoDePartida($cvePaqueteGet, $modo === 'plantilla' ? $plantillaIdGet : null);
        $sumaGet = []; $dedGet = [];
        foreach ($partidaGet['coberturas'] as $c) {
            $sumaGet[$c['cve']] = $c['suma'];
            $dedGet[$c['cve']]  = $c['deducible'];
        }

        vista('armador', armadorContexto($modo, $plantillaGet, $cvePaqueteGet, $sumaGet, $dedGet) + [
            'error' => $partidaGet['ok'] ? (string) ($_GET['error'] ?? '') : $partidaGet['mensaje'],
            'ok'    => (string) ($_GET['ok'] ?? ''),
            'previo' => [],
        ]);
        exit;

    case 'armador/cotizar':
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('armador');
        }

        $plantillaIdPost = (int) ($_POST['plantilla_id'] ?? 0);
        $modoPost = $plantillaIdPost > 0 ? 'plantilla' : 'libre';
        $plantillaPost = $modoPost === 'plantilla' ? PlantillaServicio::obtener($plantillaIdPost) : null;
        $cvePaquetePost = (string) ($_POST['cve_paquete'] ?? '');
        $coberturasPost2 = coberturasDesdePost($_POST);

        // Mismo shape de $f que 'cotizar'/'juega-y-compara' — ArmadorLibreServicio
        // lo espera igual. Se repite aquí a propósito: 'cotizar' no se toca.
        $fArm = [
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
        $fArm['contratante_edad'] = $fArm['conductor_edad'];
        $fArm['contratante_cp']   = $fArm['conductor_cp'];

        $faltanArm = [];
        if ($fArm['armadora'] === '' || $fArm['carroceria'] === '' || $fArm['version'] === '' || $fArm['modelo'] === 0) {
            $faltanArm[] = 'el vehículo completo (marca, línea, año y versión)';
        }
        if ($cvePaquetePost === '') {
            $faltanArm[] = 'el paquete base';
        }
        if ($coberturasPost2 === []) {
            $faltanArm[] = 'al menos una cobertura';
        }
        if ($fArm['conductor_edad'] <= 0 || $fArm['conductor_cp'] === '') {
            $faltanArm[] = 'la edad y el código postal del solicitante — son los que determinan el precio';
        }
        if ($faltanArm !== []) {
            $sumaArm = []; $dedArm = [];
            foreach ($coberturasPost2 as $c) { $sumaArm[$c['cve']] = $c['suma']; $dedArm[$c['cve']] = $c['deducible']; }
            vista('armador', armadorContexto($modoPost, $plantillaPost, $cvePaquetePost, $sumaArm, $dedArm) + [
                'error' => 'Falta ' . implode('; falta ', $faltanArm) . '.', 'ok' => '', 'previo' => $_POST,
            ]);
            exit;
        }

        // Misma lógica de edad-vs-fecha que 'cotizar' (ver su comentario grande).
        $nacArm = $fArm['conductor_nacimiento'];
        $coherenteArm = strlen($nacArm) === 8
            && ($dArm = DateTimeImmutable::createFromFormat('Ymd', $nacArm)) !== false
            && (int) $dArm->diff(new DateTimeImmutable('today'))->y === $fArm['conductor_edad'];
        if (!$coherenteArm) {
            $fArm['conductor_nacimiento'] = (string) (date('Y') - $fArm['conductor_edad']) . '0101';
        }

        $avisosArm = [];
        if ($fArm['conductor_edad'] < 18) {
            $avisosArm[] = '¡Advertencia! El Solicitante es menor de Edad (' . $fArm['conductor_edad'] . ' años). '
                         . 'La edad mínima para contratar es 18: si no es un caso de excepción, hay que revisar el dato.';
        }

        $resArm = ArmadorLibreServicio::cotizar($fArm, $cvePaquetePost, $coberturasPost2);

        if (!$resArm['ok']) {
            $sumaArm = []; $dedArm = [];
            foreach ($coberturasPost2 as $c) { $sumaArm[$c['cve']] = $c['suma']; $dedArm[$c['cve']] = $c['deducible']; }
            vista('armador', armadorContexto($modoPost, $plantillaPost, $cvePaquetePost, $sumaArm, $dedArm) + [
                'error' => $resArm['mensaje'], 'ok' => '', 'previo' => $_POST,
            ]);
            exit;
        }
        if (($resArm['mensaje'] ?? '') !== '') {
            $avisosArm[] = $resArm['mensaje'];
        }

        redirigir('resultado', array_filter([
            'id'    => $resArm['cotizacion_id'],
            'aviso' => implode(' ', $avisosArm),
        ]));

    case 'armador/guardar':
        if (!$post || !Auth::tokenValido($_POST['_t'] ?? null)) {
            redirigir('armador');
        }

        $plantillaIdPost2 = (int) ($_POST['plantilla_id'] ?? 0);
        $modoPost2 = $plantillaIdPost2 > 0 ? 'plantilla' : 'libre';
        $plantillaPost2 = $modoPost2 === 'plantilla' ? PlantillaServicio::obtener($plantillaIdPost2) : null;
        $cvePaquetePost2 = (string) ($_POST['cve_paquete'] ?? '');
        $coberturasPost3 = coberturasDesdePost($_POST);

        $rGuardarArm = PlantillaServicio::guardar(
            null,
            (string) ($_POST['nombre_nueva_plantilla'] ?? ''),
            $cvePaquetePost2,
            true,
            $coberturasPost3
        );

        if (!$rGuardarArm['ok']) {
            $sumaArm2 = []; $dedArm2 = [];
            foreach ($coberturasPost3 as $c) { $sumaArm2[$c['cve']] = $c['suma']; $dedArm2[$c['cve']] = $c['deducible']; }
            vista('armador', armadorContexto($modoPost2, $plantillaPost2, $cvePaquetePost2, $sumaArm2, $dedArm2) + [
                'error' => $rGuardarArm['mensaje'], 'ok' => '', 'previo' => $_POST,
            ]);
            exit;
        }

        // Reabre el armador sobre la plantilla recién creada: misma combinación,
        // ahora ya guardada — así se puede seguir cotizando con ella de una vez.
        redirigir('armador', ['plantilla_id' => $rGuardarArm['id'], 'ok' => 'Plantilla guardada como "' . (string) ($_POST['nombre_nueva_plantilla'] ?? '') . '".']);

    default:
        redirigir('cotizar');
}
