<?php

declare(strict_types=1);

// Textos de correo del proyecto (es): la galería de vista previa. Los correos
// del kit (asuntos, cuerpos, pie) vienen de los paquetes; para cambiar uno de
// sus textos, repita aquí solo la clave que cambia (ver
// docs/personalizacao.md y docs/emails.md).

return [

    // Pantalla de vista previa de correos (/mail-preview) — solo en desarrollo.
    'preview' => [
        'title' => 'Vista previa de los correos',
        'subtitle' => 'Todos los correos transaccionales del proyecto con datos de ejemplo, en cada idioma disponible y en los dos temas. Herramienta de desarrollo: en producción esta ruta responde 404.',
        'list_heading' => 'Correos',
        'language' => 'Idioma',
        'scheme' => 'Tema',
        'subject' => 'Asunto',
        'plain_text' => 'Versión en texto plano',
        'open_html' => 'Abrir el HTML',
        'open_text' => 'Ver el texto plano',
        'mailpit_hint' => 'Para comprobar cómo llega el correo de verdad (cabeceras, multipart, adjuntos), dispara el flujo y abre Mailpit en :url.',
        'mailpit_hint_generic' => 'Para comprobar cómo llega el correo de verdad (cabeceras, multipart, adjuntos), dispara el flujo y abre el Mailpit del entorno de desarrollo.',
        'emails' => [
            'email-verification' => 'Confirmación de correo',
            'verification-code' => 'Código de verificación',
            'login-code' => 'Código de acceso (inicio de sesión)',
            'password-reset' => 'Restablecer contraseña',
            'api-key-inactivity' => 'Clave de API inactiva',
            'account-invitation' => 'Invitación a una cuenta',
            'orphaned-api-keys' => 'Clave de API huérfana',
            'contact-message' => 'Formulario de contacto',
        ],
        'locales' => ['pt_BR' => 'Português', 'en' => 'English', 'es' => 'Español'],
        'schemes' => ['light' => 'Claro', 'dark' => 'Oscuro'],
    ],

];
