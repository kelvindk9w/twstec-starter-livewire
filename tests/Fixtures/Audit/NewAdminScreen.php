<?php

declare(strict_types=1);

// Fixture do teste de arquitetura da trilha de auditoria: uma "tela nova" do
// /admin, escrita SEM nenhuma linha de auditoria, no namespace do painel. Ela
// existe para provar que quem registra é a base (AdminAudit), não a tela.
// Não fica em app/ (não é tela de verdade) e é carregada só pelo teste.

namespace App\Filament\AuditFixture;

use Livewire\Component;
use Twstec\Kit\Demo\Catalog\Models\Product;

final class NewAdminScreen extends Component
{
    public function archiveOld(string $uuid): void
    {
        $product = Product::query()->where('uuid', $uuid)->firstOrFail();

        $product->forceFill(['title' => $product->title.' (arquivado)'])->save();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
