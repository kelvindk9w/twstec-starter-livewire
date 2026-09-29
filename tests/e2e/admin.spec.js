import { test, expect } from '@playwright/test';

// =============================================================================
// E2E do super admin (/admin — Filament), um teste por assunto:
// trilha de requisições (navegação WEB e tentativa de ataque OBSERVADA),
// vitrine de segurança (submissões), produtos (paginação na URL), usuários
// (alternador tabela/cards), tela de Auditoria (ação recusada e executada) e
// seletor de idioma.
//
// Login: a sessão do SUPER ADMIN DEMO é criada UMA vez no global-setup (botão
// "Entrar" com as credenciais pré-preenchidas — o setup falha se isso
// quebrar) e reaproveitada aqui por storageState. O login do Filament tem
// limite agressivo; um login por teste estouraria o limite.
//
// Os testes deste arquivo rodam em sequência no mesmo worker (o projeto não
// liga fullyParallel), mas não dependem uns dos outros: cada um abre as suas
// telas. Em sequência importa porque o de idioma troca o idioma da conta demo
// por um instante — em paralelo, os rótulos em português dos outros sumiriam.
//
// Esperas: por elementos concretos da tela e pela inicialização do Livewire
// (antes dela, um clique em botão do Filament não chega ao servidor), nunca
// por "rede ociosa" — que a cada poll/prefetch do painel pode demorar.
//
// Pré-requisito: stack de dev no ar com seeders demo (DatabaseSeeder roda
// DemoUser/DemoAdmin/Product/FormSubmission quando ui.demo_login.enabled).
// =============================================================================

test.use({ storageState: 'tests/e2e/.auth/admin.json' });

/**
 * Abre uma tela do /admin e espera o Livewire ter inicializado os componentes.
 */
async function openAdmin(page, path) {
    await page.goto(path);
    await page.waitForFunction(() => {
        const roots = document.querySelectorAll('[wire\\:id]');

        return roots.length > 0 && Array.from(roots).every((el) => el.__livewire !== undefined);
    }, null, { timeout: 15000 });
}

test('trilha de requisições: navegação WEB e tentativa de ataque observada', async ({ page, browser }) => {
    // Navegação web ANÔNIMA (outro contexto, sem a sessão do admin): vira
    // request log sem tenant.
    const anonimo = await browser.newPage({ storageState: { cookies: [], origins: [] } });

    try {
        await anonimo.goto('/');
        await anonimo.goto('/login');

        // Filtro de ataques em modo OBSERVAR (padrão): a tentativa na query
        // não derruba a página, nunca executa e vira linha marcada na trilha.
        anonimo.on('dialog', () => {
            throw new Error('XSS EXECUTOU — payload deveria ser inerte');
        });
        const tentativa = await anonimo.goto('/?e2e_observe=' + encodeURIComponent("<script>alert('e2e')</script>"));
        expect(tentativa.status()).toBe(200);
    } finally {
        await anonimo.close();
    }

    // Request logs registram navegação WEB (não só api/*).
    await openAdmin(page, '/admin/request-logs');
    // A tabela carrega via Livewire (deferred) — esperar a primeira linha.
    await expect(page.getByRole('row').nth(1)).toBeVisible({ timeout: 15000 });

    // Em paralelo, outros testes geram logs — buscar o endpoint /login.
    await page.getByPlaceholder(/pesquisar|search/i).fill('login');
    await expect(page.getByRole('cell', { name: 'login', exact: true }).first()).toBeVisible({ timeout: 15000 });

    // A tentativa observada aparece com selo e filtro próprio — nunca o
    // payload (ele fica só no detalhe, neutralizado).
    await openAdmin(page, '/admin/request-logs?filters[attacks][value]=1');
    await expect(page.getByText(/Observada: XSS/).first()).toBeVisible({ timeout: 15000 });
    await expect(page.getByText(/<script>alert\('e2e'\)/)).toHaveCount(0);
});

