<?php

declare(strict_types=1);

namespace App\Livewire\ApiKeys;

use App\Livewire\Concerns\ConfirmsSensitiveAction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Support\AccountResourceGuard;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyAttempt;
use Twstec\Kit\Accounts\ApiKeys\Http\Requests\StoreApiKeyRequest;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Services\SensitiveActionService;

/**
 * Chaves de API — a tela mais importante do painel.
 *
 * Tudo na MESMA tela: listar, criar (scopes + vínculo N:N com
 * projetos), visualização ÚNICA da secreta, rotacionar (com grace period),
 * revogar e editar vínculos de projetos.
 *
 * ZERO lógica duplicada: criação/rotação/revogação/vínculos delegam ao
 * ApiKeyService e a confirmação sensível ao SensitiveActionService
 * (senha de transação → código por e-mail → token de uso único,
 * consumido aqui via validateToken — mesmo contrato do middleware da API).
 *
 * As chaves são da CONTA ATUAL (o escopo das contas filtra toda consulta);
 * gerir chaves exige o papel owner ou admin na conta
 * (AccountAbility::ManageApiKeys). A senha de transação e a ação sensível
 * continuam sendo da PESSOA logada.
 *
 * Toda recusa fica na trilha (AccountResourceGuard), com a ação tentada: o
 * papel que não permite (403), a chave que não está na conta atual (o mesmo
 * 404 de sempre) e o projeto de fora da conta no vínculo (o mesmo erro de
 * validação).
 */
final class Index extends Component
{
    use ConfirmsSensitiveAction;

    // --- Formulário de criação (inline, mesma tela) --------------------------
    public bool $showCreateForm = false;

    public string $name = '';

    public ?string $expiresAt = null;

    /** Toggle "todas as permissões" (padrão). */
    public bool $allScopes = true;

    /** @var list<string> Seleção granular "recurso:acao" (quando allScopes off). */
    public array $selectedScopes = [];

    /** @var list<string> UUIDs de projetos vinculados (vazio = conta toda). */
    public array $selectedProjectUuids = [];

    // --- Visualização ÚNICA da secreta ---------------------------------------
    public ?string $revealedPublicKey = null;

    public ?string $revealedSecretKey = null;

    // --- Rotação ---------------------------------------------------------------
    public ?string $rotatingKeyUuid = null;

    public int $gracePeriodMinutes = 0;

    // --- Revogação --------------------------------------------------------------
    public ?string $revokingKeyUuid = null;

    // --- Vínculo N:N chave ↔ projetos -------------------------------------------
    public ?string $editingProjectsKeyUuid = null;

    /** @var list<string> */
    public array $editingProjectsSelection = [];

    // --- Fluxo de ação sensível (modal): estado no trait ConfirmsSensitiveAction.
    // Ações confirmáveis desta tela: 'create' | 'rotate'.

    /**
     * Chaves da conta atual (todas — a listagem mostra também o histórico:
     * revogadas, expiradas e rotacionadas com seu status).
     *
     * @return Collection<int, ApiKey>
     */
    public function keys(): Collection
    {
        return ApiKey::query()
            ->with('projects:projects.id,projects.uuid,projects.name')
            ->latest()
            ->get();
    }

    /**
     * Projetos da conta atual (para os checkboxes de vínculo).
     *
     * @return Collection<int, Project>
     */
    public function projects(): Collection
    {
        return Project::query()
            ->orderBy('name')
            ->get();
    }

    // =========================================================================
    // Criação
    // =========================================================================

    public function startCreate(): void
    {
        $this->authorizeManage(ApiKeyAttempt::Created);
        $this->resetValidation();
        $this->reset('name', 'expiresAt', 'selectedScopes', 'selectedProjectUuids');
        $this->allScopes = true;
        $this->showCreateForm = true;
    }

    /**
     * Passo 1 da criação: valida o formulário e abre a confirmação sensível
     * (criação de chave é ação sensível).
     */
    public function requestCreate(): void
    {
        $this->authorizeManage(ApiKeyAttempt::Created);
        $this->validateKeyForm();
        $this->resolveSelectedProjects(app(ApiKeyService::class));

        if (! $this->user()->hasTransactionPassword()) {
            throw ValidationException::withMessages([
                'name' => __('panel.api_keys.sensitive_requires_password'),
            ]);
        }

        $this->showCreateForm = false;
        $this->openSensitiveModal('create');
    }

    // =========================================================================
    // Rotação (herda nome/scopes/projetos; morte da antiga escolhida
    // pelo usuário — imediata ou grace period).
    // =========================================================================

