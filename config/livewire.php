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

    // Upload temporário do Livewire (antes de o arquivo chegar ao
    // SecureUploadService, que é a lei — ver config/uploads.php). A chave
    // inteira substitui a do pacote (o merge é só do primeiro nível), por
    // isso todas as sub-chaves estão aqui:
    //
    // - `rules`: o MAIOR limite por tipo dos uploads seguros (o padrão do
    //   Livewire é 12 MB). O TIPO não é conferido aqui: quem decide é a regra
    //   por conteúdo (SafeFile/SecureUploadService), com a mensagem que
    //   explica a recusa — um `mimes:` neste ponto recusaria antes, com um
    //   "deve ser um arquivo do tipo…" genérico.
    // - `preview_mimes`: só imagem RASTER tem pré-visualização. O padrão do
    //   Livewire inclui `svg` — documento que carrega script — e áudio/vídeo,
    //   que nenhuma tela do kit pré-visualiza.
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => [
            'required',
            'file',
            'max:'.max((int) env('UPLOADS_IMAGE_MAX_KB', 5120), (int) env('UPLOADS_PDF_MAX_KB', 10240)),
        ],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => ['png', 'jpg', 'jpeg', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
