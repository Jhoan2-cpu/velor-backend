FROM php:8.3-apache
# El de arriba indicamos que construiremos una imagen basada en php:8.3-apache

# 1) Apache must support Laravel friendly URLs (public/.htaccess)
# Con RUN decimos que se ejecute el comando dentro de la imagen base
# a2enmod rewrite es un comando de apache para habilitar mod_rewrite
# rewrite es un modulo que sirve para reescribir URLs
# Qué significa reescribir URLs?. Significa que 
RUN a2enmod rewrite

# 2) System packages + PHP extensions for Laravel + PostgreSQL
# 
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    postgresql-client \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    bcmath \
    exif \
    pcntl \
    gd \
    zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 3) Composer inside container
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Configuración de Apache para que sirva /public
# 4) Apache should serve /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 5) Working directory > nos movemos a /var/www/html -BÁSICAMENTE ESTABLECEMOS EL DIRECTORIO DE TRABAJO DENTRO DEL CONTENEDOR
WORKDIR /var/www/html

# 6) Copy full application source
COPY . .

# 7) Create writable folders before Composer scripts run
RUN mkdir -p storage/logs \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# 8) Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Copia un script de arranque, le damos  permisos para ejecutarlo y luego lo ejecuta como primer proceso que se ejecutará cuando el contenedor arranque:
# CON ENTRYPOINT le decimos que el script entrypoint.sh será el que se ejecutará al arrancar el contenedor
# EL ARCHIVO ENTRYPOINT.SH SE ENCUENTRA EN LA CARPETA DOCKER Y NO SE NECESITA CREARLO MANUALMENTE.
# 9) Container startup script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# CON EXPOSE INDICAMOS QUE EL CONTENEDOR ESCUCHA EN EL PUERTO 80, EL PUERTO 80 ES EL PUERTO POR DEFECTO DE APACHE, APACHE DENTRO DEL CONTENEDOR ESCUCHA EN 80
EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
