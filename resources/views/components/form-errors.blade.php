@props([
    'display' => null,
    'title' => null,
    'messages' => null,
    'fixed' => true,
])

{{-- Resumo de erros de validação no topo do formulário, com âncoras para os
     campos. <x-form-errors /> (segue config/ui.php → error_display)
     Override por formulário: <x-form-errors display="summary" />.
     Estratégia: inline → não renderiza nada (o erro fica junto ao campo);
     summary/both → resumo em <x-alert>; toast → toast do kit.
     :messages (['campo' => 'msg'] ou ['msg']) alimenta demos estáticos;
     :fixed="false" renderiza o toast no fluxo (uso restrito ao /ui). --}}
@php
    $strategy = form_error_display($display);

    if (is_array($messages)) {
        $items = collect($messages)
            ->map(fn (string $message, int|string $field): array => ['field' => is_string($field) ? $field : null, 'message' => $message])
            ->values();
    } else {
        $items = collect($errors->keys())
            ->flatMap(fn (string $field) => collect($errors->get($field))->map(fn (string $message): array => ['field' => $field, 'message' => $message]))
            ->values();
    }

    $showSummary = $items->isNotEmpty() && in_array($strategy, ['summary', 'both'], true);
    $showToast = $items->isNotEmpty() && $strategy === 'toast';
@endphp

@if ($showSummary)
    <x-alert type="error" :title="$title ?? __('ui.form_errors.title')" {{ $attributes }}>
        <ul class="mt-1 list-inside list-disc space-y-0.5">
            @foreach ($items as $item)
                <li>
                    @if ($item['field'] !== null)
                        <a href="#{{ $item['field'] }}" class="underline decoration-red-300 underline-offset-2 hover:text-red-900 dark:decoration-red-700 dark:hover:text-red-100">{{ $item['message'] }}</a>
                    @else
                        {{ $item['message'] }}
                    @endif
                </li>
            @endforeach
        </ul>
    </x-alert>
@elseif ($showToast)
    <div @class(['pointer-events-none fixed right-4 bottom-4 z-50 flex flex-col items-end gap-2' => $fixed])>
        <x-toast type="error" {{ $attributes }}>
            <p class="font-medium">{{ $title ?? __('ui.form_errors.title') }}</p>
            <ul class="mt-0.5 list-inside list-disc space-y-0.5">
                @foreach ($items as $item)
                    <li>{{ $item['message'] }}</li>
                @endforeach
            </ul>
        </x-toast>
    </div>
@endif
