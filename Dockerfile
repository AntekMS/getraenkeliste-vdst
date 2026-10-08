FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libonig-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install mysqli pdo pdo_mysql mbstring intl zip opcache gd exif

# Zeitzone: System und PHP
ENV TZ=Europe/Berlin
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && echo "date.timezone=Europe/Berlin" > /usr/local/etc/php/conf.d/zeitzone.ini

RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq=0" >> /usr/local/etc/php/conf.d/opcache.ini

RUN echo "memory_limit=256M" >> /usr/local/etc/php/conf.d/performance.ini \
    && echo "max_execution_time=60" >> /usr/local/etc/php/conf.d/performance.ini \
    && echo "post_max_size=10M" >> /usr/local/etc/php/conf.d/performance.ini \
    && echo "upload_max_filesize=10M" >> /usr/local/etc/php/conf.d/performance.ini

# mod_rewrite für CodeIgniter-URLs
RUN a2enmod rewrite

COPY . /var/www/html/

RUN if [ -f /var/www/html/composer.json ]; then \
        cd /var/www/html && composer install --no-dev --optimize-autoloader; \
    fi

# writable/ ist per .dockerignore ausgeschlossen (kommt zur Laufzeit per Bind-Mount)
RUN mkdir -p /var/www/html/writable \
    && chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/ \
    && chmod -R 775 /var/www/html/writable/

RUN echo '<Directory /var/www/html/>\n\
    Options FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
<Directory /var/www/html/public/>\n\
    Options FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/codeigniter.conf \
    && a2enconf codeigniter

# DocumentRoot = public/ (Front-Controller)
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

RUN echo "ServerName localhost:8090" >> /etc/apache2/apache2.conf

# Macht writable/ beim Start für www-data beschreibbar (Bind-Mount auf Linux-Hosts, z. B. Pi)
# und startet dann wie das Basis-Image. Eigene Kopie mit +x, weil das Ausführungsrecht
# aus einem Windows-Checkout nicht zuverlässig im Build-Kontext ankommt.
COPY docker/entrypoint.sh /usr/local/bin/getraenkeliste-entrypoint
RUN chmod 755 /usr/local/bin/getraenkeliste-entrypoint

ENTRYPOINT ["getraenkeliste-entrypoint"]
# Ein eigener ENTRYPOINT setzt das geerbte CMD zurück – daher explizit.
CMD ["apache2-foreground"]

EXPOSE 80
