<?php

declare(strict_types=1);

use App\Models\User;

// Tema claro/escuro/sistema: script sem flash, toggle de 3 estados e
// persistência na conta (users.theme).

it('layouts públicos e do painel carregam o script anti-flash e o default do tema', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('data-theme-default="system"', false)
        ->assertSee('prefers-color-scheme', false);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('prefers-color-scheme', false)
        ->assertSee('data-authenticated', false)
        ->assertSee('csrf-token', false);
});

it('data-theme-default reflete a preferência salva na conta', function () {
    $user = User::factory()->create(['theme' => 'dark']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-theme-default="dark"', false);
});

// O seletor de tema é um DROPDOWN com os 3 estados nomeados (era um ícone que
// ciclava às cegas): o contrato verificado é o item de cada estado, com rótulo.
it('seletor de tema com os 3 estados nomeados aparece na landing, showcase e painel', function () {
    foreach (['system', 'light', 'dark'] as $state) {
        $this->get('/')->assertOk()->assertSee('data-theme-set="'.$state.'"', false);
    }

    $this->get('/')->assertOk()->assertSee(__('ui.theme.dark'));

    config()->set('ui.showcase_enabled', true);
    $this->get('/ui')->assertOk()->assertSee('data-theme-set="dark"', false);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-theme-set="dark"', false)
        ->assertSee(__('ui.theme.system'));
})->group('demo');

it('perfil exibe o segmented control de aparência', function () {
    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk()
        ->assertSee(__('panel.profile.theme_heading'))
        ->assertSee('data-theme-set="system"', false)
        ->assertSee('data-theme-set="light"', false)
        ->assertSee('data-theme-set="dark"', false);
});

it('rota de preferência de tema persiste na conta', function (string $theme) {
    $user = User::factory()->create(['theme' => null]);

    $this->actingAs($user)
        ->postJson(route('settings.theme'), ['theme' => $theme])
        ->assertOk()
        ->assertJson(['theme' => $theme]);

    expect($user->fresh()->theme)->toBe($theme);
})->with(['light', 'dark', 'system']);

it('rota de preferência de tema rejeita valores inválidos', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.theme'), ['theme' => 'neon'])
        ->assertUnprocessable();
});

it('rota de preferência de tema exige login', function () {
    $this->postJson(route('settings.theme'), ['theme' => 'dark'])
        ->assertUnauthorized();
});

// =============================================================================
// Cores de ESTADO (o toggle ligado).
//
// O <x-toggle> pintava o trilho ligado com a cor da MARCA. Como a marca do kit
// é monocromática — quase-BRANCA no tema escuro —, o estado ligado virava um
// trilho branco com um knob branco em cima: indistinguível do desligado. Cor
// de estado é verde de sucesso; cor de marca é outra conversa.
// =============================================================================

it('o toggle ligado usa cor de ESTADO, nunca a cor da marca', function () {
    $toggle = (string) file_get_contents(resource_path('views/components/toggle.blade.php'));

    expect($toggle)->toContain('peer-checked:bg-success')
        ->and($toggle)->not->toContain('peer-checked:bg-brand')
        // Desligado: cinza com contraste suficiente, o mesmo token nos 2 temas.
        ->and($toggle)->toContain('bg-switch-off')
        ->and($toggle)->not->toContain('dark:bg-gray-600')
        // Knob sempre branco (um knob preto lê como desligado em iOS/Android).
        ->and($toggle)->toContain('after:bg-white');
});

it('os tokens de sucesso existem nos DOIS temas', function () {
    $theme = (string) file_get_contents(resource_path('css/theme.css'));

    // Bloco @theme (tema claro) e bloco .dark: cada um define o seu verde —
    // green-600 sobre branco e green-500 sobre gray-900 passam em 3:1.
    expect(substr_count($theme, '--color-success:'))->toBe(2)
        ->and(substr_count($theme, '--color-switch-off:'))->toBe(2)
        ->and($theme)->toContain('--color-success-foreground:');
});

it('o showcase mostra o toggle nos dois estados', function () {
    config()->set('ui.showcase_enabled', true);

    $this->get('/ui')->assertOk()->assertSee('peer-checked:bg-success', false);
})->group('demo');
