<?php

declare(strict_types=1);

// =============================================================================
// IDIOMA DOS E-MAILS
//
// O bug de QA #9 foi exatamente isto: o e-mail de recuperação de senha chegava
// em inglês para uma conta em pt-BR, porque a notificação nativa monta o texto
// com as linhas do PACOTE. O teste abaixo trava assunto e corpo nos três
// idiomas do kit, para todo e-mail do catálogo.
// =============================================================================

use App\Models\User;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Twstec\Kit\Auth\Notifications\ResetPasswordNotification;
use Twstec\Kit\Foundation\Mail\MailPreview;

it('traduz o assunto de todo e-mail nos três idiomas', function (string $slug): void {
    $subjects = collect(['pt_BR', 'en', 'es'])
        ->mapWithKeys(fn (string $locale): array => [$locale => MailPreview::render($slug, $locale)['subject']]);

    // Nenhum assunto vazio, nenhuma chave crua vazando (":platform", "mail.").
    $subjects->each(function (string $subject): void {
        expect($subject)
            ->not->toBe('')
            ->not->toContain(':platform')
            ->not->toStartWith('mail.');
    });

    // E os três são DIFERENTES entre si — se um idioma cair no fallback, os
    // assuntos ficam iguais e o teste acusa.
    expect($subjects->unique())->toHaveCount(3);
})->with(MailPreview::slugs());

it('traduz o corpo de todo e-mail nos três idiomas', function (string $slug): void {
    $bodies = collect(['pt_BR', 'en', 'es'])
        ->mapWithKeys(fn (string $locale): array => [$locale => MailPreview::render($slug, $locale)['text']]);

    $bodies->each(fn (string $text) => expect(trim($text))->not->toBe(''));
    expect($bodies->unique())->toHaveCount(3);
})->with(MailPreview::slugs());

it('marca o idioma do documento com o locale em vigor', function (): void {
    expect(MailPreview::render('verification-code', 'pt_BR')['html'])->toContain('<html lang="pt-BR"');
    expect(MailPreview::render('verification-code', 'es')['html'])->toContain('<html lang="es"');
});

it('manda a recuperação de senha no idioma da CONTA, não no do servidor', function (): void {
    // Quem escolhe o idioma é o destinatário: o User implementa
    // HasLocalePreference e o Laravel troca o locale ao enviar/enfileirar.
    $user = User::factory()->create(['locale' => 'es']);

    expect($user)->toBeInstanceOf(HasLocalePreference::class)
        ->and($user->preferredLocale())->toBe('es');

    // E, no idioma da conta, o corpo sai do lang/ do projeto — não das linhas
    // em inglês do pacote de notificações (era o bug de QA #9).
    app()->setLocale($user->preferredLocale());

    $message = (new ResetPasswordNotification('token-de-teste'))->toMail($user);

    expect($message->subject)->toBe(__('mail.password_reset.subject', ['platform' => platform()->name]));
    expect(view($message->view[0], $message->viewData)->render())
        ->toContain(__('mail.password_reset.intro'))
        ->toContain(__('mail.password_reset.action'));
});
