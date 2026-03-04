FROM php:8.3-apache

# ── Habilitar mod_rewrite (necesario para Laravel) ────────────────────────────
RUN a2enmod rewrite

# ── System dependencies ────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpng-dev libonig-dev libxml2-dev libzip-dev \
    libpq-dev \
    && docker-php-ext-install \
    pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ── Composer ───────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Apache: apuntar DocumentRoot a /public ────────────────────────────────────
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# ── Working directory ─────────────────────────────────────────────────────────
WORKDIR /var/www/html

# ── Copy project ──────────────────────────────────────────────────────────────
COPY . .

# ── Permissions antes de composer (package:discover requiere bootstrap/cache) ─
RUN mkdir -p /var/www/html/storage/logs \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# ── Install dependencies (incluye dev deps: Pail está en bootstrap/providers.php) ──
RUN composer install --optimize-autoloader --no-interaction

# ── Entrypoint ────────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
