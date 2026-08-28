# syntax=docker/dockerfile:1.7
#
# FORK: builds this fork the same way upstream builds fireflyiii/core
# (https://dev.azure.com/Firefly-III/_git/MainImage), except the source is this
# checkout instead of the GitHub release zip. That zip ships vendor/ and the
# Vite-built public/build, both gitignored here, so we build them in stages.
#
# Build with .fork/build.sh (it fills in the ARGs). Runtime contract comes from
# fireflyiii/base: user www-data, app at /var/www/html, nginx+php-fpm on :8080,
# /etc/entrypoint.d/11-execute-things.sh runs /usr/local/bin/entrypoint.sh at boot.
#
# BASE_IMAGE is pinned by digest on purpose — fireflyiii/base:latest is a moving
# tag. Bump it as part of /sync-upstream: docker pull fireflyiii/base:latest &&
# docker image inspect fireflyiii/base:latest --format '{{index .RepoDigests 0}}'
ARG BASE_IMAGE=fireflyiii/base@sha256:10de1d5989ae984cd7302ffbdfa6488ed881cf0f169a91d7628cfb2c22c8e10c
ARG NODE_IMAGE=node:24-alpine

# ---------------------------------------------------------------------------
# 1. Frontend: public/build (v2 layout, Vite). The v1 layout's assets are
#    committed under public/v1 and need no build.
# ---------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets
WORKDIR /src
COPY package.json package-lock.json ./
COPY patches ./patches
COPY resources ./resources
RUN mkdir -p public \
 && npm ci --workspace resources/assets/v2 --no-audit --no-fund \
 && npm run build --workspace resources/assets/v2

# ---------------------------------------------------------------------------
# 2. PHP dependencies, resolved on the exact PHP/extension set of the runtime
#    image. Uses composer.lock (upstream regenerates it at release time; we keep
#    ours reproducible). Autoloader is generated in the final stage once the app
#    code is present.
# ---------------------------------------------------------------------------
FROM ${BASE_IMAGE} AS vendor
WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

# ---------------------------------------------------------------------------
# 3. Runtime image.
# ---------------------------------------------------------------------------
FROM ${BASE_IMAGE}
ARG VERSION=dev
ARG ISODATE
ARG GITREVISION=unknown
ENV VERSION=${VERSION} ISODATE=${ISODATE} GITREVISION=${GITREVISION}

HEALTHCHECK --start-period=5m --interval=5s --timeout=3s --retries=3 \
    CMD [ "sh", "-c", "curl --insecure --silent --location --show-error --fail http://localhost:8080$HEALTHCHECK_PATH || exit 1" ]

LABEL org.opencontainers.image.title="Firefly III (fork)" \
      org.opencontainers.image.source="https://github.com/wkennedy/firefly-iii" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.base.name="docker.io/fireflyiii/base" \
      org.opencontainers.image.created="${ISODATE}" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${GITREVISION}"

# Same startup contract as upstream: entrypoint.sh + the two files it prints.
COPY --chmod=755 .fork/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN echo "${VERSION}" > /var/www/counter-main.txt && echo "${ISODATE}" > /var/www/build-date-main.txt

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor
COPY --from=assets --chown=www-data:www-data /src/public/build /var/www/html/public/build

RUN composer dump-autoload --optimize --no-scripts --no-interaction \
 && chmod -R 775 /var/www/html/storage
