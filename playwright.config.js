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
//   2. As pessoas fixas do E2E (idempotente — rode antes de cada rodada e
//      sempre que AUTH_TWO_FACTOR_REQUIRED mudar):
//        docker compose exec -T app php artisan tinker \
//          --execute="require 'tests/e2e/fixtures.php';"
//
// A suíte roda com AUTH_TWO_FACTOR_REQUIRED=none|admins|all e com o cadastro
// aberto ou fechado (AUTH_REGISTRATION_ENABLED): quem decide é o servidor —
// o login que pede código lê o código no Mailpit, a pessoa nova que cai na
// configuração do segundo fator passa por ela, e sem cadastro a pessoa nova
// nasce pelo /admin. Como trocar a combinação: a documentação de testes do
// kit (docs/testes.md, "E2E com segundo fator obrigatório e cadastro fechado").
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
    ...(installed('admin') ? [] : ['**/admin.spec.js', '**/approvals.spec.js']),
    ...(installed('accounts') ? [] : ['**/accounts.spec.js']),
    ...(installed('webhooks') ? [] : ['**/webhooks.spec.js']),
];

export default defineConfig({
    testDir: './tests/e2e',
    testIgnore: ignored,
    // Autentica UMA vez e compartilha a sessão (o login tem rate limit —
    // throttle:sensitive). Ver tests/e2e/global-setup.js.
    globalSetup: './tests/e2e/global-setup.js',
    // As mensagens das pessoas fixas (códigos de login e de confirmação) saem
    // do Mailpit no fim da rodada. Ver tests/e2e/global-teardown.js.
    globalTeardown: './tests/e2e/global-teardown.js',
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
