<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Foundation\Kit;

/**
 * Agregador do `db:seed`.
 *
 * O produto NÃO tem seeder de dado estrutural (papéis, permissões, planos,
 * configuração): tudo isso vem das migrations e do .env. Por isso, sozinho,
 * este agregador não semeia nada — e em produção `db:seed` corretamente não
 * faz nada.
 *
 * Ponto de extensão: extensões instaladas registram os próprios seeders no
 * container com a tag `database.seeders`, e é aqui que eles rodam. A
 * demonstração do kit registra o dela (contas demo, massa fictícia e o
 * histórico dos dashboards), com as regras de quando pode semear.
 *
 * MODO SISTEMA declarado (contas com membros): um seeder grava em várias
 * contas, e dado de conta sem conta atual é exceção. Os seeders rodam dentro
 * de `Accounts::asSystem('db:seed', …)` e, gravando dado de conta, informam a
 * conta de cada linha. Sem o pacote de contas (twstec/kit-accounts é
 * opcional), não há conta nem modo sistema: os seeders rodam direto.
 *
 * SEM WithoutModelEvents (e isso é decisão, não esquecimento): os models do
 * kit preenchem `uuid` (HasUuids/booted) e `codigo_publico` (HasPublicCode) no
 * evento `creating`. Silenciar os eventos durante o `db:seed` faria os
 * seeders estourarem "null value in column uuid". Se algum seeder precisar
 * de silêncio, o lugar do `WithoutModelEvents` é ELE, não este agregador.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Tag do container com os seeders das extensões instaladas.
     */
    public const EXTENSION_TAG = 'database.seeders';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seed = function (): void {
            foreach (app()->tagged(self::EXTENSION_TAG) as $seeder) {
                $this->call($seeder::class);
            }
        };

        Kit::has('accounts') ? Accounts::asSystem('db:seed', $seed) : $seed();
    }
}
