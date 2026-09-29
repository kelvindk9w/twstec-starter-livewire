{{--
    Página 429 — limite de requisições (EdgeRateLimit na borda, throttle:sensitive
    nas rotas sensíveis). AUTOSSUFICIENTE DE PROPÓSITO: ela é servida justamente
    quando o cliente está mandando requisições demais, então não carrega CSS/JS
    do build (seria mais requisição contra o mesmo limite), não consulta banco e
    não depende de sessão. Estilo inline (a CSP do kit permite style inline) com
    tema claro/escuro pelo sistema. Idioma: o do visitante (EarlyLocale).

    O botão recarrega o PRÓPRIO documento (`href=""`) em vez de montar uma URL a
    partir do caminho pedido — um caminho como `//outro-site` viraria link para
    fora.
--}}
@php
    $retryAfter = null;

    if (isset($exception) && method_exists($exception, 'getHeaders')) {
        $value = $exception->getHeaders()['Retry-After'] ?? null;
        $retryAfter = is_numeric($value) ? (int) $value : null;
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('security.throttled.title') }} · 429</title>
    <style>
        :root { color-scheme: light dark; --bg: #f7f7f8; --card: #ffffff; --text: #1d1d22; --muted: #5d5d6a; --line: #e4e4ea; --accent: #4f46e5; --accent-text: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f0f13; --card: #17171d; --text: #ececf1; --muted: #a2a2b0; --line: #2a2a33; --accent: #818cf8; --accent-text: #0f0f13; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; background: var(--bg); color: var(--text); font: 16px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 32rem; background: var(--card); border: 1px solid var(--line); border-radius: 1rem; padding: 2rem; }
        .code { margin: 0 0 .5rem; font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        h1 { margin: 0 0 .75rem; font-size: 1.5rem; line-height: 1.25; }
        p { margin: 0 0 1rem; color: var(--muted); }
        a.action { display: inline-block; margin-top: .5rem; padding: .6rem 1.1rem; border-radius: .6rem; background: var(--accent); color: var(--accent-text); text-decoration: none; font-weight: 600; }
        a.action:focus-visible { outline: 3px solid var(--text); outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <p class="code">429 · {{ __('security.throttled.title') }}</p>
        <h1>{{ __('security.throttled.heading') }}</h1>
        <p>{{ __('security.throttled.body') }}</p>
        @if ($retryAfter !== null)
            <p>{{ __('security.throttled.retry', ['seconds' => $retryAfter]) }}</p>
        @endif
        <a class="action" href="">{{ __('security.throttled.action') }}</a>
    </main>
</body>
</html>
