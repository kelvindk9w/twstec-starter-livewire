<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Twstec\Kit\Auth\PasswordPolicy;
use Twstec\Kit\Demo\Database\Seeders\DemoAdminSeeder;
use Twstec\Kit\Demo\Database\Seeders\DemoUserSeeder;

// =============================================================================
// Política de senha de login (Twstec\Kit\Auth\PasswordPolicy): configurável por
// .env, padrão = só tamanho mínimo, demais regras prontas para ligar. Também
// cobre o bug de QA #6 — a senha demo precisa passar na política do próprio
// app, e a mensagem de erro precisa sair no idioma ativo.
// =============================================================================

function politicaDeSenha(array $overrides = []): void
{
    config()->set('auth.password_rules', array_merge([
        'min_length' => 6,
        'letters' => false,
        'mixed_case' => false,
        'numbers' => false,
        'symbols' => false,
        'uncompromised' => false,
    ], $overrides));
}

function validaSenha(string $senha): Illuminate\Validation\Validator
{
    return Validator::make(['password' => $senha], ['password' => PasswordPolicy::rule()]);
}

it('por padrão só exige o tamanho mínimo (6): senha simples passa, curta não', function () {
    politicaDeSenha();

    expect(validaSenha('abcdef')->passes())->toBeTrue()
        ->and(validaSenha('abcde')->passes())->toBeFalse();
});

it('o padrão de fábrica é 6 caracteres e nenhuma regra extra ligada', function () {
    // "Fábrica" = o que uma instalação nova recebe: o .env.example (copiado
    // para .env no clone) e as chaves que o config expõe. O .env de quem
    // roda os testes pode ter outro valor — por isso não se lê config() aqui.
    $exemplo = file_get_contents(base_path('.env.example'));

    expect($exemplo)->toContain("\nAUTH_PASSWORD_MIN=6\n")
        ->and($exemplo)->toContain('#AUTH_PASSWORD_LETTERS=true')
        ->and($exemplo)->toContain('#AUTH_PASSWORD_MIXED_CASE=true')
        ->and($exemplo)->toContain('#AUTH_PASSWORD_NUMBERS=true')
        ->and($exemplo)->toContain('#AUTH_PASSWORD_SYMBOLS=true')
        ->and($exemplo)->toContain('#AUTH_PASSWORD_UNCOMPROMISED=true');

    expect(array_keys((array) config('auth.password_rules')))
        ->toBe(['min_length', 'letters', 'mixed_case', 'numbers', 'symbols', 'uncompromised']);
});

it('cada regra extra passa a ser cobrada ao ser ligada', function (string $regra, string $senhaQueFalha, string $senhaQuePassa) {
    politicaDeSenha([$regra => true]);

    expect(validaSenha($senhaQueFalha)->passes())->toBeFalse("[$regra] deveria recusar '$senhaQueFalha'")
        ->and(validaSenha($senhaQuePassa)->passes())->toBeTrue("[$regra] deveria aceitar '$senhaQuePassa'");
})->with([
    'letras' => ['letters', '123456', 'abc123'],
    'maiúscula e minúscula' => ['mixed_case', 'abcdef', 'Abcdef'],
    'número' => ['numbers', 'abcdef', 'abcde1'],
    'símbolo' => ['symbols', 'abcdef', 'abcde!'],
]);

it('o tamanho mínimo configurado é respeitado', function () {
    politicaDeSenha(['min_length' => 12]);

    expect(validaSenha('abcdefghijk')->passes())->toBeFalse()
        ->and(validaSenha('abcdefghijkl')->passes())->toBeTrue();
});

it('a dica dos formulários descreve só o que está ativo', function () {
    app()->setLocale('pt_BR');

    politicaDeSenha();
    expect(PasswordPolicy::hint())->toBe('mínimo de 6 caracteres');

    politicaDeSenha(['min_length' => 10, 'mixed_case' => true, 'numbers' => true]);
    expect(PasswordPolicy::hint())
        ->toBe('mínimo de 10 caracteres, com maiúscula e minúscula, ao menos um número');
});

it('a dica existe nos 3 idiomas', function (string $locale) {
    app()->setLocale($locale);
    politicaDeSenha(['symbols' => true]);

    expect(PasswordPolicy::hint())->not->toContain('auth.password_policy');
})->with(['pt_BR', 'en', 'es']);

it('as credenciais demo semeadas passam mesmo com TODAS as regras ligadas', function (string $chave) {
    politicaDeSenha(['min_length' => 12, 'letters' => true, 'mixed_case' => true, 'numbers' => true]);

    $validator = validaSenha((string) config($chave));

    expect($validator->passes())->toBeTrue(
        "A senha demo de [{$chave}] não passa na política endurecida do app: "
        .implode(' ', $validator->errors()->all())
    );
})->with(['ui.demo_login.password', 'ui.demo_admin.password'])->group('demo');

it('o registro público usa a política: com regra ligada, senha fraca é recusada', function () {
    politicaDeSenha(['numbers' => true]);

    $this->post('/register', [
        'name' => 'Pessoa',
        'email' => 'pessoa@example.com',
        'password' => 'semnumero',
        'password_confirmation' => 'semnumero',
    ])->assertSessionHasErrors('password');

    politicaDeSenha();

    $this->post('/register', [
        'name' => 'Pessoa',
        'email' => 'pessoa@example.com',
        'password' => 'semnumero',
        'password_confirmation' => 'semnumero',
    ])->assertSessionHasNoErrors();
});

it('o usuário demo semeado consegue logar com a senha da config', function () {
    config()->set('ui.demo_login.enabled', true);

    $this->seed(DemoUserSeeder::class);

    $this->post('/login', [
        'email' => config('ui.demo_login.email'),
        'password' => config('ui.demo_login.password'),
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
})->group('demo');

it('o admin demo semeado consegue logar com a senha da config', function () {
    config()->set('ui.demo_login.enabled', true);

    $this->seed(DemoAdminSeeder::class);

    $this->post('/login', [
        'email' => config('ui.demo_admin.email'),
        'password' => config('ui.demo_admin.password'),
    ])->assertRedirect();

    $this->assertAuthenticated();
})->group('demo');

it('a mensagem de senha fraca sai no idioma ativo, nunca em inglês fixo', function (string $locale, string $trecho) {
    app()->setLocale($locale);
    politicaDeSenha(['mixed_case' => true]);

    $validator = validaSenha('senhafraca');

    expect($validator->passes())->toBeFalse();

    expect(implode(' ', $validator->errors()->all()))->toContain($trecho);
})->with([
    ['pt_BR', 'maiúscula'],
    ['en', 'uppercase'],
    ['es', 'mayúscula'],
]);