    public function startRotate(string $uuid): void
    {
        $this->authorizeManage(ApiKeyAttempt::Rotated, $uuid);
        $key = $this->findOwnedKey($uuid, ApiKeyAttempt::Rotated);

        if (! $key->isUsable()) {
            throw ValidationException::withMessages([
                'rotatingKeyUuid' => __('api_keys.keys.not_rotatable'),
            ]);
        }

        $this->rotatingKeyUuid = $uuid;
        $this->gracePeriodMinutes = 0;
    }

    public function cancelRotate(): void
    {
        $this->reset('rotatingKeyUuid', 'gracePeriodMinutes');
    }

    public function requestRotate(): void
    {
        $this->authorizeManage(ApiKeyAttempt::Rotated, $this->rotatingKeyUuid);
        $this->validate([
            'gracePeriodMinutes' => ['required', 'integer', 'min:0', 'max:'.(int) config('api_keys.rotation.max_grace_minutes', 10080)],
        ]);

        $this->openSensitiveModal('rotate');
    }

    // =========================================================================
    // Revogação (irreversível — confirmação explícita, mesma tela)
    // =========================================================================

    public function startRevoke(string $uuid): void
    {
        $this->authorizeManage(ApiKeyAttempt::Revoked, $uuid);
        $this->findOwnedKey($uuid, ApiKeyAttempt::Revoked);
        $this->revokingKeyUuid = $uuid;
    }

    public function cancelRevoke(): void
    {
        $this->reset('revokingKeyUuid');
    }

    public function revoke(ApiKeyService $apiKeys): void
    {
        $this->authorizeManage(ApiKeyAttempt::Revoked, $this->revokingKeyUuid);
        $key = $this->findOwnedKey((string) $this->revokingKeyUuid, ApiKeyAttempt::Revoked);

        $apiKeys->revoke($key);

        $this->reset('revokingKeyUuid');
        session()->flash('keys_status', __('panel.api_keys.revoked'));
    }

    // =========================================================================
    // Vínculo N:N chave ↔ projetos (lista vazia = conta toda)
    // =========================================================================

    public function startEditProjects(string $uuid): void
    {
        $this->authorizeManage(ApiKeyAttempt::ProjectsSynced, $uuid);
        $key = $this->findOwnedKey($uuid, ApiKeyAttempt::ProjectsSynced);

        $this->editingProjectsKeyUuid = $uuid;
        $this->editingProjectsSelection = $key->projects->pluck('uuid')->all();
    }

    public function cancelEditProjects(): void
    {
        $this->reset('editingProjectsKeyUuid', 'editingProjectsSelection');
    }

    public function saveProjects(ApiKeyService $apiKeys): void
    {
        $this->authorizeManage(ApiKeyAttempt::ProjectsSynced, $this->editingProjectsKeyUuid);
        $key = $this->findOwnedKey((string) $this->editingProjectsKeyUuid, ApiKeyAttempt::ProjectsSynced);

        $this->validate([
            'editingProjectsSelection' => ['array'],
            'editingProjectsSelection.*' => ['uuid'],
        ]);

        // resolveProjectIds garante que os projetos são da conta atual;
        // uuid de outra conta vira erro de validação (nunca 500 nem vínculo),
        // com a tentativa na trilha.
        try {
            $projectIds = $apiKeys->resolveProjectIds($this->editingProjectsSelection);
        } catch (\InvalidArgumentException) {
            $this->guard()->foreignProjects(ApiKeyAttempt::ProjectsSynced, $key->uuid);

            throw ValidationException::withMessages([
                'editingProjectsSelection' => __('api_keys.projects.invalid'),
            ]);
        }

        // Lista vazia = conta toda; com projetos = restrita (ApiKeyService).
        $apiKeys->syncProjects($key, $projectIds);

        $this->cancelEditProjects();
        session()->flash('keys_status', __('panel.api_keys.projects_saved'));
    }

    // =========================================================================
    // Fluxo de ação sensível: senha de transação → código por e-mail → executa
    // (trait ConfirmsSensitiveAction + SensitiveActionService — nada duplicado).
    // =========================================================================

    protected function performSensitiveAction(string $action, string $token): void
    {
        $this->authorizeManage(
            $action === 'rotate' ? ApiKeyAttempt::Rotated : ApiKeyAttempt::Created,
            $action === 'rotate' ? $this->rotatingKeyUuid : null,
        );

        // Consome o token exatamente como o middleware `sensitive.token`
        // faria na API — a operação abaixo é a única autorizada por ele.
        abort_unless(app(SensitiveActionService::class)->validateToken($this->user(), $token), 403);

        $apiKeys = app(ApiKeyService::class);

        match ($action) {
            'create' => $this->performCreate($apiKeys),
            'rotate' => $this->performRotate($apiKeys),
            default => null,
        };
    }

