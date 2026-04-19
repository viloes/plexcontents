FROM php:8.2-fpm-alpine

WORKDIR /var/www/html

RUN set -eux; \
    apk add --no-cache nginx su-exec sqlite-libs; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev; \
    docker-php-ext-install pdo_sqlite; \
    apk del .build-deps; \
    rm -rf /tmp/*

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY --chown=www-data:www-data . /var/www/html/

RUN mkdir -p /run/nginx /var/log/nginx /var/lib/nginx/tmp \
    /var/www/html/data /var/www/html/tautulli-exports /storage/log \
    && chown -R www-data:www-data /var/www/html /storage/log

RUN set -eux; \
    sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf; \
    sed -i 's|^;*clear_env = .*|clear_env = no|' /usr/local/etc/php-fpm.d/www.conf; \
    sed -i 's|^;*catch_workers_output = .*|catch_workers_output = yes|' /usr/local/etc/php-fpm.d/www.conf; \
    sed -i 's|^;*decorate_workers_output = .*|decorate_workers_output = no|' /usr/local/etc/php-fpm.d/www.conf

RUN cat > /etc/nginx/nginx.conf <<'EOF'
user  nginx;
worker_processes auto;

error_log /var/log/nginx/error.log warn;
pid /run/nginx/nginx.pid;

events { worker_connections 1024; }

http {
  include /etc/nginx/mime.types;
  default_type application/octet-stream;
  sendfile on;
  keepalive_timeout 65;
  client_max_body_size 64m;

  server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;

    location / {
      try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      fastcgi_pass 127.0.0.1:9000;
      fastcgi_index index.php;
    }

    location ~ /\.ht {
      deny all;
    }
  }
}
EOF

RUN cat > /usr/local/bin/docker-entrypoint.sh <<'EOF'
#!/bin/sh
set -eu

DATA_DIR="/var/www/html/data"
DB_FILE="$DATA_DIR/database.sqlite"
LOG_DIR="/storage/log"

mkdir -p "$DATA_DIR" "$LOG_DIR" /run/nginx /var/lib/nginx/tmp

chown -R www-data:www-data "$DATA_DIR" "$LOG_DIR" /var/www/html || true
chmod 775 "$DATA_DIR" "$LOG_DIR" || true
[ -f "$DB_FILE" ] && chmod 664 "$DB_FILE" || true

# Importante: php-fpm master como root para evitar fallo de error_log en /proc/self/fd/2
php-fpm -D

exec nginx -g 'daemon off;'
EOF

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]