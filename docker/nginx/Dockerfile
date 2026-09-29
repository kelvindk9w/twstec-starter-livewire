# syntax=docker/dockerfile:1
# =============================================================================
# Dockerfile Nginx — TWS Laravel Starter Kit
#
# Targets:
#   dev   → HTTP simples na porta 80 (host publica em 8080)
#   prod  → HTTP 80 (redirect) + HTTPS 443 com certificado autoassinado
#           gerado no build. Em produção real, monte certificados válidos
#           em /etc/nginx/certs (server.crt / server.key) via volume.
# =============================================================================

FROM nginx:stable-alpine3.24 AS base

# -----------------------------------------------------------------------------
# DESENVOLVIMENTO
# -----------------------------------------------------------------------------
FROM base AS dev

COPY docker/nginx/dev.conf /etc/nginx/conf.d/default.conf

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

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
