import { test, expect } from '@playwright/test';

// Seção "Padrões de formulário" do /ui: os DOIS exemplos funcionais —
// Blade clássico (POST + redirect + old()) e Livewire (wire:submit, AJAX) —
// o override da estratégia de exibição de erros e a vitrine de segurança
// (ataque real: nada executa, tentativa registrada).

test('form clássico do /ui: erro inline e old() repopulando', async ({ page }) => {
    await page.goto('/ui#form_patterns');

    const form = page.locator('#demo-classic');
    await form.getByLabel('Apelido', { exact: true }).fill('maria_e2e');
    // mensagem vazia → erro de validação
    await form.getByRole('button', { name: 'Enviar demonstração' }).click();

    // Redirect de volta: apelido repopulado com old().
    await expect(form.getByLabel('Apelido', { exact: true })).toHaveValue('maria_e2e');

    // Estratégia padrão (inline): erro junto ao campo, sem resumo.
    await expect(page.locator('#classic_message')).toHaveClass(/border-red-500/);
    await expect(form.locator('[role="alert"]')).toHaveCount(0);
});

test('form clássico do /ui: override summary exibe resumo com âncoras', async ({ page }) => {
    await page.goto('/ui#form_patterns');

    const form = page.locator('#demo-classic');
    await form.getByLabel(/Estratégia de exibição/).selectOption('summary');
    await form.getByRole('button', { name: 'Enviar demonstração' }).click();

    const summary = form.locator('[role="alert"]');
    await expect(summary).toBeVisible();
    await expect(summary.getByRole('link', { name: /apelido/i })).toHaveAttribute('href', '#classic_nickname');

    // Inline suprimido na estratégia summary.
    await expect(page.locator('#classic_nickname')).not.toHaveClass(/border-red-500/);

    // Âncora do resumo rola até o campo.
    await summary.getByRole('link', { name: /apelido/i }).click();
    await expect(page).toHaveURL(/#classic_nickname$/);
});

test('form Livewire do /ui: valida sem reload e confirma o envio na própria tela', async ({ page }) => {
    await page.goto('/ui#form_patterns');
    await page.waitForFunction(() => window.Livewire !== undefined);

    const ajax = page.locator('#demo-ajax');

    // Validação server-side via wire:submit (sem reload).
    await ajax.getByRole('button', { name: 'Enviar mensagem' }).click();
    await expect(ajax.getByText('O campo Apelido é obrigatório.')).toBeVisible();
    await expect(page).toHaveURL(/#form_patterns$/);

    // Envio válido: grava em form_submissions e confirma na própria tela.
    await ajax.getByLabel('Apelido', { exact: true }).fill('maria_livewire');
    await ajax.getByLabel('Assunto').selectOption('suggestion');
    await ajax.getByLabel('Mensagem').fill('Mensagem E2E via Livewire no showcase.');
    await ajax.getByRole('button', { name: 'Enviar mensagem' }).click();

    await expect(ajax.getByText('Submissão registrada')).toBeVisible();
});

test('vitrine de segurança: XSS real no form clássico NUNCA executa e vira toast de sucesso falso', async ({ page }) => {
    // Qualquer alert() que disparar falha o teste — o payload é inerte.
    page.on('dialog', () => {
        throw new Error('XSS EXECUTOU — payload deveria ser inerte');
    });

    await page.goto('/ui#form_patterns');

    const form = page.locator('#demo-classic');
    await form.getByLabel('Apelido', { exact: true }).fill('atacante_e2e');
    await form.getByLabel('Mensagem').fill("<script>alert('ola')</script> ataque real E2E");
    await form.getByRole('button', { name: 'Enviar demonstração' }).click();

    // Sucesso falso (não damos sinal ao atacante) e a página segue íntegra.
    await expect(page.getByRole('status').filter({ hasText: 'Demonstração enviada' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Padrões de formulário/ })).toBeVisible();
});
