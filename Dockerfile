FROM php:8.2-apache

# Enable curl extension (usually built-in, but ensure it's there)
RUN docker-php-ext-install curl

# Apache config: listen on the port Render provides
ENV APACHE_LOG_DIR=/var/log/apache2
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf || true

# Copy app
COPY index.php /var/www/html/index.php

# Enable mod_rewrite so /topup and /callback/{id} route through index.php
RUN a2enmod rewrite

# .htaccess to route everything to index.php
RUN echo "RewriteEngine On\n\
RewriteCond %{REQUEST_FILENAME} !-f\n\
RewriteRule ^(.*)\$ index.php [QSA,L]" > /var/www/html/.htaccess

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

EXPOSE 80

# Render injects $PORT at runtime — start Apache listening on it
CMD sh -c "sed -i \"s/80/\$PORT/g\" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground"
