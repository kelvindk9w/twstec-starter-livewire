<?php

declare(strict_types=1);

namespace App\Models\Concerns\Fallbacks;

/**
 * O lugar do acesso ao /admin no model de usuário quando o twstec/kit-admin
 * NÃO está instalado: não há painel, então não há o que liberar. Ver
 * app/Support/optional-modules.php.
 */
trait NoAdminPanelAccess {}
