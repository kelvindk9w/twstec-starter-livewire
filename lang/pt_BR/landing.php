<?php

declare(strict_types=1);

// Chaves do SITE do produto (cabeçalho, rodapé e página inicial): usadas por
// <x-site-header>, <x-site-footer>, home.blade.php e
// App\Livewire\Support\Navigation. O grupo se chama `landing` por história —
// nasceu com a landing do kit — e o nome ficou para não quebrar quem já
// sobrescreveu estas chaves. As strings da landing em si são da demonstração
// do kit (twstec/kit-demo), que acrescenta as dela a este grupo.

return [

    'nav' => [
        'login' => 'Entrar',
        'register' => 'Criar conta',
    ],

    'footer' => [
        'tagline' => 'Starter kit Laravel para SaaS — base estrutural pronta para construir.',
        'links_heading' => 'Atalhos',
        'api_status' => 'Status da API',
        'rights' => '© :year :company — Todos os direitos reservados',
        'developed_by' => 'Desenvolvido por',
    ],

];
