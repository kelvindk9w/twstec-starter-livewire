<?php

declare(strict_types=1);

// Cadenas de interfaz (es). Toda cadena visible pasa por __().

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
        'site' => 'Navegación del sitio',
        'menu' => 'Menú',
        'open_menu' => 'Abrir menú de navegación',
        'account' => 'Mi cuenta',
        'account_menu' => 'Menú de la cuenta',
        'back_to_site' => 'Volver al sitio',
        'sections' => 'Secciones',
    ],

    'theme' => [
        'toggle' => 'Tema: alterna entre sistema, claro y oscuro',
        'label' => 'Tema',
        'system' => 'Sistema',
        'light' => 'Claro',
        'dark' => 'Oscuro',
    ],

    // Seletor de arquivo do kit (<x-file-input>): o chrome nativo do
    // <input type="file"> é traduzido pelo SISTEMA OPERACIONAL, não por nós.
    'file' => [
        'choose' => 'Elegir archivo',
        'empty' => 'Ningún archivo seleccionado',
    ],

    'chart' => [
        'empty_title' => 'Sin datos en el período',
    ],

    'password' => [
        'show' => 'Mostrar contraseña',
        'hide' => 'Ocultar contraseña',
    ],

    // Resumen de errores de validación (<x-form-errors>).
    'form_errors' => [
        'title' => 'Corrige los campos destacados',
    ],

    'footer' => [
        'operated_by' => 'Operado por :platform',
        'support' => 'Soporte',
    ],

    // Alternador tabla/tarjetas de las listas del panel (<x-view-toggle>).
    'view_toggle' => [
        'label' => 'Cómo se muestra la lista',
        'table' => 'Ver en tabla',
        'cards' => 'Ver en tarjetas',
    ],

    // Selector de cuenta (<x-account-switcher>).
    'account_switcher' => [
        'label' => 'Cuenta actual: :account. Cambiar de cuenta',
        'heading' => 'Sus cuentas',
        'manage' => 'Cuenta y miembros',
        'create' => 'Crear cuenta de empresa',
    ],

    // Comandos de consola de la aplicación (routes/console.php).
    'console' => [
        'filament_assets' => 'Publica los assets de Filament cuando el panel /admin (twstec/kit-admin) está instalado',
        'filament_skipped' => 'Filament no está instalado (el panel /admin es opcional): nada que publicar.',
    ],

];
