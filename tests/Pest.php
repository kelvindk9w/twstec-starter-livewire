<?php

declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Foundation\Kit;

// Configuração do Pest 4.
// Feature: roda com a aplicação Laravel completa + banco de teste — SQLite em
// memória no phpunit.xml (padrão local) ou PostgreSQL no phpunit.pgsql.xml (CI).

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Testes da DEMONSTRAÇÃO do kit (pacote twstec/kit-demo, instalado só no
// desenvolvimento): mesma base dos de Feature, todos no grupo `demo`. Ficam
// no starter porque exercitam a demo DENTRO do aplicativo (layout, componentes
// Blade, painel, /admin); o que não depende do aplicativo roda na suíte do
// próprio pacote (packages/demo/tests).
//
// Caso de teste do PRODUTO que exercita uma peça da demo (conta demo, tela de
// produtos/submissões, landing, vitrine, contato) também leva `->group('demo')`.
// Sem a demo instalada, todo teste do grupo PULA sozinho (tests/TestCase.php):
// a suíte do produto é o `pest` de sempre.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('demo')
    ->in('Demo');

// Telas do /admin testadas com Livewire::test: o painel opera em MODO SISTEMA
// das contas (vê todas as contas) pelo middleware persistente do plugin
// (Twstec\Kit\Admin\Http\Middleware\OperateAdminPanelAsSystem) — e o
// Livewire::test não passa pela pilha HTTP do painel. Aqui o modo sistema é
// declarado para o teste inteiro, como o painel faz a cada requisição. A prova
// pela requisição de verdade (GET do painel e endpoint do Livewire) está em
// tests/Feature/Accounts/AdminSystemModeTest.php.
pest()->in('Feature/Admin')->beforeEach(function (): void {
    // Sem o pacote de contas não há conta nem modo sistema (o painel se adapta).
    if (Kit::has('accounts')) {
        app(CurrentAccount::class)->push(CurrentAccount::systemFrame('teste do /admin (Livewire::test)'));
    }
});

// MÓDULOS OPCIONAIS: os testes de um módulo que quem instala pode deixar de
// fora (twstec/kit-accounts, twstec/kit-uploads, twstec/kit-admin) ficam no
// grupo com o nome dele — as pastas inteiras aqui, os casos soltos com
// `->group(...)` no próprio arquivo. Sem o módulo, o grupo PULA sozinho
// (tests/TestCase.php): cada combinação roda o `pest` de sempre. Um teste que
// usa dois módulos fica nos dois grupos.
pest()->group('accounts')->in(
    'Feature/Accounts',
    'Feature/Api',
    'Feature/ApiKeys',
    'Feature/Tenancy',
    'Feature/Uploads',
    'Feature/Panel/ApiKeysTest.php',
    'Feature/Panel/ProjectsTest.php',
    'Feature/Security/ApiRateLimitTest.php',
    'Feature/Database/AccountDatabaseGuardsTest.php',
    'Feature/Architecture/AccountModelsTest.php',
    'Feature/Admin/AccountOwnerDeletionTest.php',
    'Feature/Admin/AccountResourceTest.php',
    'Feature/Admin/OrphanedKeysOnDeletionTest.php',
    'Feature/Localization/AccountsPackageTranslationsOverrideTest.php',
    'Feature/Localization/UploadsPackageTranslationsOverrideTest.php',
);

pest()->group('uploads')->in(
    'Feature/Uploads',
    'Feature/Localization/UploadsPackageTranslationsOverrideTest.php',
);

pest()->group('admin')->in(
    'Feature/Admin',
    'Feature/Accounts/AdminSystemModeTest.php',
    'Feature/Architecture/AdminAuditTest.php',
    'Feature/Localization/AdminPackageTranslationsOverrideTest.php',
    'Feature/Security/AdminIpAllowlistTest.php',
    'Feature/Security/AdminLivewireEndpointBarrierTest.php',
    'Feature/Uploads/AdminAvatarTest.php',
);

// Helpers compartilhados da suíte de API Keys/Tenancy.
require_once __DIR__.'/Feature/ApiKeys/Helpers.php';

// Fixtures programáticas de arquivos da suíte de Uploads.
require_once __DIR__.'/Fixtures/uploads.php';

// Chamadas reais ao endpoint de atualização do Livewire (barreiras de acesso
// que só existem na rota de verdade — ver o próprio arquivo).
require_once __DIR__.'/Feature/Support/LivewireEndpoint.php';

// Unit: sobe a aplicação (sem banco) para helpers que dependem do container
// (ex.: platform()). Testes puramente isolados continuam funcionando.
pest()->extend(TestCase::class)
    ->in('Unit');
