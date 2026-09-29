import { test, expect } from '@playwright/test';

// =============================================================================
// E2E do painel do usuário: login → dashboard, telas
// Livewire e gating do super admin.
//
// Sessão: o global-setup (tests/e2e/global-setup.js) autentica UMA vez e
// grava tests/e2e/.auth/e2e.json — os testes autenticados reusam a sessão
// (o login tem rate limit agressivo, throttle:sensitive).
//
// Pré-requisitos:
//   1. Stack de dev no ar (`docker compose up -d`), nginx na 8180 do host.
//   2. Usuário E2E criado no banco de dev:
//        docker compose exec app php artisan tinker --execute='
//          \App\Models\User::factory()->create([
//            "email" => "e2e@example.com",
//            "password" => "E2eSenhaForte123",
//          ]);'
//      (credenciais sobreponíveis via E2E_USER_EMAIL / E2E_USER_PASSWORD;
//      a factory cria a conta com o e-mail já confirmado — sem isso o
//      painel mandaria à tela de verificação)
//
// Rodar em container (sem Node local):
//   docker run --rm --network host -v $(pwd):/work -w /work \
//     mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
// =============================================================================

const email = process.env.E2E_USER_EMAIL ?? 'e2e@example.com';
const password = process.env.E2E_USER_PASSWORD ?? 'E2eSenhaForte123';

// PNG 96x96 REAL, embutido em base64: a validação de upload do kit lê o
// CONTEUDO do arquivo (magic bytes + decodificacao GD), entao um arquivo
// falso nao serve de fixture — e binario nenhum entra no repositorio.
const PNG_BASE64 =
    'iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAIAAABt+uBvAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAB90lEQVR4nO2b3U2DMRAECaIQEC1CFbQIohUejFAUiBb/7N7naOYxUuK7Yc9xEnN6fHm/g+vcVxdwdBAkQJAAQQIECRAkQJAAQQIECRAkQJAAQQIECRAkQJAAQQIECRAkQJDgobqAbz7fnn8/+PT6ka/kglPhl/Z/SrlGlawaQV1qzslrKtiDhu1MPneMaIIWtheLUi5Ba//4sSiFBDn6yThKCPJ1EnBkF+Tuwf36nKQFXkGZbcK6ilFQ8sziW4sRE7gE5Y+8phVJkABBAgQJLILyG5BvXRIkQJAAQQIECRAkQJDAIqjqJxrHuiRIgCABggQuQfltyLQiCRIYBSVD5FvLm6CMI+sqjJjALsgdIvfrJxLk6yEwwqERc3SS2eBye9DafmJvkQVX8Ca/OQ4fQQvexWY6zB/QueUqqBR0Dvekd4WTtABBAgQJECRAkABBgvr/F5PHxdrTEJ/FBDlBy283ZUwlBFkvnLk1GQWFL+Jt9rvYzdyTXp+gqhucP6yN0uIEldtZXsNKQUew01hYyZoRO46aC+bHbUGCDmvnbkVts4KObKcxWSEfVgVTgo4fn8ZMneOCdrHTGK52UNBedhpjNbMHCUYE7RifxkDl3YL2tdPorZ8REyBI0Cdo9/lqdHVBggQIEnQIuo35avy/FxIkQJAAQYIvh3yaD4YGJgoAAAAASUVORK5CYII=';

// -----------------------------------------------------------------------------
// Sem sessão prévia: fluxo real de login e gating de guest.
// -----------------------------------------------------------------------------
test.describe('sem autenticação', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('login → dashboard exibe saudação e navegação do painel', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('E-mail').fill(email);
        await page.getByLabel('Senha', { exact: true }).fill(password);
        await page.getByRole('button', { name: 'Entrar' }).click();

        // Redireciona para o dashboard com a saudação personalizada...
        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByRole('heading', { level: 1 })).toContainText('Olá,');

        // ...e a navegação do painel (Chaves de API, Projetos, Notificações, Perfil).
        await expect(page.getByRole('link', { name: 'Chaves de API', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Projetos', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Perfil', exact: true })).toBeVisible();
    });

    test('super admin: guest é redirecionado ao login do painel', async ({ page }) => {
        await page.goto('/admin');

        await expect(page).toHaveURL(/\/admin\/login$/);
    });
});

