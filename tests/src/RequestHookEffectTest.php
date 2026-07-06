<?php

namespace Tests\MapasBlame;

use Laminas\Diactoros\ServerRequest;
use Tests\Abstract\TestCase;
use Tests\MapasBlame\Traits\RestoresHookRegistry;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * Efeito do hook de request (Plugin.php:88-110). Todos os cenários vivem em UM ÚNICO teste,
 * sequencialmente, de propósito: MapasCulturais\Plugin registra o listener de log de request
 * em `mapasculturais.run:before`, disparado só por $app->run() — e esse listener NÃO é
 * idempotente: cada disparo cria um holder novo e registra MAIS UM listener no padrão de
 * rotas montado (os antigos nunca são removidos). Em produção isso é inofensivo (1 processo
 * PHP = 1 $app->run()). No processo de teste compartilhado, se dois MÉTODOS de teste
 * diferentes cada um chamasse $app->run() (cada um com sua própria transação, com rollback em
 * tearDown()), o holder do primeiro sobrevive (é um objeto PHP, não estado de banco) e, ao
 * disparar de novo no segundo método, tenta inserir em blame_log com uma FK para o
 * blame_request que o rollback do primeiro método já desfez — violação de FK que aborta a
 * transação inteira. Por isso: um único método, uma única transação sem rollback no meio — os
 * holders acumulados ao longo do teste continuam válidos porque nada é desfeito entre os
 * passos. O tearDown() restaura o registro de hooks ao estado anterior ao teste, para que os
 * listeners registrados aqui não vazem para outros arquivos que rodam depois no mesmo
 * processo.
 */
