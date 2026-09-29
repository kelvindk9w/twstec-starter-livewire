import { test, expect } from '@playwright/test';
import { deleteAccountViaAdmin, deleteMailpitMessagesTo } from './support/cleanup.js';

// =============================================================================
// E2E da verificação em duas etapas no login, de ponta a ponta e sem atalho:
// conta nova → senha de transação → LIGA o segundo fator no perfil (senha de
// transação + código por e-mail) → sai → entra com a senha → tela do código
// (sessão ainda NÃO autenticada) → código REAL entregue pelo worker ao Mailpit
// → painel. No fim, o próprio teste apaga o que criou: a conta (pelo /admin,
// com o super admin demo) e as mensagens do Mailpit.
//
// Por que uma conta NOVA e não o e2e@example.com: ligar o segundo fator na
// conta compartilhada quebraria o login do global-setup e dos outros specs.
//
// Pré-requisitos: stack de dev no ar com o worker `queue` e o Mailpit
// (E2E_MAILPIT_URL, padrão http://localhost:18025), modo demo ligado (o
// super admin demo faz a limpeza).
// =============================================================================

const mailpit = process.env.E2E_MAILPIT_URL ?? 'http://localhost:18025';
const loginPassword = 'SenhaForte123';
const transactionPassword = 'Transacao2fa9';

/**
 * Espera a mensagem para `address` cujo assunto contém `subject` e devolve o
 * JSON completo dela. `after` ignora mensagens já vistas (códigos antigos).
 */
async function waitForMessage(request, address, subject, seen = new Set()) {
    let found = null;

    await expect
        .poll(
            async () => {
                const response = await request.get(`${mailpit}/api/v1/search`, {
                    params: { query: `to:"${address}" subject:"${subject}"` },
                });
                const body = await response.json();
                found = (body.messages ?? []).find((m) => !seen.has(m.ID)) ?? null;

                return found?.ID ?? null;
            },
            { timeout: 20_000, intervals: [500, 1_000] },
        )
        .not.toBeNull();

    seen.add(found.ID);

    return (await request.get(`${mailpit}/api/v1/message/${found.ID}`)).json();
}

function codeFrom(message) {
    const code = message.Text.match(/\b(\d{6})\b/)?.[1];
    expect(code, 'código de 6 dígitos no texto do e-mail').toBeTruthy();

    return code;
}

test.describe('verificação em duas etapas no login', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('liga no perfil → sai → senha → código do Mailpit → painel', async ({ page, request, browser }) => {
        test.setTimeout(120_000);

        const address = `e2e-2fa-${Date.now()}@example.com`;
        const seen = new Set();

        try {
            await test.step('conta nova com e-mail confirmado', async () => {
                await page.goto('/register');
                await page.getByLabel('Nome completo').fill('Pessoa 2FA E2E');
                await page.getByLabel('E-mail').fill(address);
                await page.getByLabel('Senha', { exact: true }).fill(loginPassword);
                await page.getByLabel('Confirme a senha').fill(loginPassword);
                await page.getByRole('button', { name: 'Criar conta' }).click();
                await expect(page).toHaveURL(/\/email\/verify$/);

                const message = await waitForMessage(request, address, 'Confirme seu e-mail', seen);
                const link = message.HTML.match(/href="([^"]*\/email\/verify\/[^"]+)"/)?.[1]?.replaceAll('&amp;', '&');
                expect(link).toBeTruthy();

                await page.goto(link);
                await expect(page).toHaveURL(/\/dashboard$/);
            });

            await test.step('sem senha de transação, o perfil orienta a criar', async () => {
                await page.goto('/profile');
                const card = page.locator('[data-two-factor-card]');
                await expect(card).toContainText('Verificação em duas etapas');
                await expect(card.locator('[data-two-factor-status]')).toHaveText('Desligada');
                await expect(card.locator('[data-two-factor-blocked]')).toContainText('senha de transação');
                await expect(card.getByRole('button', { name: 'Ligar verificação em duas etapas' })).toBeDisabled();

                await page.getByLabel('Nova senha de transação').fill(transactionPassword);
                await page.locator('#transactionPasswordConfirmation').fill(transactionPassword);
                await page.getByRole('button', { name: 'Alterar senha de transação' }).click();
                await expect(page.getByText('Senha de transação salva com sucesso.')).toBeVisible();
            });

            await test.step('liga o segundo fator: senha de transação + código por e-mail', async () => {
                const card = page.locator('[data-two-factor-card]');
                await card.getByRole('button', { name: 'Ligar verificação em duas etapas' }).click();

                const modal = page.getByRole('dialog');
                await expect(modal).toContainText('Confirmação de segurança');
                await modal.getByLabel('Senha de transação').fill(transactionPassword);
                await modal.getByRole('button', { name: 'Enviar código por e-mail' }).click();

                const message = await waitForMessage(request, address, 'Seu código de verificação', seen);
                await modal.getByLabel('Código de verificação').fill(codeFrom(message));
                await modal.getByRole('button', { name: 'Confirmar e executar' }).click();

                await expect(card.locator('[data-two-factor-status]')).toHaveText('Ligada');
                await expect(card).toContainText('Verificação em duas etapas ligada');
            });

            await test.step('sai e entra com a senha: a sessão fica no estado intermediário', async () => {
                await page.context().clearCookies();

                await page.goto('/login');
                await page.getByLabel('E-mail').fill(address);
                await page.getByLabel('Senha', { exact: true }).fill(loginPassword);
                await page.getByRole('button', { name: 'Entrar' }).click();

                await expect(page).toHaveURL(/\/two-factor-challenge$/);
                await expect(page.getByRole('heading', { level: 1 })).toHaveText('Verificação em duas etapas');
                await expect(page.locator('[data-two-factor-intro]')).toContainText(address);

                // O painel continua fechado: senha certa sem o código não é sessão.
                await page.goto('/dashboard');
                await expect(page).toHaveURL(/\/login$/);
                await page.goto('/two-factor-challenge');
                await expect(page).toHaveURL(/\/two-factor-challenge$/);
            });

            await test.step('código errado recusa; o código do Mailpit entra no painel', async () => {
                const message = await waitForMessage(request, address, 'Seu código de acesso', seen);
                const code = codeFrom(message);

                await page.getByLabel('Código de verificação').fill(code === '000000' ? '000001' : '000000');
                await page.getByRole('button', { name: 'Confirmar e entrar' }).click();
                await expect(page).toHaveURL(/\/two-factor-challenge$/);
                await expect(page.getByText('Código incorreto')).toBeVisible();

                await page.getByLabel('Código de verificação').fill(code);
                await page.getByRole('button', { name: 'Confirmar e entrar' }).click();

                await expect(page).toHaveURL(/\/dashboard$/);
                await expect(page.getByRole('heading', { level: 1 })).toContainText('Olá,');
            });
        } finally {
            // Limpeza: a conta sai do banco pelo /admin (super admin demo, que
            // não tem segundo fator) e as mensagens saem do Mailpit.
            await deleteAccountViaAdmin(browser, address);
            await deleteMailpitMessagesTo(request, address);
        }
    });
});
