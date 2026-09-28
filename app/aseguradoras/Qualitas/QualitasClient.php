<?php
declare(strict_types=1);

/**
 * QualitasClient — habla SOAP 1.1 con los dos servicios de Qualitas:
 *
 *   WsEmision.asmx  cotización (obtenerNuevaEmision) y prueba de conexión (Test, HolamundoAux)
 *   wsTarifa.asmx   catálogo de vehículos (listaMarcas, listaTarifas)
 *
 * CANDADO DOBLE. Qualitas cotiza y emite con el MISMO método
 * (obtenerNuevaEmision); lo único que cambia es TipoMovimiento dentro del
 * XML (2 cotiza, 3 emite, 4 endosa). El candado por ruta de la plataforma
 * (CandadoEmision::validarRuta) se usa igual, pero aquí no alcanza: la ruta
 * es la misma para cotizar y para emitir. Por eso, antes de CADA envío:
 *
 *   1. El método tiene que estar en la lista permitida. EnviaMail y
 *      obtenerNuevaEmisionDXN (no documentado) están bloqueados.
 *   2. validarRuta() sobre la URL y el método (candado común, ADR-010 punto 4).
 *   3. Candado por contenido: TipoMovimiento exactamente "2", NoPoliza,
 *      NoEndoso y TipoEndoso vacíos, consideración 04 igual al ambiente.
 *   4. Se vuelve a leer el sobre SOAP ya armado y se repite el paso 3 sobre
 *      lo que de verdad va a salir.
 *
 * Si algo no cuadra se lanza RuntimeException y NO sale nada.
 *
 * Éxito = <CodigoError> vacío. El código HTTP no decide (ADR-005, punto 5).
 * Toda llamada que sale queda en sys_llamadas con aseguradora='QUALITAS' y
 * con cUsuario/cTarifa enmascarados (ADR-006).
 */
final class QualitasClient
{
    use CandadoEmision;

    public const OK        = CotizadorAseguradora::OK;
    public const E_AUTH    = CotizadorAseguradora::E_AUTH;
    public const E_DATOS   = CotizadorAseguradora::E_DATOS;
    public const E_SISTEMA = CotizadorAseguradora::E_SISTEMA;
    public const E_TIMEOUT = CotizadorAseguradora::E_TIMEOUT;
    public const E_RED     = CotizadorAseguradora::E_RED;

    /** Namespace del WSDL de WsEmision (imagen de la pág. 7 del manual de Servicios Web). */
    public const NS_EMISION = 'http://qualitas.com.mx/';

    /** Métodos que este sistema puede llamar, y en qué servicio vive cada uno. */
    private const METODOS_PERMITIDOS = [
        'obtenerNuevaEmision' => 'emision',
        'Test'                => 'emision',
        'HolamundoAux'        => 'emision',
        'listaMarcas'         => 'tarifa',
        'listaTarifas'        => 'tarifa',
    ];

    /** Bloqueados de forma explícita, aunque ya queden fuera de la lista permitida. */
    private const METODOS_BLOQUEADOS = ['EnviaMail', 'obtenerNuevaEmisionDXN'];

    /**
     * Código de <CodigoError> → categoría (docs/aseguradoras/qualitas/00-estado.md).
     * [PENDIENTE] hasta verlos llegar. Lo que no esté aquí es DATOS.
     */
    private const CODIGOS_AUTH    = [2, 3, 4, 5, 26, 36, 59, 63, 200, 207, 310];
    private const CODIGOS_SISTEMA = [100, 172, 231, 316, 340];

    /** wsTarifa, nodo <retorno><codigo>: 1 faltan usuario/tarifa, 3 usuario no existe, 4 sin permiso. */
    private const TARIFA_AUTH = [1, 3, 4];

    /** Mismo tope de evidencia que GNP (CotizacionServicio::TOPE_EVIDENCIA). */
    private const TOPE_EVIDENCIA = 262144;

    private readonly Closure $transporte;
    private readonly Closure $bitacora;

