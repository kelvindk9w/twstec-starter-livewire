<?php

declare(strict_types=1);

use App\Livewire\Support\Navigation;
use App\Livewire\Support\SiteLinks;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Twstec\Kit\Admin\Dashboards\DashboardRegistry;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\AttackLabel;
use Twstec\Kit\Admin\Widgets\Growth\GrowthStats;
use Twstec\Kit\Admin\Widgets\Overview\LatestUploads;
use Twstec\Kit\Admin\Widgets\Overview\OverviewStats;
use Twstec\Kit\Admin\Widgets\Overview\RequestsStatusChart;
use Twstec\Kit\Admin\Widgets\Overview\RequestsTrendChart;
use Twstec\Kit\Auth\Contracts\AccountProtection;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Contracts\LoginPrefillProvider;
use Twstec\Kit\Auth\Exceptions\AccountProtectedException;
use Twstec\Kit\Auth\Support\LoginPrefill;
use Twstec\Kit\Auth\Support\ProtectedAccounts;
use Twstec\Kit\Foundation\Mail\Contracts\MailPreviewGate;
use Twstec\Kit\Foundation\Mail\Support\ConfiguredMailPreviewGate;

// =============================================================================
// PONTOS DE EXTENSÃO DO PRODUTO — o comportamento SEM extensão registrada.
//
// O produto não conhece a demonstração (twstec/kit-demo): onde precisava perguntar
// algo a ela, pergunta a um ponto de extensão neutro. Estes testes tiram do
// container o que a demo registra e provam que o produto responde sozinho,
// do jeito seguro: nenhuma conta protegida, nenhum login pré-preenchido,
// galeria de e-mails só pela flag do produto e nunca em produção, nenhum
// seeder, nenhum link de site além dos do produto.
// =============================================================================

it('sem extensão, nenhuma conta é protegida nem reservada e o model não recusa nada', function (): void {
    app()->offsetUnset(AccountProtection::class);

    $user = User::factory()->create(['email_verified_at' => null]);

    expect(ProtectedAccounts::protection())->toBeNull()
        ->and(ProtectedAccounts::protects($user))->toBeFalse()
        ->and(ProtectedAccounts::reserves($user))->toBeFalse()
        ->and($user->isReservedAccount())->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    $user->forceFill(['is_admin' => true, 'status' => 'blocked'])->save();
    $user->delete();

    expect(User::query()->whereKey($user->getKey())->exists())->toBeFalse();
});

it('com uma extensão registrada, o model e as guardas perguntam a ela', function (): void {
    $protegida = User::factory()->create(['email_verified_at' => null]);

    app()->instance(AccountProtection::class, new class($protegida->getKey()) implements AccountProtection
    {
        public function __construct(private readonly int $id) {}

        public function reserves(AuthUser $user): bool
        {
            return $user->getKey() === $this->id;
        }

        public function protects(AuthUser $user): bool
        {
            return $this->reserves($user);
        }

        public function guardUpdate(AuthUser $user): void
        {
            if ($this->protects($user) && $user->isDirty('is_admin')) {
                throw new AccountProtectedException('protegida');
            }
        }

        public function guardDelete(AuthUser $user): void
        {
            if ($this->protects($user)) {
                throw new AccountProtectedException('protegida');
            }
        }
    });

    expect($protegida->isReservedAccount())->toBeTrue()
        ->and($protegida->hasVerifiedEmail())->toBeTrue()
        ->and(fn () => $protegida->forceFill(['is_admin' => true])->save())->toThrow(AccountProtectedException::class)
        ->and(fn () => $protegida->fresh()->delete())->toThrow(AccountProtectedException::class);

    // Campo que a extensão não protege continua livre.
    $protegida->fresh()->forceFill(['name' => 'Outro nome'])->save();

    expect($protegida->fresh()->name)->toBe('Outro nome');
});

it('sem extensão, as telas de login nascem vazias', function (): void {
    // Com a flag do login demo ligada, a DEMO preencheria — sem a extensão
    // registrada, o produto não preenche nada. As credenciais são as da demo
    // (declaradas aqui: sem o pacote da demo, o config não as tem).
    config()->set('ui.demo_login', ['enabled' => true, 'email' => 'demo@tws.dev', 'password' => 'Demo-password1']);
    app()->offsetUnset(LoginPrefillProvider::class);

    expect(LoginPrefill::for('web'))->toBeNull()
        ->and(LoginPrefill::for('admin'))->toBeNull();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('name="email"', false)
        ->assertDontSee((string) config('ui.demo_login.email'))
        ->assertDontSee((string) config('ui.demo_login.password'));
});

