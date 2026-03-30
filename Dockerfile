FROM php:8.3-apache

# 1. تثبيت الاعتمادات وتفعيل مود الـ Rewrite لـ Laravel
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install zip pdo pdo_mysql \
    && a2enmod rewrite

# 2. تغيير مسار الـ DocumentRoot لمجلد public (حل مشكلة 403/500)
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/apache2!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# 3. نسخ ملفات المشروع
COPY . /var/www/html

# 4. تثبيت Composer والحزم
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader


# تغيير ملكية الملفات للمستخدم الخاص بالسيرفر
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache



# إعطاء صلاحيات القراءة والكتابة
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

RUN chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
EXPOSE 80

# 6. تشغيل المايجريشن تلقائياً ثم تشغيل السيرفر

# السطر الأخير في ملف Dockerfile
CMD php artisan config:clear && php artisan route:clear && php artisan storage:link || true && php artisan migrate --force --seed && apache2-foreground