test('vitrine de segurança: submissões com ataques bloqueados, evidência no detalhe e filtro na URL', async ({ page }) => {
    await openAdmin(page, '/admin/form-submissions');

    // A LISTAGEM mostra o selo do tipo de ataque e o trecho neutralizado —
    // nunca o payload cru (decisão do dono: a lista não pode virar catálogo
    // de ataques, mesmo escapada).
    await expect(page.getByText(/XSS|SQL injection|Honeypot|Null byte|Path traversal/).first()).toBeVisible({ timeout: 15000 });
    await expect(page.getByText(/neutraliz/i).first()).toBeVisible();
    await expect(page.getByText(/<script>alert/)).toHaveCount(0);

    // O payload íntegro existe só no DETALHE, como evidência forense,
    // escapado (texto literal, inerte — nunca executa).
    const detalhe = page.locator('a[href*="/admin/form-submissions/"]').first();
    await expect(detalhe).toBeVisible({ timeout: 15000 });
    await detalhe.click();
    await expect(page).toHaveURL(/\/admin\/form-submissions\/[0-9a-f-]{36}/);
    await expect(page.getByText(/<script>alert/).first()).toBeVisible({ timeout: 15000 });

    // Filtro por origem refletido na URL (query string do Livewire).
    await openAdmin(page, '/admin/form-submissions?filters[origin][value]=classic');
    await expect(page.getByRole('cell', { name: 'Clássico (POST)' }).first()).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('cell', { name: 'Livewire (AJAX)' })).toHaveCount(0);
});

test('produtos: paginação de 10 refletida na URL (?page=2)', async ({ page }) => {
    await openAdmin(page, '/admin/products?page=2');

    await expect(page).toHaveURL(/page=2/);
    // 36 seeds → a paginação existe e a página 2 tem itens.
    await expect(page.getByRole('row').nth(1)).toBeVisible({ timeout: 15000 });
});

test('usuários: alternador na barra da tabela e ações do card em partes iguais', async ({ page }) => {
    await openAdmin(page, '/admin/users');
    await expect(page.getByRole('row').nth(1)).toBeVisible({ timeout: 15000 });

    // O alternador saiu do cabeçalho: agora é um botão SÓ DE ÍCONE na barra
    // da tabela, vizinho do filtro e da busca.
    const barra = page.locator('.fi-ta-header-toolbar');
    const alternador = barra.locator('.fi-ac-icon-btn-action').first();
    await expect(alternador).toBeVisible();
    await expect(alternador).toHaveAttribute('aria-label', /cards|tabela|table/i);
    // Sem texto: o rótulo vive no aria-label/tooltip, não no botão.
    expect((await alternador.innerText()).trim()).toBe('');

    await alternador.click();
    const rodape = page.locator('.fi-ta-content-grid .fi-ta-record-content-ctn > .fi-ta-actions').first();
    await expect(rodape).toBeVisible({ timeout: 15000 });

    // N ações = N colunas de MESMA largura, cada ícone centralizado.
    const larguras = await rodape.evaluate((el) =>
        Array.from(el.children).map((c) => Math.round(c.getBoundingClientRect().width)),
    );
    expect(larguras.length).toBeGreaterThan(1);
    expect(new Set(larguras).size).toBe(1);

    // Só ícone, com o nome no hover (title do tooltip do Filament).
    const acoes = rodape.locator('.fi-ac-icon-btn-action');
    expect(await acoes.count()).toBe(larguras.length);
    expect((await acoes.first().innerText()).trim()).toBe('');

    // Volta para a tabela (a escolha fica na sessão, compartilhada pelos
    // testes deste arquivo).
    await barra.locator('.fi-ac-icon-btn-action').first().click();
    await expect(page.locator('.fi-ta-content-grid')).toHaveCount(0, { timeout: 15000 });
    await expect(page.getByRole('row').nth(1)).toBeVisible({ timeout: 15000 });
});

