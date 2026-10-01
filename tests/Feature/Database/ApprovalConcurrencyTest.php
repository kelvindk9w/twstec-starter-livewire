<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Admin\Approvals\ApprovableAction;
use Twstec\Kit\Admin\Approvals\Approvals;
use Twstec\Kit\Admin\Approvals\ApprovalService;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;

// =============================================================================
// APROVAÇÃO EM DOIS PASSOS SOB CONCORRÊNCIA DE VERDADE (PostgreSQL).
//
// Duas pessoas aprovam o MESMO pedido ao mesmo tempo, em dois PROCESSOS
// separados (pcntl_fork), cada um com a própria conexão ao banco. A ação
// registrada para o teste grava uma linha por execução e demora meio segundo
// (pg_sleep) — tempo de sobra para as duas aprovações se sobreporem.
//
// Esperado: UMA execução. A primeira trava o pedido (SELECT ... FOR UPDATE),
// executa e confirma; a segunda espera a trava e encontra o pedido já
// decidido — recusa registrada na trilha.
//
// ISOLAMENTO: o teste roda numa SCHEMA própria do banco de teste
// (`approval_conc`, migrada do zero aqui e apagada no fim), porque os
// processos filhos só enxergam dado CONFIRMADO — e a conexão normal da suíte
// vive dentro da transação do RefreshDatabase. Nada fica no banco da suíte.
//
// Os filhos herdam a conexão do pai: não podem fechá-la (o encerramento iria
// pelo mesmo socket e derrubaria a conexão do pai). Por isso guardam a
// referência e saem com SIGKILL, sem destrutores.
// =============================================================================

const APPROVAL_CONC_SCHEMA = 'approval_conc';

function approvalConcRun(string $file, Closure $work): void
{
    try {
        $result = $work();
    } catch (RecordedDenial $denial) {
        $result = 'denied: '.$denial->getMessage();
    } catch (Throwable $exception) {
        $result = 'error: '.$exception::class.': '.$exception->getMessage();
    }

    file_put_contents($file, (string) $result);
}

it('duas aprovações AO MESMO TEMPO, em processos separados, executam a ação UMA vez', function (): void {
    $base = config('database.connections.pgsql');
    config()->set('database.connections.approval_conc', [...$base, 'search_path' => APPROVAL_CONC_SCHEMA]);

    DB::connection('approval_conc')->statement('DROP SCHEMA IF EXISTS '.APPROVAL_CONC_SCHEMA.' CASCADE');
    DB::connection('approval_conc')->statement('CREATE SCHEMA '.APPROVAL_CONC_SCHEMA);

    $default = DB::getDefaultConnection();
    $tmp = sys_get_temp_dir().'/approval-conc-'.bin2hex(random_bytes(4));

    try {
        Artisan::call('migrate', ['--database' => 'approval_conc', '--force' => true]);

        Schema::connection('approval_conc')->create('approval_concurrency_hits', function ($table): void {
            $table->id();
            $table->string('request_uuid');
            $table->integer('pid');
        });

        // A ação de teste: uma linha por execução, e meio segundo dentro da
        // transação (as duas aprovações se sobrepõem).
        Approvals::register(new class extends ApprovableAction
        {
            public function key(): string
            {
                return 'users.concurrency_probe';
            }

            public function subjectModel(): string
            {
                return User::class;
            }

            public function fingerprint(Model $subject, array $data): array
            {
                return ['uuid' => $subject->getAttribute('uuid')];
            }

            public function execute(Model $subject, array $data, Authenticatable $actor): void
            {
                DB::select('SELECT pg_sleep(0.5)');
                DB::table('approval_concurrency_hits')->insert(['request_uuid' => (string) $subject->getAttribute('uuid'), 'pid' => getmypid()]);
            }
        });

        // Dados CONFIRMADOS na schema isolada (fora da transação da suíte).
        DB::setDefaultConnection('approval_conc');

        $quemPede = User::factory()->create(['is_admin' => true]);
        $aprovadores = [User::factory()->create(['is_admin' => true]), User::factory()->create(['is_admin' => true])];
        $alvo = User::factory()->create();

        $pedido = app(ApprovalService::class)->request('users.concurrency_probe', $alvo, [], 'Prova de concorrência', $quemPede);

        // Um token de ação sensível válido para cada aprovador (só o hash no banco).
        $tokens = [];
        foreach ($aprovadores as $i => $aprovador) {
            $tokens[$i] = 'token-concorrencia-'.$i.'-'.bin2hex(random_bytes(16));
            DB::table('sensitive_action_tokens')->insert([
                'user_id' => $aprovador->id,
                'token_hash' => hash('sha256', $tokens[$i]),
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::setDefaultConnection($default);
        // O pai fecha a conexão DELE com a schema antes de abrir os filhos.
        DB::purge('approval_conc');

        $start = microtime(true) + 1.0;
        $pids = [];

        foreach ($aprovadores as $i => $aprovador) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork falhou');
            }

            if ($pid === 0) {
                // FILHO: não fecha a conexão herdada (guarda a referência).
                $herdada = DB::connection($default)->getPdo();
                DB::purge($default);
                DB::setDefaultConnection('approval_conc');

                approvalConcRun("{$tmp}-{$i}", function () use ($aprovador, $pedido, $tokens, $i, $start): string {
                    $ator = User::query()->findOrFail($aprovador->id);

                    while (microtime(true) < $start) {
                        usleep(1000);
                    }

                    return app(ApprovalService::class)->approve($pedido->uuid, $ator, $tokens[$i])->status->value;
                });

                // Sai sem destrutores: a conexão herdada ($herdada) continua
                // aberta para o pai.
                posix_kill(getmypid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $resultados = [file_get_contents("{$tmp}-0"), file_get_contents("{$tmp}-1")];
        sort($resultados);

        $conc = DB::connection('approval_conc');

        expect($resultados[0])->toBe('denied: '.__('admin.approvals.already_decided'))
            ->and($resultados[1])->toBe(ApprovalStatus::Executed->value)
            ->and($conc->table('approval_concurrency_hits')->count())->toBe(1)
            ->and($conc->table('admin_approval_requests')->where('uuid', $pedido->uuid)->value('status'))->toBe('executed')
            ->and($conc->table('audit_events')->where('action', 'approval_request.executed')->where('outcome', 'success')->count())->toBe(1)
            ->and($conc->table('audit_events')->where('action', 'approval_request.approved')->where('outcome', 'success')->count())->toBe(1)
            ->and($conc->table('audit_events')->where('action', 'approval_request.approved')->where('outcome', 'denied')->count())->toBe(1);
    } finally {
        DB::setDefaultConnection($default);
        DB::connection('approval_conc')->statement('DROP SCHEMA IF EXISTS '.APPROVAL_CONC_SCHEMA.' CASCADE');
        DB::purge('approval_conc');

        foreach (glob("{$tmp}-*") ?: [] as $file) {
            @unlink($file);
        }
    }
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Concorrência real: PostgreSQL e pcntl (rode com -c phpunit.pgsql.xml — o CI roda). A trava do pedido (SELECT ... FOR UPDATE) é o que este teste prova.',
);
