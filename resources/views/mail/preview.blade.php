{{-- Galeria de pré-visualização dos e-mails (/mail-preview) — só em dev.

     Usa o layout do SITE (<x-layouts.site>), e não uma página solta: a
     ferramenta interna do kit também é parte do kit. O e-mail em si aparece
     dentro de um <iframe> porque ele traz o próprio <html>, o próprio <style>
     e a própria paleta — embutir isso na página contaminaria os dois lados. --}}
@php
    $links = collect(['light', 'dark']);
@endphp

<x-layouts.site :title="__('mail.preview.title').' · '.platform()->name">
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-10">

        <header class="max-w-2xl">
            <h1 class="font-display text-h1">{{ __('mail.preview.title') }}</h1>
            <p class="mt-3 text-body text-gray-600 dark:text-gray-400">{{ __('mail.preview.subtitle') }}</p>
        </header>

        <div class="mt-8 grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">

            {{-- Coluna 1: os e-mails do catálogo. --}}
            <nav aria-label="{{ __('mail.preview.list_heading') }}" class="lg:sticky lg:top-6 lg:self-start">
                <p class="text-caption font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('mail.preview.list_heading') }}</p>
                <ul class="mt-3 space-y-1">
                    @foreach ($slugs as $slug)
                        <li>
                            <a
                                href="{{ route('mail.preview', ['slug' => $slug, 'lang' => $previewLocale, 'scheme' => $dark ? 'dark' : 'light']) }}"
                                @class([
                                    'block rounded-lg px-3 py-2 text-body transition-colors duration-150 ease-(--ease-out)',
                                    'bg-surface-sunken font-semibold text-gray-900 dark:text-gray-100' => $slug === $current,
                                    'text-gray-600 hover:bg-surface-sunken dark:text-gray-400' => $slug !== $current,
                                ])
                                @if ($slug === $current) aria-current="page" @endif
                            >{{ __('mail.preview.emails.'.$slug) }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            {{-- Coluna 2: controles + o e-mail. --}}
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border border-border bg-surface p-4">

                    <div class="flex items-center gap-2">
                        <span class="text-caption text-gray-500 dark:text-gray-400">{{ __('mail.preview.language') }}</span>
                        @foreach (platform()->availableLocales as $locale)
                            <a
                                href="{{ route('mail.preview', ['slug' => $current, 'lang' => $locale, 'scheme' => $dark ? 'dark' : 'light']) }}"
                                @class([
                                    'rounded-lg px-2.5 py-1 text-caption font-medium transition-colors duration-150 ease-(--ease-out)',
                                    'bg-brand text-brand-foreground' => $locale === $previewLocale,
                                    'text-gray-600 hover:bg-surface-sunken dark:text-gray-400' => $locale !== $previewLocale,
                                ])
                            >{{ __('mail.preview.locales.'.$locale) }}</a>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-caption text-gray-500 dark:text-gray-400">{{ __('mail.preview.scheme') }}</span>
                        @foreach ($links as $scheme)
                            <a
                                href="{{ route('mail.preview', ['slug' => $current, 'lang' => $previewLocale, 'scheme' => $scheme]) }}"
                                @class([
                                    'rounded-lg px-2.5 py-1 text-caption font-medium transition-colors duration-150 ease-(--ease-out)',
                                    'bg-brand text-brand-foreground' => ($scheme === 'dark') === $dark,
                                    'text-gray-600 hover:bg-surface-sunken dark:text-gray-400' => ($scheme === 'dark') !== $dark,
                                ])
                            >{{ __('mail.preview.schemes.'.$scheme) }}</a>
                        @endforeach
                    </div>

                    <div class="ms-auto flex items-center gap-2">
                        <x-button
                            variant="secondary"
                            size="sm"
                            :href="route('mail.preview', ['slug' => $current, 'lang' => $previewLocale, 'scheme' => $dark ? 'dark' : 'light', 'format' => 'html'])"
                            target="_blank"
                        >{{ __('mail.preview.open_html') }}</x-button>
                        <x-button
                            variant="ghost"
                            size="sm"
                            :href="route('mail.preview', ['slug' => $current, 'lang' => $previewLocale, 'scheme' => $dark ? 'dark' : 'light', 'format' => 'text'])"
                            target="_blank"
                        >{{ __('mail.preview.open_text') }}</x-button>
                    </div>
                </div>

                {{-- Assunto: é a primeira coisa que a pessoa lê na caixa de
                     entrada, então também é a primeira aqui. --}}
                <div class="mt-4 rounded-xl border border-border bg-surface p-4">
                    <p class="text-caption uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('mail.preview.subject') }}</p>
                    <p class="mt-1 text-body font-semibold">{{ $email['subject'] }}</p>
                </div>

                {{-- srcdoc, e não src: o kit manda X-Frame-Options: DENY e
                     frame-ancestors 'none' em toda resposta (config/security.php),
                     então o e-mail carregado por URL não entra em iframe nenhum
                     — nem no próprio site. Com srcdoc não há resposta HTTP para
                     ser recusada, e o e-mail continua isolado do CSS da página. --}}
                <iframe
                    title="{{ __('mail.preview.emails.'.$current) }}"
                    srcdoc="{{ $email['html'] }}"
                    class="mt-4 h-[760px] w-full rounded-xl border border-border bg-white"
                ></iframe>

                {{-- Texto puro: metade dos filtros de spam olha para ele e
                     ninguém nunca o vê. Aqui ele fica ao lado do HTML. --}}
                <details class="mt-4 rounded-xl border border-border bg-surface p-4">
                    <summary class="cursor-pointer text-body font-semibold">{{ __('mail.preview.plain_text') }}</summary>
                    <pre class="mt-3 overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-surface-sunken p-4 font-mono text-caption text-gray-700 dark:text-gray-300">{{ $email['text'] }}</pre>
                </details>

                <p class="mt-4 text-caption text-gray-500 dark:text-gray-400">{{ __('mail.preview.mailpit_hint') }}</p>
            </div>
        </div>
    </main>
</x-layouts.site>
