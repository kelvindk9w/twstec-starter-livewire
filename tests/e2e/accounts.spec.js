import { test, expect } from '@playwright/test';
import { deleteAccountsViaAdmin, deleteMailpitMessagesTo } from './support/cleanup.js';

// =============================================================================
// E2E das CONTAS COM MEMBROS, de ponta a ponta e sem atalho:
//
// dona nova (cadastro + e-mail confirmado + senha de transação) → cria a conta
// de empresa e um projeto nela → convida por e-mail → o convite REAL chega ao
// Mailpit → a pessoa convidada (sem conta) abre o link, cria o acesso e entra
// na conta → vê o projeto da conta → troca para a conta pessoal (o projeto
// some) → a dona transfere a propriedade (senha de transação + código do
// Mailpit) → a nova dona remove a antiga (que virou admin).
//
// Duas sessões de navegador separadas (dona e convidada) — nenhuma usa a
// sessão compartilhada do e2e.json, para não trocar a conta de outro teste.
// Limpeza no `finally`, passando ou falhando: as duas pessoas pelo /admin (a
// conta de empresa sai junto com a última dona) e as mensagens do Mailpit.
// Ver tests/e2e/support/cleanup.js.
// =============================================================================

const mailpit = process.env.E2E_MAILPIT_URL ?? 'http://localhost:18025';
const loginPassword = 'SenhaForte123';
const transactionPassword = 'Transacao9Contas';

async function waitForMessage(request, address, subject, seen) {
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
            { timeout: 25_000, intervals: [500, 1_000] },
        )
        .not.toBeNull();

    seen.add(found.ID);

    return (await request.get(`${mailpit}/api/v1/message/${found.ID}`)).json();
}

/** Espera o Livewire da página iniciar (clicar antes não chega ao servidor). */
async function livewireReady(page) {
    await page.waitForFunction(() => window.Livewire !== undefined && document.querySelector('[wire\\:id]') !== null, null, {
        timeout: 15_000,
    });
}

async function switchTo(page, accountName) {
    const switcher = page.locator('main [data-account-switcher]');
    await switcher.locator('button').first().click();
    await switcher.locator('[data-account-option]').filter({ hasText: accountName }).click();
    await expect(page.locator('main [data-current-account]')).toHaveText(accountName);
}

