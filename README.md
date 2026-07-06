# plugin-MapasBlame
plugin para registro de logs do sistema

## Como rodar os testes

Os testes do plugin rodam na stack Docker de testes do repositório principal (`tests/docker-compose.yml`), mas **toda a configuração necessária para habilitar o plugin nessa suíte vive aqui dentro**, em `tests/docker-compose.yml` deste plugin, como um único override via `docker compose -f`. Nenhum arquivo do repositório principal (`tests/config.d/`, `config/`) precisa ser editado.

Os comandos abaixo assumem `cwd = tests/` do **repositório principal** (o caminho relativo do override deste plugin depende disso):

```bash
cd tests   # tests/ do repositório principal, não deste plugin

# build da imagem (necessário na primeira vez ou após mudanças no Dockerfile/composer)
docker compose build

# roda um teste/método específico, com o plugin MapasBlame habilitado
docker compose -f docker-compose.yml -f ../src/plugins/MapasBlame/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/MapasBlame/SmokeTest.php

# roda um método específico
docker compose -f docker-compose.yml -f ../src/plugins/MapasBlame/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/MapasBlame/SmokeTest.php --filter "testPluginIsActive"
```

Sem esse `-f`, a suíte roda normalmente sem o plugin — sem nenhum impacto nos demais testes.

### Como funciona o override (`tests/docker-compose.yml` deste plugin)

`src/conf/config.php` do core varre, em ordem alfabética, todas as pastas terminadas em `.d/` dentro de `config/` e faz `array_merge` do conteúdo de cada uma. O override deste plugin monta `tests/config.d/` (deste diretório — contém `plugins.php`) como uma pasta nova `zz-mapasblame.d/` dentro de `/var/www/config/` — como `"zz-mapasblame.d"` ordena depois de `"config.d"`, a chave `'plugins'` (inclui `MapasBlame`) sobrescreve a do core.

### Testes existentes (`tests/src/`)

| Arquivo | O que valida |
|---|---|
| `SmokeTest.php` | Add-on funcionando: plugin ativo, controller `blame` registrado, tabelas `blame_request`/`blame_log` existem e persistem |
| `PluginConfigTest.php` | `Plugin::__construct` — defaults de config e o comportamento de union (`+=`) ao passar config customizada |
| `PluginGetRequestDataTest.php` | `Plugin::getRequestData()` — despacho para `logData.{METHOD}` a partir das propriedades do controller |
| `PluginRequestHookAssemblyTest.php` | Montagem da string de rotas do hook `mapasculturais.run:before` a partir de `request.types`/`request.routes`/`request.excludeRoutes` |
| `PluginRemoveHookTest.php` | Hook `entity(<<*>>).remove:after` — dispara para entidades normais, ignora `EntityMetadata`, formato do `action`, sem reuso de holder |
| `ControllerApiTest.php` | `register()`/`Controller.php` (construtor, `entityClassName`, `usesAPI()`), mapeamento da entidade `Blame` e `API_find` como admin |
| `PluginAuthHooksTest.php` | Hook `mapas.printJsObject:before` e os gates de autorização de `panel/blame` e da API `blame.<<*>>` |
| `RequestConstructTest.php` | `Request::__construct` — id, metadata, ip, userAgent, sessionId, userId (logado e guest), browser/os/device, `isNew`, conexão |
| `RequestPersistenceTest.php` | `Request::save()`/`log()` — as colunas persistidas, o invariante "1ª log() = 2 INSERTs, N logs = 1 request + N logs", edge cases de metadata |
| `RequestHookEffectTest.php` | Efeito de ponta a ponta do hook de request: gravação via request casada, reuso do holder, formato do `action`, exclusão de `renewLock` |
| `Doubles/TestablePlugin.php` | Double do `Plugin` com `_init()` no-op (evita registrar hooks globais a cada instância de teste) |
| `Doubles/TestableRequest.php` | Double do `Request` expondo getters para `isNew`/`conn` (props protegidas) |
| `Traits/AssertsHooks.php` | Helper `assertHookFired()`/`assertHookNotFired()` — registra um listener para confirmar que um hook foi (ou não foi) disparado, útil quando o efeito do hook não é observável diretamente |
