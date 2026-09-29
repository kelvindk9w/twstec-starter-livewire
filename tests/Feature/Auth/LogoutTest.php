<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

// Logout e proteção CSRF (painel web usa sessão/cookie).
// Laravel 13: o grupo web usa PreventRequestForgery (token + validação de
// origem via Sec-Fetch-Site/Origin).

// Dublê de teste: reativa a verificação CSRF, que o framework desliga por
// padrão em ambiente de teste (PreventRequestForgery::runningUnitTests()).
class EnforcedCsrfToken extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

it('encerra a sessão no logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->post('/logout');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

it('exige autenticação para o logout', function () {
    $this->post('/logout')->assertRedirect(route('login'));
});

it('rejeita POST sem token CSRF no grupo web', function () {
    $this->app->bind(PreventRequestForgery::class, EnforcedCsrfToken::class);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertStatus(419);
});

it('aceita POST com token CSRF válido', function () {
    $this->app->bind(PreventRequestForgery::class, EnforcedCsrfToken::class);

    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $this->post('/logout', ['_token' => csrf_token()])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
