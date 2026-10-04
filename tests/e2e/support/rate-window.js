import { existsSync, readFileSync } from 'node:fs';

// =============================================================================
// O TETO POR IP DA BORDA (RATE_LIMIT_WEB por minuto) é dividido pela suíte
// inteira: os navegadores em paralelo saem do mesmo IP. Um spec que conversa
// muito com o servidor espera uma janela de minuto só dele — contada a partir
// do começo da suíte (o global-setup grava a hora). O approvals.spec.js usa
// a segunda janela; quem chegar depois escolhe outra.
// =============================================================================

/** Espera o começo da janela `n` (1 = a primeira, 0 s; 2 = 62 s; 3 = 124 s…). */
export async function waitForRateWindow(page, n) {
    const marker = 'tests/e2e/.auth/suite-started-at';
    const startedAt = existsSync(marker) ? Number(readFileSync(marker, 'utf8')) : Date.now();
    const remaining = startedAt + (n - 1) * 62_000 - Date.now();

    if (remaining > 0) {
        await page.waitForTimeout(remaining);
    }
}
