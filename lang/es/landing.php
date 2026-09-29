<?php

declare(strict_types=1);

// Claves del SITIO del producto (cabecera, pie y página inicial): las usan
// <x-site-header>, <x-site-footer>, home.blade.php y
// App\Livewire\Support\Navigation. El grupo se llama `landing` por historia;
// las cadenas de la landing son de la demostración del kit (twstec/kit-demo),
// que agrega las suyas a este grupo.

return [

    'nav' => [
        'login' => 'Entrar',
        'register' => 'Crear cuenta',
    ],

    'footer' => [
        'tagline' => 'Starter kit Laravel para SaaS — base estructural lista para construir.',
        'links_heading' => 'Atajos',
        'api_status' => 'Estado de la API',
        'rights' => '© :year :company — Todos los derechos reservados',
        'developed_by' => 'Desarrollado por',
    ],

];
