# Test WordPress site mimicking a Plesk subscription (UZEROWPUP-4).
# One image, parametrised by PHP version: docker build --build-arg PHP_VERSION=7.4 ...
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache

# PHP 7.4 images are based on Debian bullseye, whose mirrors moved to archive.debian.org after end of life.
RUN . /etc/os-release && if [ "$VERSION_CODENAME" = "bullseye" ]; then \
        rm -f /etc/apt/sources.list.d/*; \
        echo 'deb http://archive.debian.org/debian bullseye main' > /etc/apt/sources.list; \
        echo 'deb http://archive.debian.org/debian-security bullseye-security main' >> /etc/apt/sources.list; \
        echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    fi

RUN apt-get update && apt-get install -y --no-install-recommends \
        openssh-server curl less unzip procps ca-certificates \
        default-mysql-client \
        libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libicu-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" mysqli gd intl zip exif opcache bcmath \
    && a2enmod rewrite headers \
    && a2dissite 000-default \
    && mkdir -p /run/sshd /etc/ssh/authorized_keys /var/lib/hostkeys /etc/mysql/conf.d \
    && rm -rf /var/lib/apt/lists/*

# Fixture-only client settings: the test DB speaks plain TCP on a private network.
RUN printf '[client]\nskip-ssl\n' > /etc/mysql/conf.d/fixtures.cnf \
    && printf 'memory_limit=512M\nupload_max_filesize=64M\npost_max_size=64M\nsendmail_path=/bin/true\n' > /usr/local/etc/php/conf.d/fixtures.ini

# WP-CLI (pinned release).
ARG WPCLI_VERSION=2.12.0
RUN curl -fsSL -o /usr/local/bin/wp "https://github.com/wp-cli/wp-cli/releases/download/v${WPCLI_VERSION}/wp-cli-${WPCLI_VERSION}.phar" \
    && chmod +x /usr/local/bin/wp && wp --allow-root --version

COPY entrypoint.sh /usr/local/bin/fixture-entrypoint
RUN chmod +x /usr/local/bin/fixture-entrypoint

EXPOSE 22
ENTRYPOINT ["fixture-entrypoint"]
CMD ["apache2-foreground"]
