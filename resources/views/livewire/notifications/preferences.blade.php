<div class="space-y-6">
    <div>
        <h1 class="font-display text-h1">{{ __('panel.notifications.title') }}</h1>
        <p class="mt-1.5 max-w-2xl text-sm text-text-muted">{{ __('panel.notifications.subtitle') }}</p>
    </div>

    @if (session('notifications_status'))
        <x-alert type="success">{{ session('notifications_status') }}</x-alert>
    @endif

    <form wire:submit="save">
        <x-card :title="__('panel.notifications.emails_heading')" padding="none">
            <ul class="divide-y divide-border">
                @foreach ($catalog as $key => $meta)
                    <li class="flex items-center justify-between gap-4 p-5">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('panel.notifications.pref_'.$key) }}</p>
                            <p class="mt-0.5 text-caption text-text-muted">{{ __('panel.notifications.pref_'.$key.'_hint') }}</p>
                        </div>

                        @if ($meta['locked'] ?? false)
                            {{-- Alerta de segurança: sempre ativo. Um toggle
                                 desabilitado sugere "você pode, mas não agora";
                                 o badge diz a verdade — não é uma escolha. --}}
                            <x-badge class="shrink-0">
                                <x-ui-icon name="lock-closed" class="h-3 w-3" />
                                {{ __('panel.notifications.locked') }}
                            </x-badge>
                        @else
                            <x-toggle
                                class="shrink-0"
                                wire:model="preferences.{{ $key }}"
                                :aria-label="__('panel.notifications.pref_'.$key)"
                            />
                        @endif
                    </li>
                @endforeach
            </ul>

            <x-slot:footer>
                <div class="flex justify-end">
                    <x-button type="submit" wire:loading.attr="disabled">
                        <x-spinner size="sm" tone="current" wire:loading wire:target="save" />
                        {{ __('panel.notifications.save') }}
                    </x-button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</div>
