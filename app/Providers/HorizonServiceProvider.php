<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Twstec\Kit\Admin\Access\AdminAccess;
use Twstec\Kit\Foundation\Kit;

/**
 * Horizon: supervisor de filas + dashboard /horizon.
 *
 * Acesso ao dashboard: SÓ super admin com CONTA ATIVA — o MESMO critério do
 * painel /admin, lido do mesmo lugar (Twstec\Kit\Admin\Access\AdminAccess,
 * do pacote twstec/kit-admin). A barreira de origem
 * (EnsureAdminIpAllowed) também se aplica às rotas do Horizon — ver
 * config/horizon.php → middleware. Em ambiente local o pacote libera o acesso
 * sem gate (comportamento padrão dele, apenas desenvolvimento).
 *
 * POR QUE O GATE CHECA O STATUS DA CONTA, e não só a flag: o gate antes olhava
 * apenas `is_admin`. Desativar ou bloquear a conta de um administrador tirava o
 * acesso dele ao /admin (o Filament consulta canAccessPanel a cada requisição)
 * e NÃO tirava o acesso ao /horizon — com a sessão ainda viva, o administrador
 * recém-desativado continuava enxergando e operando a fila: retry de job,
 * payload de job falho, métricas. Revogar acesso tem de revogar em todas as
 * superfícies, ou não é revogação. Os dois pontos passam a ler o MESMO
 * critério, que é o que impede que voltem a divergir.
 *
 * SEM O /admin (twstec/kit-admin não instalado): não há o critério do painel,
 * e o gate FECHA — ninguém vê o /horizon fora do ambiente local. Para abrir,
 * declare aqui o critério do seu aplicativo.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define(
            'viewHorizon',
            fn (?User $user = null): bool => Kit::has('admin') && AdminAccess::allows($user),
        );
    }
}
