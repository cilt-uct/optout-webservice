# Use the official PHP image on alpine distro stripped down
FROM php:8-fpm-alpine

#Working Directory
WORKDIR /var/www/html

#run commands on alpine distro and install stuffies
RUN apk update && apk add \
	build-base \
	vim 

# RUN apt-get install -y apache2

#RUN install 
RUN docker-php-ext-install pdo_mysql 

RUN addgroup -g 1000 -S www && \
	adduser -u 1000 -S www -G www

USER www

COPY --chown=www:www . /var/www/html

EXPOSE 9000 