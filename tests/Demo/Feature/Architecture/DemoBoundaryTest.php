<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Resources\Resource;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\Finder\Finder;
use Twstec\Kit\Admin\Dashboards\OverviewDashboard;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\BaseListRecords;
use Twstec\Kit\Admin\Support\BaseResource;
use Twstec\Kit\Admin\Widgets\Overview\LatestUploads;
use Twstec\Kit\Auth\Contracts\AccountProtection;
use Twstec\Kit\Auth\Contracts\LoginPrefillProvider;
use Twstec\Kit\Demo\Accounts\DemoAccountProtection;
use Twstec\Kit\Demo\Accounts\DemoLoginPrefill;
use Twstec\Kit\Demo\Database\Seeders\DemoSeeder;
use Twstec\Kit\Demo\Filament\Dashboards\ContentDashboard;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\Pages\ListFormSubmissions;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\Pages\ViewFormSubmission;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\CreateProduct;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\EditProduct;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\ListProducts;
use Twstec\Kit\Demo\Filament\Widgets\Overview\LatestSubmissions;
use Twstec\Kit\Demo\Support\DemoMailPreviewGate;
use Twstec\Kit\Foundation\Mail\Contracts\MailPreviewGate;

// =============================================================================
// A FRONTEIRA DA DEMONSTRAÇÃO, vista do lado da demo.
//
// As regras de arquitetura do produto (AdminAuditTest, TableViewModeTest,
// FormSubmissionsTest, LocaleTest…) varrem os diretórios do produto — as
// telas, views e traduções da demo moram no pacote twstec/kit-demo e ficariam
// sem trava. Aqui as MESMAS regras valem para o pacote, lido pelo vendor/
// como o aplicativo o vê, e se confere que o provider da demo liga cada ponto
// de extensão do produto. As regras que só leem arquivos do pacote (escrita
// fora dos eventos de model, recusa sem AdminAudit::denied(), traduções nos
// três idiomas, echo cru nas views) rodam na suíte do próprio pacote
// (packages/demo/tests/Architecture/DemoRulesTest.php).
// =============================================================================

/**
 * Arquivos PHP das telas da demo no /admin (src/Filament do pacote).
 *
 * @return array<string, string> caminho relativo => conteúdo
 */
function demoFilamentSources(): array
{
    $sources = [];

    foreach ((new Finder)->files()->in(base_path('vendor/twstec/kit-demo/src/Filament'))->name('*.php') as $file) {
        // Caminho pelo vendor (não pelo link do monorepo).
        $sources[str_replace(base_path().'/', '', $file->getPath().'/'.$file->getFilename())] = $file->getContents();
    }

    ksort($sources);

    return $sources;
}

/**
 * Classe declarada num arquivo do src/ do pacote (PSR-4 do Twstec\Kit\Demo\).
 */
function demoClassOf(string $path): string
{
    return 'Twstec\\Kit\\Demo\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($path, 'kit-demo/src/'));
}

it('o provider da demo liga cada ponto de extensão do produto', function (): void {
    expect(app(AccountProtection::class))->toBeInstanceOf(DemoAccountProtection::class)
        ->and(app(LoginPrefillProvider::class))->toBeInstanceOf(DemoLoginPrefill::class)
        ->and(app(MailPreviewGate::class))->toBeInstanceOf(DemoMailPreviewGate::class)
        ->and(array_map(fn (object $seeder): string => $seeder::class, iterator_to_array(app()->tagged(DatabaseSeeder::EXTENSION_TAG))))->toBe([DemoSeeder::class])
        ->and(config('dashboards.variants.content.page'))->toBe(ContentDashboard::class)
        ->and(config('audit.admin_extension_namespaces'))->toContain('Twstec\\Kit\\Demo\\Filament\\')
        ->and(config('security.headers.surfaces.landing_alt'))->toBe(['v2'])
        ->and(app(OverviewDashboard::class)->getWidgets())->toContain(LatestSubmissions::class);

    $widgets = app(OverviewDashboard::class)->getWidgets();

    expect(array_search(LatestSubmissions::class, $widgets, true))
        ->toBe(array_search(LatestUploads::class, $widgets, true) - 1);
});

it('toda tela da demo no /admin está coberta pelo escopo de auditoria', function (): void {
    $componentes = [];

    foreach (array_keys(demoFilamentSources()) as $path) {
        $class = demoClassOf($path);

        if (class_exists($class) && is_subclass_of($class, Component::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $componentes[] = $class;

            expect(AdminAudit::covers($class))->toBeTrue("{$class} não passa pela trilha de auditoria");
        }
    }

    expect($componentes)->toContain(
        ListProducts::class,
        CreateProduct::class,
        EditProduct::class,
        ListFormSubmissions::class,
        ViewFormSubmission::class,
        ContentDashboard::class,
        LatestSubmissions::class,
    );
});

it('todo resource da demo estende as bases do kit e traduz rótulo e grupo', function (): void {
    $resources = [];
    $listagens = [];

    foreach (array_keys(demoFilamentSources()) as $path) {
        $class = demoClassOf($path);

        if (! class_exists($class)) {
            continue;
        }

        if (is_subclass_of($class, Resource::class)) {
            $resources[] = $class;
        }

        if (str_contains($path, '/Pages/List')) {
            $listagens[] = $class;
        }
    }

    expect($resources)->not->toBeEmpty()
        ->and($listagens)->not->toBeEmpty();

    foreach ($resources as $class) {
        expect(is_subclass_of($class, BaseResource::class))->toBeTrue("{$class} precisa estender App\\Filament\\Support\\BaseResource")
            ->and($class::getModelLabel())->not->toContain('admin.')
            ->and($class::getPluralModelLabel())->not->toContain('admin.')
            ->and($class::getNavigationGroup())->not->toContain('admin.');
    }

    foreach ($listagens as $class) {
        expect(is_subclass_of($class, BaseListRecords::class))->toBeTrue("{$class} precisa estender App\\Filament\\Support\\BaseListRecords");
    }
});

it('nenhuma tela do produto baixa os bundles das landings', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    $bundles = array_map(
        fn (string $entrada): string => $manifest[$entrada]['file'],
        ['vendor/twstec/kit-demo/resources/js/landing.js', 'vendor/twstec/kit-demo/resources/css/landing.css', 'vendor/twstec/kit-demo/resources/js/landing-v2.js', 'vendor/twstec/kit-demo/resources/css/landing-v2.css'],
    );

    $painel = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();

    foreach ($bundles as $bundle) {
        expect($painel)->not->toContain($bundle);
    }

    // E a landing, sim, baixa o dela — a verificação acima não é vazia.
    expect($this->get('/')->assertOk()->getContent())->toContain($manifest['vendor/twstec/kit-demo/resources/js/landing.js']['file']);
});

it('o layout público da demo é o esqueleto único do site', function (): void {
    // As MESMAS asserções que tests/Feature/Architecture/DesignSystemTest.php
    // fazia sobre components/layouts/landing quando ele morava no aplicativo:
    // o apelido público (landing e vitrine) passa pelo <x-layouts.site> e não
    // escreve o próprio <header>/<footer>.
    foreach (['components/layouts/landing'] as $layout) {
        expect((string) file_get_contents(base_path("vendor/twstec/kit-demo/resources/views/{$layout}.blade.php")))
            ->toContain('<x-layouts.site');
    }

    foreach (['components/layouts/landing'] as $layout) {
        expect((string) file_get_contents(base_path("vendor/twstec/kit-demo/resources/views/{$layout}.blade.php")))
            ->not->toContain('<header')
            ->not->toContain('<footer');
    }
});
