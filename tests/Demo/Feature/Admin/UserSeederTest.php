<?php

declare(strict_types=1);

use App\Models\User;
use Faker\Factory as FakerFactory;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Demo\Database\Seeders\UserSeeder;

// Lacuna apontada pelo QA: o banco nascia com 2 contas e a paginação/filtros
// do /admin não tinham o que exercitar.

it('semeia pelo menos 40 usuários realistas', function () {
    $this->seed(UserSeeder::class);

    expect(User::query()->count())->toBeGreaterThanOrEqual(UserSeeder::QUANTIDADE);

    $user = User::query()->latest('id')->first();

    expect($user->name)->not->toBeEmpty()
        ->and($user->email)->toContain('@')
        ->and($user->codigo_publico)->toStartWith('USR-');
});

it('produz variedade para o filtro de status do admin, com todo e-mail já confirmado', function () {
    $this->seed(UserSeeder::class);

    // Massa semeada nasce verificada: com a verificação exigida, conta
    // semeada sem e-mail confirmado ficaria presa no aviso e as chaves de API
    // semeadas para ela não autenticariam.
    expect(User::query()->where('status', UserStatus::Blocked->value)->count())->toBeGreaterThan(0)
        ->and(User::query()->whereNull('email_verified_at')->count())->toBe(0);
});

it('espalha as datas de criação pelos últimos 90 dias', function () {
    $this->seed(UserSeeder::class);

    $datas = User::query()->pluck('created_at')->map(fn ($d) => $d->toDateString())->unique();

    expect($datas->count())->toBeGreaterThan(10);

    expect(User::query()->where('created_at', '<', now()->subDays(91))->count())->toBe(0);
});

it('é idempotente: rodar de novo não duplica ninguém', function () {
    $this->seed(UserSeeder::class);
    $primeiro = User::query()->count();

    $this->seed(UserSeeder::class);

    expect(User::query()->count())->toBe($primeiro);
});

it('não toca nas contas demo', function () {
    config()->set('ui.demo_login.email', 'demo@tws.dev');

    $demo = User::factory()->create(['email' => 'demo@tws.dev', 'name' => 'Cliente Demo']);

    $this->seed(UserSeeder::class);

    expect($demo->fresh()->name)->toBe('Cliente Demo');
});

it('a massa semeada faz a paginação do admin ter mais de uma página', function () {
    $this->seed(UserSeeder::class);

    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertOk()
        ->assertCountTableRecords(UserSeeder::QUANTIDADE + 1);
});

// Regressão da instabilidade da rodada paralela (F8c): o Faker sorteia pelo
// mt_rand GLOBAL, e todo gerador do Faker, ao ser destruído, chama mt_srand()
// sem semente. Um gerador descartado antes (em ciclo: só a coleta de ciclos o
// destrói) sendo coletado NO MEIO da montagem da lista mudava o resto dela —
// e a segunda rodada do seeder criava gente nova. Aqui a coleta é provocada
// em cada ponto da montagem (o lixo com um Faker dentro é deixado a N
// "raízes" do limite que dispara a coleta): a lista tem de sair igual.
it('a lista é a mesma mesmo se um Faker descartado for coletado no meio da montagem', function () {
    $referencia = array_keys(UserSeeder::linhas());
    $mudou = [];

    for ($folga = 0; $folga < 3000; $folga += 10) {
        gc_collect_cycles();

        $descartado = FakerFactory::create('pt_BR');
        unset($descartado);

        $gc = gc_status();

        for ($i = 0, $faltam = max(0, $gc['threshold'] - $gc['roots'] - $folga); $i < $faltam; $i++) {
            $ciclo = new stdClass;
            $ciclo->eu = $ciclo;
            unset($ciclo);
        }

        if (array_keys(UserSeeder::linhas()) !== $referencia) {
            $mudou[] = $folga;

            break;
        }
    }

    expect($mudou)->toBe([])
        ->and($referencia)->toHaveCount(UserSeeder::QUANTIDADE)
        ->and(gc_enabled())->toBeTrue();
});
