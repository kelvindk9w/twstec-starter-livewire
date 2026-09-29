<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Blade;

// =============================================================================
// <x-avatar> e o menu da conta (<x-user-menu>).
//
// O cabeçalho do site é o mesmo para visitante e cliente; o avatar é o único
// elemento que diz "esta é a SUA sessão". Por isso ele NUNCA pode cair num
// boneco genérico: sem foto, mostra as iniciais do nome.
// =============================================================================

it('avatar mostra as iniciais do nome quando não há foto', function () {
    $html = Blade::render('<x-avatar name="Ana Ribeiro" />');

    expect($html)->toContain('>AR<')
        ->and($html)->not->toContain('<img');
});

it('avatar respeita acentos e nome de uma palavra só', function () {
    // mb_substr, não substr: "Ângela" começa com Â, não com meio byte.
    expect(Blade::render('<x-avatar name="Ângela" />'))->toContain('>Â<');

    // Nome vazio nunca quebra o cabeçalho.
    expect(Blade::render('<x-avatar name="" />'))->toContain('>?<');
});

it('avatar usa a foto quando existe e tem os 3 tamanhos', function () {
    expect(Blade::render('<x-avatar src="https://example.test/a.png" name="Ana" />'))
        ->toContain('https://example.test/a.png')
        ->toContain('<img');

    foreach (['sm' => 'h-8 w-8', 'md' => 'h-10 w-10', 'lg' => 'h-16 w-16'] as $size => $classes) {
        expect(Blade::render('<x-avatar name="Ana" size="'.$size.'" />'))->toContain($classes);
    }
});

it('menu da conta traz nome, e-mail, perfil, tema, volta ao site e saída', function () {
    $user = User::factory()->create(['name' => 'Ana Ribeiro']);

    $response = $this->actingAs($user)->get('/dashboard')->assertOk();

    $response
        ->assertSee(__('ui.nav.account_menu'))
        ->assertSee('Ana Ribeiro')
        ->assertSee($user->email)
        ->assertSee(__('panel.nav.profile'))
        // Os 3 estados do tema, com o ✓ ligado ao estado escolhido.
        ->assertSee('data-theme-set="system"', false)
        ->assertSee('data-theme-set="light"', false)
        ->assertSee('data-theme-set="dark"', false)
        ->assertSee('data-theme-check="dark"', false)
        ->assertSee(__('ui.nav.back_to_site'))
        ->assertSee(__('auth.ui.logout'))
        // Iniciais no gatilho (o usuário da fábrica não tem foto).
        ->assertSee('>AR<', false);
});

it('menu da conta não existe para visitante — ali ficam Entrar e Criar conta', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee(__('ui.nav.account_menu'))
        ->assertSee(__('landing.nav.login'))
        ->assertSee(__('landing.nav.register'));
});

it('o showcase documenta o avatar e o menu lateral', function () {
    config()->set('ui.showcase_enabled', true);

    $this->get('/ui')
        ->assertOk()
        ->assertSee(__('showcase.navigation.avatar_heading'))
        ->assertSee(__('showcase.navigation.side_nav_heading'))
        ->assertSee('<x-avatar')
        ->assertSee('<x-side-nav');
})->group('demo');

it('o painel só usa componentes do kit: o avatar do perfil é o do cabeçalho', function () {
    // O perfil desenhava a própria bolinha (bg-brand + 1 letra) enquanto o
    // cabeçalho desenhava outra. Duas verdades sobre a mesma pessoa.
    expect((string) file_get_contents(resource_path('views/livewire/profile.blade.php')))
        ->toContain('<x-avatar')
        ->not->toContain('rounded-full bg-brand');
});
