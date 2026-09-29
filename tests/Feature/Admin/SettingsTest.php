<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Admin\Pages\Settings;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Settings\Models\Setting;
use Twstec\Kit\Foundation\Settings\SettingsManager;

// =============================================================================
// Settings editáveis pelo super admin: tabela settings sobrescreve
// o .env em runtime, sem editar arquivo. Somente a whitelist de
// config/settings.php é gravável.
// =============================================================================

// A chave de exemplo das telas: a expiração das chaves de API (pacote de
// contas). Sem ele (twstec/kit-accounts é opcional), a mesma prova usa o
// limite das rotas sensíveis — outra chave da whitelist, com limites (1–100)
// que os valores abaixo respeitam e o 999 estoura.
beforeEach(function (): void {
    $this->chave = Kit::has('accounts') ? 'api_keys.inactivity.months' : 'security.rate_limit.sensitive';
    $this->campo = str_replace('.', '_', $this->chave);
});

it('nega a tela de configurações a não-admin (403)', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/settings')
        ->assertForbidden();
});

it('salva overrides pela UI e o valor efetivo passa a vir do banco', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(Settings::class)
        ->assertOk()
        ->set('data.'.$this->campo, 6)
        ->set('data.security_rate_limit_api', 120)
        ->call('save')
        ->assertHasNoFormErrors();

    // Os overrides estão gravados na tabela...
    expect(Setting::query()->where('key', $this->chave)->exists())->toBeTrue();

    // ...e a leitura efetiva (helper setting()) reflete o banco, não o .env.
    expect(setting($this->chave))->toBe(6)
        ->and(setting('security.rate_limit.api'))->toBe(120);
});

it('applyToConfig aplica os overrides por cima do config (boot do provider)', function () {
    $manager = app(SettingsManager::class);
    $manager->set($this->chave, 9);

    $manager->applyToConfig();

    expect(config($this->chave))->toBe(9);
});

it('campo vazio remove o override e volta ao valor do .env', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $original = config($this->chave);

    app(SettingsManager::class)->set($this->chave, 12);

    Livewire::actingAs($admin)
        ->test(Settings::class)
        ->set('data.'.$this->campo, null)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::query()->where('key', $this->chave)->exists())->toBeFalse()
        ->and(setting($this->chave))->toBe($original);
});

it('valida os limites declarados na whitelist', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(Settings::class)
        ->set('data.'.$this->campo, 999) // acima do máximo (36)
        ->call('save')
        ->assertHasFormErrors([$this->campo]);

    expect(Setting::query()->count())->toBe(0);
});

it('rejeita chave fora da whitelist (defesa contra gravação arbitrária)', function () {
    app(SettingsManager::class)->set('app.key', 1);
})->throws(InvalidArgumentException::class);
