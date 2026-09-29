<?php

declare(strict_types=1);

use App\Livewire\ApiKeys\Index as ApiKeysIndex;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\LivewireManager;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// Telas de CHAVES DE API e de PROJETOS: toda recusa fica na trilha.
//
// Requisito do dono: tudo que é tentado no painel fica no banco, inclusive as
// recusas. As telas recusam como antes — o mesmo 403 de papel, o mesmo 404 de
// chave/projeto que não está na conta atual, o mesmo erro de validação do
// projeto de fora da conta no vínculo — e cada tentativa grava `denied` com a
// ação tentada, quem, a conta e o alvo. Quem pode passa sem linha nenhuma.
// =============================================================================

/**
 * Empresa com dona (com senha de transação) e member; uma chave e um projeto
 * da empresa.
 *
 * @return array{empresa: Account, dona: User, membro: User, chave: ApiKey, projeto: Project}
 */
function empresaDaTrilha(): array
{
    $dona = User::factory()->withTransactionPassword()->create();
    $membro = User::factory()->withTransactionPassword()->create();
    $empresa = app(AccountService::class)->createAccount('Empresa Trilha', $dona);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);
    ['api_key' => $chave] = criarChave($dona, ['name' => 'Da empresa'], $empresa);
    $projeto = Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);

    return ['empresa' => $empresa, 'dona' => $dona, 'membro' => $membro, 'chave' => $chave, 'projeto' => $projeto];
}

function naTelaDa(User $pessoa, Account $conta): LivewireManager
{
    session()->put('accounts.current', $conta->uuid);

    return Livewire::actingAs($pessoa);
}

/**
 * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: string}>
 */
function recusasNaTrilha(): array
{
    return AuditEvent::query()->orderBy('id')->get()
        ->map(fn (AuditEvent $e): array => [$e->action, $e->outcome->value, $e->context->value, $e->subject_uuid, (string) $e->reason])
        ->all();
}

it('member: cada recusa de papel nas telas de chaves e projetos dá o mesmo 403 e grava denied', function (): void {
    ['empresa' => $empresa, 'membro' => $membro, 'chave' => $chave, 'projeto' => $projeto] = empresaDaTrilha();
    $k = (string) $chave->uuid;
    $p = (string) $projeto->uuid;

    naTelaDa($membro, $empresa)->test(ApiKeysIndex::class)->call('startCreate')->assertForbidden();
    naTelaDa($membro, $empresa)->test(ApiKeysIndex::class)->set('name', 'Forjada')->call('requestCreate')->assertForbidden();
    naTelaDa($membro, $empresa)->test(ApiKeysIndex::class)->call('startRotate', $k)->assertForbidden();
    naTelaDa($membro, $empresa)->test(ApiKeysIndex::class)->set('rotatingKeyUuid', $k)->call('requestRotate')->assertForbidden();
    naTelaDa($membro, $empresa)->test(ApiKeysIndex::class)->call('startRevoke', $k)->assertForbidden();
    naTelaDa($membro, $empresa)->test(ApiKeysIndex::class)->set('revokingKeyUuid', $k)->call('revoke')->assertForbidden();
    naTelaDa($membro, $empresa)->test(ApiKeysIndex::class)->call('startEditProjects', $k)->assertForbidden();
    naTelaDa($membro, $empresa)->test(ApiKeysIndex::class)->set('editingProjectsKeyUuid', $k)->call('saveProjects')->assertForbidden();
    naTelaDa($membro, $empresa)->test(ProjectsIndex::class)->call('startDelete', $p)->assertForbidden();
    naTelaDa($membro, $empresa)->test(ProjectsIndex::class)->set('confirmingDeleteUuid', $p)->call('removeProject')->assertForbidden();

    $negado = __('accounts.authorization.denied');

    expect(recusasNaTrilha())->toBe([
        ['api_key.created', 'denied', 'panel', null, $negado],
        ['api_key.created', 'denied', 'panel', null, $negado],
        ['api_key.rotated', 'denied', 'panel', $k, $negado],
        ['api_key.rotated', 'denied', 'panel', $k, $negado],
        ['api_key.revoked', 'denied', 'panel', $k, $negado],
        ['api_key.revoked', 'denied', 'panel', $k, $negado],
        ['api_key.projects_synced', 'denied', 'panel', $k, $negado],
        ['api_key.projects_synced', 'denied', 'panel', $k, $negado],
        ['project.deleted', 'denied', 'panel', $p, $negado],
        ['project.deleted', 'denied', 'panel', $p, $negado],
    ]);

    // Quem tentou e em que conta, em todas; e nada mudou.
    expect(AuditEvent::query()->distinct()->pluck('actor_uuid')->all())->toBe([(string) $membro->uuid])
        ->and(AuditEvent::query()->distinct()->pluck('tenant_uuid')->all())->toBe([(string) $empresa->uuid])
        ->and(comoSistema(fn () => ApiKey::query()->count()))->toBe(1)
        ->and(comoSistema(fn () => $chave->fresh()->status))->toBe(ApiKeyStatus::Active)
        ->and(comoSistema(fn () => Project::query()->whereKey($projeto->id)->exists()))->toBeTrue();
})->group('accounts');

