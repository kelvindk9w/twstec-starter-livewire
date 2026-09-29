<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Foundation\FoundationServiceProvider;
use Twstec\Kit\Foundation\Mail\Exceptions\NonDeliveringMailerInProductionException;
use Twstec\Kit\Foundation\Mail\NonDeliveringMailers;

// =============================================================================
// MAILER QUE NÃO ENTREGA, EM PRODUÇÃO
//
// O achado: o padrão de MAIL_MAILER era `log` no config/mail.php E no
// docker-compose.prod.yml. Produção sem e-mail configurado não dava erro — e
// cada e-mail do kit (código de verificação, link de redefinição de senha com o
// token, mensagem de contato com nome e e-mail) era GRAVADO INTEIRO no arquivo
// de log. O `array` é o irmão silencioso: descarta tudo.
//
// O contrato: em produção, `log` e `array` RECUSAM o envio com exceção que diz
// o que configurar (o job falha e aparece no Horizon); nada da mensagem chega
// ao log. Fora de produção, e com o opt-out declarado, eles funcionam. E nada
// disso pode tocar o boot: sem `.env`, o Laravel se considera em produção com
// mailer `log`, e o `composer install` tem de continuar passando.
// =============================================================================

const CODIGO_SENSIVEL = '731902';

/**
 * Finge produção e força o gerenciador de e-mail a ser resolvido de novo —
 * a recusa é instalada na RESOLUÇÃO, e a suíte pode já tê-lo resolvido.
 */
function mailerEmProducao(string $mailer): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('mail.default', $mailer);

    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();
}

/**
 * Aponta o transporte `log` para um arquivo próprio do teste, para que dê para
 * ler o que ele gravou (ou provar que não gravou nada).
 */
function capturaDoLogDeEmail(): string
{
    $arquivo = storage_path('framework/testing/mail-log-'.uniqid().'.log');

    config()->set('logging.channels.mail-capture', ['driver' => 'single', 'path' => $arquivo]);
    config()->set('mail.mailers.log.channel', 'mail-capture');

    return $arquivo;
}

function enviaCodigoDeVerificacao(): void
{
    Mail::to('titular@example.com')->sendNow(
        new VerificationCodeMail(CODIGO_SENSIVEL, VerificationPurpose::SensitiveAction),
    );
}

it('recusa o envio pelo transporte log em produção, sem gravar a mensagem no log', function (): void {
    $arquivo = capturaDoLogDeEmail();
    mailerEmProducao('log');

    expect(fn () => enviaCodigoDeVerificacao())
        ->toThrow(NonDeliveringMailerInProductionException::class, 'MAIL_MAILER');

    // O conteúdo (o código, o destinatário) não pode ter ido para o log.
    expect(file_exists($arquivo) ? (string) file_get_contents($arquivo) : '')
        ->not->toContain(CODIGO_SENSIVEL);
});

it('recusa o envio pelo transporte array em produção', function (): void {
    mailerEmProducao('array');

    expect(fn () => enviaCodigoDeVerificacao())
        ->toThrow(NonDeliveringMailerInProductionException::class, 'descarta');
});

it('olha o transporte e não o nome do mailer', function (): void {
    // Um mailer com nome próprio e `transport => log` vaza do mesmo jeito.
    config()->set('mail.mailers.principal', ['transport' => 'log']);
    mailerEmProducao('principal');

    expect(NonDeliveringMailers::defaultIsNonDelivering())->toBeTrue()
        ->and(fn () => enviaCodigoDeVerificacao())->toThrow(NonDeliveringMailerInProductionException::class);
});

it('recusa também o último recurso do failover, que é o log', function (): void {
    // O `failover` padrão do Laravel termina em `log`: quando o SMTP cai, a
    // mensagem ia parar inteira no log.
    config()->set('mail.mailers.failover.mailers', ['log']);
    mailerEmProducao('failover');

    expect(fn () => enviaCodigoDeVerificacao())->toThrow(NonDeliveringMailerInProductionException::class);
});

it('a mensagem da recusa não carrega o conteúdo do e-mail', function (): void {
    mailerEmProducao('log');

    try {
        enviaCodigoDeVerificacao();
        $this->fail('o envio deveria ter sido recusado');
    } catch (NonDeliveringMailerInProductionException $recusa) {
        expect($recusa->getMessage())->not->toContain(CODIGO_SENSIVEL)
            ->and($recusa->getMessage())->not->toContain('titular@example.com');
    }
});

it('fora de produção o transporte log continua funcionando (desenvolvimento)', function (): void {
    // Controle do teste acima: prova que o arquivo capturado é mesmo onde o
    // transporte `log` escreveria — e que em desenvolvimento ele escreve.
    $arquivo = capturaDoLogDeEmail();
    config()->set('mail.default', 'log');
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();

    enviaCodigoDeVerificacao();

    expect((string) file_get_contents($arquivo))->toContain(CODIGO_SENSIVEL);

    @unlink($arquivo);
});

it('com o opt-out declarado, produção volta a aceitar o transporte que não entrega', function (): void {
    config()->set('security.mail.allow_non_delivering_in_production', true);
    mailerEmProducao('array');

    enviaCodigoDeVerificacao();

    expect(NonDeliveringMailers::allowedInProductionByOptOut())->toBeTrue()
        ->and(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('um mailer que entrega não é afetado em produção', function (): void {
    config()->set('mail.mailers.smtp.host', 'smtp.example.com');
    mailerEmProducao('smtp');

    $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();

    expect(NonDeliveringMailers::defaultIsNonDelivering())->toBeFalse()
        ->and((string) $transport)->toContain('smtp.example.com');
});

it('o opt-out ligado grava aviso a cada boot', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('security.mail.allow_non_delivering_in_production', true);
    Log::spy();

    app()->getProvider(FoundationServiceProvider::class)->boot();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'MAIL_ALLOW_NON_DELIVERING_IN_PRODUCTION'))
        ->once();
});

it('avisa na subida do worker quando o mailer padrão não entrega, e só nele', function (string $comando, bool $avisa): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('mail.default', 'log');

    $argvOriginal = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['artisan', $comando];
    Log::spy();

    try {
        app()->getProvider(FoundationServiceProvider::class)->boot();
    } finally {
        $_SERVER['argv'] = $argvOriginal;
    }

    $esperado = fn (string $mensagem): bool => str_contains($mensagem, 'MAIL_MAILER=log');

    $avisa
        ? Log::shouldHaveReceived('warning')->withArgs($esperado)->once()
        : Log::shouldNotHaveReceived('warning', [Mockery::on($esperado)]);
})->with([
    'horizon (processa a fila)' => ['horizon', true],
    'queue:work' => ['queue:work', true],
    'package:discover (instalação: sem ruído)' => ['package:discover', false],
]);

it('não derruba `package:discover` sem .env — o caminho do composer install', function (): void {
    // Sem `.env` a aplicação se considera em produção, com mailer `log`. A
    // recusa mora no ENVIO, então instalar dependências continua passando.
    $resultado = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'APP_KEY' => '', 'MAIL_MAILER' => 'log'])
        ->run('php artisan package:discover --no-ansi');

    expect($resultado->exitCode())->toBe(0);
});
