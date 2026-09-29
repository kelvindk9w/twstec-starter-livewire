<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use Twstec\Kit\Accounts\Account\Models\AccountMembership;
use Twstec\Kit\Accounts\Account\Queries\AccountDirectory;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Foundation\Kit;

/**
 * O que o SELETOR DE CONTA mostra (<x-account-switcher>): a conta atual e
 * todas as contas da pessoa logada, cada uma com o papel dela — numa consulta
 * só (os vínculos da pessoa, com a conta).
 *
 * Como a Navigation, é a única estrutura das views do painel que precisa de
 * dado: fica aqui, não no Blade. A troca em si é um POST para a rota do
 * pacote (accounts.switch), que só aceita conta de que a pessoa é membro.
 *
 * Sem o pacote de contas (twstec/kit-accounts, opcional) não há conta: o menu
 * é vazio e o seletor não aparece.
 */
final class AccountMenu
{
    /**
     * @return array{current: array{uuid: string, name: string, role: string, personal: bool}|null, accounts: list<array{uuid: string, name: string, role: string, personal: bool, current: bool}>}
     */
    public static function for(?AuthUser $user): array
    {
        if ($user === null || ! Kit::has('accounts')) {
            return ['current' => null, 'accounts' => []];
        }

        $atual = Accounts::current();

        $contas = app(AccountDirectory::class)->accountsOf($user)
            ->sortBy(fn (AccountMembership $m): string => ($m->account->isPersonal() ? '0' : '1').mb_strtolower($m->account->displayName()))
            ->map(fn (AccountMembership $m): array => [
                'uuid' => (string) $m->account->uuid,
                'name' => $m->account->displayName(),
                'role' => $m->role->label(),
                'personal' => $m->account->isPersonal(),
                'current' => $atual !== null && $atual->is($m->account),
            ])
            ->values()
            ->all();

        $corrente = collect($contas)->firstWhere('current', true);

        return [
            'current' => $corrente === null ? null : array_diff_key($corrente, ['current' => true]),
            'accounts' => $contas,
        ];
    }
}
