import { test } from '@playwright/test';

// =============================================================================
// O cadastro público está aberto nesta instalação? (AUTH_REGISTRATION_ENABLED)
//
// Fechado, GET /register responde 404 — e os specs que criam a conta pelo
// formulário de cadastro não têm como rodar: eles PULAM, com o motivo, em vez
// de quebrar. A pergunta é feita ao próprio servidor (a mesma resposta que um
// visitante teria), não a uma variável repetida no Playwright.
// =============================================================================

export const REGISTRATION_CLOSED_REASON =
    'Cadastro público fechado (AUTH_REGISTRATION_ENABLED=false): este spec cria a conta pelo /register.';

export async function registrationOpen(request) {
    const response = await request.get('/register', { maxRedirects: 0 });

    return response.status() !== 404;
}

/**
 * Pula o teste em curso quando o cadastro está fechado.
 */
export async function skipUnlessRegistrationOpen(request) {
    test.skip(!(await registrationOpen(request)), REGISTRATION_CLOSED_REASON);
}
