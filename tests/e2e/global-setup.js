import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

// =============================================================================
// Global setup E2E: autentica UMA vez e grava o storageState reutilizado
// pelos testes autenticados. Necessário porque o login tem rate limit
// agressivo (throttle:sensitive; o do /admin é o limite do Filament): um
// login por teste estouraria o limite e invalidaria a suíte.
//
// Duas sessões:
// - `e2e.json`: a conta comum do painel do cliente;
// - `admin.json`: o SUPER ADMIN DEMO, entrando pelo botão da tela de login
//   do /admin com as credenciais PRÉ-PREENCHIDAS (sem digitar nada). Esta é
//   também a verificação desse pré-preenchimento: se o botão sozinho não
//   autenticar, o setup falha e a suíte inteira para.
// =============================================================================

const email = process.env.E2E_USER_EMAIL ?? 'e2e@example.com';
const password = process.env.E2E_USER_PASSWORD ?? 'E2eSenhaForte123';

export default async function globalSetup(config) {
    const baseURL = config.projects[0].use.baseURL;

    mkdirSync('tests/e2e/.auth', { recursive: true });

    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL });

    await page.goto('/login');
    await page.getByLabel('E-mail').fill(email);
    await page.getByLabel('Senha', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await page.waitForURL(/\/dashboard$/);

    await page.context().storageState({ path: 'tests/e2e/.auth/e2e.json' });

    const admin = await browser.newPage({ baseURL });

    await admin.goto('/admin/login');
    // O formulário do Filament é Livewire: clicar antes de ele inicializar
    // não chega ao servidor.
    await admin.waitForFunction(() => document.querySelector('[wire\\:id]')?.__livewire !== undefined, null, { timeout: 15000 });
    await admin.getByRole('button', { name: /entrar|sign in|iniciar|^login$/i }).click();
    await admin.waitForURL((url) => !url.pathname.includes('login'), { timeout: 15000 });

    await admin.context().storageState({ path: 'tests/e2e/.auth/admin.json' });
    await browser.close();
}
