<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Webhooks\Inbound\Contracts\InboundWebhookHandler;
use Twstec\Kit\Webhooks\Inbound\InboundEvent;
use Twstec\Kit\Webhooks\Inbound\InboundSource;
use Twstec\Kit\Webhooks\Inbound\InboundWebhooks;
use Twstec\Kit\Webhooks\Inbound\Verifiers\SharedSecretHeader;

// =============================================================================
// WEBHOOK RECEBIDO REPETIDO AO MESMO TEMPO (PostgreSQL de verdade).
//
// O provedor (ou quem repete uma requisição capturada) manda o MESMO evento
// quatro vezes ao mesmo tempo, em quatro PROCESSOS separados (pcntl_fork),
// cada um com a própria conexão ao banco, pela pilha HTTP inteira (proxies,
// limite de borda, filtro de ataques, trilha, limite da rota). A fila é a
// `sync` da suíte: o handler roda dentro da requisição e demora meio segundo
// (pg_sleep) — tempo de sobra para as outras chegarem no meio.
//
// Esperado: o handler roda UMA vez; um recebe 202, os outros 200
// ("duplicate"); um registro só. Quem decide é o INSERT … ON CONFLICT DO
// NOTHING sobre UNIQUE (source, event_id) — nunca "consulta e depois insere".
//
// ISOLAMENTO: schema própria (`inbound_conc`, migrada do zero e apagada no
// fim), porque os filhos só enxergam dado CONFIRMADO e a conexão da suíte
// vive na transação do RefreshDatabase. Os filhos herdam a conexão do pai:
// guardam a referência e saem com SIGKILL, sem destrutores.
// =============================================================================

it('o mesmo evento recebido quatro vezes AO MESMO TEMPO, em processos separados, é processado UMA vez', function (): void {
    $schema = 'inbound_conc';
    $base = config('database.connections.pgsql');
    config()->set('database.connections.inbound_conc', [...$base, 'search_path' => $schema]);

    DB::connection('inbound_conc')->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    DB::connection('inbound_conc')->statement("CREATE SCHEMA {$schema}");

    $default = DB::getDefaultConnection();
    $tmp = sys_get_temp_dir().'/inbound-conc-'.bin2hex(random_bytes(4));
    $token = 'tok_concorrencia_'.bin2hex(random_bytes(8));

    try {
        Artisan::call('migrate', ['--database' => 'inbound_conc', '--force' => true]);

        Schema::connection('inbound_conc')->create('inbound_conc_execucoes', function ($table): void {
            $table->id();
            $table->string('event_id');
            $table->integer('pid');
        });

        InboundWebhooks::register(new InboundSource(
            name: 'concorrencia',
            verifier: new SharedSecretHeader('x-token', [$token]),
            handler: new class implements InboundWebhookHandler
            {
                public function handle(InboundEvent $event): void
                {
                    DB::select('SELECT pg_sleep(0.5)');
                    DB::table('inbound_conc_execucoes')->insert(['event_id' => $event->id, 'pid' => getmypid()]);
                }
            },
        ));

        // Os filhos abrem conexões PRÓPRIAS: nenhuma conexão com a schema
        // isolada pode ser herdada aberta do pai (o mesmo socket em dois
        // processos corrompe o protocolo).
        DB::purge('inbound_conc');

        $body = (string) json_encode(['id' => 'evt_concorrente_1', 'type' => 'pagamento.recebido', 'valor' => 1990]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TOKEN' => $token];

        $start = microtime(true) + 1.0;
        $pids = [];

        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork falhou');
            }

            if ($pid === 0) {
                // FILHO: não fecha a conexão herdada (guarda a referência).
                $herdada = DB::connection($default)->getPdo();
                DB::purge($default);
                DB::setDefaultConnection('inbound_conc');

                try {
                    while (microtime(true) < $start) {
                        usleep(1000);
                    }

                    $response = $this->call('POST', '/webhooks/inbound/concorrencia', [], [], [], $server, $body);
                    $result = ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
                } catch (Throwable $exception) {
                    $result = ['status' => 0, 'body' => $exception::class.': '.$exception->getMessage()];
                }

                file_put_contents("{$tmp}-{$i}", json_encode($result));

                posix_kill(getmypid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        for ($i = 0; $i < 4; $i++) {
            $results[] = json_decode((string) file_get_contents("{$tmp}-{$i}"), true);
        }

        $statuses = array_column($results, 'status');
        sort($statuses);

        $conc = DB::connection('inbound_conc');

        expect($statuses)->toBe([200, 200, 200, 202], json_encode($results))
            ->and(collect($results)->where('status', 200)->pluck('body')->unique()->values()->all())->toBe(['{"status":"duplicate"}'])
            ->and($conc->table('inbound_conc_execucoes')->count())->toBe(1)
            ->and($conc->table('webhook_inbound_events')->count())->toBe(1)
            ->and($conc->table('webhook_inbound_events')->value('status'))->toBe('processed');
    } finally {
        DB::setDefaultConnection($default);
        DB::connection('inbound_conc')->statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        DB::purge('inbound_conc');

        foreach (glob("{$tmp}-*") ?: [] as $file) {
            @unlink($file);
        }
    }
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Concorrência real: PostgreSQL e pcntl (rode com -c phpunit.pgsql.xml — o CI roda). A unicidade (source, event_id) com ON CONFLICT DO NOTHING é o que este teste prova.',
);
