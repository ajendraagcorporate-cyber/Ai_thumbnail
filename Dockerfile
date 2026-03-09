FROM php:8.2-cli

# Install system dependencies + fonts for GD text rendering
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libzip-dev \
    zip \
    unzip \
    fonts-liberation \
    fonts-dejavu-core \
    fontconfig \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && fc-cache -fv \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Create and set permissions for storage directories
RUN mkdir -p storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/logs \
    bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

# Create .env from example if .env doesn't exist (env vars will be injected by Railway)
RUN cp -n .env.example .env 2>/dev/null || true

# Generate app key if not set (Railway will override with env var)
RUN php artisan key:generate --no-interaction 2>/dev/null || true

EXPOSE 8080

# Start script: clear caches and serve
CMD php artisan config:clear \
    && php artisan view:clear \
    && php artisan route:clear \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
