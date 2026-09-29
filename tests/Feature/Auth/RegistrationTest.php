<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Auth\Enums\UserStatus;

// Registro de usuário: senha forte, validação no servidor, dados
// pessoais criptografados em repouso e cookie de sessão seguro.

it('exibe o formulário de registro', function () {
    $this->get('/register')
        ->assertOk()
        ->assertSee(__('auth.ui.register_title'))
        ->assertSee('name="password_confirmation"', escape: false);
});

it('registra um usuário válido, autentica e leva à confirmação do e-mail', function () {
    $response = $this->post('/register', [
        'name' => 'Fulano da Silva',
        'email' => 'fulano@example.com',
        'password' => 'SenhaForte123',
        'password_confirmation' => 'SenhaForte123',
    ]);

    // Verificação de e-mail ligada (padrão): o painel só abre depois do link
    // — ver EmailVerificationTest.
    $response->assertRedirect(route('verification.notice'));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'fulano@example.com')->sole();

    // Identificadores externos: uuid + código público USR-xxxxxx.
    expect($user->uuid)->not->toBeNull()
        ->and($user->codigo_publico)->toStartWith('USR-')
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->transaction_password)->toBeNull();

    // Hash Argon2id — nunca plaintext.
    expect($user->password)->toStartWith('$argon2id$')
        ->and(password_verify('SenhaForte123', $user->password))->toBeTrue();

    // Nome criptografado em repouso: no banco NÃO é legível,
    // mas o cast devolve o valor em claro na aplicação.
    $rawName = DB::table('users')->where('id', $user->id)->value('name');

    expect($rawName)->not->toBe('Fulano da Silva')
        ->and($user->name)->toBe('Fulano da Silva');
});

it('rejeita senha fraca (força mínima via config)', function () {
    $this->post('/register', [
        'name' => 'Fulano',
        'email' => 'fulano@example.com',
        'password' => 'fraca',
        'password_confirmation' => 'fraca',
    ])->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0);
});

it('rejeita confirmação de senha divergente', function () {
    $this->post('/register', [
        'name' => 'Fulano',
        'email' => 'fulano@example.com',
        'password' => 'SenhaForte123',
        'password_confirmation' => 'OutraSenha123',
    ])->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0);
});

it('rejeita e-mail já cadastrado', function () {
    User::factory()->create(['email' => 'fulano@example.com']);

    $this->post('/register', [
        'name' => 'Fulano',
        'email' => 'fulano@example.com',
        'password' => 'SenhaForte123',
        'password_confirmation' => 'SenhaForte123',
    ])->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1);
});

it('redige credenciais de auth no request log da API (LGPD)', function () {
    Route::post('/api/_test/redaction', fn () => response()->json(['ok' => true]));

    $this->postJson('/api/_test/redaction', [
        'password' => 'SenhaForte123',
        'transaction_password' => 'Transacao123',
        'code' => '123456',
    ])->assertOk();

    $payload = json_decode(
        (string) DB::table('request_logs')->where('endpoint', 'api/_test/redaction')->value('payload'),
        true,
    );

    expect($payload['password'])->toBe('[REDACTED]')
        ->and($payload['transaction_password'])->toBe('[REDACTED]')
        ->and($payload['code'])->toBe('[REDACTED]');
});
