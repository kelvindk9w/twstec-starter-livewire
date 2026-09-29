<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// Showcase de componentes (/ui) — kill switch UI_SHOWCASE_ENABLED (config/ui.php).

it('showcase responde 200 quando habilitado e renderiza todas as categorias', function () {
    config()->set('ui.showcase_enabled', true);

    $response = $this->get('/ui');

    $response->assertOk()
        ->assertSee(__('showcase.title'))
        ->assertSee(route('ui.showcase'), false);

    foreach (__('showcase.categories') as $anchor => $label) {
        $response->assertSee($label)->assertSee('id="'.$anchor.'"', false);
    }
});

it('showcase renderiza os componentes reais do kit', function () {
    config()->set('ui.showcase_enabled', true);

    $this->get('/ui')
        ->assertOk()
        ->assertSee(__('showcase.buttons.primary'))
        ->assertSee(__('showcase.alerts.success_title'))
        ->assertSee(__('showcase.forms.with_error_message'))
        ->assertSee(__('showcase.empty_state.title'))
        ->assertSee(__('showcase.modal.title'))
        ->assertSee('data-modal-open', false)
        ->assertSee('animate-spin', false);
});

it('showcase renderiza snippets copiáveis, seletor de tema e scrollspy', function () {
    config()->set('ui.showcase_enabled', true);

    $this->get('/ui')
        ->assertOk()
        ->assertSee('data-copy', false)
        ->assertSee('data-theme-set="dark"', false)
        ->assertSee('data-scrollspy', false)
        ->assertSee('data-toast-show', false)
        ->assertSee('clipboard-toast', false);

    // JS de UI fica em resources/js (Vite). A ÚNICA exceção inline é o script
    // anti-flash de tema no <head> (partials/theme-script) — deliberado,
    // coberto pela CSP base ('unsafe-inline' em script-src, config/security.php).
    $html = $this->get('/ui')->getContent();

    expect(substr_count((string) $html, '<script>'))->toBe(1)
        ->and((string) $html)->toContain('prefers-color-scheme');
});

it('showcase responde 404 quando desabilitado', function () {
    config()->set('ui.showcase_enabled', false);

    $this->get('/ui')->assertNotFound();
});

it('componentes Blade do kit existem e renderizam', function (string $component, string $markup, string $expected) {
    expect(view('components.'.$component)->getPath())->toBeFile();

    $html = Blade::render($markup);

    expect($html)->toContain($expected);
})->with([
    'button' => ['button', '<x-button>OK</x-button>', 'bg-brand'],
    'button outline' => ['button', '<x-button variant="outline">OK</x-button>', 'border-brand'],
    'alert' => ['alert', '<x-alert type="success">OK</x-alert>', 'role="alert"'],
    'badge' => ['badge', '<x-badge color="green">OK</x-badge>', 'rounded-full'],
    'input' => ['input', '<x-input label="Nome" name="nome" />', 'name="nome"'],
    'input password com olho' => ['input', '<x-input label="Senha" name="senha" type="password" />', 'data-password-toggle'],
    'textarea' => ['textarea', '<x-textarea label="Mensagem" name="msg" />', '<textarea'],
    'skeleton' => ['skeleton', '<x-skeleton :lines="2" />', 'skeleton'],
    'loading-overlay' => ['loading-overlay', '<x-loading-overlay message="Aguarde" />', 'data-loading-overlay'],
    // <x-dropdown> é exercitado pelos dois componentes que o consomem
    // (o slot nomeado dentro de Blade::render marca o teste como risky).
    'locale-switcher' => ['locale-switcher', '<x-locale-switcher />', 'data-dropdown-menu'],
    'theme-toggle' => ['theme-toggle', '<x-theme-toggle />', 'data-theme-set'],
    'drawer' => ['drawer', '<x-drawer id="menu" title="Menu">conteudo</x-drawer>', 'drawer-panel'],
    'table' => ['table', '<x-table :headers="[\'Nome\']"><x-table-row><x-table-cell label="Nome">Ana</x-table-cell></x-table-row></x-table>', '<th'],
    'file-input' => ['file-input', '<x-file-input name="foto" />', 'data-file-input'],
    'stat' => ['stat', '<x-stat label="Chaves" value="7" />', 'tabular-nums'],
    'flag' => ['flag', '<x-flag locale="pt_BR" />', '<svg'],
    'select' => ['select', '<x-select label="Plano" name="plano"><option>A</option></x-select>', '<select'],
    'checkbox' => ['checkbox', '<x-checkbox label="Aceito" name="termos" />', 'type="checkbox"'],
    'toggle' => ['toggle', '<x-toggle label="2FA" name="tfa" />', 'peer'],
    'card' => ['card', '<x-card title="T">corpo</x-card>', 'rounded-xl'],
    'modal' => ['modal', '<x-modal id="m" title="T">corpo</x-modal>', 'data-modal'],
    'toast' => ['toast', '<x-toast type="success">OK</x-toast>', 'role="status"'],
    'empty-state' => ['empty-state', '<x-empty-state title="Vazio" />', 'border-dashed'],
    'snippet' => ['snippet', '<x-snippet code="php artisan inspire" />', 'data-copy'],
    'spinner' => ['spinner', '<x-spinner />', 'animate-spin'],
    'ui-icon' => ['ui-icon', '<x-ui-icon name="key" />', '<svg'],
]);

