<?php
declare(strict_types=1);

namespace andreaval\Cohesion2\Tests;

use andreaval\Cohesion2\Cohesion2;
use andreaval\Cohesion2\Cohesion2Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cohesion2::class)]
final class Cohesion2Test extends TestCase
{
    /**
     * Variabili d'ambiente lette dalla libreria. Vengono ripulite in setUp
     * per garantire isolamento fra test.
     *
     * @var list<string>
     */
    private const ENV_KEYS = [
        'COHESION2_ALLOWED_HOSTS',
        'COHESION2_TRUST_PROXY',
        'COHESION2_SITE_ID',
    ];

    /**
     * Server vars che la libreria usa per costruire la callback URL.
     *
     * @var list<string>
     */
    private const SERVER_KEYS = [
        'HTTP_HOST',
        'SERVER_NAME',
        'SERVER_PORT',
        'HTTPS',
        'HTTP_X_FORWARDED_PROTO',
        'REQUEST_URI',
        'QUERY_STRING',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_KEYS as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        foreach (self::SERVER_KEYS as $k) {
            unset($_SERVER[$k]);
        }
        $_GET = [];
        $_SESSION = [];
    }

    // ────────────────────────────────────────────────────────────────────
    // resolveSiteId
    // ────────────────────────────────────────────────────────────────────

    public function testSiteIdDefaultIsTest(): void
    {
        self::assertSame('TEST', $this->getProp(new Cohesion2(), 'siteId'));
    }

    public function testSiteIdFromConstructor(): void
    {
        $c = new Cohesion2('cohesion2', null, null, 'PORTALE_X');
        self::assertSame('PORTALE_X', $this->getProp($c, 'siteId'));
    }

    public function testSiteIdFromEnv(): void
    {
        $_ENV['COHESION2_SITE_ID'] = 'ENV_PORTALE';
        self::assertSame('ENV_PORTALE', $this->getProp(new Cohesion2(), 'siteId'));
    }

    public function testSiteIdConstructorOverridesEnv(): void
    {
        $_ENV['COHESION2_SITE_ID'] = 'ENV_PORTALE';
        $c = new Cohesion2('cohesion2', null, null, 'EXPLICIT');
        self::assertSame('EXPLICIT', $this->getProp($c, 'siteId'));
    }

    // ────────────────────────────────────────────────────────────────────
    // resolveAllowedHosts
    // ────────────────────────────────────────────────────────────────────

    public function testAllowedHostsDefaultIsEmpty(): void
    {
        self::assertSame([], $this->getProp(new Cohesion2(), 'allowedHosts'));
    }

    public function testAllowedHostsFromConstructor(): void
    {
        $c = new Cohesion2('cohesion2', ['app.example.it', 'www.example.it']);
        self::assertSame(['app.example.it', 'www.example.it'], $this->getProp($c, 'allowedHosts'));
    }

    public function testAllowedHostsFromEnvCsv(): void
    {
        $_ENV['COHESION2_ALLOWED_HOSTS'] = 'a.example.it,b.example.it,c.example.it';
        self::assertSame(
            ['a.example.it', 'b.example.it', 'c.example.it'],
            $this->getProp(new Cohesion2(), 'allowedHosts')
        );
    }

