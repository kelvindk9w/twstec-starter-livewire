import { test, expect } from '@playwright/test';
import { deleteAccountViaAdmin, deleteMailpitMessagesTo } from './support/cleanup.js';
import { livewireReady, newAddress, newPerson } from './support/flows.js';
import { codeFrom, hasCode, waitForMessage } from './support/mailpit.js';
import { receiverAllowed, receiverHost, startReceiver, verifyWebhookSignature } from './support/webhook-receiver.js';

// =============================================================================
// E2E dos WEBHOOKS (twstec/kit-webhooks) no starter Livewire: a tela inteira
// num fluxo só — criar o endpoint com a confirmação de segurança (código REAL
// do Mailpit), o segredo mostrado UMA vez, o destino na rede interna recusado
// antes de pedir o código, o evento de teste chegando ASSINADO a um receptor
// local (pelo worker da fila do projeto), o log de entregas, o REENVIO,
// desativar/reativar e excluir. Nada sai da máquina: o receptor é um servidor
// HTTP deste processo.
//
// Cada recarga da página conta no limite de borda por IP (300/min — a
// suíte inteira divide o mesmo): as esperas que recarregam vão de 1,5 s em
// 1,5 s ou mais, nunca no intervalo padrão do Playwright.
//
// Pessoa NOVA (sai no fim pelo /admin, com a conta pessoal e tudo que é dela
// — inclusive os webhooks) e as mensagens dela saem do Mailpit.
// =============================================================================

const loginPassword = 'SenhaForte123';
const transactionPassword = 'Transacao9Hooks';