// =============================================================================
// Snippets e componentes novos (tabela, dados e navegação).
// =============================================================================

it('snippets mostram o código INTEIRO (nada de truncate)', function () {
    $snippet = (string) file_get_contents(resource_path('views/components/snippet.blade.php'));

    // Numa página cujo propósito é mostrar código copiável, 100% do código
    // aparecia cortado no meio (`<x-input label="…" name="demo_na`).
    expect($snippet)->toContain('whitespace-pre-wrap')
        ->and($snippet)->toContain('break-all')
        // Sem classes de corte no markup (o comentário do arquivo explica
        // por quê — por isso a busca é pela CLASSE, não pela palavra).
        ->and($snippet)->not->toContain('class="truncate')
        ->and($snippet)->not->toContain(' truncate ')
        ->and($snippet)->not->toContain('whitespace-nowrap');
});

it('showcase documenta os componentes novos do sistema', function () {
    config()->set('ui.showcase_enabled', true);

    $response = $this->get('/ui')->assertOk();

    // Seções na sidebar (scrollspy) e no corpo.
    $response->assertSee(__('showcase.categories.data_display'))
        ->assertSee(__('showcase.categories.navigation'))
        ->assertSee('id="data_display"', false)
        ->assertSee('id="navigation"', false);

    // Snippet copiável de cada componente novo.
    foreach (['<x-table', '<x-stat', '<x-chart', '<x-dropdown', '<x-drawer', '<x-file-input'] as $componente) {
        $response->assertSee($componente);
    }

    // O gráfico com dados renderiza o canvas; o vazio, o estado desenhado.
    $response->assertSee('data-chart="line"', false)
        ->assertSee(__('ui.chart.empty_title'));
});

it('showcase só aponta para imagens que existem em public/', function () {
    config()->set('ui.showcase_enabled', true);

    $html = (string) $this->get('/ui')->assertOk()->getContent();
    $base = rtrim((string) config('app.url'), '/').'/';

    preg_match_all('/<img\b[^>]*\bsrc="([^"]+)"/i', $html, $matches);

    // Só as imagens servidas de public/ (data: URI e hosts externos ficam de fora).
    $local = collect($matches[1])
        ->map(fn (string $src): string => html_entity_decode($src))
        ->filter(fn (string $src): bool => str_starts_with($src, $base) || str_starts_with($src, '/'))
        ->map(fn (string $src): string => ltrim(str_starts_with($src, $base) ? substr($src, strlen($base)) : $src, '/'))
        ->map(fn (string $path): string => strtok($path, '?#'))
        ->unique()
        ->values();

    expect($local)->not->toBeEmpty();

    foreach ($local as $path) {
        expect(public_path($path))->toBeFile("imagem inexistente no showcase: {$path}");
    }
});
