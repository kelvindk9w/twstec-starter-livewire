<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// =============================================================================
// SÓ NO REPOSITÓRIO DO KIT (export-ignore: fora do pacote publicado e do
// projeto criado). O starter não traz cópia das telas do kit: em
// resources/views só o que é dele. No projeto criado, telas novas e cópias
// personalizadas são o uso normal — por isso a trava não vai para lá.
// =============================================================================

it('o starter não traz cópia das telas do kit: só o que é dele', function (): void {
    $application = collect(File::allFiles(resource_path('views')))
        ->map(fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()))
        ->sort()
        ->values()
        ->all();

    expect($application)->toBe(['home.blade.php', 'livewire/dashboard.blade.php']);
});