// -----------------------------------------------------------------------------
// Com sessão do global-setup (sem novo login — rate limit).
// -----------------------------------------------------------------------------
test.describe('autenticado', () => {
    test.use({ storageState: 'tests/e2e/.auth/e2e.json' });

    test('dashboard → tela de chaves de API carrega (Livewire hidratado)', async ({ page }) => {
        await page.goto('/dashboard');

        await page.getByRole('link', { name: 'Chaves de API', exact: true }).click();

        await expect(page).toHaveURL(/\/api-keys$/);
        await expect(page.getByRole('heading', { level: 1 })).toContainText('Chaves de API');
        // .first(): com a conta vazia o estado vazio (<x-empty-state>) repete
        // a mesma ação — o CTA aparece no cabeçalho e dentro do bloco.
        await expect(page.getByRole('button', { name: 'Nova chave' }).first()).toBeVisible();
    });

    test('chaves de API: formulário de criação abre na mesma tela', async ({ page }) => {
        await page.goto('/api-keys');
        await page.getByRole('button', { name: 'Nova chave' }).first().click();

        // O formulário abre via Livewire (prova que o JS hidratou — bundle
        // CSP-safe compatível com a CSP estrita do painel).
        await expect(page.getByText('Permissões (scopes)')).toBeVisible();
        await expect(page.getByText('Todas as permissões')).toBeVisible();

        // Toggle de escopos granulares (padrão tudo, granular opcional).
        await page.getByText('Todas as permissões').click();
        await expect(page.getByText('Selecione somente o que a integração precisa.')).toBeVisible();
    });

    test('super admin: usuário comum autenticado recebe 403', async ({ page }) => {
        const response = await page.goto('/admin');

        expect(response?.status()).toBe(403);
    });
});

