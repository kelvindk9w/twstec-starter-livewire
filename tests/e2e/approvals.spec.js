import { test, expect } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { passAdminChallenge } from './support/admin.js';
import { messagesAlreadyTo } from './support/mailpit.js';
import { adminEmail, adminPassword, demoInstalled, mailpitBaseUrl as mailpit } from './support/project-env.js';

// =============================================================================
// E2E da APROVAÇÃO EM DOIS PASSOS no /admin (quatro olhos), de ponta a ponta,
// SEM DEPENDER DE ESTADO ANTERIOR NEM DE IDIOMA:
//
// - quem PEDE é o admin da sessão do E2E (o super admin demo): cria um
//   produto PRÓPRIO (título único por execução) e pede o reajuste de preço —
//   a ação da demonstração que sempre exige aprovação. A notificação traz o
//   link do pedido: o teste segue exatamente ESTE pedido (nada de "o primeiro
//   pendente da lista");
// - no pedido, quem pediu não tem o botão de aprovar (a regra é do servidor;
//   os testes do pacote provam a recusa da chamada forjada);
// - OUTRA pessoa (o admin do E2E, tests/e2e/fixtures.php) aprova com a ação
//   sensível: senha de transação → código REAL entregue pelo worker ao
//   Mailpit → o preço do produto muda;
// - no fim — passando ou falhando — o pedido e o produto são apagados (o
//   pedido em aberto é recusado antes). O histórico fica na trilha.
//
// Seletores pelo NOME INTERNO das ações (`mountAction('approve')`), pelo id
// dos campos e pelos links, não pelos rótulos: outro spec troca o idioma da
// conta demo por um instante, e o texto da tela não pode decidir o teste.
//
// ECONOMIA DE REQUISIÇÕES: a suíte inteira divide o teto por IP da borda
// (RATE_LIMIT_WEB, 300/min) e já roda perto dele. Um login a mais só (o do
// aprovador, direto na tela do pedido, sem passar pelo dashboard), nenhuma
// tela aberta só para conferir (a trilha e o preço intacto antes da aprovação
// estão provados na suíte PHP), e a limpeza aproveita a tela aberta.
// =============================================================================

const transactionPassword = 'Trans4cao!Segura';

const demoTest = demoInstalled ? test : () => {};

test.use({ storageState: 'tests/e2e/.auth/admin.json' });

/** O botão de uma Action do Filament pelo nome interno. */
const action = (page, name) => page.locator(`[wire\\:click^="mountAction('${name}'"]`);

async function livewireReady(page) {
    await page.waitForFunction(() => {
        const roots = document.querySelectorAll('[wire\\:id]');

        return roots.length > 0 && Array.from(roots).every((el) => el.__livewire !== undefined);
    }, null, { timeout: 15_000 });
}

async function open(page, path) {
    await page.goto(path);
    await livewireReady(page);
}

/** Envia (confirma) o modal aberto. */
async function submitModal(page, timeout = undefined) {
    // O botão que envia o modal montado (formulário ou confirmação simples —
    // esta abre como `alertdialog`, por isso o seletor não depende do papel).
    await page
        .locator('button[type="submit"][wire\\:target="callMountedAction"]')
        .filter({ visible: true })
        .last()
        .click({ timeout });
}

/**
 * Entra direto numa tela do painel: o pedido sem sessão vai para o login, e o
 * login devolve à tela pedida (sem passar pelo dashboard — menos requisições
 * contra o teto por IP da borda, que a suíte inteira divide). Com o segundo
 * fator obrigatório (AUTH_TWO_FACTOR_REQUIRED=admins|all), o código REAL do
 * Mailpit entra no formulário que o Filament troca no lugar do login.
 */
async function signInAt(page, request, path, email, password) {
    const seen = await messagesAlreadyTo(request, email);

    await page.goto(path);
    await page.waitForURL(/\/admin\/login$/);
    await livewireReady(page);
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password);
    await page.locator('form button[type="submit"]').first().click();
    await passAdminChallenge(page, request, email, seen);
    await page.waitForURL((url) => url.pathname === path, { timeout: 15_000 });
    await livewireReady(page);
}

async function existingMessages(request, address) {
    const response = await request.get(`${mailpit}/api/v1/search`, { params: { query: `to:"${address}"` } });
    const body = await response.json();

    return new Set((body.messages ?? []).map((m) => m.ID));
}

async function waitForCode(request, address, seen) {
    let found = null;

    await expect
        .poll(
            async () => {
                const response = await request.get(`${mailpit}/api/v1/search`, { params: { query: `to:"${address}"` } });
                const body = await response.json();
                found = (body.messages ?? []).find((m) => !seen.has(m.ID)) ?? null;

                return found?.ID ?? null;
            },
            { timeout: 20_000, intervals: [500, 1_000] },
        )
        .not.toBeNull();

    const message = await (await request.get(`${mailpit}/api/v1/message/${found.ID}`)).json();
    const code = message.Text.match(/\b(\d{6})\b/)?.[1];
    expect(code, 'código de 6 dígitos no texto do e-mail').toBeTruthy();

    return code;
}

