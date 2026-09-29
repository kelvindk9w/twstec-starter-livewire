<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Demo\Accounts\DemoAccountGuard;

// Proteção das contas demo (login demo + super admin demo): NÃO podem ser
// bloqueadas/desbloqueadas por ações do super admin — senão um visitante
// quebraria a demonstração para todos os demais. A tentativa é recusada
// com uma notification clara.

beforeEach(function () {
    // O modo demo depende do ambiente (DEMO_LOGIN_ENABLED / APP_ENV=local);
    // aqui ele fica LIGADO explicitamente para o teste valer igual no CI e
    // na máquina de quem roda com outro .env.
    config()->set('ui.demo_login.enabled', true);

    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('identifica as contas demo pelas credenciais configuradas', function () {
    $demo = User::factory()->create(['email' => config('ui.demo_login.email')]);
    $demoAdmin = User::factory()->create(['email' => config('ui.demo_admin.email')]);
    $normal = User::factory()->create(['email' => 'pessoa@example.com']);

    expect($demo->isDemo())->toBeTrue()
        ->and($demoAdmin->isDemo())->toBeTrue()
        ->and($normal->isDemo())->toBeFalse();
});

it('recusa bloquear o usuário demo, com notification explicando', function () {
    $demo = User::factory()->create(['email' => config('ui.demo_login.email')]);

    Livewire::test(ListUsers::class)
        ->callTableAction('block', $demo)
        ->assertNotified(__('admin.users.account_protected'));

    expect($demo->fresh()->status)->toBe(UserStatus::Active);
});

it('recusa bloquear o super admin demo', function () {
    $demoAdmin = User::factory()->create(['email' => config('ui.demo_admin.email')]);

    Livewire::test(ListUsers::class)
        ->callTableAction('block', $demoAdmin)
        ->assertNotified(__('admin.users.account_protected'));

    expect($demoAdmin->fresh()->status)->toBe(UserStatus::Active);
});

it('recusa desbloquear conta demo (caminho reverso também protegido)', function () {
    $demo = User::factory()->create(['email' => config('ui.demo_login.email')]);
    // Preparar o cenário exige passar por cima da blindagem do model (é o
    // mesmo caminho que os seeders usam) — o teste é sobre a UI recusar.
    DemoAccountGuard::withoutProtection(fn () => $demo->forceFill(['status' => UserStatus::Blocked])->save());

    Livewire::test(ListUsers::class)
        ->callTableAction('unblock', $demo)
        ->assertNotified(__('admin.users.account_protected'));

    expect($demo->fresh()->status)->toBe(UserStatus::Blocked);
});

it('segue bloqueando usuários normais (a guarda não quebra o fluxo)', function () {
    $user = User::factory()->create(['email' => 'cliente@example.com']);

    Livewire::test(ListUsers::class)
        ->callTableAction('block', $user)
        ->assertNotified(__('admin.users.blocked_success'));

    expect($user->fresh()->status)->toBe(UserStatus::Blocked);
});
