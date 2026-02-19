FROM php:8.0-apache

RUN apt-get update && apt-get install -y \
    git \
    python3 \
    python3-pip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN pip3 install --no-cache-dir demisto-sdk

# HOME global + carpeta SDK en /tmp
ENV HOME=/tmp
RUN mkdir -p /tmp/.demisto-sdk && chmod -R 777 /tmp/.demisto-sdk

# También crea la carpeta donde a veces intenta escribir (por si HOME no aplica)
RUN mkdir -p /var/www/.demisto-sdk && chown -R www-data:www-data /var/www/.demisto-sdk

# Permisos para Apache
RUN chown -R www-data:www-data /var/www/html

# PHP limits (OJO con los && \)
RUN echo "max_execution_time = 600" > /usr/local/etc/php/conf.d/timeout_custom.ini && \
    echo "upload_max_filesize = 500M" >> /usr/local/etc/php/conf.d/timeout_custom.ini && \
    echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/timeout_custom.ini && \
    echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/timeout_custom.ini && \
    echo "error_reporting = E_ALL & ~E_NOTICE" >> /usr/local/etc/php/conf.d/timeout_custom.ini && \
    echo "max_input_vars = 20000" >> /usr/local/etc/php/conf.d/timeout_custom.ini