    public function testAllowedHostsTrimsAndFiltersEmpty(): void
    {
        $_ENV['COHESION2_ALLOWED_HOSTS'] = '  a.example.it ,, b.example.it , ';
        self::assertSame(
            ['a.example.it', 'b.example.it'],
            $this->getProp(new Cohesion2(), 'allowedHosts')
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // resolveTrustProxy
    // ────────────────────────────────────────────────────────────────────

    public function testTrustProxyDefaultFalse(): void
    {
        self::assertFalse($this->getProp(new Cohesion2(), 'trustProxy'));
    }

    public function testTrustProxyFromConstructor(): void
    {
        self::assertTrue($this->getProp(new Cohesion2('cohesion2', null, true), 'trustProxy'));
        self::assertFalse($this->getProp(new Cohesion2('cohesion2', null, false), 'trustProxy'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function envTrustProxyValues(): iterable
    {
        yield '"1"'     => ['1',     true];
        yield '"true"'  => ['true',  true];
        yield '"on"'    => ['on',    true];
        yield '"yes"'   => ['yes',   true];
        yield '"0"'     => ['0',     false];
        yield '"false"' => ['false', false];
        yield '"off"'   => ['off',   false];
        yield '"no"'    => ['no',    false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('envTrustProxyValues')]
    public function testTrustProxyFromEnv(string $envValue, bool $expected): void
    {
        $_ENV['COHESION2_TRUST_PROXY'] = $envValue;
        self::assertSame($expected, $this->getProp(new Cohesion2(), 'trustProxy'));
    }

    // ────────────────────────────────────────────────────────────────────
    // resolveProtocol
    // ────────────────────────────────────────────────────────────────────

    public function testProtocolHttpsViaServerPort(): void
    {
        $_SERVER['SERVER_PORT'] = '443';
        self::assertSame('https://', $this->call(new Cohesion2(), 'resolveProtocol'));
    }

    public function testProtocolHttpsViaHttpsServerVar(): void
    {
        $_SERVER['SERVER_PORT'] = '8443';
        $_SERVER['HTTPS']       = 'on';
        self::assertSame('https://', $this->call(new Cohesion2(), 'resolveProtocol'));
    }

    public function testProtocolHttpsServerVarOffMeansHttp(): void
    {
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS']       = 'off';
        self::assertSame('http://', $this->call(new Cohesion2(), 'resolveProtocol'));
    }

    public function testProtocolDefaultHttp(): void
    {
        $_SERVER['SERVER_PORT'] = '80';
        self::assertSame('http://', $this->call(new Cohesion2(), 'resolveProtocol'));
    }

    /**
     * Mitigazione spoofing: senza opt-in esplicito, X-Forwarded-Proto
     * non deve influenzare il protocollo.
     */
    public function testProtocolXForwardedIgnoredWithoutTrustProxy(): void
    {
        $_SERVER['SERVER_PORT']            = '80';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        self::assertSame(
            'http://',
            $this->call(new Cohesion2('cohesion2', null, false), 'resolveProtocol'),
            'X-Forwarded-Proto deve essere ignorato senza trustProxy'
        );
    }

    public function testProtocolXForwardedRespectedWithTrustProxy(): void
    {
        $_SERVER['SERVER_PORT']            = '80';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        self::assertSame('https://', $this->call(new Cohesion2('cohesion2', null, true), 'resolveProtocol'));
    }

    public function testProtocolXForwardedCsvUsesFirst(): void
    {
        $_SERVER['SERVER_PORT']            = '80';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http, http';
        self::assertSame('https://', $this->call(new Cohesion2('cohesion2', null, true), 'resolveProtocol'));
    }

    // ────────────────────────────────────────────────────────────────────
    // resolveCallbackHost
    // ────────────────────────────────────────────────────────────────────

    public function testCallbackHostUsesServerNameWithoutWhitelist(): void
    {
        $_SERVER['HTTP_HOST']   = 'evil.com';
        $_SERVER['SERVER_NAME'] = 'app.legit.it';
        self::assertSame('app.legit.it', $this->call(new Cohesion2(), 'resolveCallbackHost'));
    }

    public function testCallbackHostFallsBackToHttpHostWhenNoServerName(): void
    {
        $_SERVER['HTTP_HOST'] = 'app.legit.it';
        self::assertSame('app.legit.it', $this->call(new Cohesion2(), 'resolveCallbackHost'));
    }

    public function testCallbackHostAllowsWhitelistedHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'app.legit.it';
        $c = new Cohesion2('cohesion2', ['app.legit.it', 'www.legit.it']);
        self::assertSame('app.legit.it', $this->call($c, 'resolveCallbackHost'));
    }

    public function testCallbackHostMatchIsCaseInsensitive(): void
    {
        $_SERVER['HTTP_HOST'] = 'APP.legit.IT';
        $c = new Cohesion2('cohesion2', ['app.legit.it']);
        self::assertSame('APP.legit.IT', $this->call($c, 'resolveCallbackHost'));
    }

    /**
     * Mitigazione open redirect (CWE-601). Un Host header non whitelisted
     * deve sollevare eccezione invece di proseguire e generare la callback.
     */
    public function testCallbackHostRejectsHostHeaderInjection(): void
    {
        $_SERVER['HTTP_HOST']   = 'evil.com';
        $_SERVER['SERVER_NAME'] = 'app.legit.it';
        $c = new Cohesion2('cohesion2', ['app.legit.it']);

        $this->expectException(Cohesion2Exception::class);
        $this->expectExceptionMessageMatches('/Host non consentito/');
        $this->call($c, 'resolveCallbackHost');
    }

    public function testCallbackHostRejectsEmptyHostWhenWhitelistConfigured(): void
    {
        $c = new Cohesion2('cohesion2', ['app.legit.it']);
        $this->expectException(Cohesion2Exception::class);
        $this->call($c, 'resolveCallbackHost');
    }

    // ────────────────────────────────────────────────────────────────────
    // buildAuthXml
    // ────────────────────────────────────────────────────────────────────

    public function testBuildAuthXmlIsWellFormed(): void
    {
        $xml = $this->call(new Cohesion2(), 'buildAuthXml', 'https://app.example.it/login.php?cohesionCheck=1');

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'XML non ben formato');

        $required = ['dsAuth', 'auth', 'user', 'id_sa', 'id_sito', 'url_validate', 'url_richiesta', 'stilesheet', 'AuthRestriction'];
        foreach ($required as $name) {
            self::assertGreaterThan(0, $dom->getElementsByTagName($name)->length, "manca <$name>");
        }
    }

    public function testBuildAuthXmlIncludesCdataUrl(): void
    {
        $url = 'https://app.example.it/login.php?foo=bar&cohesionCheck=1';
        $xml = $this->call(new Cohesion2(), 'buildAuthXml', $url);
        self::assertStringContainsString('<![CDATA[' . $url . ']]>', $xml);
    }

    public function testBuildAuthXmlIncludesSiteId(): void
    {
        $c = new Cohesion2('cohesion2', null, null, 'PORTALE_X');
        $xml = $this->call($c, 'buildAuthXml', 'https://app.example.it/?cohesionCheck=1');
        self::assertMatchesRegularExpression('|<id_sito>PORTALE_X</id_sito>|', $xml);
    }

    public function testBuildAuthXmlIncludesEidasFlag(): void
    {
        $c = new Cohesion2();
        $c->enableEIDASLogin();
        $xml = $this->call($c, 'buildAuthXml', 'https://app.example.it/?cohesionCheck=1');
        self::assertStringContainsString('eidas=1', $xml);
    }

    public function testBuildAuthXmlIncludesPurposeFlag(): void
    {
        $c = new Cohesion2();
        $c->enableSPIDProLogin(['PF', 'PG']);
        $xml = $this->call($c, 'buildAuthXml', 'https://app.example.it/?cohesionCheck=1');
        self::assertStringContainsString('purpose=PF|PG', $xml);
    }

    /**
     * Mitigazione XML Injection (CWE-91). Un valore di authRestriction
     * che contiene caratteri di markup deve essere escaped, non
     * interpretato come elementi XML.
     */
    public function testBuildAuthXmlEscapesAuthRestrictionMarkup(): void
    {
        $c = new Cohesion2();
        $c->setAuthRestriction(']]><evil>injected</evil><dummy>');
        $xml = $this->call($c, 'buildAuthXml', 'https://app.example.it/?cohesionCheck=1');
        self::assertStringNotContainsString('<evil>',     $xml);
        self::assertStringNotContainsString('<dummy>',    $xml);
        self::assertStringContainsString(  '&lt;evil&gt;', $xml);
    }

    /**
     * `]]>` nella URL di callback non è rappresentabile in un singolo CDATA:
     * la libreria deve sollevare eccezione invece di generare XML
     * potenzialmente ambiguo.
     */
    public function testBuildAuthXmlRejectsCdataInjectionInUrl(): void
    {
        $this->expectException(Cohesion2Exception::class);
        $this->expectExceptionMessageMatches('/CDATA/');
        $this->call(new Cohesion2(), 'buildAuthXml', 'https://app.example.it/path]]><evil/>');
    }

    // ────────────────────────────────────────────────────────────────────
    // helper
    // ────────────────────────────────────────────────────────────────────

    private function call(Cohesion2 $instance, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($instance, $method);
        return $ref->invoke($instance, ...$args);
    }

    private function getProp(Cohesion2 $instance, string $property): mixed
    {
        $ref = new \ReflectionProperty($instance, $property);
        return $ref->getValue($instance);
    }
}
