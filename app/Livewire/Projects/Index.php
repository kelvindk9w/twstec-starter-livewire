<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Support\AccountResourceGuard;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tenancy\Enums\ProjectAttempt;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tenancy\Services\ProjectService;

/**
 * Projetos — CRUD só com nome, TUDO na mesma tela: criar e editar
 * inline, excluir com confirmação inline. Sem labirinto de cliques.
 *
 * A regra mora no ProjectService, o mesmo que a API v1 usa (os projetos são
 * da CONTA ATUAL: uuid de outra conta = 404; código público com nova
 * tentativa). A tela só confere o papel da pessoa na conta, valida o
 * formulário, chama o serviço e mostra o resultado.
 *
 * Toda recusa fica na trilha (AccountResourceGuard): o papel que não permite
 * (403) e o projeto que não está na conta atual (o mesmo 404 de sempre) gravam
 * `denied` com a ação tentada.
 */
final class Index extends Component
{
    /** Form inline de criação. */
    public bool $showCreateForm = false;

    public string $name = '';

    /** Edição inline: uuid do projeto sendo editado (null = nenhum). */
    public ?string $editingUuid = null;

    public string $editingName = '';

    /** Exclusão: uuid aguardando confirmação (null = nenhum). */
    public ?string $confirmingDeleteUuid = null;

    /**
     * @return Collection<int, Project>
     */
    public function projects(): Collection
    {
        return $this->service()->list();
    }

    public function startCreate(): void
    {
        $this->guard()->authorize(AccountAbility::CreateProjects, ProjectAttempt::Created);
        $this->resetValidation();
        $this->reset('name');
        $this->showCreateForm = true;
    }

    public function cancelCreate(): void
    {
        $this->showCreateForm = false;
        $this->reset('name');
    }

    public function create(): void
    {
        $this->guard()->authorize(AccountAbility::CreateProjects, ProjectAttempt::Created);
        $this->validate(['name' => ['required', 'string', 'max:255']], [], [
            'name' => __('panel.common.name'),
        ]);

        $this->service()->create($this->user(), $this->name);

        $this->cancelCreate();
        session()->flash('projects_status', __('panel.projects.created'));
    }

    public function startEdit(string $uuid): void
    {
        $this->guard()->authorize(AccountAbility::UpdateProjects, ProjectAttempt::Updated, $uuid);
        $project = $this->findOwned($uuid, ProjectAttempt::Updated);

        $this->resetValidation();
        $this->editingUuid = $uuid;
        $this->editingName = (string) $project->name;
        $this->showCreateForm = false;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingUuid', 'editingName');
    }

    public function update(): void
    {
        $this->guard()->authorize(AccountAbility::UpdateProjects, ProjectAttempt::Updated, $this->editingUuid);
        $this->validate(['editingName' => ['required', 'string', 'max:255']], [], [
            'editingName' => __('panel.common.name'),
        ]);

        $this->service()->update(
            $this->findOwned((string) $this->editingUuid, ProjectAttempt::Updated),
            ['name' => $this->editingName],
        );

        $this->cancelEdit();
        session()->flash('projects_status', __('panel.projects.updated'));
    }

    public function startDelete(string $uuid): void
    {
        $this->guard()->authorize(AccountAbility::DeleteProjects, ProjectAttempt::Deleted, $uuid);
        $this->findOwned($uuid, ProjectAttempt::Deleted);
        $this->confirmingDeleteUuid = $uuid;
    }

    public function cancelDelete(): void
    {
        $this->reset('confirmingDeleteUuid');
    }

    /**
     * Exclui o projeto em confirmação.
     *
     * O nome NÃO pode ser `delete`: o Livewire 4 em modo CSP-safe compila a
     * expressão de `wire:click` com um parser de JS, e `delete` é PALAVRA
     * RESERVADA da linguagem (operador). O resultado era um erro de parser no
     * console e a exclusão nunca acontecia — a ação parecia inerte. Toda ação
     * Livewire do kit evita nomes reservados do JavaScript (delete, new,
     * class, default, typeof, in, …).
     */
    public function removeProject(): void
    {
        $this->guard()->authorize(AccountAbility::DeleteProjects, ProjectAttempt::Deleted, $this->confirmingDeleteUuid);

        // O vínculo N:N cai junto; a chave que só atendia este projeto segue
        // restrita, agora a nenhum (ver ProjectService::delete()).
        $this->service()->delete($this->findOwned((string) $this->confirmingDeleteUuid, ProjectAttempt::Deleted));

        $this->cancelDelete();
        session()->flash('projects_status', __('panel.projects.deleted'));
    }

    public function render(): View
    {
        return view('livewire.projects.index', [
            'projects' => $this->projects(),
            'canCreate' => Accounts::can(AccountAbility::CreateProjects),
            'canUpdate' => Accounts::can(AccountAbility::UpdateProjects),
            'canDelete' => Accounts::can(AccountAbility::DeleteProjects),
        ])->title(__('panel.projects.title'));
    }

    /**
     * Projeto da CONTA ATUAL por UUID — de outra conta = 404 (nem confirma que
     * existe), com a tentativa na trilha.
     */
    private function findOwned(string $uuid, ProjectAttempt $attempt): Project
    {
        return $this->guard()->project($uuid, $attempt);
    }

    private function guard(): AccountResourceGuard
    {
        return app(AccountResourceGuard::class);
    }

    /**
     * Resolvido a cada chamada: componente Livewire é serializado entre
     * requisições, e serviço não é estado da tela.
     */
    private function service(): ProjectService
    {
        return app(ProjectService::class);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
