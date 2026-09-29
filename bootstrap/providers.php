<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    // Os providers da base do kit (auditoria, e-mail, configurações
    // editáveis e a pilha de segurança) vêm do pacote twstec/kit-foundation;
    // o de autenticação (respostas padrão, proteções da sessão web,
    // migrations e traduções), do twstec/kit-auth; o de contas e API
    // (projetos, chaves de API, a API v1 e as proteções dela), do
    // twstec/kit-accounts; e o de uploads (upload seguro, entrega por URL
    // assinada, foto de perfil e o POST /api/v1/uploads), do
    // twstec/kit-uploads — os quatro pela descoberta automática de pacotes
    // do Laravel. O painel /admin (twstec/kit-admin) entra como plugin no
    // App\Providers\Filament\AdminPanelProvider, que o AppServiceProvider
    // registra só com o pacote instalado (o /admin é OPCIONAL; sem ele, nem o
    // Filament está instalado). A demonstração do kit (twstec/kit-demo, só no
    // ambiente de desenvolvimento — require-dev) também entra pela descoberta
    // automática: nada aqui a nomeia.
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
