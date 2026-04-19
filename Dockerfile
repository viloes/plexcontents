FROM php:8.2-apache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Habilitar mod_rewrite para URLs limpias (opcional)
RUN a2enmod rewrite

# Copiar archivos de la aplicación al contenedor
WORKDIR /var/www/html
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Crear directorio para las imágenes exportadas de Tautulli
RUN mkdir -p /var/www/html/tautulli-exports \
    && chown -R www-data:www-data /var/www/html/tautulli-exports

# Instalar extensiones necesarias para SQLite
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Configurar el DocumentRoot a la carpeta public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80