{{-- Verificação de e-mail do cadastro. Mesmo desenho da redefinição de senha:
     UM CTA e a URL repetida em texto logo abaixo (cliente corporativo reescreve
     href e leitor de texto puro não clica). O link é ancorado em APP_URL —
     ver Twstec\Kit\Auth\Support\EmailVerification. --}}
<x-email::layouts.kit
    :title="__('mail.email_verification.subject', ['platform' => platform()->name])"
    :preheader="__('mail.email_verification.preheader')"
>
    <x-email::heading>{{ __('mail.email_verification.heading') }}</x-email::heading>

    <x-email::text>{{ __('mail.email_verification.intro') }}</x-email::text>

    <x-email::button :url="$verificationUrl">{{ __('mail.email_verification.action') }}</x-email::button>

    <x-email::text>{{ __('mail.email_verification.expires', ['minutes' => $expiresInMinutes]) }}</x-email::text>

    <x-email::text muted>{{ __('mail.email_verification.fallback') }}</x-email::text>
    <x-email::text muted>{{ $verificationUrl }}</x-email::text>

    <x-email::rule />

    <x-email::text muted last>{{ __('mail.email_verification.ignore') }}</x-email::text>
</x-email::layouts.kit>
