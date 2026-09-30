import { adminEmail, adminPassword, demoInstalled } from './project-env.js';

// =============================================================================
// Entrar no /admin (Filament) — o global-setup e a limpeza das contas que os
// testes criam.
//
// COM a demonstração do kit instalada: o SUPER ADMIN DEMO, pelo botão da tela
// de login com as credenciais PRÉ-PREENCHIDAS (sem digitar nada) — é também a
// verificação desse pré-preenchimento. SEM ela (o projeto criado): o admin do
// E2E (admin-e2e@example.com), criado por tests/e2e/fixtures.php, com e-mail e
// senha.
// =============================================================================

export async function signInToAdmin(page) {
    await page.goto('/admin/login', { waitUntil: 'networkidle' });
    // O formulário do Filament é Livewire: clicar antes de ele inicializar
    // não chega ao servidor.
    await page.waitForFunction(() => document.querySelector('[wire\\:id]')?.__livewire !== undefined, null, { timeout: 15_000 });

    if (!demoInstalled) {
        await page.locator('input[type="email"]').fill(adminEmail);
        await page.locator('input[type="password"]').fill(adminPassword);
    }

    await page.getByRole('button', { name: /entrar|sign in|iniciar|^login$/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('login'), { timeout: 15_000 });
}
