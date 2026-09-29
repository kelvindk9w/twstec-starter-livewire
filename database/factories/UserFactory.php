<?php

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
