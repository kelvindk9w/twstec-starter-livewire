<?php

declare(strict_types=1);

// Product SITE keys (header, footer and home page): used by <x-site-header>,
// <x-site-footer>, home.blade.php and App\Livewire\Support\Navigation. The
// group is called `landing` for historical reasons; the landing strings
// themselves belong to the kit demo (twstec/kit-demo), which adds its own keys
// to this group.

return [

    'nav' => [
        'login' => 'Sign in',
        'register' => 'Create account',
    ],

    'footer' => [
        'tagline' => 'Laravel starter kit for SaaS — a ready structural base to build on.',
        'links_heading' => 'Shortcuts',
        'api_status' => 'API status',
        'rights' => '© :year :company — All rights reserved',
        'developed_by' => 'Developed by',
    ],

];
