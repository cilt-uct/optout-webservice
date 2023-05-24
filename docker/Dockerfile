
# Use the official PHP image on alpine distro stripped down
FROM php:8-fpm-alpine3.18


COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=node /usr/local/bin/node /usr/local/bin/node
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm


#Working Directory
WORKDIR /var/www/html

#run commands on alpine distro and install stuffies
RUN apk update && apk add \
	build-base \
	vim \
	php-xml \
	openldap-dev \
	npm \
	nodejs --update-cache --repository http://dl-cdn.alpinelinux.org/alpine/edge/main --allow-untrusted

# RUN apt-get install -y apache2

#RUN install 
RUN docker-php-ext-install pdo_mysql ldap \
		&& docker-php-ext-enable  ldap

RUN addgroup -g 1000 -S www && \
	adduser -u 1000 -S www -G www

# USER www

# COPY --chown=www:www . /var/www/html

EXPOSE 9000 