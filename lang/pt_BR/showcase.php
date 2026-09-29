<?php

declare(strict_types=1);

// Chaves de componentes Blade do produto (<x-snippet>, <x-spinner>,
// <x-skeleton>). O grupo se chama `showcase` por história — nasceu com a
// vitrine /ui — e o nome ficou para não quebrar quem já sobrescreveu estas
// chaves. As strings da vitrine em si são da demonstração do kit
// (twstec/kit-demo), que acrescenta as dela a este grupo.

return [

    'snippets' => [
        'copy' => 'Copiar',
        'copied' => 'Copiado!',
    ],

    'components' => [
        'spinner_label' => 'Carregando',
        'loading_label' => 'Carregando conteúdo',
    ],

];
