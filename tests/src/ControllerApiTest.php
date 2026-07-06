<?php

namespace Tests\MapasBlame;

use Laminas\Diactoros\ServerRequest;
use MapasBlame\Controller;
use MapasBlame\Entities\Blame;
use Tests\Abstract\TestCase;
use Tests\MapasBlame\Traits\RestoresHookRegistry;
use Tests\Traits\UserDirector;

class ControllerApiTest extends TestCase
{
    use RestoresHookRegistry;
    use UserDirector;

    // Nota: "$app->controller('blame') resolve para MapasBlame\Controller" já está coberto
    // por SmokeTest::testBlameControllerIsRegistered — não duplicado aqui.

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

    /**
     * Plugin.php:97 lê $_SERVER['REQUEST_URI'] direto — precisa estar montado antes de
     * qualquer dispatch real ($app->run()) que caia no fallback não-api de erro (ex.: 404),
     * senão dispara um warning de chave indefinida.
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

    function testConstructorDoesNotThrow()
    {
        $controller = new Controller();

        $this->assertInstanceOf(Controller::class, $controller);
    }

    function testEntityClassNameIsExplicit()
    {
        $controller = new Controller();

        $this->assertSame('MapasBlame\Entities\Blame', $controller->entityClassName);
    }

    function testUsesApiReturnsTrue()
    {
        $this->assertTrue(Controller::usesAPI());
    }

    /**
     * Blame.php:9-11 declara TANTO `@ORM\Entity(readOnly=true)` QUANTO
     * `@ORM\entity(repositoryClass=...)` (mesma classe, diferindo só no case — PHP resolve
     * nomes de classe sem diferenciar maiúsculas/minúsculas) — a segunda anotação (sem
     * readOnly) sobrescreve a primeira, e `readOnly=true` é perdido silenciosamente na
     * metadata real do Doctrine. É a ÚNICA entidade no codebase combinando os dois desse
     * jeito — comportamento atual observado, não a intenção do código.
     */
    function testBlameEntityReadOnlyAnnotationIsSilentlyOverriddenByRepositoryClassAnnotation()
    {
        $metadata = $this->app->em->getClassMetadata(Blame::class);

        $this->assertFalse($metadata->isReadOnly);
        $this->assertSame('blame', $metadata->getTableName());
    }

    /**
     * Cobre também o cenário "API blame.<<*>> como admin → não é barrada pelo gate 403"
     * (Plugin.php:132-135) — o mesmo hit satisfaz as duas asserções.
     */
    function testApiFindAsAdminRespondsWithArray()
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $request = new ServerRequest(method: 'GET', uri: '/api/blame/find');

        $this->app->reset();
        $this->app->run($request, false);

        $this->assertSame(200, $this->app->response->getStatusCode());

