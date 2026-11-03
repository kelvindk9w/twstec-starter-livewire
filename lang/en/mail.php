<?php

declare(strict_types=1);

// Project e-mail texts (en): the preview gallery. The kit e-mails (subjects,
// bodies, footer) come from the packages; to change one of their texts,
// repeat only the key you change here (see docs/personalizacao.md and
// docs/emails.md).

return [

    // Email preview screen (/mail-preview) — development only.
    'preview' => [
        'title' => 'Email preview',
        'subtitle' => 'Every transactional email in the project with sample data, in each available language and both themes. A development tool: in production this route returns 404.',
        'list_heading' => 'Emails',
        'language' => 'Language',
        'scheme' => 'Theme',
        'subject' => 'Subject',
        'plain_text' => 'Plain text version',
        'open_html' => 'Open the HTML',
        'open_text' => 'View plain text',
        'mailpit_hint' => 'To check how the email actually arrives (headers, multipart, attachments), trigger the flow and open Mailpit at :url.',
        'mailpit_hint_generic' => 'To check how the email actually arrives (headers, multipart, attachments), trigger the flow and open the Mailpit of the development environment.',
        'emails' => [
            'email-verification' => 'Email confirmation',
            'verification-code' => 'Verification code',
            'login-code' => 'Sign-in code (login)',
            'password-reset' => 'Password reset',
            'api-key-inactivity' => 'Inactive API key',
            'account-invitation' => 'Invitation to an account',
            'orphaned-api-keys' => 'Orphaned API key',
            'contact-message' => 'Contact form',
        ],
        'locales' => ['pt_BR' => 'Português', 'en' => 'English', 'es' => 'Español'],
        'schemes' => ['light' => 'Light', 'dark' => 'Dark'],
    ],

];
