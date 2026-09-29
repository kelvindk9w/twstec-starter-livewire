<?php

declare(strict_types=1);

use App\Models\Concerns\Fallbacks\NoAdminPanelAccess;
use App\Models\Concerns\Fallbacks\NoProfilePhoto;
use App\Models\Contracts\Fallbacks\NoAdminPanelUser;
use Twstec\Kit\Foundation\Kit;

// =============================================================================
// PONTES PARA OS MÓDULOS OPCIONAIS no model de usuário.
//
// O model App\Models\User compõe o que os pacotes trazem — e dois pedaços são
// de módulos OPCIONAIS: o acesso ao /admin (trait e contrato do Filament, do
// twstec/kit-admin) e a foto de perfil (trait do twstec/kit-uploads). Um `use`
// de trait ou um `implements` de interface que não existe derruba a classe
// inteira, e com ela o aplicativo — sem o pacote, nem o login funcionaria.
//
// Por isso o model usa NOMES DO APLICATIVO, e é aqui que cada nome vira a peça
// de verdade (módulo instalado) ou a peça neutra (módulo ausente), decidido
// pelo ponto único de detecção do kit (Kit::has):
//
//   App\Models\Concerns\OptionalAdminPanelAccess
//       admin instalado → Twstec\Kit\Admin\Concerns\AccessesAdminPanel
//       ausente         → App\Models\Concerns\Fallbacks\NoAdminPanelAccess
//   App\Models\Contracts\OptionalAdminPanelUser
//       admin instalado → Filament\Models\Contracts\FilamentUser
//       ausente         → App\Models\Contracts\Fallbacks\NoAdminPanelUser
//   App\Models\Concerns\OptionalProfilePhoto
//       uploads instalado → Twstec\Kit\Uploads\Concerns\HasAvatar
//       ausente           → App\Models\Concerns\Fallbacks\NoProfilePhoto
//
// É um APELIDO da mesma classe (class_alias): `instanceof` e type hints
// aceitam os dois nomes. O carregamento é PREGUIÇOSO e vem depois do Composer
// (este arquivo entra por composer.json → autoload.files).
// =============================================================================

spl_autoload_register(static function (string $class): void {
    static $bridges = [
        'App\\Models\\Concerns\\OptionalAdminPanelAccess' => ['admin', 'Twstec\\Kit\\Admin\\Concerns\\AccessesAdminPanel', NoAdminPanelAccess::class],
        'App\\Models\\Contracts\\OptionalAdminPanelUser' => ['admin', 'Filament\\Models\\Contracts\\FilamentUser', NoAdminPanelUser::class],
        'App\\Models\\Concerns\\OptionalProfilePhoto' => ['uploads', 'Twstec\\Kit\\Uploads\\Concerns\\HasAvatar', NoProfilePhoto::class],
    ];

    if (! isset($bridges[$class])) {
        return;
    }

    [$module, $real, $fallback] = $bridges[$class];

    class_alias(Kit::has($module) ? $real : $fallback, $class);
});
