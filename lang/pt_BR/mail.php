<?php

declare(strict_types=1);

// Textos de e-mail do projeto (pt-BR): a galeria de pré-visualização. Os
// e-mails do kit (assuntos, corpos, rodapé) vêm dos pacotes; para mudar um
// texto deles, repita aqui só a chave que muda (ver docs/personalizacao.md e
// docs/emails.md).

return [

    // Tela de pré-visualização dos e-mails (/mail-preview) — só em dev.
    'preview' => [
        'title' => 'Pré-visualização dos e-mails',
        'subtitle' => 'Todos os e-mails transacionais do projeto com dados de exemplo, em cada idioma disponível e nos dois temas. Ferramenta de desenvolvimento: em produção esta rota responde 404.',
        'list_heading' => 'E-mails',
        'language' => 'Idioma',
        'scheme' => 'Tema',
        'subject' => 'Assunto',
        'plain_text' => 'Versão em texto puro',
        'open_html' => 'Abrir o HTML',
        'open_text' => 'Ver o texto puro',
        'mailpit_hint' => 'Para conferir como o e-mail chega de verdade (cabeçalhos, multipart, anexos), dispare o fluxo e abra o Mailpit em :url.',
        'mailpit_hint_generic' => 'Para conferir como o e-mail chega de verdade (cabeçalhos, multipart, anexos), dispare o fluxo e abra o Mailpit do ambiente de desenvolvimento.',
        'emails' => [
            'email-verification' => 'Confirmação de e-mail',
            'verification-code' => 'Código de verificação',
            'login-code' => 'Código de acesso (login)',
            'password-reset' => 'Redefinição de senha',
            'api-key-inactivity' => 'Chave de API inativa',
            'account-invitation' => 'Convite para uma conta',
            'orphaned-api-keys' => 'Chave de API órfã',
            'contact-message' => 'Formulário de contato',
        ],
        'locales' => ['pt_BR' => 'Português', 'en' => 'English', 'es' => 'Español'],
        'schemes' => ['light' => 'Claro', 'dark' => 'Escuro'],
    ],

];
