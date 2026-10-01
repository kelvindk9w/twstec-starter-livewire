<?php

declare(strict_types=1);

use App\Livewire\Account\Show as AccountShow;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Deletion\Contracts\DeletionCheck;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Deletion\DeletionRequest;
use Twstec\Kit\Admin\Approvals\ApprovalService;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Admin\Resources\Uploads\Pages\ListUploads;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Resources\Users\Support\DeleteUserApproval;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Classification\UploadClassification;
use Twstec\Kit\Uploads\Confidential\ConfidentialAccess;
use Twstec\Kit\Uploads\Erasure\UploadEraser;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Retention\LegalHold;
use Twstec\Kit\Uploads\Services\SecureUploadService;

// =============================================================================
// UPLOADS CONFIDENCIAIS, RETENÇÃO LEGAL e IMPEDIMENTOS DE EXCLUSÃO no
// aplicativo inteiro (starter), no SQLite e no PostgreSQL:
//
// - pela API: o objeto no armazenamento é cifrado; a URL da resposta entrega
//   o original, com a trilha;
// - pelo /admin: abrir o confidencial fica na trilha com o contexto `admin`;
//   excluir a pessoa com upload sob guarda: a pessoa sai, o arquivo fica
//   (desvinculado), a recusa de apagar fica na trilha;
// - tabela do APLICATIVO com chave estrangeira RESTRICT de verdade (a lei
//   manda guardar o registro): excluir a conta pela página da conta e a
//   pessoa pelo /admin dão recusa limpa, nada apagado pela metade — nunca o
//   erro bruto do banco; declarado como impedimento, a recusa vem antes de
//   pedir o código.
// =============================================================================

/**
 * O verificador do exemplo da documentação: registros que a lei manda
 * guardar e que apontam para a conta.
 */
final class RegistrosGuardadosDaConta implements DeletionCheck
{
    public function impediments(DeletionRequest $request): iterable
    {
        $total = DB::table('registros_guardados')->whereIn('account_id', $request->accountIds())->count();

        return $total === 0 ? [] : [new DeletionImpediment('retained_records', "Há {$total} registro(s) que a lei manda guardar.")];
    }
}

function criaRegistrosGuardados(): void
{
    Schema::create('registros_guardados', function (Blueprint $tabela): void {
        $tabela->id();
        $tabela->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
        $tabela->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
    });
}

function codigoDeExclusao(): string
{
    $codigo = null;

    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codigo): bool {
        $codigo = $mail->code;

        return true;
    });

    return (string) $codigo;
}

beforeEach(function (): void {
    Storage::fake('uploads-test');
    config()->set('uploads.disk', 'uploads-test');
    config()->set('uploads.confidential.key', 'base64:'.base64_encode(random_bytes(32)));

    $this->ana = User::factory()->withTransactionPassword()->create(['name' => 'Ana Confidencial']);
});

it('API: o confidencial vai CIFRADO para o armazenamento; a URL da resposta entrega o original, com a trilha', function (): void {
    config()->set('uploads.classification.default', 'confidential');
    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave($this->ana, ['name' => 'Integração']);

    $resposta = $this->withHeaders(headersApi($chave, $segredo))
        ->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'contrato.pdf')], ['Accept' => 'application/json'])
        ->assertCreated();

    $upload = comoSistema(fn (): Upload => Upload::query()->where('uuid', $resposta->json('data.uuid'))->sole());
    $cru = (string) Storage::disk('uploads-test')->get((string) $upload->path);

    expect($upload->classification)->toBe(UploadClassification::Confidential)
        ->and($cru)->not->toContain('%PDF')
        ->and($cru)->not->toContain('/Type/Catalog')
        ->and((string) $resposta->json('data.url'))->toContain('/uploads/confidential/');

    $entregue = $this->get((string) $resposta->json('data.url'))->assertOk();

    expect($entregue->streamedContent())->toBe(fixtureBytesPdf())
        ->and(AuditEvent::query()->where('action', ConfidentialAccess::ISSUED)->sole()->context->value)->toBe('api')
        ->and(AuditEvent::query()->where('action', ConfidentialAccess::VIEWED)->sole()->actor_uuid)->toBe((string) $this->ana->uuid);
});

