<?php

namespace Tests\MapasBlame;

use MapasBlame\Entities\Blame;
use MapasCulturais\Exceptions\PermissionDenied;
use Tests\Abstract\TestCase;
use Tests\MapasBlame\Traits\RestoresHookRegistry;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * O gate de autorização do plugin (Plugin.php:132-136) só cobre `API(blame.<<*>>):before` —
 * rotas /api. Mas `MapasBlame\Controller` herda `EntityController` completo (Controller.php:6
 * — `ControllerEntity`, `ControllerEntityActions`, `ControllerEntityViews`), que expõe rotas
 * NÃO-api como `GET /blame/single/{id}` e `DELETE /blame/single/{id}` — o core só dispara
 * hooks `API(...)` para rotas /api (core/Controller.php:305-308), então essas rotas nunca
 * passam pelo gate do plugin. Nunca considerado nem documentado antes desta revisão.
 */
class ControllerNonApiSurfaceTest extends TestCase
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

    private function seedBlameLogId(int $userId): int
    {
        $requestId = substr(md5(uniqid('', true)), 0, 13);
        $conn = $this->app->em->getConnection();

        $conn->insert('blame_request', [
            'id' => $requestId,
            'ip' => '203.0.113.50',
            'session_id' => str_repeat('d', 32),
            'user_id' => $userId,
            'metadata' => json_encode([]),
            'user_agent' => 'ControllerNonApiSurfaceTest',
            'user_browser_name' => 'ControllerNonApiSurfaceTest',
            'user_browser_version' => '1.0',
            'user_os' => 'ControllerNonApiSurfaceTest',
            'user_device' => 'ControllerNonApiSurfaceTest',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        $conn->insert('blame_log', [
            'request_id' => $requestId,
            'action' => 'ACTION',
            'metadata' => json_encode([]),
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->app->em->getConnection()->fetchScalar(
            'SELECT id FROM blame_log WHERE request_id = ?',
            [$requestId]
        );
    }

    // ===== GET /blame/single/{id} =====

    function testGetSingleAsGuestIs404()
    {
        $user = $this->userDirector->createUser();
        $logId = $this->seedBlameLogId($user->id);

        $request = $this->requestFactory->GET('blame', 'single', [$logId], ajax: true);

        $this->withRequestUri("/blame/single/{$logId}", function () use ($request) {
            $this->assertStatus404($request);
        });
    }

    /**
     * canUserView (Entity.php, herdado — Blame não sobrescreve) não usa getOwnerUser(): cai
     * em canUser('@control'), que checa $this->owner (indefinido em Blame, só existe $user) —
     * nenhum branch de canUser_control passa para um não-admin. Ser "o autor da ação logada"
     * (blame_request.user_id) não concede view. Comportamento observado, não assumido.
     */
    function testGetSingleAsAuthorOfTheLoggedActionIs404()
    {
        $user = $this->userDirector->createUser();
        $logId = $this->seedBlameLogId($user->id);
        $this->login($user);

        $request = $this->requestFactory->GET('blame', 'single', [$logId], ajax: true);

        $this->withRequestUri("/blame/single/{$logId}", function () use ($request) {
            $this->assertStatus404($request);
        });
    }

    function testGetSingleAsAdminRespondsWithEntityJson()
    {
        $admin = $this->userDirector->createUser('admin');
        $other = $this->userDirector->createUser();
        $logId = $this->seedBlameLogId($other->id);
        $this->login($admin);

        $request = $this->requestFactory->GET('blame', 'single', [$logId], ajax: true);

        $this->withRequestUri("/blame/single/{$logId}", function () use ($request) {
            $this->app->reset();
            $this->app->run($request, false);
        });

        $this->assertSame(200, $this->app->response->getStatusCode());

        $body = json_decode((string) $this->app->response->getBody(), true);
        $this->assertSame($logId, $body['id']);
        $this->assertSame($other->id, $body['user']['id']);
    }

    // ===== DELETE /blame/single/{id} =====

    function testDeleteSingleAsNonAuthorNonAdminIsForbidden()
    {
        $user = $this->userDirector->createUser();
        $other = $this->userDirector->createUser();
        $logId = $this->seedBlameLogId($other->id);
        $this->login($user);

        $request = $this->requestFactory->DELETE('blame', 'single', [$logId]);

        $this->withRequestUri("/blame/single/{$logId}", function () use ($request) {
            $this->assertStatus403($request);
        });
    }

    /**
     * NÃO despachamos um DELETE real como autor/admin aqui — ver docblock da classe e a nota
     * abaixo. Este teste caracteriza só a CHECAGEM DE PERMISSÃO (Entity::checkPermission,
     * chamada direta, sem passar por Controller::DELETE_single), que é o que determina se a
     * remoção sequer chega a ser tentada.
     *
     * Descoberta: DELETE_single() (core/Traits/ControllerEntityActions.php:376-386) chama
     * $entity->delete(true) sem NENHUMA checagem própria — delega inteiramente a
     * Entity::canUserRemove(), que concede permissão tanto para admin quanto para
     * getOwnerUser()->id == $user->id. Como Blame tem uma relação real $user
     * (Blame.php:41-49), Entity::getOwnerUser() (Entity.php:328-333, "isset($this->user)")
     * retorna essa relação — ou seja, QUALQUER usuário autenticado passa na checagem de
     * permissão de remoção para uma linha de auditoria que registrou uma ação SUA PRÓPRIA,
     * não só admin.
     *
     * Verificado manualmente (fora da suíte automatizada, pelo risco abaixo) que a remoção
     * de fato tentada — tanto pelo autor quanto por um admin — falha no Postgres com
     * "cannot delete from view "blame": Views that do not select from a single table or view
     * are not automatically updatable" (SQLSTATE 55000), e essa falha FECHA o EntityManager
     * do Doctrine para o resto do processo PHP — como a suíte roda sem isolamento de
     * processo, qualquer arquivo de teste que rodasse depois deste na mesma execução
     * quebraria com "EntityManager is closed". Por isso a suíte automatizada trava só a
     * checagem de permissão (que não toca o banco além de um SELECT), não a tentativa real
     * de DELETE.
     */
    function testDeleteSingleCheckPermissionPassesForAuthorOfTheLoggedActionEvenNotAdmin()
    {
        $user = $this->userDirector->createUser();
        $logId = $this->seedBlameLogId($user->id);
        $this->login($user);

        $blame = $this->app->repo(Blame::class)->find($logId);

        $threw = false;
        try {
            $blame->checkPermission('remove');
        } catch (PermissionDenied $e) {
            $threw = true;
        }

        $this->assertFalse(
            $threw,
            'O autor da ação logada (blame_request.user_id) deveria passar na checagem de permissão de remoção, mesmo não sendo admin'
        );
    }
}
