<?php

declare(strict_types=1);

use App\Livewire\Account\Show as AccountShow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Avatar\AvatarService;
use Twstec\Kit\Uploads\Erasure\UploadEraser;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;

// =============================================================================
// UPLOADS DA CONTA no aplicativo inteiro (starter), pelas rotas e telas de
// verdade:
//
// - web e API gravam do MESMO jeito: a conta atual e quem agiu;
// - a FOTO DE PERFIL é da pessoa: aparece em todas as contas dela (e para
//   quem divide uma conta com ela), e o caminho da foto nunca abre upload de
//   conta de empresa nem de outra pessoa;
// - LGPD: excluir a pessoa pelo /admin apaga, do banco e do DISCO, a foto
//   dela e os uploads da conta pessoal — os que ela criou na conta de outra
//   pessoa ficam; excluir a conta pela página da conta apaga os uploads dela.
// =============================================================================

beforeEach(function (): void {
    Storage::fake('uploads-test');
    config()->set('uploads.disk', 'uploads-test');

    $this->ana = User::factory()->withTransactionPassword()->create(['name' => 'Ana Uploads']);
    $this->bruno = User::factory()->withTransactionPassword()->create(['name' => 'Bruno Uploads']);
    $this->empresa = app(AccountService::class)->createAccount('Empresa dos Uploads', $this->bruno);
    app(AccountService::class)->addMember($this->empresa, $this->ana, AccountRole::Admin);
});

function enviaNaConta(Account $conta, User $quem, string $nome): Upload
{
    return Accounts::actingAs(
        $conta,
        fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), $nome)),
        $quem,
    );
}

it('web e API gravam igual: a conta atual e quem agiu (a foto de perfil é pessoal)', function (): void {
    // API: a chave da EMPRESA, criada pela Ana (admin de lá).
    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave($this->ana, ['name' => 'Integração'], $this->empresa);

    $pelaApi = $this->withHeaders(headersApi($chave, $segredo))
        ->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'nota.pdf')], ['Accept' => 'application/json'])
        ->assertCreated();

    // Web: a foto de perfil, com a empresa selecionada na sessão.
    $this->actingAs($this->ana)->withSession([CurrentAccount::sessionKey() => (string) $this->empresa->uuid])
        ->postJson('/settings/avatar', ['avatar' => fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png')])
        ->assertCreated();

    [$api, $foto] = comoSistema(fn (): array => [
        Upload::query()->where('uuid', $pelaApi->json('data.uuid'))->sole(),
        Upload::query()->whereKey($this->ana->fresh()->avatar_upload_id)->sole(),
    ]);

    expect([$api->account_id, $api->created_by, $api->personal])->toBe([$this->empresa->id, $this->ana->id, false])
        // A foto não vai para a empresa selecionada: é da pessoa.
        ->and([$foto->account_id, $foto->created_by, $foto->personal])->toBe([null, $this->ana->id, true]);
});

