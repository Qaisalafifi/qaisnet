# Dockerfile
FROM php:8.3-apache

WORKDIR /var/www/html

COPY . /var/www/html

RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install zip pdo pdo_mysql

# تثبيت Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# تثبيت الحزم
RUN composer install --no-dev --optimize-autoloader

# إعدادات Apache
EXPOSE 80
CMD ["apache2-foreground"]