<?php

namespace Tests\MapasBlame\Traits;

use MapasCulturais\App;

/**
 * Helper para confirmar que um hook foi (ou não foi) disparado durante uma ação,
 * sem depender de efeito colateral observável (sessão, banco, etc.) do próprio hook.
 */
trait AssertsHooks
{
    /**
     * Registra um listener no hook informado, executa $action() e confirma que o hook foi
     * disparado pelo menos uma vez durante a execução. O listener nunca é removido — fica
     * registrado no App (singleton) pelo resto do processo, como qualquer outro registrado
     * via $app->hook(); inofensivo aqui porque só seta uma flag local.
     */
    protected function assertHookFired(string $hookName, callable $action, string $message = ''): void
    {
        $app = App::i();
        $fired = false;

        $app->hook($hookName, function () use (&$fired) {
            $fired = true;
        });

        $action();

        $this->assertTrue(
            $fired,
            $message !== '' ? $message : "Esperava que o hook '{$hookName}' fosse disparado, mas não foi."
        );
    }

    /**
     * Inverso de assertHookFired: confirma que o hook NÃO foi disparado durante a ação.
     */
    protected function assertHookNotFired(string $hookName, callable $action, string $message = ''): void
    {
        $app = App::i();
        $fired = false;

        $app->hook($hookName, function () use (&$fired) {
            $fired = true;
        });

        $action();

        $this->assertFalse(
            $fired,
            $message !== '' ? $message : "Esperava que o hook '{$hookName}' NÃO fosse disparado, mas foi."
        );
    }
}
