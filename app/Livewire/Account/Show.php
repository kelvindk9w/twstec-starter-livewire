<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Livewire\Concerns\ConfirmsSensitiveAction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Twstec\Kit\Accounts\Account\Actions\ChangeMemberRole;
use Twstec\Kit\Accounts\Account\Actions\DeleteAccount;
use Twstec\Kit\Accounts\Account\Actions\InviteMember;
use Twstec\Kit\Accounts\Account\Actions\LeaveAccount;
use Twstec\Kit\Accounts\Account\Actions\RemoveMember;
use Twstec\Kit\Accounts\Account\Actions\RenameAccount;
use Twstec\Kit\Accounts\Account\Actions\ResendInvitation;
use Twstec\Kit\Accounts\Account\Actions\RevokeInvitation;
use Twstec\Kit\Accounts\Account\Actions\TransferOwnership;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Queries\AccountDirectory;
use Twstec\Kit\Accounts\Account\Support\MemberRules;
use Twstec\Kit\Accounts\Accounts;

/**
 * A PÁGINA DA CONTA ATUAL — dados, membros, convites e os fluxos de dono.
 *
 * Tudo na mesma tela, como as outras do painel: renomear, convidar, reenviar
 * e revogar convite, mudar papel, remover membro, sair, transferir a
 * propriedade e excluir a conta. A REGRA mora nas Actions do pacote de
 * contas (Twstec\Kit\Accounts\Account\Actions), que conferem o papel, gravam
 * a trilha de auditoria (inclusive as recusas) e respondem 403 ao que o papel
 * não permite — também na PRÉ-CHECAGEM (`authorize()` da Action) que esta
 * tela faz antes de abrir uma confirmação; esta tela só ESCONDE o que o papel não permite (pela mesma
 * regra — MemberRules), valida o formulário e mostra o resultado.
 *
 * Transferir e excluir são AÇÕES SENSÍVEIS: senha de transação → código por
 * e-mail → token de uso único, que a Action consome (trait
 * ConfirmsSensitiveAction, o mesmo modal das chaves de API).
 */
final class Show extends Component
{
    use ConfirmsSensitiveAction;

    public const VIEW_SESSION_KEY = 'panel.account.members_view';

    // --- Dados da conta -------------------------------------------------------
    public bool $editingName = false;

    public string $name = '';

    // --- Convite -------------------------------------------------------------
    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    // --- Lista de membros: tabela ou cartões -------------------------------------
    public string $view = 'table';

    // --- Confirmações (modal do kit, controlado pelo servidor) -----------------
    public ?string $removingUuid = null;

    public ?string $revokingInvitationUuid = null;

    public bool $confirmingLeave = false;

    // --- Transferência (a confirmação sensível vem do trait) --------------------
    public string $transferTo = '';

    public function mount(): void
    {
        $salvo = session(self::VIEW_SESSION_KEY);
        $this->view = in_array($salvo, ['table', 'cards'], true) ? $salvo : 'table';
    }

    // =========================================================================
    // Dados da conta
    // =========================================================================

    public function startRename(RenameAccount $rename): void
    {
        $rename->authorize($this->user());
        $this->resetValidation();
        $this->name = (string) $this->account()->name;
        $this->editingName = true;
    }

    public function cancelRename(): void
    {
        $this->reset('editingName', 'name');
    }

    public function rename(RenameAccount $rename): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']], [], ['name' => __('panel.common.name')]);

        $this->run(fn () => $rename->handle($this->user(), $this->name));