test.describe('contas com membros', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('convida → e-mail → aceita criando conta → projetos da conta → troca de conta → transfere → remove', async ({
        browser,
        request,
    }) => {
        test.setTimeout(180_000);

        const stamp = Date.now();
        const owner = `e2e-dona-${stamp}@example.com`;
        const member = `e2e-convidada-${stamp}@example.com`;
        const company = `e2e-empresa-${stamp}`;
        const project = `Projeto e2e ${stamp}`;
        const seen = new Set();

        // Celular: o seletor mora no topo do conteúdo (a coluna some) — a
        // suíte roda no desktop e usa o seletor do <main>, visível em ambos.
        const ownerContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const memberContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const ownerPage = await ownerContext.newPage();
        const memberPage = await memberContext.newPage();

        try {
            await test.step('dona nova: cadastro, e-mail confirmado e senha de transação', async () => {
                await ownerPage.goto('/register');
                await ownerPage.getByLabel('Nome completo').fill('Dona E2E Contas');
                await ownerPage.getByLabel('E-mail').fill(owner);
                await ownerPage.getByLabel('Senha', { exact: true }).fill(loginPassword);
                await ownerPage.getByLabel('Confirme a senha').fill(loginPassword);
                await ownerPage.getByRole('button', { name: 'Criar conta' }).click();
                await expect(ownerPage).toHaveURL(/\/email\/verify$/);

                const message = await waitForMessage(request, owner, 'Confirme seu e-mail', seen);
                const link = message.HTML.match(/href="([^"]*\/email\/verify\/[^"]+)"/)?.[1]?.replaceAll('&amp;', '&');
                expect(link).toBeTruthy();
                await ownerPage.goto(link);
                await expect(ownerPage).toHaveURL(/\/dashboard$/);

                await ownerPage.goto('/settings/transaction-password');
                await ownerPage.getByLabel('Nova senha de transação').fill(transactionPassword);
                await ownerPage.getByLabel('Confirme a senha').fill(transactionPassword);
                await ownerPage.getByRole('button', { name: 'Salvar' }).click();
                await expect(ownerPage.getByText('Senha de transação salva com sucesso.')).toBeVisible();
            });

            await test.step('cria a conta de empresa (vira a atual) e um projeto nela', async () => {
                await ownerPage.goto('/accounts/create');
                await livewireReady(ownerPage);
                await ownerPage.getByLabel('Nome da conta').fill(company);
                await ownerPage.getByRole('button', { name: 'Criar conta' }).click();

                await expect(ownerPage).toHaveURL(/\/account$/);
                await expect(ownerPage.locator('[data-account-name]')).toHaveText(company);
                await expect(ownerPage.locator('main [data-current-account]')).toHaveText(company);
                await expect(ownerPage.locator('[data-my-role]')).toContainText('Dono');

                await ownerPage.goto('/projects');
                await livewireReady(ownerPage);
                await ownerPage.getByRole('button', { name: 'Novo projeto' }).first().click();
                await ownerPage.getByPlaceholder('Ex.: Loja Virtual').fill(project);
                await ownerPage.getByRole('button', { name: 'Criar', exact: true }).click();
                await expect(ownerPage.getByText(project)).toBeVisible();
            });

            await test.step('convida por e-mail (papel membro)', async () => {
                await ownerPage.goto('/account');
                await livewireReady(ownerPage);
                const form = ownerPage.locator('[data-invite-form]');
                await form.getByLabel('E-mail').fill(member);
                await form.getByRole('button', { name: 'Enviar convite' }).click();

                await expect(ownerPage.locator('[data-account-status]')).toContainText(member);
                await expect(ownerPage.locator(`[data-invitation="${member}"]`)).toBeVisible();
            });

            let inviteLink;

            await test.step('o convite chega ao Mailpit com o link da tela de aceite', async () => {
                const message = await waitForMessage(request, member, company, seen);

                expect(message.Subject).toContain('Convite para a conta');
                inviteLink = message.HTML.match(/href="([^"]*\/invitations\/[0-9a-f]{64})"/)?.[1];
                expect(inviteLink, 'link do convite no HTML do e-mail').toBeTruthy();
                expect(message.Text).toContain('/invitations/');
                expect(message.HTML).toContain(company);
            });

            await test.step('a convidada (sem conta) abre o link, cria o acesso e entra na conta', async () => {
                await memberPage.goto(inviteLink);
                const card = memberPage.locator('[data-invitation-mode="register"]');
                await expect(card).toBeVisible();
                await expect(card).toContainText(company);
                await expect(card.locator('[data-invitation-email]')).toHaveText(member);

                await memberPage.getByLabel('Nome completo').fill('Convidada E2E Contas');
                await memberPage.getByLabel('Senha', { exact: true }).fill(loginPassword);
                await memberPage.getByLabel('Confirme a senha').fill(loginPassword);
                await memberPage.getByRole('button', { name: 'Criar conta e aceitar' }).click();

                // Sem passar pela verificação de e-mail: o link provou o e-mail.
                await expect(memberPage).toHaveURL(/\/dashboard$/);
                await expect(memberPage.locator('main [data-current-account]')).toHaveText(company);
            });

            await test.step('vê os projetos da conta; trocando para a conta pessoal, não vê', async () => {
                await memberPage.goto('/projects');
                await expect(memberPage.getByText(project)).toBeVisible();

                await switchTo(memberPage, 'Convidada E2E Contas');
                await expect(memberPage).toHaveURL(/\/projects$/);
                await expect(memberPage.getByText(project)).toHaveCount(0);

                await switchTo(memberPage, company);
                await expect(memberPage.getByText(project)).toBeVisible();
            });

            await test.step('a dona transfere a propriedade: senha de transação + código do e-mail', async () => {
                await ownerPage.goto('/account');
                await livewireReady(ownerPage);
                await expect(ownerPage.locator(`[data-member="${member}"]`)).toBeVisible();

                const transfer = ownerPage.locator('[data-transfer]');
                await transfer.getByLabel('Novo dono').selectOption({ label: `Convidada E2E Contas — ${member}` });
                await transfer.getByRole('button', { name: 'Transferir' }).click();

                const modal = ownerPage.getByRole('dialog');
                await expect(modal).toContainText('Confirmação de segurança');
                await expect(modal).toContainText('Convidada E2E Contas');

                // Senha errada recusa, e nada muda.
                await modal.getByLabel('Senha de transação').fill('errada-123');
                await modal.getByRole('button', { name: 'Enviar código por e-mail' }).click();
                await expect(modal).toContainText('incorreta');

                await modal.getByLabel('Senha de transação').fill(transactionPassword);
                await modal.getByRole('button', { name: 'Enviar código por e-mail' }).click();

                const message = await waitForMessage(request, owner, 'Seu código de verificação', seen);
                const code = message.Text.match(/\b(\d{6})\b/)?.[1];
                expect(code).toBeTruthy();
                await modal.getByLabel('Código de verificação').fill(code);
                await modal.getByRole('button', { name: 'Confirmar e executar' }).click();

                await expect(ownerPage.locator('[data-account-status]')).toContainText('Propriedade transferida');
                await expect(ownerPage.locator('[data-my-role]')).toContainText('Administrador');
                await expect(ownerPage.locator(`[data-member="${member}"] [data-member-role]`)).toHaveText('Dono');
            });

            await test.step('a nova dona remove a antiga (agora admin)', async () => {
                await memberPage.goto('/account');
                await livewireReady(memberPage);
                await expect(memberPage.locator('[data-my-role]')).toContainText('Dono');

                const row = memberPage.locator(`[data-member="${owner}"]`);
                await row.locator('[data-action="remove"]').click();
                await memberPage.locator('[data-confirm="remove"]').click();

                await expect(memberPage.locator('[data-account-status]')).toContainText('Membro removido');
                await expect(memberPage.locator(`[data-member="${owner}"]`)).toHaveCount(0);

                // Quem foi removido volta para a conta pessoal na próxima tela.
                await ownerPage.goto('/projects');
                await expect(ownerPage.locator('main [data-current-account]')).toHaveText('Dona E2E Contas');
                await expect(ownerPage.getByText(project)).toHaveCount(0);
            });
        } finally {
            await ownerContext.close();
            await memberContext.close();
            await deleteAccountsViaAdmin(browser, [member, owner]);
            await deleteMailpitMessagesTo(request, owner);
            await deleteMailpitMessagesTo(request, member);
        }
    });
});
