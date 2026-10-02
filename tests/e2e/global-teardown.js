import { request as requestFactory } from '@playwright/test';
import { deleteMailpitMessagesTo, deleteMailpitMessagesWithMarker } from './support/mailpit.js';
import { dotEnv, fixedPeople, isolationProblem, mailpitBaseUrl } from './support/project-env.js';

// =============================================================================
// Global teardown do E2E: as mensagens das pessoas FIXAS saem do Mailpit no
// fim da rodada — com o segundo fator obrigatório, cada login delas manda um
// código por e-mail; com a aprovação em dois passos, o admin do E2E recebe o
// código da confirmação. As das pessoas que os testes criam (`e2e-…`) cada
// spec já apaga no `finally` (support/cleanup.js). E as do formulário de
// contato (smoke.spec.js, com a demonstração) que a fila tenha entregado
// depois do fim do teste: só as que trazem o marcador `e2e-contato-`.
//
// A mesma trava de isolamento do global-setup (support/project-env.js): se o
// E2E não está apontado para ESTE projeto, nada é apagado.
// =============================================================================

export default async function globalTeardown(config) {
    const baseURL = config.projects[0].use.baseURL;
    const problem = isolationProblem(String(baseURL), mailpitBaseUrl, null);

    if (problem !== null) {
        console.warn(`limpeza E2E: as mensagens das pessoas fixas NÃO foram apagadas — ${problem}.`);

        return;
    }

    const request = await requestFactory.newContext();

    try {
        for (const address of fixedPeople) {
            await deleteMailpitMessagesTo(request, address);
        }

        if (dotEnv.PLATFORM_CONTACT_EMAIL) {
            await deleteMailpitMessagesWithMarker(request, dotEnv.PLATFORM_CONTACT_EMAIL, 'e2e-contato-');
        }
    } finally {
        await request.dispose();
    }
}
