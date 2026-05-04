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
     * @param string $session_name Nome da assegnare alla variabile di sessione. Default: cohesion2
     */
    public function __construct(string $session_name = 'cohesion2')
    {
        $this->session_name = $session_name;
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
        $urlPagina = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $urlPagina .= ($_SERVER['QUERY_STRING']) ? '&' : '?';
        $urlPagina .= 'cohesionCheck=1';
        $xmlAuth = '<dsAuth xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://tempuri.org/Auth.xsd">
            <auth>
                <user />
                <id_sa />
                <id_sito>TEST</id_sito>
                <esito_auth_sa />
                <id_sessione_sa />
                <id_sessione_aspnet_sa />
                <url_validate><![CDATA[' . $urlPagina . ']]></url_validate>
                <url_richiesta><![CDATA[' . $urlPagina . ']]></url_richiesta>
                <esito_auth_sso />
                <id_sessione_sso />
                <id_sessione_aspnet_sso />
                <stilesheet>AuthRestriction=' . $this->authRestriction
                    . ($this->eIDAS ? ';' . self::EIDAS_FLAG : '')
                    . ($this->SPIDProPurpose !== null ? ';' . self::PURPOSE_FLAG . $this->SPIDProPurpose : '')
                . '</stilesheet>
                <AuthRestriction xmlns="">' . $this->authRestriction . '</AuthRestriction>
            </auth>
        </dsAuth>';
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
     * @throws Cohesion2Exception
     */
    private function verify(string $auth): void
    {
        $xml = trim(base64_decode($auth));
        $domXML = new \DOMDocument();
        $domXML->loadXML($xml);
        $this->id_sso    = $domXML->getElementsByTagName('id_sessione_sso')->item(0)?->nodeValue;
        $this->id_aspnet = $domXML->getElementsByTagName('id_sessione_aspnet_sso')->item(0)?->nodeValue;
        $this->username  = $domXML->getElementsByTagName('user')->item(0)?->nodeValue;
        $esito           = $domXML->getElementsByTagName('esito_auth_sso')->item(0)?->nodeValue;
        if ($esito !== 'OK' || $this->id_sso === null || $this->id_sso === '' || $this->id_aspnet === null || $this->id_aspnet === '') {
            throw new Cohesion2Exception("Errore in fase di autenticazione ($esito,$this->id_sso,$this->id_aspnet)");
        }

        $url = $this->saml20 ? self::COHESION2_SAML20_WEB : self::COHESION2_WEB;
        $data = [
            'Operation'        => 'GetCredential',
            'IdSessioneSSO'    => $this->id_sso,
            'IdSessioneASPNET' => $this->id_aspnet,
        ];
        $result = $this->httpPost($url, $data);
        $domXML->loadXML($result);
        $profilo = simplexml_import_dom($domXML);
        $base = current($profilo->xpath('//base'));
        if (is_object($base) && $base->login) {
            $resp = [];
            foreach ($base->children() as $node) {
                $resp[$node->getName()] = (string) $node;
            }
            $this->profile = $resp;
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
