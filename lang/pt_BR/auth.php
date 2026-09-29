<?php

declare(strict_types=1);

// Strings de autenticação (pt-BR). Toda string de UI passa por __().
// As mensagens do DOMÍNIO (recusas, avisos, política de senha) vêm do pacote
// twstec/kit-auth; aqui ficam as das telas. Uma chave repetida aqui vence a
// do pacote.

return [

    'password' => 'A senha informada está incorreta.',

    // Verificação de e-mail do cadastro (Twstec\Kit\Auth\Support\EmailVerification).
    'email_verification' => [
        'title' => 'Confirme seu e-mail',
        'intro' => 'Enviamos um link de confirmação para :email. Abra o e-mail e clique no link para liberar o painel.',
        'hint' => 'Não chegou? Confira o spam ou peça um novo envio.',
        'resend' => 'Reenviar e-mail',
    ],

    // Verificação em duas etapas no LOGIN (TwoFactorLogin): tela do código,
    // mensagens do fluxo e as recusas de ligar/desligar.
    'two_factor' => [
        'title' => 'Verificação em duas etapas',
        'intro' => 'Enviamos um código de 6 dígitos para :email. Digite-o abaixo para concluir a entrada — ele vale por :minutes minutos.',
        'submit' => 'Confirmar e entrar',
        'resend' => 'Enviar outro código',
        'resend_hint' => 'Não chegou? Confira o spam. Um código novo invalida o anterior.',
        'cancel' => 'Voltar ao login',
        'enabled' => 'Verificação em duas etapas ligada. A partir do próximo login, pediremos o código enviado ao seu e-mail.',
        'disabled' => 'Verificação em duas etapas desligada. O login volta a pedir só a senha.',
    ],

    // Strings de interface (formulários/telas de autenticação).
    'ui' => [
        'login_title' => 'Entrar',
        'login_submit' => 'Entrar',
        'login_link' => 'Já tem conta? Entrar',
        'register_title' => 'Criar conta',
        'register_submit' => 'Criar conta',
        'register_link' => 'Criar conta',
        'name' => 'Nome completo',
        'email' => 'E-mail',
        'password' => 'Senha',
        'new_password' => 'Nova senha',
        'password_confirmation' => 'Confirme a senha',
        'remember_me' => 'Manter conectado',
        'forgot_password' => 'Esqueci minha senha',
        'forgot_title' => 'Recuperar senha',
        'forgot_subtitle' => 'Informe seu e-mail para receber o link de redefinição.',
        'forgot_submit' => 'Enviar link de redefinição',
        'reset_title' => 'Redefinir senha',
        'reset_submit' => 'Redefinir senha',
        'logout' => 'Sair',
        'save' => 'Salvar',
        'transaction_password_title' => 'Senha de transação',
        'transaction_password_subtitle' => 'Usada para autorizar ações sensíveis (saques, chaves de API). Deve ser diferente da senha de login.',
        'current_transaction_password' => 'Senha de transação atual',
        'new_transaction_password' => 'Nova senha de transação',
        'dashboard_title' => 'Painel',
        'dashboard_greeting' => 'Olá, :name',
        'dashboard_code' => 'Seu código de usuário',
    ],

];
