<?php
declare(strict_types=1);

namespace andreaval\Cohesion2;

if (!class_exists(__NAMESPACE__ . '\\Cohesion2Exception')) {
    require_once __DIR__ . '/Cohesion2Exception.php';
}

/**
 * Classe per la gestione del SSO di Cohesion2.
 *
 * @version 4.0.0 04/05/2026
 * @requires PHP 8.3
 * @author Andrea Vallorani <andrea.vallorani@gmail.com>
 * @license MIT License <https://github.com/andreaval/Cohesion2PHPLibrary/blob/master/LICENSE>
 * @link http://cohesion.regione.marche.it/cohesioninformativo/
 */
class Cohesion2
{
    public const COHESION2_CHECK        = 'https://cohesion2.regione.marche.it/sso/Check.aspx?auth=';
    public const COHESION2_LOGIN        = 'https://cohesion2.regione.marche.it/SA/AccediCohesion.aspx?auth=';
    public const COHESION2_WEB          = 'https://cohesion2.regione.marche.it/SSO/webCheckSessionSSO.aspx';
    public const COHESION2_SAML20_CHECK = 'https://cohesion2.regione.marche.it/SPManager/WAYF.aspx?auth=';
    public const COHESION2_SAML20_WEB   = 'https://cohesion2.regione.marche.it/SPManager/webCheckSessionSSO.aspx';
    public const EIDAS_FLAG             = 'eidas=1';
    public const PURPOSE_FLAG           = 'purpose=';

    private readonly string $session_name;
    /** @var string[] Whitelist degli host accettati per la callback URL. */
    private readonly array $allowedHosts;
    private string $authRestriction = '0,1,2,3';
    private bool $sso = true;
    private bool $saml20 = false;
    private bool $eIDAS = false;
    private ?string $SPIDProPurpose = null;
    private bool $tlsVerify = true;

    /** ID sessione SSO */
    public ?string $id_sso = null;

    /** ID sessione ASPNET */
    public ?string $id_aspnet = null;

    /** Username utente autenticato in Cohesion */
    public ?string $username = null;

    /** Profilo dell'utente con i dati forniti dal server */
    public ?array $profile = null;

    /**
     * @param string        $session_name Nome della variabile di sessione. Default: 'cohesion2'.
     * @param string[]|null $allowedHosts Whitelist di host accettati come callback
     *                      URL post-autenticazione. Se null (default), viene letta
     *                      dalla variabile d'ambiente `COHESION2_ALLOWED_HOSTS`
     *                      come CSV (es. `app.example.com,www.example.com`); se
     *                      neppure quella è impostata, la callback URL usa
     *                      `SERVER_NAME` invece di `HTTP_HOST`. Si consiglia di
     *                      popolare sempre la whitelist negli ambienti di
     *                      produzione (vedi README e CWE-601).
     */
    public function __construct(
        string $session_name = 'cohesion2',
        ?array $allowedHosts = null,
    ) {
        $this->session_name = $session_name;
        $this->allowedHosts = $this->resolveAllowedHosts($allowedHosts);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if ($this->isAuth()) {
            $data = $_SESSION[$this->session_name];
            if (is_array($data)) {
                $this->id_sso    = $data['id_sso']    ?? null;
                $this->id_aspnet = $data['id_aspnet'] ?? null;
                $this->username  = $data['username']  ?? null;
                $this->profile   = $data['profile']   ?? null;
            } else {
                // Formato di sessione precedente la 4.0.0: scartato.
                unset($_SESSION[$this->session_name]);
            }
        }
    }

    /**
     * Imposta i metodi di autenticazione permessi.
     *
     * Valore di default: '0,1,2,3' (separare le scelte con una virgola).
     *   0 = Utente e Password
     *   1 = Utente, Password e PIN
     *   2 = Smart Card
     *   3 = autenticazione di Dominio (utenti interni alla rete regionale)
     *
     * NON TUTTE LE COMBINAZIONI VENGONO ACCETTATE
     * (es. '0,1' fa comunque mostrare tutti i metodi).
     */
    public function setAuthRestriction(string $authRestriction): static
    {
        if ($authRestriction !== '') {
            $this->authRestriction = $authRestriction;
        }
        return $this;
    }

    /** Controlla se l'utente è già stato autenticato. */
    public function isAuth(): bool
    {
        return isset($_SESSION[$this->session_name]);
    }

