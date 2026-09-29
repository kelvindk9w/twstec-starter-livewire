import { defineConfig, devices } from '@playwright/test';

// =============================================================================
// Playwright — testes E2E (testes validam CONTEÚDO, não só status).
//
// Pré-requisito: stack de dev no ar (`docker compose up -d`).
// Rodar local (Node instalado):  npx playwright test
// Rodar em container (sem Node local):
//   docker run --rm --network host -v $(pwd):/work -w /work \
//     mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
// =============================================================================

export default defineConfig({
    testDir: './tests/e2e',
    // Autentica UMA vez e compartilha a sessão (o login tem rate limit —
    // throttle:sensitive). Ver tests/e2e/global-setup.js.
    globalSetup: './tests/e2e/global-setup.js',
    timeout: 30_000,
    retries: process.env.CI ? 1 : 0,
    reporter: [['list']],
    use: {
        // URL da stack de dev (nginx publica na 8180 do host).
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8180',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
