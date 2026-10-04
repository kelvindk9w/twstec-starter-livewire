<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// =============================================================================
// <x-checkbox>: os atributos que carregam o ESTADO (wire:model, value, data-*)
// vão para o INPUT, não para a label. Até a 2.0.0-beta.14 iam para a label, e
// uma LISTA de caixas com wire:model (escopos e projetos de uma chave de API)
// não sincronizava no navegador: a chave nascia sem a restrição marcada. O
// teste do Livewire (Livewire::test) não roda o JavaScript — a prova no
// navegador é o tests/e2e/api-key-scopes.spec.js; aqui, a marcação.
// =============================================================================

/**
 * O HTML do componente e os atributos do input e da label, lidos pelo DOM.
 *
 * @return array{input: DOMElement, label: DOMElement}
 */
function renderedCheckbox(string $blade): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.Blade::render($blade, ['uuid' => 'a1b2']));

    /** @var DOMElement $input */
    $input = $dom->getElementsByTagName('input')->item(0);
    /** @var DOMElement $label */
    $label = $dom->getElementsByTagName('label')->item(0);

    return ['input' => $input, 'label' => $label];
}

it('numa lista, o wire:model e o valor de cada caixa vão para o input', function () {
    ['input' => $input, 'label' => $label] = renderedCheckbox('<x-checkbox class="rounded-lg" wire:model="selectedProjectUuids" value="{{ $uuid }}" data-x="1" label="Loja A" />');

    expect($input->getAttribute('wire:model'))->toBe('selectedProjectUuids')
        ->and($input->getAttribute('value'))->toBe('a1b2')
        ->and($input->getAttribute('data-x'))->toBe('1')
        ->and($input->getAttribute('type'))->toBe('checkbox')
        ->and($label->hasAttribute('wire:model'))->toBeFalse()
        ->and($label->hasAttribute('value'))->toBeFalse()
        ->and($label->getAttribute('class'))->toContain('rounded-lg')
        ->and($label->textContent)->toContain('Loja A');
});

it('caixa única de formulário: envia "1" com o nome dado', function () {
    ['input' => $input] = renderedCheckbox('<x-checkbox name="remember" label="Lembrar de mim" :checked="true" />');

    expect($input->getAttribute('name'))->toBe('remember')
        ->and($input->getAttribute('value'))->toBe('1')
        ->and($input->hasAttribute('checked'))->toBeTrue();
});

it('as telas usam a caixa em lista com valor próprio (chaves de API e webhooks)', function () {
    $chaves = (string) file_get_contents(resource_path('views/livewire/api-keys/index.blade.php'));

    expect(substr_count($chaves, '<x-checkbox'))->toBe(3)
        ->and(substr_count($chaves, 'wire:model="selectedScopes"'))->toBe(1)
        ->and(substr_count($chaves, 'wire:model="selectedProjectUuids"'))->toBe(1)
        ->and(substr_count($chaves, 'wire:model="editingProjectsSelection"'))->toBe(1);
});
