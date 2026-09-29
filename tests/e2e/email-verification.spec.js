import { test, expect } from '@playwright/test';
import { deleteAccountViaAdmin, deleteMailpitMessagesTo } from './support/cleanup.js';

// =============================================================================
// E2E da verificação de e-mail no cadastro, de ponta a ponta e sem atalho:
// cadastro → tela de aviso (painel fechado) → e-mail REAL entregue pelo worker
// da fila ao Mailpit → clicar no link → painel liberado.
//
// Pré-requisitos: stack de dev no ar com o worker `queue` rodando e o Mailpit
// (docker compose sobe os dois). A caixa do Mailpit é lida pela API dele
// (E2E_MAILPIT_URL, padrão http://localhost:18025).
//
// Cada rodada cadastra uma conta NOVA (e-mail com carimbo de tempo), porque o
// cadastro não aceita e-mail repetido. No fim — passando ou falhando — o
// próprio teste apaga o que criou: a conta (pelo /admin, com o super admin
// demo, por isso o modo demo precisa estar ligado) e as mensagens do Mailpit.
// Ver tests/e2e/support/cleanup.js.
// =============================================================================

const mailpit = process.env.E2E_MAILPIT_URL ?? 'http://localhost:18025';

/**
 * Espera o e-mail chegar ao Mailpit (a fila entrega em segundos) e devolve o
 * ID da mensagem.
 */
async function waitForMessageTo(request, address) {
    let id = null;

    await expect
        .poll(
            async () => {
                const response = await request.get(`${mailpit}/api/v1/search`, {
                    params: { query: `to:"${address}"` },
                });
                const body = await response.json();
                id = body.messages?.[0]?.ID ?? null;

                return id;
            },
            { timeout: 20_000, intervals: [500, 1_000] },
        )
        .not.toBeNull();

    return id;
}

test.describe('verificação de e-mail no cadastro', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('cadastro → aviso → e-mail no Mailpit → link → painel liberado', async ({ page, request, browser }) => {
        test.setTimeout(60_000);

        const address = `e2e-verificacao-${Date.now()}@example.com`;

        try {
            await page.goto('/register');
            await page.getByLabel('Nome completo').fill('Pessoa Verificação E2E');
            await page.getByLabel('E-mail').fill(address);
            await page.getByLabel('Senha', { exact: true }).fill('SenhaForte123');
            await page.getByLabel('Confirme a senha').fill('SenhaForte123');
            await page.getByRole('button', { name: 'Criar conta' }).click();

            // Tela de aviso, no layout do site, dizendo para onde o e-mail foi.
            await expect(page).toHaveURL(/\/email\/verify$/);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirme seu e-mail');
            await expect(page.locator('[data-verification-intro]')).toContainText(address);
            await expect(page.getByRole('button', { name: 'Reenviar e-mail' })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Sair' }).last()).toBeVisible();

            // O painel continua fechado enquanto o e-mail não é confirmado.
            await page.goto('/dashboard');
            await expect(page).toHaveURL(/\/email\/verify$/);
            await page.goto('/api-keys');
            await expect(page).toHaveURL(/\/email\/verify$/);

            // Reenviar logo em seguida esbarra no intervalo mínimo — com o motivo na tela.
            await page.getByRole('button', { name: 'Reenviar e-mail' }).click();
            await expect(page.locator('[data-verification-error]')).toContainText('Aguarde');

            // O e-mail de verdade, entregue pelo worker ao Mailpit.
            const id = await waitForMessageTo(request, address);
            const message = await (await request.get(`${mailpit}/api/v1/message/${id}`)).json();

            expect(message.Subject).toContain('Confirme seu e-mail');

            const link = message.HTML.match(/href="([^"]*\/email\/verify\/[^"]+)"/)?.[1]?.replaceAll('&amp;', '&');
            expect(link, 'link de verificação no HTML do e-mail').toBeTruthy();
            expect(link).toContain('signature=');
            expect(link).toContain('expires=');

            // O texto puro traz o mesmo link por extenso.
            expect(message.Text).toContain('/email/verify/');

            // Clicar no link (mesma sessão) libera o painel e devolve à última
            // página que a pessoa tentou abrir (/api-keys), pelo SafeRedirect.
            await page.goto(link);
            await expect(page).toHaveURL(/\/api-keys$/);
            await expect(page.getByRole('heading', { level: 1 })).toContainText('Chaves de API');
            await expect(page.getByRole('status').filter({ hasText: 'E-mail confirmado' })).toBeVisible();

            await page.goto('/dashboard');
            await expect(page).toHaveURL(/\/dashboard$/);
            await expect(page.getByRole('heading', { level: 1 })).toContainText('Olá,');
        } finally {
            await deleteAccountViaAdmin(browser, address);
            await deleteMailpitMessagesTo(request, address);
        }
    });
});
