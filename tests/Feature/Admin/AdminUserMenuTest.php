<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Admin\Support\InitialsAvatarProvider;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// Menu do usuário do /admin: avatar (foto ou iniciais), perfil,
// alternador de tema claro/escuro/sistema, voltar ao site e sair.
//
// O seletor de IDIOMA continua na topbar, fora deste menu (decisão do dono) —
// o último teste segura essa decisão.
// =============================================================================

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Ana Lima']);
    $this->actingAs($this->admin);
});

it('o menu do usuário traz perfil, voltar ao site e sair', function () {
    $this->get('/admin')
        ->assertOk()
        ->assertSee(__('admin.profile.heading'))
        ->assertSee(Profile::getUrl(), false)
        ->assertSee(__('admin.menu.back_to_site'))
        ->assertSee(__('filament-panels::layout.actions.logout.label'));
});

it('o alternador de tema claro/escuro/sistema está disponível no painel', function () {
    expect(Filament::getPanel('admin')->hasDarkMode())->toBeTrue()
        ->and(Filament::getPanel('admin')->hasDarkModeForced())->toBeFalse()
        ->and(Filament::getPanel('admin')->hasThemeSwitcher())->toBeTrue();

    // Os três botões do alternador (claro/escuro/sistema) chegam na página.
    $this->get('/admin')
        ->assertOk()
        ->assertSee('fi-theme-switcher', false);
});

it('sem foto de perfil o avatar são as INICIAIS, desenhadas localmente (sem CDN)', function () {
    $avatar = Filament::getUserAvatarUrl($this->admin);

    expect($avatar)
        ->toStartWith('data:image/svg+xml;base64,')
        ->not->toContain('ui-avatars.com');

    expect(base64_decode(str_replace('data:image/svg+xml;base64,', '', $avatar)))
        ->toContain('AL'); // Ana Lima
});

it('com foto de perfil o avatar é a foto do usuário', function () {
    // A foto é PESSOAL (da pessoa, sem conta), enviada por ela.
    $upload = Upload::query()->create([
        'personal' => true,
        'created_by' => $this->admin->id,
        'disk' => 'local',
        'path' => 'avatars/ana.png',
        'original_name' => 'ana.png',
        'mime' => 'image/png',
        'size' => 1024,
        'sha256' => hash('sha256', 'ana'),
    ]);

    $this->admin->forceFill(['avatar_upload_id' => $upload->id])->save();

    expect(Filament::getUserAvatarUrl($this->admin->fresh()))
        ->toBe($this->admin->fresh()->avatarUrl())
        ->not->toStartWith('data:image/svg+xml');
})->group('uploads');

it('as iniciais são a primeira e a última palavra do nome, com queda para "?"', function () {
    expect(InitialsAvatarProvider::initials('Ana Lima'))->toBe('AL')
        ->and(InitialsAvatarProvider::initials('Ana Beatriz Souza Lima'))->toBe('AL')
        ->and(InitialsAvatarProvider::initials('Ana'))->toBe('A')
        ->and(InitialsAvatarProvider::initials('   '))->toBe('?');
});

it('o seletor de idioma segue na topbar, e não dentro do menu do usuário', function () {
    $this->get('/admin')
        ->assertOk()
        ->assertSee('tws-locale', false)
        ->assertSee(__('ui.locale.label'));
});
