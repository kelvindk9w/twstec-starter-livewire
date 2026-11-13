<?php

declare(strict_types=1);

use App\Livewire\ApiKeys\Index;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;
use Livewire\Livewire;
use Twstec\Kit\Accounts\ApiKeys\Confirmation\ApiKeyCreationConfirmation;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyHasher;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Models\SensitiveActionToken;

// =============================================================================
// Chaves de API pela UI (Livewire): a tela mais importante.
// Fluxo completo com ação sensível REAL (senha de transação + código por
// e-mail — o código é capturado do mailable via Mail::fake) consumindo o
// ApiKeyService/SensitiveActionService das Fases 3/4 — nada duplicado.
//
// A CRIAÇÃO segue a confirmação do ambiente das chaves
// (`api_keys.creation_confirmation`): em `live`, senha de transação + código;
// em `test` (o do .env.example), só o código. Cada teste diz o ambiente.
// =============================================================================

/**
 * Código de verificação mais recente "enviado" (capturado do mailable fake).
 */
function latestSentCode(): string
{
    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)->last();

    return $mail->code;
}

beforeEach(function () {
    Mail::fake();
    // Sem cooldown de reenvio nos testes (a regra em si é coberta em SensitiveActionTest).
    config()->set('auth.verification.resend_cooldown_seconds', 0);
});

it('exige autenticação (deny-by-default)', function () {
    $this->get('/api-keys')->assertRedirect(route('login'));
});

it('lista apenas as chaves do próprio usuário com status e último uso', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    criarChave($user, ['name' => 'Minha Integração']);
    criarChave($outro, ['name' => 'Chave Alheia']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertOk()
        ->assertSee('Minha Integração')
        ->assertSee(__('panel.api_keys.status_active'))
        ->assertSee(__('panel.common.never'))
        ->assertDontSee('Chave Alheia');
});

it('cria chave pela UI com 2FA completo (live) e exibe a secreta UMA única vez', function () {
    config()->set('api_keys.environment', 'live');
    $user = User::factory()->withTransactionPassword()->create();
    $projeto = projetoDe($user, 'Loja A');

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Integração ERP')
        ->set('selectedProjectUuids', [$projeto->uuid])
        ->call('requestCreate')
        ->assertHasNoErrors()
        ->assertSet('pendingAction', 'create')
        // Passo 1: senha de transação → código por e-mail.
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->assertSet('codeSent', true);

    Mail::assertQueued(VerificationCodeMail::class);

    // Passo 2: código → token de uso único consumido → chave criada.
    $component
        ->set('sensitiveCode', latestSentCode())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors()
        ->assertSet('pendingAction', null);

    $secret = $component->get('revealedSecretKey');
    $public = $component->get('revealedPublicKey');

    expect($public)->toStartWith('pk_')
        ->and($secret)->toStartWith('sk_');

    $key = comoSistema(fn () => ApiKey::query()->sole());

    expect($key->name)->toBe('Integração ERP')
        ->and($key->public_key)->toBe($public)
        ->and($key->scopes)->toBe(['*:*']) // padrão: tudo habilitado
        ->and($key->projects->pluck('id')->all())->toBe([$projeto->id])
        // Só o HASH no banco — a secreta em claro nunca toca o banco.
        ->and(app(ApiKeyHasher::class)->verify($secret, (string) $key->secret_hash))->toBeTrue();

    // "Já guardei" → a secreta some e nunca mais é exibida.
    $component->call('dismissSecret')
        ->assertSet('revealedSecretKey', null);
});

it('cria chave com escopos granulares quando o toggle "todas" está desligado', function () {
    config()->set('api_keys.environment', 'test');
    $user = User::factory()->withTransactionPassword()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Só leitura de projetos')
        ->set('allScopes', false)
        ->set('selectedScopes', ['projects:read', 'uploads:create'])
        ->call('requestCreate')
        ->call('sendSensitiveCode')
        ->set('sensitiveCode', latestSentCode())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors();

    $key = comoSistema(fn () => ApiKey::query()->sole());

    expect($key->scopes)->toBe(['projects:read', 'uploads:create'])
        ->and($key->allows('projects:read'))->toBeTrue()
        ->and($key->allows('projects:delete'))->toBeFalse();
});

