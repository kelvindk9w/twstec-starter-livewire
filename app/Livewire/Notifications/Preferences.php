<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Preferências de notificação (esqueleto preparado para as notificações
 * do projeto que herdar o kit — ex.: pagamentos).
 *
 * Catálogo de toggles: config/notifications.php (defaults + flags 'locked').
 * A escolha do usuário fica no JSON users.notification_preferences; a leitura
 * efetiva é User::notificationPreference() (escolha → default do config).
 */
final class Preferences extends Component
{
    /** @var array<string, bool> */
    public array $preferences = [];

    public function mount(): void
    {
        foreach (array_keys((array) config('notifications.preferences', [])) as $key) {
            $this->preferences[$key] = $this->user()->notificationPreference($key);
        }
    }

    public function save(): void
    {
        $catalog = (array) config('notifications.preferences', []);

        // Toggles 'locked' (alertas de segurança) são sempre gravados como true
        // — a UI os desabilita, e aqui é a segunda linha de defesa.
        $saved = [];

        foreach ($catalog as $key => $meta) {
            $saved[$key] = ($meta['locked'] ?? false) ? true : (bool) ($this->preferences[$key] ?? false);
        }

        $this->user()->forceFill(['notification_preferences' => $saved])->save();

        session()->flash('notifications_status', __('panel.notifications.saved'));
    }

    public function render(): View
    {
        return view('livewire.notifications.preferences', [
            'catalog' => (array) config('notifications.preferences', []),
        ])->title(__('panel.notifications.title'));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
