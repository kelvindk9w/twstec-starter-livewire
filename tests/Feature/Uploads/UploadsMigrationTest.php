<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Services\AccountService;

// =============================================================================
// MIGRAÇÃO DOS UPLOADS PARA AS CONTAS no esquema COMPLETO do starter (SQLite
// e PostgreSQL), com dados sintéticos como a 1.x/F8b gravava: web
// (`user_id`), API (`tenant_uuid` de conta pessoal e de empresa), fotos de
// perfil em uso (inclusive a enviada por outra pessoa e a que veio pela API)
// e órfãos (dono excluído, uuid de ninguém, sem dono). Ida, idempotência,
// volta e ida de novo. A prova no banco de desenvolvimento (números, arquivos
// antigos servidos) está no relatório da fase.
// =============================================================================

function migracaoDosUploadsDoStarter(): object
{
    return require base_path('vendor/twstec/kit-uploads/database/migrations/2026_09_28_000001_move_uploads_to_accounts.php');
}

function uploadDaVersaoAnterior(array $dono): int
{
    return (int) DB::table('uploads')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'codigo_publico' => 'UPL-'.strtoupper(Str::random(6)),
        'disk' => 'local',
        'path' => 'uploads/'.Str::uuid().'.pdf',
        'original_name' => 'antigo.pdf',
        'mime' => 'application/pdf',
        'size' => 10,
        'sha256' => str_repeat('a', 64),
        'status' => 'stored',
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
        ...$dono,
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function retratoDosUploads(array $colunas): array
{
    return DB::table('uploads')->orderBy('id')->get(['id', ...$colunas])
        ->mapWithKeys(fn (object $linha): array => [(int) $linha->id => array_map(
            fn (mixed $valor): mixed => is_numeric($valor) && ! is_string($valor) ? (int) $valor : $valor,
            array_diff_key((array) $linha, ['id' => true]),
        )])->all();
}

it('no esquema do starter: web, API, fotos e órfãos para o destino certo — ida, idempotência, volta e ida', function (): void {
    [$ana, $bruno, $carla, $dora, $excluida] = User::factory()->count(5)->create()->all();
    $empresa = app(AccountService::class)->createAccount('Empresa', $bruno);
    $pessoal = fn (User $pessoa): int => (int) DB::table('accounts')->where('personal_user_id', $pessoa->id)->value('id');

    // Uma pessoa excluída antes da fase: a conta pessoal saiu junto; o
    // upload da API dela ficou com um uuid que não aponta para ninguém.
    $uuidDaExcluida = (string) $excluida->uuid;
    $excluida->delete();

    migracaoDosUploadsDoStarter()->down();

    $ids = [
        'web' => uploadDaVersaoAnterior(['user_id' => $ana->id]),
        'api_pessoal' => uploadDaVersaoAnterior(['tenant_uuid' => $ana->uuid]),
        'api_empresa' => uploadDaVersaoAnterior(['tenant_uuid' => $empresa->uuid]),
        'foto_da_ana' => uploadDaVersaoAnterior(['user_id' => $ana->id]),
        'foto_da_carla_pelo_bruno' => uploadDaVersaoAnterior(['user_id' => $bruno->id]),
        'foto_da_dora_pela_api' => uploadDaVersaoAnterior(['tenant_uuid' => $dora->uuid]),
        'da_excluida_pela_api' => uploadDaVersaoAnterior(['tenant_uuid' => $uuidDaExcluida]),
        'sem_dono' => uploadDaVersaoAnterior([]),
    ];

    DB::table('users')->where('id', $ana->id)->update(['avatar_upload_id' => $ids['foto_da_ana']]);
    DB::table('users')->where('id', $carla->id)->update(['avatar_upload_id' => $ids['foto_da_carla_pelo_bruno']]);
    DB::table('users')->where('id', $dora->id)->update(['avatar_upload_id' => $ids['foto_da_dora_pela_api']]);

    $antes = retratoDosUploads(['user_id', 'tenant_uuid']);
    $fotos = DB::table('users')->whereNotNull('avatar_upload_id')->orderBy('id')->pluck('avatar_upload_id', 'id')->all();

    migracaoDosUploadsDoStarter()->up();

    $esperado = [
        $ids['web'] => [$pessoal($ana), $ana->id, false, false],
        $ids['api_pessoal'] => [$pessoal($ana), null, false, false],
        $ids['api_empresa'] => [(int) $empresa->id, null, false, false],
        $ids['foto_da_ana'] => [null, $ana->id, true, false],
        $ids['foto_da_carla_pelo_bruno'] => [null, $bruno->id, true, false],
        $ids['foto_da_dora_pela_api'] => [$pessoal($dora), null, false, false],
        $ids['da_excluida_pela_api'] => [null, null, false, true],
        $ids['sem_dono'] => [null, null, false, true],
    ];

    $destino = fn (): array => DB::table('uploads')->orderBy('id')->get(['id', 'account_id', 'created_by', 'personal', 'orphaned_at'])
        ->mapWithKeys(fn (object $l): array => [(int) $l->id => [
            $l->account_id === null ? null : (int) $l->account_id,
            $l->created_by === null ? null : (int) $l->created_by,
            (bool) $l->personal,
            $l->orphaned_at !== null,
        ]])->all();

    expect($destino())->toBe($esperado)
        ->and(Schema::hasColumn('uploads', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumn('uploads', 'tenant_uuid'))->toBeFalse()
        ->and(DB::table('users')->whereNotNull('avatar_upload_id')->orderBy('id')->pluck('avatar_upload_id', 'id')->all())->toBe($fotos)
        ->and(User::query()->findOrFail($ana->id)->avatarUrl())->toBeString()
        ->and(User::query()->findOrFail($carla->id)->avatarUrl())->toBeString()
        ->and(User::query()->findOrFail($dora->id)->avatarUrl())->toBeString();

    // Chave estrangeira da conta: a conta sai, os uploads dela saem junto.
    expect(collect(Schema::getForeignKeys('uploads'))->pluck('foreign_table', 'columns.0')->all())
        ->toMatchArray(['account_id' => 'accounts', 'created_by' => 'users']);

    // Idempotente.
    migracaoDosUploadsDoStarter()->up();
    expect($destino())->toBe($esperado);

    // Volta: o dono no jeito antigo (o uuid da excluída não é guardado).
    migracaoDosUploadsDoStarter()->down();

    $esperadoNaVolta = $antes;
    $esperadoNaVolta[$ids['da_excluida_pela_api']]['tenant_uuid'] = null;

    expect(retratoDosUploads(['user_id', 'tenant_uuid']))->toEqual($esperadoNaVolta)
        ->and(DB::table('users')->whereNotNull('avatar_upload_id')->orderBy('id')->pluck('avatar_upload_id', 'id')->all())->toBe($fotos);

    // Ida de novo: o mesmo destino.
    migracaoDosUploadsDoStarter()->up();

    expect($destino())->toBe($esperado);
});
