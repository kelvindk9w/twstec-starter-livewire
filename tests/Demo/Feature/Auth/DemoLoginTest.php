<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Twstec\Kit\Demo\Database\Seeders\DemoUserSeeder;

// Login demo (fricção zero em dev) — só quando config('ui.demo_login.enabled'),
// cujo padrão é APP_ENV=local. Em produção, nada disso existe.

it('tela de login mostra credenciais demo pré-preenchidas quando habilitado', function () {
    config()->set('ui.demo_login.enabled', true);

    $this->get('/login')
        ->assertOk()
        ->assertSee(__('auth.ui.demo_notice'))
        ->assertSee((string) config('ui.demo_login.email'))
        ->assertSee('value="'.config('ui.demo_login.email').'"', false);
});

it('tela de login NÃO mostra nada de demo quando desabilitado (produção)', function () {
    config()->set('ui.demo_login.enabled', false);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee(__('auth.ui.demo_notice'))
        ->assertDontSee((string) config('ui.demo_login.email'));
});

it('seeder cria o usuário demo autenticável (idempotente)', function () {
    $this->seed(DemoUserSeeder::class);
    $this->seed(DemoUserSeeder::class); // updateOrCreate: rodar 2x não duplica

    expect(Auth::validate([
        'email' => config('ui.demo_login.email'),
        'password' => config('ui.demo_login.password'),
    ]))->toBeTrue();
});

it('por padrão o login demo só é habilitado em ambiente local', function () {
    // Contrato do config (sem mexer no env do teste): os defaults derivam de APP_ENV.
    $source = file_get_contents(base_path('vendor/twstec/kit-demo/config/ui.php'));

    expect($source)->toContain("env('APP_ENV') === 'local'")
        ->toContain("env('DEMO_LOGIN_ENABLED'")
        ->toContain("env('UI_SHOWCASE_ENABLED'");
});