it('exige seleção granular quando o toggle "todas" está desligado', function () {
    $user = User::factory()->withTransactionPassword()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Sem escopo')
        ->set('allScopes', false)
        ->call('requestCreate')
        ->assertHasErrors(['selectedScopes']);

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(0);
});

it('live: bloqueia a criação quando a senha de transação não foi definida', function () {
    config()->set('api_keys.environment', 'live');
    $user = User::factory()->create(); // sem senha de transação

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Qualquer')
        ->call('requestCreate')
        ->assertHasErrors(['name']);

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(0);
});

it('live: rejeita senha de transação incorreta no fluxo sensível', function () {
    config()->set('api_keys.environment', 'live');
    $user = User::factory()->withTransactionPassword()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'ERP')
        ->call('requestCreate')
        ->set('sensitivePassword', 'senha-errada')
        ->call('sendSensitiveCode')
        ->assertHasErrors(['sensitivePassword'])
        ->assertSet('codeSent', false);

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(0);
    Mail::assertNothingQueued();
});

it('rejeita código de verificação incorreto', function () {
    $user = User::factory()->withTransactionPassword()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'ERP')
        ->call('requestCreate')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->set('sensitiveCode', '000000')
        ->call('confirmSensitiveAction')
        ->assertHasErrors(['sensitiveCode']);

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(0);
});

it('rotaciona com grace period: nova chave herda tudo e a antiga fica em transição', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $projeto = projetoDe($user, 'Loja');

    $original = criarChave($user, [
        'name' => 'Principal',
        'project_uuids' => [$projeto->uuid],
    ])['api_key'];

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRotate', $original->uuid)
        ->set('gracePeriodMinutes', 60)
        ->call('requestRotate')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->set('sensitiveCode', latestSentCode())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors();

    $novaSecreta = $component->get('revealedSecretKey');

    expect($novaSecreta)->toStartWith('sk_')
        ->and(comoSistema(fn () => ApiKey::query()->count()))->toBe(2);

    $nova = comoSistema(fn () => ApiKey::query()->where('id', '!=', $original->id)->sole());
    $antiga = $original->fresh();

    expect($nova->name)->toBe('Principal')
        ->and($nova->rotated_from_id)->toBe($original->id)
        ->and($nova->projects->pluck('id')->all())->toBe([$projeto->id])
        // Grace de 60 min: antiga segue utilizável até grace_ends_at.
        ->and($antiga->grace_ends_at)->not->toBeNull()
        ->and($antiga->isUsable())->toBeTrue();
});

it('revoga com confirmação na mesma tela (irreversível)', function () {
    $user = User::factory()->create();
    $key = criarChave($user, ['name' => 'Vai morrer'])['api_key'];

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRevoke', $key->uuid)
        ->assertSet('revokingKeyUuid', $key->uuid)
        ->call('revoke');

    expect($key->fresh()->status)->toBe(ApiKeyStatus::Revoked);
});

it('edita os vínculos N:N chave ↔ projetos pela UI', function () {
    $user = User::factory()->create();
    $projeto = projetoDe($user, 'Loja B');
    $key = criarChave($user, ['name' => 'Chave'])['api_key'];

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startEditProjects', $key->uuid)
        ->set('editingProjectsSelection', [$projeto->uuid])
        ->call('saveProjects')
        ->assertHasNoErrors();

    expect($key->fresh()->projects->pluck('id')->all())->toBe([$projeto->id]);
});

