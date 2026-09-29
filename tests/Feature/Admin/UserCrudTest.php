<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\CreateUser;
use Twstec\Kit\Admin\Resources\Users\Pages\EditUser;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Auth\Enums\UserStatus;

// =============================================================================
// CRUD completo de usuários no /admin (lacuna apontada pelo QA).
//
// Regras de negócio protegidas no SERVIDOR: contas demo intocáveis, o admin
// não se exclui/bloqueia e o último admin ativo não perde o acesso.
// =============================================================================

beforeEach(function () {
    // Um segundo admin garante que o ator NUNCA é o "último admin ativo",
    // exceto nos testes que exercitam exatamente essa regra.
    $this->outroAdmin = User::factory()->create(['is_admin' => true]);
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('cria usuário com senha, situação e flag de admin', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Ana Nova',
            'email' => 'ana.nova@example.com',
            'password' => 'Senha-Forte123',
            'password_confirmation' => 'Senha-Forte123',
            'status' => UserStatus::Active->value,
            'is_admin' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'ana.nova@example.com')->sole();

    expect($user->name)->toBe('Ana Nova')
        ->and($user->is_admin)->toBeTrue()
        ->and($user->status)->toBe(UserStatus::Active)
        ->and(Hash::check('Senha-Forte123', $user->password))->toBeTrue()
        // A senha NUNCA fica em claro no banco.
        ->and($user->password)->not->toBe('Senha-Forte123');
});

it('exige a política de senha do app na criação', function () {
    // A política é configurável (PasswordPolicy); o admin obedece ao que
    // estiver ligado — aqui, ligamos uma regra para provar que ele cobra.
    config()->set('auth.password_rules.numbers', true);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Fraca',
            'email' => 'fraca@example.com',
            'password' => 'senhafraca',
            'password_confirmation' => 'senhafraca',
            'status' => UserStatus::Active->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(User::query()->where('email', 'fraca@example.com')->exists())->toBeFalse();
});

it('exige que a confirmação de senha confira', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Divergente',
            'email' => 'divergente@example.com',
            'password' => 'Senha-Forte123',
            'password_confirmation' => 'Outra-Senha123',
            'status' => UserStatus::Active->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('recusa e-mail duplicado', function () {
    User::factory()->create(['email' => 'existente@example.com']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Repetido',
            'email' => 'existente@example.com',
            'password' => 'Senha-Forte123',
            'password_confirmation' => 'Senha-Forte123',
            'status' => UserStatus::Active->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['email']);
});

it('edita nome, e-mail e situação sem trocar a senha quando o campo fica vazio', function () {
    $user = User::factory()->create(['email' => 'antes@example.com']);
    $hashAntigo = $user->password;

    Livewire::test(EditUser::class, ['record' => $user->uuid])
        ->fillForm([
            'name' => 'Nome Editado',
            'email' => 'depois@example.com',
            'password' => '',
            'password_confirmation' => '',
            'status' => UserStatus::Blocked->value,
            'is_admin' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->name)->toBe('Nome Editado')
        ->and($user->email)->toBe('depois@example.com')
        ->and($user->status)->toBe(UserStatus::Blocked)
        ->and($user->password)->toBe($hashAntigo);
});

it('troca a senha quando o campo é preenchido na edição', function () {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->uuid])
        ->fillForm([
            'password' => 'Nova-Senha456',
            'password_confirmation' => 'Nova-Senha456',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('Nova-Senha456', $user->fresh()->password))->toBeTrue();
});

it('exclui usuário pela ação da tabela', function () {
    $user = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callTableAction('delete', $user);

    expect(User::query()->whereKey($user->getKey())->exists())->toBeFalse();
});

// --- Guardas -----------------------------------------------------------------

it('não oferece editar nem excluir para conta demo', function () {
    config()->set('ui.demo_login.email', 'demo@tws.dev');
    $demo = User::factory()->create(['email' => 'demo@tws.dev']);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('edit', $demo)
        ->assertTableActionHidden('delete', $demo);
})->group('demo');

it('recusa gravar edição de conta demo mesmo com a rota forçada', function () {
    config()->set('ui.demo_login.email', 'demo@tws.dev');
    $demo = User::factory()->create(['email' => 'demo@tws.dev', 'name' => 'Demo Original']);

    Livewire::test(EditUser::class, ['record' => $demo->uuid])
        ->fillForm(['name' => 'Sequestrado', 'status' => UserStatus::Blocked->value])
        ->call('save');

    expect($demo->fresh()->name)->toBe('Demo Original')
        ->and($demo->fresh()->status)->toBe(UserStatus::Active);
})->group('demo');

it('o admin não exclui a própria conta', function () {
    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('delete', $this->admin);

    expect(User::query()->whereKey($this->admin->getKey())->exists())->toBeTrue();
});

it('o admin não bloqueia a própria conta', function () {
    Livewire::test(ListUsers::class)
        ->callTableAction('block', $this->admin);

    expect($this->admin->fresh()->status)->toBe(UserStatus::Active);
});

it('não remove a flag do último admin ativo', function () {
    // Sobra apenas UM admin ativo: o ator.
    $this->outroAdmin->forceFill(['is_admin' => false])->save();

    Livewire::test(EditUser::class, ['record' => $this->admin->uuid])
        ->fillForm(['is_admin' => false, 'status' => UserStatus::Active->value])
        ->call('save');

    expect($this->admin->fresh()->is_admin)->toBeTrue();
});

it('não bloqueia nem exclui o último admin ativo', function () {
    $this->outroAdmin->forceFill(['is_admin' => false])->save();

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('delete', $this->admin)
        ->callTableAction('block', $this->admin);

    expect($this->admin->fresh()->status)->toBe(UserStatus::Active)
        ->and(User::query()->whereKey($this->admin->getKey())->exists())->toBeTrue();
});

it('o comando user:make-admin continua funcionando', function () {
    $user = User::factory()->create(['email' => 'promovido@example.com']);

    $this->artisan('user:make-admin', ['email' => 'promovido@example.com'])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();

    $this->artisan('user:make-admin', ['email' => 'promovido@example.com', '--remove' => true])
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeFalse();
});
