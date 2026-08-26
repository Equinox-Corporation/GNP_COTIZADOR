<?php
declare(strict_types=1);

/**
 * GnpClient — Cliente del Web Service Preferente de GNP.
 *
 * Cubre los tres servicios del alcance: catálogos, cotización e impresión.
 *
 * SEGURIDAD: el ambiente de GNP es PRODUCCIÓN, no hay pruebas. Esta clase
 * deliberadamente no conoce ninguna ruta de emisión, cobro ni cancelación, y
 * además valida la ruta antes de cada llamada. Cotizar e imprimir no generan
 * póliza; todo lo demás sí.
 */
final class GnpClient
{
    public const OK        = 'OK';
    public const E_AUTH    = 'AUTH';      // credenciales rechazadas (CLAVE 1 / ldapService)
    public const E_DATOS   = 'DATOS';     // mandamos algo mal (CLAVE 2, 4, 7…)
    public const E_SISTEMA = 'SISTEMA';   // falla interna de GNP (CLAVE 0 / runtime)
    public const E_TIMEOUT = 'TIMEOUT';   // 504: el resultado es demasiado grande
    public const E_RED     = 'RED';

    private const RUTA_CATALOGO  = '/autos/wsp/catalogos/catalogo';
    private const RUTA_COTIZAR   = '/autos/wsp/cotizador/cotizar';
    private const RUTA_IMPRIMIR  = '/autos/wsp/impresor/impresion';

    /** Ninguna llamada puede tocar estas rutas: generarían una póliza real. */
    private const PROHIBIDAS = ['emisor', 'emitir', 'cancelacion', 'recibofiscal', 'ecommerce', 'previopago'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $usuario,
        private readonly string $password,
        private readonly string $idUnidadOperable,
        private readonly string $intermediario = '',
        private readonly int    $timeoutSegundos = 120
    ) {
    }

    // ─── Catálogos planos ────────────────────────────────────────────────────

    /** @param array<string,string> $filtros */
    public function catalogo(string $tipoCatalogo, array $filtros = []): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml  = "<SOLICITUD_CATALOGO>\n";
        $xml .= $this->credenciales();
        $xml .= '   <TIPO_CATALOGO>' . $e($tipoCatalogo) . "</TIPO_CATALOGO>\n";
        $xml .= '   <ID_UNIDAD_OPERABLE>' . $e($this->idUnidadOperable) . "</ID_UNIDAD_OPERABLE>\n";
        $xml .= "   <FECHA>01/01/1800</FECHA>\n";
        $xml .= $this->elementos($filtros);
        $xml .= '</SOLICITUD_CATALOGO>';

        $r = $this->enviar(self::RUTA_CATALOGO, $xml, 'catalogo');
        $r['elementos'] = [];

