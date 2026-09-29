<?php

declare(strict_types=1);

// =============================================================================
// /mail-preview — GALERIA DOS E-MAILS (SÓ EM DESENVOLVIMENTO)
//
// A tela é conveniência de dev; em produção é material pronto para phishing
// (todo o desenho oficial dos e-mails da plataforma, em três idiomas, servido
// publicamente). Por isso a trava desligada é testada com o mesmo cuidado que
// a ligada.
// =============================================================================

use Twstec\Kit\Foundation\Mail\MailPreview;

it('lista os e-mails quando a flag de dev está ligada', function (): void {
    config()->set('ui.demo_login.enabled', true);

    $response = $this->get('/mail-preview');

    $response->assertOk()
        ->assertSee(__('mail.preview.title'))
        ->assertSee(__('mail.preview.emails.verification-code'))
        ->assertSee(__('mail.preview.emails.password-reset'))
        ->assertSee(__('mail.preview.emails.api-key-inactivity'))
        ->assertSee(__('mail.preview.emails.contact-message'));
})->group('demo');

it('responde 404 fora do desenvolvimento', function (): void {
    config()->set('ui.demo_login.enabled', false);

    $this->get('/mail-preview')->assertNotFound();
    $this->get('/mail-preview/verification-code')->assertNotFound();
});

it('abre cada e-mail do catálogo', function (string $slug): void {
    config()->set('ui.demo_login.enabled', true);

    $this->get("/mail-preview/{$slug}")->assertOk();
})->group('demo')->with(MailPreview::slugs());

it('serve o HTML cru e o texto puro na mesma rota', function (): void {
    config()->set('ui.demo_login.enabled', true);

    $this->get('/mail-preview/verification-code?format=html')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('<table role="presentation"', escape: false);

    $this->get('/mail-preview/verification-code?format=text')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertDontSee('<table', escape: false);
})->group('demo');

it('troca idioma e tema pela URL', function (): void {
    config()->set('ui.demo_login.enabled', true);

    $this->get('/mail-preview/verification-code?lang=es&format=html')
        ->assertOk()
        ->assertSee(__('mail.verification_code.heading', locale: 'es'));

    // Tema escuro: escrito inline, sem depender do sistema de quem olha.
    $this->get('/mail-preview/verification-code?scheme=dark&format=html')
        ->assertOk()
        ->assertSee('#111827', escape: false);
})->group('demo');

it('recusa um e-mail que não existe no catálogo', function (): void {
    config()->set('ui.demo_login.enabled', true);

    $this->get('/mail-preview/e-mail-inventado')->assertNotFound();
});
