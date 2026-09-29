<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Twstec\Kit\Accounts\Account\Actions\CreateAccount;
use Twstec\Kit\Accounts\Accounts;

/**
 * CRIAR UMA CONTA de empresa: só o nome. A pessoa vira a dona, a conta nova
 * passa a ser a atual e a tela leva à página dela (para convidar a equipe).
 *
 * A regra (nome, limite de contas de que a pessoa é dona, trilha de
 * auditoria) é da Action CreateAccount do pacote de contas.
 */
final class Create extends Component
{
    public string $name = '';

    public function create(CreateAccount $create): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']], [], [
            'name' => __('panel.common.name'),
        ]);

        $account = $create->handle($this->user(), $this->name);

        Accounts::switchTo($account);

        session()->flash('account_status', __('panel.account.created', ['account' => $account->displayName()]));
        $this->redirectRoute('panel.account');
    }

    public function render(): View
    {
        return view('livewire.account.create')->title(__('panel.account.create_title'));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
