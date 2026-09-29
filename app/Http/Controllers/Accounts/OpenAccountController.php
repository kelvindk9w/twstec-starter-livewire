<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounts;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Twstec\Kit\Accounts\Account\Actions\SwitchAccount;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Abre uma tela do painel JÁ NA CONTA CERTA — o link dos e-mails de conta
 * (o aviso de chave órfã leva às chaves da conta de onde a pessoa saiu).
 *
 * A troca de conta por GET só vale com URL ASSINADA (middleware `signed`):
 * ninguém monta um link que troca a conta de outra pessoa. E, como no
 * seletor, só conta de que a pessoa é membro (SwitchAccount: 403 e `denied`
 * na trilha). O destino é uma lista fechada, não uma URL.
 */
final class OpenAccountController
{
    /**
     * Destinos aceitos => rota do painel.
     */
    public const TARGETS = [
        'api-keys' => 'panel.api-keys',
        'account' => 'panel.account',
    ];

    public function __invoke(Request $request, string $account, string $to, SwitchAccount $switch): RedirectResponse
    {
        abort_unless(array_key_exists($to, self::TARGETS), 404);

        /** @var AuthUser $user */
        $user = $request->user();

        $switch->handle($user, $account);

        return redirect()->route(self::TARGETS[$to]);
    }
}
