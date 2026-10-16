FROM php:8.1-apache
RUN a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && printf 'upload_max_filesize=64M\npost_max_size=64M\ndisplay_errors=Off\nlog_errors=On\n' \
        > /usr/local/etc/php/conf.d/send.ini