test.describe('webhooks', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test.skip(!receiverAllowed, 'o .env de desenvolvimento não libera o receptor local (WEBHOOKS_REQUIRE_HTTPS=false e WEBHOOKS_ALLOWED_PRIVATE_NETWORKS) — ver docs/testes.md');
    test.skip(receiverHost() === null, 'sem o IP do host do Docker: defina E2E_WEBHOOK_RECEIVER_HOST');

    test('cria (ação sensível) → segredo uma vez → SSRF recusado → teste assinado chega → log → reenvia → desativa → exclui', async ({ browser, request }) => {
        test.setTimeout(180_000);

        const address = newAddress('webhooks');
        const seen = new Set();
        const receiver = await startReceiver();
        const url = `http://${receiverHost()}:${receiver.port}/hooks/pedidos`;
        const context = await browser.newContext();
        const page = await context.newPage();
        let secret = '';

        const confirmSensitive = async () => {
            const modal = page.locator('#sensitive-action');
            await expect(modal).toBeVisible();
            await modal.getByLabel('Senha de transação').fill(transactionPassword);
            await modal.getByRole('button', { name: 'Enviar código por e-mail' }).click();
            const code = codeFrom(await waitForMessage(request, address, seen, hasCode));
            await modal.getByLabel('Código de verificação').fill(code);
            await modal.getByRole('button', { name: 'Confirmar e executar' }).click();
            await expect(modal).toHaveCount(0);
        };

        try {
            await test.step('pessoa nova no painel, com senha de transação', async () => {
                const { twoFactorRequired } = await newPerson(page, request, browser, {
                    address,
                    name: 'Pessoa E2E Webhooks',
                    password: loginPassword,
                    transactionPassword,
                    seen,
                });

                if (!twoFactorRequired) {
                    await page.goto('/settings/transaction-password');
                    await page.getByLabel('Nova senha de transação').fill(transactionPassword);
                    await page.getByLabel('Confirme a senha').fill(transactionPassword);
                    await page.getByRole('button', { name: 'Salvar' }).click();
                    await expect(page.getByText('Senha de transação salva com sucesso.')).toBeVisible();
                }
            });

            await test.step('o menu leva à tela; destino na rede interna é recusado ANTES de pedir o código', async () => {
                await page.goto('/dashboard');
                await page.getByRole('link', { name: 'Webhooks', exact: true }).first().click();
                await expect(page).toHaveURL(/\/webhooks$/);
                await livewireReady(page);
                await expect(page.getByRole('heading', { level: 1 })).toHaveText('Webhooks');

                await page.getByTestId('webhook-new').click();
                await page.locator('input[name="webhookName"]').fill('Metadados');
                await page.locator('input[name="webhookUrl"]').fill('https://169.254.169.254/latest/meta-data/');
                await page.locator('[data-webhook-event="*"]').check();
                await page.getByTestId('webhook-save').click();

                await expect(page.getByText('Este endereço é de serviço interno de nuvem e não é permitido.')).toBeVisible();
                await expect(page.locator('#sensitive-action')).toHaveCount(0);
            });

            await test.step('cria o endpoint com a confirmação de segurança e vê o segredo UMA vez', async () => {
                await page.locator('input[name="webhookName"]').fill('Receptor E2E');
                await page.locator('input[name="webhookUrl"]').fill(url);
                await page.getByTestId('webhook-save').click();
                await confirmSensitive();

                const panel = page.getByTestId('webhook-secret-panel');
                await expect(panel).toBeVisible();
                secret = (await page.getByTestId('webhook-secret').textContent()).trim();
                expect(secret).toMatch(/^whsk_[A-Za-z0-9_-]{43}$/);

                await page.getByTestId('webhook-secret-done').click();
                await expect(panel).toHaveCount(0);

                // Recarregar não traz o segredo de volta.
                await page.reload();
                await expect(page.getByTestId('webhook-endpoint')).toContainText('Receptor E2E');
                expect(await page.content()).not.toContain(secret);
            });

            await test.step('enviar teste: o evento chega ASSINADO ao receptor e aparece no log', async () => {
                await livewireReady(page);
                await page.getByTestId('webhook-send-test').click();
                await expect(page.getByText('Evento de teste enviado para a fila.')).toBeVisible();

                await expect.poll(() => receiver.state.requests.length, { timeout: 30_000 }).toBe(1);
                const delivered = receiver.state.requests[0];
                const body = JSON.parse(delivered.body);

                expect(delivered.method).toBe('POST');
                expect(delivered.url).toBe('/hooks/pedidos');
                expect(body.type).toBe('webhook.ping');
                expect(delivered.headers['x-webhook-id']).toBe(body.id);
                expect(delivered.headers['x-correlation-id']).toBeUndefined();
                expect(verifyWebhookSignature(delivered.body, delivered.headers['x-webhook-signature'], secret)).toBe(true);
                expect(verifyWebhookSignature(delivered.body, delivered.headers['x-webhook-signature'], 'whsk_outro')).toBe(false);

                await expect
                    .poll(async () => {
                        await page.reload();

                        return page.getByTestId('webhook-delivery').first().getAttribute('data-status');
                    }, { timeout: 30_000, intervals: [1_500, 3_000] })
                    .toBe('succeeded');
            });

            await test.step('reenviar: o mesmo evento (mesmo id) sai de novo — e a falha do receptor fica no log', async () => {
                receiver.state.status = 500;
                await livewireReady(page);
                await page.getByTestId('webhook-resend').first().click();
                await expect(page.getByText('Reenvio solicitado.')).toBeVisible();

                await expect.poll(() => receiver.state.requests.length, { timeout: 30_000 }).toBe(2);
                expect(JSON.parse(receiver.state.requests[1].body).id).toBe(JSON.parse(receiver.state.requests[0].body).id);

                await expect
                    .poll(async () => {
                        await page.reload();

                        return page.getByTestId('webhook-delivery').first().getAttribute('data-status');
                    }, { timeout: 30_000, intervals: [1_500, 3_000] })
                    .toBe('failed');

                const delivery = page.getByTestId('webhook-delivery').first();
                await delivery.locator('summary').click();
                await expect(delivery.getByTestId('webhook-attempt')).toHaveCount(2);
                await expect(delivery.getByTestId('webhook-attempt').nth(1)).toContainText('reenvio manual');
                await expect(delivery.getByTestId('webhook-attempt').nth(1)).toContainText('HTTP 500');
            });

            await test.step('desativar e reativar; excluir', async () => {
                await livewireReady(page);
                const row = page.getByTestId('webhook-endpoint');

                await row.getByRole('button', { name: 'Mais ações' }).click();
                await page.getByRole('menuitem', { name: 'Desativar' }).click();
                await expect(row.getByTestId('webhook-status')).toHaveText('Desativado');

                await row.getByRole('button', { name: 'Mais ações' }).click();
                await page.getByRole('menuitem', { name: 'Reativar' }).click();
                await expect(row.getByTestId('webhook-status')).toHaveText('Ativo');

                await row.getByRole('button', { name: 'Mais ações' }).click();
                await page.getByRole('menuitem', { name: 'Excluir' }).click();
                await page.getByTestId('webhook-delete-confirm').click();
                await expect(page.getByTestId('webhook-endpoint')).toHaveCount(0);
                await expect(page.getByText('Nenhum endpoint cadastrado.')).toBeVisible();
            });
        } finally {
            await context.close();
            await receiver.close();
            await deleteAccountViaAdmin(browser, address);
            await deleteMailpitMessagesTo(request, address);
        }
    });
});
