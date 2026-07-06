<?php

namespace Tests\MapasBlame\Doubles;

use MapasBlame\Plugin;

/**
 * Sobrescreve _init() para não-operação: MapasCulturais\Module::__construct chama _init()
 * direto no construtor, e o _init() real do Plugin registra hooks globais no App
 * (mapasculturais.run:before, entity(<<*>>).remove:after, etc.) — instanciar o Plugin real
 * repetidamente nos testes acumularia esses hooks a cada instância, sem isolamento de processo.
 */
class TestablePlugin extends Plugin
{
    function _init()
    {
    }

    /**
     * Espelha a montagem da string de rotas feita em Plugin.php:76-86, hoje inline dentro
     * da closure de `mapasculturais.run:before` (registrada apenas quando _init() roda de
     * verdade). Mesma expressão, fora da closure — se essa lógica mudar em Plugin.php, este
     * método precisa ser atualizado manualmente para não dessincronizar.
     */
    function buildRequestHookRoutes(): string
    {
        $request_types = implode('|', $this->config['request.types']);
        $routes = [];

        foreach ($this->config['request.routes'] as $route) {
            $routes[] = "<<$request_types>>($route):before";
        }
        foreach ($this->config['request.excludeRoutes'] as $route) {
            $routes[] = "-<<*>>($route):before";
        }

        return implode(',', $routes);
    }
}
