# DevTools in a container (ADR-0034): PHP, git and Node — Node reads Angular and Express projects with their own
# TypeScript. Run it with the project mounted:
#
#     docker run --rm -v "$PWD:/project" ghcr.io/jul6art/devtools:<version> workflows:inspect --no-ai
#
# The phar is the release's own (devtools.phar, built by the release workflow); built locally:
#     docker build --build-arg PHAR=build/devtools.phar -t devtools .
FROM php:8.6-cli-alpine

ARG PHAR=build/devtools.phar

# PHP 8.6's official images no longer build mbstring in (8.5's did): it is built here — the polyfill the phar carries
# would do, slower — and oniguruma, which it links to, stays.
RUN apk add --no-cache git nodejs oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS oniguruma-dev \
    && docker-php-ext-install mbstring \
    && apk del .build-deps \
    && git config --system --add safe.directory '*'

COPY ${PHAR} /usr/local/bin/devtools

RUN chmod 0755 /usr/local/bin/devtools \
    && printf 'memory_limit=-1\nphar.readonly=1\n' > /usr/local/etc/php/conf.d/devtools.ini

WORKDIR /project

ENTRYPOINT ["devtools"]
CMD ["list"]
