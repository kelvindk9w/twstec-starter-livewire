{{-- Redefinição de senha. UM CTA, e a URL repetida em texto logo abaixo:
     cliente corporativo reescreve href e leitor de texto puro não clica. --}}
<x-email::layouts.kit
    :title="__('mail.password_reset.subject', ['platform' => platform()->name])"
    :preheader="__('mail.password_reset.preheader', ['minutes' => $expiresInMinutes])"
>
    <x-email::heading>{{ __('mail.password_reset.heading') }}</x-email::heading>

    <x-email::text>{{ __('mail.password_reset.intro') }}</x-email::text>

    <x-email::button :url="$resetUrl">{{ __('mail.password_reset.action') }}</x-email::button>

    <x-email::text>{{ __('mail.password_reset.expires', ['minutes' => $expiresInMinutes]) }}</x-email::text>

    <x-email::text muted>{{ __('mail.password_reset.fallback') }}</x-email::text>
    <x-email::text muted>{{ $resetUrl }}</x-email::text>

    <x-email::rule />

    <x-email::text muted last>{{ __('mail.password_reset.ignore') }}</x-email::text>
</x-email::layouts.kit>
