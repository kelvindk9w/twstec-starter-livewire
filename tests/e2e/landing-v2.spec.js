import { test, expect } from '@playwright/test';

// =============================================================================
// E2E da landing "O Rastro" (/v2).
//
// Cobre o que só existe no navegador e o que o teste de feature não alcança:
// o MOMENTO-ASSINATURA (a redação LGPD acontecendo) e o BOTÃO DE SOM (mudo por
// padrão, com estado visível). Os dois são promessas de comportamento — se
// quebrarem, a página continua respondendo 200 e mentindo.
// =============================================================================

test.describe('landing /v2 — O Rastro', () => {
    test('o herói se forma e os dois CTAs estão lá', async ({ page }) => {
        const response = await page.goto('/v2');

        expect(response?.ok()).toBeTruthy();

        // O título é montado caractere a caractere pelo SplitText: o texto
        // acessível continua sendo a frase inteira (aria-label do <h1>).
        const headline = page.getByRole('heading', { level: 1 });

        await expect(headline).toHaveAttribute('aria-label', 'Tudo que acontece fica registrado.');

        // CTA primário: o próprio comando, copiável.
        const clone = page.locator('[data-lv2-clone]');

        await expect(clone).toBeVisible();
        await expect(clone).toHaveAttribute('data-copy', /^git clone https?:\/\/.+ meu-projeto$/);

        // CTA secundário.
        await expect(page.getByRole('link', { name: 'Entrar na demo' })).toHaveAttribute('href', /\/login$/);

        // Sem mockup de browser (decisão da direção): nenhuma imagem de painel.
        await expect(page.locator('img[src*="img/landing/dashboard.png"]')).toHaveCount(0);
    });

    test('MOMENTO-ASSINATURA: a redação LGPD acontece na frente do visitante', async ({ page }) => {
        await page.goto('/v2');

        const card = page.locator('[data-lv2-audit="0"]');

        await card.scrollIntoViewIfNeeded();

        // Antes: o dado sensível está inteiro na tela.
        await expect(card).toContainText('marina.duarte@exemplo.com');
        await expect(card).toContainText('472.918.330-15');
        await expect(card).toContainText('Prim@vera-2026');

        await card.hover();

        // Depois: a saída REAL do Redactor do kit — e-mail com a primeira
        // letra e o domínio, CPF com três primeiros e dois últimos dígitos,
        // senha substituída por inteiro.
        await expect(card).toContainText('m***@exemplo.com', { timeout: 5000 });
        await expect(card).toContainText('472.***.***-15');
        await expect(card).toContainText('[REDACTED]');

        // E o dado cru saiu da tela.
        await expect(card).not.toContainText('marina.duarte@exemplo.com');
        await expect(card).not.toContainText('Prim@vera-2026');

        // A legenda diz QUAL regra agiu em cada campo.
        await expect(card).toContainText('chave sensível');
        await expect(card).toContainText('CPF/CNPJ');

        // "Ver o original" devolve o payload cru (a demonstração é repetível).
        await page.getByRole('button', { name: 'Ver o original' }).click();
        await expect(card).toContainText('marina.duarte@exemplo.com');
    });

    test('a redação também dispara pelo TECLADO', async ({ page }) => {
        await page.goto('/v2');

        const card = page.locator('[data-lv2-audit="1"]');

        await card.scrollIntoViewIfNeeded();
        await card.focus();

        // O api_secret é chave sensível: some por inteiro.
        await expect(card).toContainText('[REDACTED]', { timeout: 5000 });
        await expect(card).not.toContainText('sk_live_2f9a41c7d0e84b6f');
    });

    test('o som começa MUDO e o botão mostra o próprio estado', async ({ page }) => {
        await page.goto('/v2');

        const sound = page.locator('[data-lv2-sound]').first();

        await expect(sound).toBeVisible();
        await expect(sound).toHaveAttribute('aria-pressed', 'false');

        // Nenhum AudioContext existe antes do gesto: som que começa sozinho
        // é o pior comportamento que uma página pode ter.
        await sound.click();
        await expect(sound).toHaveAttribute('aria-pressed', 'true');

        const running = await page.evaluate(() => document.querySelector('[data-lv2-sound]')?.getAttribute('aria-pressed'));

        expect(running).toBe('true');

        await sound.click();
        await expect(sound).toHaveAttribute('aria-pressed', 'false');
    });

    test('o bento acende UMA célula por vez e navega pelo teclado', async ({ page }) => {
        await page.goto('/v2');

        const tabs = page.locator('[data-lv2-bento] [role="tab"]');

        await expect(tabs).toHaveCount(6);
        await expect(page.locator('[data-lv2-bento] [role="tab"][aria-selected="true"]')).toHaveCount(1);

        await tabs.first().scrollIntoViewIfNeeded();
        await tabs.first().focus();
        await page.keyboard.press('ArrowRight');

        await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
        await expect(tabs.first()).toHaveAttribute('aria-selected', 'false');
        await expect(page.locator('[data-lv2-bento] [role="tab"][aria-selected="true"]')).toHaveCount(1);

        // O painel da célula viva abre código real, copiável.
        await expect(page.locator('[data-lv2-panel]:not([hidden])')).toHaveCount(1);
        await expect(page.locator('[data-lv2-panel]:not([hidden])')).toContainText('RequestLog');
    });

    test('o 2FA de mentira roda o fluxo inteiro sem enviar e-mail', async ({ page }) => {
        await page.goto('/v2');

        const box = page.locator('[data-lv2-twofa]');

        await box.scrollIntoViewIfNeeded();
        await box.getByRole('button', { name: 'Enviar código' }).click();

        const feedback = box.locator('[data-lv2-twofa-feedback]');

        await expect(feedback).toBeVisible({ timeout: 5000 });

        // O texto declara que é demonstração e mostra o código a usar.
        const message = await feedback.textContent();
        const code = message.match(/\b(\d{6})\b/)[1];

        // Código errado: o erro nomeia o problema E a recuperação.
        await box.locator('[data-lv2-twofa-input]').fill('000000');
        await box.getByRole('button', { name: 'Confirmar' }).click();
        await expect(feedback).toContainText('inválido');
        await expect(feedback).toContainText('tentativas');

        // Código certo: ação confirmada.
        await box.locator('[data-lv2-twofa-input]').fill(code);
        await box.getByRole('button', { name: 'Confirmar' }).click();
        await expect(feedback).toContainText('confirmada');
    });

    test('o mundo padrão da /v2 é a TINTA, e a escolha explícita manda', async ({ browser }) => {
        // Sem escolha nenhuma: tinta.
        const virgin = await browser.newContext();
        const p1 = await virgin.newPage();

        await p1.goto('/v2');
        await p1.waitForTimeout(900);
        await expect
            .poll(() => p1.evaluate(() => document.documentElement.classList.contains('dark')))
            .toBe(true);
        await virgin.close();

        // 'system' guardado também cai na tinta: aqui "sistema" resolve contra
        // o padrão DA TELA, não contra o sistema operacional.
        const system = await browser.newContext({ colorScheme: 'light' });

        await system.addInitScript(() => localStorage.setItem('theme', 'system'));

        const p2 = await system.newPage();

        await p2.goto('/v2');
        await p2.waitForTimeout(900);
        await expect
            .poll(() => p2.evaluate(() => document.documentElement.classList.contains('dark')))
            .toBe(true);
        await system.close();

        // 'light' explícito é respeitado: a página declara um padrão, não
        // sequestra a preferência de ninguém.
        const light = await browser.newContext();

        await light.addInitScript(() => localStorage.setItem('theme', 'light'));

        const p3 = await light.newPage();

        await p3.goto('/v2');
        await p3.waitForTimeout(900);
        expect(await p3.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(false);

        // E a /v2 não escreve na preferência global do visitante.
        expect(await p3.evaluate(() => localStorage.getItem('theme'))).toBe('light');
        await light.close();
    });

    test('a troca de tema repinta a página inteira (tokens, não cores à mão)', async ({ page }) => {
        await page.goto('/v2');

        const background = () =>
            page.evaluate(() => getComputedStyle(document.querySelector('.lv2')).backgroundColor);

        await page.evaluate(() => document.documentElement.classList.remove('dark'));
        const light = await background();

        await page.evaluate(() => document.documentElement.classList.add('dark'));
        const dark = await background();

        expect(light).not.toBe(dark);

        // E o cabeçalho do kit acompanha, sem que nenhum componente
        // compartilhado tenha sido editado para isso.
        const header = await page.evaluate(() => getComputedStyle(document.querySelector('header')).backgroundColor);

        expect(header).not.toBe(light);
    });

    test('a ESPINHA marca as seções e o marcador vivo acompanha o scroll', async ({ page }) => {
        await page.goto('/v2');
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.waitForTimeout(1200);

        const marks = page.locator('[data-lv2-mark]');

        await expect(marks).toHaveCount(5);

        // Cada marcador é ancorado na seção que representa: a posição no fio
        // é a posição REAL daquela seção, não um palpite em porcentagem.
        const tops = await marks.evaluateAll((els) => els.map((el) => Number.parseFloat(el.style.top)));

        expect(tops.every((t) => Number.isFinite(t) && t > 0 && t <= 100)).toBe(true);
        expect([...tops]).toEqual([...tops].sort((a, b) => a - b));

        // No meio da página há exatamente UM marcador vivo, e há marcadores
        // já registrados atrás dele.
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight * 0.5));
        await page.waitForTimeout(700);

        await expect(page.locator('[data-lv2-mark].is-live')).toHaveCount(1);
        expect(await page.locator('[data-lv2-mark].is-done').count()).toBeGreaterThan(0);

        // E o carimbo de tempo andou: o scroll é a linha do tempo.
        const stamp = await page.locator('[data-lv2-stamp]').textContent();

        expect(stamp).toMatch(/^00:0[0-9]:[0-5][0-9]$/);
        expect(stamp).not.toBe('00:00:00');
    });

    test('o CTA final fecha o ciclo com o mesmo campo ambiente do herói', async ({ page }) => {
        await page.goto('/v2');

        await expect(page.locator('[data-lv2-ambient]')).toHaveCount(1);
        await expect(page.locator('[data-lv2-ambient-final]')).toHaveCount(1);

        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        await page.waitForTimeout(800);

        // O canvas do fim tem tamanho real (foi dimensionado, não ficou 0×0).
        const size = await page.locator('[data-lv2-ambient-final]').evaluate((el) => ({
            w: el.width,
            h: el.height,
        }));

        expect(size.w).toBeGreaterThan(100);
        expect(size.h).toBeGreaterThan(50);
    });

    test('a /v2 é bilíngue e o conteúdo troca de idioma', async ({ page }) => {
        await page.goto('/v2');
        await expect(page.getByRole('heading', { level: 1 })).toHaveAttribute('aria-label', /registrado/);

        await page.goto('/locale/en');
        await page.goto('/v2');

        await expect(page.getByRole('heading', { level: 1 })).toHaveAttribute(
            'aria-label',
            'Everything that happens is recorded.',
        );
        await expect(page.getByRole('link', { name: 'Enter the demo' })).toBeVisible();

        // Volta ao pt-BR para não vazar estado para os outros specs.
        await page.goto('/locale/pt_BR');
    });


    test('o HERÓI existe no primeiro paint e o scroll dirige a cena pinada', async ({ page }) => {
        await page.goto('/v2');

        // Antes de qualquer rolagem: título, subtítulo, CTAs e objeto já estão
        // na tela. Um herói que só existe depois que a pessoa rola é um herói
        // em branco no primeiro paint.
        const front = page.locator('[data-lv2-front-item]').first();
        const object = page.locator('[data-lv2-object]');

        await front.waitFor();
        await object.waitFor();

        const opacityOf = (locator) =>
            locator.evaluate((element) => Number(getComputedStyle(element).opacity));

        await expect.poll(() => opacityOf(front), { timeout: 6000 }).toBeGreaterThan(0.9);
        await expect.poll(() => opacityOf(object), { timeout: 6000 }).toBeGreaterThan(0.9);

        // O herói é PINADO: rolar uma tela mantém o topo da seção colado no
        // topo da viewport (o ScrollTrigger cria o espaçador do pin).
        const spacer = await page.evaluate(() => !!document.querySelector('.pin-spacer'));

        expect(spacer).toBe(true);

        await page.evaluate(() => window.scrollTo(0, window.innerHeight * 0.9));
        await page.waitForTimeout(700);

        const top = await page.evaluate(() => document.querySelector('[data-lv2-hero]').getBoundingClientRect().top);

        expect(Math.abs(top), 'o herói continua preso no topo enquanto a cena roda').toBeLessThan(120);
    });

    test('CAMPO INTERATIVO: o que o visitante digita é redigido ao vivo', async ({ page }) => {
        await page.goto('/v2');

        const input = page.locator('[data-lv2-live-input]');
        const output = page.locator('[data-lv2-live-output]');
        const note = page.locator('[data-lv2-live-note]');

        // CPF e e-mail — as MESMAS regras do Redactor do kit.
        await input.fill('meu cpf é 472.918.330-15 e o e-mail marina.duarte@exemplo.com');
        await expect(output).toHaveText(/472\.\*\*\*\.\*\*\*-15/);
        await expect(output).toHaveText(/m\*\*\*@exemplo\.com/);
        await expect(note).toContainText('CPF/CNPJ');
        await expect(note).toContainText('e-mail');

        // Chave sensível: some por inteiro.
        await input.fill('password: SenhaSuperSecreta1');
        await expect(output).toHaveText(/\[REDACTED\]/);

        // Cartão: só os quatro últimos.
        await input.fill('cartao 4111 1111 1111 1111');
        await expect(output).toHaveText(/\*\*\*\* \*\*\*\* \*\*\*\* 1111/);

        // Número que não passa no Luhn (pedido, protocolo): intacto, como no kit.
        await input.fill('pedido 4111 1111 1111 1112');
        await expect(output).toHaveText('pedido 4111 1111 1111 1112');

        // Nada sensível: o texto passa intacto — a página não inventa alarme.
        await input.fill('nada sensivel aqui');
        await expect(output).toHaveText('nada sensivel aqui');
        await expect(note).toContainText('nada sensível');
    });

    test('os números da faixa de confiança contam e levam "+"', async ({ page }) => {
        await page.goto('/v2');

        const first = page.locator('[data-lv2-count]').first();

        await first.scrollIntoViewIfNeeded();
        await page.waitForTimeout(2200);

        const text = await first.textContent();

        expect(text).toMatch(/^[\d.,]+\+$/);
        expect(Number(text.replace(/\D/g, ''))).toBeGreaterThan(100);
    });

    test('com movimento reduzido a página NÃO pina e continua completa', async ({ browser }) => {
        const ctx = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 1440, height: 900 } });
        const page = await ctx.newPage();

        await page.goto('/v2');
        await page.waitForTimeout(1500);

        // Rolagem sequestrada é exatamente o que essa preferência pede para
        // não existir: sem pin, sem espaçador.
        expect(await page.evaluate(() => !!document.querySelector('.pin-spacer'))).toBe(false);

        // E o conteúdo continua todo lá.
        await expect(page.getByRole('heading', { level: 1 })).toHaveAttribute('aria-label', /registrado/);
        await expect(page.locator('[data-lv2-live-input]')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Entrar na demo' })).toBeVisible();

        await ctx.close();
    });

    test('a página não escreve no console e não rola na horizontal', async ({ page }) => {
        const errors = [];

        // A contagem de estrelas é a ÚNICA chamada externa da página e tem
        // fallback silencioso por decisão: o rate limit anônimo do GitHub
        // responde 403 com frequência, e isso NÃO é um defeito da landing —
        // é o caminho de erro previsto. O que o teste não tolera é erro de
        // código. (Que o bloco some quando a chamada falha é verificado
        // logo abaixo.)
        const isGithubFetch = (text) => /api\.github\.com|status of (4|5)\d\d/.test(text);

        page.on('console', (message) => {
            if (message.type() === 'error' && !isGithubFetch(message.text())) errors.push(message.text());
        });
        page.on('pageerror', (error) => errors.push(error.message));

        await page.goto('/v2');
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await page.waitForTimeout(1200);

        expect(errors).toEqual([]);

        // Estrelas: ou o bloco traz um número > 0, ou não existe. Nunca um
        // "0 estrelas" e nunca uma mensagem de erro na cara do visitante.
        const stars = await page.evaluate(() => {
            const wrapper = document.querySelector('[data-lv2-stars]');

            return { hidden: wrapper.hidden, value: wrapper.textContent.trim() };
        });

        if (!stars.hidden) expect(Number(stars.value.replace(/\D/g, ''))).toBeGreaterThan(0);

        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(300);

            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
            );

            expect(overflow, `overflow horizontal em ${width}px`).toBeLessThanOrEqual(1);
        }
    });
});
