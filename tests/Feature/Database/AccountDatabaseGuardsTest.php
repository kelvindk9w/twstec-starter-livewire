<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountDatabaseGuards;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

// =============================================================================
// A REGRA DO DONO NO PRÓPRIO BANCO (PostgreSQL) — o que passa por fora do
// Eloquent: `DB::table`, DELETE em massa, psql.
//
// - exatamente um dono: segundo dono (índice), dono rebaixado, vínculo que
//   muda de conta e conta que termina a transação sem dono — recusados;
// - o dono não sai de conta com outros membros — nem apagando a PESSOA por
//   fora do model;
// - a saída do dono de conta sem outros membros apaga a conta com os dados;
// - vínculo chave ↔ projeto só dentro da mesma conta.
// =============================================================================

function recusadoPeloBancoDeContas(Closure $operacao): mixed
{
    return DB::transaction($operacao);
}

/**
 * @return array{dona: User, membro: User, empresa: Account}
 */
function empresaNoBanco(): array
{
    $dona = User::factory()->create();
    $membro = User::factory()->create();
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);

    return ['dona' => $dona, 'membro' => $membro, 'empresa' => $empresa];
}

it('segundo dono por fora do Eloquent: o índice único parcial recusa (nos dois bancos)', function (): void {
    ['membro' => $membro, 'empresa' => $empresa] = empresaNoBanco();

    expect(fn () => recusadoPeloBancoDeContas(fn () => DB::table('account_memberships')
        ->where('account_id', $empresa->id)->where('user_id', $membro->id)->update(['role' => 'owner'])))
        ->toThrow(QueryException::class);

    expect(DB::table('account_memberships')->where('account_id', $empresa->id)->where('role', 'owner')->count())->toBe(1);
});

describe('gatilhos das contas (PostgreSQL)', function (): void {
    it('estão instalados pela migration', function (): void {
        expect(AccountDatabaseGuards::installed())->toBeTrue();
    });

    it('o dono não é rebaixado e o vínculo não muda de conta, nem por SQL', function (): void {
        ['dona' => $dona, 'empresa' => $empresa] = empresaNoBanco();

        expect(fn () => recusadoPeloBancoDeContas(fn () => DB::table('account_memberships')
            ->where('account_id', $empresa->id)->where('user_id', $dona->id)->update(['role' => 'admin'])))
            ->toThrow(QueryException::class, AccountDatabaseGuards::ERROR_PREFIX);

        expect(fn () => recusadoPeloBancoDeContas(fn () => DB::table('account_memberships')
            ->where('account_id', $empresa->id)->where('user_id', $dona->id)->update(['account_id' => contaPessoal($dona)->id])))
            ->toThrow(QueryException::class, AccountDatabaseGuards::ERROR_PREFIX);

        expect($empresa->fresh()->owner?->is($dona))->toBeTrue();
    });

    it('o dono não sai de conta com outros membros — nem apagando a pessoa por fora do model', function (): void {
        ['dona' => $dona, 'empresa' => $empresa] = empresaNoBanco();

        expect(fn () => recusadoPeloBancoDeContas(fn () => DB::table('account_memberships')
            ->where('account_id', $empresa->id)->where('user_id', $dona->id)->delete()))
            ->toThrow(QueryException::class, AccountDatabaseGuards::ERROR_PREFIX);

        expect(fn () => recusadoPeloBancoDeContas(fn () => DB::table('users')->where('id', $dona->id)->delete()))
            ->toThrow(QueryException::class, AccountDatabaseGuards::ERROR_PREFIX);

        expect(User::query()->whereKey($dona->id)->exists())->toBeTrue()
            ->and($empresa->fresh())->not->toBeNull()
            ->and(comoSistema(fn () => Project::query()->where('account_id', $empresa->id)->count()))->toBe(1);
    });

    it('pessoa sem conta compartilhada apagada por SQL: a conta pessoal sai com os dados, na mesma sentença', function (): void {
        $pessoa = User::factory()->create();
        $conta = contaPessoal($pessoa);
        projetoDe($pessoa, 'Meu');
        criarChave($pessoa);

        DB::table('users')->where('id', $pessoa->id)->delete();

        expect(DB::table('accounts')->where('id', $conta->id)->exists())->toBeFalse()
            ->and(DB::table('account_memberships')->where('account_id', $conta->id)->count())->toBe(0)
            ->and(comoSistema(fn () => Project::query()->where('account_id', $conta->id)->count()))->toBe(0)
            ->and(comoSistema(fn () => ApiKey::query()->where('account_id', $conta->id)->count()))->toBe(0);
    });

    it('conta que termina a transação sem dono é recusada', function (): void {
        expect(fn () => recusadoPeloBancoDeContas(function (): void {
            DB::table('accounts')->insert([
                'uuid' => (string) Str::uuid7(), 'codigo_publico' => 'ACC-SEMDON', 'name' => 'Sem dono',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // Fim da transação (o gatilho é adiado para o commit).
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->toThrow(QueryException::class, AccountDatabaseGuards::ERROR_PREFIX);

        expect(DB::table('accounts')->where('codigo_publico', 'ACC-SEMDON')->exists())->toBeFalse();
    });

    it('vínculo chave ↔ projeto de contas diferentes é recusado pelo banco', function (): void {
        $ana = User::factory()->create();
        $bruno = User::factory()->create();
        $projetoDoBruno = projetoDe($bruno, 'Do Bruno');
        $chaveDaAna = criarChave($ana)['api_key'];

        expect(fn () => recusadoPeloBancoDeContas(fn () => DB::table('api_key_project')->insert([
            'api_key_id' => $chaveDaAna->id, 'project_id' => $projetoDoBruno->id, 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, AccountDatabaseGuards::ERROR_PREFIX);

        expect(DB::table('api_key_project')->count())->toBe(0);
    });
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'Os gatilhos das contas são do PostgreSQL; rode com -c phpunit.pgsql.xml (o CI roda). No SQLite a regra fica no código (AccountMembership, AccountService).',
);
