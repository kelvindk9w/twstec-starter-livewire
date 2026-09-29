<?php

// =============================================================================
// Interface do produto.
//
// (As flags da demonstração do kit — login demo, vitrine /ui e o opt-out de
// produção — vêm do próprio pacote de demonstração, quando instalado, e se
// juntam a este grupo; ver docs/demo.md.)
// =============================================================================

return [

    // Estratégia de exibição de erros de validação nos formulários clássicos
    // (POST + redirect). Override por formulário: <x-form-errors display="…">
    // e field_error('campo', '…').
    //   inline  → erro embaixo de cada campo (prop :error dos inputs)
    //   summary → só o resumo <x-form-errors> no topo, com âncoras p/ os campos
    //   toast   → erros disparam o toast do kit
    //   both    → inline + resumo
    'error_display' => env('UI_ERROR_DISPLAY', 'inline'),

];
