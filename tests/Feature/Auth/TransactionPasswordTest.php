<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Senha de transação: hash SEPARADO da senha de login,
// regras fortes via config, nunca igual à senha de login.

it('exige autenticação para definir a senha de transação (deny-by-default)', function () {
    $this->putJson('/settings/transaction-password', [
        'transaction_password' => 'Transacao123',
        'transaction_password_confirmation' => 'Transacao123',
    ])->assertUnauthorized();
});

it('define a senha de transação na primeira vez', function () {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $response = $this->actingAs($user)->put('/settings/transaction-password', [
        'transaction_password' => 'Transacao123',
        'transaction_password_confirmation' => 'Transacao123',
    ]);

    $response->assertSessionHas('status', __('auth.transaction_password.saved'));

    $user->refresh();

    // Hash SEPARADO e Argon2id — nunca plaintext.
    expect($user->hasTransactionPassword())->toBeTrue()
        ->and($user->transaction_password)->toStartWith('$argon2id$')
        ->and($user->transaction_password)->not->toBe($user->password)
        ->and(Hash::check('Transacao123', $user->transaction_password))->toBeTrue()
        ->and($user->transaction_password_set_at)->not->toBeNull();
});

it('rejeita senha de transação igual à senha de login', function () {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $this->actingAs($user)->put('/settings/transaction-password', [
        'transaction_password' => 'LoginForte123',
        'transaction_password_confirmation' => 'LoginForte123',
    ])->assertSessionHasErrors('transaction_password');

    expect(session('errors')->get('transaction_password')[0])
        ->toBe(__('auth.transaction_password.same_as_login'));

    expect($user->fresh()->hasTransactionPassword())->toBeFalse();
});

it('rejeita senha de transação fraca', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/settings/transaction-password', [
        'transaction_password' => 'fraca',
        'transaction_password_confirmation' => 'fraca',
    ])->assertSessionHasErrors('transaction_password');

    expect($user->fresh()->hasTransactionPassword())->toBeFalse();
});

it('exige a senha de transação atual para alterá-la', function () {
    $user = User::factory()->withTransactionPassword()->create();

    // Sem a atual → campo obrigatório.
    $this->actingAs($user)->put('/settings/transaction-password', [
        'transaction_password' => 'NovaTransacao456',
        'transaction_password_confirmation' => 'NovaTransacao456',
    ])->assertSessionHasErrors('current_transaction_password');

    // Atual incorreta → erro específico.
    $this->actingAs($user)->put('/settings/transaction-password', [
        'current_transaction_password' => 'Errada999',
        'transaction_password' => 'NovaTransacao456',
        'transaction_password_confirmation' => 'NovaTransacao456',
    ])->assertSessionHasErrors('current_transaction_password');

    expect(Hash::check('NovaTransacao456', $user->fresh()->transaction_password))->toBeFalse();
});

it('altera a senha de transação com a atual correta', function () {
    $user = User::factory()->withTransactionPassword()->create();

    $this->actingAs($user)->put('/settings/transaction-password', [
        'current_transaction_password' => 'Trans4cao!Segura',
        'transaction_password' => 'NovaTransacao456',
        'transaction_password_confirmation' => 'NovaTransacao456',
    ])->assertSessionHas('status', __('auth.transaction_password.saved'));

    expect(Hash::check('NovaTransacao456', $user->fresh()->transaction_password))->toBeTrue();
});
