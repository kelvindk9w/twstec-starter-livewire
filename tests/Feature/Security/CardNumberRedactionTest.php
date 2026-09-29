<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Twstec\Kit\Demo\Contact\Mail\ContactMessageMail;
use Twstec\Kit\Demo\Showcase\Models\FormSubmission;
use Twstec\Kit\Demo\Showcase\Support\FormSubmissionGuard;
use Twstec\Kit\Foundation\Logging\CardNumberMaskingFormatter;
use Twstec\Kit\Foundation\Logging\MaskCardNumbersInLogs;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Support\Platform;

// Número de cartão (PAN) nunca é persistido em claro (PCI DSS req. 3): nem
// na trilha (request_logs), nem em form_submissions, nem no e-mail/fila do
// contato, nem em storage/logs. Só os 4 últimos dígitos sobrevivem.

function contactWithCard(): array
{
    return [
        'name' => 'Maria Silva',
        'email' => 'maria@example.com',
        'subject' => 'other',
        'message' => 'Meu cartão é 4111 1111 1111 1111, podem estornar?',
    ];
}

it('grava o payload do request log com o cartão mascarado', function () {
    Mail::fake();

    $this->post(route('contact.store'), contactWithCard())->assertRedirect();

    $log = RequestLog::query()->where('method', 'POST')->sole();

    expect($log->payload['message'])->toBe('Meu cartão é **** **** **** 1111, podem estornar?')
        ->and(json_encode($log->payload))->not->toContain('4111 1111 1111 1111');
})->group('demo');

it('grava a submissão do contato com o cartão mascarado e o resto do texto intacto', function () {
    Mail::fake();

    $this->post(route('contact.store'), contactWithCard())->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->message)->toBe('Meu cartão é **** **** **** 1111, podem estornar?')
        ->and($submission->sender_email)->toBe('maria@example.com');
})->group('demo');

it('o e-mail enfileirado do contato não carrega o cartão', function () {
    Mail::fake();
    config()->set('platform.contact_email', 'contato@example.com');
    app()->forgetInstance(Platform::class);

    $this->post(route('contact.store'), contactWithCard())->assertRedirect();

    Mail::assertQueued(
        ContactMessageMail::class,
        fn (ContactMessageMail $mail): bool => $mail->messageText === 'Meu cartão é **** **** **** 1111, podem estornar?',
    );
})->group('demo');

it('a tentativa de ataque grava o cartão mascarado na trilha, nos dois modos', function (string $mode, int $status) {
    config()->set('security.validation.mode', $mode);

    $this->post(route('contact.store'), [
        ...contactWithCard(),
        'message' => '4111111111111111 <script>alert(1)</script>',
    ])->assertStatus($status);

    $log = RequestLog::query()->whereNotNull('attack_type')->sole();

    expect(json_encode($log->payload))->toContain('************1111')
        ->not->toContain('4111111111111111');
})->group('demo')->with([
    'block (BLOQUEADA, 422)' => ['block', 422],
    'observe (segue, redirect)' => ['observe', 302],
]);

it('a detecção de ataque do formulário vê o texto original (mascarar não esconde ataque)', function () {
    $submission = app(FormSubmissionGuard::class)->submit(
        origin: FormSubmission::ORIGIN_CLASSIC,
        nickname: 'cartão 5555555555554444',
        subject: 'assunto 4111 1111 1111 1111',
        message: '4111111111111111 <script>alert(1)</script>',
        honeypot: null,
    );

    expect($submission->isBlocked())->toBeTrue()
        ->and($submission->nickname)->toBe('cartão ************4444')
        ->and($submission->subject)->toBe('assunto **** **** **** 1111')
        ->and($submission->message)->toBe('************1111 <script>alert(1)</script>');
})->group('demo');

it('mascara o cartão no log de arquivo, na mensagem, no contexto e na exceção', function (string $channel) {
    $path = storage_path('framework/testing/pan-'.$channel.'-'.uniqid().'.log');
    config()->set("logging.channels.{$channel}.path", $path);
    Log::forgetChannel($channel);

    Log::channel($channel)->error('falha ao cobrar 5555555555554444', [
        'input' => 'cartão 4111 1111 1111 1111',
        'exception' => new RuntimeException('insert values (4111-1111-1111-1111)'),
    ]);

    // O canal diário grava com a data no nome do arquivo.
    $files = glob(str_replace('.log', '*.log', $path)) ?: [];
    $written = implode('', array_map('file_get_contents', $files));
    array_map('unlink', $files);
    Log::forgetChannel($channel);

    expect($written)->toContain('************4444')
        ->toContain('**** **** **** 1111')
        ->toContain('****-****-****-1111')
        ->not->toContain('5555555555554444')
        ->not->toContain('4111 1111 1111 1111')
        ->not->toContain('4111-1111-1111-1111');
})->with(['single', 'request_log']);

it('o JSON do request_log continua válido depois da máscara', function () {
    $path = storage_path('framework/testing/pan-json-'.uniqid().'.log');
    config()->set('logging.channels.request_log.path', $path);
    Log::forgetChannel('request_log');

    Log::channel('request_log')->info('request.started', ['nota' => 'cartão 4111111111111111']);

    $files = glob(str_replace('.log', '*.log', $path)) ?: [];
    $line = trim((string) file_get_contents($files[0]));
    array_map('unlink', $files);
    Log::forgetChannel('request_log');

    expect(json_decode($line, true)['context']['nota'] ?? null)->toBe('cartão ************1111');
});

it('todo canal de log que escreve texto tem o tap de mascaramento', function (string $channel) {
    expect(config("logging.channels.{$channel}.tap"))->toContain(MaskCardNumbersInLogs::class);
})->with(['single', 'daily', 'monthly', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog', 'request_log']);

it('o tap não envolve o mesmo formatter duas vezes', function () {
    $logger = Log::channel('single');

    app(MaskCardNumbersInLogs::class)($logger);
    app(MaskCardNumbersInLogs::class)($logger);

    $formatter = $logger->getLogger()->getHandlers()[0]->getFormatter();

    expect($formatter)->toBeInstanceOf(CardNumberMaskingFormatter::class)
        ->and($formatter->inner())->not->toBeInstanceOf(CardNumberMaskingFormatter::class);
});
