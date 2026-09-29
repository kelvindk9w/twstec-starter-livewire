// Capturas de validação visual das telas AUTENTICADAS + landing/showcase/login
// (o screenshots.js cobre só as públicas). Desktop e mobile, claro e escuro.
//
// Uso (stack de dev no ar):  node tests/e2e/shots-panel.js <pasta-destino>
// Gera: test-results/<pasta>/<tela>-<viewport>-<tema>.png
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const outDir = `test-results/${process.argv[2] ?? 'panel'}`;
const base = process.env.E2E_BASE_URL ?? 'http://localhost:8180';
const email = process.env.E2E_USER_EMAIL ?? 'demo@tws.dev';
const password = process.env.E2E_USER_PASSWORD ?? 'Demo-password1';

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch();

// Sessão autenticada compartilhada (o login tem rate limit agressivo).
const auth = await browser.newContext({ baseURL: base });
const login = await auth.newPage();
await login.goto('/login');
await login.getByLabel('E-mail').fill(email);
await login.getByLabel('Senha', { exact: true }).fill(password);
await login.getByRole('button', { name: 'Entrar' }).click();
await login.waitForURL(/\/dashboard$/);
const storageState = await auth.storageState();
await auth.close();

const viewports = [
    ['desktop', { width: 1440, height: 900 }],
    ['mobile', { width: 390, height: 844 }],
];

const screens = [
    ['dashboard', '/dashboard', true],
    ['profile', '/profile', true],
    ['ui', '/ui', false],
    ['landing', '/', false],
    ['login', '/login', false],
];

async function settle(page) {
    await page.evaluate(async () => {
        const step = window.innerHeight * 0.8;
        for (let y = 0; y < document.body.scrollHeight; y += step) {
            window.scrollTo(0, y);
            await new Promise((r) => setTimeout(r, 100));
        }
        window.scrollTo(0, 0);
    });
    await page.waitForTimeout(500);
}

for (const [vpName, viewport] of viewports) {
    for (const theme of ['light', 'dark']) {
        for (const [name, path, authenticated] of screens) {
            const context = await browser.newContext({
                baseURL: base,
                viewport,
                storageState: authenticated ? storageState : undefined,
            });
            const page = await context.newPage();
            await page.addInitScript((t) => localStorage.setItem('theme', t), theme);
            await page.goto(path, { waitUntil: 'networkidle' });
            await settle(page);
            await page.screenshot({ path: `${outDir}/${name}-${vpName}-${theme}.png`, fullPage: true });
            await context.close();
        }
    }
}

await browser.close();
console.log(`ok → ${outDir}`);
