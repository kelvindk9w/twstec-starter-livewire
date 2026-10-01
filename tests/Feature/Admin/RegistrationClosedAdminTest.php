<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\CreateUser;
use Twstec\Kit\Auth\Enums\UserStatus;

// Cadastro público FECHADO (AUTH_REGISTRATION_ENABLED=false — issue #22): o
// /admin continua criando pessoas (o interruptor fecha só a porta pública).

it('com o cadastro fechado, o /admin cria a conta', function (): void {
    config()->set('auth.registration.enabled', false);

    User::factory()->create(['is_admin' => true]);
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Criada Pelo Admin',
            'email' => 'criada.admin@example.com',
            'password' => 'Senha-Forte123',
            'password_confirmation' => 'Senha-Forte123',
            'status' => UserStatus::Active->value,
            'is_admin' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'criada.admin@example.com')->sole()->isActive())->toBeTrue();
});