// -----------------------------------------------------------------------------
// Regressões de QA (bugs 1 e 8) — o que quebrou de verdade no navegador.
// -----------------------------------------------------------------------------
test.describe('regressões', () => {
    test.use({ storageState: 'tests/e2e/.auth/e2e.json' });

    test('projetos: criar e EXCLUIR de verdade (parser CSP-safe do Livewire)', async ({ page }) => {
        const nome = `Projeto E2E ${Date.now()}`;
        const erros = [];
        page.on('console', (msg) => msg.type() === 'error' && erros.push(msg.text()));

        await page.goto('/projects');
        await page.getByRole('button', { name: 'Novo projeto' }).first().click();
        await page.getByLabel('Nome', { exact: true }).fill(nome);
        await page.getByRole('button', { name: 'Criar', exact: true }).click();
        await expect(page.getByText(nome)).toBeVisible();

        // Excluir: menu de overflow → modal de confirmação → Excluir.
        const linha = page.getByRole('row').filter({ hasText: nome });
        await linha.getByRole('button', { name: 'Mais ações' }).click();
        await linha.getByRole('menuitem', { name: 'Excluir' }).click();

        const modal = page.locator('#delete-project');
        await expect(modal).toBeVisible();
        await modal.getByRole('button', { name: 'Excluir' }).click();

        // O projeto SAI da lista de verdade (o bug antigo: `wire:click="delete"`
        // estourava o parser CSP do Livewire e a ação nunca rodava).
        await expect(page.getByText(nome)).toHaveCount(0);
        await page.reload();
        await expect(page.getByText(nome)).toHaveCount(0);

        expect(erros.filter((e) => e.includes('CSP Parser Error'))).toHaveLength(0);
    });

    test('painel no mobile: a gaveta traz o site E o "Minha conta"; Esc fecha', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/dashboard');

        // Uma gaveta só (#site-menu) — a mesma da landing. Antes o painel
        // tinha a própria (#panel-menu) e o cliente logado navegava em dois
        // menus diferentes para o mesmo produto.
        const drawer = page.locator('#site-menu');
        await expect(drawer).toBeHidden();

        await page.getByRole('button', { name: 'Abrir menu de navegação' }).click();
        await expect(drawer).toBeVisible();

        // Links do site...
        await expect(drawer.getByRole('link', { name: 'Componentes' })).toBeVisible();
        // ...e a seção da conta, com identidade e os itens do menu lateral.
        await expect(drawer.getByText('Minha conta')).toBeVisible();
        await expect(drawer.getByRole('link', { name: 'Chaves de API' })).toBeVisible();
        await expect(drawer.getByRole('link', { name: 'Senha de transação' })).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
    });

    test('menu do avatar: abre, troca o tema e o Esc fecha', async ({ page }) => {
        await page.goto('/dashboard');

        const trigger = page.getByRole('button', { name: 'Menu da conta' });
        await trigger.click();

        const menu = page.locator('[data-dropdown].is-open [data-dropdown-menu]');
        await expect(menu).toBeVisible();
        await expect(menu.getByText('Voltar ao site')).toBeVisible();
        await expect(menu.getByRole('menuitem', { name: 'Perfil' })).toBeVisible();

        // Troca o tema pelo menu: a classe .dark entra no <html> na hora.
        await menu.getByRole('menuitem', { name: 'Escuro' }).click();
        await expect(page.locator('html')).toHaveClass(/dark/);

        // Reabrir mostra o ✓ no estado escolhido, e o Esc fecha.
        await trigger.click();
        await expect(page.locator('[data-dropdown].is-open')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-dropdown].is-open')).toHaveCount(0);

        // Volta ao tema claro para não vazar estado para os próximos testes.
        await trigger.click();
        await page.getByRole('menuitem', { name: 'Sistema' }).click();
    });

    test('painel no desktop: menu lateral "Minha conta" marca a tela atual', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/projects');

        const sidebar = page.getByRole('navigation', { name: 'Minha conta' });
        await expect(sidebar).toBeVisible();
        await expect(sidebar.getByText('Desenvolvimento')).toBeVisible();

        // Item ativo com aria-current — reconhecer onde se está sem clicar.
        await expect(sidebar.getByRole('link', { name: 'Projetos' })).toHaveAttribute('aria-current', 'page');

        // E navegar pela coluna funciona.
        await sidebar.getByRole('link', { name: 'Chaves de API' }).click();
        await expect(page).toHaveURL(/\/api-keys$/);
    });


    test('perfil: sobe a foto, ela vira o avatar do cabeçalho e o arquivo falso é recusado', async ({ page }) => {
        await page.goto('/profile');

        const cartao = page.locator('form').filter({ has: page.locator('input[type="file"]') });
        const antes = await page.evaluate(() => Array.from(document.images).map((i) => i.src));

        await cartao.locator('input[type="file"]').setInputFiles({
            name: 'foto-valida.png',
            mimeType: 'image/png',
            buffer: Buffer.from(PNG_BASE64, 'base64'),
        });
        await cartao.getByRole('button', { name: /salvar foto/i }).click();
        await expect(page.getByText(/foto/i).first()).toBeVisible();

        // A foto tem de sobreviver ao reload: o vínculo é do banco, não da
        // sessão. E a URL é ASSINADA (política de uploads do kit: documento
        // nunca em bucket público).
        await page.reload({ waitUntil: 'networkidle' });
        const src = await page.evaluate(
            () => Array.from(document.images).map((i) => i.src).find((s) => /\/storage\/avatars\//.test(s)) ?? null,
        );
        expect(src).toBeTruthy();
        expect(src).toMatch(/(signature|expires)=/i);
        expect(antes).not.toContain(src);

        // Arquivo que só PARECE imagem: recusado com mensagem, sem gravar.
        await cartao.locator('input[type="file"]').setInputFiles({
            name: 'nao-e-imagem.png',
            mimeType: 'image/png',
            buffer: Buffer.from('isto aqui e texto puro, apenas renomeado para .png'),
        });
        await cartao.getByRole('button', { name: /salvar foto/i }).click();
        await expect(
            page.getByText(/corrompida|não é permitido|não corresponde|inválida/i).first(),
        ).toBeVisible({ timeout: 15000 });
    });

    test('dashboard: métricas, gráfico e últimas chamadas da API', async ({ page }) => {
        await page.goto('/dashboard');

        // Escopo no <main>: "Projetos" também é item do menu lateral e da
        // gaveta — sem escopo, o primeiro match é um link escondido.
        const conteudo = page.getByRole('main');

        await expect(conteudo.getByText('Chaves de API ativas')).toBeVisible();
        await expect(conteudo.getByText('Projetos', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('heading', { name: /Requisições por dia/ })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Últimas chamadas da API' })).toBeVisible();
    });
});
