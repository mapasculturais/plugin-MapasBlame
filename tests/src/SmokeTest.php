<?php

namespace Tests\MapasBlame;

use MapasBlame\Controller;
use MapasBlame\Plugin;
use Tests\Abstract\TestCase;

/**
 * Smoke test de validação do add-on de testes do plugin MapasBlame
 * (config + autoload + fixture, ver tests/docker-compose.yml deste plugin).
 */
class SmokeTest extends TestCase
{
    function testPluginIsActive()
    {
        $app = $this->app;

        $this->assertArrayHasKey('MapasBlame', $app->plugins);
        $this->assertInstanceOf(Plugin::class, $app->plugins['MapasBlame']);
    }

    function testBlameControllerIsRegistered()
    {
        $app = $this->app;

        $this->assertInstanceOf(Controller::class, $app->controller('blame'));
    }

    function testBlameTablesExistAndPersist()
    {
        $conn = $this->app->em->getConnection();

        $requestId = substr(md5(uniqid('', true)), 0, 13);

        $conn->insert('blame_request', [
            'id' => $requestId,
            'ip' => '127.0.0.1',
            'session_id' => str_repeat('a', 32),
            'user_id' => 0,
            'metadata' => json_encode([]),
            'user_agent' => 'SmokeTest',
            'user_browser_name' => 'SmokeTest',
            'user_browser_version' => '1.0',
            'user_os' => 'SmokeTest OS',
            'user_device' => 'SmokeTest Device',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        $conn->insert('blame_log', [
            'request_id' => $requestId,
            'action' => 'SmokeTest ACTION',
            'metadata' => json_encode([]),
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        $request = $conn->fetchAssoc('SELECT * FROM blame_request WHERE id = ?', [$requestId]);
        $log = $conn->fetchAssoc('SELECT * FROM blame_log WHERE request_id = ?', [$requestId]);

        $this->assertNotNull($request, 'A linha inserida em blame_request deve ser lida de volta');
        $this->assertNotNull($log, 'A linha inserida em blame_log deve ser lida de volta');
        $this->assertSame('SmokeTest ACTION', $log['action']);
    }
}
