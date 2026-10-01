<?php

// =============================================================================
// Configuração centralizada da plataforma (nada hardcoded — regra inegociável)
//
// TODOS os dados institucionais (nome, logo, URLs, contatos, CNPJ) vêm daqui,
// sempre lidos do .env. É PROIBIDO hardcodar qualquer um desses valores em
// código, views ou configs. Alterar aqui (via .env) = sistema inteiro reflete.
//
// Acesso tipado em runtime: helper global platform() (Twstec\Kit\Foundation\Support\Platform).
// =============================================================================

return [

    // Nome público da plataforma (telas, e-mails, metadados).
    'name' => env('PLATFORM_NAME', 'TWS Starter Kit'),

    // Versão da plataforma (endpoint /api/health, rodapés, suporte).
    'version' => env('PLATFORM_VERSION', '2.0.0-beta.10'),

    // URL do logotipo oficial (nunca caminho hardcoded em views).
    'logo_url' => env('PLATFORM_LOGO_URL'),

    // Override OPCIONAL da cor primária da marca (hex). Vazio = identidade
    // monocromática dos tokens (theme.css: preto no claro, branco no escuro —
    // esquema Vercel/Linear), que se adapta ao tema; uma cor fixa não se
    // adapta, por isso o padrão é NÃO definir esta chave.
    'primary_color' => env('PLATFORM_PRIMARY_COLOR'),

    // URL institucional oficial do produto.
    'official_url' => env('PLATFORM_OFFICIAL_URL', env('APP_URL', 'http://localhost:8180')),

    // URL pública do repositório do projeto (CTA da landing).
    'repo_url' => env('PLATFORM_REPO_URL'),

    // E-mail que RECEBE as mensagens do formulário de contato da landing.
    'contact_email' => env('PLATFORM_CONTACT_EMAIL'),

    // Empresa operadora (rodapé: direitos autorais e "desenvolvido por").
    'company_name' => env('PLATFORM_COMPANY_NAME', 'TWS'),
    'company_url' => env('PLATFORM_COMPANY_URL'),

    // E-mail público de suporte.
    'support_email' => env('PLATFORM_SUPPORT_EMAIL'),

    // CNPJ da empresa operadora (rodapés, termos, notas).
    'cnpj' => env('PLATFORM_CNPJ'),

    // Locale padrão da plataforma (MVP: pt-BR; multi-idioma preparado via lang/).
    'locale' => env('PLATFORM_LOCALE', 'pt_BR'),

    // Idiomas disponíveis na UI (seletor de idioma + middleware SetLocale).
    // O padrão do kit continua sendo 'locale' acima; visitantes escolhem via
    // cookie e usuários logados persistem a preferência na conta (users.locale).
    'available_locales' => array_values(array_filter(explode(',', (string) env('PLATFORM_AVAILABLE_LOCALES', 'pt_BR,en,es')))),

    // Timezone de EXIBIÇÃO (borda). Internamente tudo é UTC.
    'display_timezone' => env('PLATFORM_DISPLAY_TIMEZONE', 'America/Sao_Paulo'),

    // Moeda padrão da plataforma (multi-moeda preparado: valor + moeda).
    'currency' => env('PLATFORM_CURRENCY', 'BRL'),

    // Dinheiro (Twstec\Kit\Foundation\Money\Money — docs/convencoes.md).
    'money' => [
        // Regra de arredondamento que Money::defaultRounding() devolve: nome
        // de um caso do enum nativo RoundingMode (HalfAwayFromZero = metade
        // para longe do zero; HalfEven = metade para o par, "do banqueiro";
        // TowardsZero, AwayFromZero, NegativeInfinity, PositiveInfinity...).
        // O cálculo continua pedindo a regra no parâmetro: esta é só a que o
        // projeto escolhe passar quando quer uma regra única.
        'rounding' => env('PLATFORM_MONEY_ROUNDING', 'HalfAwayFromZero'),

        // Casas decimais por moeda, quando não são as do ISO 4217 (moeda
        // própria, crédito interno em milésimos...). Ex.: ['XPT' => 3].
        'fraction_digits' => [],
    ],

];
