<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Support\AccountDatabaseGuards;

// =============================================================================
// MIGRAÇÃO 1.x → CONTAS no esquema COMPLETO do starter (SQLite e PostgreSQL —
// no PostgreSQL, com os gatilhos das contas), com dados sintéticos: várias
// pessoas, projetos, chaves (ativas, revogadas, restritas), vínculos e a
// trilha (request_logs e audit_events) — que NÃO é reescrita.
//
// Ida e volta: up → down → up. A prova no banco de desenvolvimento e o tempo
// com volume estão no relatório da fase.
// =============================================================================

function migracaoDoPacote(string $arquivo): Migration
{
    return require base_path('vendor/twstec/kit-accounts/database/migrations/'.$arquivo);
}

/**
 * As duas migrations das contas, a dos convites e a dos uploads da conta
 * (as duas últimas dependem de `accounts` por chave estrangeira: no
 * PostgreSQL elas saem antes e voltam depois).
 *
 * @return array{criar: Migration, mover: Migration, convites: Migration, uploads: Migration}
 */
function migracoesDeContas(): array
{
    return [
        'criar' => migracaoDoPacote('2026_09_26_000001_create_accounts_tables.php'),
        'mover' => migracaoDoPacote('2026_09_26_000002_move_projects_and_api_keys_to_accounts.php'),
        'convites' => migracaoDoPacote('2026_09_27_000001_create_account_invitations_table.php'),
        'uploads' => require base_path('vendor/twstec/kit-uploads/database/migrations/2026_09_28_000001_move_uploads_to_accounts.php'),
    ];
}

/**
 * @return array<string, mixed>
 */
function retratoDaVersaoUm(): array
{
    return [
        'projects' => DB::table('projects')->orderBy('id')->get(['id', 'uuid', 'user_id', 'name', 'status'])->map(fn ($r) => (array) $r)->all(),
        'keys' => DB::table('api_keys')->orderBy('id')->get(['id', 'uuid', 'user_id', 'public_key', 'secret_hash', 'status', 'restricted_to_projects'])->map(fn ($r) => (array) $r)->all(),
        'links' => DB::table('api_key_project')->orderBy('id')->get(['api_key_id', 'project_id'])->map(fn ($r) => (array) $r)->all(),
        'trilha' => retratoDaTrilha(),
    ];
}

/**
 * @return list<list<array<string, mixed>>>
 */
function retratoDaTrilha(): array
{
    return [
        DB::table('request_logs')->orderBy('id')->get(['id', 'tenant_uuid'])->map(fn ($r) => (array) $r)->all(),
        DB::table('audit_events')->orderBy('id')->get(['id', 'actor_uuid', 'subject_uuid'])->map(fn ($r) => (array) $r)->all(),
    ];
}

