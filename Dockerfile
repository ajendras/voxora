FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libsqlite3-dev \
    ffmpeg \
    curl \
    && docker-php-ext-install sqlite3 \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

# Download Piper binary
RUN curl -L https://github.com/rhasspy/piper/releases/download/v1.2.0/piper_linux_x86_64.tar.gz -o /tmp/piper.tar.gz && \
    mkdir -p /tmp/piper && \
    tar -xzf /tmp/piper.tar.gz -C /tmp/piper && \
    cp /tmp/piper/piper/piper /usr/local/bin/piper && \
    chmod +x /usr/local/bin/piper && \
    rm -rf /tmp/piper /tmp/piper.tar.gz

# Download example Piper voice (lessac-medium - high quality)
RUN mkdir -p /usr/share/piper && \
    curl -L -o /usr/share/piper/en_US-lessac-medium.onnx \
      https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium/en_US-lessac-medium.onnx && \
    curl -L -o /usr/share/piper/en_US-lessac-medium.onnx.json \
      https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium/en_US-lessac-medium.onnx.json

# Create required directories and set permissions
RUN mkdir -p data storage/audio && \
    chown -R www-data:www-data /var/www/html/data /var/www/html/storage && \
    chmod -R 755 /usr/share/piper

# Apache configuration for front controller (public/index.php)
COPY <<'EOF' /etc/apache2/sites-available/000-default.conf
<VirtualHost *:80>
    DocumentRoot /var/www/html/public
    <Directory /var/www/html/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

EXPOSE 80

CMD ["apache2-foreground"]