/** Limpeza que não derruba o teste: recusa o pedido em aberto e apaga os dois. */
async function cleanUp(page, requestPath, productPath) {
    if (requestPath) {
        try {
            await open(page, requestPath);

            if (await action(page, 'reject').count()) {
                await action(page, 'reject').click({ timeout: 10_000 });
                await page.getByRole('dialog').locator('textarea').fill('Limpeza do E2E');
                await submitModal(page, 10_000);
                await expect(action(page, 'reject')).toHaveCount(0);
            }

            await action(page, 'delete').click({ timeout: 10_000 });
            await submitModal(page, 10_000);
            await page.waitForURL(/\/admin\/approval-requests$/, { timeout: 15_000 });
        } catch (error) {
            console.warn(`limpeza do pedido ${requestPath} falhou: ${error.message}`);
        }
    }

    if (productPath) {
        try {
            if (new URL(page.url()).pathname !== productPath) {
                await open(page, productPath);
            }

            await action(page, 'delete').click({ timeout: 10_000 });
            await submitModal(page, 10_000);
            await page.waitForURL(/\/admin\/products$/, { timeout: 15_000 });
        } catch (error) {
            console.warn(`limpeza do produto ${productPath} falhou: ${error.message}`);
        }
    }
}

/**
 * Espera a janela seguinte do teto por IP da borda: a suíte inteira (oito
 * navegadores em paralelo, do mesmo IP) gasta quase todo o orçamento do
 * primeiro minuto, e este spec — o que mais conversa com o servidor (um
 * fluxo de ponta a ponta com dois modais e um login) — faria os specs de
 * outro arquivo receberem 429. Esperando a janela seguinte, as requisições
 * dele não disputam com as deles.
 */
async function waitForNextRateWindow(page) {
    const marker = 'tests/e2e/.auth/suite-started-at';
    const startedAt = existsSync(marker) ? Number(readFileSync(marker, 'utf8')) : Date.now();
    const remaining = startedAt + 62_000 - Date.now();

    if (remaining > 0) {
        await page.waitForTimeout(remaining);
    }
}

demoTest('quatro olhos no /admin: quem pediu não aprova; outra pessoa aprova com a ação sensível e o reajuste executa', async ({ page, browser, request }) => {
    test.setTimeout(180_000);

    await waitForNextRateWindow(page);

    const title = `e2e-reajuste-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
    const approverContext = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    let productPath = '';
    let requestPath = '';
    let deletedRequest = false;

    try {
        await test.step('quem pede cria o próprio produto', async () => {
            await open(page, '/admin/products/create');
            await page.locator('[id="form.title"]').fill(title);
            await page.locator('[id="form.price"]').fill('100,00');
            await page.locator('button[type="submit"][wire\\:target="create"]').click();
            await page.waitForURL(/\/admin\/products\/[0-9a-f-]{36}\/edit$/);
            await livewireReady(page);

            productPath = new URL(page.url()).pathname;
        });

        await test.step('pede o reajuste (vira pedido) e segue o link do pedido', async () => {
            await action(page, 'reprice').click();
            const modal = page.getByRole('dialog');
            await modal.locator('[id$=".price"]').fill('150,00');
            await modal.locator('textarea').fill(`Reajuste do E2E ${title}`);
            await submitModal(page);

            const link = page.locator('a[href*="/admin/approval-requests/"]').filter({ visible: true }).first();
            await expect(link).toBeVisible();
            requestPath = new URL(await link.getAttribute('href')).pathname;
            expect(requestPath).toMatch(/^\/admin\/approval-requests\/[0-9a-f-]{36}$/);
        });

        await test.step('no pedido, quem pediu não tem como aprovar', async () => {
            await open(page, requestPath);
            // É o pedido DESTE produto (o uuid do alvo; o motivo passa pela
            // redação da trilha, que mascara sequências longas de dígitos).
            const productUuid = productPath.split('/')[3];
            await expect(page.getByText(productUuid, { exact: false }).first()).toBeVisible();
            await expect(action(page, 'reject')).toBeVisible();
            await expect(action(page, 'approve')).toHaveCount(0);
        });

        await test.step('outra pessoa aprova: senha de transação → código do Mailpit → executa', async () => {
            const approverPage = await approverContext.newPage();
            await signInAt(approverPage, request, requestPath, adminEmail, adminPassword);

            const seen = await existingMessages(request, adminEmail);

            await action(approverPage, 'approve').click();
            await approverPage.getByRole('dialog').locator('input[type="password"]').fill(transactionPassword);
            await submitModal(approverPage);

            const code = await waitForCode(request, adminEmail, seen);
            await approverPage.getByRole('dialog').locator('[id$=".code"]').first().fill(code);
            await submitModal(approverPage);

            // Encerrado: nem aprovar nem recusar; só excluir — e quem aprovou
            // já limpa o pedido (o histórico fica na trilha).
            await expect(action(approverPage, 'delete')).toBeVisible();
            await expect(action(approverPage, 'approve')).toHaveCount(0);
            await expect(action(approverPage, 'reject')).toHaveCount(0);

            await action(approverPage, 'delete').click();
            await submitModal(approverPage);
            await approverPage.waitForURL(/\/admin\/approval-requests$/);
            deletedRequest = true;
        });

        await test.step('o preço mudou (a execução aconteceu)', async () => {
            await open(page, productPath);
            await expect(page.locator('[id="form.price"]')).toHaveValue(/150[,.]00/);
        });
    } finally {
        await cleanUp(page, deletedRequest ? '' : requestPath, productPath);
        await approverContext.close();
    }

    // A limpeza é parte do teste: nada fica para a próxima execução.
    expect((await page.goto(requestPath)).status()).toBe(404);
    expect((await page.goto(productPath)).status()).toBe(404);
});
