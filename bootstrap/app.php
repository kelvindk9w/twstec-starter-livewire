<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Twstec\Kit\Foundation\Http\Exceptions\ApiErrorRenderer;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Localization\Middleware\SetLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A PILHA GLOBAL DE SEGURANÇA (TrustProxies → SecurityHeaders →
        // EdgeRateLimit → TrustHosts → SecurityValidation → RequestLogging, na
        // frente de todo o resto) é instalada pelo pacote twstec/kit-foundation,
        // com a ordem e o porquê de cada posição documentados em
        // Twstec\Kit\Foundation\FoundationServiceProvider::GLOBAL_MIDDLEWARE.
        // Os aliases `security.validation`, `security.headers` e
        // `request.logging` também vêm de lá. Aqui fica só a composição do
        // aplicativo: o grupo web e o destino do convidado.

        // A cadeia da API — o `throttle:api` (limite por chave) na frente do
        // grupo `api`, a autenticação por chave (`resolve.tenant`) antes do
        // limite na lista de prioridade e os aliases `resolve.tenant`,
        // `scope` e `account.key` — é instalada pelo pacote
        // twstec/kit-accounts (Twstec\Kit\Accounts\AccountsServiceProvider),
        // junto com as rotas /api/v1 dele.
        //
        // SEM O PACOTE DE CONTAS (opcional — `php artisan tws:install`), a
        // API do aplicativo é só o /api/health, e o `throttle:api` (o
        // limitador `api` do twstec/kit-foundation: por IP, sem chave) é
        // posto aqui. A proteção não some com o módulo.
        if (! Kit::has('accounts')) {
            $middleware->throttleApi();
        }

        // Locale da interface web: usuário logado → preferência da
        // conta; visitante → cookie; fallback → padrão da plataforma (pt-BR).
        //
        // O status da conta a cada requisição web (EnsureAccountIsActive) é
        // anexado ao FIM do grupo pelo pacote twstec/kit-auth — logo depois
        // deste SetLocale, para a mensagem de recusa sair no idioma da conta.
        // Conta bloqueada/pendente com sessão aberta perde a sessão na próxima
        // requisição — página, formulário ou ação Livewire (o endpoint do
        // Livewire está no grupo `web`).
        $middleware->web(append: [SetLocale::class]);

        // Os aliases de autenticação — `sensitive.token` (token de ação
        // sensível, uso único) e `verified` (painel só com e-mail confirmado,
        // AUTH_EMAIL_VERIFICATION_REQUIRED; substitui o do framework) — são
        // instalados pelo pacote twstec/kit-auth; os da API, pelo
        // twstec/kit-accounts.

        // Deny-by-default: convidado em rota `auth` vai para
        // o login; `redirect()->intended()` devolve ao destino original.
        $middleware->redirectGuestsTo(fn (): string => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // O envelope padronizado de erro da API (`api/*`, contrapartida do
        // envelope de sucesso {"data": …} — nunca stack trace/caminho de
        // servidor, nem com APP_DEBUG=true) é registrado pelo pacote
        // twstec/kit-accounts, dono da API (ver ApiErrorRenderer, do
        // foundation, e docs/api.md). Um render próprio declarado aqui roda
        // ANTES do dele e prevalece.
        //
        // Sem o pacote de contas (opcional), o envelope é ligado aqui, com o
        // mesmo renderizador do foundation: os erros de `api/*` (inclusive o
        // 429 da borda no /api/health) continuam sem detalhe interno.
        if (! Kit::has('accounts')) {
            $exceptions->render(static fn (Throwable $e, Request $request) => app(ApiErrorRenderer::class)($e, $request));
        }

        // Captura a mensagem da exceção para o request log finalizar como ERRO
        // com o motivo (redigido depois pelo RequestLogging — LGPD).
        $exceptions->report(function (Throwable $e): void {
            $request = request();

            if (! $request->attributes->has('request_log_error')) {
                $request->attributes->set('request_log_error', $e->getMessage());
            }
        });
    })->create();
