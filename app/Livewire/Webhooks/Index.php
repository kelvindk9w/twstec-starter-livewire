<?php

declare(strict_types=1);

namespace App\Livewire\Webhooks;

use App\Livewire\Concerns\ConfirmsSensitiveAction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Twstec\Kit\Webhooks\Actions\CheckEndpointInput;
use Twstec\Kit\Webhooks\Actions\CreateEndpoint;
use Twstec\Kit\Webhooks\Actions\DeleteEndpoint;
use Twstec\Kit\Webhooks\Actions\ResendDelivery;
use Twstec\Kit\Webhooks\Actions\RevealSecret;
use Twstec\Kit\Webhooks\Actions\RotateSecret;
use Twstec\Kit\Webhooks\Actions\SendTestEvent;
use Twstec\Kit\Webhooks\Actions\SetEndpointStatus;
use Twstec\Kit\Webhooks\Actions\UpdateEndpoint;
use Twstec\Kit\Webhooks\Support\WebhookAccess;
use Twstec\Kit\Webhooks\Support\WebhookPanel;

/**
 * Webhooks da conta atual — tudo na MESMA tela: os endpoints (criar,
 * editar, ativar/desativar, excluir, enviar teste, revelar e rotacionar o
 * segredo), o log de entregas (status, tentativas, resposta redigida) e o
 * reenvio.
 *
 * ZERO regra aqui: cada operação chama a Action do twstec/kit-webhooks, que
 * confere o papel (dono/admin), o destino (SSRF, DNS de agora), a ação
 * sensível (o token é consumido pela própria Action) e grava a trilha. Esta
 * tela só guarda o estado do formulário e mostra o segredo UMA vez (até a
 * pessoa confirmar que guardou — dismissSecret).
 *
 * Ações sensíveis (senha de transação → código por e-mail): `save` (criar
 * ou editar — destino novo para os dados da conta), `reveal` e `rotate`.
 */
final class Index extends Component
{
    use ConfirmsSensitiveAction;

    // --- Formulário (criar e editar, mesma tela) --------------------------------
    public bool $showForm = false;

    public ?string $editingUuid = null;

    public string $name = '';

    public string $url = '';

    /**
     * Eventos marcados. Vem do navegador: pode chegar com chaves soltas.
     *
     * @var array<array-key, string>
     */
    public array $events = [];

    public string $project = '';

    // --- Segredo mostrado UMA vez -----------------------------------------------
    public ?string $revealedSecret = null;

    public ?string $revealedFor = null;

    // --- Rotação / revelação / exclusão -----------------------------------------
    public ?string $revealingUuid = null;

    public ?string $rotatingUuid = null;

    public int $overlapMinutes = 1440;

    public ?string $deletingUuid = null;

