import { defineConfig, devices } from '@playwright/test';
import { baseUrl, demoInstalled, installed } from './tests/e2e/support/project-env.js';

// =============================================================================
// Playwright — testes E2E (testes validam CONTEÚDO, não só status).
//
// ONDE: no site e no Mailpit DESTE projeto — E2E_BASE_URL e E2E_MAILPIT_URL,
// ou, sem elas, a APP_URL e a DEV_MAIL_PORT do .env (ver
// tests/e2e/support/project-env.js). O global-setup confere, antes de
// qualquer teste, que o site que responde é mesmo este projeto.
//
// Pré-requisitos (na raiz do projeto):
//   1. O projeto no ar: docker compose up -d
//      (o worker `queue` entrega os e-mails ao Mailpit).
//   2. As pessoas fixas do E2E (idempotente — pode rodar sempre):
//        docker compose exec -T app php artisan tinker \
//          --execute="require 'tests/e2e/fixtures.php';"
//
// Rodar em container (sem Node na máquina), na raiz do projeto:
//   docker run --rm --network host --user $(id -u):$(id -g) -e HOME=/tmp \
//     -v $(pwd):/work -w /work mcr.microsoft.com/playwright:v1.63.0-noble \
//     npx playwright test
//
// Os specs da DEMONSTRAÇÃO do kit (landings, vitrine /ui; os testes do /admin
// sobre os dados demo — admin.spec.js, `demoTest`) e os de um módulo opcional
// ausente ficam de fora sozinhos — não aparecem como pulados. Espere 60 s entre duas rodadas (o limite de borda
// por IP).
// =============================================================================

const ignored = [
    ...(demoInstalled ? [] : ['**/smoke.spec.js', '**/landing.spec.js', '**/landing-v2.spec.js', '**/form-patterns.spec.js']),
    ...(installed('admin') ? [] : ['**/admin.spec.js']),
    ...(installed('accounts') ? [] : ['**/accounts.spec.js']),
];

export default defineConfig({
    testDir: './tests/e2e',
    testIgnore: ignored,
    // Autentica UMA vez e compartilha a sessão (o login tem rate limit —
    // throttle:sensitive). Ver tests/e2e/global-setup.js.
    globalSetup: './tests/e2e/global-setup.js',
    timeout: 30_000,
    retries: process.env.CI ? 1 : 0,
    reporter: [['list']],
    use: {
        baseURL: baseUrl,
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
