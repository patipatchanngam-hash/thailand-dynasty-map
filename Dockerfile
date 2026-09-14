FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Bake the app into the image (docker-compose mounts ./Frontend over this for local development).
COPY Frontend/ /var/www/html/

# Hosts like Render pass the port to listen on in $PORT; fall back to 80 locally.
CMD sed -i "s/^Listen .*/Listen ${PORT:-80}/" /etc/apache2/ports.conf \
    && sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf \
    && exec apache2-foreground