    // --- Log de entregas: filtro por endpoint (null = todos) --------------------
    public ?string $deliveriesFor = null;

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->reset('editingUuid', 'name', 'url', 'events', 'project');
        $this->showForm = true;
    }

    public function startEdit(string $uuid): void
    {
        $this->resetValidation();
        $endpoint = $this->endpointRow($uuid);

        abort_if($endpoint === null, 404);

        /** @var list<string> $events */
        $events = $endpoint['events'];
        /** @var array{uuid: string, name: string}|null $project */
        $project = $endpoint['project'];

        $this->editingUuid = $uuid;
        $this->name = (string) $endpoint['name'];
        $this->url = (string) $endpoint['url'];
        $this->events = $events;
        $this->project = $project['uuid'] ?? '';
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetValidation();
        $this->reset('showForm', 'editingUuid', 'name', 'url', 'events', 'project');
    }

    /**
     * Passo 1: confere o formulário e o destino (as mesmas regras de quando
     * grava) e abre a confirmação sensível.
     */
    public function requestSave(CheckEndpointInput $check): void
    {
        if (! $this->user()->hasTransactionPassword()) {
            throw ValidationException::withMessages(['name' => __('webhooks.ui.sensitive_requires_password')]);
        }

        $check->handle($this->user(), $this->formData(), $this->editingUuid);

        $this->openSensitiveModal('save');
    }

    public function startReveal(string $uuid): void
    {
        $this->requireTransactionPassword();
        $this->revealingUuid = $uuid;
        $this->openSensitiveModal('reveal');
    }

    public function startRotate(string $uuid): void
    {
        $this->resetValidation();
        $this->requireTransactionPassword();
        $this->rotatingUuid = $uuid;
        $this->overlapMinutes = (int) config('webhooks.secret.default_overlap_minutes', 1440);
    }

    public function cancelRotate(): void
    {
        $this->reset('rotatingUuid', 'overlapMinutes');
    }

    public function requestRotate(): void
    {
        $this->validate(['overlapMinutes' => ['required', 'integer', 'min:0', 'max:'.(int) config('webhooks.secret.max_overlap_minutes', 10080)]]);

        $this->openSensitiveModal('rotate');
    }

    public function dismissSecret(): void
    {
        $this->reset('revealedSecret', 'revealedFor');
    }

    public function toggleStatus(string $uuid, bool $active, SetEndpointStatus $status): void
    {
        $this->mapErrors(fn () => $status->handle($this->user(), $uuid, $active), ['url' => 'webhooks']);

        session()->flash('webhooks_status', __($active ? 'webhooks.ui.enabled' : 'webhooks.ui.disabled'));
    }

    public function sendTest(string $uuid, SendTestEvent $test): void
    {
        $this->mapErrors(fn () => $test->handle($this->user(), $uuid));

        session()->flash('webhooks_status', __('webhooks.ui.test_sent'));
        $this->deliveriesFor = $uuid;
    }

    public function resend(string $deliveryUuid, ResendDelivery $resend): void
    {
        $this->mapErrors(fn () => $resend->handle($this->user(), $deliveryUuid));

        session()->flash('webhooks_status', __('webhooks.ui.resent'));
    }

    public function startDelete(string $uuid): void
    {
        $this->deletingUuid = $uuid;
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingUuid');
    }

    public function removeEndpoint(DeleteEndpoint $delete): void
    {
        $delete->handle($this->user(), (string) $this->deletingUuid);

        if ($this->deliveriesFor === $this->deletingUuid) {
            $this->deliveriesFor = null;
        }

        $this->reset('deletingUuid');
        session()->flash('webhooks_status', __('webhooks.ui.deleted'));
    }

    public function showDeliveries(?string $uuid = null): void
    {
        $this->deliveriesFor = $uuid;
    }

    public function cancelSensitiveAction(): void
    {
        $this->closeSensitiveModal();
        $this->reset('revealingUuid');
    }

    protected function performSensitiveAction(string $action, string $token): void
    {
        match ($action) {
            'save' => $this->performSave($token),
            'reveal' => $this->performReveal($token),
            'rotate' => $this->performRotate($token),
            default => null,
        };
    }

    public function render(): View
    {
        return view('livewire.webhooks.index', [
            'endpoints' => WebhookPanel::endpoints(),
            'deliveries' => WebhookPanel::deliveries($this->deliveriesFor),
            'eventOptions' => WebhookPanel::eventOptions(),
            'projectOptions' => WebhookPanel::projectOptions(),
            'canManage' => WebhookAccess::canManage(),
            'hasTransactionPassword' => $this->user()->hasTransactionPassword(),
            'sensitiveDescription' => match ($this->pendingAction) {
                'reveal' => __('webhooks.ui.reveal'),
                'rotate' => __('webhooks.ui.rotate'),
                'save' => $this->editingUuid === null ? __('webhooks.ui.new') : __('webhooks.ui.edit'),
                default => null,
            },
        ])->title(__('webhooks.ui.title'));
    }

    private function performSave(string $token): void
    {
        if ($this->editingUuid === null) {
            ['endpoint' => $endpoint, 'secret' => $secret] = app(CreateEndpoint::class)->handle($this->user(), $this->formData(), $token);

            $this->revealedSecret = $secret;
            $this->revealedFor = $endpoint->name;
            session()->flash('webhooks_status', __('webhooks.ui.created'));
        } else {
            app(UpdateEndpoint::class)->handle($this->user(), $this->editingUuid, $this->formData(), $token);
            session()->flash('webhooks_status', __('webhooks.ui.updated'));
        }

        $this->reset('showForm', 'editingUuid', 'name', 'url', 'events', 'project');
    }

    private function performReveal(string $token): void
    {
        $uuid = (string) $this->revealingUuid;

        $this->revealedSecret = app(RevealSecret::class)->handle($this->user(), $uuid, $token);
        $this->revealedFor = (string) ($this->endpointRow($uuid)['name'] ?? '');
        $this->reset('revealingUuid');
    }

    private function performRotate(string $token): void
    {
        $uuid = (string) $this->rotatingUuid;

        $this->mapErrors(function () use ($uuid, $token): void {
            $this->revealedSecret = app(RotateSecret::class)->handle($this->user(), $uuid, $this->overlapMinutes, $token);
        });

        $this->revealedFor = (string) ($this->endpointRow($uuid)['name'] ?? '');
        $this->reset('rotatingUuid', 'overlapMinutes');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'events' => array_values($this->events),
            'project' => $this->project !== '' ? $this->project : null,
        ];
    }

    /**
     * Os erros das Actions com os nomes dos campos desta tela.
     *
     * @param  array<string, string>  $extra
     */
    private function mapErrors(\Closure $callback, array $extra = []): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $map = [...['overlap' => 'overlapMinutes', 'endpoint' => 'webhooks', 'delivery' => 'webhooks'], ...$extra];
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors[$map[$key] ?? $key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * O endpoint da conta atual, como a tela o mostra (nunca o segredo).
     *
     * @return array<string, mixed>|null
     */
    private function endpointRow(string $uuid): ?array
    {
        foreach (WebhookPanel::endpoints() as $endpoint) {
            if ($endpoint['uuid'] === $uuid) {
                return $endpoint;
            }
        }

        return null;
    }

    private function requireTransactionPassword(): void
    {
        if (! $this->user()->hasTransactionPassword()) {
            throw ValidationException::withMessages(['webhooks' => __('webhooks.ui.sensitive_requires_password')]);
        }
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
