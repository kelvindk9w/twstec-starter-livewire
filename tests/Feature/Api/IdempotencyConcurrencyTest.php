<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;

// =============================================================================
// IDEMPOTENCY-KEY SOB CONCORRÊNCIA DE VERDADE (PostgreSQL).
//
// Duas requisições com a MESMA chave, ao mesmo tempo, em dois PROCESSOS
// separados (pcntl_fork), cada um com a própria conexão ao banco, pela pilha
// HTTP inteira (autenticação por chave de API, limite, middleware
// `idempotent`). A rota de teste grava uma linha por execução e demora meio
// segundo (pg_sleep) — tempo de sobra para as duas se sobreporem.
//
// Esperado: UMA execução. O INSERT "em processamento" com ON CONFLICT DO
// NOTHING sobre a unicidade (escopo, chave) deixa só um processo passar; o
// outro recebe 409 `idempotency_request_in_progress` — ou, com a espera curta
// ligada (idempotency.wait_ms), o replay da resposta do primeiro.
//
// ISOLAMENTO: o teste roda numa SCHEMA própria do banco de teste
// (`idem_conc`, migrada do zero aqui e apagada no fim), porque os processos
// filhos só enxergam dado CONFIRMADO — e a conexão normal da suíte vive dentro
// da transação do RefreshDatabase. Nada fica no banco da suíte.
//
// Os filhos herdam a conexão do pai: não podem fechá-la (o encerramento iria
// pelo mesmo socket e derrubaria a conexão do pai). Por isso guardam a
// referência e saem com SIGKILL, sem destrutores.
// =============================================================================

it('duas requisições com a mesma chave AO MESMO TEMPO, em processos separados, executam UMA vez', function (int $waitMs): void {
    $schema = 'idem_conc';
    $base = config('database.connections.pgsql');
    config()->set('database.connections.idem_conc', [...$base, 'search_path' => $schema]);
    config()->set('idempotency.wait_ms', $waitMs);

    DB::connection('idem_conc')->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    DB::connection('idem_conc')->statement("CREATE SCHEMA {$schema}");

    $default = DB::getDefaultConnection();
    $tmp = sys_get_temp_dir().'/idem-conc-'.bin2hex(random_bytes(4));

    try {
        Artisan::call('migrate', ['--database' => 'idem_conc', '--force' => true]);

        Schema::connection('idem_conc')->create('idem_conc_pedidos', function ($table): void {
            $table->id();
            $table->string('item');
            $table->integer('pid');
        });

        // Uma linha por execução, e meio segundo de trabalho.
        Route::middleware(['api', 'resolve.tenant', 'idempotent'])->post('/api/v1/_teste/pedidos-lentos', function (Request $request) {
            DB::select('SELECT pg_sleep(0.5)');
            $id = DB::table('idem_conc_pedidos')->insertGetId(['item' => (string) $request->input('item'), 'pid' => getmypid()]);

            return response()->json(['data' => ['id' => $id, 'nonce' => bin2hex(random_bytes(8))]], 201);
        });

        // Pessoa, conta e chave CONFIRMADAS na schema isolada.
        DB::setDefaultConnection('idem_conc');
        ['api_key' => $key, 'secret_key' => $secret] = criarChave(User::factory()->create());
        $headers = [...headersApi($key, $secret), 'Idempotency-Key' => 'pedido-concorrente-0001-abcdef'];

        DB::setDefaultConnection($default);
        DB::purge('idem_conc');

        $start = microtime(true) + 1.0;
        $pids = [];

        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork falhou');
            }

            if ($pid === 0) {
                // FILHO: não fecha a conexão herdada (guarda a referência).
                $herdada = DB::connection($default)->getPdo();
                DB::purge($default);
                DB::setDefaultConnection('idem_conc');

                try {
                    while (microtime(true) < $start) {
                        usleep(1000);
                    }

                    $response = $this->postJson('/api/v1/_teste/pedidos-lentos', ['item' => 'caderno'], $headers);
                    $result = ['status' => $response->status(), 'body' => $response->getContent(), 'replayed' => $response->headers->get('Idempotent-Replayed')];
                } catch (Throwable $exception) {
                    $result = ['status' => 0, 'body' => $exception::class.': '.$exception->getMessage(), 'replayed' => null];
                }

                file_put_contents("{$tmp}-{$i}", json_encode($result));

                // Sai sem destrutores: a conexão herdada ($herdada) continua
                // aberta para o pai.
                posix_kill(getmypid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        /** @var list<array{status: int, body: string, replayed: string|null}> $results */
        $results = [json_decode((string) file_get_contents("{$tmp}-0"), true), json_decode((string) file_get_contents("{$tmp}-1"), true)];
        usort($results, fn (array $a, array $b): int => [$a['status'], $a['replayed']] <=> [$b['status'], $b['replayed']]);

        $conc = DB::connection('idem_conc');

        expect($conc->table('idem_conc_pedidos')->count())->toBe(1)
            ->and($conc->table(IdempotencyStore::TABLE)->count())->toBe(1)
            ->and($conc->table(IdempotencyStore::TABLE)->value('status'))->toBe(IdempotencyStore::COMPLETED);

        if ($waitMs === 0) {
            // Sem espera: um executa, o outro recebe 409 "em processamento".
            expect($results[0]['status'])->toBe(201)
                ->and($results[0]['replayed'])->toBeNull()
                ->and($results[1]['status'])->toBe(409)
                ->and(json_decode($results[1]['body'], true)['error']['code'] ?? null)->toBe('idempotency_request_in_progress');
        } else {
            // Com a espera curta: o segundo espera o primeiro e recebe o replay.
            expect(array_column($results, 'status'))->toBe([201, 201])
                ->and($results[0]['replayed'])->toBeNull()
                ->and($results[1]['replayed'])->toBe('true')
                ->and($results[1]['body'])->toBe($results[0]['body']);
        }
    } finally {
        DB::setDefaultConnection($default);
        DB::connection('idem_conc')->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        DB::purge('idem_conc');

        foreach (glob("{$tmp}-*") ?: [] as $file) {
            @unlink($file);
        }
    }
})->with([
    'sem espera (409)' => [0],
    'com espera curta (replay)' => [3000],
])->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Concorrência real: PostgreSQL e pcntl (rode com -c phpunit.pgsql.xml — o CI roda). A unicidade (escopo, chave) com ON CONFLICT DO NOTHING é o que este teste prova.',
);