it('a foto de perfil aparece em TODAS as contas da pessoa e para quem divide a conta com ela', function (): void {
    $foto = app(AvatarService::class)->replace($this->ana, fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png'));
    $nomeDoArquivo = basename((string) $foto->path);

    // Na conta pessoal.
    $this->actingAs($this->ana)->get('/profile')->assertOk()->assertSee($nomeDoArquivo);

    // Na empresa (selecionada): a mesma foto.
    $this->actingAs($this->ana)->withSession([CurrentAccount::sessionKey() => (string) $this->empresa->uuid])
        ->get('/profile')->assertOk()->assertSee($nomeDoArquivo);

    // O Bruno, dono da empresa, vê a foto da Ana na lista de membros.
    $this->actingAs($this->bruno)->withSession([CurrentAccount::sessionKey() => (string) $this->empresa->uuid])
        ->get('/account')->assertOk()->assertSee($nomeDoArquivo);
});

it('o caminho da foto nunca abre upload de conta de empresa nem de outra pessoa', function (): void {
    $daEmpresa = enviaNaConta($this->empresa, $this->bruno, 'contrato.pdf');
    $doBruno = enviaNaConta(contaPessoal($this->bruno), $this->bruno, 'pessoal.pdf');
    $daAna = enviaNaConta(contaPessoal($this->ana), $this->ana, 'da-ana.pdf');

    // Forçado por fora (banco): a foto da Ana apontando para upload alheio.
    foreach ([$daEmpresa, $doBruno] as $alheio) {
        DB::table('users')->where('id', $this->ana->id)->update(['avatar_upload_id' => $alheio->id]);

        expect($this->ana->fresh()->avatarUrl())->toBeNull()
            ->and($this->ana->fresh()->avatarUpload())->toBeNull();

        $this->actingAs($this->ana)->get('/profile')->assertOk()->assertDontSee(basename((string) $alheio->path));
    }

    // Um upload da conta PESSOAL dela (escolhido como foto pelo /admin) vale.
    DB::table('users')->where('id', $this->ana->id)->update(['avatar_upload_id' => $daAna->id]);

    expect($this->ana->fresh()->avatarUrl())->toContain(basename((string) $daAna->path));
});

it('LGPD: excluída pelo /admin, a foto e os uploads da conta pessoal saem do banco e do DISCO; o que ela criou na empresa do Bruno fica', function (): void {
    $foto = app(AvatarService::class)->replace($this->ana, fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png'));
    $pessoal = enviaNaConta(contaPessoal($this->ana), $this->ana, 'pessoal.pdf');
    $naEmpresa = enviaNaConta($this->empresa, $this->ana, 'na-empresa.pdf');
    $doBruno = enviaNaConta(contaPessoal($this->bruno), $this->bruno, 'do-bruno.pdf');

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);
    app(CurrentAccount::class)->push(CurrentAccount::systemFrame('teste do /admin (Livewire::test)'));

    Livewire::test(ListUsers::class)->callTableAction('delete', $this->ana);

    expect(User::query()->whereKey($this->ana->id)->exists())->toBeFalse();

    foreach ([$foto, $pessoal] as $saiu) {
        expect(comoSistema(fn (): bool => Upload::query()->whereKey($saiu->id)->exists()))->toBeFalse();
        Storage::disk('uploads-test')->assertMissing((string) $saiu->path);
    }

    foreach ([$naEmpresa, $doBruno] as $ficou) {
        expect(comoSistema(fn (): bool => Upload::query()->whereKey($ficou->id)->exists()))->toBeTrue();
        Storage::disk('uploads-test')->assertExists((string) $ficou->path);
    }

    // Na trilha, pelo /admin: quem excluiu, quantos e por quê — sem caminho.
    $linha = AuditEvent::query()->where('action', UploadEraser::AUDIT_ACTION)->sole();

    expect($linha->context->value)->toBe('admin')
        ->and($linha->actor_uuid)->toBe($admin->uuid)
        ->and($linha->changes['uploads']['before'])->toBe(2)
        ->and(json_encode($linha->changes))->not->toContain('.pdf')
        ->and(AuditEvent::query()->where('action', 'upload.files_deleted')->exists())->toBeTrue();
})->group('admin');

it('LGPD: excluir a CONTA pela página da conta apaga os uploads dela (banco e disco)', function (): void {
    $daEmpresa = enviaNaConta($this->empresa, $this->ana, 'da-empresa.pdf');
    $daAna = enviaNaConta(contaPessoal($this->ana), $this->ana, 'da-ana.pdf');
    Mail::fake();

    $this->actingAs($this->bruno);
    session()->put(CurrentAccount::sessionKey(), (string) $this->empresa->uuid);

    $tela = Livewire::actingAs($this->bruno)->test(AccountShow::class)
        ->call('requestDelete')
        ->assertSet('pendingAction', 'delete')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->assertHasNoErrors();

    $codigo = null;
    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codigo): bool {
        $codigo = $mail->code;

        return true;
    });

    $tela->set('sensitiveCode', (string) $codigo)->call('confirmSensitiveAction')->assertRedirect(route('dashboard'));

    expect(Account::query()->whereKey($this->empresa->id)->exists())->toBeFalse()
        ->and(comoSistema(fn (): bool => Upload::query()->whereKey($daEmpresa->id)->exists()))->toBeFalse()
        ->and(comoSistema(fn (): bool => Upload::query()->whereKey($daAna->id)->exists()))->toBeTrue();

    Storage::disk('uploads-test')->assertMissing((string) $daEmpresa->path);
    Storage::disk('uploads-test')->assertExists((string) $daAna->path);

    expect(AuditEvent::query()->where('action', UploadEraser::AUDIT_ACTION)->sole()->tenant_uuid)->toBe((string) $this->empresa->uuid);
});