it('não vincula projeto de outro tenant a uma chave (anti-IDOR)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $alheio = projetoDe($outro, 'Alheio');
    $key = criarChave($user, ['name' => 'Chave'])['api_key'];

    // resolveProjectIds rejeita uuid alheio → erro de validação,
    // e o vínculo NÃO é criado.
    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startEditProjects', $key->uuid)
        ->set('editingProjectsSelection', [$alheio->uuid])
        ->call('saveProjects')
        ->assertHasErrors(['editingProjectsSelection']);

    expect($key->fresh()->projects)->toHaveCount(0);
});

it('não rotaciona nem revoga chave de outro tenant (404 uniforme)', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $outro = User::factory()->create();
    $alheia = criarChave($outro, ['name' => 'Alheia'])['api_key'];

    // firstOrFail → ModelNotFoundException → 404 (desde o Livewire 4.4.6 o
    // harness de teste responde 404 como a request real, sem propagar).
    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRotate', $alheia->uuid)
        ->assertNotFound();
});

it('não revoga chave de outro tenant (404 uniforme)', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $outro = User::factory()->create();
    $alheia = criarChave($outro, ['name' => 'Alheia'])['api_key'];

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRevoke', $alheia->uuid)
        ->assertNotFound();
});

// =============================================================================
// Regressões de interface (QA): o que a tela mostra, não só o que ela grava.
// =============================================================================

it('não empilha dois modais: a confirmação de segurança substitui o de rotação', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $chave = criarChave($user, ['name' => 'Produção'])['api_key'];

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRotate', $chave->uuid);

    // Passo 1: só o modal de rotação está na tela.
    $component->assertSee(__('panel.api_keys.rotate_title'))
        ->assertDontSee(__('panel.sensitive.heading'));

    // Passo 2: a confirmação de segurança abre e o de rotação SAI — antes os
    // dois ficavam sobrepostos, o de trás visível através do backdrop.
    $component->set('gracePeriodMinutes', 0)
        ->call('requestRotate')
        ->assertSee(__('panel.sensitive.heading'))
        ->assertDontSee(__('panel.api_keys.rotate_title'));

    // Cancelar a confirmação volta para o passo anterior (nada some sem aviso).
    $component->call('cancelSensitiveAction')
        ->assertSee(__('panel.api_keys.rotate_title'));
});

it('oferece copiar a chave pública em cada linha da listagem', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $chave = criarChave($user, ['name' => 'Integração'])['api_key'];

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('data-copy="'.$chave->public_key.'"', false)
        ->assertSee(__('panel.api_keys.copy_public'));
});

it('mantém a ação destrutiva fora da linha de frente (menu de overflow)', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $chave = criarChave($user, ['name' => 'Integração'])['api_key'];

    Livewire::actingAs($user)
        ->test(Index::class)
        // Rotina: botões visíveis. Destrutiva: dentro de [data-dropdown-menu].
        ->assertSee(__('panel.api_keys.rotate'))
        ->assertSee('data-dropdown-menu', false)
        ->assertSee(__('panel.common.more_actions'));
});

// =============================================================================
// CONFIRMAÇÃO DA CRIAÇÃO POR AMBIENTE e VALIDADE ESCOLHIDA NA CRIAÇÃO
// =============================================================================

it('test: cria só com o código por e-mail — sem senha de transação, sem token de ação sensível', function () {
    config()->set('api_keys.environment', 'test');
    $user = User::factory()->create(); // sem senha de transação: a criação não pede

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Sandbox')
        ->call('requestCreate')
        ->assertHasNoErrors()
        ->assertSet('pendingAction', 'create')
        // O modal não tem o campo da senha: só o aviso e o envio do código.
        ->assertSee(__('panel.sensitive.code_only_hint'))
        ->assertDontSee(__('panel.sensitive.password_hint'))
        ->assertDontSeeHtml('name="sensitivePassword"')
        ->call('sendSensitiveCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true);

    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)->last();
    expect($mail->purpose)->toBe(VerificationPurpose::CodeConfirmation);

    $component->set('sensitiveCode', latestSentCode())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors()
        ->assertSet('pendingAction', null);

    $key = comoSistema(fn () => ApiKey::query()->sole());

    expect($key->name)->toBe('Sandbox')
        ->and($component->get('revealedPublicKey'))->toBe($key->public_key)
        ->and(SensitiveActionToken::query()->count())->toBe(0);
});

