#!/bin/sh
# =============================================================================
# Entrypoint da imagem de PRODUÇÃO (containers app, migrate, horizon, scheduler).
#
# A CHAVE DA APLICAÇÃO É PRÉ-REQUISITO, NÃO EFEITO COLATERAL DA SUBIDA.
#
# Até aqui, este script GERAVA uma APP_KEY quando ela vinha vazia e a escrevia
# no `.env` de dentro do container. Parecia conveniência; era perda silenciosa
# de dado. A imagem de produção é a MESMA para quatro serviços (app, migrate,
# horizon, scheduler) e para cada réplica de cada um — então "gerar se faltar"
# produzia uma chave DIFERENTE por container. E como `.env` mora na camada
# gravável do container, a chave também não sobrevivia à recriação: cada
# `up -d --build`, cada restart, cada réplica nova = outra chave.
#
# O que isso quebra, sem NENHUM erro visível na subida: o cast `encrypted` do
# nome do usuário (gravado por um container, ilegível no outro), o cookie de
# sessão (assinado com a chave — o usuário é deslogado ao cair noutro
# container), as URLs assinadas e o pepper do hash das chaves de API, que tem
# fallback para a APP_KEY (chave de API gravada com um pepper nunca mais
# verifica com outro: 401 permanente, e nem APP_PREVIOUS_KEYS resgata).
#
# Dado que deixa de descriptografar é pior que serviço que não sobe: o serviço
# que não sobe é percebido em segundos e não corrompe nada. Por isso, em
# produção, este script FALHA ALTO E PARA (código de saída 78, EX_CONFIG do
# sysexits.h: "erro de configuração") em vez de inventar uma chave.
#
# FORA de produção a conveniência continua — a imagem de produção também é
# usada para testar a stack localmente (APP_ENV=local/staging), e ali não há
# dado real a perder. Duas diferenças em relação ao comportamento antigo: a
# chave é exportada no AMBIENTE do processo (nunca escrita em arquivo, para não
# deixar rastro de chave em disco nem no caminho de produção) e a subida avisa
# em voz alta que aquela chave morre com o container.
#
# DIVISÃO DE TRABALHO com o guard da aplicação (Twstec\Kit\Foundation\Support\CriticalSecrets):
# aqui só se verifica AUSÊNCIA, porque é tudo que o shell precisa saber para
# parar antes de subir o php-fpm. Chave presente mas de EXEMPLO/placeholder, e
# senhas de infraestrutura com valor de placeholder, são reconhecidas no boot da
# aplicação, onde existe vocabulário configurável e log estruturado.
#
# E há uma diferença de ALCANCE entre as duas camadas, de propósito: esta aqui
# roda no `exec` do container, que é sempre um processo que vai servir ou
# processar — então pode recusar sem ressalva. O guard da aplicação também boota
# em `composer install`, `package:discover` e `migrate`, onde a recusa não
# protege ninguém e só derrubaria build e deploy; lá ele avisa em voz alta e
# deixa passar. Ver o cabeçalho do CriticalSecrets.
# =============================================================================
set -e

cd /var/www/html

# Ausência de APP_ENV é tratada como produção: esta imagem existe para
# produção, e na dúvida a resposta segura é a mais restritiva.
APP_ENV_EFFECTIVE="${APP_ENV:-production}"

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    if [ "$APP_ENV_EFFECTIVE" = "production" ]; then
        cat >&2 <<'MSG'
[entrypoint] ABORTANDO: APP_KEY nao definida e APP_ENV=production.

A chave da aplicacao NAO pode ser gerada na subida. Esta imagem e a mesma para
app, migrate, horizon e scheduler (e para cada replica): gerar aqui daria uma
chave DIFERENTE por container, e ela nao sobreviveria ao restart. Consequencia
silenciosa: dados com cast `encrypted` deixam de descriptografar, usuarios sao
deslogados ao cair noutro container, URLs assinadas ficam invalidas e as chaves
de API param de verificar (o pepper do hash tem fallback para a APP_KEY).

COMO CORRIGIR

  1) Gere UMA chave, uma unica vez, num container DESCARTAVEL desta imagem
     (o --entrypoint sh contorna esta verificacao do shell; o guard da
     aplicacao nao recusa comandos de manutencao, e key:generate e um deles):

       docker run --rm --entrypoint sh <imagem> -c 'php artisan key:generate --show'

     Sem container nenhum, o equivalente e:

       openssl rand -base64 32 | sed 's/^/base64:/'

  2) Coloque o valor (a string inteira, com o prefixo `base64:`) como variavel
     de ambiente APP_KEY para TODOS os servicos PHP, no .env.prod do compose ou
     no secret do seu orquestrador (Swarm/Kubernetes/Nomad). NUNCA num `.env`
     dentro do container: a camada gravavel do container e descartada no
     restart, e a chave sumiria junto.

  3) Suba de novo. A MESMA chave tem de chegar a app, migrate, horizon e
     scheduler.

ATENCAO: trocar a APP_KEY de uma instalacao que ja tem dado e IRREVERSIVEL sem
a chave antiga. Se voce esta trocando de chave, declare a anterior em
APP_PREVIOUS_KEYS (lista separada por virgula) para que o dado antigo continue
legivel. Isso NAO vale para as chaves de API: o hash delas usa apenas o pepper
atual, entao defina API_KEYS_HASH_PEPPER como segredo dedicado, independente da
APP_KEY, antes de emitir a primeira chave.

Detalhes: .env.prod.example e docs/producao.md.
MSG
        exit 78
    fi

    echo "[entrypoint] APP_KEY ausente e APP_ENV=${APP_ENV_EFFECTIVE} (nao e producao):" >&2
    echo "[entrypoint] gerando chave EFEMERA so no ambiente deste processo." >&2
    echo "[entrypoint] Ela morre com o container e e DIFERENTE em cada container:" >&2
    echo "[entrypoint] nao use assim com dado que precise sobreviver." >&2

    APP_KEY="$(php artisan key:generate --show --no-interaction)"
    export APP_KEY
fi

# O link public/storage já vem pronto na imagem (o public/ não é gravável pelo
# processo). Só se cria aqui quando falta — rodar sempre imprimia um "ERROR ...
# link already exists" a cada subida, que ensina a ignorar a palavra ERROR.
if [ ! -e public/storage ]; then
    php artisan storage:link --no-interaction >/dev/null 2>&1 || true
fi

# Publica os arquivos estáticos no volume compartilhado com o nginx
# (o nginx de produção não tem o código — recebe só o public/ read-only).
if [ -d /app-public ]; then
    cp -r /var/www/html/public/. /app-public/ 2>/dev/null || true
fi

exec "$@"
