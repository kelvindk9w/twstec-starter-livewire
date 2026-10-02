import { expect } from '@playwright/test';
import { codeFrom, hasCode, messagesAlreadyTo, waitForMessage } from './mailpit.js';
import { adminEmail, adminPassword, demoInstalled } from './project-env.js';

// =============================================================================
// Entrar no /admin (Filament) — o global-setup grava a sessão em
// `adminState`, e a limpeza das contas que os testes criam a reaproveita
// (support/cleanup.js).
//
// COM a demonstração do kit instalada: o SUPER ADMIN DEMO, pelo botão da tela
// de login com as credenciais PRÉ-PREENCHIDAS (sem digitar nada) — é também a
// verificação desse pré-preenchimento. SEM ela (o projeto criado): o admin do
// E2E (admin-e2e@example.com), criado por tests/e2e/fixtures.php, com e-mail e
// senha.
//
// SEGUNDO FATOR: se a conta o tem (AUTH_TWO_FACTOR_REQUIRED=admins|all — a
// conta demo protegida fica de fora da regra), o Filament troca o formulário
// pelo do código; o código REAL é lido no Mailpit. Um login por rodada: o
// código novo só sai depois do intervalo de reenvio.
// =============================================================================

/** A sessão do /admin gravada pelo global-setup. */
export const adminState = 'tests/e2e/.auth/admin.json';

/** Espera o Livewire do /admin iniciar (clicar antes não chega ao servidor). */
async function adminReady(page) {
    await page.waitForFunction(() => document.querySelector('[wire\\:id]')?.__livewire !== undefined, null, { timeout: 15_000 });
}

/**
 * Depois do envio do e-mail e da senha no /admin: se o formulário do código
 * apareceu, o código do Mailpit (só o que chegou depois de `seen`) entra.
 * Termina fora do login.
 */
export async function passAdminChallenge(page, request, email, seen) {
    const code = page.locator('input[autocomplete="one-time-code"]');

    await expect
        .poll(async () => !new URL(page.url()).pathname.endsWith('/login') || (await code.isVisible()), {
            message: `login de ${email} no /admin: nem o painel nem o código`,
            timeout: 15_000,
        })
        .toBe(true);

    if (await code.isVisible()) {
        const digits = codeFrom(await waitForMessage(request, email, seen, hasCode));
        await code.click();
        await page.keyboard.type(digits);
        await code.locator('xpath=ancestor::form').locator('button[type="submit"]').click();
    }

    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 15_000 });
}

export async function signInToAdmin(page, request) {
    const email = demoInstalled ? null : adminEmail;
    const seen = email !== null ? await messagesAlreadyTo(request, email) : new Set();

    await page.goto('/admin/login', { waitUntil: 'networkidle' });
    // O formulário do Filament é Livewire: clicar antes de ele inicializar
    // não chega ao servidor.
    await adminReady(page);

    if (!demoInstalled) {
        await page.locator('input[type="email"]').fill(adminEmail);
        await page.locator('input[type="password"]').fill(adminPassword);
    }

    await page.getByRole('button', { name: /entrar|sign in|iniciar|^login$/i }).click();

    // A conta demo é protegida e fica de fora da regra: sem código.
    if (email === null) {
        await page.waitForURL((url) => !url.pathname.includes('login'), { timeout: 15_000 });

        return;
    }

    await passAdminChallenge(page, request, email, seen);
}
