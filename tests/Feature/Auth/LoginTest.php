<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Auth\Enums\UserStatus;

// Login (sessão web): bloqueio por tentativas,
// deny-by-default, sessão regenerada e cookie seguro.

it('exibe o formulário de login', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee(__('auth.ui.login_title'));
});

it('autentica com credenciais válidas e redireciona ao painel', function () {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'LoginForte123',
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('rejeita senha incorreta com mensagem genérica (anti-enumeração)', function () {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'SenhaErrada123',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toBe(__('auth.failed'));
    $this->assertGuest();
});

it('usa a mesma mensagem para e-mail inexistente (anti-enumeração)', function () {
    $this->post('/login', [
        'email' => 'naoexiste@example.com',
        'password' => 'Qualquer123',
    ])->assertSessionHasErrors('email');

    expect(session('errors')->get('email')[0])->toBe(__('auth.failed'));
});

it('bloqueia após N tentativas falhas, inclusive com a senha correta', function () {
    config()->set('security.rate_limit.sensitive', 100);
    config()->set('auth.login.max_attempts', 3);

    $user = User::factory()->create(['password' => 'LoginForte123']);

    for ($i = 0; $i < 3; $i++) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'SenhaErrada123',
        ])->assertSessionHasErrors('email');
    }

    // 4ª tentativa: bloqueada pelo throttle — MESMO com a senha correta.
    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'LoginForte123',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toContain('Muitas tentativas');
    $this->assertGuest();
});

it('libera o login após o período de bloqueio', function () {
    config()->set('security.rate_limit.sensitive', 100);
    config()->set('auth.login.max_attempts', 2);
    config()->set('auth.login.lockout_minutes', 15);

    $user = User::factory()->create(['password' => 'LoginForte123']);

    $this->post('/login', ['email' => $user->email, 'password' => 'Errada123']);
    $this->post('/login', ['email' => $user->email, 'password' => 'Errada123']);
    $this->post('/login', ['email' => $user->email, 'password' => 'LoginForte123']);
    $this->assertGuest();

    $this->travel(16)->minutes();

    $this->post('/login', ['email' => $user->email, 'password' => 'LoginForte123'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('nega login de conta inativa mesmo com credenciais corretas', function () {
    $user = User::factory()->create([
        'password' => 'LoginForte123',
        'status' => UserStatus::Blocked,
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'LoginForte123',
    ])->assertSessionHasErrors('email');

    expect(session('errors')->get('email')[0])->toBe(__('auth.account_inactive'));
    $this->assertGuest();
});

it('regenera o ID da sessão no login (prevenção de fixation)', function () {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $this->get('/login');
    $sessionIdAntes = session()->getId();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'LoginForte123',
    ])->assertRedirect(route('dashboard'));

    expect(session()->getId())->not->toBe($sessionIdAntes);
});

it('define o cookie de sessão com HttpOnly e SameSite', function () {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'LoginForte123',
    ]);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(mb_strtolower((string) $cookie->getSameSite()))->toBe('lax');
});

it('marca o cookie como Secure quando configurado (produção/HTTPS)', function () {
    config()->set('session.secure', true);

    $user = User::factory()->create(['password' => 'LoginForte123']);

    $response = $this->post('https://localhost/login', [
        'email' => $user->email,
        'password' => 'LoginForte123',
    ]);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue();
});

it('redireciona convidado ao login em rota protegida (deny-by-default)', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('honra o redirect intended após o login', function () {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $this->get('/dashboard')->assertRedirect(route('login'));

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'LoginForte123',
    ])->assertRedirect('/dashboard');
});

it('destino intended FORA da APP_URL não é seguido: o pós-login cai no dashboard', function (string $destino) {
    // O `url.intended` é o `fullUrl()` da requisição barrada, montado com o host
    // dela. O pós-login passa pelo SafeRedirect, ancorado na APP_URL: mesmo que
    // um host forjado chegasse a ser gravado, ele não vira destino.
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $this->withSession(['url.intended' => $destino])
        ->post('/login', [
            'email' => $user->email,
            'password' => 'LoginForte123',
        ])->assertRedirect(route('dashboard'));
})->with([
    'outro host' => ['https://evil.example.com/dashboard'],
    'protocolo relativo' => ['//evil.example.com/dashboard'],
]);