        if ($r['estado'] === self::OK) {
            foreach ($r['_xml']->ELEMENTOS->ELEMENTO ?? [] as $el) {
                $r['elementos'][] = [
                    'clave'  => trim((string) $el->CLAVE),
                    'nombre' => trim((string) $el->NOMBRE),
                    'valor'  => trim((string) $el->VALOR),
                ];
            }
        }
        unset($r['_xml']);
        return $r;
    }

    // ─── Catálogo VEHICULOS (estructura distinta) ────────────────────────────

    /**
     * Devuelve muchos bloques <ELEMENTOS>, uno por vehículo, con 8 campos cada uno.
     * Exige mínimo 2 filtros. Si el resultado es enorme responde 504 (no es rechazo).
     *
     * @param array<string,string> $filtros
     */
    public function vehiculos(array $filtros): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml  = "<SOLICITUD_CATALOGO>\n";
        $xml .= $this->credenciales();
        $xml .= "   <TIPO_CATALOGO>VEHICULOS</TIPO_CATALOGO>\n";
        $xml .= '   <ID_UNIDAD_OPERABLE>' . $e($this->idUnidadOperable) . "</ID_UNIDAD_OPERABLE>\n";
        $xml .= "   <FECHA>01/01/1800</FECHA>\n";
        $xml .= $this->elementos($filtros);
        $xml .= '</SOLICITUD_CATALOGO>';

        $r = $this->enviar(self::RUTA_CATALOGO, $xml, 'vehiculos');
        $r['vehiculos'] = [];

        if ($r['estado'] === self::OK) {
            foreach ($r['_xml']->ELEMENTOS ?? [] as $grupo) {
                $c = [];
                foreach ($grupo->ELEMENTO ?? [] as $el) {
                    $n = trim((string) $el->NOMBRE);
                    if ($n !== '') {
                        // Los VALOR vienen con espacios de relleno: siempre TRIM.
                        $c[$n] = ['clave' => trim((string) $el->CLAVE), 'valor' => trim((string) $el->VALOR)];
                    }
                }
                if (!isset($c['CLAVEMARCA'])) {
                    continue;
                }
                $v = static fn (string $k, string $q = 'clave'): string => $c[$k][$q] ?? '';
                $r['vehiculos'][] = [
                    'clavemarca'        => $v('CLAVEMARCA'),
                    'tipo_vehiculo'     => $v('TIPO_VEHICULO'),
                    'armadora'          => $v('ARMADORA'),
                    'armadora_nombre'   => $v('ARMADORA', 'valor'),
                    'modelo'            => (int) $v('MODELO'),
                    'carroceria'        => $v('CARROCERIA'),
                    'carroceria_nombre' => $v('CARROCERIA', 'valor'),
                    'version'           => $v('VERSION'),
                    'version_nombre'    => $v('VERSION', 'valor'),
                    'alto_valor'        => (int) $v('ALTOVALOR'),
                    'altisimo_valor'    => (int) $v('ALTISIMOVALOR'),
                ];
            }
        }
        unset($r['_xml']);
        return $r;
    }

    // ─── Cotización ──────────────────────────────────────────────────────────

    /**
     * Cotiza uno o varios paquetes en UNA sola llamada.
     *
     * Cotizar NO emite póliza: sólo devuelve primas.
     *
     * @param array $d  vehículo, contratante, conductor, vigencia, periodicidad
     * @param list<array{cve:string,desc:string}> $paquetes
     * @param list<array{cve:string,suma:string}> $opcionales
     */
    public function cotizar(array $d, array $paquetes, array $opcionales = []): array
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml  = "<COTIZACION>\n";
        $xml .= "   <SOLICITUD>\n";
        $xml .= $this->credenciales('      ');
        $xml .= '      <ID_UNIDAD_OPERABLE>' . $e($this->idUnidadOperable) . "</ID_UNIDAD_OPERABLE>\n";
        $xml .= '      <FCH_INICIO_VIGENCIA>' . $e($d['vigencia_inicio']) . "</FCH_INICIO_VIGENCIA>\n";
        $xml .= '      <FCH_FIN_VIGENCIA>' . $e($d['vigencia_fin']) . "</FCH_FIN_VIGENCIA>\n";
        $xml .= "      <VIA_PAGO>IN</VIA_PAGO>\n";
        $xml .= '      <PERIODICIDAD>' . $e($d['periodicidad'] ?? 'A') . "</PERIODICIDAD>\n";
        if ($this->intermediario !== '') {
            $xml .= "      <ELEMENTOS>\n";
            $xml .= "         <ELEMENTO>\n";
            $xml .= "            <NOMBRE>INTERMEDIARIO</NOMBRE>\n";
            $xml .= '            <CLAVE>' . $e($this->intermediario) . "</CLAVE>\n";
            $xml .= '            <VALOR>' . $e($this->intermediario) . "</VALOR>\n";
            $xml .= "         </ELEMENTO>\n";
            $xml .= "      </ELEMENTOS>\n";
        }
        $xml .= "   </SOLICITUD>\n";

        $xml .= "   <VEHICULO>\n";
        $xml .= '      <SUB_RAMO>' . $e($d['sub_ramo']) . "</SUB_RAMO>\n";
        $xml .= '      <TIPO_VEHICULO>' . $e($d['tipo_vehiculo']) . "</TIPO_VEHICULO>\n";
        $xml .= '      <MODELO>' . $e($d['modelo']) . "</MODELO>\n";
        $xml .= '      <ARMADORA>' . $e($d['armadora']) . "</ARMADORA>\n";
        $xml .= '      <CARROCERIA>' . $e($d['carroceria']) . "</CARROCERIA>\n";
        $xml .= '      <VERSION>' . $e($d['version']) . "</VERSION>\n";
        $xml .= "      <USO>01</USO>\n";
        $xml .= "      <FORMA_INDEMNIZACION>03</FORMA_INDEMNIZACION>\n";
        $xml .= "      <VALOR_FACTURA/>\n";
        $xml .= "   </VEHICULO>\n";

        // ─────────────────────────────────────────────────────────────────────
        // CONTRATANTE: identifica al titular, NO interviene en el precio.
        //
        // El bloque es obligatorio, pero su contenido no lo es todo. El propio
        // ejemplo de GNP para persona moral manda únicamente TIPO_PERSONA y
        // CODIGO_POSTAL —sin nombre, sin edad y sin RFC— y cotiza igual.
        // Por eso aquí sólo se escriben las etiquetas que traen dato: mandar
        // <EDAD></EDAD> vacío es pedirle a GNP que interprete un hueco.
        //
        // Ojo: cuando todos los campos vienen llenos, el XML resultante es
        // idéntico al que se verificó contra producción. No cambia nada del
        // camino que ya funciona.
        // ─────────────────────────────────────────────────────────────────────
        $opcional = static function (string $etiqueta, mixed $valor) use ($e): string {
            $v = trim((string) $valor);
            return ($v === '' || $v === '0') ? '' : '      <' . $etiqueta . '>' . $e($v) . '</' . $etiqueta . ">\n";
        };

        $xml .= "   <CONTRATANTE>\n";
        $xml .= '      <TIPO_PERSONA>' . $e($d['tipo_persona']) . "</TIPO_PERSONA>\n";
        $xml .= $opcional('NOMBRES',          $d['nombres']          ?? '');
        $xml .= $opcional('APELLIDO_PATERNO', $d['apellido_paterno'] ?? '');
        $xml .= $opcional('APELLIDO_MATERNO', $d['apellido_materno'] ?? '');
        $xml .= $opcional('EDAD',             $d['contratante_edad'] ?? '');
        $xml .= $opcional('RFC',              $d['contratante_rfc']  ?? '');
        $xml .= "      <CVE_CLIENTE_ORIGEN>0000000000</CVE_CLIENTE_ORIGEN>\n";
        $xml .= '      <CODIGO_POSTAL>' . $e($d['contratante_cp']) . "</CODIGO_POSTAL>\n";
        $xml .= "   </CONTRATANTE>\n";

        // El conductor es quien determina la prima: GNP tarifica y presenta con sus datos.
        $xml .= "   <CONDUCTOR>\n";
        $xml .= '      <FCH_NACIMIENTO>' . $e($d['conductor_nacimiento']) . "</FCH_NACIMIENTO>\n";
        $xml .= '      <SEXO>' . $e($d['conductor_sexo']) . "</SEXO>\n";
        $xml .= '      <EDAD>' . $e($d['conductor_edad']) . "</EDAD>\n";
        $xml .= '      <CODIGO_POSTAL>' . $e($d['conductor_cp']) . "</CODIGO_POSTAL>\n";
        $xml .= "   </CONDUCTOR>\n";

        $xml .= "   <PAQUETES>\n";
        foreach ($paquetes as $p) {
            $xml .= "      <PAQUETE>\n";
            $xml .= '         <CVE_PAQUETE>' . $e($p['cve']) . "</CVE_PAQUETE>\n";
            $xml .= '         <DESC_PAQUETE>' . $e($p['desc']) . "</DESC_PAQUETE>\n";
            if ($opcionales === []) {
                $xml .= "         <COBERTURAS/>\n";
            } else {
                // Forma verificada contra producción el 25 de agosto de 2026:
                // CVE_COBERTURA + NOMBRE + SUMA_ASEGURADA. Es la del ejemplo del
                // manual y la que usó 02.4a.
                //
                // La variante de la tabla §3.1.2.9 —con TIPO_COBERTURA y UDMSA—
                // también funciona y da el mismo precio, pero se descartó porque
                // UDMSA sólo admite "IMPT" o "PORC" y hay coberturas medidas en
                // días (Auto Sustituto). Esta forma no tiene ese problema.
                $xml .= "         <COBERTURAS>\n";
                foreach ($opcionales as $c) {
                    $xml .= "            <COBERTURA>\n";
                    $xml .= '               <CVE_COBERTURA>' . $e($c['cve']) . "</CVE_COBERTURA>\n";
                    if (($c['nombre'] ?? '') !== '') {
                        $xml .= '               <NOMBRE>' . $e($c['nombre']) . "</NOMBRE>\n";
                    }
                    if (($c['suma'] ?? '') !== '') {
                        $xml .= '               <SUMA_ASEGURADA>' . $e($c['suma']) . "</SUMA_ASEGURADA>\n";
                    }
                    $xml .= "            </COBERTURA>\n";
                }
                $xml .= "         </COBERTURAS>\n";
            }
            $xml .= "      </PAQUETE>\n";
        }
        $xml .= "   </PAQUETES>\n";
        $xml .= '</COTIZACION>';

        $r = $this->enviar(self::RUTA_COTIZAR, $xml, 'cotizar');
        $r['folio']     = '';
        $r['paquetes']  = [];

        if ($r['estado'] === self::OK) {
            $r['folio'] = trim((string) ($r['_xml']->SOLICITUD->NUM_COTIZACION ?? ''));

            foreach ($r['_xml']->PAQUETES->PAQUETE ?? [] as $p) {
                // Los importes vienen como lista NOMBRE/MONTO: se leen POR NOMBRE,
                // nunca por posición. TOTAL_PAGAR es el precio; PRIMA_NETA no lo es.
                $conceptos = [];
                foreach ($p->TOTALES->TOTAL_PRIMA->CONCEPTO_ECONOMICO ?? [] as $ce) {
                    $conceptos[trim((string) $ce->NOMBRE)] = (float) trim((string) $ce->MONTO);
                }

                $coberturas = [];
                foreach ($p->COBERTURAS->COBERTURA ?? [] as $cb) {
                    $coberturas[] = [
                        'cve'    => trim((string) $cb->CVE_COBERTURA),
                        'nombre' => trim((string) $cb->NOMBRE),
                        'suma'   => trim((string) $cb->SUMA_ASEGURADA),
                        'ded'    => trim((string) $cb->DEDUCIBLE),
                    ];
                }

                $descuentos = [];
                foreach ($p->DESCUENTOS->DESCUENTO ?? [] as $ds) {
                    $descuentos[] = [
                        'cve'    => trim((string) $ds->CVE_DESCUENTO),
                        'desc'   => trim((string) $ds->DESCRIPCION),
                        'valor'  => trim((string) $ds->VALOR),
                    ];
                }

                $r['paquetes'][] = [
                    'cve'         => trim((string) $p->CVE_PAQUETE),
                    'desc'        => trim((string) $p->DESC_PAQUETE),
                    'conceptos'   => $conceptos,
                    'coberturas'  => $coberturas,
                    'descuentos'  => $descuentos,
                    'total_pagar' => $conceptos['TOTAL_PAGAR'] ?? null,
                ];
            }
        }
        unset($r['_xml']);
        return $r;
    }

    // ─── Impresión ───────────────────────────────────────────────────────────

    /**
     * Trae el PDF oficial de la cotización.
     *
     * Ojo: GNP TAMBIÉN manda el PDF por correo a la dirección que se le pase,
     * con su propio remitente. Decidir bien qué correo se envía.
     *
     * @param list<array{cve:string,desc:string}> $paquetes
     */
    public function imprimir(string $folio, string $correo, array $paquetes, string $periodicidad = 'A'): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml  = "<IMPRESION>\n";
        $xml .= $this->credenciales();
        $xml .= '   <NUM_COTIZACION>' . $e($folio) . "</NUM_COTIZACION>\n";
        $xml .= '   <CORREO_ELECTRONICO>' . $e($correo) . "</CORREO_ELECTRONICO>\n";
        // ─────────────────────────────────────────────────────────────────────
        // <NOMBRE> lleva la DESCRIPCIÓN del paquete, no la etiqueta "PAQUETE".
        //
        // El manual (§3.3.2.1) dice que <NOMBRE> vale "PAQUETE" y que la
        // descripción va en <VALOR>. Está mal para este servicio: <VALOR> no se
        // lee, así que con esa forma el paquete solicitado queda llamándose
        // "PAQUETE" — y GNP responde, con toda razón, 400 clave 4:
        // "El paquete de la cotizacion es incorrecto".
        //
        // Verificado contra producción el 25 de agosto de 2026:
        //   CLAVE + NOMBRE(descripción)                      → OK
        //   CLAVE + NOMBRE("PAQUETE") + NOMBRE(descripción)  → OK  (gana el último)
        //   CLAVE + NOMBRE("PAQUETE") + VALOR(descripción)   → 400 clave 4
        //
        // Se usa la forma mínima: no depende de cuál <NOMBRE> gane si GNP
        // cambiara su parser. <PERIODICIDADES> es otra estructura y sí usa
        // <VALOR>; no unificarlas sin volver a probar contra el servicio.
        // ─────────────────────────────────────────────────────────────────────
        $xml .= "   <PAQUETES>\n";
        foreach ($paquetes as $p) {
            $xml .= "      <ELEMENTO>\n";
            $xml .= '         <CLAVE>' . $e($p['cve']) . "</CLAVE>\n";
            $xml .= '         <NOMBRE>' . $e($p['desc'] !== '' ? $p['desc'] : $p['cve']) . "</NOMBRE>\n";
            $xml .= "      </ELEMENTO>\n";
        }
        $xml .= "   </PAQUETES>\n";
        $xml .= "   <PERIODICIDADES>\n";
        $xml .= "      <ELEMENTO>\n";
        $xml .= '         <CLAVE>' . $e($periodicidad) . "</CLAVE>\n";
        $xml .= "         <NOMBRE>PERIODICIDAD</NOMBRE>\n";
        $xml .= "         <VALOR>Anual</VALOR>\n";
        $xml .= "      </ELEMENTO>\n";
        $xml .= "   </PERIODICIDADES>\n";
        $xml .= '</IMPRESION>';

        $r = $this->enviar(self::RUTA_IMPRIMIR, $xml, 'imprimir', guardarSalida: false);
        $r['pdf']        = '';
        $r['referencia'] = '';
        $r['mensaje']    = '';

        if ($r['estado'] === self::OK) {
            $crudo = $r['_crudo'];

            // El manual documenta <ESTATUS>ok</ESTATUS>; el servicio devuelve "OK".
            // Se compara sin distinguir mayúsculas.
            $estatus = '';
            if (preg_match('#<ESTATUS>(.*?)</ESTATUS>#is', $crudo, $m)) {
                $estatus = strtoupper(trim($m[1]));
            }
            if (preg_match('#<MENSAJE>(.*?)</MENSAJE>#is', $crudo, $m)) {
                $r['mensaje'] = trim($m[1]);
            }
            // La referencia de envío viene embebida en el texto del mensaje.
            if (preg_match('/PRPL\d{6,20}/', $crudo, $m)) {
                $r['referencia'] = $m[0];
            }

            if ($estatus !== 'OK') {
                $r['estado'] = self::E_DATOS;
                $r['error']  = $this->error($r['mensaje'] !== '' ? $r['mensaje'] : 'GNP no devolvió ESTATUS OK.', '', 'impresor');
            } elseif (preg_match('#<CADENA_BINARIA>(.*?)</CADENA_BINARIA>#is', $crudo, $m)) {
                $bytes = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
                if ($bytes !== false && str_starts_with($bytes, '%PDF')) {
                    $r['pdf'] = $bytes;
                } else {
                    $r['estado'] = self::E_SISTEMA;
                    $r['error']  = $this->error('La cadena binaria no es un PDF válido.', '', 'impresor');
                }
            } else {
                $r['estado'] = self::E_SISTEMA;
                $r['error']  = $this->error('La respuesta no trae <CADENA_BINARIA>.', '', 'impresor');
            }
        }

        unset($r['_xml'], $r['_crudo']);
        return $r;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function credenciales(string $sangria = '   '): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return $sangria . '<USUARIO>' . $e($this->usuario) . "</USUARIO>\n"
             . $sangria . '<PASSWORD>' . $e($this->password) . "</PASSWORD>\n";
    }

    /** @param array<string,string> $filtros */
    private function elementos(array $filtros): string
    {
        if ($filtros === []) {
            return '';
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $x = "   <ELEMENTOS>\n";
        foreach ($filtros as $nombre => $clave) {
            $x .= "      <ELEMENTO>\n";
            $x .= '         <NOMBRE>' . $e((string) $nombre) . "</NOMBRE>\n";
            $x .= '         <CLAVE>' . $e((string) $clave) . "</CLAVE>\n";
            $x .= "      </ELEMENTO>\n";
        }
        return $x . "   </ELEMENTOS>\n";
    }

    private function enviar(string $ruta, string $cuerpo, string $servicio, bool $guardarSalida = true): array
    {
        foreach (self::PROHIBIDAS as $p) {
            if (str_contains(strtolower($ruta), $p)) {
                throw new RuntimeException(
                    "BLOQUEADO: la ruta contiene \"{$p}\". Este sistema es sólo de cotización y apunta a producción."
                );
            }
        }

        $cuerpo = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $cuerpo . "\n";
        $inicio = microtime(true);

        $ch = curl_init(rtrim($this->baseUrl, '/') . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSegundos,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/xml; charset=UTF-8', 'Accept: application/xml'],
        ]);
        $respuesta = curl_exec($ch);
        $http      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errRed    = $respuesta === false ? (curl_error($ch) ?: 'error de red') : null;
        curl_close($ch);

        $crudo = is_string($respuesta) ? $respuesta : '';

        $r = [
            'servicio'    => $servicio,
            'http'        => $http,
            'ms'          => (int) round((microtime(true) - $inicio) * 1000),
            'bytes'       => strlen($crudo),
            'error'       => null,
            'estado'      => self::OK,
            'xml_entrada' => $this->sinPassword($cuerpo),
            // Las respuestas de impresión pesan más de 1 MB: no se guardan enteras.
            'xml_salida'  => $guardarSalida ? $crudo : '(' . strlen($crudo) . ' caracteres, no se conserva)',
            '_crudo'      => $crudo,
        ];

        if ($errRed !== null) {
            $r['estado'] = self::E_RED;
            $r['error']  = $this->error($errRed, '', 'curl');
            return $r;
        }

        // 504 no es rechazo: GNP no alcanzó a armar la respuesta. Hay que acotar.
        if ($http === 504 || $http === 408) {
            $r['estado'] = self::E_TIMEOUT;
            $r['error']  = $this->error("El servicio no respondió a tiempo (HTTP {$http}).", (string) $http, 'gateway');
            return $r;
        }

        if (trim($crudo) === '') {
            $r['estado'] = self::E_RED;
            $r['error']  = $this->error('Respuesta vacía.', (string) $http, 'red');
            return $r;
        }

        $previo = libxml_use_internal_errors(true);
        $xml    = simplexml_load_string($crudo);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if ($xml === false) {
            $r['estado'] = self::E_SISTEMA;
            $r['error']  = $this->error('La respuesta no es XML válido.', '', 'parser');
            return $r;
        }

        // GNP responde HTTP 200 aunque el negocio falle: el veredicto va en el cuerpo.
        if ($xml->getName() === 'ERROR') {
            $err = $this->error(
                trim((string) $xml->DESCRIPCION),
                trim((string) $xml->CLAVE),
                trim((string) $xml->ORIGEN),
                trim((string) $xml->FECHA)
            );
            $r['estado'] = $this->clasificar($err['origen'], $err['clave']);
            $r['error']  = $err;
            return $r;
        }

        $r['_xml'] = $xml;
        return $r;
    }

    /**
     * Clasifica el error de GNP.
     *
     * Firmas observadas contra el servicio real:
     *
     *   CLAVE 0 · runtime      · HTTP 200 · falla interna de GNP
     *   CLAVE 1 · ldapService  · HTTP 400 · credenciales rechazadas
     *   CLAVE 2 · catalogos    · HTTP 200 · el catálogo no existe
     *   CLAVE 4 · impresion    · HTTP 400 · el paquete no pertenece a esa cotización
     *   CLAVE 7 · catalogos    · HTTP 200 · faltan filtros
     *
     * De ahí la regla: la CLAVE manda. 0 es falla de ellos, 1 es autenticación,
     * y de 2 en adelante son validaciones de negocio — datos que mandamos mal.
     * El ORIGEN confirma; el código HTTP NO sirve de criterio (400 aparece tanto
     * en autenticación como en errores de datos).
     */
    private function clasificar(string $origen, string $clave): string
    {
        $o = strtolower($origen);

        if (str_contains($o, 'ldap'))    return self::E_AUTH;
        if (str_contains($o, 'runtime')) return self::E_SISTEMA;

        return match ($clave) {
            '0'     => self::E_SISTEMA,
            '1'     => self::E_AUTH,
            ''      => self::E_SISTEMA,
            default => self::E_DATOS,
        };
    }

    private function error(string $descripcion, string $clave, string $origen, string $fecha = ''): array
    {
        return ['descripcion' => $descripcion, 'clave' => $clave, 'origen' => $origen, 'fecha' => $fecha];
    }

    /** La contraseña nunca se guarda en la evidencia ni en la bitácora. */
    private function sinPassword(string $xml): string
    {
        return (string) preg_replace('#<PASSWORD>.*?</PASSWORD>#s', '<PASSWORD>***</PASSWORD>', $xml);
    }

    /** Mensaje para el usuario final, sin jerga. */
    public static function explicar(array $r): string
    {
        return match ($r['estado']) {
            self::E_AUTH    => 'GNP no aceptó las credenciales del sistema. Avisa a sistemas: hay que revisar la contraseña del Portal.',
            self::E_TIMEOUT => 'GNP tardó demasiado en responder. Vuelve a intentar en un momento.',
            self::E_RED     => 'No se pudo conectar con GNP. Revisa la conexión a internet.',
            self::E_DATOS   => 'GNP rechazó los datos: ' . ($r['error']['descripcion'] ?? 'sin detalle'),
            self::E_SISTEMA => 'GNP reportó una falla interna. Si se repite, hay que escalarlo con ellos.',
            default         => '',
        };
    }
}
