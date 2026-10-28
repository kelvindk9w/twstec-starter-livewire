<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
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

// =============================================================================
// A RETOMADA NÃO VENCE UMA CONCLUSÃO QUE CHEGA NO MEIO (PostgreSQL de verdade).
//
// A execução A (modo `transactional`) passou do prazo. A tentativa B lê a
// linha (ainda "em processamento", prazo vencido: retomável). Nesse instante
// — entre a leitura e o UPDATE de B, a janela da corrida, aberta aqui por um
// ouvinte da consulta — A marca a chave concluída DENTRO da transação dela,
// e a linha fica travada até o commit. O UPDATE da retomada de B espera a
// trava; A confirma; o PostgreSQL reavalia o UPDATE de B contra a versão
// confirmada. Como a condição exige "em processamento com prazo vencido" (não
// só o dono), B perde, relê e recebe a conclusão — nunca uma segunda
// execução.
// =============================================================================

it('a retomada que espera a trava da dona perde quando ela confirma a conclusão (sem segunda execução)', function (): void {
    $schema = 'idem_takeover';
    $base = config('database.connections.pgsql');
    config()->set('database.connections.idem_takeover', [...$base, 'search_path' => $schema]);

    DB::connection('idem_takeover')->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    DB::connection('idem_takeover')->statement("CREATE SCHEMA {$schema}");

    $default = DB::getDefaultConnection();
    $tmp = sys_get_temp_dir().'/idem-takeover-'.bin2hex(random_bytes(4));
    $scope = hash('sha256', 'escopo-da-corrida');
    $key = hash('sha256', 'chave-da-corrida');
    $corpo = hash('sha256', 'o-mesmo-corpo');

    try {
        Artisan::call('migrate', ['--database' => 'idem_takeover', '--force' => true]);
        config()->set('idempotency.connection', 'idem_takeover');

        $store = app(IdempotencyStore::class);

        // A execução A: adquiriu e passou do prazo da retomada.
        $a = $store->acquire($scope, $key, $corpo, 'POST', 'api/v1/pedidos', null, 120);
        DB::connection('idem_takeover')->table(IdempotencyStore::TABLE)->update(['locked_until' => now()->subSecond()]);
        DB::purge('idem_takeover');

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork falhou');
        }

        if ($pid === 0) {
            // FILHO = a execução A: conclui dentro da transação, segura a
            // trava um pouco e confirma.
            $herdada = DB::connection($default)->getPdo();
            DB::purge($default);

            try {
                $limite = microtime(true) + 10;

                while (! file_exists("{$tmp}-leu") && microtime(true) < $limite) {
                    usleep(1000);
                }

                DB::connection('idem_takeover')->transaction(function () use ($store, $scope, $key, $a, $tmp): void {
                    $store->complete($scope, $key, (string) $a->owner, 201, 'resposta-de-a', false);
                    file_put_contents("{$tmp}-travada", '1');
                    usleep(1_500_000);
                });
            } finally {
                posix_kill(getmypid(), SIGKILL);
            }
        }

        // PAI = a tentativa B. Logo depois de ela LER a linha, A conclui e
        // trava; só então B segue para o UPDATE da retomada.
        $leu = false;
        DB::connection('idem_takeover')->listen(function (QueryExecuted $query) use (&$leu, $tmp): void {
            if ($leu || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, IdempotencyStore::TABLE)) {
                return;
            }

            $leu = true;
            touch("{$tmp}-leu");
            $limite = microtime(true) + 10;

            while (! file_exists("{$tmp}-travada") && microtime(true) < $limite) {
                usleep(1000);
            }
        });

        $b = $store->acquire($scope, $key, $corpo, 'POST', 'api/v1/pedidos', null, 120);

        pcntl_waitpid($pid, $status);

        $linha = DB::connection('idem_takeover')->table(IdempotencyStore::TABLE)->first();

        expect($leu)->toBeTrue()
            ->and(file_exists("{$tmp}-travada"))->toBeTrue()
            ->and($b->owner)->toBeNull()
            ->and($b->existing?->completed())->toBeTrue()
            ->and($linha->status)->toBe(IdempotencyStore::COMPLETED)
            ->and($linha->owner)->toBe($a->owner)
            ->and($linha->response)->toBe('resposta-de-a');
    } finally {
        config()->set('idempotency.connection', null);
        DB::connection('idem_takeover')->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        DB::purge('idem_takeover');

        foreach (glob("{$tmp}-*") ?: [] as $file) {
            @unlink($file);
        }
    }
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Concorrência real: PostgreSQL e pcntl (rode com -c phpunit.pgsql.xml — o CI roda).',
);
