<?php

declare(strict_types=1);

// =============================================================================
// TESTE DE ARQUITETURA DO DESIGN SYSTEM
//
// O kit vende um design system documentado em /ui. O maior risco não é que ele
// esteja errado — é que o próprio produto deixe de usá-lo: alguém com pressa
// escreve `class="rounded-lg bg-brand px-4 py-2"` em vez de <x-button>, e a
// tela perde o foco, o :active, o estado desabilitado e a transição que o
// componente já resolve. Duas telas depois, o produto tem três botões
// diferentes para a mesma coisa.
//
// Este teste é a trava: as views do painel (resources/views/livewire/**) NÃO
// PODEM conter as classes utilitárias que reimplementam um componente do kit.
// Se faltar uma variante, ESTENDA o componente — não escreva Tailwind cru.
//
// Como estender a lista: acrescente um par [padrão => componente que resolve]
// em FORBIDDEN_PATTERNS. Como abrir exceção: não abra. Crie a variante.
// =============================================================================

use Symfony\Component\Finder\Finder;

/**
 * Padrão proibido => componente do kit que resolve o caso.
 *
 * Os padrões são expressões regulares aplicadas ao Blade cru das views do
 * painel. Cada um representa um componente reimplementado à mão.
 */
const FORBIDDEN_PATTERNS = [
    // Botões escritos à mão (primário / secundário / destrutivo).
    '/bg-brand\s+px-\d/' => '<x-button>',
    '/rounded-lg\s+bg-brand/' => '<x-button>',
    '/border\s+border-gray-300\s+px-\d/' => '<x-button variant="secondary">',
    '/bg-red-600\s+px-\d/' => '<x-button variant="danger">',
    '/border-red-300/' => '<x-button variant="danger">',

    // Modal escrito à mão (o do kit tem Esc, backdrop, foco preso e animação).
    '/fixed\s+inset-0\s+z-\d/' => '<x-modal> ou <x-drawer>',
    '/bg-black\/50/' => '<x-modal>',

    // Estado vazio e alerta escritos à mão.
    '/border-dashed/' => '<x-empty-state>',
    '/bg-green-50/' => '<x-alert type="success">',

    // Superfícies escritas com a escala do Tailwind em vez dos tokens
    // semânticos (é o que faz a elevação inverter no tema escuro).
    '/bg-white\s+(?:[a-z0-9:_\/\[\]-]+\s+)*dark:bg-gray-\d00/' => 'bg-surface (theme.css)',
    '/dark:bg-gray-900(?![\/\d])/' => 'bg-surface (theme.css)',
    '/border-gray-200\s+.*dark:border-gray-800/' => 'border-border (theme.css)',
];

/**
 * Palavras reservadas do JavaScript. O Livewire 4 em modo CSP-safe compila a
 * expressão de wire:click/wire:submit com um parser de JS — uma ação chamada
 * `delete` estoura "Expected IDENTIFIER but got KEYWORD" e a ação NUNCA roda,
 * silenciosamente (foi exatamente o bug de "excluir projeto").
 */
const JS_RESERVED_WORDS = [
    'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger',
    'default', 'delete', 'do', 'else', 'enum', 'export', 'extends', 'false',
    'finally', 'for', 'function', 'if', 'import', 'in', 'instanceof', 'new',
    'null', 'return', 'super', 'switch', 'this', 'throw', 'true', 'try',
    'typeof', 'var', 'void', 'while', 'with', 'yield',
];

/**
 * @return array<string, string> caminho relativo => conteúdo
 */
function bladeViews(string $directory): array
{
    $files = [];

    foreach (Finder::create()->files()->in(base_path($directory))->name('*.blade.php') as $file) {
        $files[$directory.'/'.$file->getRelativePathname()] = (string) file_get_contents($file->getRealPath());
    }

    return $files;
}

