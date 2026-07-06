<?php

namespace Tests\MapasBlame;

use Laminas\Diactoros\ServerRequest;
use MapasBlame\Entities\Blame;
use Tests\Abstract\TestCase;
use Tests\MapasBlame\Traits\RestoresHookRegistry;
use Tests\Traits\UserDirector;

class PluginAuthHooksTest extends TestCase
{
    use RestoresHookRegistry;
    use UserDirector;

    private $viewControllerSnapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotHookRegistry();
        $this->viewControllerSnapshot = $this->app->view->controller;
    }

    protected function tearDown(): void
    {
        // Cada $app->run() contra uma rota casada (panel/blame) faz o listener de
        // mapasculturais.run:before de Plugin.php:74 registrar MAIS UM listener permanente
        // no padrão de rotas — o closure não é idempotente, os listeners antigos nunca são
        // removidos. Sem restaurar aqui, esses listeners sobrevivem além deste arquivo e, ao
        // disparar de novo em outro arquivo, tentam reusar um Request cujo blame_request já
        // foi desfeito pelo rollback deste teste — violação de FK que aborta a transação do
        // teste seguinte. restoreHookRegistry() (ao contrário de clearHooks(), usado aqui
        // antes) remove só o que foi registrado durante este teste, sem atingir listeners
        // legítimos de outros módulos/plugins (LGPD, MultipleLocalAuth, ProfileCompletion)
        // que também casam o nome 'GET(panel.blame):before'.
        $this->restoreHookRegistry();

        // testPrintJsObjectPopulatesEntitiesDescriptionForBlame atribui $app->view->controller
        // diretamente (Theme.php:164-169 lê essa propriedade) — sem restaurar aqui, o valor
        // vaza para outros arquivos que rodam depois no mesmo processo (sem isolamento).
        $this->app->view->controller = $this->viewControllerSnapshot;

        parent::tearDown();
    }

    /**
     * Plugin.php:97 lê $_SERVER['REQUEST_URI'] direto — precisa estar montado antes de
     * qualquer dispatch real ($app->run()), senão dispara um warning de chave indefinida.
     */
    private function withRequestUri(string $uri, callable $callback)
    {
        $hadKey = array_key_exists('REQUEST_URI', $_SERVER);
        $previous = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = $uri;

        try {
            return $callback();
        } finally {
            if ($hadKey) {
                $_SERVER['REQUEST_URI'] = $previous;
            } else {
                unset($_SERVER['REQUEST_URI']);
            }
        }
    }

    function testPrintJsObjectPopulatesEntitiesDescriptionForBlame()
    {
        $theme = $this->app->view;
        // Theme.php:164-169 também escuta mapas.printJsObject:before e lê
        // $app->view->controller->id/action/urlData — sem um controller real montado
        // (nenhum dispatch aconteceu neste teste), isso dispararia warnings de
        // "read property on null".
        $theme->controller = $this->app->controller('site');

        $this->app->applyHookBoundTo($theme, 'mapas.printJsObject:before');

        $this->assertSame(
            Blame::getPropertiesMetadata(),
            $theme->jsObject['EntitiesDescription']['blame']
        );
    }

    function testPanelBlameRequiresAuthenticationForGuest()
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/panel/blame',
            headers: ['X-Requested-With' => 'XMLHttpRequest']
        );

        $this->withRequestUri('/panel/blame', function () use ($request) {
            $this->assertStatus401($request);
        });
    }

    /**
     * Descoberta: nenhum tema no repositório (BaseV1, BaseV2, Funarte, MapaMinC, Pnab,
     * Subsite) tem um arquivo de view "panel/blame-system" — hitar esta rota autenticado
     * produz hoje um erro 500 ("Template panel/blame-system.php not found"), não um render
     * bem-sucedido. O gate de autenticação (Plugin.php:127-129) não é o que bloqueia — o
     * usuário passa por requireAuthentication() normalmente; é a chamada a
     * $this->render('blame-system', []) logo em seguida que falha. Comportamento atual
     * travado como está, não a intenção original do código.
     */
    function testPanelBlameAuthenticatedPassesAuthenticationButTemplateIsMissing()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $request = new ServerRequest(method: 'GET', uri: '/panel/blame');

        $this->withRequestUri('/panel/blame', function () use ($request) {
            $this->assertHttpStatusCode($request, 500);
        });
    }

    function testApiBlameAsNonAdminIsForbidden()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $request = new ServerRequest(method: 'GET', uri: '/api/blame/find');

        $this->withRequestUri('/api/blame/find', function () use ($request) {
            $this->assertStatus403($request);
        });
    }

    function testApiBlameAsGuestIsForbidden()
    {
        // setUp() já faz logout(); usuário atual é GuestUser.
        $request = new ServerRequest(method: 'GET', uri: '/api/blame/find');

        $this->withRequestUri('/api/blame/find', function () use ($request) {
            $this->assertStatus403($request);
        });
    }

    // Nota: "API blame.<<*>> como admin → não é barrada pelo gate 403" já está coberto por
    // ControllerApiTest::testApiFindAsAdminRespondsWithArray — não duplicado aqui.
}
