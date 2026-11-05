# Development image for the Laravel orchestrator (app + horizon).
# Dev only: production runs on the tool's VM (php-fpm + systemd), not in this image.
FROM php:8.4-cli-bookworm

ARG WPUP_UID=1000
ARG WPUP_GID=1000

# PostgreSQL 17 client from the PGDG repository (bookworm ships 15, which cannot
# dump the 17 server): the tool's own backup (UZEROWPUP-47) runs pg_dump. The
# signing key is checked against its pinned fingerprint before it is trusted.
ARG PGDG_KEY_FINGERPRINT=B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && curl -fsSL -o /tmp/pgdg.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && gpg --batch --show-keys --with-colons /tmp/pgdg.asc | grep -q "^fpr:::::::::${PGDG_KEY_FINGERPRINT}:" \
    && gpg --batch --dearmor -o /usr/share/keyrings/pgdg.gpg /tmp/pgdg.asc \
    && echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && rm -f /tmp/pgdg.asc \
    && rm -rf /var/lib/apt/lists/*

# age encrypts the tool's backup before it leaves the machine (ADR-0009).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip rsync openssh-client postgresql-client-17 age \
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
