<?php

declare(strict_types=1);

namespace App\Providers;

use App\Livewire\Support\SiteLinks;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Twstec\Kit\Auth\Http\Middleware\EnsureEmailIsVerified;
use Twstec\Kit\Foundation\Kit;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // O singleton da plataforma (platform()) e a troca do `backup:run` pela
        // versão que recusa backup sem criptografia em produção são do pacote
        // twstec/kit-foundation (FoundationServiceProvider). O contexto do
        // tenant da requisição e o comando `api-keys:process-inactivity` são
        // do pacote twstec/kit-accounts (AccountsServiceProvider).

        // Links do site acrescentados por extensões (cabeçalho e rodapé
        // públicos — ver SiteLinks). Um por aplicação: nada vaza entre testes.
        $this->app->singleton(SiteLinks::class);

        // O painel /admin é OPCIONAL (twstec/kit-admin, que traz o Filament):
        // o PanelProvider do aplicativo só é registrado com o pacote
        // instalado. Fica aqui, e não no bootstrap/providers.php, porque o
        // `make:provider` reescreve aquele arquivo e apagaria a condição.
        if (Kit::has('admin')) {
            $this->app->register(AdminPanelProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // As guardas de produção da base do kit (segredo crítico, HTTPS,
        // APP_DEBUG e os avisos dos opt-outs de segurança) são aplicadas pelo
        // pacote twstec/kit-foundation, sozinho — ver
        // Twstec\Kit\Foundation\Support\ProductionHardening.

        // A barreira de origem nos downloads de export/import do Filament
        // (`/filament/exports/…`, fora do painel) é ligada pelo pacote
        // twstec/kit-admin — ver Twstec\Kit\Admin\AdminServiceProvider.

        // Verificação de e-mail nas AÇÕES Livewire do painel. O endpoint de
        // atualização do Livewire é um só para todos os componentes e não
        // carrega os middlewares da rota da página; só reaplica os da lista
        // de persistentes, com a rota de origem gravada no snapshot. Sem esta
        // linha, a página /api-keys mandaria ao aviso, mas a ação "criar
        // chave" disparada de um snapshot anterior passaria.
        Livewire::addPersistentMiddleware([EnsureEmailIsVerified::class]);

        // O comando `user:make-admin` é registrado pelo pacote twstec/kit-admin.

        // Os limitadores `api` e `sensitive` são registrados pelo pacote
        // twstec/kit-foundation (FoundationServiceProvider). Um RateLimiter::for
        // com o mesmo nome aqui substituiria o do pacote.
    }
}
