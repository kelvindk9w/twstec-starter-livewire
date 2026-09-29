<?php

declare(strict_types=1);

namespace App\Models\Concerns\Fallbacks;

/**
 * A foto de perfil quando o twstec/kit-uploads NÃO está instalado: não existe
 * foto, e as telas desenham as iniciais (<x-avatar>). Ver
 * app/Support/optional-modules.php.
 */
trait NoProfilePhoto
{
    /**
     * Sem o pacote de uploads, nunca há foto.
     */
    public function avatarUrl(): ?string
    {
        return null;
    }
}
