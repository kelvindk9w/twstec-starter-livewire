import { test, expect } from '@playwright/test';
import { deleteAccountViaAdmin, deleteMailpitMessagesTo } from './support/cleanup.js';
import { livewireReady, newAddress, newPerson } from './support/flows.js';
import { codeFrom, hasCode, waitForMessage } from './support/mailpit.js';
import { baseUrl, installed } from './support/project-env.js';
import { waitForRateWindow } from './support/rate-window.js';

// =============================================================================
// E2E das CAIXAS DE SELEÇÃO da tela de chaves de API (escopos e projetos): o
// que a pessoa marca no NAVEGADOR é exatamente o que a chave pode. A prova é
// por fora, com a própria chave criada, na API v1:
//
// - escopos granulares (`projects:read` e `projects:update` marcados): ler
//   projetos responde 200; ler chaves (escopo não marcado) responde 403;
// - projetos marcados (o projeto A): a chave só enxerga o A, nunca o B — uma
//   chave "da conta toda" quando a pessoa restringiu é uma credencial mais
//   ampla do que a pedida;
// - a edição dos projetos da chave (o modal) grava o que foi marcado.
//
// O teste do Livewire (Livewire::test) não roda o JavaScript: só o navegador
// prova que a caixa sincroniza com a lista do componente.
//
// O spec conversa muito com o servidor (cadastro, dois projetos, duas chaves
// com a confirmação de segurança, a API): começa na TERCEIRA janela de minuto
// da suíte (a segunda é do approvals.spec.js), para não deixar os outros
// arquivos sem orçamento no teto por IP da borda.
//
// Pessoa NOVA (sai no fim pelo /admin, com a conta, os projetos e as chaves)
// e as mensagens dela saem do Mailpit.
// =============================================================================

const loginPassword = 'SenhaForte123';
const transactionPassword = 'Transacao9Chaves';

test.describe('chaves de API: escopos e projetos marcados', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test.skip(!installed('accounts'), 'módulo de contas não instalado');

    test('o que é marcado é o que a chave pode — escopos, projetos e a edição dos projetos', async ({ browser, request }) => {
        test.setTimeout(420_000);

        const address = newAddress('escopos');
        const seen = new Set();
        const stamp = Date.now();
        const projectA = `Loja A ${stamp}`;
        const projectB = `Loja B ${stamp}`;
        const context = await browser.newContext();
        const page = await context.newPage();

        await waitForRateWindow(page, 3);

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

        const createKey = async (name, { scopes = null, projects = [] }) => {
            await page.goto('/api-keys');
            await livewireReady(page);
            await page.getByRole('button', { name: 'Nova chave' }).first().click();
            await page.getByLabel('Nome', { exact: true }).fill(name);

            if (scopes !== null) {
                await page.getByText('Todas as permissões').click();
                await expect(page.getByText('Selecione somente o que a integração precisa.')).toBeVisible();

                for (const scope of scopes) {
                    await page.locator(`[value="${scope}"]`).first().click();
                }
            }

            for (const project of projects) {
                await page.locator('form').getByLabel(project, { exact: true }).check();
            }

            await page.getByRole('button', { name: 'Criar', exact: true }).click();
            await confirmSensitive();

            const publicKey = (await page.getByTestId('revealed-public-key').textContent()).trim();
            const secret = (await page.getByTestId('revealed-secret-key').textContent()).trim();
            await page.getByRole('button', { name: 'Já guardei a chave com segurança' }).click();

            return { 'X-Api-Key': publicKey, Authorization: `Bearer ${secret}`, Accept: 'application/json' };
        };

        const projectNames = async (headers) => {
            const response = await request.get(`${baseUrl}/api/v1/projects`, { headers });
            expect(response.status(), 'GET /api/v1/projects com a chave').toBe(200);

            return ((await response.json()).data ?? []).map((project) => project.name).sort();
        };

        try {
            await test.step('pessoa nova, com senha de transação e dois projetos', async () => {
                const { twoFactorRequired } = await newPerson(page, request, browser, {
                    address,
                    name: 'Pessoa E2E Escopos',
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

                for (const name of [projectA, projectB]) {
                    await page.goto('/projects');
                    await livewireReady(page);
                    await page.getByRole('button', { name: 'Novo projeto' }).first().click();
                    await page.getByLabel('Nome', { exact: true }).fill(name);
                    await page.getByRole('button', { name: 'Criar', exact: true }).click();
                    await expect(page.getByText(name)).toBeVisible();
                }
            });

            await test.step('escopos marcados: só ler e editar projetos — e restrita ao projeto A', async () => {
                const headers = await createKey('Escopos marcados', { scopes: ['projects:read', 'projects:update'], projects: [projectA] });

                // Escopo marcado: ler projetos. Projeto marcado: só o A.
                expect(await projectNames(headers)).toEqual([projectA]);

                // Escopo NÃO marcado: ler chaves é recusado.
                const keys = await request.get(`${baseUrl}/api/v1/api-keys`, { headers });
                expect(keys.status(), 'GET /api/v1/api-keys sem o escopo api-keys:read').toBe(403);
            });

            await test.step('todas as permissões, restrita ao projeto B; a edição acrescenta o A', async () => {
                // A segunda confirmação de segurança da mesma pessoa espera o
                // intervalo de reenvio do código (60 s, regra do kit-auth).
                await page.waitForTimeout(61_000);

                const headers = await createKey('Projetos marcados', { projects: [projectB] });

                expect(await projectNames(headers)).toEqual([projectB]);

                // O modal dos projetos da chave: marca o A também.
                await page.goto('/api-keys');
                await livewireReady(page);
                const row = page.getByRole('row').filter({ hasText: 'Projetos marcados' });
                await row.getByRole('button', { name: 'Projetos', exact: true }).click();
                const modal = page.locator('#edit-key-projects');
                await expect(modal).toBeVisible();
                await modal.getByLabel(projectA, { exact: true }).check();
                await modal.getByRole('button', { name: 'Salvar' }).click();
                await expect(page.getByText('Vínculos de projetos atualizados.')).toBeVisible();

                expect(await projectNames(headers)).toEqual([projectA, projectB].sort());
            });
        } finally {
            await context.close();
            await deleteAccountViaAdmin(browser, address);
            await deleteMailpitMessagesTo(request, address);
        }
    });
});
