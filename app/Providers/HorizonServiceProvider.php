<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Twstec\Kit\Admin\Access\HorizonAccess;
use Twstec\Kit\Foundation\Kit;

/**
 * Horizon: supervisor de filas + dashboard /horizon.
 *
 * Acesso ao dashboard: SÓ admin do /admin com CONTA ATIVA e com a permissão
 * `horizon.access` no papel — o critério mora no pacote twstec/kit-admin
 * (Twstec\Kit\Admin\Access\HorizonAccess, que confere a entrada no painel,
 * Access\AdminAccess, antes do papel). De fábrica só o `owner` e a
 * `operations` a têm (config/admin.php → authorization.roles): o Horizon
 * mostra o payload dos jobs (dado pessoal) e deixa refazer e apagar job, e
 * o `support` e a auditoria não abrem — `*.view` não casa com a permissão,
 * de propósito. A barreira de origem
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
            fn (?User $user = null): bool => Kit::has('admin') && HorizonAccess::allows($user),
        );
    }
}