class RequestHookEffectTest extends TestCase
{
    use RequestFactory;
    use RestoresHookRegistry;
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotHookRegistry();
    }

    protected function tearDown(): void
    {
        $this->restoreHookRegistry();
        parent::tearDown();
    }

    private function snapshotBlameRequestIds(): array
    {
        return $this->app->em->getConnection()->fetchColumn('SELECT id FROM blame_request');
    }

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

    private function fetchBlameLogs(string $requestId): array
    {
        return $this->app->em->getConnection()->fetchAll(
            'SELECT * FROM blame_log WHERE request_id = ? ORDER BY id',
            [$requestId]
        );
    }

    private function countAllBlameLogs(): int
    {
        return (int) $this->app->em->getConnection()->fetchScalar('SELECT count(*) FROM blame_log');
    }

    /**
     * Dispara um hit real via $app->run() contra uma rota casada e retorna o ID do
     * ÚNICO blame_request novo que aparece — falha se não for exatamente 1 novo ID.
     */
    private function hitMatchedRouteAndGetNewRequestId($request, string $requestUri): string
    {
        $before = $this->snapshotBlameRequestIds();

        $this->withRequestUri($requestUri, function () use ($request) {
            $this->app->reset();
            $this->app->run($request, false);
        });

        $after = $this->snapshotBlameRequestIds();
        $new = array_values(array_diff($after, $before));

        $this->assertCount(1, $new, 'Esperava exatamente 1 novo blame_request após o hit');

        return $new[0];
    }

    function testRequestHookEffect()
    {
        // ===== Um hit numa rota casada gera 1 blame_request + 1 blame_log =====
        $requestA = $this->requestFactory->GET('site', 'index');
        $idA = $this->hitMatchedRouteAndGetNewRequestId($requestA, '/site/index');
        $this->assertCount(1, $this->fetchBlameLogs($idA));

        // ===== action no formato "{method} {request_uri} ({id}.{action})" =====
        $logsA = $this->fetchBlameLogs($idA);
        $this->assertSame('GET /site/index (site.index)', $logsA[0]['action']);

        // ===== GET não adiciona chave de escrita ao metadata (só URL + GET) =====
        $metadataA = json_decode($logsA[0]['metadata'], true);
        $this->assertEqualsCanonicalizing(['URL', 'GET'], array_keys($metadataA));

        // ===== Reuso do holder: 2º disparo na MESMA request usa o mesmo Request =====
        $afterFirstHit = $this->snapshotBlameRequestIds();
        // App::controller() é singleton — reaproveita a MESMA instância que processou o
        // dispatch acima, com method/id/action já setados por Controller::callAction. Sem
        // novo $app->run(), nenhum holder novo é criado: o disparo manual abaixo reusa o
        // holder recém-criado pelo hit de cima.
        $controller = $this->app->controller('site');
        $this->withRequestUri('/site/index', function () use ($controller) {
            $this->app->applyHookBoundTo(
                $controller,
                "{$controller->method}({$controller->id}.{$controller->action}):before",
                []
            );
        });
        $afterSecondFiring = $this->snapshotBlameRequestIds();

        $this->assertSame($afterFirstHit, $afterSecondFiring, 'Um segundo disparo na mesma request não deveria criar um novo blame_request');
        $this->assertCount(2, $this->fetchBlameLogs($idA), 'O segundo disparo deveria reusar o Request e adicionar um segundo blame_log');

        // ===== metadata sempre inclui as chaves URL e GET, com o valor capturado verbatim =====
        $requestB = $this->requestFactory->GET('site', 'index', [], ['foo' => 'bar']);
        $idB = $this->hitMatchedRouteAndGetNewRequestId($requestB, '/site/index');
        $logsB = $this->fetchBlameLogs($idB);
        $metadataB = json_decode($logsB[0]['metadata'], true);
        $this->assertArrayHasKey('URL', $metadataB);
        $this->assertArrayHasKey('GET', $metadataB);
        // logData.URL/GET default são a closure identidade (Plugin.php:19-24) — os valores
        // devem ser exatamente urlData/getData do controller, não só as chaves presentes.
        // 'site.index' não tem parâmetros de URL, então urlData é vazio.
        $this->assertSame([], $metadataB['URL']);
        $this->assertSame(['foo' => 'bar'], $metadataB['GET']);

        // ===== Verbo de escrita adiciona metadata[$method] — e o valor descarta o corpo =====
        // 'site.clearCache' é ALL_clearCache() — aceita qualquer verbo HTTP e não exige
        // entidade/permissão para disparar o hook :before (só age se o usuário for
        // superAdmin, o que não é necessário aqui). RequestFactory não constrói PUT, então
        // cobrimos POST/PATCH/DELETE. O payload não-vazio abaixo existe para provar que o
        // corpo É descartado (logData.POST default retorna [], Plugin.php:25-27) — a decisão
        // de privacidade central do plugin (nunca persistir senha/dado sensível em blame_log).
        foreach (['POST', 'PATCH', 'DELETE'] as $method) {
            $request = $this->requestFactory->{$method}('site', 'clearCache', payload: ['password' => 'segredo']);
            $id = $this->hitMatchedRouteAndGetNewRequestId($request, '/site/clearCache');
            $logs = $this->fetchBlameLogs($id);
            $metadata = json_decode($logs[0]['metadata'], true);
            $this->assertArrayHasKey($method, $metadata, "metadata deveria ter a chave {$method}");
            $this->assertSame([], $metadata[$method], "metadata[{$method}] deveria descartar o corpo da request (default de logData.{$method})");
        }

        // ===== Rotas /api não geram nenhum log de request =====
        // 'API' está comentado em request.types (Plugin.php:16) e o core só dispara hooks
        // API(controller.action):before para essas rotas — nunca GET(...)/POST(...) (core
        // Controller.php:305-308) — então o hook de log de request nunca chega a rodar aqui,
        // mesmo com um dispatch real e bem-sucedido (200, admin autenticado).
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);
        $beforeApi = $this->snapshotBlameRequestIds();
        $apiRequest = new ServerRequest(method: 'GET', uri: '/api/blame/find');
        $this->app->reset();
        $this->app->run($apiRequest, false);
        $afterApi = $this->snapshotBlameRequestIds();
        $this->assertSame($beforeApi, $afterApi, 'Uma rota /api não deveria gerar um novo blame_request');

        // ===== Ação *.renewLock é excluída do log de request =====
        $beforeRenewLock = $this->snapshotBlameRequestIds();
        // A esta altura do teste já existem vários holders acumulados (um por hit anterior),
        // todos com Request não-nulo — só medir blame_request não basta: se a exclusão fosse
        // removida da produção, os disparos ainda reusariam esses Requests existentes e
        // inseririam só em blame_log (Plugin.php:92), nunca um blame_request novo. Por isso
        // medimos os dois lados.
        $beforeBlameLogCount = $this->countAllBlameLogs();
        // Dispara diretamente o nome de hook que uma ação real "*.renewLock" produziria
        // (Controller.php:308,332) — testa o mecanismo de exclusão do App\Hooks
        // (Plugin.php:18, `<<*>>.renewLock`) sem depender de uma entidade/rota real. Reusa o
        // registro já feito pelos hits acima, nesta mesma execução.
        $context = (object) ['method' => 'POST', 'id' => 'agent', 'action' => 'renewLock'];
        $this->app->applyHookBoundTo($context, 'POST(agent.renewLock):before', []);
        $afterRenewLock = $this->snapshotBlameRequestIds();
        $afterBlameLogCount = $this->countAllBlameLogs();

        $this->assertSame($beforeRenewLock, $afterRenewLock, 'Uma ação *.renewLock não deveria gerar um novo blame_request');
        $this->assertSame($beforeBlameLogCount, $afterBlameLogCount, 'Uma ação *.renewLock não deveria gerar nenhum blame_log, nem reusando um Request existente');
    }
}