    /**
     * Attiva o disattiva l'uso del Single Sign-On.
     * Se disabilitato, l'utente viene sempre reindirizzato alla pagina di
     * login senza controllare se è già autenticato tramite SSO.
     */
    public function useSSO(bool $on = true): static
    {
        $this->sso = $on;
        return $this;
    }

    /**
     * Abilita o disabilita la modalità SAML 2.0.
     * L'attivazione comporta automaticamente l'attivazione del SSO.
     */
    public function useSAML20(bool $on = true): static
    {
        $this->useSSO(true);
        $this->saml20 = $on;
        return $this;
    }

    /** Abilita il login eIDAS (e automaticamente la modalità SAML 2.0). */
    public function enableEIDASLogin(): static
    {
        $this->useSAML20(true);
        $this->eIDAS = true;
        return $this;
    }

    /**
     * Abilita il login con SPID Professionale e automaticamente la modalità SAML 2.0.
     *
     * @param string[] $SPIDProPurposes Purpose richiesti.
     *                 Default: ['PF'] (SPID per Persone Fisiche ad Uso Professionale).
     *                 Valori possibili: LP, PG, PF, PX (avviso SPID 18 v2).
     * @link https://www.agid.gov.it/sites/default/files/repository_files/spid-avviso-n18_v.2-_autenticazione_persona_giuridica_o_uso_professionale_per_la_persona_giuridica.pdf
     */
    public function enableSPIDProLogin(array $SPIDProPurposes = ['PF']): static
    {
        $this->useSAML20(true);
        $this->SPIDProPurpose = implode('|', $SPIDProPurposes);
        return $this;
    }

    /**
     * Disabilita la verifica del certificato TLS nelle chiamate HTTPS verso
     * Cohesion2.
     *
     * SCONSIGLIATO. Esposto solo per consentire l'uso della libreria in
     * ambienti in cui la catena di certificati non è verificabile (es.
     * proxy MITM aziendale con CA non installata sul server). In tutti gli
     * altri casi mantenere la verifica attiva: il certificato di
     * cohesion2.regione.marche.it è emesso da una CA pubblica.
     */
    public function disableTLSVerification(): static
    {
        $this->tlsVerify = false;
        return $this;
    }

