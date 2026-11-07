# PHP for development and tests: the lowest supported version, with the extensions tansu uses
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine
RUN apk add --no-cache freetype libjpeg-turbo libpng libwebp \
 && apk add --no-cache --virtual .build freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install gd \
 && apk del .build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app
