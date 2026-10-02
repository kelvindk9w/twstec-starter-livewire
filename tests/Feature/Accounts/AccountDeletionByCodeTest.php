<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Deletion\AccountDeletion;
use Twstec\Kit\Accounts\Deletion\DeletionImpediments;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionOutsideServiceException;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// EXCLUSÃO CHAMADA POR CÓDIGO (job, comando, serviço do aplicativo) pelo
// caminho único do twstec/kit-accounts (Deletion\AccountDeletion), com chave
// estrangeira RESTRICT DE VERDADE — no SQLite e no PostgreSQL:
//
// - a tabela do aplicativo aponta para a pessoa ou para a conta e ninguém a
//   declarou como impedimento: recusa limpa (nunca o erro bruto do banco),
//   nada apagado pela metade, UMA linha `denied` na trilha — mesmo com o job
//   desfazendo a transação em volta;
// - `delete()` direto na conta: recusado antes de qualquer linha sair.
//
// Nada é declarado no topo do arquivo além de `use` (sem o módulo de contas,
// o arquivo é carregado e o grupo `accounts` pula — tests/TestCase.php).
// =============================================================================

beforeEach(function (): void {
    Schema::create('registros_do_app_por_codigo', function (Blueprint $tabela): void {
        $tabela->id();
        $tabela->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
        $tabela->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
    });

    $this->ana = User::factory()->create(['name' => 'Ana Por Código']);
    $this->pessoal = app(AccountService::class)->personalAccountOf($this->ana);
});

it('PESSOA referenciada por RESTRICT do aplicativo: recusa limpa, a pessoa e a conta pessoal ficam, a recusa na trilha', function (): void {
    DB::table('registros_do_app_por_codigo')->insert(['user_id' => $this->ana->id]);

    try {
        app(AccountDeletion::class)->deleteUser($this->ana);
        $this->fail('A exclusão deveria ter sido recusada.');
    } catch (DeletionImpededException $exception) {
        expect($exception->codes())->toBe([DeletionImpediments::REFERENCED])
            ->and($exception->getMessage())->toBe(__('accounts.deletion.referenced'));
    }

    expect(User::query()->whereKey($this->ana->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($this->pessoal->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->sole()->reason)
        ->toBe(__('accounts.deletion.referenced'));
});

it('CONTA referenciada por RESTRICT do aplicativo, num job que desfaz a transação em volta: recusa limpa, nada sai, UMA recusa na trilha', function (): void {
    $empresa = app(AccountService::class)->createAccount('Empresa por código', $this->ana);
    DB::table('registros_do_app_por_codigo')->insert(['account_id' => $empresa->id]);

    expect(fn () => DB::transaction(fn () => app(AccountDeletion::class)->deleteAccount($empresa)))
        ->toThrow(DeletionImpededException::class, __('accounts.deletion.referenced'));

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and($empresa->memberships()->count())->toBe(1)
        ->and(DB::table('registros_do_app_por_codigo')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'account.deleted')->where('tenant_uuid', $empresa->uuid)->pluck('outcome')->map->value->all())
        ->toBe(['denied']);
});

it('sem referência, a conta sai pelo serviço; `delete()` direto na conta é recusado antes de qualquer linha sair', function (): void {
    $direta = app(AccountService::class)->createAccount('Empresa apagada direto', $this->ana);
    $peloServico = app(AccountService::class)->createAccount('Empresa apagada pelo serviço', $this->ana);

    expect(fn () => $direta->delete())->toThrow(DeletionOutsideServiceException::class);

    app(AccountDeletion::class)->deleteAccount($peloServico, $this->ana);

    expect(Account::query()->whereKey($direta->id)->exists())->toBeTrue()
        ->and($direta->memberships()->count())->toBe(1)
        ->and(Account::query()->whereKey($peloServico->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'account.deleted')->where('tenant_uuid', $direta->uuid)->sole()->outcome)->toBe(AuditOutcome::Denied)
        ->and(AuditEvent::query()->where('action', 'account.deleted')->where('tenant_uuid', $peloServico->uuid)->sole()->outcome)->toBe(AuditOutcome::Success);
});
