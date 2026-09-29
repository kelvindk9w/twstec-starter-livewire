import { test, expect } from '@playwright/test';

// Teste de fumaça E2E: a landing pública sobe e exibe o nome da plataforma
// (que vem da config centralizada, nunca hardcoded no código).

test('landing responde e exibe o hero com o nome da plataforma', async ({ page }) => {
    const response = await page.goto('/');

    expect(response?.ok()).toBeTruthy();

    // Headline da landing (lang/pt_BR/landing.php) e nome vindo de PLATFORM_NAME.
    await expect(page.getByRole('heading', { level: 1 })).toContainText('IA não precisa gerar');
    await expect(page).toHaveTitle(/TWS Starter Kit/);
});

test('link "Componentes" do cabeçalho leva ao showcase /ui', async ({ page }) => {
    await page.goto('/');

    await page.getByRole('link', { name: 'Componentes', exact: true }).first().click();

    await expect(page).toHaveURL(/\/ui$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Componentes UI');
    await expect(page.getByRole('heading', { name: 'Botões' })).toBeVisible();
});

test('landing tem CTA da demo e o clone do repositório', async ({ page }) => {
    await page.goto('/');

    // CTA secundário do herói aponta para o login com credenciais demo.
    await expect(page.getByRole('link', { name: 'Ver a demo' }).first()).toHaveAttribute('href', /\/login$/);

    // CTA final: clonar o repositório (PLATFORM_REPO_URL) ou, sem ele
    // configurado, criar conta — nunca uma URL quebrada.
    const clone = page.getByRole('link', { name: 'Clonar o repositório' });
    await expect(clone).toBeVisible();

    // As telas do herói são capturas REAIS do produto (assets commitados).
    await expect(page.locator('img[src*="img/landing/dashboard-"]').first()).toBeVisible();
});

// O seletor de idioma é um dropdown do kit (era um <select> nativo com emoji):
// bandeira em SVG + nome do idioma por extenso, com links reais.
test('seletor de idioma: landing renderiza em inglês e espanhol', async ({ page }) => {
    await page.goto('/');

    await page.locator('[data-dropdown-trigger]').first().click();
    await page.getByRole('menuitem', { name: 'English' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('The base your AI');

    await page.locator('[data-dropdown-trigger]').first().click();
    await page.getByRole('menuitem', { name: 'Español' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('La base que tu IA');
});

// Abaixo de sm: a nav vira drawer (QA bug 11): hambúrguer abre, Esc fecha.
test('landing no mobile: menu hambúrguer abre o drawer e o Esc fecha', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');

    // #site-menu: a gaveta é a MESMA da landing, do /ui, das telas de auth e
    // do painel (um cabeçalho para o produto inteiro).
    const drawer = page.locator('#site-menu');
    await expect(drawer).toBeHidden();

    await page.getByRole('button', { name: 'Abrir menu de navegação' }).click();
    await expect(drawer).toBeVisible();
    await expect(drawer.getByRole('link', { name: 'Componentes' })).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
});

test('formulário de contato: envio válido mostra toast de sucesso', async ({ page }) => {
    await page.goto('/#contato');

    await page.getByLabel('Nome', { exact: true }).fill('Maria E2E');
    await page.getByLabel('E-mail', { exact: true }).fill('maria-e2e@example.com');
    // `exact`: o SplitText da landing devolve o texto revelado num aria-label,
    // e o subtítulo da seção contém a palavra "assunto". O rótulo do campo é
    // exatamente "Assunto".
    await page.getByLabel('Assunto', { exact: true }).selectOption('complaint');
    await page.getByLabel('Mensagem', { exact: true }).fill('Mensagem de teste E2E do formulário de contato.');
    await page.getByRole('button', { name: 'Enviar mensagem' }).click();

    // Redirect de volta + toast do kit com a confirmação.
    await expect(page.locator('[data-toast]')).toContainText('Mensagem enviada');
});

test('showcase: snippets copiam com feedback e o tema alterna claro/escuro', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto('/ui');

    const copyButton = page.locator('[data-copy]').first();
    await copyButton.click();
    await expect(copyButton).toContainText('Copiado!');

    // Feedback também via toast do próprio kit.
    await expect(page.locator('#clipboard-toast')).toBeVisible();

    const copied = await page.evaluate(() => navigator.clipboard.readText());
    expect(copied).toContain('<x-button');

    // Seletor de tema: 3 estados NOMEADOS num dropdown (era um ícone que
    // ciclava às cegas). colorScheme padrão do Playwright = light, então
    // 'Sistema' não aplica .dark.
    const html = page.locator('html');
    const themeTrigger = page.locator('[data-theme-set="dark"]').first();

    await page.getByRole('button', { name: 'Tema' }).first().click();
    await themeTrigger.click();
    await expect(html).toHaveClass(/dark/);

    await page.reload(); // persistido em localStorage
    await expect(html).toHaveClass(/dark/);

    await page.getByRole('button', { name: 'Tema' }).first().click();
    await page.locator('[data-theme-set="light"]').first().click();
    await expect(html).not.toHaveClass(/dark/);
});

// -----------------------------------------------------------------------------
// Índice do /ui no mobile (<x-side-nav>). Antes era uma nuvem de 13 pílulas
// empilhadas ANTES do conteúdo: a página começava com um menu do tamanho da
// tela. Agora é barra compacta + gaveta, como as docs do Next.js.
// -----------------------------------------------------------------------------
test('showcase no mobile: barra compacta abre o índice, navegar fecha e ancora', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/ui');

    // A barra diz em que seção o leitor está (scrollspy) e é o gatilho.
    const bar = page.locator('[data-modal-open="ui-drawer"]');
    await expect(bar).toBeVisible();

    const drawer = page.locator('#ui-drawer');
    await expect(drawer).toBeHidden();

    await bar.click();
    await expect(drawer).toBeVisible();

    // Grupos colapsáveis, na ordem do documento.
    await expect(drawer.getByText('Fundamentos')).toBeVisible();
    await expect(drawer.getByText('Componentes', { exact: true })).toBeVisible();

    // Navegar fecha a gaveta e rola até a âncora — o título tem de ficar
    // ABAIXO do cabeçalho (64px) e da barra (~48px), nunca escondido atrás.
    await drawer.getByRole('link', { name: 'Alertas' }).click();
    await expect(drawer).toBeHidden();
    await expect(page).toHaveURL(/#alerts$/);

    const top = await page.locator('#alerts h2').evaluate((el) => el.getBoundingClientRect().top);
    expect(top).toBeGreaterThan(100);

    // E a barra passa a anunciar a seção onde o leitor está.
    await expect(page.locator('[data-side-nav-current]')).toHaveText('Alertas');
});

test('showcase no desktop: índice em coluna com a seção atual marcada', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/ui');

    const index = page.getByRole('navigation', { name: 'Seções' });
    await expect(index).toBeVisible();

    await index.getByRole('link', { name: 'Badges' }).click();
    await expect(index.getByRole('link', { name: 'Badges' })).toHaveAttribute('aria-current', 'true');
});
