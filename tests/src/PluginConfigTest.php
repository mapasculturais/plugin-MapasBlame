<?php

namespace Tests\MapasBlame;

use Tests\Abstract\TestCase;
use Tests\MapasBlame\Doubles\TestablePlugin;

class PluginConfigTest extends TestCase
{
    // ===== Defaults (sem config) =====

    function testDefaultRequestTypesIncludesAllHttpVerbs()
    {
        $plugin = new TestablePlugin();

        $this->assertSame(['GET', 'DELETE', 'PATCH', 'POST', 'PUT'], $plugin->config['request.types']);
    }

    function testDefaultRequestEnableIsTrue()
    {
        $plugin = new TestablePlugin();

        $this->assertTrue($plugin->config['request.enable']);
    }

    function testDefaultRequestRoutesIsWildcard()
    {
        $plugin = new TestablePlugin();

        $this->assertSame(['<<*>>'], $plugin->config['request.routes']);
    }

    function testDefaultRequestExcludeRoutesIsRenewLock()
    {
        $plugin = new TestablePlugin();

        $this->assertSame(['<<*>>.renewLock'], $plugin->config['request.excludeRoutes']);
    }

    function testDefaultLogDataUrlReturnsDataUnchanged()
    {
        $plugin = new TestablePlugin();

        $data = ['foo' => 'bar'];
        $this->assertSame($data, $plugin->config['request.logData.URL']($data));
    }

    function testDefaultLogDataGetReturnsDataUnchanged()
    {
        $plugin = new TestablePlugin();

        $data = ['foo' => 'bar'];
        $this->assertSame($data, $plugin->config['request.logData.GET']($data));
    }

    function testDefaultLogDataWriteVerbsReturnEmptyArray()
    {
        $plugin = new TestablePlugin();

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertSame(
                [],
                $plugin->config["request.logData.{$method}"](['foo' => 'bar']),
                "request.logData.{$method} deveria retornar []"
            );
        }
    }

    // ===== Config passado no construtor (union `+=`) =====

    function testConfigRequestTypesOverridesDefault()
    {
        $plugin = new TestablePlugin(['request.types' => ['POST', 'PUT']]);

        $this->assertSame(['POST', 'PUT'], $plugin->config['request.types']);
    }

    function testConfigRequestEnableFalseIsPreserved()
    {
        $plugin = new TestablePlugin(['request.enable' => false]);

        $this->assertFalse($plugin->config['request.enable']);
    }

    function testConfigExtraKeyNotPresentInDefaultsIsPreserved()
    {
        $plugin = new TestablePlugin(['some.extra.key' => 'valor']);

        $this->assertSame('valor', $plugin->config['some.extra.key']);
    }

    function testConfigLogDataPostOverridesDefault()
    {
        $custom = function ($data) {
            return ['custom' => true];
        };

        $plugin = new TestablePlugin(['request.logData.POST' => $custom]);

        $this->assertSame(['custom' => true], $plugin->config['request.logData.POST']([]));
    }
}
