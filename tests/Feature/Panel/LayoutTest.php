<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Foundation\Kit;

// =============================================================================
// Layout unificado: o painel do usuário usa o MESMO cabeçalho e o MESMO rodapé
// do site (decisão do dono — quem entra na conta não pode sentir que saiu do
// site), com um menu lateral "Minha conta" à esquerda do conteúdo.
//
// Antes eram três esqueletos (landing, auth, painel) com marcas, larguras e
// rodapés diferentes; a navegação do painel era uma barra horizontal que no
// mobile empilhava sobre a logo e comia 29% da altura da tela.
//
// Este teste trava o contrato: um cabeçalho, um rodapé, uma gaveta, uma lista
// de itens — em todas as superfícies.
// =============================================================================

it('painel, landing, showcase e auth compartilham o MESMO cabeçalho e rodapé', function () {
    config()->set('ui.showcase_enabled', true);

    $user = User::factory()->create();

    $paginas = [
        '/' => null,
        '/ui' => null,
        '/login' => null,
        '/dashboard' => $user,
    ];

    foreach ($paginas as $url => $comoUsuario) {
        $request = $comoUsuario !== null ? $this->actingAs($comoUsuario) : $this;

        $request->get($url)
            ->assertOk()
            // Cabeçalho único: mesma gaveta, mesmo gatilho, mesma marca.
            ->assertSee('data-modal-open="site-menu"', false)
            ->assertSee('id="site-menu"', false)
            ->assertSee(__('ui.nav.open_menu'))
            // Rodapé institucional do site (antes só existia na landing).
            ->assertSee(__('landing.footer.links_heading'))
            ->assertSee(__('ui.footer.operated_by', ['platform' => platform()->name]));
    }
})->group('demo');

it('painel mostra o menu lateral "Minha conta" com o item atual marcado', function () {
    $response = $this->actingAs(User::factory()->create())->get('/projects')->assertOk();

    // Grupos do menu lateral.
    $response
        ->assertSee(__('panel.nav.groups.overview'))
        ->assertSee(__('panel.nav.groups.development'))
        ->assertSee(__('panel.nav.groups.account'))
        ->assertSee('side-nav-item', false);

    // Todos os itens da conta, incluindo a senha de transação (que só existia
    // como rota solta, sem entrada em nenhum menu).
    foreach (['dashboard', 'api_keys', 'projects', 'notifications', 'profile', 'transaction_password'] as $item) {
        $response->assertSee(__('panel.nav.'.$item));
    }

    // Item atual destacado: aria-current no link da tela aberta (nos dois
    // markups — a coluna do desktop e a gaveta do mobile).
    $matches = preg_match_all(
        '/href="'.preg_quote(route('panel.projects'), '/').'"[^>]*aria-current="page"/',
        (string) $response->getContent(),
    );

    expect($matches)->toBe(2);
})->group('accounts');

it('a gaveta do mobile leva o site E o "Minha conta" quando há sessão', function () {
    $user = User::factory()->create();

    // Deslogado: gaveta só com o site e os CTAs de entrada.
    $this->get('/')
        ->assertOk()
        ->assertSee(__('landing.nav.features'))
        ->assertDontSee(__('ui.nav.account'));

    // Logado: a MESMA gaveta ganha a seção da conta com o menu lateral dentro.
    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee(__('ui.nav.account'))
        ->assertSee(__('landing.nav.features'))
        ->assertSee($user->email);
})->group('demo');

it('cada item da conta aparece nos DOIS markups: coluna do desktop e gaveta', function () {
    $response = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();

    // Uma lista (App\Livewire\Support\Navigation), dois markups.
    // Chaves e projetos são telas do pacote de contas (opcional).
    foreach (['dashboard', ...(Kit::has('accounts') ? ['api_keys', 'projects'] : []), 'notifications', 'profile'] as $item) {
        expect(substr_count((string) $response->getContent(), __('panel.nav.'.$item)))
            ->toBeGreaterThanOrEqual(2);
    }
});

it('showcase e painel usam o MESMO componente de menu lateral', function () {
    config()->set('ui.showcase_enabled', true);

    // /ui: barra compacta no mobile + gaveta própria + scrollspy.
    $this->get('/ui')
        ->assertOk()
        ->assertSee('data-modal-open="ui-drawer"', false)
        ->assertSee('data-side-nav-current', false)
        ->assertSee('data-scrollspy', false)
        ->assertSee(__('showcase.groups.foundations'));

    // Painel: mesma classe de item, sem barra compacta (o índice mobile vive
    // na gaveta do cabeçalho — uma gaveta só).
    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertOk()
        ->assertSee('side-nav-item', false)
        ->assertDontSee('data-side-nav-current', false);
})->group('demo');

it('alvos de toque do painel têm no mínimo 44px no mobile', function () {
    // min-h-11 = 2.75rem = 44px (WCAG 2.5.8 / iOS HIG). O <x-button> aplica em
    // todos os tamanhos; sm: pode encolher no desktop, onde o alvo é o mouse.
    $componentes = ['button', 'checkbox', 'toggle', 'dropdown-item'];

    foreach ($componentes as $component) {
        expect((string) file_get_contents(resource_path("views/components/{$component}.blade.php")))
            ->toContain('min-h-11');
    }

    // O item do menu lateral vive no CSS (uma regra serve os dois markups):
    // 44px na gaveta, compacto só a partir de lg, onde o alvo é o ponteiro.
    expect((string) file_get_contents(resource_path('css/app.css')))
        ->toContain('min-height: 2.75rem')
        ->toContain('@media (min-width: 1024px)');
});
