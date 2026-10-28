# syntax=docker/dockerfile:1
# =============================================================================
# Dockerfile Nginx — PRODUÇÃO (o nginx só recebe o public/ publicado pelo app)
#
# Target:
#   prod  → HTTP 80 (redirect) + HTTPS 443 com certificado autoassinado,
#           só para os nomes do site (NGINX_SERVER_NAMES, ou o host da
#           APP_URL); host desconhecido não é atendido. Certificado
#           gerado no build — em produção real, monte certificados válidos
#           em /etc/nginx/certs (server.crt / server.key) via volume.
#
# O DESENVOLVIMENTO não usa esta imagem: o compose.yaml sobe a imagem oficial
# do nginx com docker/dev/nginx.conf.template.
# =============================================================================

FROM nginx:stable-alpine3.24 AS base

# -----------------------------------------------------------------------------
# PRODUÇÃO: TLS autoassinado embutido para a stack subir autocontida.
# Substituir por certificado real via volume em /etc/nginx/certs.
# -----------------------------------------------------------------------------
FROM base AS prod

RUN apk add --no-cache openssl \
    && mkdir -p /etc/nginx/certs \
    && openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout /etc/nginx/certs/server.key \
        -out /etc/nginx/certs/server.crt \
        -subj "/CN=localhost" \
    && chmod 600 /etc/nginx/certs/server.key

# A configuração é um MODELO (os nomes do site vêm do ambiente ao subir — ver
# o cabeçalho do prod.conf): a imagem oficial a grava em conf.d/default.conf.
# O server-names.envsh roda antes (os .envsh do /docker-entrypoint.d são
# lidos pelo entrypoint): os nomes a partir da APP_URL, conferidos.
COPY docker/nginx/prod.conf /etc/nginx/templates/default.conf.template
COPY --chmod=755 docker/nginx/server-names.envsh /docker-entrypoint.d/05-server-names.envsh
RUN rm -f /etc/nginx/conf.d/default.conf
ENV NGINX_ENVSUBST_FILTER=^NGINX_SERVER_NAMES$
