import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Demonstração do kit (twstec/kit-demo): pacote de DESENVOLVIMENTO
// (require-dev). Instalada, ela acrescenta ao build as entradas das landings,
// as imagens e as pastas que o Tailwind precisa ler (ver o vite.js do
// pacote). Sem ela — build de produção (`composer install --no-dev`) ou depois
// de `composer remove --dev twstec/kit-demo` — este arquivo não carrega nada
// e o build é só o do produto.
const demoVite = resolve('vendor/twstec/kit-demo/vite.js');
const demo = existsSync(demoVite) ? (await import(pathToFileURL(demoVite).href)).default() : null;

// Tema do /admin (resources/css/filament.css): só com o painel instalado. O
// /admin (twstec/kit-admin, que traz o Filament) é OPCIONAL — sem ele o
// filament.css não tem o que importar (o preset do Filament e as fontes do
// pacote) e fica fora do build.
const admin = existsSync(resolve('vendor/filament/filament')) && existsSync(resolve('vendor/twstec/kit-admin'));

// Docker de desenvolvimento (compose.yaml, serviço `vite`): o Vite roda num
// container e o navegador o acha pelo endereço do projeto
// (http://<nome>.localhost:<porta do Vite>, VITE_DEV_ORIGIN), não pelo do
// container. A PÁGINA vem de outra origem — o site, servido pelo nginx
// (http://<nome>.localhost:<porta do site>, VITE_DEV_APP_URL) —, e o navegador
// só executa os scripts do Vite se ele liberar essa origem no CORS. Com
// `server.origin` definido, o laravel-vite-plugin libera só a origem do
// próprio Vite (e a página fica em branco); por isso o CORS é declarado aqui:
// SÓ a origem do site, nenhuma outra (outro projeto `*.localhost` ou um site
// qualquer continuam sem o cabeçalho de liberação). Sem as variáveis (`npm run
// dev` na máquina), nada disso vale e o padrão do plugin continua.
// VITE_DEV_POLLING=true: pastas do Windows montadas no Docker não avisam
// quando um arquivo muda.
const devOrigin = process.env.VITE_DEV_ORIGIN ? new URL(process.env.VITE_DEV_ORIGIN) : null;
const devAppOrigin = process.env.VITE_DEV_APP_URL ? new URL(process.env.VITE_DEV_APP_URL).origin : null;
const devServer = devOrigin
    ? {
          host: '0.0.0.0',
          port: 5173,
          strictPort: true,
          origin: devOrigin.origin,
          hmr: { host: devOrigin.hostname, clientPort: Number(devOrigin.port) },
          // Só a origem do site; sem ela, nenhuma (nunca a do próprio Vite).
          cors: { origin: devAppOrigin ? [devAppOrigin] : [] },
      }
    : {};
const polling = process.env.VITE_DEV_POLLING === 'true';

export default defineConfig({
    plugins: [
        // Antes do Tailwind: a demo acrescenta as fontes dela ao CSS do app.
        ...(demo?.plugins ?? []),
        laravel({
            // filament.css = tema do /admin (mesmos tokens de theme.css) —
            // apontado por ->viteTheme() no AdminPanelProvider.
            input: [
                'resources/css/app.css',
                ...(admin ? ['resources/css/filament.css'] : []),
                'resources/js/app.js',
                ...(demo?.input ?? []),
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        ...devServer,
        watch: {
            usePolling: polling,
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
