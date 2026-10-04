import { expect } from '@playwright/test';
import { adminState } from './admin.js';

export { deleteMailpitMessagesTo } from './mailpit.js';

// =============================================================================
// Limpeza dos specs que CRIAM dados no ambiente de dev (contas novas e
// e-mails no Mailpit). Todo spec que cadastra uma conta chama as duas funções
// num `finally`, para não deixar resto no banco nem na caixa — inclusive
// quando o teste falha no meio.
//
// A conta sai pelo /admin (support/admin.js: o super admin demo, com a
// demonstração instalada; o admin do E2E, sem ela): é o caminho que um
// operador usaria, e passa pelas guardas do UserAdminGuard. A sessão é a que
// o global-setup gravou (`adminState`), sem login novo: com o segundo fator
// obrigatório, cada login pediria um código, e o código novo só sai depois do
// intervalo de reenvio.
// =============================================================================

/**
 * Abre uma tela do /admin; se a borda responder 429 (a suíte inteira divide o
 * limite de 300 requisições por minuto do mesmo IP, e a limpeza costuma cair
 * no fim da rodada), espera o que o `Retry-After` pede e tenta de novo. A
 * limpeza continua conferindo tudo: só não desiste por causa do limite.
 */
async function gotoAdmin(admin, url) {
    for (let tentativa = 0; tentativa < 4; tentativa++) {
        const response = await admin.goto(url, { waitUntil: 'networkidle' });

        if (response?.status() !== 429) {
            return;
        }

        const espera = Number(response.headers()['retry-after'] ?? 10);
        await admin.waitForTimeout((Number.isFinite(espera) ? espera + 1 : 11) * 1_000);
    }
}

/**
 * Exclui a conta `address` pelo /admin. Não faz nada se a conta não existe
 * (o teste pode ter falhado antes do cadastro).
 *
 * NÃO depende do idioma do painel. O idioma do /admin vem de `users.locale`
 * da conta logada, e o super admin demo é UMA conta só no banco de dev: o
 * teste do seletor de idioma (admin.spec.js) passa essa conta para inglês
 * por alguns segundos, em outro worker. Se a limpeza cair nessa janela, a
 * tela vem em inglês — procurar o botão "Excluir" ou o aviso "Usuário
 * excluído." pelo texto esperava para sempre e deixava a conta no banco.
 * Por isso a limpeza acha a ação pelo NOME da ação do Filament (`delete`),
 * o estado vazio pela classe e confirma a exclusão refazendo a busca — o
 * que também prova que a conta saiu, em vez de só confiar no aviso.
 */
export async function deleteAccountViaAdmin(browser, address) {
    const context = await browser.newContext({ storageState: adminState });
    const admin = await context.newPage();
    const search = `/admin/users?search=${encodeURIComponent(address)}`;
    const leftover = `limpeza E2E: a conta ${address} pode ter ficado no banco de dev`;

    try {
        await gotoAdmin(admin, search);
        const row = admin.getByRole('row').filter({ hasText: address });
        const empty = admin.locator('.fi-ta-empty-state');

        // Espera a busca terminar (achou a linha OU mostrou o estado vazio)
        // antes de concluir que não há o que apagar.
        await expect(row.or(empty), `${leftover} (a busca no /admin não terminou)`).toBeVisible({ timeout: 15_000 });

        if (await empty.isVisible()) {
            return;
        }

        await expect(row, `${leftover} (mais de uma linha na busca)`).toHaveCount(1);

        const deleteButton = row.locator(`button[wire\\:click^="mountAction('delete'"]`);
        await expect(deleteButton, `${leftover} (a ação de excluir não aparece na linha)`).toBeVisible({ timeout: 15_000 });
        await deleteButton.click();

        // O modal de confirmação do Filament não tem role=dialog; o botão de
        // confirmar é o submit do modal aberto.
        const confirm = admin.locator('.fi-modal-window').filter({ visible: true }).locator('button[type="submit"]');
        await expect(confirm, `${leftover} (o modal de confirmação não abriu)`).toBeVisible({ timeout: 15_000 });
        await confirm.click();
        await expect(row, `${leftover} (a linha não saiu depois de confirmar)`).toHaveCount(0, { timeout: 15_000 });

        // Prova no servidor: a mesma busca, recarregada, volta vazia.
        await gotoAdmin(admin, search);
        await expect(empty, `${leftover} (a busca ainda encontra a conta)`).toBeVisible({ timeout: 15_000 });
    } finally {
        await context.close();
    }
}

/**
 * Exclui VÁRIAS contas pelo /admin, na ordem que a regra do dono permitir.
 *
 * Pessoa dona de conta com outros membros não pode ser excluída (o /admin
 * recusa e a linha fica). Um teste de contas com membros que falhe no meio
 * pode deixar qualquer combinação — o dono com um membro, o membro que virou
 * dono com o antigo dono como admin... Por isso a limpeza tenta cada conta,
 * deixa para a rodada seguinte a que foi recusada (a exclusão de outra pode
 * liberá-la) e, no fim, confere pela busca que NENHUMA ficou. Contas de
 * empresa cujo dono sai por aqui vão junto (sem outros membros, a conta sai
 * com a pessoa). Independente de idioma, como deleteAccountViaAdmin.
 */
export async function deleteAccountsViaAdmin(browser, addresses) {
    const context = await browser.newContext({ storageState: adminState });
    const admin = await context.newPage();
    let pending = [...addresses];

    try {
        for (let round = 0; round <= addresses.length && pending.length > 0; round++) {
            const left = [];

            for (const address of pending) {
                if ((await tryDeleteRow(admin, address)) === 'refused') {
                    left.push(address);
                }
            }

            pending = left;
        }

        expect(pending, `limpeza E2E: contas que ficaram no banco de dev: ${pending.join(', ')}`).toEqual([]);
    } finally {
        await context.close();
    }
}

/**
 * Uma tentativa: 'absent' (não existe), 'deleted' ou 'refused' (a linha
 * continuou depois de confirmar — a guarda do dono recusou).
 */
async function tryDeleteRow(admin, address) {
    const search = `/admin/users?search=${encodeURIComponent(address)}`;

    await gotoAdmin(admin, search);
    const row = admin.getByRole('row').filter({ hasText: address });
    const empty = admin.locator('.fi-ta-empty-state');

    await expect(row.or(empty), `limpeza E2E: a busca por ${address} no /admin não terminou`).toBeVisible({ timeout: 15_000 });

    if (await empty.isVisible()) {
        return 'absent';
    }

    await row.locator(`button[wire\\:click^="mountAction('delete'"]`).click();
    const confirm = admin.locator('.fi-modal-window').filter({ visible: true }).locator('button[type="submit"]');
    await expect(confirm).toBeVisible({ timeout: 15_000 });
    await confirm.click();

    try {
        await expect(row).toHaveCount(0, { timeout: 5_000 });
    } catch {
        return 'refused';
    }

    await gotoAdmin(admin, search);

    return (await empty.isVisible()) ? 'deleted' : 'refused';
}
