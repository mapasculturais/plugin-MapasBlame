<?php

namespace Tests\MapasBlame;

use Tests\Abstract\TestCase;
use Tests\MapasBlame\Doubles\TestablePlugin;

class PluginGetRequestDataTest extends TestCase
{
    function testGetRequestDataUrlReadsUrlDataAndAppliesLogData()
    {
        $plugin = new TestablePlugin();

        $controller = new \stdClass();
        $controller->urlData = ['id' => 42];

        $this->assertSame(['id' => 42], $plugin->getRequestData($controller, 'URL'));
    }

    function testGetRequestDataGetReadsGetDataAndAppliesLogData()
    {
        $plugin = new TestablePlugin();

        $controller = new \stdClass();
        $controller->getData = ['page' => 2];

        $this->assertSame(['page' => 2], $plugin->getRequestData($controller, 'GET'));
    }

    function testGetRequestDataPostReadsPostDataAndAppliesDefaultLogData()
    {
        $plugin = new TestablePlugin();

        $controller = new \stdClass();
        $controller->postData = ['password' => 'secreta'];

        $this->assertSame([], $plugin->getRequestData($controller, 'POST'));
    }

    function testGetRequestDataPropertyNameIsDerivedFromLowercaseMethod()
    {
        // Sobrescreve logData.PATCH com identidade para expor o valor lido de $controller,
        // provando que a propriedade acessada foi "patchData" (strtolower('PATCH') . 'Data').
        $identity = function ($data) {
            return $data;
        };
        $plugin = new TestablePlugin(['request.logData.PATCH' => $identity]);

        $controller = new \stdClass();
        $controller->patchData = ['field' => 'valor'];

        $this->assertSame(['field' => 'valor'], $plugin->getRequestData($controller, 'PATCH'));
    }
}
