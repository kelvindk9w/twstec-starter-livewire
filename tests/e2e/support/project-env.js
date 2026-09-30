import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { parseEnv } from 'node:util';

// =============================================================================
// ONDE O E2E RODA: sempre no PRÓPRIO projeto — nunca no ambiente de outro
// projeto da máquina (o E2E cria e apaga pessoas e mensagens).
//
//   site     E2E_BASE_URL; senão a APP_URL do .env do projeto
//   Mailpit  E2E_MAILPIT_URL; senão http://127.0.0.1:<DEV_MAIL_PORT do .env>
//
// O .env é o da pasta de onde o Playwright roda (a raiz do projeto), o mesmo
// que o Docker de desenvolvimento usa; lido com o `util.parseEnv` do Node,
// sem dependência nova. Sem a variável e sem o valor no .env, o E2E PARA com
// a instrução, em vez de adivinhar um endereço.
//
// O Mailpit é chamado por 127.0.0.1: dentro do container do Playwright,
// `<nome>.localhost` pode resolver para ::1, e as portas do Docker de
// desenvolvimento só são publicadas em IPv4 (o navegador tenta o IPv4
// sozinho; o Node, não).
//
// No repositório do kit (o composer.json instala os pacotes por path
// repository — `../../packages/…`), valem os endereços do docker-compose.yml
// da raiz quando o .env não diz. (Por isso e não pela pasta packages/: o
// container do Playwright monta só a pasta do starter.)
// =============================================================================

const root = process.cwd();
const dotEnvPath = resolve(root, '.env');

/** As variáveis do .env do projeto (vazio quando ele não existe). */
export const dotEnv = existsSync(dotEnvPath) ? parseEnv(readFileSync(dotEnvPath, 'utf8')) : {};

/** Um projeto com o Docker de desenvolvimento (o instalador gravou o nome). */
export const dockerProject = Boolean(dotEnv.COMPOSE_PROJECT_NAME);

/** O repositório do próprio kit: pacotes por path repository. */
export const kitRepository = (() => {
    try {
        const composer = JSON.parse(readFileSync(resolve(root, 'composer.json'), 'utf8'));

        return (composer.repositories ?? []).some((repository) => repository.type === 'path' && String(repository.url).startsWith('../../packages/'));
    } catch {
        return false;
    }
})();

const KIT_REPOSITORY_DEFAULTS = { baseUrl: 'http://localhost:8180', mailpitUrl: 'http://localhost:18025' };

function missing(what, variable, key) {
    throw new Error(`E2E: não sei onde está ${what} deste projeto — defina ${variable} ou ${key} no .env da raiz do projeto (a pasta de onde o Playwright roda: ${root}).`);
}

/** Onde o site do projeto responde. */
export const baseUrl =
    process.env.E2E_BASE_URL ||
    dotEnv.APP_URL ||
    (kitRepository ? KIT_REPOSITORY_DEFAULTS.baseUrl : missing('o site', 'E2E_BASE_URL', 'APP_URL'));

/** A API do Mailpit do projeto. */
export const mailpitBaseUrl =
    process.env.E2E_MAILPIT_URL ||
    (dotEnv.DEV_MAIL_PORT ? `http://127.0.0.1:${dotEnv.DEV_MAIL_PORT}` : '') ||
    (kitRepository ? KIT_REPOSITORY_DEFAULTS.mailpitUrl : missing('o Mailpit', 'E2E_MAILPIT_URL', 'DEV_MAIL_PORT'));

/** Credenciais do E2E (as pessoas fixas de tests/e2e/fixtures.php). */
export const userEmail = process.env.E2E_USER_EMAIL ?? 'e2e@example.com';
export const userPassword = process.env.E2E_USER_PASSWORD ?? 'E2eSenhaForte123';
export const adminEmail = process.env.E2E_ADMIN_EMAIL ?? 'admin-e2e@example.com';
export const adminPassword = process.env.E2E_ADMIN_PASSWORD ?? 'E2eAdminSenha123';

/**
 * Os pacotes instalados, pelo registro do Composer (vendor/composer/
 * installed.json) — e não pela pasta em vendor/: no repositório do kit, os
 * pacotes são links para fora da pasta do starter, que o container do
 * Playwright não monta.
 */
const installedPackages = (() => {
    try {
        const registry = JSON.parse(readFileSync(resolve(root, 'vendor/composer/installed.json'), 'utf8'));

        return new Set((registry.packages ?? registry).map((item) => item.name));
    } catch {
        return new Set();
    }
})();

/** Um pacote do kit (`twstec/kit-<módulo>`) está instalado? */
export const installed = (module) => installedPackages.has(`twstec/kit-${module}`);

/** A demonstração do kit está instalada (os specs dela só rodam com ela). */
export const demoInstalled = installed('demo');

/**
 * A trava de isolamento, antes de qualquer teste criar ou apagar algo: num
 * projeto com o Docker de desenvolvimento, sem endereço explícito, o E2E
 * tem de estar apontado para o site e o Mailpit DESTE .env — e o site que
 * responde tem de ser este projeto (o cookie de sessão tem o nome dele).
 * Devolve o motivo da recusa, ou null.
 */
export function isolationProblem(resolvedBaseUrl, resolvedMailpitUrl, cookieNames) {
    if (!dockerProject) {
        return null;
    }

    if (!process.env.E2E_BASE_URL && dotEnv.APP_URL && new URL(resolvedBaseUrl).origin !== new URL(dotEnv.APP_URL).origin) {
        return `o E2E aponta para ${resolvedBaseUrl}, mas o site deste projeto é ${dotEnv.APP_URL} (APP_URL do .env)`;
    }

    if (!process.env.E2E_MAILPIT_URL && dotEnv.DEV_MAIL_PORT && new URL(resolvedMailpitUrl).port !== dotEnv.DEV_MAIL_PORT) {
        return `o E2E lê o Mailpit em ${resolvedMailpitUrl}, mas o deste projeto está na porta ${dotEnv.DEV_MAIL_PORT} (DEV_MAIL_PORT do .env)`;
    }

    if (!process.env.E2E_BASE_URL && cookieNames !== null && dotEnv.SESSION_COOKIE && !cookieNames.includes(dotEnv.SESSION_COOKIE)) {
        return `o site em ${resolvedBaseUrl} não é este projeto: ele não devolveu o cookie de sessão ${dotEnv.SESSION_COOKIE} (SESSION_COOKIE do .env), e sim ${cookieNames.join(', ') || 'nenhum'}`;
    }

    return null;
}
