# ZeroBounce PHP SDK – test image (PHP 8.4, as in the SDK CI matrix, + Composer)
FROM php:8.4-cli-alpine

RUN apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-interaction --no-progress

# The suite writes downloaded files and PHPUnit's result cache into the working
# directory, so the non-root test user owns /app.
RUN chown -R 65532:65532 /app

USER 65532:65532

CMD ["vendor/bin/phpunit", "--colors=always"]
