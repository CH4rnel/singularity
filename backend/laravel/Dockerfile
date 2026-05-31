FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git \
    libgmp-dev \
    unzip \
    curl \
    && docker-php-ext-install bcmath gmp \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