    /**
     * @param array{
     *   ambiente:string, url_emision:string, parametro_emision:string,
     *   url_tarifa:string, ns_tarifa:string, catalogo_usuario:string, catalogo_tarifa:string,
     *   negocio:string, agente:string, derechos:string, pronto_pago_dias:string, tarifa:string,
     *   timeout:int
     * } $config
     * @param Closure|null $transporte fn(string $url, string $cuerpo, list<string> $encabezados, int $timeout): array{http:int, cuerpo:string, errno:int, error:string}
     *                                 Por omisión, cURL. Las pruebas sin red pasan uno que no sale a ningún lado.
     * @param Closure|null $bitacora   fn(array $registro): int  — por omisión, INSERT en sys_llamadas.
     */
    public function __construct(
        private readonly array $config,
        ?Closure $transporte = null,
        ?Closure $bitacora = null,
    ) {
        if (!in_array($config['ambiente'], ['QA', 'PRODUCCION'], true)) {
            throw new InvalidArgumentException("Ambiente de Qualitas desconocido: \"{$config['ambiente']}\" (QA o PRODUCCION).");
        }
        $this->transporte = $transporte ?? self::transporteCurl(...);
        $this->bitacora   = $bitacora ?? self::registrarEnBitacora(...);
    }

    /** Cliente con la configuración QUALITAS_* de config/.env.local (ADR-010, punto 10). */
    public static function desdeEnv(?Closure $transporte = null, ?Closure $bitacora = null): self
    {
        $ambiente = strtoupper(Env::get('QUALITAS_AMBIENTE', 'QA'));
        $url = $ambiente === 'PRODUCCION' ? Env::get('QUALITAS_URL_PRODUCCION') : Env::get('QUALITAS_URL_QA');
        if ($url === '') {
            throw new RuntimeException(
                $ambiente === 'PRODUCCION'
                    ? 'QUALITAS_URL_PRODUCCION está vacía: no se llama a producción hasta que se configure a propósito.'
                    : 'Falta QUALITAS_URL_QA en config/.env.local.'
            );
        }

        $dias = Env::get('QUALITAS_PRONTO_PAGO_DIAS', '14');
        if (!ctype_digit($dias) || (int) $dias < 1 || (int) $dias > 14) {
            // Error 192 de Qualitas: "El periodo de Gracia no puede exceder los 14 días".
            throw new RuntimeException("QUALITAS_PRONTO_PAGO_DIAS debe ser de 1 a 14: \"{$dias}\".");
        }

        return new self([
            'ambiente'          => $ambiente,
            'url_emision'       => $url,
            'parametro_emision' => Env::get('QUALITAS_WS_PARAMETRO'),
            'url_tarifa'        => Env::get('QUALITAS_TARIFAS_URL'),
            'ns_tarifa'         => Env::get('QUALITAS_TARIFAS_NS'),
            'catalogo_usuario'  => Env::get('QUALITAS_CATALOGO_USUARIO'),
            'catalogo_tarifa'   => Env::get('QUALITAS_CATALOGO_TARIFA'),
            'negocio'           => Env::requerir('QUALITAS_NEGOCIO'),
            'agente'            => Env::requerir('QUALITAS_AGENTE'),
            'derechos'          => Env::get('QUALITAS_DERECHOS', '750'),
            'pronto_pago_dias'  => $dias,
            'tarifa'            => Env::get('QUALITAS_TARIFA', 'LINEA'),
            'timeout'           => (int) Env::get('QUALITAS_TIMEOUT', '60'),
        ], $transporte, $bitacora);
    }

