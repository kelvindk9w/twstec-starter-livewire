<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// =============================================================================
// TRAVA DE ISOLAMENTO DO E2E (tests/e2e/support/project-env): apontado para o
// site ou o Mailpit de OUTRO projeto da máquina, o E2E recusa antes de criar
// ou apagar qualquer coisa — inclusive com E2E_BASE_URL/E2E_MAILPIT_URL
// explícitos. O site é conferido pelo cookie de sessão; o Mailpit, pelo
// rótulo (MP_LABEL = COMPOSE_PROJECT_NAME, no compose.yaml).
//
// Roda a trava de verdade no Node, num projeto de mentira (.env próprio) e
// contra um "Mailpit" de mentira (só a /api/v1/webui).
// =============================================================================

it('recusa o site e o Mailpit de outro projeto, com endereço explícito ou não', function (): void {
    if (! Process::run(['node', '--version'])->successful()) {
        $this->markTestSkipped('Sem Node nesta máquina: a trava do E2E é conferida onde há Node (CI).');
    }

    $project = storage_path('framework/testing/e2e-isolation-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($project);
    File::put($project.'/composer.json', '{}');
    File::put($project.'/.env', implode("\n", [
        'COMPOSE_PROJECT_NAME=acme',
        'APP_URL=http://acme.localhost:8080',
        'DEV_MAIL_PORT=8025',
        'SESSION_COOKIE=acme_session',
    ])."\n");
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($project));

    $script = <<<'JS'
        import { createServer } from 'node:http';

        const [modulePath] = process.argv.slice(1);
        let label = 'acme';
        const server = createServer((request, response) => {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({ Label: label }));
        });
        await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
        const mailpit = `http://127.0.0.1:${server.address().port}`;
        const closed = 'http://127.0.0.1:1';

        const { isolationProblem, mailpitProblem } = await import(modulePath);
        const out = {};

        out.siteOwn = isolationProblem('http://acme.localhost:8080', 'http://127.0.0.1:8025', ['acme_session']);
        out.siteOther = isolationProblem('http://acme.localhost:8080', 'http://127.0.0.1:8025', ['outro_session']);
        out.siteOtherAddress = isolationProblem('http://outro.localhost:8090', 'http://127.0.0.1:8025', null);

        process.env.E2E_BASE_URL = 'http://127.0.0.1:8090';
        process.env.E2E_MAILPIT_URL = mailpit;
        out.explicitSiteOwn = isolationProblem('http://127.0.0.1:8090', mailpit, ['acme_session']);
        out.explicitSiteOther = isolationProblem('http://127.0.0.1:8090', mailpit, ['outro_session']);

        out.mailpitOwn = await mailpitProblem(mailpit);
        label = 'outro';
        out.mailpitOther = await mailpitProblem(mailpit);
        label = '';
        out.mailpitUnlabeled = await mailpitProblem(mailpit);
        out.mailpitDown = await mailpitProblem(closed);

        server.close();
        console.log(JSON.stringify(out));
        JS;

    $result = Process::path($project)->run(['node', '--input-type=module', '-e', $script, base_path('tests/e2e/support/project-env.js')]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    $out = json_decode($result->output(), true);

    expect($out['siteOwn'])->toBeNull()
        ->and($out['siteOther'])->toContain('não é este projeto')
        ->and($out['siteOtherAddress'])->toContain('APP_URL')
        ->and($out['explicitSiteOwn'])->toBeNull()
        ->and($out['explicitSiteOther'])->toContain('não é este projeto') // com E2E_BASE_URL, o cookie de sessão continua conferido
        ->and($out['mailpitOwn'])->toBeNull()
        ->and($out['mailpitOther'])->toContain('não é deste projeto')->toContain('"outro"')
        ->and($out['mailpitUnlabeled'])->toContain('não é deste projeto')
        ->and($out['mailpitDown'])->toContain('não respondeu');
});

it('o compose.yaml dá ao Mailpit o rótulo do projeto', function (): void {
    // No repositório do kit, o compose de desenvolvimento fica em docker/dev/.
    $compose = collect(['compose.yaml', 'docker/dev/compose.yaml'])->map(fn (string $path): string => base_path($path))->first(fn (string $path): bool => is_file($path));

    if ($compose === null) {
        $this->markTestSkipped('Projeto sem o Docker de desenvolvimento (sem compose.yaml).');
    }

    expect(File::get($compose))
        ->toMatch("/mailpit:\\n\\s+image: [^\\n]+\\n(?:\\s+#[^\\n]*\\n)*\\s+environment:\\n\\s+MP_LABEL: '\\$\\{COMPOSE_PROJECT_NAME\\}'/");
});