        $body = json_decode((string) $this->app->response->getBody(), true);
        $this->assertIsArray($body);
    }

    /**
     * `Controller::callAction()` (core/Controller.php:305-308) só chama um método real para
     * dispatch de API se existir `API_{$action}` no controller — para método 'API' a
     * fallback `ALL_{$action}` é explicitamente pulada (Controller.php:317-319,
     * "$method !== 'API'"). `MapasBlame\Controller` não declara `API_index` nem qualquer hook
     * `API(blame.index)` — sem `$call_method` nem `$has_hook`, cai em `$app->pass()`
     * (Controller.php:349), que lança `Exceptions\NotFound` e vira 404
     * (RoutesManager.php:88-91). Comportamento observado: a superfície `/api` do controller
     * `blame` só expõe consulta (`find`, via `ControllerApiV2`) — nenhum verbo de escrita
     * chega perto de instanciar `Blame` (cujo `__construct` é privado, Blame.php:145) por essa
     * via. A superfície `/api` do controller `blame`, portanto, só expõe consulta — nenhum
     * verbo de escrita chega a instanciar `Blame` por esse caminho; ver
     * `ControllerNonApiSurfaceTest` para a superfície não-api, que expõe escrita de fato.
     */
    function testApiPostAsAdminIs404BecauseControllerHasNoApiIndexAction()
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $request = new ServerRequest(method: 'POST', uri: '/api/blame');

        $this->withRequestUri('/api/blame', function () use ($request) {
            $this->app->reset();
            $this->app->run($request, false);
        });

        $this->assertSame(404, $this->app->response->getStatusCode());
    }

    /**
     * Insere direto via conexão (mesmo padrão do SmokeTest) 1 par blame_request+blame_log —
     * db-updates.php:76-97 mapeia essas colunas na view "blame" que a entidade Blame lê.
     */
    private function seedBlameRequestAndLog(int $userId, string $action): string
    {
        $requestId = substr(md5(uniqid('', true)), 0, 13);
        $conn = $this->app->em->getConnection();

        $conn->insert('blame_request', [
            'id' => $requestId,
            'ip' => '203.0.113.10',
            'session_id' => str_repeat('a', 32),
            'user_id' => $userId,
            'metadata' => json_encode(['URL' => [], 'GET' => []]),
            'user_agent' => 'ControllerApiTest-Agent/1.0',
            'user_browser_name' => 'ControllerApiTest Browser',
            'user_browser_version' => '1.0',
            'user_os' => 'ControllerApiTest OS',
            'user_device' => 'ControllerApiTest Device',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        $conn->insert('blame_log', [
            'request_id' => $requestId,
            'action' => $action,
            'metadata' => json_encode([]),
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        return $requestId;
    }

    /**
     * A entidade Blame nunca era exercitada com dados reais na suíte — este teste semeia uma
     * linha real e confirma a hidratação com o mesmo @select e filtro de userId que
     * components/blame-table/script.js:52 usa verbatim.
     *
     * Descoberta: apesar do front-end pedir `user.{email,profile.{name,avatar}}`, a API
     * devolve só `{id, @entityType}` para a relação user — os subcampos pedidos são
     * ignorados. Comportamento atual observado (a tabela do painel provavelmente exibe
     * célula vazia onde esperaria email/nome/avatar do usuário) — não é escopo desta suíte
     * investigar a causa em ApiQuery::_preCreateSelectSubquery, só travar o que é.
     */
    function testApiFindReturnsHydratedRowMatchingFrontendSelect()
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $user = $this->userDirector->createUser();
        $requestId = $this->seedBlameRequestAndLog($user->id, 'GET /site/index (site.index)');

        $request = new ServerRequest(method: 'GET', uri: '/api/blame/find', queryParams: [
            '@select' => 'id,sessionId,requestId,requestTimestamp,action,logTimestamp,userBrowserName,userBrowserVersion,ip,userId,userOS,userDevice,user.{email,profile.{name,avatar}}',
            'userId' => "EQ({$user->id})",
        ]);

        $this->app->reset();
        $this->app->run($request, false);

        $this->assertSame(200, $this->app->response->getStatusCode());

        $body = json_decode((string) $this->app->response->getBody(), true);

        $this->assertCount(1, $body, 'Deveria retornar exatamente a linha semeada para este userId');
        $row = $body[0];

        $this->assertSame($requestId, $row['requestId']);
        $this->assertSame($user->id, $row['userId']);
        $this->assertSame('GET /site/index (site.index)', $row['action']);
        $this->assertSame('203.0.113.10', $row['ip']);
        // Comportamento observado (ver docblock do teste): os subcampos pedidos em
        // user.{email,profile.{name,avatar}} não vêm — só id e @entityType da relação.
        $this->assertSame(['id' => $user->id, '@entityType' => 'user'], $row['user']);
    }

    /**
     * db-updates.php:76-97 monta a view com LEFT JOIN blame_request->blame_log — uma
     * blame_request sem log correspondente (exatamente o que save() sem log() produz,
     * RequestPersistenceTest::testSaveCalledDirectlyInsertsNothingIntoBlameLog) aparece na
     * view com as colunas do lado blame_log NULL, incluindo log_id — que é o @ORM\Id da
     * entidade Blame (Blame.php:20-23).
     *
     * Descoberta: a hidratação NÃO falha nem lança exceção com id=null — Doctrine devolve a
     * linha normalmente (id/action null, o resto das colunas de blame_request presentes).
     * Comportamento atual observado, não assumido.
     */
    function testApiFindHydratesBlameRequestWithoutLogAsNullIdAndAction()
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $user = $this->userDirector->createUser();

        $requestId = substr(md5(uniqid('', true)), 0, 13);
        $conn = $this->app->em->getConnection();
        $conn->insert('blame_request', [
            'id' => $requestId,
            'ip' => '203.0.113.99',
            'session_id' => str_repeat('c', 32),
            'user_id' => $user->id,
            'metadata' => json_encode([]),
            'user_agent' => 'ControllerApiTest-Agent/1.0',
            'user_browser_name' => 'ControllerApiTest Browser',
            'user_browser_version' => '1.0',
            'user_os' => 'ControllerApiTest OS',
            'user_device' => 'ControllerApiTest Device',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        // Sem INSERT em blame_log de propósito — é o caso órfão do LEFT JOIN.

        $request = new ServerRequest(method: 'GET', uri: '/api/blame/find', queryParams: [
            '@select' => 'id,requestId,action,ip,userId',
            'userId' => "EQ({$user->id})",
        ]);

        $this->app->reset();
        $this->app->run($request, false);

        $this->assertSame(200, $this->app->response->getStatusCode());

        $body = json_decode((string) $this->app->response->getBody(), true);

        $this->assertCount(1, $body);
        $row = $body[0];

        $this->assertNull($row['id']);
        $this->assertNull($row['action']);
        $this->assertSame($requestId, $row['requestId']);
        $this->assertSame($user->id, $row['userId']);
        $this->assertSame('203.0.113.99', $row['ip']);
    }
}
