<?php

declare(strict_types=1);

// =============================================================================
// Nomes antigos (1.x) → nomes novos das classes que ficaram NO APLICATIVO
// quando o módulo de autenticação virou o pacote twstec/kit-auth. Por UMA
// versão (2.x); saem na 3.0.
//
// O model de usuário era App\Core\Auth\Models\User e agora é App\Models\User
// (o aplicativo é dono do próprio model). Esse nome antigo pode estar GRAVADO:
//
//   - payload de fila serializado antes da atualização — a notificação de
//     recuperação de senha ou de verificação de e-mail leva o usuário
//     destinatário como referência de model, com o NOME DA CLASSE; o worker
//     que desserializa depois do deploy precisa achar a classe por esse nome;
//   - snapshot de componente Livewire aberto no navegador durante o deploy;
//   - AUTH_MODEL num .env antigo, ou código do projeto que ainda não trocou o
//     `use`.
//
// (O comando `user:make-admin` — App\Core\Auth\Console\MakeAdminUser na 1.x
// e App\Console\Commands\MakeAdminUser antes da 2.0 — é do pacote
// twstec/kit-admin, que traz os dois nomes antigos.)
//
// (A trilha de auditoria NÃO grava o nome da classe: `audit_events.subject_type`
// guarda o nome curto estável, `user` — ver AuditTrail::subjectType.)
//
// O alias é PREGUIÇOSO e vem por último: o Composer e os apelidos dos pacotes
// tentam antes; este só registra o nome antigo como apelido da classe nova —
// a MESMA classe, então `instanceof` e type hints aceitam os dois nomes.
// Carregado pelo composer.json → autoload.files.
// =============================================================================

spl_autoload_register(static function (string $class): void {
    static $moved = [
        'App\\Core\\Auth\\Models\\User' => 'App\\Models\\User',
    ];

    if (isset($moved[$class]) && class_exists($moved[$class])) {
        class_alias($moved[$class], $class);
    }
});
