<?php

declare(strict_types=1);

// =============================================================================
// Pessoas FIXAS do E2E, no banco de DESENVOLVIMENTO.
//
//   docker compose exec -T app php artisan tinker --execute="require 'tests/e2e/fixtures.php';"
//
// Sem a demonstração do kit (o projeto criado), não há conta demo nem login
// pré-preenchido: o E2E usa duas pessoas próprias, criadas (ou devolvidas ao
// estado inicial) por este script — pode rodar quantas vezes quiser. Com a
// demonstração, o /admin do E2E é o super admin demo, e só a primeira pessoa
// é usada.
//
// - e2e@example.com: a pessoa comum do painel (sessão do global-setup).
//   E-mail confirmado, ativa, sem segundo fator, idioma pt_BR, tema do
//   sistema, sem foto e sem senha de transação.
// - admin-e2e@example.com: super admin do /admin (login do global-setup e a
//   limpeza das pessoas que os testes criam). NÃO começa com `e2e-`: esse é o
//   prefixo das pessoas que os testes criam e apagam.
//
// As senhas podem vir do ambiente (E2E_USER_PASSWORD, E2E_ADMIN_PASSWORD),
// as mesmas que o Playwright lê.
// =============================================================================

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Auth\Enums\UserStatus;

$fixture = function (string $email, string $name, string $password, bool $transactionPassword): User {
    $user = User::query()->where('email', $email)->first() ?? User::factory()->create([
        'email' => $email,
        'name' => $name,
    ]);

    $user->forceFill([
        'name' => $name,
        'password' => Hash::make($password),
        'email_verified_at' => $user->email_verified_at ?? now(),
        'status' => UserStatus::Active,
        'locale' => 'pt_BR',
        'theme' => null,
        'two_factor_enabled_at' => null,
        'transaction_password' => $transactionPassword ? Hash::make('Trans4cao!Segura') : null,
        'transaction_password_set_at' => $transactionPassword ? now() : null,
    ]);

    // Sem foto (a coluna existe com o pacote de uploads).
    if (Schema::hasColumn('users', 'avatar_upload_id')) {
        $user->forceFill(['avatar_upload_id' => null]);
    }

    $user->save();

    return $user;
};

$fixture('e2e@example.com', 'Pessoa E2E', (string) (getenv('E2E_USER_PASSWORD') ?: 'E2eSenhaForte123'), false);
$fixture('admin-e2e@example.com', 'Admin E2E', (string) (getenv('E2E_ADMIN_PASSWORD') ?: 'E2eAdminSenha123'), false);

// O /admin é opcional (twstec/kit-admin): sem ele, o admin do E2E é só uma
// pessoa comum, sem uso.
if (array_key_exists('user:make-admin', Artisan::all())) {
    Artisan::call('user:make-admin', ['email' => 'admin-e2e@example.com']);
}

echo 'Pessoas fixas do E2E prontas: e2e@example.com e admin-e2e@example.com'.PHP_EOL;
