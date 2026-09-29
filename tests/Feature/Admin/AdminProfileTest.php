<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Admin\Pages\Profile;

// Perfil do super admin (demo-safe): nome editável funciona; e-mail é
// read-only com nota; seção de senha montada mas sem endpoint (campo
// desabilitado e não desidratado — nada pode derrubar o acesso demo).

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('abre a página de perfil com nome e e-mail preenchidos', function () {
    Livewire::test(Profile::class)
        ->assertOk()
        ->assertFormSet([
            'name' => $this->admin->name,
            'email' => $this->admin->email,
        ]);
});

it('salva a alteração do nome', function () {
    Livewire::test(Profile::class)
        ->fillForm(['name' => 'Novo Nome Admin'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('admin.profile.saved'));

    expect($this->admin->fresh()->name)->toBe('Novo Nome Admin');
});

it('exige o nome (não aceita vazio)', function () {
    Livewire::test(Profile::class)
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name']);
});

it('e-mail é read-only: não é desidratado nem alterado pelo form', function () {
    $original = $this->admin->email;

    Livewire::test(Profile::class)
        ->set('data.email', 'invadido@example.com')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->admin->fresh()->email)->toBe($original);
});

it('senha é só prévia: campo desabilitado e nada é alterado ao salvar', function () {
    $hashAntes = $this->admin->password;

    Livewire::test(Profile::class)
        ->set('data.current_password', 'qualquer-coisa')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->admin->fresh()->password)->toBe($hashAntes);
});

it('renderiza as notas explicativas (e-mail read-only + senha indisponível)', function () {
    $this->get(Profile::getUrl())
        ->assertOk()
        ->assertSee(__('admin.profile.email_readonly_note'))
        ->assertSee(__('admin.profile.password_note'));
});

it('exige autenticação de admin', function () {
    auth()->logout();

    $this->get(Profile::getUrl())->assertRedirect();
});
