# DevTools in a container (ADR-0034): PHP, git and Node — Node reads Angular and Express projects with their own
# TypeScript. Run it with the project mounted:
#
#     docker run --rm -v "$PWD:/project" ghcr.io/jul6art/devtools:<version> workflows:inspect --no-ai
#
# The phar is the release's own (devtools.phar, built by the release workflow); built locally:
#     docker build --build-arg PHAR=build/devtools.phar -t devtools .
FROM php:8.5-cli-alpine

ARG PHAR=build/devtools.phar

RUN apk add --no-cache git nodejs \
    && git config --system --add safe.directory '*'

COPY ${PHAR} /usr/local/bin/devtools

RUN chmod 0755 /usr/local/bin/devtools \
    && printf 'memory_limit=-1\nphar.readonly=1\n' > /usr/local/etc/php/conf.d/devtools.ini

WORKDIR /project

ENTRYPOINT ["devtools"]
CMD ["list"]
