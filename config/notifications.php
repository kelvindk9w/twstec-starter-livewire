<?php

declare(strict_types=1);

// =============================================================================
// Preferências de notificação do usuário (esqueleto preparado para as
// notificações do projeto que herdar o kit — ex.: pagamentos).
//
// Cada chave é um toggle de e-mail na tela de preferências do painel. Os
// valores aqui são os DEFAULTS (usuário sem escolha gravada); a escolha fica
// no JSON users.notification_preferences. Labels em lang/pt_BR/panel.php.
//
// 'locked' = notificação de segurança obrigatória (não pode ser desligada).
// =============================================================================

return [

    'preferences' => [
        // Pagamento confirmado (futuro motor de cobranças).
        'payment_confirmed' => ['default' => true],
        // E-mail ao CLIENTE FINAL do vendedor (padrão ATIVO).
        'final_customer_receipt' => ['default' => true],
        // Eventos de chaves de API (criação, rotação, aviso de inatividade).
        'api_key_events' => ['default' => true],
        // Alertas de segurança (logins, ações sensíveis) — sempre ativos.
        'security_alerts' => ['default' => true, 'locked' => true],
    ],

];
