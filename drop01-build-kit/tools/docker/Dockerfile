# Local shared-hosting simulation: PHP 8.2 + Apache with .htaccess, same extensions as GoDaddy cPanel
FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libzip-dev unzip \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install gd zip \
 && a2enmod rewrite headers deflate expires \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*
