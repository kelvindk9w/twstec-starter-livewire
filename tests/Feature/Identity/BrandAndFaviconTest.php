<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Foundation\Support\Platform;

// =============================================================================
// MARCA E ÍCONE DA ABA DO PROJETO: sem logotipo, site, painel e /admin
// mostram a inicial do nome configurado (nunca o desenho do kit); com
// PLATFORM_FAVICON_URL, TODA página — site, telas de entrada, painel,
// galeria, página 429 e /admin — aponta para ele; e o projeto não carrega
// arquivo de marca nem favicon do kit em public/.
// =============================================================================

const KIT_MARK_PATH = 'M9 11h14M16 11v11';

/**
 * @param  array<string, mixed>  $platform
 */
function brandingWith(array $platform): void
{
    config(collect($platform)->mapWithKeys(fn (mixed $value, string $key): array => ["platform.{$key}" => $value])->all());
    config(['mail.preview.enabled' => true, 'ui.demo_login.enabled' => true]);
    app()->forgetInstance(Platform::class);
}

beforeEach(function (): void {
    brandingWith(['name' => 'Zeta Cobranças', 'logo_url' => null, 'favicon_url' => null, 'favicon_svg_url' => null]);
});

dataset('pages with head', [
    'página inicial' => [fn () => (string) test()->view('home')],
    'entrada' => [fn () => test()->get('/login')->assertOk()->getContent()],
    'galeria' => [fn () => test()->get('/mail-preview')->assertOk()->getContent()],
    'painel' => [fn () => test()->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent()],
    'página 429' => [fn () => (string) test()->view('errors.429')],
]);

it('sem logotipo, a marca do site e do painel é a inicial do nome', function (): void {
    $this->actingAs(User::factory()->create());

    foreach ([(string) $this->view('home'), $this->get('/dashboard')->assertOk()->getContent()] as $html) {
        expect($html)->toMatch('/data-brand-initial[^>]*>Z<\/span>/')
            ->not->toContain(KIT_MARK_PATH)
            ->not->toContain('brand-mark.svg');
    }
});

it('sem logotipo, a marca do /admin é a inicial do nome', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    expect($this->get('/admin')->assertOk()->getContent())->toContain('>Z</text>')
        ->not->toContain(KIT_MARK_PATH);
})->group('admin');

it('sem favicon nem logotipo, o ícone da aba é a inicial gerada', function (Closure $render): void {
    $html = $render();

    expect($html)->toMatch('/<link rel="icon" href="data:image\/svg\+xml;base64,[^"]+" type="image\/svg\+xml">/')
        ->not->toContain('/favicon.ico')
        ->not->toContain('/favicon.svg');
})->with('pages with head');

it('com PLATFORM_FAVICON_URL e a variante SVG, toda página aponta para eles', function (Closure $render): void {
    brandingWith(['favicon_url' => 'https://cdn.zeta.test/favicon.ico', 'favicon_svg_url' => 'https://cdn.zeta.test/favicon.svg']);

    expect($render())
        ->toContain('<link rel="icon" href="https://cdn.zeta.test/favicon.ico" sizes="any">')
        ->toContain('<link rel="icon" href="https://cdn.zeta.test/favicon.svg" type="image/svg+xml">')
        ->not->toContain('data:image/svg+xml;base64');
})->with('pages with head');

it('com PLATFORM_FAVICON_URL, o /admin também aponta para ele', function (): void {
    brandingWith(['favicon_url' => 'https://cdn.zeta.test/favicon.ico']);
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    expect($this->get('/admin')->assertOk()->getContent())->toContain('href="https://cdn.zeta.test/favicon.ico"');
})->group('admin');

it('o projeto não tem arquivo de marca nem favicon do kit em public/', function (): void {
    foreach (['img/brand-mark.svg', 'favicon.ico', 'favicon.svg'] as $file) {
        expect(file_exists(public_path($file)))->toBeFalse("public/{$file} é a marca do kit");
    }
});
