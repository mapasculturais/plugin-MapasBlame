<?php

namespace Tests\MapasBlame\Traits;

/**
 * `App::reset()` (core/App.php:380-393), chamado por `TestCase::setUp()` antes de cada teste,
 * não toca `$app->request` — um teste que atribui `$app->request` (para satisfazer
 * `MapasBlame\Request::__construct`, que lê `$app->request->getIp()`) deixa esse valor vazando
 * para o próximo teste que rodar no mesmo processo, mesmo em outro arquivo (a suíte roda sem
 * isolamento de processo). Este trait tira um snapshot de `$app->request` antes da mutação e o
 * restaura depois, do mesmo jeito que `RestoresHookRegistry` faz para o registro de hooks.
 */
trait RestoresAppRequest
{
    private $appRequestSnapshot;

    protected function snapshotAppRequest(): void
    {
        $this->appRequestSnapshot = $this->app->request;
    }

    protected function restoreAppRequest(): void
    {
        $this->app->request = $this->appRequestSnapshot;
    }
}
