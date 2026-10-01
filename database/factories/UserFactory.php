<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// "password" da factory é propositalmente fraca (velocidade nos testes);
// testes que exercitam as regras de força usam senhas próprias.

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * O model do aplicativo (App\Models\User), declarado explicitamente.
     *
     * @var class-string<User>
     */
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Admin criado pela factory (`create(['is_admin' => true])`) sem papel
     * declarado nasce DONO do /admin — como o `user:make-admin` faz. Para
     * outro papel, passe `admin_role` (ou use admin('support')). Só com o
     * twstec/kit-admin instalado (é ele que cria a coluna).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            if (config()->has('admin.authorization.super_role')
                && $user->getAttribute('is_admin') === true
                && $user->getAttribute('admin_role') === null) {
                $user->forceFill(['admin_role' => (string) config('admin.authorization.super_role')]);
            }
        });
    }

    /**
     * Admin do /admin com o papel dado (padrão: o de dono).
     */
    public function admin(?string $role = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_admin' => true,
            'admin_role' => $role ?? (string) config('admin.authorization.super_role', 'owner'),
        ]);
    }

    /**
     * Usuário com senha de transação definida (hash separado da senha de login).
     */
    public function withTransactionPassword(string $password = 'Trans4cao!Segura'): static
    {
        return $this->state(fn (array $attributes): array => [
            'transaction_password' => Hash::make($password),
            'transaction_password_set_at' => now(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