it('chave e projeto fora da conta atual: o mesmo 404 (de outra conta ou inexistente) e cada tentativa grava denied', function (): void {
    ['empresa' => $empresa, 'dona' => $dona] = empresaDaTrilha();
    $outra = User::factory()->create();
    ['api_key' => $alheia] = criarChave($outra, ['name' => 'Alheia']);
    $alheio = projetoDe($outra, 'Alheio');
    $k = (string) $alheia->uuid;
    $p = (string) $alheio->uuid;
    $nada = (string) Str::uuid();

    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)->call('startRotate', $k)->assertNotFound();
    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)->call('startRevoke', $k)->assertNotFound();
    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)->set('revokingKeyUuid', $k)->call('revoke')->assertNotFound();
    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)->call('startEditProjects', $k)->assertNotFound();
    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)->set('editingProjectsKeyUuid', $k)->call('saveProjects')->assertNotFound();
    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)->call('startRevoke', $nada)->assertNotFound();
    naTelaDa($dona, $empresa)->test(ProjectsIndex::class)->call('startEdit', $p)->assertNotFound();
    naTelaDa($dona, $empresa)->test(ProjectsIndex::class)->set('editingUuid', $p)->set('editingName', 'Tomado')->call('update')->assertNotFound();
    naTelaDa($dona, $empresa)->test(ProjectsIndex::class)->call('startDelete', $p)->assertNotFound();
    naTelaDa($dona, $empresa)->test(ProjectsIndex::class)->set('confirmingDeleteUuid', $p)->call('removeProject')->assertNotFound();
    naTelaDa($dona, $empresa)->test(ProjectsIndex::class)->call('startDelete', $nada)->assertNotFound();
    naTelaDa($dona, $empresa)->test(ProjectsIndex::class)->call('startDelete', 'nao-e-uuid')->assertNotFound();

    $fora = __('accounts.authorization.not_found');

    expect(recusasNaTrilha())->toBe([
        ['api_key.rotated', 'denied', 'panel', $k, $fora],
        ['api_key.revoked', 'denied', 'panel', $k, $fora],
        ['api_key.revoked', 'denied', 'panel', $k, $fora],
        ['api_key.projects_synced', 'denied', 'panel', $k, $fora],
        ['api_key.projects_synced', 'denied', 'panel', $k, $fora],
        ['api_key.revoked', 'denied', 'panel', $nada, $fora],
        ['project.updated', 'denied', 'panel', $p, $fora],
        ['project.updated', 'denied', 'panel', $p, $fora],
        ['project.deleted', 'denied', 'panel', $p, $fora],
        ['project.deleted', 'denied', 'panel', $p, $fora],
        ['project.deleted', 'denied', 'panel', $nada, $fora],
        ['project.deleted', 'denied', 'panel', null, $fora],
    ]);

    expect(AuditEvent::query()->distinct()->pluck('actor_uuid')->all())->toBe([(string) $dona->uuid])
        ->and(AuditEvent::query()->distinct()->pluck('tenant_uuid')->all())->toBe([(string) $empresa->uuid])
        ->and(comoSistema(fn () => $alheia->fresh()->status))->toBe(ApiKeyStatus::Active)
        ->and(comoSistema(fn () => $alheio->fresh()->name))->toBe('Alheio');
})->group('accounts');

it('projeto de outra conta no vínculo e na criação da chave: o mesmo erro de validação e a tentativa grava denied', function (): void {
    ['empresa' => $empresa, 'dona' => $dona, 'chave' => $chave] = empresaDaTrilha();
    $outra = User::factory()->create();
    $alheio = projetoDe($outra, 'Alheio');

    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)
        ->set('editingProjectsKeyUuid', $chave->uuid)
        ->set('editingProjectsSelection', [$alheio->uuid])
        ->call('saveProjects')
        ->assertHasErrors(['editingProjectsSelection']);

    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)
        ->call('startCreate')
        ->set('name', 'Com projeto alheio')
        ->set('selectedProjectUuids', [$alheio->uuid])
        ->call('requestCreate')
        ->assertHasErrors(['selectedProjectUuids'])
        ->assertSet('pendingAction', null);

    $invalido = __('api_keys.projects.invalid');

    expect(recusasNaTrilha())->toBe([
        ['api_key.projects_synced', 'denied', 'panel', (string) $chave->uuid, $invalido],
        ['api_key.created', 'denied', 'panel', null, $invalido],
    ])
        ->and(comoSistema(fn () => $chave->fresh()->projects()->count()))->toBe(0)
        ->and(comoSistema(fn () => ApiKey::query()->count()))->toBe(1);
})->group('accounts');

it('quem pode passa sem linha nenhuma na trilha', function (): void {
    ['empresa' => $empresa, 'dona' => $dona, 'membro' => $membro, 'chave' => $chave, 'projeto' => $projeto] = empresaDaTrilha();

    naTelaDa($dona, $empresa)->test(ApiKeysIndex::class)
        ->call('startCreate')->assertOk()
        ->call('startRotate', $chave->uuid)->assertOk()
        ->call('startRevoke', $chave->uuid)->assertOk()
        ->call('startEditProjects', $chave->uuid)->assertOk()
        ->set('editingProjectsSelection', [$projeto->uuid])
        ->call('saveProjects')->assertHasNoErrors();

    naTelaDa($dona, $empresa)->test(ProjectsIndex::class)
        ->call('startEdit', $projeto->uuid)->assertOk()
        ->call('startDelete', $projeto->uuid)->assertOk();

    // O member cria e edita projeto (o papel permite).
    naTelaDa($membro, $empresa)->test(ProjectsIndex::class)
        ->call('startCreate')->assertOk()
        ->call('startEdit', $projeto->uuid)->assertOk();

    expect(AuditEvent::query()->count())->toBe(0);
})->group('accounts');
