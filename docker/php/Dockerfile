# syntax=docker/dockerfile:1
FROM php:8.3-fpm-bookworm

# System deps + PHP extensions needed by Laravel + this project.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
        default-mysql-client \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli bcmath zip exif pcntl gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer (copied from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Match the host user so bind-mounted files stay writable.
ARG UID=1000
ARG GID=1000
RUN groupmod -g "${GID}" www-data 2>/dev/null || true \
    && usermod -u "${UID}" -g "${GID}" www-data 2>/dev/null || true

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Raise upload limits for track uploads (15MB+ headroom).
RUN { \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=40M'; \
        echo 'memory_limit=512M'; \
    } > /usr/local/etc/php/conf.d/zz-jamdio.ini

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
