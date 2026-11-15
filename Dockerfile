# Версию PHP можно сменить переменной PHP_IMAGE в .env (например php:8.2-apache).
ARG PHP_IMAGE=php:8.0-apache
FROM ${PHP_IMAGE}

# php:8.0-* построен на Debian 11 (bullseye): его поддержка закончилась и пакеты
# переехали на archive.debian.org. На более новых образах этот шаг пропускается.
RUN if grep -q bullseye /etc/os-release; then \
      for f in /etc/apt/sources.list /etc/apt/sources.list.d/*; do \
        [ -f "$f" ] && sed -i \
          -e 's|deb.debian.org/debian-security|archive.debian.org/debian-security|g' \
          -e 's|security.debian.org/debian-security|archive.debian.org/debian-security|g' \
          -e 's|deb.debian.org/debian|archive.debian.org/debian|g' \
          -e '/bullseye-updates/d' "$f"; \
      done; \
      echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    fi

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libicu-dev libzip-dev unzip \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd bcmath intl zip opcache exif \
 && a2enmod rewrite remoteip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-doors.ini
COPY docker/apache/doors.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY composer.json ./
# Зависимости (mPDF, PhpSpreadsheet) обязательны: если установка не удалась, сборка должна остановиться, а не дать неработающий образ
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/photos storage/exports storage/logs storage/backups storage/sessions \
 && chown -R www-data:www-data storage
# Apache стартует от root только для открытия порта 80, рабочие процессы работают как www-data.
EXPOSE 80
