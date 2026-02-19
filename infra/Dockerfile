FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libssl-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install Swoole extension (v5.1)
RUN pecl install swoole-5.1.5 \
    && docker-php-ext-enable swoole

# Install PDO MySQL (for migrations/tooling; hot paths use Swoole coroutine client)
RUN docker-php-ext-install pdo pdo_mysql

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --prefer-dist 2>/dev/null || true

# Copy full source
COPY . .

# Run composer install again with full source
RUN composer install --no-interaction --prefer-dist

EXPOSE 8000

CMD ["php", "bin/fabriq", "serve"]
