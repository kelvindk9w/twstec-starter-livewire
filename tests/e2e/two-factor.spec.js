import { test, expect } from '@playwright/test';
import { deleteAccountViaAdmin, deleteMailpitMessagesTo } from './support/cleanup.js';
import { newAddress, newPerson, submitLogin } from './support/flows.js';
import { codeFrom, hasCode, waitForMessage } from './support/mailpit.js';

// =============================================================================
// E2E da verificação em duas etapas no login, de ponta a ponta e sem atalho,
// com os códigos REAIS entregues pelo worker ao Mailpit. Dois caminhos até o
// segundo fator ligado, conforme a instalação:
//
// - opcional (AUTH_TWO_FACTOR_REQUIRED=none, ou `admins` para quem não é
//   admin): conta nova → sem senha de transação o perfil orienta a criar →
//   senha de transação → LIGA no perfil (senha de transação + código);
// - obrigatório (`all`): a conta nova já passou pela configuração (senha de
//   transação → código) ao entrar; o perfil mostra o selo "obrigatória" e o
//   botão de desligar travado, com o motivo.
//
// Comum aos dois: sai → entra com a senha → tela do código (sessão ainda NÃO
// autenticada) → código errado recusa → o código do Mailpit entra no painel.
//
// A conta nova vem do cadastro público, ou do /admin com ele fechado
// (support/flows.js). No fim, o próprio teste apaga o que criou: a conta
// (pelo /admin) e as mensagens do Mailpit.
//
// Por que uma conta NOVA e não o e2e@example.com: ligar o segundo fator na
// conta compartilhada mudaria o login do global-setup e dos outros specs.
// =============================================================================

const loginPassword = 'SenhaForte123';
const transactionPassword = 'Transacao2fa9';

test.describe('verificação em duas etapas no login', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('liga (perfil ou configuração obrigatória) → sai → senha → código do Mailpit → painel', async ({ page, request, browser }) => {
        test.setTimeout(120_000);

        const address = newAddress('2fa');
        const seen = new Set();

        try {
            let required = false;

            await test.step('conta nova, já no painel', async () => {
                ({ twoFactorRequired: required } = await newPerson(page, request, browser, {
                    address,
                    name: 'Pessoa 2FA E2E',
                    password: loginPassword,
                    transactionPassword,
                    seen,
                }));
            });

            if (required) {
                await test.step('obrigatório: o perfil mostra o selo e não deixa desligar, com o motivo', async () => {
                    await page.goto('/profile');
                    const card = page.locator('[data-two-factor-card]');
                    await expect(card.locator('[data-two-factor-status]')).toHaveText('Ligada');
                    await expect(card.locator('[data-two-factor-required]')).toHaveText('Obrigatória nesta instalação');
                    await expect(card.locator('[data-two-factor-blocked]')).toContainText('obrigatória');
                    await expect(card.getByRole('button', { name: 'Desligar verificação em duas etapas' })).toBeDisabled();
                });
            } else {
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

                    const message = await waitForMessage(request, address, seen, hasCode);
                    expect(message.Subject).toContain('Seu código de verificação');
                    await modal.getByLabel('Código de verificação').fill(codeFrom(message));
                    await modal.getByRole('button', { name: 'Confirmar e executar' }).click();

                    await expect(card.locator('[data-two-factor-status]')).toHaveText('Ligada');
                    await expect(card).toContainText('Verificação em duas etapas ligada');
                });
            }

            await test.step('sai e entra com a senha: a sessão fica no estado intermediário', async () => {
                await page.context().clearCookies();

                await submitLogin(page, address, loginPassword);

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
                const message = await waitForMessage(request, address, seen, hasCode);
                expect(message.Subject).toContain('Seu código de acesso');
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
            // Limpeza: a conta sai do banco pelo /admin e as mensagens saem do
            // Mailpit.
            await deleteAccountViaAdmin(browser, address);
            await deleteMailpitMessagesTo(request, address);
        }
    });
});
