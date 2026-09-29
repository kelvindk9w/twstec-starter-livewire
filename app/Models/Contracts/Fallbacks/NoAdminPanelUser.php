<?php

declare(strict_types=1);

namespace App\Models\Contracts\Fallbacks;

/**
 * O lugar do contrato do Filament no model de usuário quando o
 * twstec/kit-admin (e, com ele, o Filament) NÃO está instalado. Ver
 * app/Support/optional-modules.php.
 */
interface NoAdminPanelUser {}
