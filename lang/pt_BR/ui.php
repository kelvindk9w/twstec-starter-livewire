<?php

declare(strict_types=1);

// Strings de interface (UI) — pt-BR. Toda string exibida ao usuário passa
// por __() apontando para estes arquivos. NUNCA texto fixo em views.

return [

    'locale' => [
        'label' => 'Idioma',
        'names' => [
            'pt_BR' => 'Português (Brasil)',
            'en' => 'English',
            'es' => 'Español',
        ],
    ],

    // Navegação do site (<x-site-header>, <x-side-nav>). O painel e a landing
    // compartilham o MESMO cabeçalho — por isso estas chaves são de UI, não
    // de "landing" nem de "panel".
    'nav' => [
        'site' => 'Navegação do site',
        'menu' => 'Menu',
        'open_menu' => 'Abrir menu de navegação',
        'account' => 'Minha conta',
        'account_menu' => 'Menu da conta',
        'back_to_site' => 'Voltar ao site',
        'sections' => 'Seções',
    ],

    'theme' => [
        'toggle' => 'Tema: alterna entre sistema, claro e escuro',
        'label' => 'Tema',
        'system' => 'Sistema',
        'light' => 'Claro',
        'dark' => 'Escuro',
    ],

    // Seletor de arquivo do kit (<x-file-input>): o chrome nativo do
    // <input type="file"> é traduzido pelo SISTEMA OPERACIONAL, não por nós.
    'file' => [
        'choose' => 'Escolher arquivo',
        'empty' => 'Nenhum arquivo selecionado',
    ],

    'chart' => [
        'empty_title' => 'Sem dados no período',
    ],

    'password' => [
        'show' => 'Mostrar senha',
        'hide' => 'Ocultar senha',
    ],

    // Resumo de erros de validação (<x-form-errors>).
    'form_errors' => [
        'title' => 'Corrija os campos destacados',
    ],

    'footer' => [
        'operated_by' => 'Operado por :platform',
        'support' => 'Suporte',
    ],

    // Alternador tabela/cartões das listas do painel (<x-view-toggle>).
    'view_toggle' => [
        'label' => 'Como a lista aparece',
        'table' => 'Ver em tabela',
        'cards' => 'Ver em cartões',
    ],

    // Seletor de conta (<x-account-switcher>).
    'account_switcher' => [
        'label' => 'Conta atual: :account. Trocar de conta',
        'heading' => 'Suas contas',
        'manage' => 'Conta e membros',
        'create' => 'Criar conta de empresa',
    ],

    // Comandos de console do aplicativo (routes/console.php).
    'console' => [
        'filament_assets' => 'Publica os assets do Filament quando o painel /admin (twstec/kit-admin) está instalado',
        'filament_skipped' => 'Filament não instalado (o painel /admin é opcional): nada a publicar.',
    ],

];
