// E2E da landing oficial (/) — o que só o navegador prova: o split-screen
// arrastável, o bento vivo, a cena pinada do herói, o fallback do 3D e a
// ausência de scroll lateral no celular.
//
// Rodar: npx playwright test tests/e2e/landing.spec.js
import { test, expect } from '@playwright/test';

const DESKTOP = { width: 1440, height: 900 };
const MOBILE = { width: 390, height: 844 };

test.describe('landing oficial — /', () => {
    test('as seções chegam ao navegador, com as âncoras do menu do site', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/');

        await expect(page.locator('[data-sky-hero]')).toBeVisible();
        await expect(page.locator('#componentes')).toBeAttached();
        await expect(page.locator('#como-funciona')).toBeAttached();
        await expect(page.locator('#recursos')).toBeAttached();
        await expect(page.locator('#horas')).toBeAttached();
        await expect(page.locator('#stack')).toBeAttached();
        await expect(page.locator('#contato')).toBeAttached();
        await expect(page.locator('[data-sky-3d-layout="arc"], [data-sky-layout="arc"]')).toBeAttached();
    });

    test('o endereço antigo /v3 leva à home sem passar por 404', async ({ page }) => {
        const response = await page.goto('/v3');

        expect(new URL(page.url()).pathname).toBe('/');
        expect(response.status()).toBe(200);
        await expect(page.locator('[data-sky-hero]')).toBeVisible();
    });

    test('o leque mostra as quatro telas reais, já carregadas', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/');

        const cards = page.locator('[data-sky-fan-card]');
        await expect(cards).toHaveCount(4);

        // A carta central é o LCP: tem de estar decodificada, não só no DOM.
        const loaded = await cards.nth(2).locator('img:visible').first().evaluate((img) => img.complete && img.naturalWidth > 0);
        expect(loaded).toBe(true);
    });

    test('o split-screen é arrastável no ponteiro e no teclado', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/');

        const split = page.locator('[data-sky-split]');
        await split.scrollIntoViewIfNeeded();

        const range = split.locator('[data-sky-split-range]');
        await expect(range).toHaveAttribute('aria-label', /.+/);

        // Teclado: a seta move o controle E a linha (a variável CSS é a
        // ÚNICA fonte da posição — se ela não mudar, o arrasto é falso).
        await range.focus();
        const before = await split.evaluate((el) => el.style.getPropertyValue('--sky-split'));
        await range.press('ArrowRight');
        await range.press('ArrowRight');
        await range.press('ArrowRight');
        const after = await split.evaluate((el) => el.style.getPropertyValue('--sky-split'));
        expect(after).not.toBe(before);

        // Ponteiro: arrastar leva a linha para perto de onde o dedo soltou.
        const box = await split.boundingBox();
        await page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.5);
        await page.mouse.down();
        await page.mouse.move(box.x + box.width * 0.8, box.y + box.height * 0.5, { steps: 8 });
        await page.mouse.up();

        const dragged = Number.parseFloat(await split.evaluate((el) => el.style.getPropertyValue('--sky-split')));
        expect(dragged).toBeGreaterThan(65);
    });

    test('o bento acende uma célula por vez e para no ponteiro', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/');

        const bento = page.locator('[data-sky-bento]');
        await bento.scrollIntoViewIfNeeded();

        await expect(bento.locator('[data-sky-cell]')).toHaveCount(6);
        await expect(bento.locator('.is-live')).toHaveCount(1);

        // Com o ponteiro dentro, o rodízio para: a célula viva não muda.
        await bento.hover();
        const live = await bento.locator('.is-live').getAttribute('class');
        await page.waitForTimeout(4200);
        await expect(bento.locator('.is-live')).toHaveCount(1);
        expect(await bento.locator('.is-live').getAttribute('class')).toBe(live);
    });

    test('a cena do herói é dirigida pelo scroll e depois solta a página', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/');
        await page.waitForTimeout(1200);

        const hero = page.locator('[data-sky-hero]');
        const topBefore = (await hero.boundingBox()).y;

        await page.mouse.wheel(0, 700);
        await page.waitForTimeout(900);

        // Pinado: a página rolou 700px e o herói praticamente não saiu do
        // lugar. A folga existe porque o scroll é suavizado (Lenis) e a
        // medida cai no meio da inércia — o que se prova aqui é que o herói
        // NÃO acompanhou a rolagem, não que ele ficou congelado no pixel.
        const pinned = await hero.boundingBox();
        expect(Math.abs(pinned.y - topBefore)).toBeLessThan(200);

        // O leque se abriu (o GSAP escreveu a rotação nas cartas).
        const rotated = await page
            .locator('[data-sky-fan-card]')
            .first()
            .evaluate((el) => el.style.transform);
        expect(rotated).toContain('rotate');

        // Depois do fim do pin a página volta a rolar normalmente.
        await page.mouse.wheel(0, 2600);
        await page.waitForTimeout(900);
        expect((await hero.boundingBox()).y).toBeLessThan(topBefore - 100);
    });

    test('sem WebGL a página continua inteira: o fallback de vidro fica', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.addInitScript(() => {
            // Simula um aparelho sem WebGL2 (o guard da landing desiste antes de
            // baixar o Three.js).
            HTMLCanvasElement.prototype.getContext = () => null;
        });
        await page.goto('/');
        await page.waitForTimeout(1200);

        await expect(page.locator('[data-sky-three-ready]')).toHaveCount(0);
        await expect(page.locator('[data-sky-3d-fallback] .sky-tech-cube').first()).toBeVisible();
        // O logo oficial dentro do cubo é forma preenchida, não traço.
        await expect(page.locator('[data-sky-mark="laravel"]').first()).toBeAttached();
        // Os nomes das tecnologias são conteúdo e nunca dependem do 3D.
        await expect(page.getByText('PostgreSQL', { exact: true }).first()).toBeVisible();
    });

    test('no celular não existe scroll lateral em nenhuma altura da página', async ({ page }) => {
        await page.setViewportSize(MOBILE);
        await page.goto('/');
        await page.waitForTimeout(800);

        const total = await page.evaluate(() => document.documentElement.scrollHeight);

        for (let y = 0; y <= total; y += MOBILE.height * 0.75) {
            await page.evaluate((value) => window.scrollTo(0, value), y);
            await page.waitForTimeout(220);

            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, `scroll lateral em y=${Math.round(y)}`).toBeLessThanOrEqual(0);
        }
    });

    test('o teclado alcança o conteúdo pelo atalho de pular', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/');

        await page.keyboard.press('Tab');
        const skip = page.locator('a[href="#conteudo"]');
        await expect(skip).toBeFocused();
        await expect(skip).toBeVisible();
    });
});
