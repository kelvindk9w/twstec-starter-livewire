import { expect } from '@playwright/test';
import { mailpitBaseUrl as mailpit } from './project-env.js';

// =============================================================================
// A caixa do Mailpit DESTE projeto, lida pela API dele. Os e-mails são
// entregues pelo worker da fila (serviço `queue`); a leitura espera por eles
// (poll), nunca por tempo fixo. A mensagem é escolhida pelo DESTINATÁRIO e
// pelo que ainda não foi visto (`seen`) — e, quando dado, por um critério do
// conteúdo (`match`).
// =============================================================================

/**
 * As mensagens que JÁ estão no Mailpit para `address` — o `seen` inicial de
 * quem vai ler o código de uma pessoa FIXA (que acumula mensagens de testes
 * anteriores): só o que chegar depois conta.
 */
export async function messagesAlreadyTo(request, address) {
    const search = await request.get(`${mailpit}/api/v1/search`, { params: { query: `to:"${address}"`, limit: '500' } });

    return new Set(((await search.json()).messages ?? []).map((m) => m.ID));
}

/**
 * A próxima mensagem para `address` que ainda não está em `seen` (e que
 * satisfaz `match`). Devolve o JSON completo e a marca como vista.
 */
export async function waitForMessage(request, address, seen, match = () => true) {
    let found = null;

    await expect
        .poll(
            async () => {
                const search = await request.get(`${mailpit}/api/v1/search`, { params: { query: `to:"${address}"` } });
                const ids = ((await search.json()).messages ?? []).map((m) => m.ID).filter((id) => !seen.has(id));

                // A busca vem da mais nova para a mais antiga: a mais antiga
                // ainda não vista é a próxima.
                for (const id of ids.reverse()) {
                    const message = await (await request.get(`${mailpit}/api/v1/message/${id}`)).json();

                    if (match(message)) {
                        found = message;

                        return id;
                    }
                }

                return null;
            },
            { message: `e-mail para ${address} no Mailpit`, timeout: 30_000, intervals: [500, 1_000] },
        )
        .not.toBeNull();

    seen.add(found.ID);

    return found;
}

/** O código de 6 dígitos do texto do e-mail. */
export function codeFrom(message) {
    const code = message.Text.match(/\b(\d{6})\b/)?.[1];
    expect(code, 'código de 6 dígitos no texto do e-mail').toBeTruthy();

    return code;
}

/** O link assinado de verificação de e-mail (HTML do e-mail). */
export function verificationLinkFrom(message) {
    const link = message.HTML.match(/href="([^"]*\/email\/verify\/[^"]+)"/)?.[1]?.replaceAll('&amp;', '&');
    expect(link, 'link de verificação no HTML do e-mail').toBeTruthy();

    return link;
}

export const hasCode = (message) => /\b\d{6}\b/.test(message.Text);
export const hasVerificationLink = (message) => message.HTML.includes('/email/verify/');

/**
 * As mensagens para `address` cujo texto traz `marker` (comparação no texto
 * decodificado da mensagem, não na busca do Mailpit — a busca por um trecho
 * com hífens não é confiável).
 */
async function messagesWithMarker(request, address, marker) {
    const search = await request.get(`${mailpit}/api/v1/search`, { params: { query: `to:"${address}"`, limit: '500' } });
    const found = [];

    for (const { ID } of (await search.json()).messages ?? []) {
        const message = await (await request.get(`${mailpit}/api/v1/message/${ID}`)).json();

        if (message.Text.includes(marker)) {
            found.push(message);
        }
    }

    return found;
}

/**
 * A mensagem para `address` cujo texto traz `marker` — para o que não tem
 * destinatário próprio do teste (o formulário de contato vai para o endereço
 * da plataforma): o teste põe um marcador único no conteúdo e acha por ele.
 */
export async function waitForMessageWithMarker(request, address, marker, timeout = 30_000) {
    let found = null;

    await expect
        .poll(
            async () => {
                found = (await messagesWithMarker(request, address, marker))[0] ?? null;

                return found?.ID ?? null;
            },
            { message: `e-mail para ${address} com ${marker} no Mailpit`, timeout, intervals: [500, 1_000] },
        )
        .not.toBeNull();

    return found;
}

/**
 * Apaga as mensagens para `address` cujo texto traz `marker` — no fim do
 * teste que a criou e, como rede de segurança, no global-teardown (para o
 * que a fila entregou depois). Só o que tem o marcador do E2E sai.
 */
export async function deleteMailpitMessagesWithMarker(request, address, marker) {
    const ids = (await messagesWithMarker(request, address, marker)).map((message) => message.ID);

    if (ids.length > 0) {
        await request.delete(`${mailpit}/api/v1/messages`, { data: { IDs: ids } });
    }

    return ids.length;
}

/** Apaga do Mailpit todas as mensagens enviadas para `address`. */
export async function deleteMailpitMessagesTo(request, address) {
    const search = await request.get(`${mailpit}/api/v1/search`, { params: { query: `to:"${address}"`, limit: '500' } });
    const ids = ((await search.json()).messages ?? []).map((m) => m.ID);

    if (ids.length > 0) {
        await request.delete(`${mailpit}/api/v1/messages`, { data: { IDs: ids } });
    }
}
