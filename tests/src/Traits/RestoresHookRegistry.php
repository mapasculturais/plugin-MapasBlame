<?php

namespace Tests\MapasBlame\Traits;

/**
 * Isola, entre métodos/arquivos de teste, os efeitos de $app->run() sobre o registro de
 * hooks do App.
 *
 * O App é singleton e a suíte roda sem isolamento de processo — hooks registrados via
 * $app->hook() (inclusive os que MapasBlame\Plugin::_init() registra a cada $app->run(), de
 * forma não idempotente: cada disparo acrescenta MAIS UM listener permanente no padrão de
 * rotas) sobrevivem para o resto do processo, a menos que algo os remova. Isso faz um
 * arquivo de teste que despacha $app->run() vazar listeners para os testes seguintes,
 * possivelmente com holders presos a linhas de banco já revertidas pelo rollback do teste.
 *
 * Este trait tira um snapshot do registro de hooks ANTES de cada teste e o restaura
 * EXATAMENTE como estava depois — removendo só o que foi registrado durante o teste, sem
 * tocar em nada que já existisse antes. Diferente de $app->clearHooks($nome), que remove por
 * casamento de padrão contra o nome informado e pode atingir listeners legítimos de outros
 * módulos/plugins que coincidentemente casem o mesmo nome.
 */
trait RestoresHookRegistry
{
    private $hookRegistrySnapshot;

    protected function snapshotHookRegistry(): void
    {
        $this->hookRegistrySnapshot = [
            'hooks' => $this->readHooksProperty('_hooks'),
            'excludeHooks' => $this->readHooksProperty('_excludeHooks'),
        ];
    }

    protected function restoreHookRegistry(): void
    {
        $this->writeHooksProperty('_hooks', $this->hookRegistrySnapshot['hooks']);
        $this->writeHooksProperty('_excludeHooks', $this->hookRegistrySnapshot['excludeHooks']);
        // getCallables() cacheia por nome firado; sem isso, um nome já consultado durante o
        // teste continuaria retornando os callables removidos (ou deixando de retornar os
        // restaurados) até que hook() registrasse algo novo e limpasse o cache sozinho.
        $this->writeHooksProperty('_hookCache', []);
    }

    private function readHooksProperty(string $property)
    {
        $reflection = new \ReflectionProperty(\MapasCulturais\Hooks::class, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($this->app->hooks);
    }

    private function writeHooksProperty(string $property, $value): void
    {
        $reflection = new \ReflectionProperty(\MapasCulturais\Hooks::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($this->app->hooks, $value);
    }
}
