<?php

declare(strict_types=1);

use Illuminate\Support\ViewErrorBag;

// =============================================================================
// Helpers globais da INTERFACE (formulários Blade do starter).
// Carregado via composer.json → autoload.files.
//
// Ficam fora de app/Core de propósito: são da camada de telas, não do
// backend — o backend não sabe como um erro de validação é exibido.
// =============================================================================

if (! function_exists('form_error_display')) {
    /**
     * Estratégia efetiva de exibição de erros de validação nos formulários
     * clássicos (config/ui.php → error_display), com override por formulário.
     * Whitelist: inline | summary | toast | both (fora dela → 'inline').
     */
    function form_error_display(?string $override = null): string
    {
        $strategy = $override ?? (string) config('ui.error_display', 'inline');

        return in_array($strategy, ['inline', 'summary', 'toast', 'both'], true) ? $strategy : 'inline';
    }
}

if (! function_exists('field_error')) {
    /**
     * Erro inline de um campo, respeitando a estratégia configurada: retorna
     * '' (campo sem marcação) quando a estratégia é summary/toast — nesses
     * modos o erro aparece apenas no <x-form-errors>. Uso:
     * <x-input name="email" :error="field_error('email')" />.
     */
    function field_error(string $field, ?string $display = null): string
    {
        if (! in_array(form_error_display($display), ['inline', 'both'], true)) {
            return '';
        }

        /** @var ViewErrorBag $errors */
        $errors = view()->shared('errors');

        return $errors->first($field);
    }
}
