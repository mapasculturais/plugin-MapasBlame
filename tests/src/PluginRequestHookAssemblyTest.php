<?php

namespace Tests\MapasBlame;

use Tests\Abstract\TestCase;
use Tests\MapasBlame\Doubles\TestablePlugin;

class PluginRequestHookAssemblyTest extends TestCase
{
    function testDefaultConfigAssemblesExpectedRouteString()
    {
        $plugin = new TestablePlugin();

        $this->assertSame(
            '<<GET|DELETE|PATCH|POST|PUT>>(<<*>>):before,-<<*>>(<<*>>.renewLock):before',
            $plugin->buildRequestHookRoutes()
        );
    }

    function testRequestTypesAreJoinedByPipeInsideVerbGroup()
    {
        $plugin = new TestablePlugin(['request.types' => ['POST', 'PUT']]);

        $this->assertSame(
            '<<POST|PUT>>(<<*>>):before,-<<*>>(<<*>>.renewLock):before',
            $plugin->buildRequestHookRoutes()
        );
    }

    function testEachRequestRouteGeneratesOnePositiveEntry()
    {
        $plugin = new TestablePlugin([
            'request.routes' => ['rota.a', 'rota.b'],
            'request.excludeRoutes' => [],
        ]);

        $this->assertSame(
            '<<GET|DELETE|PATCH|POST|PUT>>(rota.a):before,<<GET|DELETE|PATCH|POST|PUT>>(rota.b):before',
            $plugin->buildRequestHookRoutes()
        );
    }

    function testEachExcludeRouteGeneratesOneNegativeEntry()
    {
        $plugin = new TestablePlugin([
            'request.routes' => [],
            'request.excludeRoutes' => ['rota.a.renewLock', 'rota.b.renewLock'],
        ]);

        $this->assertSame(
            '-<<*>>(rota.a.renewLock):before,-<<*>>(rota.b.renewLock):before',
            $plugin->buildRequestHookRoutes()
        );
    }

    function testPositiveAndNegativeEntriesAreJoinedByComma()
    {
        $plugin = new TestablePlugin([
            'request.routes' => ['rota.a'],
            'request.excludeRoutes' => ['rota.a.renewLock'],
        ]);

        $this->assertSame(
            '<<GET|DELETE|PATCH|POST|PUT>>(rota.a):before,-<<*>>(rota.a.renewLock):before',
            $plugin->buildRequestHookRoutes()
        );
    }

    // ===== request.enable => false =====
    //
    // O guard `if ($plugin->config['request.enable'])` fica FORA da montagem da string
    // (Plugin.php:75), envolvendo tanto a montagem quanto o registro do hook — não há como
    // observá-lo chamando buildRequestHookRoutes() isoladamente, e testá-lo de verdade exigiria
    // disparar `mapasculturais.run:before` numa instância real do Plugin (registrando um hook
    // permanente na suíte, sem isolamento de processo). Skip deliberado: a preservação de
    // `request.enable => false` na config já está coberta por
    // PluginConfigTest::testConfigRequestEnableFalseIsPreserved.
}
