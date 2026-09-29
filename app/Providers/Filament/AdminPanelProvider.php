<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\FontProviders\LocalFontProvider;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Twstec\Kit\Admin\AdminPlugin;
use Twstec\Kit\Foundation\Support\BrandMark;

/**
 * Super admin Filament (/admin) — o painel é DESTE aplicativo; o produto
 * (resources, páginas, login com segundo fator, dashboards, trilha de
 * auditoria) entra pelo plugin do pacote twstec/kit-admin.
 *
 * Aqui fica só o que é do aplicativo: id e caminho do painel, marca, cores,
 * fonte, tema (resources/css/filament.css) e o seletor de idioma da topbar
 * (a mesma linguagem do resto do front). Para acrescentar telas próprias,
 * escreva-as em app/Filament (a descoberta abaixo as registra e a trilha de
 * auditoria já as cobre) ou registre outro plugin.
 *
 * As PROTEÇÕES do painel são do pacote e valem sem nenhuma linha aqui:
 * - acesso SÓ de `is_admin` + conta ativa (os demais recebem 403; promoção
 *   exclusiva via `php artisan user:make-admin` ou por outro admin);
 * - barreira de ORIGEM (allowlist de IP, `security.admin.allowed_ips` /
 *   ADMIN_ALLOWED_IPS) como PRIMEIRO middleware e persistente nas ações
 *   Livewire — lista vazia libera fora de produção; em produção sem allowlist
 *   declarada o painel RECUSA (403), ver
 *   Twstec\Kit\Foundation\Security\AdminIpAllowlist;
 * - Actions e Criar/Salvar em transação e a trilha de auditoria de toda ação.
 * Ver Twstec\Kit\Admin\AdminPlugin e Support\AdminPanelHardening.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(AdminPlugin::make())
            // Branding 100% via platform(): nome, logo e cor primária vêm do
            // .env — nada hardcoded.
            ->brandName(platform()->name)
            // Marca: o .env sempre vence; sem ele, o kit tem uma
            // marca monocromática própria em vez de um quadrado preto.
            ->brandLogo(fn () => filled(platform()->logoUrl)
                ? (string) platform()->logoUrl
                : BrandMark::inlineSvg())
            ->brandLogoHeight('1.75rem')
            // Tipografia unificada (crítica de design #6): a MESMA voz do
            // painel do usuário e da landing. Self-hosted via
            // @fontsource-variable no tema Vite — nada de CDN de fonte
            // (a CSP do kit não permite font-src externo), por isso o
            // provider local sem URL: quem serve a fonte é o filament.css.
            ->font('Space Grotesk Variable', provider: LocalFontProvider::class)
            // Tema do painel com os tokens de identidade do kit
            // (resources/css/filament.css → theme.css, e as classes das telas
            // do pacote pelo @import dele).
            ->viteTheme('resources/css/filament.css')
            // Primária do painel: neutro puro, para casar com --color-brand
            // (quase-preto no claro / quase-branco no escuro). O Zinc antigo
            // dava um cinza-médio que não existia em nenhuma outra tela.
            ->colors([
                'primary' => platform()->primaryColor !== null
                    ? Color::hex(platform()->primaryColor)
                    : Color::Neutral,
            ])
            // Telas próprias do aplicativo no painel (vazio no kit).
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Seletor de idioma na topbar (mesmo formato compacto do resto
            // do kit: bandeira + sigla). O locale é resolvido pelo SetLocale
            // da pilha do painel (preferência da conta → cookie → padrão da
            // plataforma).
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('filament.topbar-locale-switcher')->render(),
            );
    }
}