it('painel usa o design system do kit, nunca Tailwind cru de componente', function () {
    $violations = [];

    // As TELAS do painel e o ESQUELETO que as embrulha. O layout entrou na
    // varredura quando virou o cabeçalho/rodapé de todo o produto: o lugar
    // onde uma superfície escrita à mão contamina landing, /ui, auth e painel
    // de uma vez é justamente esse.
    $diretorios = ['resources/views/livewire', 'resources/views/layouts'];

    foreach ($diretorios as $diretorio) {
        foreach (bladeViews($diretorio) as $path => $contents) {
            foreach (FORBIDDEN_PATTERNS as $pattern => $component) {
                if (preg_match($pattern, $contents, $matches) === 1) {
                    $violations[] = sprintf('%s: "%s" — use %s', $path, trim($matches[0]), $component);
                }
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('o esqueleto do site é montado com componentes do kit', function () {
    // Um cabeçalho copiado é um cabeçalho que diverge. Landing, showcase,
    // auth e painel passam pelo MESMO <x-layouts.site>, que por sua vez só
    // compõe componentes do kit. (O apelido público da demonstração,
    // <x-layouts.landing>, é conferido do lado dela: tests/Demo, "o layout
    // público da demo…".)
    $site = (string) file_get_contents(resource_path('views/components/layouts/site.blade.php'));

    expect($site)->toContain('<x-site-header')
        ->and($site)->toContain('<x-site-footer')
        ->and($site)->toContain('<x-flash-toast');

    foreach (['layouts/app', 'layouts/auth'] as $layout) {
        expect((string) file_get_contents(resource_path("views/{$layout}.blade.php")))
            ->toContain('<x-layouts.site');
    }

    // Nenhum layout escreve o próprio <header>/<footer> de site. (O <header>
    // de uma PÁGINA — o bloco de título do /ui — é conteúdo, não é chrome.)
    foreach (['layouts/app', 'layouts/auth'] as $layout) {
        expect((string) file_get_contents(resource_path("views/{$layout}.blade.php")))
            ->not->toContain('<header')
            ->not->toContain('<footer');
    }
});

it('nenhuma ação Livewire usa palavra reservada do JavaScript (CSP-safe)', function () {
    $violations = [];

    // 1) O que as views chamam: wire:click="acao" / wire:submit="acao".
    foreach (bladeViews('resources/views') as $path => $contents) {
        preg_match_all('/wire:(?:click|submit)(?:\.[a-z.]+)?="([a-zA-Z_$][a-zA-Z0-9_]*)/', $contents, $matches);

        foreach ($matches[1] as $action) {
            if (in_array($action, JS_RESERVED_WORDS, true)) {
                $violations[] = sprintf('%s: wire:…="%s" é palavra reservada do JS', $path, $action);
            }
        }
    }

    // 2) O que os componentes expõem: método público = ação chamável.
    foreach (Finder::create()->files()->in(app_path('Livewire'))->name('*.php') as $file) {
        preg_match_all('/public function ([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', (string) file_get_contents($file->getRealPath()), $matches);

        foreach ($matches[1] as $method) {
            if (in_array($method, JS_RESERVED_WORDS, true)) {
                $violations[] = sprintf('app/Livewire/%s: método público "%s" é palavra reservada do JS', $file->getRelativePathname(), $method);
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('componentes do kit não fixam superfície com a escala do Tailwind', function () {
    // Os componentes são a FONTE das superfícies: se eles escreverem
    // `bg-white dark:bg-gray-900`, os tokens semânticos viram decoração.
    $componentes = ['card', 'input', 'select', 'textarea', 'modal', 'drawer', 'table', 'empty-state'];

    $violations = [];

    foreach ($componentes as $component) {
        $contents = (string) file_get_contents(resource_path("views/components/{$component}.blade.php"));

        if (preg_match('/dark:bg-gray-\d00/', $contents, $matches) === 1) {
            $violations[] = sprintf('components/%s.blade.php: "%s" — use bg-surface/bg-surface-raised', $component, $matches[0]);
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});
