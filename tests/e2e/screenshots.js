// Screenshots de validação visual da landing e do showcase (dev).
// Cobre: desktop+mobile, tema claro+escuro e os 3 idiomas na landing.
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const base = 'http://localhost:8180';
mkdirSync('test-results/shots', { recursive: true });

const browser = await chromium.launch();

// Rola a página inteira para disparar o scroll-reveal (IntersectionObserver)
// e espera as transitions terminarem antes da captura fullPage.
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

// Tema via localStorage ('light' | 'dark' | 'system') antes do primeiro load.
// Idioma: os cookies da app são criptografados (EncryptCookies) — passamos
// pela rota real de troca (/locale/{locale}), que grava o cookie correto.
async function themedPage(viewport, theme, locale = 'pt_BR') {
    const context = await browser.newContext({ baseURL: base, viewport });
    const page = await context.newPage();
    await page.addInitScript((t) => localStorage.setItem('theme', t), theme);
    if (locale !== 'pt_BR') {
        await page.goto(`/locale/${locale}`, { waitUntil: 'networkidle' });
    }
    return { context, page };
}

// --- Landing: 2 viewports × 2 temas ------------------------------------------
for (const [name, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
    for (const theme of ['dark', 'light']) {
        const { context, page } = await themedPage(viewport, theme);
        await page.goto('/', { waitUntil: 'networkidle' });
        await settle(page);
        await page.screenshot({ path: `test-results/shots/landing-${name}-${theme}.png`, fullPage: true });
        await context.close();
    }
}

// --- Landing nos 3 idiomas (desktop, escuro) ----------------------------------
for (const locale of ['pt_BR', 'en', 'es']) {
    const { context, page } = await themedPage({ width: 1440, height: 900 }, 'dark', locale);
    await page.goto('/', { waitUntil: 'networkidle' });
    await settle(page);
    await page.screenshot({ path: `test-results/shots/landing-lang-${locale}.png`, fullPage: true });
    await context.close();
}

// --- Showcase: desktop 2 temas + mobile escuro --------------------------------
{
    const { context, page } = await themedPage({ width: 1440, height: 900 }, 'dark');
    await page.goto('/ui', { waitUntil: 'networkidle' });
    await settle(page);
    await page.screenshot({ path: 'test-results/shots/showcase-desktop.png', fullPage: true });
    await context.close();
}
{
    const { context, page } = await themedPage({ width: 1440, height: 900 }, 'light');
    await page.goto('/ui', { waitUntil: 'networkidle' });
    await settle(page);
    await page.screenshot({ path: 'test-results/shots/showcase-light.png', fullPage: true });
    await context.close();
}
{
    const { context, page } = await themedPage({ width: 390, height: 844 }, 'dark');
    await page.goto('/ui', { waitUntil: 'networkidle' });
    await settle(page);
    await page.screenshot({ path: 'test-results/shots/showcase-mobile.png', fullPage: true });
    await context.close();
}

// --- Login (credenciais demo) e login do admin demo ---------------------------
{
    const page = await browser.newPage({ baseURL: base, viewport: { width: 1440, height: 900 } });
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'test-results/shots/login-demo.png' });
    await page.goto('/admin/login', { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    await page.screenshot({ path: 'test-results/shots/admin-login-demo.png' });
    await page.close();
}

await browser.close();
console.log('screenshots ok');
