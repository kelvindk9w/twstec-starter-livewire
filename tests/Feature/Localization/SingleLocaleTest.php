<?php

declare(strict_types=1);

use App\Livewire\Profile;
use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Foundation\Support\Platform;

// =============================================================================
// PROJETO DE UM IDIOMA SÓ: com PLATFORM_AVAILABLE_LOCALES de um idioma,
// nenhum seletor aparece (site, telas de entrada, painel, perfil, topbar do
// /admin, galeria de e-mails) e a troca para outro idioma responde 404. Com
// mais de um, os mesmos lugares mostram o seletor — o caso de controle prova
// que o teste enxerga o seletor quando ele existe.
// =============================================================================

/**
 * @param  list<string>  $locales
 */
function useLocales(array $locales): void
{
    config(['platform.available_locales' => $locales, 'mail.preview.enabled' => true, 'ui.demo_login.enabled' => true]);
    app()->forgetInstance(Platform::class);
}

/**
 * O HTML tem um seletor de idioma — mesmo um de uma opção só? (o link de
 * troca para o idioma padrão, o rótulo dos idiomas da galeria ou o campo de
 * idioma do perfil)
 */
function hasLocaleChoice(string $html): bool
{
    return str_contains($html, route('locale.switch', 'pt_BR'))
        || str_contains($html, e(__('mail.preview.language')))
        || str_contains($html, 'wire:model="locale"');
}

dataset('locale surfaces', [
    'página inicial' => [fn () => (string) test()->view('home'), false],
    'entrada' => [fn () => test()->get('/login')->assertOk()->getContent(), false],
    'cadastro' => [fn () => test()->get('/register')->assertOk()->getContent(), false],
    'galeria de e-mails' => [fn () => test()->get('/mail-preview')->assertOk()->getContent(), false],
    'painel' => [fn () => test()->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent(), true],
    'perfil' => [fn () => test()->actingAs(User::factory()->create())->get('/profile')->assertOk()->getContent(), true],
]);

it('com um idioma só, nenhum seletor de idioma', function (Closure $render): void {
    useLocales(['pt_BR']);

    expect(hasLocaleChoice($render()))->toBeFalse();
})->with('locale surfaces');

it('com mais de um idioma, o seletor aparece nos mesmos lugares', function (Closure $render): void {
    useLocales(['pt_BR', 'en']);

    expect(hasLocaleChoice($render()))->toBeTrue();
})->with('locale surfaces');

it('com um idioma só, trocar para outro responde 404', function (): void {
    useLocales(['pt_BR']);

    $this->get('/locale/en')->assertNotFound();
    $this->get('/locale/pt_BR')->assertRedirect();
});

it('com um idioma só, o perfil salva sem o campo de idioma', function (): void {
    useLocales(['pt_BR']);
    $user = User::factory()->create(['locale' => 'pt_BR']);

    Livewire::actingAs($user)->test(Profile::class)
        ->set('name', 'Nome Novo')
        ->call('updateProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Nome Novo');
});

it('com um idioma só, a topbar do /admin não tem seletor', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    useLocales(['pt_BR']);
    expect($this->get('/admin')->assertOk()->getContent())->not->toContain('tws-locale');

    useLocales(['pt_BR', 'en']);
    expect($this->get('/admin')->assertOk()->getContent())->toContain('tws-locale');
})->group('admin');
