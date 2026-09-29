@php
    $deletingProject = $confirmingDeleteUuid !== null
        ? $projects->firstWhere('uuid', $confirmingDeleteUuid)
        : null;
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-h1">{{ __('panel.projects.title') }}</h1>
            <p class="mt-1.5 max-w-2xl text-sm text-text-muted">{{ __('panel.projects.subtitle') }}</p>
        </div>
        {{-- Ações por papel na conta (AccountRole): member cria e edita, não exclui. --}}
        @if ($canCreate && ! $showCreateForm)
            <x-button type="button" wire:click="startCreate" size="sm">
                <x-ui-icon name="plus" class="h-4 w-4" />
                {{ __('panel.projects.new') }}
            </x-button>
        @endif
    </div>

    @if (session('projects_status'))
        <x-alert type="success">{{ session('projects_status') }}</x-alert>
    @endif

    {{-- Criação inline (mesma tela) --}}
    @if ($showCreateForm)
        <x-card :title="__('panel.projects.new')">
            <form wire:submit="create" class="flex flex-wrap items-end gap-3">
                <x-input
                    class="min-w-0 flex-1"
                    :label="__('panel.common.name')"
                    name="projectName"
                    wire:model="name"
                    maxlength="255"
                    :placeholder="__('panel.projects.name_placeholder')"
                    :error="$errors->first('name')"
                />
                <div class="flex gap-2">
                    <x-button type="submit">{{ __('panel.common.create') }}</x-button>
                    <x-button type="button" variant="secondary" wire:click="cancelCreate">{{ __('panel.common.cancel') }}</x-button>
                </div>
            </form>
        </x-card>
    @endif

    {{-- Skeleton durante ações Livewire (criar/editar/excluir): mostra a
         ESTRUTURA da lista em vez de spinner — percepção de rapidez. --}}
    <div wire:loading class="space-y-3">
        <x-skeleton :lines="3" />
    </div>

    <div wire:loading.remove>
        @if ($projects->isEmpty())
            @unless ($showCreateForm)
                <x-empty-state
                    icon="folder"
                    :title="__('panel.projects.empty_title')"
                    :description="__('panel.projects.empty')"
                >
                    @if ($canCreate)
                        <x-button type="button" wire:click="startCreate">{{ __('panel.projects.new') }}</x-button>
                    @endif
                </x-empty-state>
            @endunless
        @else
            <x-table :headers="[
                __('panel.common.name'),
                __('panel.projects.code'),
                __('panel.projects.keys_column'),
                __('panel.common.actions'),
            ]">
                @foreach ($projects as $project)
                    <x-table-row>
                        @if ($editingUuid === $project->uuid)
                            {{-- Edição inline: a linha inteira vira o formulário. --}}
                            <td colspan="4" class="block p-0 sm:table-cell sm:px-5 sm:py-3">
                                <form wire:submit="update" class="flex flex-wrap items-end gap-3">
                                    <x-input
                                        class="min-w-0 flex-1"
                                        :label="__('panel.projects.edit')"
                                        name="editingName"
                                        wire:model="editingName"
                                        maxlength="255"
                                        :error="$errors->first('editingName')"
                                    />
                                    <div class="flex gap-2">
                                        <x-button type="submit" size="sm">{{ __('panel.common.save') }}</x-button>
                                        <x-button type="button" variant="secondary" size="sm" wire:click="cancelEdit">{{ __('panel.common.cancel') }}</x-button>
                                    </div>
                                </form>
                            </td>
                        @else
                            <x-table-cell :label="__('panel.common.name')">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $project->name }}</span>
                            </x-table-cell>
                            <x-table-cell :label="__('panel.projects.code')">
                                <code class="font-mono text-caption text-text-muted">{{ $project->codigo_publico }}</code>
                            </x-table-cell>
                            <x-table-cell :label="__('panel.projects.keys_column')">
                                <span class="text-caption text-text-muted">{{ __('panel.projects.linked_keys', ['count' => $project->api_keys_count]) }}</span>
                            </x-table-cell>
                            <x-table-cell :label="__('panel.common.actions')" align="end">
                                <span class="flex items-center justify-end gap-2">
                                    @if ($canUpdate)
                                        <x-button type="button" variant="secondary" size="sm" wire:click="startEdit('{{ $project->uuid }}')">{{ __('panel.common.edit') }}</x-button>
                                    @endif

                                    {{-- Ação destrutiva mora num menu de overflow:
                                         no mobile, três botões encostados fazem o
                                         dedo errar o alvo — e o alvo errado aqui
                                         apaga dado. --}}
                                    @if ($canDelete)
                                    <x-dropdown>
                                        <x-slot:trigger>
                                            <button
                                                type="button"
                                                aria-label="{{ __('panel.common.more_actions') }}"
                                                class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border text-gray-500 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken hover:text-gray-900 sm:h-8 sm:w-8 dark:text-gray-400 dark:hover:text-gray-100"
                                            >
                                                <x-ui-icon name="ellipsis-horizontal" class="h-4 w-4" />
                                            </button>
                                        </x-slot:trigger>
                                        <x-dropdown-item danger wire:click="startDelete('{{ $project->uuid }}')">
                                            <x-ui-icon name="trash" class="h-4 w-4" />
                                            {{ __('panel.common.delete') }}
                                        </x-dropdown-item>
                                    </x-dropdown>
                                    @endif
                                </span>
                            </x-table-cell>
                        @endif
                    </x-table-row>
                @endforeach
            </x-table>
        @endif
    </div>

    {{-- Confirmação de exclusão: modal do kit, controlado pelo servidor
         (:open + dismiss ligam backdrop/X/Esc à ação Livewire). --}}
    @if ($deletingProject !== null)
        <x-modal
            id="delete-project"
            :open="true"
            dismiss="cancelDelete"
            :title="__('panel.projects.delete_title')"
        >
            {{ __('panel.projects.delete_warning', ['name' => $deletingProject->name]) }}

            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelDelete">{{ __('panel.common.cancel') }}</x-button>
                {{-- wire:click="removeProject": `delete` é palavra reservada do
                     JS e quebra o parser CSP-safe do Livewire (ver o método). --}}
                <x-button type="button" variant="danger" wire:click="removeProject">{{ __('panel.common.delete') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
