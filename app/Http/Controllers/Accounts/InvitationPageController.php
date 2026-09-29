<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounts;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Twstec\Kit\Accounts\Account\Invitations\InvitationPreview;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * A TELA do link de convite (pública) — só GET, só HTTP.
 *
 * O que ela pode mostrar é do pacote de contas (InvitationPreview): o estado
 * do convite e, só quando ele está pendente e quem vê pode vê-lo, a conta,
 * quem convidou e o papel. Os ENVIOS (aceitar, criar a conta, recusar) vão
 * para o controller do pacote (InvitationController), que traz o próprio
 * `throttle:sensitive`.
 *
 * - Logado com o e-mail do convite: botão "Aceitar".
 * - Deslogado, o e-mail já tem conta: "Entrar para aceitar" — o login volta
 *   para esta tela (url.intended, que o pós-login passa pelo SafeRedirect).
 * - Deslogado, o e-mail não tem conta: o formulário que cria a conta.
 * - Logado com OUTRO e-mail, ou convite fora de validade: só o motivo.
 */
final class InvitationPageController
{
    public function show(Request $request, string $token): View
    {
        $user = $request->user();
        $preview = InvitationPreview::for($token, $user instanceof AuthUser ? $user : null);

        if ($preview->mode === InvitationPreview::MODE_LOGIN) {
            $request->session()->put('url.intended', route('invitations.show', $token));
        }

        return view('accounts.invitation', [
            'token' => $token,
            'preview' => $preview,
        ]);
    }
}
