# ZeroBounce PHP SDK – test image (PHP 8.2 + Composer)
FROM php:8.2-cli-alpine

RUN apk add --no-cache git unzip && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY . .

RUN composer install --no-interaction --no-progress

CMD ["vendor/bin/phpunit", "--colors=always"]
