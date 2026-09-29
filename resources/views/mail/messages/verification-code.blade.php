{{-- Código de verificação (2FA por e-mail). Um único assunto:
     o código. Nada de botão, nada de link — quem abre este e-mail já está
     com o formulário aberto do outro lado. `$copy` é o grupo de strings da
     finalidade (ação sensível ou login — VerificationCodeMail::copyKey). --}}
<x-email::layouts.kit
    :title="__($copy.'.subject', ['platform' => platform()->name])"
    :preheader="__($copy.'.preheader', ['minutes' => $expiresInMinutes])"
>
    <x-email::heading>{{ __($copy.'.heading') }}</x-email::heading>

    <x-email::text>{{ __($copy.'.intro') }}</x-email::text>

    <x-email::code :code="$code" />

    <x-email::text>{{ __($copy.'.expires', ['minutes' => $expiresInMinutes]) }}</x-email::text>

    <x-email::notice>{{ __($copy.'.ignore') }}</x-email::notice>
</x-email::layouts.kit>
