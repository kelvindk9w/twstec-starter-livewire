<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Kit;

// =============================================================================
// Configurações editáveis pelo super admin pela UI.
//
// WHITELIST (lei): somente as chaves abaixo podem ser editadas no painel
// /admin → Configurações. Cada chave mapeia para um caminho de config: um
// valor presente na tabela `settings` SOBRESCREVE em runtime o valor do .env
// (ver SettingsServiceProvider). Ausente na tabela = .env vigente.
//
// NUNCA adicionar aqui chaves de segredos/credenciais (APP_KEY, DB, mail...):
// a tabela settings não é cofre. Apenas parâmetros operacionais ajustáveis.
//
// As chaves de um módulo OPCIONAL (chaves de API: twstec/kit-accounts;
// uploads: twstec/kit-uploads) só entram com o módulo instalado — sem ele, a
// tela não mostra um campo que não mudaria nada.
// =============================================================================

return [

    // Chave do cache das sobreposições (invalidada a cada escrita).
    'cache_key' => 'settings.db_overrides',

    // Chave da tabela settings => [tipo, limites de UI, grupo e largura].
    // As labels das telas vivem em lang/*/admin.php (toda string via __()).
    //
    // `group`  agrupa os campos por ASSUNTO na tela (uma lista plana de seis
    //          números não diz a ninguém o que é de quê);
    // `span`   é a largura do campo em colunas de 12 — a largura de um
    //          campo é informação: um número de 2 dígitos não pede 1000px
    //          (crítica de design, /admin/settings).
    'overrides' => [
        // Expiração de chaves de API por inatividade: meses sem uso
        // até desativar + dias de aviso prévio por e-mail.
        ...(Kit::has('accounts') ? [
            'api_keys.inactivity.months' => ['type' => 'int', 'min' => 1, 'max' => 36, 'group' => 'api_keys', 'span' => 3],
            'api_keys.inactivity.warning_days' => ['type' => 'int', 'min' => 1, 'max' => 90, 'group' => 'api_keys', 'span' => 3],
        ] : []),

        // Limites de upload.
        ...(Kit::has('uploads') ? [
            'uploads.types.image.max_kb' => ['type' => 'int', 'min' => 64, 'max' => 51200, 'group' => 'uploads', 'span' => 4],
            'uploads.types.pdf.max_kb' => ['type' => 'int', 'min' => 64, 'max' => 102400, 'group' => 'uploads', 'span' => 4],
        ] : []),

        // Rate limits: requisições por minuto.
        'security.rate_limit.api' => ['type' => 'int', 'min' => 1, 'max' => 10000, 'group' => 'rate_limit', 'span' => 3],
        'security.rate_limit.sensitive' => ['type' => 'int', 'min' => 1, 'max' => 100, 'group' => 'rate_limit', 'span' => 3],
    ],

];
