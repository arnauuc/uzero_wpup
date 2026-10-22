# Development image for the Laravel orchestrator (app + horizon).
# Dev only: production runs on the tool's VM (php-fpm + systemd), not in this image.
FROM php:8.4-cli-bookworm

ARG WPUP_UID=1000
ARG WPUP_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip rsync openssh-client postgresql-client \
        libpq-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl pcntl zip bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN groupadd --gid "${WPUP_GID}" wpup \
    && useradd --uid "${WPUP_UID}" --gid "${WPUP_GID}" --create-home --shell /bin/bash wpup \
    && mkdir -p /app \
    && chown wpup:wpup /app

ENV COMPOSER_HOME=/home/wpup/.composer

WORKDIR /app
USER wpup

EXPOSE 8000
