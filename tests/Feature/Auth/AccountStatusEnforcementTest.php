<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Foundation\Kit;

// =============================================================================
// STATUS DA CONTA VALE A CADA REQUISIÇÃO, NÃO SÓ NO LOGIN
//
// O achado: o login já recusava conta não ativa, mas era a ÚNICA verificação
// do lado web. Uma conta bloqueada DEPOIS de logada seguia com a sessão
// intacta: navegava pelo painel, criava projetos, trocava senha de transação —
// o bloqueio só "pegava" quando a sessão expirasse ou a pessoa saísse sozinha.
// (O /admin já barrava pelo canAccessPanel; a API já barrava pelo
// ResolveTenant, que confere o dono da chave a cada chamada.)
//
// A regra agora (Twstec\Kit\Auth\Http\Middleware\EnsureAccountIsActive, no
// grupo `web`): conta autenticada que não está ATIVA tem a sessão encerrada na
// próxima requisição e vai ao login com a mesma mensagem traduzida que o login
// daria. Vale para página, formulário e ação Livewire (o endpoint de
// atualização do Livewire está no grupo `web`).
//
// PENDING recebe o mesmo tratamento que BLOCKED: deny-by-default — só Active
// opera, exatamente como o login e a API já decidiam.
// =============================================================================

/**
 * @return array<string, array{0: UserStatus}>
 */
function statusesInativos(): array
{
    return [
        'bloqueada' => [UserStatus::Blocked],
        'pendente' => [UserStatus::Pending],
    ];
}

it('conta desativada com sessão aberta perde as páginas do painel na próxima requisição', function (string $url, UserStatus $status): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get($url)->assertOk();

    $user->forceFill(['status' => $status])->save();

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.account_inactive')]);

    $this->assertGuest();
})->with([
    'dashboard' => '/dashboard',
    // Telas do pacote de contas (twstec/kit-accounts, opcional): só com ele.
    ...(Kit::has('accounts') ? ['chaves de API' => '/api-keys', 'projetos' => '/projects'] : []),
    'perfil' => '/profile',
    'notificações' => '/notifications',
    'senha de transação' => '/settings/transaction-password',
])->with(statusesInativos());

it('a sessão encerrada não volta: a requisição seguinte já é de visitante', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $user->forceFill(['status' => UserStatus::Blocked])->save();

    $this->get('/dashboard')->assertRedirect(route('login'));

    // Sem o actingAs: o que conta é o que sobrou na sessão.
    Auth::forgetGuards();
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('conta desativada não altera a senha de transação por formulário', function (UserStatus $status): void {
    $user = User::factory()->create();
    $user->forceFill(['status' => $status])->save();

    $this->actingAs($user)
        ->put('/settings/transaction-password', [
            'transaction_password' => 'Nova4Senha9Tx',
            'transaction_password_confirmation' => 'Nova4Senha9Tx',
        ])
        ->assertRedirect(route('login'));

    expect($user->fresh()->transaction_password)->toBeNull();
    $this->assertGuest();
})->with(statusesInativos());

it('conta desativada não executa ação Livewire do painel pelo endpoint real', function (UserStatus $status): void {
    $user = User::factory()->create();

    // Snapshot obtido enquanto a conta estava ativa (a aba que ficou aberta).
    $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'projects');

    $user->forceFill(['status' => $status])->save();

    livewireCall($this, $snapshot, 'create', ['name' => 'Projeto após bloqueio'])
        ->assertRedirect(route('login'));

    expect(comoSistema(fn () => Project::query()->where('account_id', contaPessoal($user)->id)->exists()))->toBeFalse();
    $this->assertGuest();
})->with(statusesInativos())->group('accounts');

it('conta desativada não executa ação Livewire do perfil pelo endpoint real (em qualquer combinação de módulos)', function (UserStatus $status): void {
    $user = User::factory()->create(['name' => 'Nome antes']);

    // A mesma prova da ação de projetos, numa tela que existe sem nenhum
    // módulo opcional instalado.
    $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'profile');

    $user->forceFill(['status' => $status])->save();

    livewireCall($this, $snapshot, 'updateProfile', ['name' => 'Nome depois do bloqueio'])
        ->assertRedirect(route('login'));

    expect($user->fresh()->name)->toBe('Nome antes');
    $this->assertGuest();
})->with(statusesInativos());

it('conta ativa continua operando o painel, inclusive a ação Livewire', function (): void {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'projects');

    livewireCall($this, $snapshot, 'create', ['name' => 'Projeto ativo'])->assertOk();

    expect(comoSistema(fn () => Project::query()->where('account_id', contaPessoal($user)->id)->where('name', 'Projeto ativo')->exists()))->toBeTrue();
    $this->assertAuthenticatedAs($user);
})->group('accounts');

it('a mensagem do encerramento sai no idioma da conta', function (): void {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $user->forceFill(['status' => UserStatus::Blocked])->save();

    $this->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.account_inactive', [], 'en')]);
});

it('a mensagem sobrevive ao redirecionamento seguido pelo cliente do Livewire', function (): void {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'projects');

    $user->forceFill(['status' => UserStatus::Blocked])->save();

    livewireCall($this, $snapshot, 'create', ['name' => 'X'])->assertRedirect(route('login'));

    // O fetch do Livewire segue o 302 sozinho (com o mesmo header) antes de o
    // navegador ir ao login; é a navegação seguinte que precisa da mensagem.
    $this->withHeaders(['X-Livewire' => '1'])->get(route('login'))->assertOk();
    $this->flushHeaders()->get(route('login'))
        ->assertOk()
        ->assertSee(__('auth.account_inactive'));
})->group('accounts');

it('visitante e páginas públicas seguem intactos', function (): void {
    $this->get('/')->assertOk();
    $this->get(route('login'))->assertOk();
    $this->assertGuest();
});

it('chamada web que espera JSON recebe 401 com a mensagem, e a sessão também é encerrada', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['status' => UserStatus::Blocked])->save();

    $this->actingAs($user)
        ->getJson('/dashboard')
        ->assertUnauthorized()
        ->assertJsonPath('message', __('auth.account_inactive'));

    $this->assertGuest();
});

it('chave de API emitida antes do bloqueio para de funcionar na chamada seguinte', function (UserStatus $status): void {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $this->getJson('/api/v1/projects', headersApi($key, $secret))->assertOk();

    $user->forceFill(['status' => $status])->save();

    // 401 no envelope padrão da API, sem dizer o motivo (não oracular).
    $this->getJson('/api/v1/projects', headersApi($key, $secret))
        ->assertUnauthorized()
        ->assertJsonPath('error.message', __('api_keys.auth.invalid'));
})->with(statusesInativos())->group('accounts');
