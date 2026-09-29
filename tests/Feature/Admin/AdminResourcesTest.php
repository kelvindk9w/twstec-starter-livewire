<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Admin\Resources\ApiKeys\Pages\ListApiKeys;
use Twstec\Kit\Admin\Resources\Projects\Pages\ListProjects;
use Twstec\Kit\Admin\Resources\RequestLogs\Pages\ListRequestLogs;
use Twstec\Kit\Admin\Resources\Uploads\Pages\ListUploads;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// Resources do super admin (Filament): listagens com conteúdo,
// ações administrativas (bloquear usuário, revogar chave) e consulta de
// auditoria de request logs com filtros.
// =============================================================================

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('lista usuários com código público, e-mail e status', function () {
    $user = User::factory()->create(['email' => 'cliente@example.com']);

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$user, $this->admin])
        ->assertSee('cliente@example.com')
        ->assertSee($user->codigo_publico);
});

it('bloqueia e desbloqueia usuário pela tabela', function () {
    $user = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callTableAction('block', $user);

    expect($user->fresh()->status)->toBe(UserStatus::Blocked);

    Livewire::test(ListUsers::class)
        ->callTableAction('unblock', $user);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('lista chaves de API de TODOS os tenants (visão global) sem expor a secreta', function () {
    $dono = User::factory()->create(['email' => 'dono@example.com']);
    $key = criarChave($dono, ['name' => 'Chave Visível'])['api_key'];

    Livewire::test(ListApiKeys::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$key])
        ->assertSee('Chave Visível')
        ->assertSee('dono@example.com')
        ->assertDontSee('sk_'); // a secreta JAMAIS aparece (só hash no banco)
})->group('accounts');

it('revoga chave de qualquer tenant pela ação administrativa', function () {
    $dono = User::factory()->create();
    $key = criarChave($dono, ['name' => 'Revogável'])['api_key'];

    Livewire::test(ListApiKeys::class)
        ->callTableAction('revoke', $key);

    expect($key->fresh()->status)->toBe(ApiKeyStatus::Revoked);
})->group('accounts');

it('não oferece revogação para chave já revogada', function () {
    $dono = User::factory()->create();
    $key = criarChave($dono, ['name' => 'Morta'])['api_key'];
    naConta($dono, fn () => app(ApiKeyService::class)->revoke($key));

    Livewire::test(ListApiKeys::class)
        ->assertTableActionHidden('revoke', $key);
})->group('accounts');

it('lista projetos com dono e contagem de chaves vinculadas', function () {
    $dono = User::factory()->create();
    $projeto = projetoDe($dono, 'Projeto Admin');

    Livewire::test(ListProjects::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$projeto])
        ->assertSee('Projeto Admin');
})->group('accounts');

it('lista request logs e destaca órfãos (sem tenant = possível ataque)', function () {
    $logOrfao = RequestLog::query()->create([
        'correlation_id' => (string) str()->uuid7(),
        'tenant_uuid' => null,
        'method' => 'GET',
        'endpoint' => '/api/v1/inexistente',
        'status' => RequestLogStatus::Bloqueada,
    ]);

    $logNormal = RequestLog::query()->create([
        'correlation_id' => (string) str()->uuid7(),
        'tenant_uuid' => (string) str()->uuid7(),
        'method' => 'POST',
        'endpoint' => '/api/v1/projects',
        'status' => RequestLogStatus::Concluida,
    ]);

    Livewire::test(ListRequestLogs::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$logOrfao, $logNormal])
        ->assertSee(__('admin.request_logs.orphan'))
        ->assertSee('/api/v1/projects')
        // Filtro de status: só o BLOQUEADA permanece.
        ->filterTable('status', RequestLogStatus::Bloqueada->value)
        ->assertCanSeeTableRecords([$logOrfao])
        ->assertCanNotSeeTableRecords([$logNormal]);
});

it('filtra request logs: somente órfãos', function () {
    $orfao = RequestLog::query()->create([
        'correlation_id' => (string) str()->uuid7(),
        'tenant_uuid' => null,
        'method' => 'GET',
        'endpoint' => '/api/v1/x',
        'status' => RequestLogStatus::Iniciada,
    ]);

    $normal = RequestLog::query()->create([
        'correlation_id' => (string) str()->uuid7(),
        'tenant_uuid' => (string) str()->uuid7(),
        'method' => 'GET',
        'endpoint' => '/api/v1/y',
        'status' => RequestLogStatus::Concluida,
    ]);

    Livewire::test(ListRequestLogs::class)
        ->filterTable('orphans', true)
        ->assertCanSeeTableRecords([$orfao])
        ->assertCanNotSeeTableRecords([$normal]);
});

it('lista uploads com dono, tipo e tamanho', function () {
    $dono = User::factory()->create();
    $upload = naConta($dono, fn () => Upload::query()->create([
        'disk' => 'local',
        'path' => 'avatars/abc.png',
        'original_name' => 'documento.png',
        'mime' => 'image/png',
        'size' => 2048,
        'sha256' => hash('sha256', 'x'),
    ]));

    Livewire::test(ListUploads::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$upload])
        ->assertSee('documento.png')
        ->assertSee('image/png');
})->group('accounts')->group('uploads');

// R4: com o filtro de ataques em modo observe, a tentativa não vira linha
// BLOQUEADA — o painel precisa mostrar o selo pela coluna `attack_type`.
it('request logs mostram o selo da tentativa (observada ou bloqueada) e filtram só as tentativas', function () {
    $observada = RequestLog::query()->create([
        'correlation_id' => (string) str()->uuid7(),
        'method' => 'POST',
        'endpoint' => 'api/v1/projects',
        'status' => RequestLogStatus::Concluida,
        'attack_type' => 'sqli',
    ]);

    $bloqueada = RequestLog::query()->create([
        'correlation_id' => (string) str()->uuid7(),
        'method' => 'POST',
        'endpoint' => 'api/v1/projects',
        'status' => RequestLogStatus::Bloqueada,
        'attack_type' => 'xss',
    ]);

    $limpa = RequestLog::query()->create([
        'correlation_id' => (string) str()->uuid7(),
        'method' => 'GET',
        'endpoint' => 'api/v1/projects',
        'status' => RequestLogStatus::Concluida,
    ]);

    Livewire::test(ListRequestLogs::class)
        ->assertOk()
        ->assertSee(__('admin.request_logs.attack_observed', ['type' => 'SQL injection']))
        ->assertSee(__('admin.request_logs.attack_blocked', ['type' => 'XSS']))
        ->filterTable('attacks', true)
        ->assertCanSeeTableRecords([$observada, $bloqueada])
        ->assertCanNotSeeTableRecords([$limpa]);
});
