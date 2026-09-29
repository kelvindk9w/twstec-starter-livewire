// Screenshots de validação visual do super admin + landing com o tema
// monocromático (execução manual: node tests/e2e/shots-admin.js).
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const base = 'http://localhost:8180';
mkdirSync('test-results/shots', { recursive: true });

const browser = await chromium.launch();

async function settle(page) {
    await page.evaluate(async () => {
        const step = window.innerHeight * 0.8;
        for (let y = 0; y < document.body.scrollHeight; y += step) {
            window.scrollTo(0, y);
            await new Promise((r) => setTimeout(r, 120));
        }
        window.scrollTo(0, 0);
    });
    await page.waitForTimeout(700);
}

// --- Landing nos 2 temas (nova identidade neutra) ----------------------------
for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.addInitScript((t) => localStorage.setItem('theme', t), theme);
    await page.goto('/', { waitUntil: 'networkidle' });
    await settle(page);
    await page.screenshot({ path: `test-results/shots/rebrand-landing-${theme}.png`, fullPage: true });
    await context.close();
}

// --- Admin: login demo → telas -----------------------------------------------
for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    await page.goto('/admin/login', { waitUntil: 'networkidle' });
    // Credenciais do admin demo vêm pré-preenchidas (config ui.demo_admin).
    await page.getByRole('button', { name: /entrar|sign in|iniciar|login/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('login'), { timeout: 15000 });
    await page.waitForLoadState('networkidle');

    // Tema do admin: o Filament segue a preferência do sistema por padrão.
    await page.emulateMedia({ colorScheme: theme === 'dark' ? 'dark' : 'light' });
    await page.reload({ waitUntil: 'networkidle' });

    await page.screenshot({ path: `test-results/shots/rebrand-admin-dashboard-${theme}.png`, fullPage: true });

    await page.goto('/admin/products', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.screenshot({ path: `test-results/shots/rebrand-admin-products-${theme}.png`, fullPage: true });

    await page.goto('/admin/request-logs', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.screenshot({ path: `test-results/shots/rebrand-admin-request-logs-${theme}.png`, fullPage: true });

    await page.goto('/admin/profile', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.screenshot({ path: `test-results/shots/rebrand-admin-profile-${theme}.png`, fullPage: true });

    await page.goto('/admin/form-submissions', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    await page.screenshot({ path: `test-results/shots/rebrand-admin-submissions-${theme}.png`, fullPage: true });

    await context.close();
}

// --- Showcase /ui: seção de formulários nos 2 temas --------------------------
for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.addInitScript((t) => localStorage.setItem('theme', t), theme);
    await page.goto('/ui#form_patterns', { waitUntil: 'networkidle' });
    await page.locator('#form_patterns').scrollIntoViewIfNeeded();
    await page.waitForTimeout(900);
    await page.screenshot({ path: `test-results/shots/rebrand-ui-forms-${theme}.png` });
    await context.close();
}

await browser.close();
console.log('shots salvos em test-results/shots/');
