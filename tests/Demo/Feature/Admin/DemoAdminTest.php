<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Twstec\Kit\Demo\Database\Seeders\DemoAdminSeeder;

// Super admin demo (/admin): seeder + pré-preenchimento do login do Filament,
// governados pela MESMA flag do login demo (config('ui.demo_login.enabled') —
// padrão só em APP_ENV=local). NUNCA em produção.

it('seeder cria o admin demo autenticável com a flag is_admin (idempotente)', function () {
    $this->seed(DemoAdminSeeder::class);
    $this->seed(DemoAdminSeeder::class); // rodar 2x não duplica nem derruba a flag

    $admin = User::query()->where('email', config('ui.demo_admin.email'))->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and($admin->isActive())->toBeTrue()
        ->and(Auth::validate([
            'email' => config('ui.demo_admin.email'),
            'password' => config('ui.demo_admin.password'),
        ]))->toBeTrue();
});

it('login do /admin vem pré-preenchido quando o demo está habilitado', function () {
    config()->set('ui.demo_login.enabled', true);

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee((string) config('ui.demo_admin.email'), false);
});

it('login do /admin NÃO pré-preenche nada quando o demo está desabilitado', function () {
    config()->set('ui.demo_login.enabled', false);

    $this->get('/admin/login')
        ->assertOk()
        ->assertDontSee((string) config('ui.demo_admin.email'), false);
});

it('admin demo acessa o painel; credenciais demo comuns não viram admin', function () {
    $this->seed(DemoAdminSeeder::class);

    $admin = User::query()->where('email', config('ui.demo_admin.email'))->sole();

    $this->actingAs($admin)->get('/admin')->assertOk();

    // O login demo comum (DemoUserSeeder) NUNCA é admin (backdoor proibida).
    expect(User::factory()->create()->is_admin)->toBeFalse();
});