        $this->cancelRename();
        session()->flash('account_status', __('panel.account.renamed'));
    }

    // =========================================================================
    // Convites
    // =========================================================================

    public function invite(InviteMember $invite): void
    {
        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteRole' => ['required', 'in:admin,member'],
        ], [], [
            'inviteEmail' => __('panel.account.invite_email'),
            'inviteRole' => __('panel.account.role'),
        ]);

        $this->run(fn () => $invite->handle($this->user(), $this->inviteEmail, AccountRole::from($this->inviteRole)), ['email' => 'inviteEmail']);

        // Mesma mensagem para e-mail com e sem conta na plataforma.
        session()->flash('account_status', __('panel.account.invited', ['email' => $this->inviteEmail]));
        $this->reset('inviteEmail', 'inviteRole');
    }

    public function resendInvitation(string $uuid, ResendInvitation $resend): void
    {
        $this->run(fn () => $resend->handle($this->user(), $uuid), ['invitation' => 'invitations']);

        session()->flash('account_status', __('panel.account.invitation_resent'));
    }

    public function startRevokeInvitation(string $uuid, RevokeInvitation $revoke): void
    {
        $revoke->authorize($this->user());
        $this->directory()->invitation($uuid);
        $this->revokingInvitationUuid = $uuid;
    }

    public function cancelRevokeInvitation(): void
    {
        $this->reset('revokingInvitationUuid');
    }

    public function revokeInvitation(RevokeInvitation $revoke): void
    {
        $this->run(fn () => $revoke->handle($this->user(), (string) $this->revokingInvitationUuid), ['invitation' => 'invitations']);

        $this->cancelRevokeInvitation();
        session()->flash('account_status', __('panel.account.invitation_revoked'));
    }

    // =========================================================================
    // Membros
    // =========================================================================

    public function setView(string $view): void
    {
        $this->view = $view === 'cards' ? 'cards' : 'table';
        session()->put(self::VIEW_SESSION_KEY, $this->view);
    }

    public function changeRole(string $uuid, string $role, ChangeMemberRole $change): void
    {
        $papel = AccountRole::tryFrom($role);
        abort_if($papel === null, 422);

        $this->run(fn () => $change->handle($this->user(), $uuid, $papel));

        session()->flash('account_status', __('panel.account.role_changed'));
    }

    public function startRemove(string $uuid, RemoveMember $remove): void
    {
        $remove->authorize($this->user());
        $this->removingUuid = $uuid;
    }

    public function cancelRemove(): void
    {
        $this->reset('removingUuid');
    }

    public function removeMember(RemoveMember $remove): void
    {
        $this->run(fn () => $remove->handle($this->user(), (string) $this->removingUuid));

        $this->cancelRemove();
        session()->flash('account_status', __('panel.account.member_removed'));
    }

    // =========================================================================
    // Sair da conta
    // =========================================================================

    public function startLeave(): void
    {
        $this->confirmingLeave = true;
    }

    public function cancelLeave(): void
    {
        $this->confirmingLeave = false;
    }

    public function leave(LeaveAccount $leave): void
    {
        $nome = $this->account()->displayName();

        $this->run(fn () => $leave->handle($this->user()));

        session()->flash('status', __('panel.account.left', ['account' => $nome]));
        $this->redirectRoute('dashboard');
    }

    // =========================================================================
    // Transferir a propriedade e excluir a conta (ações sensíveis)
    // =========================================================================

    public function requestTransfer(TransferOwnership $transfer): void
    {
        // A pré-checagem do papel é a da Action: a recusa fica na trilha.
        $transfer->authorize($this->user());
        $this->validate(['transferTo' => ['required', 'uuid']], [], ['transferTo' => __('panel.account.transfer_to')]);
        $this->requireTransactionPassword('transferTo');

        $this->openSensitiveModal('transfer');
    }

    public function requestDelete(DeleteAccount $delete): void
    {
        $delete->authorize($this->user());
        $this->requireTransactionPassword('deleteAccount');

        $this->openSensitiveModal('delete');
    }

    protected function performSensitiveAction(string $action, string $token): void
    {
        match ($action) {
            'transfer' => $this->performTransfer($token),
            'delete' => $this->performDelete($token),
            default => null,
        };
    }

    private function performTransfer(string $token): void
    {
        $this->run(fn () => app(TransferOwnership::class)->handle($this->user(), $this->transferTo, $token), [], 'transferTo');

        $this->reset('transferTo');
        session()->flash('account_status', __('panel.account.transferred'));
    }

    private function performDelete(string $token): void
    {
        $nome = $this->account()->displayName();

        $this->run(fn () => app(DeleteAccount::class)->handle($this->user(), $token), [], 'deleteAccount');

        session()->flash('status', __('panel.account.deleted', ['account' => $nome]));
        $this->redirectRoute('dashboard');
    }

    // =========================================================================
    // Tela
    // =========================================================================

    public function render(): View
    {
        $account = $this->account();
        $papel = Accounts::roleOf($this->user());
        $members = $this->members($account, $papel);

        return view('livewire.account.show', [
            'account' => $account,
            'role' => $papel,
            'members' => $members,
            'invitations' => $this->invitations(),
            'canRename' => ! $account->isPersonal() && Accounts::can(AccountAbility::UpdateAccount),
            'canInvite' => Accounts::can(AccountAbility::ManageMembers),
            'canTransfer' => ! $account->isPersonal() && Accounts::can(AccountAbility::TransferOwnership),
            'canDelete' => ! $account->isPersonal() && Accounts::can(AccountAbility::DeleteAccount),
            'canLeave' => MemberRules::canLeave($papel),
            'transferCandidates' => $members->reject(fn (array $m): bool => $m['role'] === AccountRole::Owner),
            'hasTransactionPassword' => $this->user()->hasTransactionPassword(),
            'sensitiveDescription' => match ($this->pendingAction) {
                'transfer' => __('panel.account.transfer_confirm', ['name' => $members->firstWhere('uuid', $this->transferTo)['name'] ?? '']),
                'delete' => __('panel.account.delete_confirm', ['account' => $account->displayName()]),
                default => null,
            },
        ])->title(__('panel.account.title'));
    }

    /**
     * Membros da conta atual, com o papel e o que QUEM ESTÁ VENDO pode fazer
     * com cada um (a mesma regra que a Action aplica).
     *
     * @return Collection<int, array{uuid: string, name: string, email: string, role: AccountRole, self: bool, user: User, canPromote: bool, canDemote: bool, canRemove: bool, joined: mixed}>
     */
    private function members(Account $account, ?AccountRole $papel): Collection
    {
        $ordem = [AccountRole::Owner->value => 0, AccountRole::Admin->value => 1, AccountRole::Member->value => 2];

        return $this->directory()->members($account)
            ->map(function (User $pessoa) use ($papel): array {
                $role = AccountRole::from((string) $pessoa->pivot->getAttribute('role'));
                $self = $pessoa->is($this->user());

                return [
                    'uuid' => (string) $pessoa->uuid,
                    'name' => (string) $pessoa->name,
                    'email' => (string) $pessoa->email,
                    'role' => $role,
                    'self' => $self,
                    'user' => $pessoa,
                    'canPromote' => MemberRules::canChangeRole($papel, $role, AccountRole::Admin, $self),
                    'canDemote' => MemberRules::canChangeRole($papel, $role, AccountRole::Member, $self),
                    'canRemove' => MemberRules::canRemove($papel, $role, $self),
                    'joined' => $pessoa->pivot->getAttribute('created_at'),
                ];
            })
            ->sortBy(fn (array $m): string => $ordem[$m['role']->value].mb_strtolower($m['name']))
            ->values();
    }

    /**
     * Convites em aberto da conta atual (pendentes e expirados — os que ainda
     * podem ser reenviados ou revogados). Só para quem gere membros.
     *
     * @return Collection<int, AccountInvitation>
     */
    private function invitations(): Collection
    {
        if (! Accounts::can(AccountAbility::ManageMembers)) {
            return collect();
        }

        return $this->directory()->openInvitations();
    }

    /**
     * Roda a Action e devolve os erros de validação dela nos campos DESTA
     * tela (a Action usa os nomes do domínio: `email`, `name`...).
     *
     * @param  array<string, string>  $campos  campo da Action => campo da tela
     */
    private function run(\Closure $acao, array $campos = [], ?string $padrao = null): mixed
    {
        try {
            return $acao();
        } catch (ValidationException $exception) {
            $erros = [];

            foreach ($exception->errors() as $campo => $mensagens) {
                $erros[$campos[$campo] ?? $padrao ?? $campo] = $mensagens;
            }

            throw ValidationException::withMessages($erros);
        }
    }

    private function requireTransactionPassword(string $campo): void
    {
        if (! $this->user()->hasTransactionPassword()) {
            throw ValidationException::withMessages([$campo => __('panel.account.sensitive_requires_password')]);
        }
    }

    /**
     * Resolvido a cada chamada: componente Livewire é serializado entre
     * requisições, e consulta não é estado da tela.
     */
    private function directory(): AccountDirectory
    {
        return app(AccountDirectory::class);
    }

    private function account(): Account
    {
        return Accounts::currentOrFail();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
