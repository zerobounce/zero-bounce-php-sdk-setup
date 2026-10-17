# ZeroBounce PHP SDK – test image (PHP 8.2 + Composer)
FROM php:8.2-cli-alpine

RUN apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-interaction --no-progress

USER 65532:65532

CMD ["vendor/bin/phpunit", "--colors=always"]