test('tela de Auditoria: ação recusada e ação executada aparecem, com o link para a requisição', async ({ page }) => {
    // 1) Ação RECUSADA, sem efeito colateral nenhum: bloquear a conta demo do
    // cliente. A guarda do servidor recusa — e a tentativa vai para a trilha
    // como `user.blocked` / Recusada.
    await openAdmin(page, '/admin/users?search=demo%40tws.dev');
    const linhaDemo = page.getByRole('row').filter({ hasText: 'demo@tws.dev' });
    await expect(linhaDemo).toHaveCount(1, { timeout: 15000 });
    await linhaDemo.getByRole('button', { name: 'Bloquear' }).click();
    await page.locator('.fi-modal-window').getByRole('button', { name: 'Confirmar' }).click();
    await expect(page.getByText(/Conta de demonstração protegida/).first()).toBeVisible({ timeout: 15000 });

    await openAdmin(page, '/admin/audit-events?filters[action][value]=user.blocked&filters[outcome][value]=denied');
    const recusa = page.getByRole('row').filter({ hasText: 'user.blocked' }).first();
    await expect(recusa).toBeVisible({ timeout: 15000 });
    await expect(recusa).toContainText('Recusada');

    // Detalhe: o motivo e o link para a requisição na trilha de requisições.
    await recusa.getByRole('link', { name: /visualizar/i }).click();
    await expect(page).toHaveURL(/\/admin\/audit-events\/[0-9a-f-]{36}/);
    await expect(page.getByText(/Conta de demonstração protegida/).first()).toBeVisible({ timeout: 15000 });
    await page.locator('a[href*="/admin/request-logs/"]').first().click();
    await expect(page).toHaveURL(/\/admin\/request-logs\/[0-9a-f-]{36}/);
    await expect(page.getByText(/livewire/).first()).toBeVisible({ timeout: 15000 });

    // 2) Ação EXECUTADA, desfeita em seguida: mudar uma configuração e voltar
    // ao valor anterior. São duas linhas `setting.changed`, com o de/para.
    await openAdmin(page, '/admin/settings');
    const campo = page.getByLabel('Dias de aviso prévio por e-mail');
    await expect(campo).toBeVisible({ timeout: 15000 });
    const original = await campo.inputValue();
    const temporario = original === '13' ? '14' : '13';

    try {
        await campo.fill(temporario);
        await page.getByRole('button', { name: 'Salvar' }).click();
        await expect(page.getByText('Configurações salvas.').first()).toBeVisible({ timeout: 15000 });
    } finally {
        await openAdmin(page, '/admin/settings');
        await page.getByLabel('Dias de aviso prévio por e-mail').fill(original);
        await page.getByRole('button', { name: 'Salvar' }).click();
        await expect(page.getByText('Configurações salvas.').first()).toBeVisible({ timeout: 15000 });
    }

    await openAdmin(page, '/admin/audit-events?filters[action][value]=setting.changed');
    const mudanca = page.getByRole('row').filter({ hasText: 'setting.changed' }).first();
    await expect(mudanca).toBeVisible({ timeout: 15000 });
    await expect(mudanca).toContainText('Executada');

    await mudanca.getByRole('link', { name: /visualizar/i }).click();
    await expect(page).toHaveURL(/\/admin\/audit-events\/[0-9a-f-]{36}/);
    await expect(page.getByText('api_keys.inactivity.warning_days').first()).toBeVisible({ timeout: 15000 });
});

test('seletor de idioma (bandeira em SVG + nome) troca o idioma do painel', async ({ page }) => {
    await openAdmin(page, '/admin');

    // Era um <select> nativo com emoji; virou o menu do kit (<details>
    // autocontido: o /admin não carrega o ui.js nem os utilitários do app).
    const switcher = page.locator('details.tws-locale');
    await expect(switcher).toBeVisible();

    try {
        await switcher.locator('summary').click();
        await switcher.getByRole('menuitem', { name: 'English' }).click();
        // Painel em inglês: o grupo de navegação traduz.
        await expect(page.getByText('Security and audit').first()).toBeVisible({ timeout: 15000 });
    } finally {
        // Cleanup: a troca persiste users.locale na conta demo (banco de dev
        // compartilhado) — volta para PT mesmo se o teste falhar no meio.
        // Enquanto dura, a conta demo vê o /admin em inglês em QUALQUER
        // sessão, inclusive nos outros workers: quem usa o /admin fora deste
        // arquivo não pode depender de texto (ver support/cleanup.js).
        await openAdmin(page, '/admin');
        await page.locator('details.tws-locale summary').click();
        await page.locator('details.tws-locale').getByRole('menuitem', { name: 'Português (Brasil)' }).click();
        await expect(page.getByText('Segurança e auditoria').first()).toBeVisible({ timeout: 15000 });
    }
});