    /** Lo que QualitasXml::cotizacion() necesita de la configuración. */
    public function configXml(): array
    {
        return [
            'negocio'          => $this->config['negocio'],
            'agente'           => $this->config['agente'],
            'derechos'         => $this->config['derechos'],
            'pronto_pago_dias' => $this->config['pronto_pago_dias'],
            'tarifa'           => $this->config['tarifa'],
            'ambiente_pruebas' => $this->config['ambiente'] === 'QA',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Botones
    // ─────────────────────────────────────────────────────────────────────

    /** Comprobación de la URL: Test o HolamundoAux. No cotiza nada. */
    public function prueba(string $metodo = 'Test'): array
    {
        if (!in_array($metodo, ['Test', 'HolamundoAux'], true)) {
            throw new RuntimeException("BLOQUEADO: \"{$metodo}\" no es un método de prueba.");
        }
        return $this->enviar($metodo, [], 'prueba', null, "QUALITAS {$metodo}");
    }

    /**
     * Descarga la descripción del servicio de emisión (GET …?WSDL). No
     * ejecuta ningún método; sirve para leer los nombres de parámetro que
     * la imagen del manual no deja ver. Queda en la bitácora igual.
     */
    public function wsdl(): array
    {
        $url = $this->config['url_emision'] . '?WSDL';
        $this->validarRuta($url);

        $inicio = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $respuesta = curl_exec($ch);
        $errno = $respuesta === false ? curl_errno($ch) : 0;
        $error = $respuesta === false ? (curl_error($ch) ?: 'error de red') : '';
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $crudo = is_string($respuesta) ? $respuesta : '';

        $estado = self::OK;
        $err = null;
        if ($errno !== 0) {
            $estado = $errno === 28 ? self::E_TIMEOUT : self::E_RED;
            $err = self::error($error, (string) $errno, 'curl');
        } elseif (self::cargar($crudo) === null) {
            $estado = self::E_SISTEMA;
            $err = self::error("El WSDL no es XML válido (HTTP {$http}).", (string) $http, 'parser');
        }

        $r = [
            'servicio' => 'wsdl', 'metodo' => 'WSDL', 'http' => $http,
            'ms' => (int) round((microtime(true) - $inicio) * 1000), 'bytes' => strlen($crudo),
            'estado' => $estado, 'error' => $err, 'xml_entrada' => 'GET ' . $url, 'xml_salida' => $crudo,
        ];
        $r['llamada_id'] = ($this->bitacora)([
            'cotizacion_id' => null, 'servicio' => 'wsdl', 'detalle' => 'QUALITAS GET ?WSDL',
            'estado' => $estado, 'http' => $http, 'ms' => $r['ms'], 'bytes' => $r['bytes'],
            'error_clave' => $err['clave'] ?? null, 'error_origen' => $err['origen'] ?? null, 'error_desc' => $err['descripcion'] ?? null,
            'xml_entrada' => $r['xml_entrada'], 'xml_salida' => $crudo,
        ]);
        return $r;
    }

    /**
     * Envía un XML de cotización ya armado (QualitasXml::cotizacion()).
     * Devuelve el estado y los movimientos interpretados.
     */
    public function cotizar(string $xmlMovimientos, ?int $cotizacionId = null, string $detalle = ''): array
    {
        $parametro = $this->config['parametro_emision'];
        if ($parametro === '') {
            throw new RuntimeException(
                'Falta QUALITAS_WS_PARAMETRO: el nombre del parámetro de obtenerNuevaEmision no se ve en la '
                . 'imagen del WSDL del manual [PENDIENTE]. No se envía nada hasta tenerlo.'
            );
        }
        return $this->enviar(
            'obtenerNuevaEmision',
            [$parametro => $xmlMovimientos],
            'cotizar',
            $cotizacionId,
            $detalle !== '' ? $detalle : 'QUALITAS obtenerNuevaEmision TipoMovimiento=2'
        );
    }

    public function listaMarcas(): array
    {
        return $this->enviar('listaMarcas', $this->credencialesCatalogo(), 'catalogo', null, 'QUALITAS listaMarcas');
    }

    /**
     * Catálogo de vehículos, SIEMPRE filtrado por marca y modelo: pedir todo
     * de golpe es lo que en GNP terminó en 504 (ADR-005, punto 7).
     *
     * @param array{marca:string, modelo:string, tipo?:string, version?:string, amis?:string, categoria?:string, nva_amis?:string} $f
     */
    public function listaTarifas(array $f): array
    {
        $marca  = trim((string) ($f['marca'] ?? ''));
        $modelo = trim((string) ($f['modelo'] ?? ''));
        if ($marca === '' || $modelo === '') {
            throw new RuntimeException('BLOQUEADO: listaTarifas se pide filtrada por marca y modelo, nunca completa.');
        }

        // Nombres de parámetro del manual WSTARIFAS. "cCategoría" viene con acento
        // en el documento; se manda sin acento [PENDIENTE].
        $p = $this->credencialesCatalogo() + [
            'cMarca'     => $marca,
            'cTipo'      => trim((string) ($f['tipo'] ?? '')),
            'cVersion'   => trim((string) ($f['version'] ?? '')),
            'cModelo'    => $modelo,
            'cCAMIS'     => trim((string) ($f['amis'] ?? '')),
            'cCategoria' => trim((string) ($f['categoria'] ?? '')),
            'cNvaAMIS'   => trim((string) ($f['nva_amis'] ?? '')),
        ];

        return $this->enviar('listaTarifas', $p, 'catalogo', null, "QUALITAS listaTarifas {$marca} {$modelo}");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Candado por contenido
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Revisa el XML de movimientos que se pretende mandar. Lanza si no es una
     * cotización pura. Es público para poder probarlo directamente.
     */
    public function validarContenido(string $xmlMovimientos): void
    {
        $bloquear = static function (string $motivo): never {
            throw new RuntimeException("BLOQUEADO: {$motivo} Este sistema es sólo de cotización (TipoMovimiento=\"2\").");
        };

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xmlMovimientos)) {
            $bloquear('el XML trae DOCTYPE o ENTITY.');
        }

        $dom = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xmlMovimientos, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (!$ok || $dom->documentElement === null) {
            $bloquear('el XML de movimientos no se pudo leer.');
        }
        if ($dom->documentElement->localName !== 'Movimientos') {
            $bloquear('la raíz no es <Movimientos>.');
        }

        $xp = new DOMXPath($dom);
        $movimientos = $xp->query('//*[local-name()="Movimiento"]');
        if ($movimientos === false || $movimientos->length === 0) {
            $bloquear('no hay ningún <Movimiento>.');
        }

        // TipoMovimiento, en CUALQUIER lugar donde aparezca (atributo o elemento),
        // tiene que ser exactamente "2".
        foreach ($xp->query('//@*[local-name()="TipoMovimiento"] | //*[local-name()="TipoMovimiento"]') as $n) {
            if ($n->nodeValue !== '2') {
                $bloquear("TipoMovimiento=\"{$n->nodeValue}\".");
            }
        }
        foreach ($movimientos as $m) {
            /** @var DOMElement $m */
            if ($m->getAttribute('TipoMovimiento') !== '2') {
                $bloquear('un <Movimiento> no trae TipoMovimiento="2".');
            }
        }

        // NoPoliza, NoEndoso y TipoEndoso vacíos: con número de póliza el 3 emite
        // con ese número y el 4 endosa esa póliza.
        foreach (['NoPoliza', 'NoEndoso', 'TipoEndoso'] as $campo) {
            foreach ($xp->query("//@*[local-name()=\"{$campo}\"] | //*[local-name()=\"{$campo}\"]") as $n) {
                if (trim((string) $n->nodeValue) !== '') {
                    $bloquear("{$campo} trae valor (\"{$n->nodeValue}\").");
                }
            }
        }

        // Consideración 04 (ambiente): 1 pruebas, 0 producción. Tiene que venir y
        // tiene que coincidir con la URL a la que se va a mandar.
        $esperado = $this->config['ambiente'] === 'QA' ? '1' : '0';
        $c04 = $xp->query('//*[local-name()="ConsideracionesAdicionalesDG"][@NoConsideracion="4" or @NoConsideracion="04"]/*[local-name()="ValorRegla"]');
        if ($c04 === false || $c04->length !== $movimientos->length) {
            $bloquear('falta la consideración 04 (ambiente) en algún movimiento.');
        }
        foreach ($c04 as $n) {
            if (trim((string) $n->nodeValue) !== $esperado) {
                $bloquear("la consideración 04 dice \"{$n->nodeValue}\" y el ambiente es {$this->config['ambiente']}.");
            }
        }
    }

    /** Lista permitida de métodos + servicio al que pertenece cada uno. */
    private function validarMetodo(string $metodo): string
    {
        if (in_array($metodo, self::METODOS_BLOQUEADOS, true)) {
            throw new RuntimeException("BLOQUEADO: el método \"{$metodo}\" está prohibido en este sistema.");
        }
        if (!isset(self::METODOS_PERMITIDOS[$metodo])) {
            throw new RuntimeException("BLOQUEADO: el método \"{$metodo}\" no está en la lista permitida.");
        }
        return self::METODOS_PERMITIDOS[$metodo];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Envío
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,string> $parametros */
    private function enviar(string $metodo, array $parametros, string $servicio, ?int $cotizacionId, string $detalle): array
    {
        // 1. Lista permitida.
        $destino = $this->validarMetodo($metodo);

        if ($destino === 'emision') {
            $url = $this->config['url_emision'];
            $ns  = self::NS_EMISION;
        } else {
            $url = $this->config['url_tarifa'];
            $ns  = $this->config['ns_tarifa'];
            if ($url === '') {
                throw new RuntimeException('QUALITAS_TARIFAS_URL está vacía: el catálogo sólo tiene URL de producción y cada descarga se autoriza a propósito.');
            }
            if ($ns === '') {
                throw new RuntimeException('Falta QUALITAS_TARIFAS_NS: el namespace de wsTarifa no está documentado [PENDIENTE].');
            }
        }
        if ($url === '') {
            throw new RuntimeException('La URL de Qualitas está vacía.');
        }

        // 2. Candado común por ruta.
        $this->validarRuta($url . '#' . $metodo);

        // 3. Candado por contenido.
        if ($metodo === 'obtenerNuevaEmision') {
            if (count($parametros) !== 1) {
                throw new RuntimeException('BLOQUEADO: obtenerNuevaEmision lleva exactamente un parámetro (el XML).');
            }
            $this->validarContenido((string) reset($parametros));
        }

        $sobre = self::sobre($metodo, $ns, $parametros);

        // 4. Lo que va a salir de verdad se vuelve a revisar.
        if ($metodo === 'obtenerNuevaEmision') {
            $this->validarContenido(self::parametroDelSobre($sobre, $metodo));
        } elseif (preg_match('/TipoMovimiento/i', $sobre)) {
            throw new RuntimeException("BLOQUEADO: \"{$metodo}\" no puede llevar movimientos.");
        }

        $encabezados = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $ns . $metodo . '"',
        ];

        $inicio = microtime(true);
        $t = ($this->transporte)($url, $sobre, $encabezados, (int) $this->config['timeout']);
        $crudo = (string) ($t['cuerpo'] ?? '');

        $r = [
            'servicio'    => $servicio,
            'metodo'      => $metodo,
            'http'        => (int) ($t['http'] ?? 0),
            'ms'          => (int) round((microtime(true) - $inicio) * 1000),
            'bytes'       => strlen($crudo),
            'estado'      => self::OK,
            'error'       => null,
            'xml_entrada' => self::sinCredenciales($sobre),
            'xml_salida'  => $crudo,
        ];

        if (($t['errno'] ?? 0) !== 0) {
            // 28 = CURLE_OPERATION_TIMEDOUT
            $r['estado'] = (int) $t['errno'] === 28 ? self::E_TIMEOUT : self::E_RED;
            $r['error']  = self::error((string) ($t['error'] ?? 'error de red'), (string) $t['errno'], 'curl');
        } elseif (trim($crudo) === '') {
            $r['estado'] = self::E_RED;
            $r['error']  = self::error("Respuesta vacía (HTTP {$r['http']}).", (string) $r['http'], 'red');
        } else {
            $r = array_merge($r, self::interpretarRespuesta($crudo, $metodo, $r['http']));
        }

        $r['llamada_id'] = ($this->bitacora)([
            'cotizacion_id' => $cotizacionId,
            'servicio'      => $servicio,
            'detalle'       => $detalle,
            'estado'        => $r['estado'],
            'http'          => $r['http'],
            'ms'            => $r['ms'],
            'bytes'         => $r['bytes'],
            'error_clave'   => $r['error']['clave'] ?? null,
            'error_origen'  => $r['error']['origen'] ?? null,
            'error_desc'    => $r['error']['descripcion'] ?? null,
            'xml_entrada'   => $r['xml_entrada'],
            'xml_salida'    => $r['xml_salida'],
        ]);

        return $r;
    }

    /** Sobre SOAP 1.1. Los parámetros van como texto (el XML de movimientos, escapado). */
    private static function sobre(string $metodo, string $ns, array $parametros): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $cuerpo = '';
        foreach ($parametros as $nombre => $valor) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $nombre)) {
                throw new RuntimeException("Nombre de parámetro SOAP inválido: \"{$nombre}\".");
            }
            $cuerpo .= "      <{$nombre}>{$e((string) $valor)}</{$nombre}>\n";
        }

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
             . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
             . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' . "\n"
             . "  <soap:Body>\n"
             . "    <{$metodo} xmlns=\"{$e($ns)}\">\n"
             . $cuerpo
             . "    </{$metodo}>\n"
             . "  </soap:Body>\n"
             . '</soap:Envelope>';
    }

    /** Saca del sobre ya armado el texto del único parámetro de obtenerNuevaEmision. */
    private static function parametroDelSobre(string $sobre, string $metodo): string
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($sobre, LIBXML_NONET)) {
            throw new RuntimeException('BLOQUEADO: el sobre SOAP armado no se pudo leer.');
        }
        $xp = new DOMXPath($dom);
        $n = $xp->query("//*[local-name()=\"Body\"]/*[local-name()=\"{$metodo}\"]/*");
        if ($n === false || $n->length !== 1) {
            throw new RuntimeException('BLOQUEADO: el sobre SOAP no trae exactamente un parámetro.');
        }
        return (string) $n->item(0)->textContent;
    }

    private function credencialesCatalogo(): array
    {
        if ($this->config['catalogo_usuario'] === '' || $this->config['catalogo_tarifa'] === '') {
            throw new RuntimeException('Qualitas no ha entregado cUsuario/cTarifa para el catálogo (QUALITAS_CATALOGO_USUARIO / QUALITAS_CATALOGO_TARIFA).');
        }
        return ['cUsuario' => $this->config['catalogo_usuario'], 'cTarifa' => $this->config['catalogo_tarifa']];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Respuesta
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Interpreta el cuerpo de respuesta. Público y estático para poder
     * probarlo con respuestas SIMULADAS sin red.
     *
     * La forma exacta de la respuesta (si <obtenerNuevaEmisionResult> trae el
     * XML escapado como texto o como elementos) no está documentada
     * [PENDIENTE]: se aceptan las dos.
     */
    public static function interpretarRespuesta(string $crudo, string $metodo, int $http = 200): array
    {
        $dom = self::cargar($crudo);
        if ($dom === null) {
            return ($http === 504 || $http === 408)
                ? ['estado' => self::E_TIMEOUT, 'error' => self::error("El servicio no respondió a tiempo (HTTP {$http}).", (string) $http, 'gateway')]
                : ['estado' => self::E_SISTEMA, 'error' => self::error("La respuesta no es XML válido (HTTP {$http}).", (string) $http, 'parser')];
        }

        $xp = new DOMXPath($dom);
        $fault = $xp->query('//*[local-name()="Fault"]');
        if ($fault !== false && $fault->length > 0) {
            $txt = $xp->query('//*[local-name()="Fault"]/*[local-name()="faultstring"]');
            $msg = ($txt !== false && $txt->length > 0) ? trim((string) $txt->item(0)->textContent) : 'SOAP Fault sin detalle';
            return ['estado' => self::E_SISTEMA, 'error' => self::error($msg, (string) $http, 'soap-fault')];
        }

        $res = $xp->query("//*[local-name()=\"{$metodo}Result\"]");
        if ($res === false || $res->length === 0) {
            return ['estado' => self::E_SISTEMA, 'error' => self::error("La respuesta no trae <{$metodo}Result>.", '', 'parser')];
        }
        /** @var DOMElement $nodo */
        $nodo = $res->item(0);

        return match ($metodo) {
            'obtenerNuevaEmision'         => self::interpretarMovimientos(self::contenido($nodo, 'Movimientos')),
            'listaMarcas', 'listaTarifas' => self::interpretarTarifa(self::contenido($nodo, 'salida')),
            default                       => ['estado' => self::OK, 'texto' => trim((string) $nodo->textContent)],
        };
    }

    /**
     * Movimientos de la respuesta. Un movimiento con <CodigoError> vacío es
     * éxito; cualquier otra cosa se clasifica por su código y el mensaje se
     * conserva tal cual.
     */
    private static function interpretarMovimientos(?DOMDocument $dom): array
    {
        if ($dom === null) {
            return ['estado' => self::E_SISTEMA, 'error' => self::error('La respuesta no trae <Movimientos>.', '', 'parser'), 'movimientos' => []];
        }

        $xp  = new DOMXPath($dom);
        $txt = static function (DOMNode $ctx, string $ruta) use ($xp): string {
            $n = $xp->query($ruta, $ctx);
            return ($n !== false && $n->length > 0) ? trim((string) $n->item(0)->textContent) : '';
        };
        $num = static function (string $v): ?float {
            $v = str_replace([',', '$', ' '], '', $v);
            return is_numeric($v) ? (float) $v : null;
        };

        $movs = [];
        foreach ($xp->query('//*[local-name()="Movimiento"]') as $m) {
            /** @var DOMElement $m */
            $codigo = $txt($m, './*[local-name()="CodigoError"]');

            $primas = [];
            foreach (['PrimaNeta', 'Derecho', 'Recargo', 'Impuesto', 'PrimaTotal', 'Comision'] as $campo) {
                $primas[$campo] = $num($txt($m, "./*[local-name()=\"Primas\"]/*[local-name()=\"{$campo}\"]"));
            }

            $coberturas = [];
            foreach ($xp->query('.//*[local-name()="Coberturas"]', $m) as $c) {
                /** @var DOMElement $c */
                $coberturas[] = [
                    'no'        => $c->getAttribute('NoCobertura'),
                    'suma'      => $txt($c, './*[local-name()="SumaAsegurada"]'),
                    'tipo_suma' => $txt($c, './*[local-name()="TipoSuma"]'),
                    'deducible' => $txt($c, './*[local-name()="Deducible"]'),
                    'prima'     => $num($txt($c, './*[local-name()="Prima"]')),
                ];
            }

            // Recibos: el manual los describe para emisión; si llegan en cotización se
            // conservan tal cual, campo por campo [PENDIENTE].
            $recibos = [];
            foreach ($xp->query('./*[local-name()="Recibos"]', $m) as $rc) {
                $fila = [];
                foreach ($rc->attributes ?? [] as $a) {
                    $fila['@' . $a->nodeName] = $a->nodeValue;
                }
                foreach ($rc->childNodes as $h) {
                    if ($h instanceof DOMElement) {
                        $fila[$h->localName] = trim((string) $h->textContent);
                    }
                }
                $recibos[] = $fila;
            }

            $movs[] = [
                'estado'        => $codigo === '' ? self::OK : self::clasificarCodigo($codigo),
                'codigo_error'  => $codigo,
                'no_cotizacion' => $m->getAttribute('NoCotizacion'),
                'paquete'       => $txt($m, './/*[local-name()="DatosVehiculo"]/*[local-name()="Paquete"]'),
                'primas'        => $primas,
                'coberturas'    => $coberturas,
                'recibos'       => $recibos,
            ];
        }

        if ($movs === []) {
            return ['estado' => self::E_SISTEMA, 'error' => self::error('La respuesta no trae ningún <Movimiento>.', '', 'parser'), 'movimientos' => []];
        }

        // El estado general es el del primer movimiento con error, si hay alguno.
        foreach ($movs as $mv) {
            if ($mv['estado'] !== self::OK) {
                return [
                    'estado'      => $mv['estado'],
                    'error'       => self::error($mv['codigo_error'], self::codigoNumerico($mv['codigo_error']), 'CodigoError'),
                    'movimientos' => $movs,
                ];
            }
        }
        return ['estado' => self::OK, 'error' => null, 'movimientos' => $movs];
    }

    /** Respuesta de wsTarifa: <salida><datos><Elemento>…</Elemento></datos><retorno><codigo/><descripcion/></retorno></salida>. */
    private static function interpretarTarifa(?DOMDocument $dom): array
    {
        if ($dom === null) {
            return ['estado' => self::E_SISTEMA, 'error' => self::error('La respuesta no trae <salida>.', '', 'parser'), 'datos' => []];
        }
        $xp = new DOMXPath($dom);
        $codigo = trim((string) ($xp->query('//*[local-name()="retorno"]/*[local-name()="codigo"]')->item(0)?->textContent ?? ''));
        $desc   = trim((string) ($xp->query('//*[local-name()="retorno"]/*[local-name()="descripcion"]')->item(0)?->textContent ?? ''));

        $datos = [];
        foreach ($xp->query('//*[local-name()="datos"]/*[local-name()="Elemento"]') as $el) {
            $fila = [];
            foreach ($el->childNodes as $h) {
                if ($h instanceof DOMElement) {
                    $fila[$h->localName] = trim((string) $h->textContent);
                }
            }
            $datos[] = $fila;
        }

        if ($codigo === '0') {
            return ['estado' => self::OK, 'error' => null, 'datos' => $datos];
        }
        $estado = $codigo === '' ? self::E_SISTEMA
            : (in_array((int) $codigo, self::TARIFA_AUTH, true) ? self::E_AUTH : self::E_DATOS);
        return ['estado' => $estado, 'error' => self::error($desc, $codigo, 'wsTarifa'), 'datos' => $datos];
    }

    /**
     * Categoría de un <CodigoError>. Decide el NÚMERO, nunca el texto: el
     * catálogo repite códigos con textos distintos (7, 126, 233…).
     * Si no trae número al inicio no hay cómo clasificarlo: SISTEMA, y el
     * texto se muestra tal cual [PENDIENTE: ver el formato real].
     */
    public static function clasificarCodigo(string $codigoError): string
    {
        $n = self::codigoNumerico($codigoError);
        if ($n === '') {
            return self::E_SISTEMA;
        }
        $n = (int) $n;
        if (in_array($n, self::CODIGOS_AUTH, true)) {
            return self::E_AUTH;
        }
        if (in_array($n, self::CODIGOS_SISTEMA, true)) {
            return self::E_SISTEMA;
        }
        return self::E_DATOS;
    }

    /** Número al inicio del texto ("0310--Codigo de Zona…" → "310"). */
    private static function codigoNumerico(string $codigoError): string
    {
        return preg_match('/^\s*0*(\d+)/', $codigoError, $m) ? $m[1] : '';
    }

    /** Mensaje para el usuario. El texto de Qualitas se muestra tal cual. */
    public static function explicar(array $r): string
    {
        $msg = (string) ($r['error']['descripcion'] ?? '');
        return match ($r['estado']) {
            self::E_AUTH    => 'Qualitas no aceptó la configuración del negocio o del agente. Avisa a administración. Qualitas dice: ' . $msg,
            self::E_TIMEOUT => 'Qualitas tardó demasiado en responder. Vuelve a intentar en un momento.',
            self::E_RED     => 'No se pudo conectar con Qualitas. Revisa la conexión a internet.',
            self::E_DATOS   => 'Qualitas rechazó los datos: ' . ($msg !== '' ? $msg : 'sin detalle'),
            self::E_SISTEMA => 'Qualitas reportó una falla. Si se repite, hay que escalarlo con ellos. Detalle: ' . $msg,
            default         => '',
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Utilidades
    // ─────────────────────────────────────────────────────────────────────

    /**
     * El contenido de un *Result: si trae elementos se usan tal cual; si trae
     * texto, se lee como XML. Devuelve un documento cuya raíz (o descendiente)
     * es $raiz, o null.
     */
    private static function contenido(DOMElement $nodo, string $raiz): ?DOMDocument
    {
        foreach ($nodo->childNodes as $h) {
            if ($h instanceof DOMElement) {
                $dom = new DOMDocument();
                $dom->appendChild($dom->importNode($h, true));
                return self::contiene($dom, $raiz) ? $dom : null;
            }
        }
        $dom = self::cargar(trim((string) $nodo->textContent));
        return ($dom !== null && self::contiene($dom, $raiz)) ? $dom : null;
    }

    private static function contiene(DOMDocument $dom, string $raiz): bool
    {
        $n = (new DOMXPath($dom))->query("//*[local-name()=\"{$raiz}\"]");
        return $n !== false && $n->length > 0;
    }

    private static function cargar(string $xml): ?DOMDocument
    {
        if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return null;
        }
        $dom = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        return $ok ? $dom : null;
    }

    private static function error(string $descripcion, string $clave, string $origen): array
    {
        return ['descripcion' => $descripcion, 'clave' => $clave, 'origen' => $origen];
    }

    /** cUsuario y cTarifa nunca llegan a la base (ADR-006, punto 2). */
    public static function sinCredenciales(string $xml): string
    {
        return (string) preg_replace('#<(cUsuario|cTarifa)>.*?</\1>#s', '<$1>***</$1>', $xml);
    }

    private static function transporteCurl(string $url, string $cuerpo, array $encabezados, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $encabezados,
        ]);
        $respuesta = curl_exec($ch);
        $r = [
            'http'   => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'cuerpo' => is_string($respuesta) ? $respuesta : '',
            'errno'  => $respuesta === false ? curl_errno($ch) : 0,
            'error'  => $respuesta === false ? (curl_error($ch) ?: 'error de red') : '',
        ];
        curl_close($ch);
        return $r;
    }

    /** INSERT en sys_llamadas con aseguradora='QUALITAS'. Devuelve el id. */
    private static function registrarEnBitacora(array $r): int
    {
        $recortar = static fn (string $x): string => strlen($x) <= self::TOPE_EVIDENCIA
            ? $x
            : substr($x, 0, self::TOPE_EVIDENCIA) . "\n<!-- recortado: el original tiene " . strlen($x) . ' caracteres -->';

        Db::ejecutar(
            'INSERT INTO sys_llamadas (cotizacion_id, servicio, detalle, estado, http, ms, bytes, error_clave, error_origen, error_desc, xml_entrada, xml_salida, aseguradora)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $r['cotizacion_id'], $r['servicio'], $r['detalle'], $r['estado'],
                $r['http'], $r['ms'], $r['bytes'],
                $r['error_clave'], $r['error_origen'], $r['error_desc'],
                $recortar(self::sinCredenciales((string) $r['xml_entrada'])),
                $recortar((string) $r['xml_salida']),
                'QUALITAS',
            ]
        );
        return Db::ultimoId();
    }
}