it('a galeria de e-mails do produto segue a própria flag e nunca abre em produção', function (): void {
    $gate = new ConfiguredMailPreviewGate;

    config()->set('mail.preview.enabled', false);
    expect($gate->allows())->toBeFalse();

    config()->set('mail.preview.enabled', true);
    expect($gate->allows())->toBeTrue();

    app()->detectEnvironment(fn (): string => 'production');
    expect($gate->allows())->toBeFalse();
});

it('sem extensão, a galeria de e-mails usa a regra padrão do produto', function (): void {
    app()->offsetUnset(MailPreviewGate::class);
    app()->bind(MailPreviewGate::class, ConfiguredMailPreviewGate::class);

    config()->set('mail.preview.enabled', false);
    $this->get('/mail-preview')->assertNotFound();

    config()->set('mail.preview.enabled', true);
    $this->get('/mail-preview')->assertOk();
});

it('sem extensão, o db:seed do produto não semeia nada', function (): void {
    // Sem API pública para desmarcar: zera as marcas desta aplicação de teste.
    (fn () => $this->tags = [])->call(app());

    $antes = User::query()->count();

    app(DatabaseSeeder::class)->run();

    expect(iterator_to_array(app()->tagged(DatabaseSeeder::EXTENSION_TAG)))->toBe([])
        ->and(User::query()->count())->toBe($antes);
});

it('os links do site vêm das extensões, na ordem de sort, e o status da API fecha o rodapé', function (): void {
    app()->forgetInstance(SiteLinks::class);
    app()->singleton(SiteLinks::class);

    expect(Navigation::site())->toBe([])
        ->and(Navigation::footer())->toBe([['label' => __('landing.footer.api_status'), 'href' => url('/api/health')]]);

    $links = app(SiteLinks::class);
    $links->add(SiteLinks::FOOTER, 20, fn (): array => ['label' => 'B', 'href' => '/b']);
    $links->add(SiteLinks::FOOTER, 10, fn (): array => ['label' => 'A', 'href' => '/a']);
    $links->add(SiteLinks::HEADER, 5, fn (): array => ['label' => 'H', 'href' => '/h']);

    expect(array_column(Navigation::footer(), 'label'))->toBe(['A', 'B', __('landing.footer.api_status')])
        ->and(Navigation::site())->toBe([['label' => 'H', 'href' => '/h']]);
});

it('extensão acrescenta widget a uma variante de dashboard na posição pedida', function (): void {
    $base = [OverviewStats::class, RequestsStatusChart::class, LatestUploads::class];

    config()->set('dashboards.widgets.overview', []);
    expect(DashboardRegistry::widgets('overview', $base))->toBe($base);

    config()->set('dashboards.widgets.overview', [
        ['widget' => RequestsStatusChart::class, 'before' => OverviewStats::class], // já está: ignorado
        ['widget' => 'App\\NaoExiste\\Widget'],                                   // classe inexistente: ignorado
    ]);
    expect(DashboardRegistry::widgets('overview', $base))->toBe($base);

    config()->set('dashboards.widgets.overview', [
        ['widget' => RequestsTrendChart::class, 'before' => LatestUploads::class],
        ['widget' => GrowthStats::class],
    ]);
    expect(DashboardRegistry::widgets('overview', $base))->toBe([
        OverviewStats::class,
        RequestsStatusChart::class,
        RequestsTrendChart::class,
        LatestUploads::class,
        GrowthStats::class,
    ]);
})->group('admin');

it('telas de extensão no /admin só entram na trilha quando o namespace é registrado', function (): void {
    config()->set('audit.admin_extension_namespaces', []);

    expect(AdminAudit::covers('App\\Filament\\Qualquer\\Tela'))->toBeTrue()
        ->and(AdminAudit::covers('Filament\\Qualquer\\Tela'))->toBeTrue()
        ->and(AdminAudit::covers('Extensao\\Filament\\Tela'))->toBeFalse();

    config()->set('audit.admin_extension_namespaces', ['Extensao\\Filament\\']);

    expect(AdminAudit::covers('Extensao\\Filament\\Tela'))->toBeTrue();
})->group('admin');

it('o rótulo do tipo de ataque é traduzido e cai no genérico quando desconhecido', function (): void {
    expect(AttackLabel::for('xss'))->toBe(__('admin.submissions.attack_xss'))
        ->and(AttackLabel::for('tipo-novo'))->toBe(__('admin.submissions.attack_unknown'))
        ->and(AttackLabel::for(null))->toBe(__('admin.submissions.attack_unknown'));
})->group('admin');

it('a página inicial do produto mostra a plataforma e o caminho para entrar', function (): void {
    $html = view('home')->render();

    expect($html)->toContain(platform()->name)
        ->toContain(route('login'))
        ->toContain(route('register'));

    $this->actingAs(User::factory()->create());

    expect(view('home')->render())->toContain(route('dashboard'));
});
