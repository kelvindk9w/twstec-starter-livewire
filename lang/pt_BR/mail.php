<?php

declare(strict_types=1);

// Strings de e-mails transacionais (pt-BR). Toda string passa por __().
// O corpo dos e-mails vive em resources/views/mail/messages/**, sobre o layout
// único <x-email::layouts.kit>. Ver docs/emails.md. O rodapé comum a todos
// (mail.footer.*) vem do pacote twstec/kit-foundation.

return [

    // Aviso prévio de expiração de chave de API por inatividade.
    'api_key_inactivity' => [
        'preheader' => 'Uma chave sem uso será desativada em :days dias.',
        'heading' => 'Uma chave de API sua está prestes a ser desativada',
        'intro' => 'A chave abaixo está sem uso e será desativada automaticamente por inatividade.',
        'name_label' => 'Nome da chave',
        'code_label' => 'Código público',
        'key_label' => 'Chave pública',
        'expires' => 'A desativação acontece em :days dias.',
        'action' => 'Para mantê-la ativa, basta fazer uma requisição autenticada com ela. Se não precisar mais dela, recomendamos revogá-la no painel.',
        'cta' => 'Abrir minhas chaves',
        'ignore' => 'Se você não reconhece esta chave, revogue-a imediatamente e troque suas credenciais.',
    ],

    // Código de verificação (2FA por e-mail).
    'verification_code' => [
        'preheader' => 'Seu código expira em :minutes minutos.',
        'heading' => 'Seu código de verificação',
        'intro' => 'Use o código abaixo para confirmar a ação solicitada. Ele é de uso único.',
        'expires' => 'Este código expira em :minutes minutos.',
        'ignore' => 'Se você não solicitou esta ação, ignore este e-mail e considere trocar sua senha.',
    ],

    // Código do segundo fator do LOGIN (mesmo e-mail do código, outra finalidade).
    'login_code' => [
        'preheader' => 'Seu código de acesso expira em :minutes minutos.',
        'heading' => 'Seu código de acesso',
        'intro' => 'A senha da sua conta foi digitada corretamente e falta um passo para concluir a entrada. Se foi você, use o código abaixo. Ele é de uso único.',
        'expires' => 'Este código expira em :minutes minutos.',
        'ignore' => 'Não foi você? Não repasse este código a ninguém e troque sua senha agora: quem tentou entrar sabe a senha atual.',
    ],

    // Recuperação de senha (bug de QA #9 — antes vinha em inglês do pacote).
    'email_verification' => [
        'preheader' => 'Falta um passo: confirme o e-mail para liberar sua conta.',
        'heading' => 'Confirme seu e-mail',
        'intro' => 'Sua conta foi criada. Para liberar o acesso ao painel, confirme que este endereço é seu.',
        'action' => 'Confirmar e-mail',
        'expires' => 'Este link expira em :minutes minutos. Depois disso, peça um novo na tela de aviso.',
        'fallback' => 'Se o botão não funcionar, copie e cole este endereço no navegador:',
        'ignore' => 'Se você não criou esta conta, ignore este e-mail: sem a confirmação, ela não é liberada.',
    ],

    'password_reset' => [
        'preheader' => 'Link de redefinição válido por :minutes minutos.',
        'heading' => 'Redefinir sua senha',
        'intro' => 'Você está recebendo este e-mail porque recebemos um pedido de redefinição de senha para a sua conta.',
        'action' => 'Redefinir senha',
        'expires' => 'Este link expira em :minutes minutos.',
        'fallback' => 'Se o botão não funcionar, copie e cole este endereço no navegador:',
        'ignore' => 'Se você não pediu a redefinição, nenhuma ação é necessária.',
    ],

    // Mensagem do formulário de contato da landing → e-mail do time.
    // O assunto continua em contact.mail.subject_line (é a string do módulo
    // de contato); aqui ficam só as partes do CORPO no layout do kit.
    'contact_message' => [
        'preheader' => 'Nova mensagem de :name (:subject).',
        'heading' => 'Nova mensagem do formulário de contato',
        'message_label' => 'Mensagem',
        'reply_hint' => 'Responder este e-mail responde direto para quem escreveu.',
    ],

    // Convite para uma conta (twstec/kit-accounts). O assunto é do pacote.
    'account_invitation' => [
        'preheader' => ':inviter convidou você para a conta :account.',
        'heading' => 'Você foi convidado para a conta :account',
        'intro' => ':inviter convidou você para trabalhar na conta :account em :platform.',
        'account_label' => 'Conta',
        'role_label' => 'Papel',
        'expires_label' => 'Válido até',
        'action' => 'Abrir o convite',
        'how' => 'Se você ainda não tem acesso à plataforma, crie o seu na própria tela do convite. Se já tem, entre e aceite.',
        'fallback' => 'Se o botão não funcionar, copie e cole este endereço no navegador:',
        'ignore' => 'Se você não esperava este convite, ignore este e-mail: sem o aceite, nada acontece.',
    ],

    // Aviso de chave órfã (twstec/kit-accounts). O assunto é do pacote.
    'orphaned_api_keys' => [
        'preheader' => '{1} :count chave de API da conta :account continua valendo.|[2,*] :count chaves de API da conta :account continuam valendo.',
        'heading' => '{1} Uma chave de API ficou sem quem a criou|[2,*] Chaves de API ficaram sem quem as criou',
        'intro_removed' => '{1} :name foi removido da conta :account e tinha criado a chave abaixo.|[2,*] :name foi removido da conta :account e tinha criado as chaves abaixo.',
        'intro_deleted' => '{1} O acesso de :name à plataforma foi excluído, e essa pessoa tinha criado a chave abaixo na conta :account.|[2,*] O acesso de :name à plataforma foi excluído, e essa pessoa tinha criado as chaves abaixo na conta :account.',
        'intro_left' => '{1} :name saiu da conta :account e tinha criado a chave abaixo.|[2,*] :name saiu da conta :account e tinha criado as chaves abaixo.',
        'name_label' => 'Nome da chave',
        'code_label' => 'Código público',
        'key_label' => 'Chave pública',
        'still_valid' => '{1} A chave é da conta, não da pessoa: ela continua funcionando. Se quem a usava não deve mais ter acesso, rotacione-a ou revogue-a.|[2,*] As chaves são da conta, não da pessoa: elas continuam funcionando. Se quem as usava não deve mais ter acesso, rotacione-as ou revogue-as.',
        'cta' => 'Revisar as chaves da conta',
        'why' => 'Você recebe este aviso porque é dono ou administrador da conta.',
    ],

    // Tela de pré-visualização dos e-mails (/mail-preview) — só em dev.
    'preview' => [
        'title' => 'Pré-visualização dos e-mails',
        'subtitle' => 'Todos os e-mails transacionais do kit com dados de exemplo, nos três idiomas e nos dois temas. Ferramenta de desenvolvimento: em produção esta rota responde 404.',
        'list_heading' => 'E-mails',
        'language' => 'Idioma',
        'scheme' => 'Tema',
        'subject' => 'Assunto',
        'plain_text' => 'Versão em texto puro',
        'open_html' => 'Abrir o HTML',
        'open_text' => 'Ver o texto puro',
        'mailpit_hint' => 'Para conferir como o e-mail chega de verdade (cabeçalhos, multipart, anexos), dispare o fluxo e abra o Mailpit em http://localhost:18025.',
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
