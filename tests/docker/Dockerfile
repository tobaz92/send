FROM php:8.1-apache
# MultiViews comme sur OVH mutualisé : /admin/upload/x y est servi par admin/upload.php
RUN a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && sed -ri 's/Options Indexes FollowSymLinks/Options Indexes FollowSymLinks MultiViews/' /etc/apache2/apache2.conf \
    && printf '<Directory /var/www/>\n    MultiviewsMatch Any\n</Directory>\n' > /etc/apache2/conf-enabled/multiviews.conf \
    && printf 'upload_max_filesize=64M\npost_max_size=64M\ndisplay_errors=Off\nlog_errors=On\n' \
        > /usr/local/etc/php/conf.d/send.ini
