# syntax=docker/dockerfile:1

FROM php:8.2-cli

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PATH="/app/bin:$PATH"

# Install system dependencies and PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        gnupg2 \
        libicu-dev \
        libonig-dev \
        default-mysql-client \
        default-libmysqlclient-dev \
        libzip-dev \
        unzip \
        zlib1g-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl mbstring opcache pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js 18 and Yarn (Classic)
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install --global yarn@1 \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/app-entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
