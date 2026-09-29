<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Foundation\Security\Middleware\EnsureAdminIpAllowed;

// =============================================================================
// BARREIRAS DO /admin TAMBÉM NO ENDPOINT DE ATUALIZAÇÃO DO LIVEWIRE
//
// O achado: o endpoint de atualização do Livewire é UM SÓ para o painel do
// usuário e para o painel Filament, e é uma rota fora do prefixo /admin — ela
// não carrega a pilha de middleware do painel. As barreiras do /admin valiam
// na navegação (GET /admin/...), mas uma AÇÃO de componente do admin chega por
// essa outra rota. O Livewire reaplica ali só os middlewares declarados como
// "persistentes" da rota original do componente; o Filament já declara o seu
// Authenticate (é por isso que admin inativo continuava barrado), mas a
// allowlist de IP NÃO estava na lista: de fora da allowlist, uma ação de
// componente do admin executava.
//
// A correção declara a EnsureAdminIpAllowed como persistente no painel
// (App\Providers\Filament\AdminPanelProvider). Como a rota original de cada
// componente vai DENTRO do snapshot assinado, a barreira vale exatamente para
// os componentes do admin e não toca os do painel do usuário.
// =============================================================================

beforeEach(function (): void {
    config()->set('security.admin.allowed_ips', ['10.10.10.10']);
    config()->set('security.admin.allow_any_ip', false);
});

/**
 * Snapshot do componente de perfil do admin, renderizado de DENTRO da
 * allowlist (o caminho legítimo pelo qual um snapshot existe).
 */
function snapshotDoPerfilAdmin(User $admin): string
{
    $html = test()->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
        ->get('/admin/profile')
        ->assertOk()
        ->getContent();

    return livewireSnapshotFrom((string) $html, 'Twstec\\Kit\\Admin\\Pages\\Profile');
}

it('IP fora da allowlist NÃO executa ação de componente do admin pelo endpoint do Livewire', function (): void {
    $admin = User::factory()->create(['is_admin' => true, 'name' => 'Nome Original']);
    $snapshot = snapshotDoPerfilAdmin($admin);

    livewireCall($this, $snapshot, 'save', ['data.name' => 'Alterado de Fora'], ip: '203.0.113.99')
        ->assertForbidden();

    expect($admin->fresh()->name)->toBe('Nome Original');
});

it('a allowlist também vale para a tela de login do admin chamada pelo endpoint do Livewire', function (): void {
    $html = $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
        ->get('/admin/login')
        ->assertOk()
        ->getContent();

    $snapshot = livewireSnapshotFrom((string) $html, 'Auth\\Login');

    // De fora da allowlist nem a tentativa de autenticação é processada.
    livewireCall($this, $snapshot, 'authenticate', ip: '203.0.113.99')->assertForbidden();
});

it('dentro da allowlist a ação do admin continua funcionando pelo endpoint do Livewire', function (): void {
    $admin = User::factory()->create(['is_admin' => true, 'name' => 'Nome Original']);
    $snapshot = snapshotDoPerfilAdmin($admin);

    livewireCall($this, $snapshot, 'save', ['data.name' => 'Novo Nome'], ip: '10.10.10.10')
        ->assertOk();

    expect($admin->fresh()->name)->toBe('Novo Nome');
});

it('admin desativado com sessão viva NÃO executa ação de componente do admin pelo endpoint do Livewire', function (UserStatus $status): void {
    $admin = User::factory()->create(['is_admin' => true, 'name' => 'Nome Original']);
    $snapshot = snapshotDoPerfilAdmin($admin);

    $admin->forceFill(['status' => $status])->save();

    $response = livewireCall($this, $snapshot, 'save', ['data.name' => 'Alterado'], ip: '10.10.10.10');

    // Barrado (a sessão é encerrada e a resposta manda ao login); nunca 200.
    expect($response->getStatusCode())->not->toBe(200);
    expect($admin->fresh()->name)->toBe('Nome Original');
})->with([UserStatus::Blocked, UserStatus::Pending]);

it('a allowlist do admin NÃO afeta componentes do painel do usuário no mesmo endpoint', function (): void {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'projects');

    // IP fora da allowlist do admin: o painel do usuário não tem essa barreira.
    livewireCall($this, $snapshot, 'create', ['name' => 'Projeto via Livewire'], ip: '203.0.113.99')
        ->assertOk();

    expect(comoSistema(fn () => Project::query()->where('account_id', contaPessoal($user)->id)->where('name', 'Projeto via Livewire')->exists()))->toBeTrue();
})->group('accounts');

it('a allowlist do admin NÃO afeta componentes do painel do usuário — no perfil, em qualquer combinação de módulos', function (): void {
    $user = User::factory()->create(['name' => 'Nome antes']);

    $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'profile');

    livewireCall($this, $snapshot, 'updateProfile', ['name' => 'Nome via Livewire'], ip: '203.0.113.99')
        ->assertOk();

    expect($user->fresh()->name)->toBe('Nome via Livewire');
});

it('a barreira de origem está registrada como middleware persistente do Livewire', function (): void {
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())
        ->toContain(EnsureAdminIpAllowed::class);
});