it('no esquema do starter: conta pessoal com o mesmo id e uuid, dados movidos, trilha intacta — e ida e volta', function (): void {
    ['criar' => $criar, 'mover' => $mover, 'convites' => $convites, 'uploads' => $uploads] = migracoesDeContas();

    $pessoas = User::factory()->count(6)->create();

    $uploads->down();
    $convites->down();
    $mover->down();
    $criar->down();

    expect(Schema::hasTable('accounts'))->toBeFalse()
        ->and(AccountDatabaseGuards::installed())->toBeFalse();

    // Dados como a 1.x gravava.
    $agora = now();

    foreach ($pessoas->values() as $i => $pessoa) {
        foreach (range(0, $i % 3) as $p) {
            DB::table('projects')->insert([
                'uuid' => (string) Str::uuid7(), 'codigo_publico' => 'PRJ-'.strtoupper(Str::random(6)),
                'user_id' => $pessoa->id, 'name' => "P{$i}.{$p}", 'status' => 'active',
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        foreach (['active', 'revoked'] as $k => $status) {
            DB::table('api_keys')->insert([
                'uuid' => (string) Str::uuid7(), 'codigo_publico' => 'KEY-'.strtoupper(Str::random(6)),
                'user_id' => $pessoa->id, 'name' => "K{$i}.{$k}", 'public_key' => 'pk_test_'.Str::random(32),
                'secret_hash' => hash('sha256', Str::random()), 'scopes' => json_encode(['*:*']),
                'restricted_to_projects' => $k === 0 && $i % 2 === 0, 'status' => $status,
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        $chaveRestrita = DB::table('api_keys')->where('user_id', $pessoa->id)->where('restricted_to_projects', true)->value('id');

        if ($chaveRestrita !== null) {
            DB::table('api_key_project')->insert([
                'api_key_id' => $chaveRestrita,
                'project_id' => DB::table('projects')->where('user_id', $pessoa->id)->value('id'),
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        DB::table('request_logs')->insert([
            'uuid' => (string) Str::uuid7(), 'correlation_id' => (string) Str::uuid7(), 'tenant_uuid' => $pessoa->uuid,
            'ip' => '127.0.0.1', 'method' => 'GET', 'endpoint' => '/api/v1/projects', 'status' => 'concluida',
            'http_status_response' => 200, 'created_at' => $agora,
        ]);
    }

    $antes = retratoDaVersaoUm();

    expect(count($antes['projects']))->toBe(12)
        ->and(count($antes['keys']))->toBe(12)
        ->and(count($antes['links']))->toBe(3);

    $criar->up();
    $mover->up();

    $conferir = function () use ($pessoas, $antes): void {
        expect(Schema::hasColumn('projects', 'user_id'))->toBeFalse()
            ->and(Schema::hasColumn('api_keys', 'user_id'))->toBeFalse()
            ->and(DB::table('accounts')->count())->toBe($pessoas->count());

        foreach ($pessoas as $pessoa) {
            $conta = DB::table('accounts')->where('personal_user_id', $pessoa->id)->sole();

            expect((int) $conta->id)->toBe($pessoa->id)
                ->and($conta->uuid)->toBe($pessoa->uuid)
                ->and(DB::table('account_memberships')->where('account_id', $conta->id)->pluck('role', 'user_id')->all())->toBe([$pessoa->id => 'owner']);
        }

        foreach ($antes['projects'] as $p) {
            $linha = DB::table('projects')->where('id', $p['id'])->sole();
            expect([(int) $linha->account_id, (int) $linha->created_by])->toBe([(int) $p['user_id'], (int) $p['user_id']]);
        }

        foreach ($antes['keys'] as $k) {
            $linha = DB::table('api_keys')->where('id', $k['id'])->sole();
            expect([(int) $linha->account_id, (int) $linha->created_by, $linha->secret_hash, $linha->status, (bool) $linha->restricted_to_projects])
                ->toBe([(int) $k['user_id'], (int) $k['user_id'], $k['secret_hash'], $k['status'], (bool) $k['restricted_to_projects']]);
        }

        expect(DB::table('api_key_project')->orderBy('id')->get(['api_key_id', 'project_id'])->map(fn ($r) => (array) $r)->all())->toBe($antes['links'])
            // A trilha não é reescrita (e o tenant_uuid gravado é o uuid da conta pessoal).
            ->and(retratoDaTrilha())->toBe($antes['trilha']);

        foreach ($antes['trilha'][0] as $log) {
            expect(DB::table('accounts')->where('uuid', $log['tenant_uuid'])->exists())->toBeTrue();
        }

        expect(AccountDatabaseGuards::installed())->toBe(AccountDatabaseGuards::supported());
    };

    $conferir();

    // Volta: os dados da 1.x, exatamente.
    $mover->down();

    $depois = retratoDaVersaoUm();

    expect($depois['projects'])->toEqual($antes['projects'])
        ->and($depois['keys'])->toEqual($antes['keys'])
        ->and($depois['links'])->toBe($antes['links'])
        ->and($depois['trilha'])->toBe($antes['trilha']);

    $criar->down();
    $criar->up();
    $mover->up();
    $convites->up();
    $uploads->up();

    $conferir();
})->group('uploads');

it('pessoa criada depois da migração: conta pessoal nova não colide com as migradas (sequência ajustada)', function (): void {
    ['criar' => $criar, 'mover' => $mover, 'convites' => $convites, 'uploads' => $uploads] = migracoesDeContas();

    User::factory()->count(3)->create();
    $uploads->down();
    $convites->down();
    $mover->down();
    $criar->down();
    $criar->up();
    $mover->up();
    $convites->up();
    $uploads->up();

    $maiorAntes = (int) DB::table('accounts')->max('id');
    $nova = User::factory()->create();
    $conta = DB::table('accounts')->where('personal_user_id', $nova->id)->sole();

    expect((int) $conta->id)->toBeGreaterThan($maiorAntes)
        ->and($conta->uuid)->toBe($nova->uuid);
})->group('uploads');
