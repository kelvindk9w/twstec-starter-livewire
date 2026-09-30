<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A CSP durante o `npm run dev` (servidor de desenvolvimento do Vite).
 *
 * Com o servidor do Vite ligado, a página carrega os módulos, o CSS e as
 * fontes DELE (outra origem, ex.: http://localhost:5173), e o HMR conversa
 * por WebSocket. A CSP estrita recusaria tudo isso. Em vez de o
 * desenvolvedor afrouxar a CSP à mão (e esquecer afrouxada), a origem do
 * servidor — lida do public/hot, que o laravel-vite-plugin cria ao subir e
 * apaga ao descer — entra nas diretivas que ela precisa:
 *
 *   script-src, style-src, font-src, img-src  → a origem
 *   connect-src                               → a origem e o ws:// / wss://
 *
 * Nada além disso: NUNCA 'unsafe-eval' (o cliente do Vite não precisa).
 *
 * Só em APP_ENV=local e só com uma origem válida (esquema http/https, host e
 * porta, sem caminho): fora disso a CSP volta intacta. Em produção o
 * public/hot nem existe (o build é estático) — e, se existisse, seria
 * ignorado.
 */
final class ViteDevServerCsp
{
    /**
     * @var list<string>
     */
    public const DIRECTIVES = ['script-src', 'style-src', 'font-src', 'img-src'];

    public static function extend(mixed $csp, string $environment, string $hotFile): mixed
    {
        if (! is_string($csp) || $csp === '' || $environment !== 'local') {
            return $csp;
        }

        $origin = self::origin($hotFile);

        if ($origin === null) {
            return $csp;
        }

        $socket = (string) preg_replace('#^http#', 'ws', $origin);

        foreach (self::DIRECTIVES as $directive) {
            $csp = self::append($csp, $directive, $origin);
        }

        return self::append($csp, 'connect-src', $origin.' '.$socket);
    }

    /**
     * A origem do servidor do Vite, se o public/hot existe e traz uma origem
     * bem formada.
     */
    public static function origin(string $hotFile): ?string
    {
        if (! is_file($hotFile)) {
            return null;
        }

        $url = trim((string) file_get_contents($hotFile));

        return preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+$#', $url) === 1 ? $url : null;
    }

    private static function append(string $csp, string $directive, string $sources): string
    {
        $pattern = '/(^|;\s*)'.preg_quote($directive, '/').'\s([^;]*)/';

        if (preg_match($pattern, $csp) === 1) {
            return (string) preg_replace($pattern, '$1'.$directive.' $2 '.$sources, $csp, 1);
        }

        return rtrim($csp, '; ').'; '.$directive.' '.$sources;
    }
}
