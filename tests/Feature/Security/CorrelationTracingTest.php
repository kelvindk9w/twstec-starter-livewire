<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Tracing\Models\OutboundHttpLog;
use Twstec\Kit\Foundation\Tracing\Support\OutboundHttpLogTrigger;

// =============================================================================
// Rastreio pelo correlation_id no projeto (twstec/kit-foundation, config
// tracing.php): a chamada HTTP de saída feita numa requisição leva o id dela
// e deixa a linha na trilha só-acréscimo — também no PostgreSQL, onde o
// gatilho recusa UPDATE/DELETE/TRUNCATE por SQL cru.
// =============================================================================

it('a chamada HTTP de saída feita numa requisição leva o id dela e grava a linha da trilha', function (): void {
    Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);

    Route::middleware('web')->get('/_tracing-probe', function () {
        Http::withToken('segredo-que-nao-pode-vazar')->get('https://api.example.test/v1/status?token=segredo-que-nao-pode-vazar');

        return response()->noContent();
    });

    $id = $this->get('/_tracing-probe')->assertNoContent()->headers->get('X-Correlation-Id');

    Http::assertSent(fn (HttpRequest $request): bool => $request->header('X-Correlation-Id') === [$id]);

    $log = OutboundHttpLog::query()->sole();

    expect($log->correlation_id)->toBe($id)
        ->and($log->host)->toBe('api.example.test')
        ->and($log->path)->toBe('/v1/status')
        ->and($log->query_keys)->toBe(['token'])
        ->and(json_encode(DB::table('outbound_http_logs')->get()))->not->toContain('segredo-que-nao-pode-vazar');
});

it('no PostgreSQL, o gatilho da trilha de saída recusa UPDATE/DELETE/TRUNCATE por SQL cru', function (): void {
    Http::fake();
    Http::get('https://api.example.test/v1/status');

    expect(OutboundHttpLogTrigger::installed())->toBeTrue();

    foreach ([
        fn () => DB::table('outbound_http_logs')->update(['http_status' => 1]),
        fn () => DB::table('outbound_http_logs')->delete(),
        fn () => DB::statement('TRUNCATE outbound_http_logs'),
    ] as $tentativa) {
        try {
            DB::transaction(fn () => $tentativa());
            $this->fail('o banco aceitou uma escrita na trilha append-only');
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('TWS_OUTBOUND_HTTP_APPEND_ONLY');
        }
    }

    expect(OutboundHttpLog::query()->count())->toBe(1);

    // A poda continua sendo a única porta.
    DB::table('outbound_http_logs')->insertUsing(
        ['uuid', 'correlation_id', 'method', 'host', 'path', 'duration_ms', 'created_at'],
        DB::table('outbound_http_logs')->selectRaw("gen_random_uuid(), correlation_id, method, host, '/velho', duration_ms, now() - interval '400 days'"),
    );

    $this->artisan('outbound-http:prune', ['--days' => 90])->assertSuccessful();

    expect(OutboundHttpLog::query()->pluck('path')->all())->toBe(['/v1/status']);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'gatilho só existe no PostgreSQL');