it('test: "enviar outro código" manda outro na hora, sem voltar à senha', function () {
    config()->set('api_keys.environment', 'test');
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Sandbox')
        ->call('requestCreate')
        ->call('sendSensitiveCode')
        ->call('resendSensitiveCode')
        ->assertSet('codeSent', true)
        ->assertHasNoErrors();

    Mail::assertQueuedCount(2);

    // Só o último código vale.
    $component->set('sensitiveCode', latestSentCode())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors();

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(1);
});

it('test: a rotação continua pedindo a senha de transação', function () {
    config()->set('api_keys.environment', 'test');
    $user = User::factory()->withTransactionPassword()->create();
    $chave = criarChave($user, ['name' => 'Principal'])['api_key'];

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRotate', $chave->uuid)
        ->call('requestRotate')
        ->assertSee(__('panel.sensitive.password_hint'))
        ->assertDontSee(__('panel.sensitive.code_only_hint'))
        ->call('sendSensitiveCode')
        ->assertHasErrors(['sensitivePassword'])
        ->assertSet('codeSent', false);

    Mail::assertNothingQueued();
    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(1);
});

it('live nunca afrouxa: sem a senha de transação, nenhum código sai e nenhuma chave nasce', function () {
    config()->set('api_keys.environment', 'live');
    $user = User::factory()->withTransactionPassword()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Produção')
        ->call('requestCreate')
        ->assertSee(__('panel.sensitive.password_hint'))
        ->assertDontSee(__('panel.sensitive.code_only_hint'))
        ->call('sendSensitiveCode')
        ->assertHasErrors(['sensitivePassword'])
        ->assertSet('codeSent', false)
        // Código inventado não cria nada.
        ->set('sensitiveCode', '123456')
        ->call('confirmSensitiveAction')
        ->assertHasErrors(['sensitiveCode']);

    Mail::assertNothingQueued();
    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(0);
});

it('a validade escolhida na criação: sem validade, N horas ou N dias', function (string $unit, ?int $amount, ?string $expected) {
    config()->set('api_keys.environment', 'test');
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00', 'UTC'));
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->assertSet('expiryUnit', 'none')
        ->set('name', 'Com validade')
        ->set('expiryUnit', $unit)
        ->set('expiryAmount', $amount)
        ->call('requestCreate')
        ->assertHasNoErrors()
        ->call('sendSensitiveCode')
        ->set('sensitiveCode', latestSentCode())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors();

    $key = comoSistema(fn () => ApiKey::query()->sole());

    expect($key->expires_at?->utc()->toIso8601String())->toBe($expected);
})->with([
    'sem validade' => ['none', null, null],
    '6 horas' => ['hours', 6, '2026-11-14T16:00:00+00:00'],
    '30 dias' => ['days', 30, '2026-12-14T10:00:00+00:00'],
]);

it('a validade fora do teto da unidade é recusada no formulário', function (string $unit, ?int $amount) {
    config()->set('api_keys.environment', 'test');
    config()->set('api_keys.expiry.max_hours', 48);
    config()->set('api_keys.expiry.max_days', 90);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Validade errada')
        ->set('expiryUnit', $unit)
        ->set('expiryAmount', $amount)
        ->call('requestCreate')
        ->assertHasErrors()
        ->assertSet('pendingAction', null);

    Mail::assertNothingQueued();
})->with([
    '49 horas' => ['hours', 49],
    '91 dias' => ['days', 91],
    'horas sem quantidade' => ['hours', null],
    'unidade desconhecida' => ['weeks', 1],
]);