    // =========================================================================
    // Tela de visualização única: o usuário confirma que guardou a secreta —
    // as propriedades são limpas e o valor NUNCA mais é exibido (no banco só fica o hash).
    // =========================================================================

    public function dismissSecret(): void
    {
        $this->reset('revealedPublicKey', 'revealedSecretKey');
    }

    public function render(): View
    {
        return view('livewire.api-keys.index', [
            'keys' => $this->keys(),
            'projects' => $this->projects(),
            'scopesCatalog' => (array) config('api_keys.scopes_catalog', []),
            'hasTransactionPassword' => $this->user()->hasTransactionPassword(),
            'canManageKeys' => Accounts::can(AccountAbility::ManageApiKeys),
        ])->title(__('panel.api_keys.title'));
    }

    // =========================================================================
    // Internos
    // =========================================================================

    /**
     * Validação do formulário de chave — MESMAS regras do StoreApiKeyRequest
     * da API v1, para UI e API se comportarem igual.
     */
    private function validateKeyForm(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
            'selectedScopes' => ['array'],
            'selectedScopes.*' => ['string', 'regex:'.StoreApiKeyRequest::SCOPE_REGEX],
            'selectedProjectUuids' => ['array'],
            'selectedProjectUuids.*' => ['uuid'],
        ], [
            'selectedScopes.*.regex' => __('api_keys.scopes.invalid_format'),
        ], [
            'name' => __('panel.common.name'),
            'expiresAt' => __('panel.api_keys.expires_at'),
        ]);

        if (! $this->allScopes && $this->selectedScopes === []) {
            throw ValidationException::withMessages([
                'selectedScopes' => __('api_keys.scopes.invalid_format'),
            ]);
        }
    }

    private function performCreate(ApiKeyService $apiKeys): void
    {
        // Os projetos de novo (podem ter mudado desde o passo 1): fora da conta
        // atual = erro de validação com a tentativa na trilha, nunca 500.
        $this->resolveSelectedProjects($apiKeys);

        $result = $apiKeys->create($this->user(), [
            'name' => $this->name,
            'scopes' => $this->allScopes ? null : array_values($this->selectedScopes),
            'expires_at' => $this->expiresAt !== null && $this->expiresAt !== ''
                ? Carbon::parse($this->expiresAt)->toDateTimeString()
                : null,
            'project_uuids' => $this->selectedProjectUuids !== [] ? array_values($this->selectedProjectUuids) : null,
        ]);

        // A secreta em claro fica SÓ nestas propriedades transitórias, até o
        // usuário confirmar que guardou (dismissSecret). Nunca toca o banco.
        $this->revealedPublicKey = $result['api_key']->public_key;
        $this->revealedSecretKey = $result['secret_key'];

        $this->reset('name', 'expiresAt', 'selectedScopes', 'selectedProjectUuids');
        $this->allScopes = true;
        $this->showCreateForm = false;
    }

    private function performRotate(ApiKeyService $apiKeys): void
    {
        $key = $this->findOwnedKey((string) $this->rotatingKeyUuid, ApiKeyAttempt::Rotated);

        $result = $apiKeys->rotate($key, $this->gracePeriodMinutes);

        $this->revealedPublicKey = $result['api_key']->public_key;
        $this->revealedSecretKey = $result['secret_key'];

        $this->reset('rotatingKeyUuid', 'gracePeriodMinutes');
    }

    /**
     * Os projetos escolhidos na criação são da conta atual? Senão, erro de
     * validação no campo, com a tentativa na trilha.
     *
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function resolveSelectedProjects(ApiKeyService $apiKeys): array
    {
        try {
            return $apiKeys->resolveProjectIds(array_values($this->selectedProjectUuids));
        } catch (\InvalidArgumentException) {
            $this->guard()->foreignProjects(ApiKeyAttempt::Created);

            throw ValidationException::withMessages([
                'selectedProjectUuids' => __('api_keys.projects.invalid'),
            ]);
        }
    }

    /**
     * Busca chave da CONTA ATUAL por UUID — uuid de outra conta = 404
     * (anti-enumeração, mesmo padrão dos controllers da API), com a
     * tentativa na trilha.
     */
    private function findOwnedKey(string $uuid, ApiKeyAttempt $attempt): ApiKey
    {
        return $this->guard()->apiKey($uuid, $attempt);
    }

    /**
     * Gerir chaves: owner ou admin da conta (403 para member, com a
     * tentativa na trilha).
     */
    private function authorizeManage(ApiKeyAttempt $attempt, ?string $keyUuid = null): void
    {
        $this->guard()->authorize(AccountAbility::ManageApiKeys, $attempt, $keyUuid);
    }

    private function guard(): AccountResourceGuard
    {
        return app(AccountResourceGuard::class);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
