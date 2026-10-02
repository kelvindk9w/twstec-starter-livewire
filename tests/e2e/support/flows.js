import { expect } from '@playwright/test';
import { adminState } from './admin.js';
import { codeFrom, hasCode, hasVerificationLink, messagesAlreadyTo, verificationLinkFrom, waitForMessage } from './mailpit.js';
import { registrationOpen } from './registration.js';

// =============================================================================
// Passos repetidos pelos specs do painel: login (com o segundo fator, quando
// a conta o tem), a configuração do segundo fator OBRIGATÓRIO
// (AUTH_TWO_FACTOR_REQUIRED) e a pessoa nova — pelo cadastro público, ou pelo
// /admin quando ele está fechado (AUTH_REGISTRATION_ENABLED=false).
//
// O E2E não lê a configuração do projeto: quem decide é o servidor. Se o
// login leva à tela do código, o código REAL é lido no Mailpit; se a pessoa
// nova cai na configuração do segundo fator, ela passa por ela. Os campos são
// achados pelo `name` e pelos atributos `data-*` das telas.
// =============================================================================

/** Um endereço novo por teste e rodada (o cadastro não aceita repetido). */
export function newAddress(tag) {
    return `e2e-${tag}-${Date.now()}-${Math.floor(Math.random() * 1_000)}@example.com`;
}

/** Espera o Livewire da página iniciar (clicar antes não chega ao servidor). */
export async function livewireReady(page) {
    await page.waitForFunction(
        () => {
            const roots = document.querySelectorAll('[wire\\:id]');

            return roots.length > 0 && Array.from(roots).every((el) => el.__livewire !== undefined);
        },
        null,
        { timeout: 15_000 },
    );
}

/** Login pela tela do painel (só o envio do formulário). */
export async function submitLogin(page, email, password) {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('input[name="password"]').locator('xpath=ancestor::form').locator('button[type="submit"]').click();
}

/**
 * Depois do envio do login: se a conta tem o segundo fator, a tela do código
 * — e o código REAL do Mailpit entra. Termina fora do login e do código.
 * Devolve se passou pelo código.
 */
export async function passLoginChallenge(page, request, email, seen) {
    await page.waitForURL((url) => url.pathname !== '/login', { timeout: 15_000 });

    if (new URL(page.url()).pathname !== '/two-factor-challenge') {
        return false;
    }

    const code = codeFrom(await waitForMessage(request, email, seen, hasCode));
    const field = page.locator('input[name="code"]');
    await field.fill(code);
    await field.locator('xpath=ancestor::form').locator('button[type="submit"]').click();
    await page.waitForURL((url) => url.pathname !== '/two-factor-challenge', { timeout: 15_000 });

    return true;
}

/**
 * Login pela tela de uma pessoa FIXA, com o segundo fator quando a conta o
 * tem (as mensagens que ela já tinha no Mailpit não contam). Devolve se o
 * login passou pelo código.
 */
export async function signInToPanel(page, request, email, password) {
    const seen = await messagesAlreadyTo(request, email);

    await submitLogin(page, email, password);

    return passLoginChallenge(page, request, email, seen);
}

/**
 * A configuração do segundo fator OBRIGATÓRIO, na tela para onde o painel
 * leva quem ainda não ligou: senha de transação (se faltar) → senha de
 * transação para mandar o código → código REAL do Mailpit → a pessoa segue
 * para onde ia. Sem atalho: o mesmo caminho de quem usa.
 */
export async function completeTwoFactorSetup(page, request, address, seen, transactionPassword) {
    await expect(page).toHaveURL(/\/two-factor\/setup$/);

    const define = page.locator('[data-two-factor-setup-transaction-password]');
    const send = page.locator('[data-two-factor-setup-send]');
    await expect(define.or(send)).toBeVisible();

    if (await define.isVisible()) {
        await define.locator('input[name="transaction_password"]').fill(transactionPassword);
        await define.locator('input[name="transaction_password_confirmation"]').fill(transactionPassword);
        await define.locator('button[type="submit"]').click();
    }

    await expect(send).toBeVisible();
    await send.locator('input[name="transaction_password"]').fill(transactionPassword);
    await send.locator('button[type="submit"]').click();

    const confirm = page.locator('[data-two-factor-setup-code]');
    await expect(confirm).toBeVisible();
    const code = codeFrom(await waitForMessage(request, address, seen, hasCode));
    await confirm.locator('input[name="code"]').fill(code);
    await confirm.locator('button[type="submit"]').click();
    await page.waitForURL((url) => url.pathname !== '/two-factor/setup', { timeout: 15_000 });
}

/**
 * Cadastro pela tela e e-mail confirmado pelo link REAL do Mailpit: a pessoa
 * termina logada — no painel, ou, com o segundo fator obrigatório, na tela de
 * configuração dele (o painel fica fechado até lá).
 */
export async function registerAndVerify(page, request, address, name, password, seen) {
    await page.goto('/register');
    await page.locator('input[name="name"]').fill(name);
    await page.locator('input[name="email"]').fill(address);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('input[name="password_confirmation"]').fill(password);
    await page.locator('input[name="password"]').locator('xpath=ancestor::form').locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/email\/verify$/);

    await page.goto(verificationLinkFrom(await waitForMessage(request, address, seen, hasVerificationLink)));
    await expect(page).toHaveURL(/\/(dashboard|two-factor\/setup)$/);
}

/**
 * Cria a pessoa pelo /admin (o caminho das contas com o cadastro público
 * fechado), com a sessão do /admin do global-setup: nasce ativa e com o
 * e-mail confirmado. Sai no fim pela limpeza (support/cleanup.js), como as do
 * cadastro.
 */
export async function createPersonViaAdmin(browser, address, name, password) {
    const context = await browser.newContext({ storageState: adminState });
    const admin = await context.newPage();

    try {
        await admin.goto('/admin/users/create');
        await livewireReady(admin);
        await admin.locator('[id="form.name"]').fill(name);
        await admin.locator('[id="form.email"]').fill(address);
        await admin.locator('[id="form.password"]').fill(password);
        await admin.locator('[id="form.password_confirmation"]').fill(password);
        await admin.locator('[id="form.name"]').locator('xpath=ancestor::form').locator('button[type="submit"]').first().click();
        await admin.waitForURL((url) => !url.pathname.endsWith('/users/create'), { timeout: 15_000 });
    } finally {
        await context.close();
    }
}

/**
 * Uma pessoa NOVA, logada no painel, pelo caminho que a instalação oferece:
 * com o cadastro público aberto, cadastro + e-mail confirmado; fechado,
 * criada pelo /admin e login pela tela. Com o segundo fator obrigatório,
 * passa pela configuração dele (que define a senha de transação). Termina no
 * painel e diz se o segundo fator era obrigatório.
 */
export async function newPerson(page, request, browser, { address, name, password, transactionPassword, seen }) {
    if (await registrationOpen(request)) {
        await registerAndVerify(page, request, address, name, password, seen);
    } else {
        await createPersonViaAdmin(browser, address, name, password);
        await submitLogin(page, address, password);
        await page.waitForURL((url) => url.pathname !== '/login', { timeout: 15_000 });
    }

    const twoFactorRequired = new URL(page.url()).pathname === '/two-factor/setup';

    if (twoFactorRequired) {
        await completeTwoFactorSetup(page, request, address, seen, transactionPassword);
    }

    await expect(page).toHaveURL(/\/dashboard$/);

    return { twoFactorRequired };
}
