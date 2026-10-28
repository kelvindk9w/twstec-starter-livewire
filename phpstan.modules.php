<?php

declare(strict_types=1);

// =============================================================================
// ANÁLISE ESTÁTICA × MÓDULOS OPCIONAIS (incluído pelo phpstan.neon.dist).
//
// As telas de um módulo opcional ficam no app/ mesmo quando o módulo não é
// instalado: as rotas e o menu não as registram (Kit::has), e o
// `php artisan tws:add <módulo>` as reaproveita depois. Sem o pacote, porém,
// elas citam classes que não existem — e o Larastan reprovaria o projeto.
//
// Aqui, para cada módulo AUSENTE do vendor/:
//
// - os arquivos EXCLUSIVOS dele saem da análise (continuam conhecidos pelo
//   PHPStan — `excludePaths.analyse` —, só não são analisados);
// - nos arquivos MISTOS (código da base que, protegido por Kit::has, cita o
//   módulo), só os erros que mencionam as classes do módulo são ignorados,
//   e só naquele arquivo.
//
// Com todos os módulos instalados, nada é excluído nem ignorado. Arquivo novo
// que cite um módulo opcional entra no mapa abaixo — senão a análise de um
// projeto sem o módulo reprova, e é assim que se descobre.
// =============================================================================

$modules = [
    'webhooks' => [
        'namespaces' => ['Twstec\\Kit\\Webhooks\\'],
        'exclusive' => [
            'app/Livewire/Webhooks',
        ],
        'mixed' => [],
    ],
    'uploads' => [
        'namespaces' => ['Twstec\\Kit\\Uploads\\'],
        'exclusive' => [],
        'mixed' => [
            'app/Livewire/Profile.php',
        ],
    ],
    'admin' => [
        'namespaces' => ['Twstec\\Kit\\Admin\\', 'Filament\\'],
        'exclusive' => [
            'app/Providers/Filament',
        ],
        'mixed' => [
            'app/Providers/HorizonServiceProvider.php',
        ],
    ],
    'accounts' => [
        'namespaces' => ['Twstec\\Kit\\Accounts\\'],
        'exclusive' => [
            'app/Http/Controllers/Accounts',
            'app/Livewire/Account',
            'app/Livewire/ApiKeys',
            'app/Livewire/Projects',
            'app/Livewire/Support/AccountMenu.php',
        ],
        'mixed' => [
            'app/Livewire/Dashboard.php',
        ],
    ],
];

$exclude = [];
$ignore = [];

foreach ($modules as $module => $map) {
    if (is_dir(__DIR__.'/vendor/twstec/kit-'.$module)) {
        continue;
    }

    foreach ($map['exclusive'] as $path) {
        if (file_exists(__DIR__.'/'.$path)) {
            $exclude[] = __DIR__.'/'.$path;
        }
    }

    foreach ($map['mixed'] as $path) {
        if (! file_exists(__DIR__.'/'.$path)) {
            continue;
        }

        foreach ($map['namespaces'] as $namespace) {
            $ignore[] = [
                'message' => '#'.preg_quote($namespace, '#').'#',
                'path' => __DIR__.'/'.$path,
                'reportUnmatched' => false,
            ];
        }
    }
}

return [
    'parameters' => [
        'excludePaths' => ['analyse' => $exclude],
        'ignoreErrors' => $ignore,
    ],
];
