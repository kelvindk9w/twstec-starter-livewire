<?php

declare(strict_types=1);

// =============================================================================
// Livewire 4. Apenas as chaves que diferem do default do
// pacote (o resto vem do merge da config do próprio Livewire).
// =============================================================================

return [

    // Bundle CSP-SAFE do Alpine (sem eval/new Function): exigido pela nossa
    // CSP restritiva (config/security.php — script-src sem 'unsafe-eval').
    // Sem isto, o JavaScript do Livewire é bloqueado
    // pelo navegador e os componentes não hidratam.
    'csp_safe' => env('LIVEWIRE_CSP_SAFE', true),

];