it('/admin: abrir o confidencial fica na trilha com o contexto `admin` e o operador', function (): void {
    $upload = Accounts::actingAs(contaPessoal($this->ana), fn (): Upload => app(SecureUploadService::class)->handle(
        fixtureArquivoEnviado(fixtureBytesPdf(), 'rg.pdf'),
        classification: UploadClassification::Confidential,
    ), $this->ana);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);
    app(CurrentAccount::class)->push(CurrentAccount::systemFrame('teste do /admin (Livewire::test)'));

    $tela = Livewire::test(ListUploads::class)->callAction(TestAction::make('openConfidential')->table($upload));

    app(CurrentAccount::class)->reset();

    expect($this->get((string) $tela->effects['redirect'])->assertOk()->streamedContent())->toBe(fixtureBytesPdf());

    foreach ([ConfidentialAccess::ISSUED, ConfidentialAccess::VIEWED] as $acao) {
        $linha = AuditEvent::query()->where('action', $acao)->sole();

        expect($linha->context->value)->toBe('admin')
            ->and($linha->actor_uuid)->toBe((string) $admin->uuid)
            ->and($linha->tenant_uuid)->toBe((string) contaPessoal($this->ana)->uuid);
    }
})->group('admin');

it('/admin: excluir a pessoa com upload SOB GUARDA — ela sai, o arquivo fica desvinculado, a recusa de apagar está na trilha', function (): void {
    $guardado = Accounts::actingAs(contaPessoal($this->ana), fn (): Upload => app(SecureUploadService::class)->handle(
        fixtureArquivoEnviado(fixtureBytesPdf(), 'contrato.pdf'),
        classification: UploadClassification::Confidential,
    ), $this->ana);
    $comum = Accounts::actingAs(contaPessoal($this->ana), fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), 'rascunho.pdf')), $this->ana);

    Accounts::actingAs(contaPessoal($this->ana), fn () => app(LegalHold::class)->place($guardado, now()->addYears(5), 'Contrato: guarda de 5 anos'), $this->ana);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);
    app(CurrentAccount::class)->push(CurrentAccount::systemFrame('teste do /admin (Livewire::test)'));

    Livewire::test(ListUsers::class)->callAction(TestAction::make('delete')->table($this->ana));

    $ficou = comoSistema(fn (): ?Upload => Upload::query()->whereKey($guardado->id)->first());

    expect(User::query()->whereKey($this->ana->id)->exists())->toBeFalse()
        ->and($ficou)->not->toBeNull()
        ->and($ficou->isDetached())->toBeTrue()
        ->and($ficou->account_id)->toBeNull()
        ->and(comoSistema(fn (): bool => Upload::query()->whereKey($comum->id)->exists()))->toBeFalse();

    Storage::disk('uploads-test')->assertExists((string) $ficou->path);
    Storage::disk('uploads-test')->assertMissing((string) $comum->path);

    $recusa = AuditEvent::query()->where('action', UploadEraser::REFUSED_ACTION)->sole();

    expect($recusa->outcome)->toBe(AuditOutcome::Denied)
        ->and($recusa->context->value)->toBe('admin')
        ->and($recusa->subject_uuid)->toBe((string) $guardado->uuid)
        ->and($recusa->reason)->toContain('Contrato: guarda de 5 anos');

    // Vencido o prazo, o comando agendado apaga.
    $this->travelTo(now()->addYears(5)->addDay());
    $this->artisan('uploads:erase-expired-holds')->assertSuccessful();

    expect(comoSistema(fn (): bool => Upload::query()->whereKey($guardado->id)->exists()))->toBeFalse();
    Storage::disk('uploads-test')->assertMissing((string) $ficou->path);
})->group('admin');

