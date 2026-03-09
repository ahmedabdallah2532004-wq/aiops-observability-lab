FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite3

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# The actual code will be mounted as a volume in docker-compose, but we also copy for standalone builds
COPY . /app

# Ensure correct permissions
RUN chown -R www-data:www-data /app

# Expose port
EXPOSE 8000

# Start artisan server
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
