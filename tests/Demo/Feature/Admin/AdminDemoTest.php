<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Twstec\Kit\Demo\Database\Seeders\DemoAdminSeeder;

// Super admin demo (/admin): seeder + pré-preenchimento do login Filament,
// tudo na MESMA flag do login demo (ui.demo_login.enabled — padrão local).
// Em produção, nada disso existe.

it('seeder cria o admin demo autenticável e com acesso ao painel (idempotente)', function () {
    $this->seed(DemoAdminSeeder::class);
    $this->seed(DemoAdminSeeder::class); // firstOrNew: rodar 2x não duplica

    $admin = User::query()->where('email', config('ui.demo_admin.email'))->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and(Auth::validate([
            'email' => config('ui.demo_admin.email'),
            'password' => config('ui.demo_admin.password'),
        ]))->toBeTrue();

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('login do /admin vem pré-preenchido quando o demo está habilitado', function () {
    config()->set('ui.demo_login.enabled', true);

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee((string) config('ui.demo_admin.email'));
});

it('login do /admin NÃO mostra credenciais quando o demo está desabilitado', function () {
    config()->set('ui.demo_login.enabled', false);

    $this->get('/admin/login')
        ->assertOk()
        ->assertDontSee((string) config('ui.demo_admin.email'));
});

it('landing expõe o botão "Ver admin demo" só quando o demo está habilitado', function () {
    config()->set('ui.demo_login.enabled', true);
    $this->get('/')->assertOk()->assertSee(__('landing.hero.cta_admin_demo'));

    config()->set('ui.demo_login.enabled', false);
    $this->get('/')->assertOk()->assertDontSee(__('landing.hero.cta_admin_demo'));
});

it('usuário demo comum continua FORA do /admin (gating inalterado)', function () {
    config()->set('ui.demo_login.enabled', true);

    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});
