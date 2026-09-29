# Starter Livewire

> **Parte do [TWS Laravel Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit).** O código, as issues e os
> pull requests ficam no monorepo
> [kelvindk9w/tws-laravel-starter-kit](https://github.com/kelvindk9w/tws-laravel-starter-kit) (pasta `starters/livewire`); este
> repositório é o espelho só-leitura publicado a cada versão.
> Documentação: [docs/](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs) · Segurança:
> [SECURITY.md](SECURITY.md) · Licença: MIT ([LICENSE](LICENSE)).

O aplicativo completo do TWS Laravel Starter Kit: painel do usuário em
Livewire, super admin em Filament, API v1, filas, backup e o deploy de
produção em Docker (`docker-compose.prod.yml`). Composer:
`twstec/starter-livewire` (projeto).

## Criar um projeto

Requisitos: PHP 8.4 (extensões `intl`, `bcmath`, `gd`, `pcntl`, `pdo_pgsql`
ou `pdo_sqlite`), Composer 2 e Node 24.

```bash
composer create-project "twstec/starter-livewire:^2.0@beta" meu-app
# ou, com o instalador do Laravel:
laravel new meu-app --using="twstec/starter-livewire:^2.0@beta"
```

O `post-create-project-cmd` cria o `.env`, gera a `APP_KEY` e o pepper das
chaves de API e chama o instalador (`php artisan tws:install`), que num
terminal pergunta os módulos opcionais. O `.env.example` aponta para o
PostgreSQL do Docker de desenvolvimento do monorepo (`DB_HOST=postgres`):
no projeto criado, aponte o `.env` para o seu banco (ou use
`DB_CONNECTION=sqlite`) e rode `php artisan migrate`; depois `npm ci`,
`npm run build` e `composer dev`. Detalhes em
[docs/instalacao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/instalacao.md).

**Produção:** `docker compose -f docker-compose.prod.yml up -d --build`, com o
`.env.prod` a partir do `.env.prod.example`. A imagem do app
(`docker/php/Dockerfile`, target `prod`) instala os pacotes do kit pelo
Composer, como qualquer dependência — ver
[docs/producao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/producao.md).

## No monorepo

Como instalar, rodar e testar está no [README da raiz do repositório](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/README.md);
a documentação por assunto está em [`docs/`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs). O ambiente de
desenvolvimento (`docker-compose.yml`) fica na raiz do repositório, e os
comandos `docker compose` funcionam também daqui de dentro.

**Módulos opcionais.** `twstec/kit-foundation` e `twstec/kit-auth` vêm
sempre; contas e API (`twstec/kit-accounts`), uploads (`twstec/kit-uploads`)
e o `/admin` (`twstec/kit-admin`) são opcionais — escolha com
`php artisan tws:install` (pacote `twstec/kit-installer`, em `require-dev`).
Sem um módulo, as telas, rotas e menus dele somem sozinhos: o aplicativo
pergunta `Twstec\Kit\Foundation\Kit::has()` antes de registrá-los. Ver
[docs/instalacao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/instalacao.md).

A **demonstração** do kit (landings em `/` e `/v2`, vitrine `/ui`, contato,
catálogo e submissões no `/admin`, contas demo e massa fictícia) não mora
aqui: é o pacote [`twstec/kit-demo`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/demo), declarado em
`require-dev` do `composer.json`. Vem no ambiente de desenvolvimento e não vai
para a imagem de produção. Sem ele, `/` mostra a página inicial mínima do
produto (`resources/views/home.blade.php`). Como tirar: [docs/demo.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/demo.md#como-remover).
