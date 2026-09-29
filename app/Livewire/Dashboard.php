<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Twstec\Kit\Accounts\Tenancy\Queries\AccountOverviewQuery;
use Twstec\Kit\Foundation\Kit;

/**
 * Dashboard do painel do usuário (Livewire).
 *
 * A tela de entrada mostra o que o kit JÁ COLETA sobre a CONTA ATUAL: chaves,
 * projetos e o tráfego real da API da conta (request_logs). Um dashboard que
 * só conta linhas de duas tabelas não prova que a instrumentação existe.
 *
 * Os números e listas vêm do AccountOverviewQuery (backend, reutilizável por
 * qualquer tela); este componente só os apresenta.
 *
 * Sem o pacote de contas (twstec/kit-accounts, opcional) não há conta, chave,
 * projeto nem API: a tela abre com os atalhos da conta da pessoa.
 */
final class Dashboard extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.dashboard', [
            'user' => $user,
            ...(Kit::has('accounts') ? app(AccountOverviewQuery::class)->forCurrentAccount()->toArray() : []),
        ])->title(__('panel.dashboard.title'));
    }
}
