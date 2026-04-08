FROM php:8.3-cli-alpine

WORKDIR /app

RUN docker-php-ext-install curl

COPY . .

EXPOSE 8080

CMD ["sh", "-lc", "php -S 0.0.0.0:${PORT:-8080} -t backend/public backend/router.php"]
