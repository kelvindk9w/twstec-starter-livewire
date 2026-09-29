<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Auth\Enums\UserStatus;

// =============================================================================
// Acesso ao super admin Filament (/admin): SÓ is_admin +
// conta ativa. Demais = 403; guest = redirect ao login do painel. A flag só
// muda pelo comando `user:make-admin`.
// =============================================================================

it('redireciona guest para o login do painel', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('nega com 403 usuário autenticado SEM a flag admin', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('nega com 403 admin com conta bloqueada', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'status' => UserStatus::Blocked,
    ]);

    $this->actingAs($admin)->get('/admin')->assertForbidden();
});

it('permite admin ativo no painel', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('is_admin NUNCA é mass-assignable (só via comando artisan)', function () {
    $user = User::factory()->create();

    // Tentativa de mass assignment via create/fill: a flag é ignorada.
    $user->fill(['is_admin' => true]);

    expect($user->is_admin)->toBeFalse();
});

it('comando user:make-admin promove e revoga (--remove)', function () {
    $user = User::factory()->create(['email' => 'dev@example.com']);

    $this->artisan('user:make-admin', ['email' => 'dev@example.com'])
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();

    $this->artisan('user:make-admin', ['email' => 'dev@example.com', '--remove' => true])
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('comando user:make-admin falha para e-mail inexistente', function () {
    $this->artisan('user:make-admin', ['email' => 'ninguem@example.com'])
        ->assertFailed();
});

it('IP allowlist: quando configurada, IP fora da lista recebe 403', function () {
    config()->set('security.admin.allowed_ips', ['10.10.10.10']);

    $admin = User::factory()->create(['is_admin' => true]);

    // O request de teste sai de 127.0.0.1 — fora da allowlist.
    $this->actingAs($admin)->get('/admin')->assertForbidden();

    // Dentro da allowlist passa.
    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
        ->get('/admin')
        ->assertOk();
});

it('IP allowlist vazia = sem restrição (apenas desenvolvimento)', function () {
    config()->set('security.admin.allowed_ips', []);

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get('/admin')->assertOk();
});
