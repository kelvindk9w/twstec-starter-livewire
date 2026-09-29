@props(['code'])

{{-- Snippet de código copiável. <x-snippet code='<x-button>OK</x-button>' />
    Cópia e feedback via resources/js/ui.js ([data-copy]). --}}
<span class="inline-flex max-w-full items-start gap-1.5 rounded-md border border-border bg-surface-sunken py-1 pl-2 pr-1">
    {{-- O snippet existe para ser LIDO e copiado: nada de truncate. O código
         quebra em várias linhas (whitespace-pre-wrap + break-all) e, se ainda
         assim estourar, rola dentro do próprio bloco. Um trecho cortado no
         meio (`<x-input label="…" name="demo_na`) é pior do que nenhum. --}}
    <code class="min-w-0 max-w-full flex-1 overflow-x-auto whitespace-pre-wrap break-all text-xs leading-relaxed text-gray-600 dark:text-gray-400" style="scrollbar-width: thin">{{ $code }}</code>
    <button
        type="button"
        data-copy="{{ $code }}"
        data-copied-text="{{ __('showcase.snippets.copied') }}"
        title="{{ __('showcase.snippets.copy') }}"
        class="inline-flex shrink-0 items-center gap-1 self-start rounded px-1.5 py-0.5 text-xs text-gray-500 transition-colors duration-150 ease-(--ease-out) hover:bg-border-strong hover:text-gray-700 active:scale-[0.97] motion-reduce:active:scale-100 dark:hover:bg-gray-800 dark:hover:text-gray-300"
    >
        <x-ui-icon name="clipboard-document" class="h-3.5 w-3.5" />
        <span data-copy-label>{{ __('showcase.snippets.copy') }}</span>
    </button>
</span>
