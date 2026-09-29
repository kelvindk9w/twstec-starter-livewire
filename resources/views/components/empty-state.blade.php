@props([
    'title',
    'description' => null,
    'icon' => 'information-circle',
])

{{-- Estado vazio de listagens. <x-empty-state title="…" description="…">ação</x-empty-state> --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl border border-dashed border-border-strong bg-surface px-6 py-12 text-center']) }}>
    <x-ui-icon :name="$icon" class="h-10 w-10 text-gray-400 dark:text-gray-500" />
    <h3 class="mt-4 font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h3>
    @if ($description !== null)
        <p class="mt-1 max-w-sm text-sm text-text-muted">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-6">{{ $slot }}</div>
    @endif
</div>
