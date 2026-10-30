
# PHP 8.3 FPM Base Image
FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    zip \
    libicu-dev \
    libonig-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure GD extension
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp

# Install PHP extensions
RUN docker-php-ext-install -j"$(nproc)" \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    intl \
    zip \
    opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# PHP-FPM listens on port 9000
EXPOSE 9000

CMD ["php-fpm"]