it('RESTRICT do aplicativo que ninguém declarou: excluir a CONTA pela página dá recusa limpa — nada apagado, recusa na trilha', function (): void {
    criaRegistrosGuardados();
    $empresa = app(AccountService::class)->createAccount('Empresa com registros', $this->ana);
    DB::table('registros_guardados')->insert(['account_id' => $empresa->id]);
    Mail::fake();

    $this->actingAs($this->ana);
    session()->put(CurrentAccount::sessionKey(), (string) $empresa->uuid);

    $tela = Livewire::actingAs($this->ana)->test(AccountShow::class)
        ->call('requestDelete')
        ->assertSet('pendingAction', 'delete')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->assertHasNoErrors();

    $tela->set('sensitiveCode', codigoDeExclusao())
        ->call('confirmSensitiveAction')
        ->assertHasErrors('deleteAccount')
        ->assertNoRedirect();

    // (a mensagem tem ":" — comparada direto, não como regra)
    expect($tela->errors()->first('deleteAccount'))->toBe(__('accounts.deletion.referenced'));

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and($empresa->memberships()->count())->toBe(1)
        ->and(DB::table('registros_guardados')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'account.deleted')->where('tenant_uuid', $empresa->uuid)->pluck('outcome')->map->value->all())
        ->toBe(['denied']);
});

it('declarado como IMPEDIMENTO: a recusa vem ANTES de pedir o código, com a mensagem do aplicativo junto do botão', function (): void {
    criaRegistrosGuardados();
    config()->set('accounts.deletion.checks', [RegistrosGuardadosDaConta::class]);

    $empresa = app(AccountService::class)->createAccount('Empresa com registros', $this->ana);
    DB::table('registros_guardados')->insert(['account_id' => $empresa->id]);

    $this->actingAs($this->ana);
    session()->put(CurrentAccount::sessionKey(), (string) $empresa->uuid);

    Livewire::actingAs($this->ana)->test(AccountShow::class)
        ->call('requestDelete')
        ->assertHasErrors(['deleteAccount' => 'Há 1 registro(s) que a lei manda guardar.'])
        ->assertSet('pendingAction', null);

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'account.deleted')->where('outcome', AuditOutcome::Denied)->sole()->reason)
        ->toBe('Há 1 registro(s) que a lei manda guardar.');
});

it('RESTRICT do aplicativo apontando para a PESSOA: excluir pelo /admin dá recusa limpa, a pessoa e as contas ficam', function (): void {
    criaRegistrosGuardados();
    DB::table('registros_guardados')->insert(['account_id' => contaPessoal($this->ana)->id, 'user_id' => $this->ana->id]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);
    app(CurrentAccount::class)->push(CurrentAccount::systemFrame('teste do /admin (Livewire::test)'));

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('delete')->table($this->ana))
        ->assertNotified(__('admin.users.action_denied'));

    expect(User::query()->whereKey($this->ana->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey(contaPessoal($this->ana)->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->sole()->reason)
        ->toBe(__('accounts.deletion.referenced'));
})->group('admin');

it('APROVAÇÃO EM DOIS PASSOS: o pedido aprovado de excluir pessoa referenciada por RESTRICT do aplicativo fica `failed` com a mensagem traduzida — nada sai', function (): void {
    criaRegistrosGuardados();
    DB::table('registros_guardados')->insert(['account_id' => contaPessoal($this->ana)->id, 'user_id' => $this->ana->id]);
    config()->set('admin.approvals.actions', [DeleteUserApproval::KEY]);
    Mail::fake();

    $pede = User::factory()->withTransactionPassword()->create(['is_admin' => true, 'admin_role' => 'owner']);
    $aprova = User::factory()->withTransactionPassword()->create(['is_admin' => true, 'admin_role' => 'owner']);

    $pedido = app(ApprovalService::class)->request(DeleteUserApproval::KEY, $this->ana, [], 'Pedido do titular', $pede);

    app(SensitiveActionService::class)->sendCode($aprova, 'Trans4cao!Segura');
    $token = app(SensitiveActionService::class)->confirmCode($aprova, codigoDeExclusao())['token'];

    $resultado = app(ApprovalService::class)->approve($pedido, $aprova, $token);

    expect($resultado->status)->toBe(ApprovalStatus::Failed)
        ->and($resultado->failure_reason)->toBe(__('accounts.deletion.referenced'))
        ->and(User::query()->whereKey($this->ana->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey(contaPessoal($this->ana)->id)->exists())->toBeTrue()
        ->and(DB::table('registros_guardados')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->sole()->reason)
        ->toBe(__('accounts.deletion.referenced'));
})->group('admin');
