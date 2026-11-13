<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\NullHandler;
use Twstec\Kit\Foundation\Logging\RequestLogChannel;

// =============================================================================
// A SUÍTE NÃO GRAVA EM storage/logs.
//
// O phpunit.xml manda o canal padrão (laravel.log) para `null` e liga o
// descarte do `request_log` do kit (REQUEST_LOG_DISCARD). Sem isso, cada
// rodada da suíte — duas por verificação — gravava dezenas de MB por dia no
// disco de quem desenvolve. O evento de log continua disparado: quem
// verifica log (Log::spy, Log::listen) segue vendo cada linha.
// =============================================================================

/**
 * Os arquivos de storage/logs que contêm o texto.
 *
 * @return list<string>
 */
function logFilesContaining(string $needle): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(storage_path('logs'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
            $found[] = $file->getPathname();
        }
    }

    return $found;
}

it('nos testes, o canal padrão e o request_log descartam: nenhum handler grava arquivo', function (): void {
    expect(RequestLogChannel::discards())->toBeTrue()
        // LOG_CHANNEL=null chega como nulo (o env() do Laravel converte), e
        // nos testes o LogManager usa o canal `null` nesse caso.
        ->and(config('logging.default'))->toBeNull()
        ->and(config('logging.channels.request_log'))->toBe(RequestLogChannel::discardDefinition());

    foreach ([Log::driver(), Log::channel(RequestLogChannel::NAME)] as $channel) {
        $handlers = $channel->getLogger()->getHandlers();

        expect($handlers)->not->toBeEmpty();

        foreach ($handlers as $handler) {
            expect($handler)->toBeInstanceOf(NullHandler::class);
        }
    }
});

it('uma requisição e linhas em todos os níveis não chegam a nenhum arquivo de storage/logs — e o evento de log continua saindo', function (): void {
    $marker = 'marca-do-teste-'.Str::uuid();
    $seen = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$seen, $marker): void {
        if (str_contains($event->message, $marker)) {
            $seen[] = $event->level;
        }
    });

    $this->get('/?'.$marker.'=1')->assertOk();
    Log::info($marker);
    Log::error($marker);
    Log::channel(RequestLogChannel::NAME)->warning($marker, ['marker' => $marker]);
    Log::channel(RequestLogChannel::NAME)->critical($marker);

    expect(logFilesContaining($marker))->toBe([])
        ->and($seen)->toBe(['info', 'error', 'warning', 'critical']);
});
