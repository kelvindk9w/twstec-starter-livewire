import { createHmac, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { networkInterfaces } from 'node:os';
import { dotEnv } from './project-env.js';

// =============================================================================
// RECEPTOR DE WEBHOOK do E2E — um servidor HTTP no próprio processo do
// Playwright (nada de rede externa). O worker da fila do projeto, num
// container, alcança-o pelo IP do host do Docker (o gateway de uma rede
// bridge — ou E2E_WEBHOOK_RECEIVER_HOST). Para o projeto aceitar esse
// destino, o .env de DESENVOLVIMENTO libera http e a rede privada do Docker
// (WEBHOOKS_REQUIRE_HTTPS=false, WEBHOOKS_ALLOWED_PRIVATE_NETWORKS) — em
// produção isso é ignorado. Sem essa configuração o spec pula, com o motivo.
//
// A conferência da assinatura é a da documentação (docs/webhooks.md, o
// exemplo em Node): HMAC-SHA256 de "timestamp.corpo", tolerância de 5 min,
// comparação em tempo constante.
// =============================================================================

/** O projeto aceita um receptor na rede do Docker de desenvolvimento? */
export const receiverAllowed = Boolean(dotEnv.WEBHOOKS_ALLOWED_PRIVATE_NETWORKS) && String(dotEnv.WEBHOOKS_REQUIRE_HTTPS ?? 'true').toLowerCase() === 'false';

/** O IPv4 está dentro da faixa CIDR (`172.16.0.0/12`)? */
function inCidr(address, cidr) {
    const [network, bits = '32'] = cidr.trim().split('/');
    const toNumber = (ip) => ip.split('.').reduce((total, part) => total * 256 + Number(part), 0);
    const size = Number(bits);

    if (!/^\d+\.\d+\.\d+\.\d+$/.test(network ?? '') || !Number.isInteger(size) || size < 0 || size > 32) {
        return false;
    }

    const block = 2 ** (32 - size);

    return Math.floor(toNumber(address) / block) === Math.floor(toNumber(network) / block);
}

/**
 * O IP deste host visto de dentro dos containers: o gateway de uma rede
 * bridge do Docker que ESTEJA na faixa liberada no .env
 * (WEBHOOKS_ALLOWED_PRIVATE_NETWORKS) — numa máquina com várias redes do
 * Docker, a primeira bridge pode ser a de outro projeto. Ou
 * E2E_WEBHOOK_RECEIVER_HOST.
 */
export function receiverHost() {
    if (process.env.E2E_WEBHOOK_RECEIVER_HOST) {
        return process.env.E2E_WEBHOOK_RECEIVER_HOST;
    }

    const allowed = String(dotEnv.WEBHOOKS_ALLOWED_PRIVATE_NETWORKS ?? '').split(',').filter((range) => range.trim() !== '');

    for (const [name, addresses] of Object.entries(networkInterfaces())) {
        if (!name.startsWith('br-') && name !== 'docker0') {
            continue;
        }

        const ipv4 = (addresses ?? []).find((address) => address.family === 'IPv4' && !address.internal && allowed.some((range) => inCidr(address.address, range)));

        if (ipv4) {
            return ipv4.address;
        }
    }

    return null;
}

/** Confere o cabeçalho X-Webhook-Signature (o exemplo em Node da documentação). */
export function verifyWebhookSignature(rawBody, header, secret, toleranceSeconds = 300) {
    let timestamp = Number.NaN;
    const signatures = [];

    for (const part of String(header).split(',')) {
        const [key, value = ''] = part.trim().split(/=(.*)/s);

        if (key === 't') {
            timestamp = Number(value);
        } else if (key === 'v1' && value !== '') {
            signatures.push(value);
        }
    }

    if (!Number.isInteger(timestamp) || signatures.length === 0) {
        return false;
    }

    if (Math.abs(Math.floor(Date.now() / 1000) - timestamp) > toleranceSeconds) {
        return false;
    }

    const expected = Buffer.from(createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex'));

    return signatures.some((signature) => {
        const given = Buffer.from(signature);

        return given.length === expected.length && timingSafeEqual(given, expected);
    });
}

/**
 * Sobe o receptor numa porta livre. `status` (mutável) é a resposta que ele
 * dá; `requests` guarda o que chegou (corpo BRUTO e cabeçalhos).
 */
export async function startReceiver() {
    const state = { status: 200, requests: [] };

    const server = createServer((request, response) => {
        const chunks = [];

        request.on('data', (chunk) => chunks.push(chunk));
        request.on('end', () => {
            state.requests.push({ method: request.method, url: request.url, headers: request.headers, body: Buffer.concat(chunks).toString('utf8') });
            response.writeHead(state.status, { 'Content-Type': 'application/json' });
            response.end(state.status < 300 ? '{"received":true}' : '{"error":"receptor do E2E fora do ar"}');
        });
    });

    await new Promise((resolve) => server.listen(0, '0.0.0.0', resolve));

    return {
        state,
        port: server.address().port,
        close: () => new Promise((resolve) => server.close(resolve)),
    };
}
