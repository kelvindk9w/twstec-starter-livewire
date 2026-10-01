import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { signInToAdmin } from './support/admin.js';
import { installed, isolationProblem, mailpitBaseUrl, userEmail, userPassword } from './support/project-env.js';

// =============================================================================
// Global setup E2E: autentica UMA vez e grava o storageState reutilizado
// pelos testes autenticados. Necessário porque o login tem rate limit
// agressivo (throttle:sensitive; o do /admin é o limite do Filament): um
// login por teste estouraria o limite e invalidaria a suíte.
//
// ANTES DE TUDO, a trava de isolamento (support/project-env.js): o site que
// responde tem de ser ESTE projeto, e o Mailpit o dele — senão a suíte para
// sem criar nem apagar nada.
//
// Duas sessões:
// - `e2e.json`: a conta comum do painel do cliente (e2e@example.com — ver
//   tests/e2e/fixtures.php);
// - `admin.json`: o /admin (support/admin.js — o super admin demo, com a
//   demonstração instalada; o admin do E2E, sem ela).
// =============================================================================

function refuse(problem) {
    throw new Error(`global-setup: ${problem}. O E2E cria e apaga pessoas: ele só roda no próprio projeto.`);
}

export default async function globalSetup(config) {
    const baseURL = config.projects[0].use.baseURL;
    const configured = isolationProblem(String(baseURL), mailpitBaseUrl, null);

    if (configured !== null) {
        refuse(configured);
    }

    mkdirSync('tests/e2e/.auth', { recursive: true });

    // Quando a suíte começou a falar com o site: o teto por IP da borda
    // (RATE_LIMIT_WEB por minuto) é dividido por todos os specs, e quem
    // precisa de uma janela própria espera a seguinte (approvals.spec.js).
    writeFileSync('tests/e2e/.auth/suite-started-at', String(Date.now()));

    const browser = await chromium.launch();

    try {
        const page = await browser.newPage({ baseURL });

        await page.goto('/login');
        const answered = isolationProblem(String(baseURL), mailpitBaseUrl, (await page.context().cookies()).map((cookie) => cookie.name));

        if (answered !== null) {
            refuse(answered);
        }

        await page.getByLabel('E-mail').fill(userEmail);
        await page.getByLabel('Senha', { exact: true }).fill(userPassword);
        await page.getByRole('button', { name: 'Entrar' }).click();
        await page.waitForURL(/\/dashboard$/).catch(() => {
            throw new Error(`global-setup: login de ${userEmail} não chegou ao painel — rode tests/e2e/fixtures.php (ver playwright.config.js)`);
        });

        await page.context().storageState({ path: 'tests/e2e/.auth/e2e.json' });

        // O /admin é opcional (twstec/kit-admin): sem ele, não há sessão de
        // admin (e os specs dele ficam de fora — playwright.config.js).
        if (installed('admin')) {
            const admin = await browser.newPage({ baseURL });

            await signInToAdmin(admin);
            await admin.context().storageState({ path: 'tests/e2e/.auth/admin.json' });
        }
    } finally {
        await browser.close();
    }
}
