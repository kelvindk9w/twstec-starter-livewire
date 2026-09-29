{{-- Tela do LINK DE CONVITE (pública) — InvitationPageController.

     Mesmo layout das telas de autenticação (cabeçalho, idioma e tema do site).
     O que aparece vem do pacote de contas (InvitationPreview):
     - pendente + logado com o e-mail do convite → Aceitar / Recusar;
     - pendente + deslogado, o e-mail já tem conta → Entrar para aceitar (o
       login volta para cá);
     - pendente + deslogado, sem conta → o formulário que CRIA a conta (o
       e-mail é o do convite, não se escolhe);
     - qualquer outro estado → só o motivo (expirado, revogado, já usado,
       recusado, e-mail diferente, já é membro, link inválido). Com e-mail
       diferente, NENHUM dado da conta aparece. --}}
@extends('layouts.auth')

@section('title', __('panel.invitation.title'))

@section('content')
    <div data-invitation-state="{{ $preview->state }}" @if ($preview->mode) data-invitation-mode="{{ $preview->mode }}" @endif>
        <h1 class="mb-2 font-display text-xl font-semibold tracking-[-0.01em]">{{ __('panel.invitation.title') }}</h1>

        @if (session('invitation_error'))
            <x-alert type="error" class="mb-4" data-invitation-error>{{ session('invitation_error') }}</x-alert>
        @endif

        @if ($preview->isPending())
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-300" data-invitation-intro>
                {{ __('panel.invitation.intro', ['inviter' => $preview->inviterName ?? __('panel.invitation.someone'), 'account' => $preview->accountName, 'role' => $preview->roleLabel]) }}
            </p>

            <dl class="mb-6 space-y-1 rounded-lg border border-border bg-surface-sunken p-3 text-sm">
                <div>
                    <dt class="text-caption text-text-muted">{{ __('panel.invitation.email') }}</dt>
                    <dd class="break-all font-medium" data-invitation-email>{{ $preview->email }}</dd>
                </div>
                <div class="pt-1">
                    <dt class="text-caption text-text-muted">{{ __('panel.invitation.expires') }}</dt>
                    <dd class="font-medium">{{ $preview->expiresAt?->translatedFormat('d M Y H:i') }}</dd>
                </div>
            </dl>

            @if ($preview->mode === 'accept')
                <form method="POST" action="{{ route('invitations.accept', $token) }}">
                    @csrf
                    <x-button type="submit" class="w-full" data-invitation-accept>{{ __('panel.invitation.accept') }}</x-button>
                </form>
            @elseif ($preview->mode === 'login')
                <p class="mb-4 text-sm text-text-muted">{{ __('panel.invitation.login_hint') }}</p>
                <x-button :href="route('login')" class="w-full" data-invitation-login>{{ __('panel.invitation.login') }}</x-button>
            @else
                <p class="mb-4 text-sm text-text-muted">{{ __('panel.invitation.register_hint') }}</p>
                <form method="POST" action="{{ route('invitations.register', $token) }}" class="space-y-4" data-invitation-register>
                    @csrf
                    <x-input :label="__('auth.ui.name')" name="name" :value="old('name')" :error="field_error('name')" required autofocus autocomplete="name" />
                    <x-input :label="__('auth.ui.password')" name="password" type="password" :hint="ucfirst(\Twstec\Kit\Auth\PasswordPolicy::hint())" :error="field_error('password')" required autocomplete="new-password" />
                    <x-input :label="__('auth.ui.password_confirmation')" name="password_confirmation" type="password" :error="field_error('password_confirmation')" required autocomplete="new-password" />
                    <x-button type="submit" class="w-full">{{ __('panel.invitation.register') }}</x-button>
                </form>
            @endif

            <form method="POST" action="{{ route('invitations.decline', $token) }}" class="mt-3">
                @csrf
                <x-button type="submit" variant="ghost" class="w-full" data-invitation-decline>{{ __('panel.invitation.decline') }}</x-button>
            </form>
        @else
            <x-alert :type="$preview->state === 'accepted' || $preview->state === 'already_member' ? 'info' : 'warning'" class="mb-6" data-invitation-unavailable>
                {{ __('accounts.invitations.unavailable.'.$preview->state) }}
            </x-alert>

            @auth
                @if ($preview->state === 'wrong_email')
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-button type="submit" variant="secondary" class="w-full">{{ __('auth.ui.logout') }}</x-button>
                    </form>
                @else
                    <x-button :href="route('dashboard')" variant="secondary" class="w-full">{{ __('panel.invitation.go_to_panel') }}</x-button>
                @endif
            @else
                <x-button :href="route('login')" variant="secondary" class="w-full">{{ __('auth.ui.login_link') }}</x-button>
            @endauth
        @endif
    </div>
@endsection
