import { test, expect } from '@playwright/test';
import { registrationOpen } from './support/registration.js';

// =============================================================================
// O interruptor do cadastro público (AUTH_REGISTRATION_ENABLED), no navegador:
// aberto, a tela de login (e o cabeçalho do site) oferecem "Criar conta" e o
// formulário abre; fechado, nenhum link aponta para /register e o endereço
// responde 404. O spec se adapta ao que a instalação está usando — roda nos
// dois estados.
// =============================================================================

test.describe('cadastro público', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('os links de "Criar conta" seguem o interruptor, e a rota também', async ({ page, request }) => {
        const open = await registrationOpen(request);

        await page.goto('/login');
        const links = page.locator('a[href$="/register"]');

        if (open) {
            await expect(links.first()).toBeVisible();

            const response = await page.goto('/register');
            expect(response?.status()).toBe(200);
            await expect(page.getByRole('button', { name: 'Criar conta' })).toBeVisible();
        } else {
            await expect(links).toHaveCount(0);

            const response = await page.goto('/register');
            expect(response?.status()).toBe(404);
        }
    });
});
