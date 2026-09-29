<?php

declare(strict_types=1);

// =============================================================================
// VERSÃO EM TEXTO PURO
//
// Um e-mail só-HTML tem cara de phishing para os filtros do Gmail/Outlook e
// perde entregabilidade. O kit gera o texto do próprio HTML — o que este teste
// garante é que ele saia MESMO, que ele seja legível (sem marcação sobrando) e
// que o link do botão vire URL escrita por extenso: num e-mail em texto puro,
// um botão sem URL é um beco sem saída.
// =============================================================================

use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Foundation\Mail\MailPreview;
use Twstec\Kit\Foundation\Mail\PlainText;

it('gera uma versão em texto puro para todo e-mail do catálogo', function (string $slug): void {
    $email = MailPreview::render($slug, 'pt_BR');

    expect(trim($email['text']))
        ->not->toBe('')
        // Nada de atributo de estilo ou entidade sobrando.
        ->not->toContain('style=')
        ->not->toContain('&nbsp;')
        ->not->toContain('&#');

    // Nenhuma TAG sobrando. O "<" cru continua permitido: o e-mail de contato
    // mostra o remetente como Nome <email>, que é texto legítimo.
    expect(preg_match('/<\/[a-z]+>|<(?:html|body|div|p|span|table|tr|td|a|br|img|style|meta|h1)\b/i', $email['text']))->toBe(0);
})->with(MailPreview::slugs());

it('anexa o texto puro ao próprio Mailable (multipart/alternative)', function (): void {
    $content = (new VerificationCodeMail('482913', VerificationPurpose::SensitiveAction))->content();

    expect($content->text)->toBe('mail.text.auto');
    expect($content->with)->toHaveKey('plainTextBody');
    expect($content->with['plainTextBody'])->toContain('482913');
});

it('escreve a URL do botão por extenso no texto puro', function (): void {
    $text = MailPreview::render('password-reset', 'pt_BR')['text'];

    expect($text)
        ->toContain(__('mail.password_reset.action'))
        ->toContain('/reset-password/');
});

it('deixa o pré-header e o botão do Outlook fora do texto puro', function (): void {
    $html = <<<'HTML'
        <!--[text:skip]--><div>ruído da caixa de entrada</div><!--[/text:skip]-->
        <p>Corpo de verdade.</p>
        <!--[if mso]><v:roundrect>Rótulo duplicado</v:roundrect><![endif]-->
        <!--[if !mso]><!-- --><a href="https://exemplo.test">Continuar</a><!--<![endif]-->
        HTML;

    $text = PlainText::fromHtml($html);

    expect($text)
        ->toContain('Corpo de verdade.')
        ->toContain('Continuar: https://exemplo.test')
        ->not->toContain('ruído da caixa de entrada')
        ->not->toContain('Rótulo duplicado');
});

it('mantém o rótulo sozinho quando o link é um e-mail', function (): void {
    expect(PlainText::fromHtml('<a href="mailto:suporte@example.com">suporte@example.com</a>'))
        ->toBe('suporte@example.com');
});
