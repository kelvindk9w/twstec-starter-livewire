<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Identifiers\HasPublicCode;

// Testes do código público legível: PREFIXO-XXXXXX, sem ambiguidade,
// unicidade garantida por constraint UNIQUE + retry de colisão.

class PublicCodeProbe extends Model
{
    use HasPublicCode;

    protected const PUBLIC_CODE_PREFIX = 'TST';

    protected $table = 'public_code_probes';

    protected $fillable = ['codigo_publico'];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::create('public_code_probes', function (Blueprint $table) {
        $table->id();
        $table->string('codigo_publico')->unique();
    });
});

it('gera código no formato PREFIXO-XXXXXX com alfabeto sem ambiguidade', function () {
    $probe = PublicCodeProbe::createWithPublicCodeRetry([]);

    expect($probe->codigo_publico)->toMatch('/^TST-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');
});

it('tenta de novo quando o código colide com a constraint UNIQUE', function () {
    // Força colisão: fixa o primeiro código e gera o mesmo no segundo insert.
    $codigo = 'TST-ABC234';
    PublicCodeProbe::create(['codigo_publico' => $codigo]);

    // Pré-atribui o código duplicado; o retry deve regenerar e persistir.
    $probe = PublicCodeProbe::createWithPublicCodeRetry(['codigo_publico' => $codigo]);

    expect($probe->codigo_publico)
        ->not->toBe($codigo)
        ->toMatch('/^TST-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');
});

// -----------------------------------------------------------------------------
// Retry dentro de transação já aberta (PostgreSQL).
//
// No PostgreSQL a violação de UNIQUE ABORTA a transação inteira: sem um
// savepoint por tentativa, o retry falhava com "current transaction is
// aborted" — e o SQLite, que não aborta nada, nunca mostrou isso. A suíte
// roda no PostgreSQL no CI; em SQLite estes testes passam de qualquer jeito.
// -----------------------------------------------------------------------------

/**
 * Probe que SEMPRE gera o mesmo código: toda tentativa colide.
 */
class AlwaysCollidingPublicCodeProbe extends PublicCodeProbe
{
    public static function generatePublicCode(): string
    {
        return 'TST-FIXO22';
    }
}

it('tenta de novo dentro de uma transação aberta pelo chamador, e a transação segue válida', function () {
    $codigo = 'TST-ABC234';
    PublicCodeProbe::create(['codigo_publico' => $codigo]);

    $probe = DB::transaction(function () use ($codigo): PublicCodeProbe {
        $probe = PublicCodeProbe::createWithPublicCodeRetry(['codigo_publico' => $codigo]);

        // A transação do chamador continua utilizável depois da colisão.
        PublicCodeProbe::createWithPublicCodeRetry([]);

        return $probe;
    });

    expect($probe->codigo_publico)->not->toBe($codigo)
        ->and(PublicCodeProbe::query()->count())->toBe(3);
});

it('esgotadas as tentativas, a colisão sobe e a transação do chamador segue utilizável', function () {
    AlwaysCollidingPublicCodeProbe::create([]);

    DB::transaction(function (): void {
        expect(fn () => AlwaysCollidingPublicCodeProbe::createWithPublicCodeRetry([]))
            ->toThrow(UniqueConstraintViolationException::class);

        // Sem o savepoint, no PostgreSQL esta consulta falharia: a transação
        // teria sido abortada pela primeira colisão.
        expect(PublicCodeProbe::query()->count())->toBe(1);
    });
});