it('validade adulterada depois do passo 1: recusada antes de gastar o código', function () {
    config()->set('api_keys.environment', 'test');
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Adulterada')
        ->set('expiryUnit', 'days')
        ->set('expiryAmount', 30)
        ->call('requestCreate')
        ->call('sendSensitiveCode');

    $codigo = latestSentCode();

    $component->set('expiryAmount', 999999)
        ->set('sensitiveCode', $codigo)
        ->call('confirmSensitiveAction')
        ->assertHasErrors(['sensitiveCode']);

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(0);

    // O código não foi gasto: com a validade de volta ao permitido, ele cria.
    $component->set('expiryAmount', 30)
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors();

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(1);
});

it('a tela mostra a escolha da validade com os tetos da configuração', function () {
    config()->set('api_keys.expiry.max_hours', 48);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->assertSeeHtml('data-testid="key-expiry"')
        ->assertSee(__('panel.api_keys.expiry_unit_none'))
        ->assertSee(__('panel.api_keys.expiry_unit_hours'))
        ->assertSee(__('panel.api_keys.expiry_unit_days'))
        ->assertDontSeeHtml('wire:model="expiresAt"')
        ->set('expiryUnit', 'hours')
        ->assertSeeHtml('max="48"')
        ->assertSee(__('panel.api_keys.expiry_amount_hint_hours', ['max' => 48]));
});

it('a tela do kit segue funcionando com o componente anterior à 2.3.0 (data de validade, senha + código)', function () {
    $user = User::factory()->create();

    // Um componente com o estado e os dados que a tela recebia antes: sem
    // `expiryOptions` nem `sensitivePasswordless`.
    $antigo = new class extends Component
    {
        public bool $showCreateForm = true;

        public string $name = '';

        public ?string $expiresAt = null;

        public bool $allScopes = true;

        /** @var array<int, string> */
        public array $selectedScopes = [];

        /** @var array<int, string> */
        public array $selectedProjectUuids = [];

        public ?string $revealedPublicKey = null;

        public ?string $revealedSecretKey = null;

        public ?string $rotatingKeyUuid = null;

        public int $gracePeriodMinutes = 0;

        public ?string $revokingKeyUuid = null;

        public ?string $editingProjectsKeyUuid = null;

        /** @var array<int, string> */
        public array $editingProjectsSelection = [];

        public ?string $pendingAction = 'create';

        public string $sensitivePassword = '';

        public string $sensitiveCode = '';

        public bool $codeSent = false;

        public function resendCooldown(): int
        {
            return 0;
        }

        public function render(): View
        {
            return view('livewire.api-keys.index', [
                'keys' => collect(),
                'projects' => collect(),
                'scopesCatalog' => [],
                'hasTransactionPassword' => true,
                'canManageKeys' => true,
            ]);
        }
    };

    Livewire::actingAs($user)
        ->test($antigo::class)
        ->assertOk()
        ->assertSeeHtml('wire:model="expiresAt"')
        ->assertDontSeeHtml('data-testid="key-expiry"')
        ->assertSeeHtml('name="sensitivePassword"')
        ->assertSee(__('panel.sensitive.password_hint'));
});

it('a chave de SESSÃO não aparece na lista do painel', function () {
    config()->set('api_keys.environment', 'test');
    $user = User::factory()->create();
    criarChave($user, ['name' => 'Chave comum']);

    // A chave de sessão nasce pela API do pacote (como a "Experimente" de
    // uma documentação), na sessão de verdade da pessoa.
    $session = app('session.store');
    $session->start();
    Auth::guard('web')->login($user);

    $confirmation = app(ApiKeyCreationConfirmation::class);
    $confirmation->sendCode($user);
    $prova = $confirmation->confirm($user, latestSentCode());

    naConta($user, fn () => app(ApiKeyService::class)->createForSession($user, $session, ['name' => 'Chave da sessão'], $prova));

    expect(comoSistema(fn () => ApiKey::query()->withSessionKeys()->count()))->toBe(2);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Chave comum')
        ->assertDontSee('Chave da sessão');
});
