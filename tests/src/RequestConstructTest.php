<?php

namespace Tests\MapasBlame;

use MapasCulturais\Request as CoreRequest;
use Sinergi\BrowserDetector\Browser;
use Sinergi\BrowserDetector\Device;
use Sinergi\BrowserDetector\Os;
use Tests\Abstract\TestCase;
use Tests\MapasBlame\Doubles\TestableRequest;
use Tests\MapasBlame\Traits\RestoresAppRequest;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class RequestConstructTest extends TestCase
{
    use RequestFactory;
    use RestoresAppRequest;
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotAppRequest();
    }

    protected function tearDown(): void
    {
        $this->restoreAppRequest();
        parent::tearDown();
    }

    /**
     * MapasBlame\Request::__construct lê $app->request->getIp(), então $app->request
     * (null por padrão em teste) precisa estar montado antes de cada instanciação.
     */
    private function setAppRequestIp(?string $ip): void
    {
        $psr7 = $this->requestFactory->GET('site', 'index');

        if ($ip !== null) {
            $psr7 = $psr7->withAttribute('ip_address', $ip);
        }

        $this->app->request = new CoreRequest($psr7, 'site', 'index', []);
    }

    function testIdReceivesA13CharToken()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest();

        $this->assertSame(13, strlen($request->id));
    }

    function testTwoConsecutiveRequestsHaveDistinctIds()
    {
        $this->setAppRequestIp('203.0.113.10');

        $a = new TestableRequest();
        $b = new TestableRequest();

        $this->assertNotSame($a->id, $b->id);
    }

    function testEmptyMetadataArrayBecomesEmptyStdClass()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest([]);

        $this->assertEquals(new \stdClass(), $request->metadata);
    }

    function testAssociativeMetadataArrayBecomesStdClassWithSameKeysAndValues()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest(['foo' => 'bar', 'baz' => 1]);

        $this->assertEquals((object) ['foo' => 'bar', 'baz' => 1], $request->metadata);
    }

    function testIpComesFromRequestIpAddressAttributeWhenPresent()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest();

        $this->assertSame('203.0.113.10', $request->ip);
    }

    function testIpIsEmptyStringWhenIpAddressAttributeIsAbsent()
    {
        $this->setAppRequestIp(null);

        $request = new TestableRequest();

        $this->assertSame('', $request->ip);
    }

    function testUserAgentComesFromServerSuperglobalWhenSet()
    {
        $this->setAppRequestIp('203.0.113.10');

        $previous = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'MapasBlameTest-Agent/1.0';

        try {
            $request = new TestableRequest();

            $this->assertSame('MapasBlameTest-Agent/1.0', $request->userAgent);
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $previous;
            }
        }
    }

    function testUserAgentIsEmptyStringWhenServerSuperglobalIsAbsent()
    {
        $this->setAppRequestIp('203.0.113.10');

        $hadKey = array_key_exists('HTTP_USER_AGENT', $_SERVER);
        $previous = $_SERVER['HTTP_USER_AGENT'] ?? null;
        unset($_SERVER['HTTP_USER_AGENT']);

        try {
            $request = new TestableRequest();

            $this->assertSame('', $request->userAgent);
        } finally {
            if ($hadKey) {
                $_SERVER['HTTP_USER_AGENT'] = $previous;
            }
        }
    }

    function testSessionIdMatchesPhpSessionId()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest();

        $this->assertSame(session_id(), $request->sessionId);
    }

    function testUserIdIsLoggedInUserId()
    {
        $this->setAppRequestIp('203.0.113.10');

        $user = $this->userDirector->createUser();
        $this->login($user);

        $request = new TestableRequest();

        $this->assertSame($user->id, $request->userId);
    }

    function testUserIdIsZeroForGuest()
    {
        $this->setAppRequestIp('203.0.113.10');

        // setUp() já faz logout(); usuário atual é GuestUser (id === 0).
        $request = new TestableRequest();

        $this->assertSame(0, $request->userId);
    }

    function testBrowserOsDeviceAreSinergiInstances()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest();

        $this->assertInstanceOf(Browser::class, $request->browser);
        $this->assertInstanceOf(Os::class, $request->os);
        $this->assertInstanceOf(Device::class, $request->device);
    }

    function testIsNewIsTrueRightAfterConstruction()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest();

        $this->assertTrue($request->isNew());
    }

    function testConnIsEntityManagerConnection()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest();

        $this->assertSame($this->app->em->getConnection(), $request->conn());
    }

    function testConstructorWithoutArgumentDefaultsToEmptyMetadata()
    {
        $this->setAppRequestIp('203.0.113.10');

        $request = new TestableRequest();

        $this->assertEquals(new \stdClass(), $request->metadata);
    }
}
