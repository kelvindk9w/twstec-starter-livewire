# shellcheck shell=sh
# =============================================================================
# scripts/lib/selo.sh — o SELO DA VERIFICAÇÃO LOCAL (opcional, desligado por
# padrão). Não é executável sozinho: o scripts/verificar, o scripts/promover e
# o hook pre-push o carregam com `.`.
#
# O selo é um commit status do GitHub no SHA exato (contexto
# `verificacao-local`, `success`), publicado pelo `scripts/verificar --selo`
# quando tudo passa — sem gastar minutos do GitHub Actions. O hook pre-push
# (ligado por scripts/instalar-hooks) recusa enviar commit sem ele, e o
# scripts/promover só leva adiante, por fast-forward, commit com ele. No
# servidor, um ruleset pode exigir o mesmo status nas branches protegidas.
#
# CONFIGURAÇÃO — variável de ambiente ou, sem ela, a linha do .env do projeto
# (nenhuma é obrigatória):
#   VERIFICACAO_CONTEXTO      o contexto do status (padrão verificacao-local)
#   VERIFICACAO_BRANCHES      a cadeia de promoção, da primeira à última
#                             (padrão "desenvolvimento sandbox producao")
#   VERIFICACAO_E2E_BRANCHES  as branches que só recebem commit com o selo do
#                             E2E (`scripts/verificar --selo --e2e`); padrão: a
#                             última da cadeia
#   VERIFICACAO_PUBLICADORES  logins do GitHub aceitos como autor do selo na
#                             promoção, separados por espaço; vazio = qualquer
#                             um com permissão de escrita no repositório (só
#                             quem tem pode publicar status)
#   VERIFICACAO_REPO          dono/repositório no GitHub; padrão: o do remoto
#                             origin
#
# Precisa de: git e o GitHub CLI (`gh`) com login (`gh auth login`).
# POSIX sh: roda no Linux, no macOS, no WSL e no Git Bash.
# =============================================================================

# shellcheck disable=SC2034 # usada pelo scripts/promover
SELO_MARCA_E2E='E2E: sim'
# O GitHub só aceita status em commit que ele já tem: um commit que ainda não
# subiu vai por um instante para esta referência técnica (fora de refs/heads:
# não é branch, nenhuma regra de branch se aplica), recebe o status e a
# referência é apagada. O status fica no SHA e aparece quando o commit chega
# a uma branch.
SELO_REF_TECNICA='refs/verificacao'
SELO_TAB=$(printf '\t')

selo_erro() { printf '✗ %s\n' "$*" >&2; }
selo_aviso() { printf '! %s\n' "$*" >&2; }

# A raiz do repositório git.
selo_raiz() { git rev-parse --show-toplevel; }

# Um valor de configuração: a variável de ambiente; senão, a última linha
# CHAVE=valor do .env da raiz (sem aspas); senão, o padrão.
selo_config() {
    chave=$1
    padrao=$2
    valor=$(eval "printf '%s' \"\${$chave:-}\"")
    if [ -z "$valor" ] && [ -f "$(selo_raiz)/.env" ]; then
        valor=$(sed -n "s/^$chave=//p" "$(selo_raiz)/.env" | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")
    fi
    printf '%s\n' "${valor:-$padrao}"
}

selo_contexto() { selo_config VERIFICACAO_CONTEXTO verificacao-local; }
selo_branches() { selo_config VERIFICACAO_BRANCHES 'desenvolvimento sandbox producao'; }
selo_branches_e2e() {
    ultima=
    for branch in $(selo_branches); do ultima=$branch; done
    selo_config VERIFICACAO_E2E_BRANCHES "$ultima"
}

# Os 7 primeiros caracteres de um SHA.
selo_curto() { printf '%.7s' "$1"; }

# dono/repositório no GitHub: VERIFICACAO_REPO ou a URL do remoto origin.
selo_repo() {
    repo=$(selo_config VERIFICACAO_REPO '')
    if [ -n "$repo" ]; then printf '%s\n' "$repo"; return 0; fi
    url=$(git remote get-url origin 2>/dev/null) || return 1
    url=${url%.git}
    case "$url" in
        https://github.com/*/*) printf '%s\n' "${url#https://github.com/}" ;;
        git@github.com:*/*) printf '%s\n' "${url#git@github.com:}" ;;
        ssh://git@github.com/*/*) printf '%s\n' "${url#ssh://git@github.com/}" ;;
        *) return 1 ;;
    esac
}

# O gh instalado e com login (sem rede: só a configuração local).
selo_gh_pronto() {
    command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1
}

# A árvore de trabalho igual ao HEAD (nada modificado, nada novo).
selo_arvore_limpa() { [ -z "$(git status --porcelain --untracked-files=all)" ]; }

# O selo mais recente de um SHA no GitHub, numa linha:
#   <estado>\t<autor>\t<descrição>
# estado = success | failure | error | pending | ausente. Sai com 1 se não
# conseguiu consultar (gh ausente, sem rede, sem permissão).
selo_do_github() {
    contexto=$(selo_contexto)
    gh api "repos/$1/commits/$2/statuses?per_page=100" \
        --jq "map(select(.context == \"$contexto\")) | first
              | if . == null then \"ausente\t\t\" else \"\(.state)\t\(.creator.login)\t\(.description // \"\")\" end" \
        2>/dev/null
}

# O campo N (1, 2 ou 3) de uma linha do selo_do_github.
selo_campo() { printf '%s\n' "$1" | cut -d "$SELO_TAB" -f "$2"; }

# Publica o selo `success` no SHA, com a descrição (até 140 caracteres), e
# confere lendo de volta.
selo_publicar() {
    sha=$1
    descricao=$2
    repo=$(selo_repo) || { selo_erro 'o remoto origin não é um repositório do GitHub (ou defina VERIFICACAO_REPO).'; return 1; }
    selo_gh_pronto || { selo_erro "gh ausente ou sem login: rode 'gh auth login'."; return 1; }
    contexto=$(selo_contexto)

    subiu=0
    if ! gh api "repos/$repo/commits/$sha" --silent >/dev/null 2>&1; then
        git push --quiet origin "$sha:$SELO_REF_TECNICA/$sha" || {
            selo_erro 'não consegui enviar o commit ao GitHub para receber o selo.'; return 1; }
        subiu=1
    fi
    ok=0
    gh api -X POST "repos/$repo/statuses/$sha" -f state=success -f context="$contexto" \
        -f description="$descricao" --silent && ok=1
    if [ "$subiu" = 1 ]; then
        git push --quiet origin ":$SELO_REF_TECNICA/$sha" 2>/dev/null \
            || selo_aviso "não consegui apagar $SELO_REF_TECNICA/$sha no GitHub (inofensivo; apague depois)."
    fi
    [ "$ok" = 1 ] || { selo_erro 'o GitHub recusou o status.'; return 1; }

    lido=$(selo_do_github "$repo" "$sha") || { selo_erro 'não consegui reler o selo.'; return 1; }
    [ "$(selo_campo "$lido" 1)" = success ] || { selo_erro "selo relido com estado inesperado: $lido"; return 1; }
    printf 'Selo publicado: %s = success em %s@%s\n  %s\n' "$contexto" "$repo" "$(selo_curto "$sha")" "$descricao"
}
