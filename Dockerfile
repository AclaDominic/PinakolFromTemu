# Stage 1: Build the frontend
FROM node:20 AS frontend-builder

# Set working directory for frontend
WORKDIR /app/frontend

# Install frontend dependencies
COPY frontend/package*.json ./
RUN npm ci || npm install

# Copy frontend source code
COPY frontend/ ./

# Run the build. 
# Note: Based on vite.config.ts, this will output to /app/backend/public
RUN npm run build


# Stage 2: PHP Apache Server
FROM php:8.2-apache

# Install dependencies and PostgreSQL extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install pdo_pgsql pgsql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Update Apache config to use the PORT environment variable and point to public directory
RUN sed -i 's/Listen 80/Listen ${PORT}/g' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf "<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\n" >> /etc/apache2/sites-available/000-default.conf

# Set default port (Render typically sets this automatically)
ENV PORT=8080

# Set working directory to backend
WORKDIR /var/www/html

# Copy backend files
COPY backend/ ./

# Install Laravel dependencies
RUN composer install --no-interaction --no-dev --optimize-autoloader

# Copy frontend build from builder stage into the backend's public directory
COPY --from=frontend-builder /app/backend/public/ ./public/

# Ensure necessary Laravel directories exist and have correct permissions
RUN mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy entrypoint script to the image
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Use the custom entrypoint script
ENTRYPOINT ["docker-entrypoint.sh"]

# Start Apache in the foreground as the default command
CMD ["apache2-foreground"]
