FROM php:7.4-cli

RUN apt-get update
RUN apt-get install --no-install-recommends -y git-core unzip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Create app directory
WORKDIR /usr/src/app

COPY composer.json ./
COPY main.php ./

RUN composer install

CMD [ "php", "main.php", "-n" ]
