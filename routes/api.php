<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Http\Controllers\HealthController;

// Saúde da aplicação (excluída do request log em banco — ver config/security.php
// e docs/logs-lgpd.md). Passa por validação de segurança, headers e rate limit global.
Route::get('/health', HealthController::class)->name('api.health');

// =============================================================================
// API v1.
//
// As rotas da API v1 vêm dos pacotes, no mesmo grupo (prefixo v1,
// autenticação por chave `resolve.tenant`, limite por chave, nomes api.v1.*):
//
// - chaves de API e projetos (/api/v1/api-keys…, /api/v1/projects…): pacote
//   twstec/kit-accounts — ver Twstec\Kit\Accounts\Http\ApiRoutes;
// - upload seguro (POST /api/v1/uploads, escopo uploads:create): pacote
//   twstec/kit-uploads — ver Twstec\Kit\Uploads\Http\UploadRoutes.
//
// Detalhes em docs/api.md e docs/uploads.md.
// =============================================================================
