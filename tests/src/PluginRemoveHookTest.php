<?php

namespace Tests\MapasBlame;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Request as CoreRequest;
use Tests\Abstract\TestCase;
use Tests\MapasBlame\Traits\AssertsHooks;
use Tests\MapasBlame\Traits\RestoresAppRequest;
use Tests\Traits\AgentDirector;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class PluginRemoveHookTest extends TestCase
{
    use AssertsHooks;
    use AgentDirector;
    use RequestFactory;
    use RestoresAppRequest;
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotAppRequest();

        // MapasBlame\Request::__construct lê $app->request->getIp(), e $app->request
        // é null por padrão em teste — Plugin.php:119 cria um `new Request()` a cada
        // remoção, então precisa estar montado antes de qualquer teste deste arquivo.
        $psr7 = $this->requestFactory->GET('site', 'index');
        $this->app->request = new CoreRequest($psr7, 'site', 'index', []);
    }

    protected function tearDown(): void
    {
        $this->restoreAppRequest();
        parent::tearDown();
    }

    private function snapshotBlameRequestIds(): array
    {
        return $this->app->em->getConnection()->fetchColumn('SELECT id FROM blame_request');
    }

    private function fetchBlameLogs(string $requestId): array
    {
        return $this->app->em->getConnection()->fetchAll(
            'SELECT * FROM blame_log WHERE request_id = ? ORDER BY id',
            [$requestId]
        );
    }

    private function newBlameRequestIds(array $before): array
    {
        $after = $this->snapshotBlameRequestIds();

        return array_values(array_diff($after, $before));
    }

    /**
     * Agent usa a trait EntitySoftDelete, que sobrescreve delete() para um soft-delete
     * (muda `status` para TRASH) — a remoção física via Doctrine (que dispara o postRemove
     * lifecycle callback e, por consequência, `entity(<<*>>).remove:after`) só acontece
     * através de destroy(), que chama parent::delete() (Entity::delete() real). Além disso,
     * Agent::canUserDestroy() (Agent.php:525-531) exige superAdmin — por isso o usuário
     * logado nos testes deste arquivo é criado com esse papel.
     */
    private function destroyAgent($agent): void
    {
        $agent->destroy(true);
    }

    function testRemovingNormalEntityFiresTheRemoveHook()
    {
        $user = $this->userDirector->createUser('superAdmin');
        $this->login($user);
        $agent = $this->agentDirector->createIndividual($user);

        $this->assertHookFired('entity(Agent).remove:after', function () use ($agent) {
            $this->destroyAgent($agent);
        });
    }

    function testRemovingNormalEntityInsertsOneBlameRequestAndOneBlameLogEndingInDelete()
    {
        $user = $this->userDirector->createUser('superAdmin');
        $this->login($user);
        $agent = $this->agentDirector->createIndividual($user);

        $before = $this->snapshotBlameRequestIds();
        $this->destroyAgent($agent);
        $new = $this->newBlameRequestIds($before);

        $this->assertCount(1, $new, 'Remover uma entidade normal deveria criar exatamente 1 blame_request');

        $logs = $this->fetchBlameLogs($new[0]);
        $this->assertCount(1, $logs, 'A remoção deveria gerar exatamente 1 blame_log');
        $this->assertStringEndsWith(' DELETE', $logs[0]['action']);
        // Plugin.php:119-120 chama $request->log("{$this} DELETE", []) — metadata é sempre
        // o array vazio literal, nunca dados da entidade removida.
        $this->assertSame('[]', $logs[0]['metadata']);
    }

    function testActionUsesEntityToStringFollowedByDelete()
    {
        $user = $this->userDirector->createUser('superAdmin');
        $this->login($user);
        $agent = $this->agentDirector->createIndividual($user);
        // Doctrine zera o id em memória antes de disparar o postRemove lifecycle callback
        // (é isso que dispara nosso hook) — "{$this}" nesse momento já não tem mais o id,
        // vira "ClassName:" (vazio), não "ClassName:123". Comportamento atual observado.
        $expectedAction = get_class($agent) . ': DELETE';

        $before = $this->snapshotBlameRequestIds();
        $this->destroyAgent($agent);
        $new = $this->newBlameRequestIds($before);

        $logs = $this->fetchBlameLogs($new[0]);
        $this->assertSame($expectedAction, $logs[0]['action']);
    }

    function testRemovingEntityMetadataDoesNotLog()
    {
        $user = $this->userDirector->createUser('superAdmin');
        $this->login($user);
        $agent = $this->agentDirector->createIndividual($user);

        // 'sentNotification' é registrada como metadata do Agent pela classe base Theme
        // (Theme.php:158-160, hook 'app.register') — disponível independente do tema ativo.
        $agent->sentNotification = true;
        $agent->saveMetadata();

        $agentMeta = $this->app->repo('AgentMeta')->findOneBy(['owner' => $agent, 'key' => 'sentNotification']);
        $this->assertNotNull($agentMeta, 'saveMetadata() deveria ter persistido um AgentMeta "sentNotification"');

        $before = $this->snapshotBlameRequestIds();

        // Confirma que o hook REALMENTE dispara para AgentMeta (senão o assertCount(0, ...)
        // abaixo passaria de qualquer forma, mesmo que o hook nunca alcançasse nosso closure
        // — o que provaria uma coisa bem diferente do "return" do instanceof em Plugin.php:116).
        $this->assertHookFired('entity(AgentMeta).remove:after', function () use ($agentMeta) {
            $this->app->em->remove($agentMeta);
            $this->app->em->flush();
        }, 'Remover um AgentMeta deveria disparar entity(AgentMeta).remove:after (para então ser ignorado pelo instanceof)');

        $new = $this->newBlameRequestIds($before);
        $this->assertCount(0, $new, 'Remover uma instância de EntityMetadata não deveria gerar um blame_request');
    }

    function testEachRemovalCreatesItsOwnBlameRequest()
    {
        // Sem login: nada aqui passa por checkPermission — o disparo do hook é manual
        // (abaixo), não via destroy()/Doctrine, então não há checagem de permissão a
        // satisfazer.
        $user = $this->userDirector->createUser();

        // Duas instâncias de Agent NÃO persistidas, só para servir de $this ao closure —
        // o que este cenário precisa provar é que Plugin.php:119 (`$request = new Request()`,
        // sem holder) cria um Request novo a cada disparo, não que a remoção física via
        // Doctrine funciona (já coberto pelos testes acima). Disparar o hook diretamente evita
        // o pipeline completo de remoção (revisão + relações de taxonomia) de dois agentes
        // reais em sequência, irrelevante para o que está sendo travado aqui.
        $agentA = new Agent($user);
        $agentB = new Agent($user);

        $before = $this->snapshotBlameRequestIds();
        $this->app->applyHookBoundTo($agentA, 'entity(Agent).remove:after', []);
        $this->app->applyHookBoundTo($agentB, 'entity(Agent).remove:after', []);
        $new = $this->newBlameRequestIds($before);

        $this->assertCount(2, $new, 'Cada remoção deveria criar seu próprio blame_request — sem reuso de holder');
    }
}
