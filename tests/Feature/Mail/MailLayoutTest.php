<?php

declare(strict_types=1);

// =============================================================================
// O LAYOUT ÚNICO DOS E-MAILS
//
// O risco que estes testes cobrem não é "o e-mail quebrou" — é o e-mail sair
// SEM a moldura: alguém cria um Mailable novo, escreve o HTML na mão, e a
// mensagem chega sem marca, sem rodapé e sem texto puro. Aqui todo e-mail do
// catálogo é obrigado a passar pelo mesmo esqueleto.
// =============================================================================

use Twstec\Kit\Foundation\Mail\MailPreview;

it('renderiza todo e-mail do catálogo com o cabeçalho e o rodapé do kit', function (string $slug): void {
    $email = MailPreview::render($slug, 'pt_BR');

    // Cabeçalho: a marca vem de config/platform.php, nunca fixa.
    expect($email['html'])->toContain(platform()->name);

    // Rodapé: empresa, aviso de e-mail transacional e direitos autorais.
    expect($email['html'])
        ->toContain(platform()->companyName)
        ->toContain(__('mail.footer.transactional'))
        ->toContain(__('mail.footer.rights', ['year' => now()->year, 'company' => platform()->companyName]))
        ->toContain((string) platform()->supportEmail);
})->with(MailPreview::slugs());

it('monta o e-mail sobre a estrutura que os clientes de e-mail entendem', function (string $slug): void {
    $html = MailPreview::render($slug, 'pt_BR')['html'];

    expect($html)
        // Tabela, não flex/grid: o Outlook renderiza com o motor do Word.
        ->toContain('<table role="presentation"')
        // 600px é o consenso de compatibilidade.
        ->toContain('max-width:600px')
        // Os dois temas anunciados ao cliente.
        ->toContain('<meta name="color-scheme" content="light dark">')
        // Cor escrita inline (nenhum cliente resolve var() nem CSS externo).
        ->toContain('style="background-color:')
        ->not->toContain('var(--')
        ->not->toContain('<link rel="stylesheet"');
})->with(MailPreview::slugs());

it('leva um texto de pré-visualização próprio para a caixa de entrada', function (string $slug): void {
    $html = MailPreview::render($slug, 'pt_BR')['html'];

    // O bloco escondido existe e é marcado para NÃO entrar no texto puro.
    expect($html)
        ->toContain('<!--[text:skip]-->')
        ->toContain('mso-hide:all');
})->with(MailPreview::slugs());

it('escreve o tema escuro inline quando ele é forçado (é o que a pré-visualização usa)', function (): void {
    $light = MailPreview::render('verification-code', 'pt_BR', dark: false)['html'];
    $dark = MailPreview::render('verification-code', 'pt_BR', dark: true)['html'];

    // No claro o escuro fica na media query; no forçado, vira estilo inline.
    expect($light)->toContain('@media (prefers-color-scheme: dark)');
    expect($dark)
        ->not->toContain('@media (prefers-color-scheme: dark)')
        ->toContain('background-color:#111827');
});

it('não vaza segredo nenhum no aviso de chave de API inativa', function (): void {
    $html = MailPreview::render('api-key-inactivity', 'pt_BR')['html'];

    expect($html)
        ->toContain('pk_live_3f9a2c81b7d4e6520a1c8f37')
        ->not->toContain('sk_');
})->group('accounts');
