<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Twstec\Kit\Foundation\Customization\LivewireScreens;
use Twstec\Kit\Foundation\Customization\PackageViews;

// =============================================================================
// TELAS DO KIT NO PACOTE (issue #72, etapa 2): as views das telas do kit
// (entrada, perfil, contas, chaves de API, layouts, componentes do design
// system…) moram no twstec/kit-foundation (resources/livewire), com os nomes
// de sempre, e O APLICATIVO VENCE — o arquivo de mesmo caminho em
// resources/views vale no lugar do pacote. O projeto criado não recebe cópias.
// =============================================================================

/**
 * Uma pasta de views "do aplicativo" só deste teste, na frente da busca — como
 * o resources/views (que fica fora: os testes rodam em paralelo e leem a
 * mesma pasta).
 *
 * @param  array<string, string>  $views
 */
function applicationViews(array $views): void
{
    $dir = storage_path('framework/testing/views-'.bin2hex(random_bytes(4)));

    foreach ($views as $relative => $contents) {
        File::ensureDirectoryExists(dirname("{$dir}/{$relative}"));
        File::put("{$dir}/{$relative}", $contents);
    }

    app('view')->getFinder()->prependLocation($dir);
    app('view')->getFinder()->flush();

    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));
}

it('o aplicativo não traz cópia das telas do kit: só o que é dele', function (): void {
    $application = collect(File::allFiles(resource_path('views')))
        ->map(fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()))
        ->sort()
        ->values()
        ->all();

    expect($application)->toBe(['home.blade.php', 'livewire/dashboard.blade.php'])
        ->and(PackageViews::paths())->toContain(LivewireScreens::path());

    foreach (['auth/login.blade.php', 'layouts/app.blade.php', 'components/button.blade.php', 'livewire/profile.blade.php'] as $kitView) {
        expect(kitViewsPath($kitView))->toBeFile();
    }
});

it('sem a tela no aplicativo, vale a do kit', function (): void {
    expect(app('view')->getFinder()->find('auth.login'))->toBe(LivewireScreens::path().'/auth/login.blade.php');

    $this->get('/login')->assertOk()->assertSee('name="email"', false);
});

it('o resources/views do aplicativo vem antes das telas do kit na busca', function (): void {
    $paths = app('view')->getFinder()->getPaths();

    expect(array_search(resource_path('views'), $paths, true))->toBeLessThan(array_search(LivewireScreens::path(), $paths, true));
});

it('com a tela no aplicativo, vale a dele', function (): void {
    applicationViews(['auth/login.blade.php' => '<p>login do aplicativo</p>']);

    $this->get('/login')->assertOk()->assertSee('login do aplicativo')->assertDontSee('name="email"', false);
});

it('componente do aplicativo vale também dentro das telas do kit', function (): void {
    // <x-button> é usado pela tela de login do kit; a versão do aplicativo
    // troca o botão em todas as telas.
    applicationViews(['components/button.blade.php' => '<button {{ $attributes }}>botão do aplicativo</button>']);

    $this->get('/login')->assertOk()->assertSee('botão do aplicativo');
});
