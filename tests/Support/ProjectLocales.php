<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Arr;

/**
 * Os idiomas que o PROJETO tem: cada pasta de `lang/` (fora `vendor/`).
 *
 * O kit entrega pt_BR, en e es, mas um projeto de um idioma só pode apagar
 * os que não usa. A suíte segue o que existe: a paridade de chaves compara só
 * os idiomas presentes, o TestCase limita os idiomas disponíveis aos que têm
 * pasta, e o teste que precisa de um idioma ausente PULA, com o motivo
 * (requireLocales() em tests/Pest.php).
 */
final class ProjectLocales
{
    /**
     * Pastas de idioma em `$langPath`, em ordem alfabética.
     *
     * @return list<string>
     */
    public static function present(?string $langPath = null): array
    {
        $langPath ??= dirname(__DIR__, 2).'/lang';
        $locales = [];

        foreach (glob($langPath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== 'vendor') {
                $locales[] = basename($dir);
            }
        }

        sort($locales);

        return $locales;
    }

    public static function has(string $locale, ?string $langPath = null): bool
    {
        return in_array($locale, self::present($langPath), true);
    }

    /**
     * Os idiomas de `$configured` que o projeto tem, na mesma ordem.
     *
     * @param  list<string>  $configured
     * @return list<string>
     */
    public static function availableAmong(array $configured, ?string $langPath = null): array
    {
        return array_values(array_intersect($configured, self::present($langPath)));
    }

    /**
     * Onde cada idioma presente diverge do de referência: arquivo que falta,
     * chave que falta e chave a mais. Vazio = paridade. Idioma ausente não
     * conta — só o que existe precisa estar completo.
     *
     * @param  list<string>|null  $files  nomes sem extensão (null = todos os da referência)
     * @return list<string>
     */
    public static function divergences(string $langPath, string $reference, ?array $files = null): array
    {
        $problems = [];
        $files ??= array_map(
            fn (string $path): string => basename($path, '.php'),
            glob("{$langPath}/{$reference}/*.php") ?: [],
        );

        foreach (array_diff(self::present($langPath), [$reference]) as $locale) {
            foreach ($files as $file) {
                $path = "{$langPath}/{$locale}/{$file}.php";

                if (! is_file($path)) {
                    $problems[] = "lang/{$locale}/{$file}.php não existe";

                    continue;
                }

                $expected = self::keys("{$langPath}/{$reference}/{$file}.php");
                $actual = self::keys($path);

                foreach (array_diff($expected, $actual) as $key) {
                    $problems[] = "lang/{$locale}/{$file}.php: falta {$key}";
                }

                foreach (array_diff($actual, $expected) as $key) {
                    $problems[] = "lang/{$locale}/{$file}.php: sobra {$key}";
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function keys(string $path): array
    {
        $contents = require $path;

        return array_map('strval', array_keys(Arr::dot(is_array($contents) ? $contents : [])));
    }
}