    /**
     * Risolve la whitelist degli host accettati come callback URL.
     *
     * Ordine di precedenza:
     *   1. parametro `$allowedHosts` esplicito del costruttore
     *   2. variabile d'ambiente `COHESION2_ALLOWED_HOSTS` (CSV)
     *
     * Lettura da `$_ENV`, `$_SERVER` e `getenv()` per coprire le diverse
     * convenzioni dei framework e dei loader .env (vlucas/phpdotenv,
     * Symfony Dotenv, Laravel, ecc.).
     *
     * @param  string[]|null $explicit
     * @return string[]
     */
    private function resolveAllowedHosts(?array $explicit): array
    {
        if ($explicit !== null) {
            return array_values(array_filter(
                array_map(static fn($v): string => trim((string) $v), $explicit),
                static fn(string $v): bool => $v !== ''
            ));
        }
        $env = $_ENV['COHESION2_ALLOWED_HOSTS']
            ?? $_SERVER['COHESION2_ALLOWED_HOSTS']
            ?? getenv('COHESION2_ALLOWED_HOSTS');
        if (!is_string($env) || $env === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $env)),
            static fn(string $v): bool => $v !== ''
        ));
    }

    /**
     * Restituisce l'host da usare nella callback URL inviata a Cohesion2.
     *
     * Difesa contro l'open redirect via Host header injection (CWE-601):
     * `$_SERVER['HTTP_HOST']` proviene dall'header HTTP `Host`, controllato
     * dal client. Se Cohesion2 non valida server-side i redirect autorizzati
     * per il `id_sito` configurato, un attaccante può forzare la callback
     * verso un host arbitrario e intercettare l'`auth=` con il token di
     * sessione.
     *
     * Logica:
     *   - se la whitelist è popolata, `HTTP_HOST` deve corrispondere
     *     (case-insensitive) a uno dei valori dichiarati: in caso contrario
     *     viene sollevata un'eccezione anziché redirezionare;
     *   - se la whitelist è vuota, si usa `SERVER_NAME` (configurato lato
     *     web server e non manipolabile via header HTTP) e solo come ultima
     *     risorsa `HTTP_HOST`.
     *
     * @throws Cohesion2Exception se HTTP_HOST non è nella whitelist
     */
    private function resolveCallbackHost(): string
    {
        $httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($this->allowedHosts !== []) {
            if ($httpHost !== '') {
                foreach ($this->allowedHosts as $allowed) {
                    if (strcasecmp($httpHost, $allowed) === 0) {
                        return $httpHost;
                    }
                }
            }
            throw new Cohesion2Exception(sprintf(
                'Host non consentito nella callback URL: %s',
                $httpHost !== '' ? $httpHost : '(Host header assente)'
            ));
        }

        $serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
        return $serverName !== '' ? $serverName : $httpHost;
    }

    /**
     * Autentica l'utente nel sistema.
     *
     * @throws Cohesion2Exception in caso di errore
     */
    public function auth(): void
    {
        if (!$this->isAuth()) {
            if (!empty($_REQUEST['auth']) && is_string($_REQUEST['auth'])) {
                $this->verify($_REQUEST['auth']);
            } else {
                $this->check();
            }
        }
    }

    /** Chiude la sessione locale e quella del SSO. */
    public function logout(): void
    {
        if ($this->isAuth()) {
            $data = [
                'Operation'        => 'LogoutSito',
                'IdSessioneSSO'    => $this->id_sso,
                'IdSessioneASPNET' => $this->id_aspnet,
            ];
            $this->httpPost(self::COHESION2_WEB, $data);
            unset($_SESSION[$this->session_name]);
        }
    }

    /** Chiude la sessione locale lasciando aperta quella del SSO. */
    public function logoutLocal(): void
    {
        if ($this->isAuth()) {
            unset($_SESSION[$this->session_name]);
        }
    }

    private function check(): never
    {
        $protocol = ($_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
        $host = $this->resolveCallbackHost();
        $urlPagina = $protocol . $host . $_SERVER['REQUEST_URI'];
        $urlPagina .= ($_SERVER['QUERY_STRING']) ? '&' : '?';
        $urlPagina .= 'cohesionCheck=1';
        $xmlAuth = $this->buildAuthXml($urlPagina);
        $auth = urlencode(base64_encode($xmlAuth));
        if ($this->saml20) {
            $urlLogin = self::COHESION2_SAML20_CHECK . $auth;
        } else {
            $urlLogin = $this->sso ? self::COHESION2_CHECK . $auth : self::COHESION2_LOGIN . $auth;
        }
        header("Location: $urlLogin");
        exit;
    }

    /**
     * Costruisce il payload XML inviato a Cohesion2 al passo di check.
     *
     * La libreria 3.x componeva l'XML per concatenazione di stringhe: i
     * valori di `$urlPagina` (derivato da $_SERVER) e `$authRestriction`
     * (impostabile via setAuthRestriction) finivano nel markup senza
     * escaping — un input contenente `]]>` o caratteri di markup poteva
     * iniettare nodi nel payload (CWE-91, XML Injection).
     *
     * Qui il documento è costruito con \DOMDocument: il testo dei nodi è
     * escapato automaticamente da libxml; i CDATA sono protetti dal
     * controllo esplicito su `]]>`, sequenza non rappresentabile in
     * un'unica sezione CDATA.
     *
     * @throws Cohesion2Exception se la URL di callback contiene una
     *                            sequenza non valida in CDATA.
     */
    private function buildAuthXml(string $urlPagina): string
    {
        if (str_contains($urlPagina, ']]>')) {
            throw new Cohesion2Exception(
                'URL di callback contiene una sequenza non rappresentabile in CDATA'
            );
        }

        $ns = 'http://tempuri.org/Auth.xsd';
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $dsAuth = $dom->createElementNS($ns, 'dsAuth');
        $dsAuth->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            'http://www.w3.org/2001/XMLSchema-instance'
        );
        $dom->appendChild($dsAuth);

        $auth = $dom->createElementNS($ns, 'auth');
        $dsAuth->appendChild($auth);

        foreach (['user', 'id_sa'] as $name) {
            $auth->appendChild($dom->createElementNS($ns, $name));
        }
        $auth->appendChild($dom->createElementNS($ns, 'id_sito', 'TEST'));
        foreach (['esito_auth_sa', 'id_sessione_sa', 'id_sessione_aspnet_sa'] as $name) {
            $auth->appendChild($dom->createElementNS($ns, $name));
        }

        $urlValidate = $dom->createElementNS($ns, 'url_validate');
        $urlValidate->appendChild($dom->createCDATASection($urlPagina));
        $auth->appendChild($urlValidate);

        $urlRichiesta = $dom->createElementNS($ns, 'url_richiesta');
        $urlRichiesta->appendChild($dom->createCDATASection($urlPagina));
        $auth->appendChild($urlRichiesta);

        foreach (['esito_auth_sso', 'id_sessione_sso', 'id_sessione_aspnet_sso'] as $name) {
            $auth->appendChild($dom->createElementNS($ns, $name));
        }

        $stilesheet = sprintf(
            'AuthRestriction=%s%s%s',
            $this->authRestriction,
            $this->eIDAS ? ';' . self::EIDAS_FLAG : '',
            $this->SPIDProPurpose !== null ? ';' . self::PURPOSE_FLAG . $this->SPIDProPurpose : ''
        );
        $auth->appendChild($dom->createElementNS($ns, 'stilesheet', $stilesheet));

        // <AuthRestriction xmlns=""> esplicitamente fuori dal default namespace.
        $auth->appendChild($dom->createElementNS('', 'AuthRestriction', $this->authRestriction));

        $output = $dom->saveXML();
        if ($output === false) {
            throw new Cohesion2Exception('Impossibile serializzare il payload XML di autenticazione');
        }
        return $output;
    }

    /**
     * @throws Cohesion2Exception
     */
    private function verify(string $auth): void
    {
        // LIBXML_NONET disabilita il fetching di DTD/entità esterne dal parser
        // libxml: previene XXE (CWE-611) anche su versioni in cui la
        // disabilitazione di default non sia garantita. NON usare
        // LIBXML_NOENT, che invece *abilita* la sostituzione delle entità.
        $xmlOptions = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;

        $xml = trim(base64_decode($auth));
        $domXML = new \DOMDocument();
        $domXML->loadXML($xml, $xmlOptions);
        $this->id_sso    = $domXML->getElementsByTagName('id_sessione_sso')->item(0)?->nodeValue;
        $this->id_aspnet = $domXML->getElementsByTagName('id_sessione_aspnet_sso')->item(0)?->nodeValue;
        $this->username  = $domXML->getElementsByTagName('user')->item(0)?->nodeValue;
        $esito           = $domXML->getElementsByTagName('esito_auth_sso')->item(0)?->nodeValue;
        if ($esito !== 'OK' || $this->id_sso === null || $this->id_sso === '' || $this->id_aspnet === null || $this->id_aspnet === '') {
            // Il messaggio non include id_sso/id_aspnet: sono token di
            // sessione e gli error log applicativi spesso vengono raccolti
            // in sistemi centralizzati con visibilità ampia.
            throw new Cohesion2Exception(sprintf(
                'Errore in fase di autenticazione: esito=%s',
                $esito ?? '(assente)'
            ));
        }

        $url = $this->saml20 ? self::COHESION2_SAML20_WEB : self::COHESION2_WEB;
        $data = [
            'Operation'        => 'GetCredential',
            'IdSessioneSSO'    => $this->id_sso,
            'IdSessioneASPNET' => $this->id_aspnet,
        ];
        $result = $this->httpPost($url, $data);
        $domXML->loadXML($result, $xmlOptions);
        $profilo = simplexml_import_dom($domXML);
        $base = current($profilo->xpath('//base'));
        if (is_object($base) && $base->login) {
            $resp = [];
            foreach ($base->children() as $node) {
                $resp[$node->getName()] = (string) $node;
            }
            $this->profile = $resp;
            // Rigenerazione dell'id di sessione: passaggio a livello di privilegio
            // (da anonimo ad autenticato), mitigazione di session fixation.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION[$this->session_name] = [
                'id_sso'    => $this->id_sso,
                'id_aspnet' => $this->id_aspnet,
                'username'  => $this->username,
                'profile'   => $this->profile,
            ];
        } else {
            throw new Cohesion2Exception('Profilo utente non trovato nella risposta fornita da Cohesion2');
        }
    }

    /**
     * @param array<string, scalar|null> $data
     * @throws Cohesion2Exception
     */
    private function httpPost(string $url, array $data): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Cohesion2Exception('Impossibile inizializzare cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Connection: close',
            ],
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $result   = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $errno !== 0) {
            throw new Cohesion2Exception(sprintf(
                'Errore cURL chiamando %s: [%d] %s',
                $url,
                $errno,
                $error !== '' ? $error : 'errore sconosciuto'
            ));
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Cohesion2Exception(sprintf(
                'HTTP %d ricevuto da %s',
                $httpCode,
                $url
            ));
        }

        return is_string($result) ? $result : '';
    }
}
