FROM alpine:3.24.1

# Base is pinned to the current latest Alpine release (bump deliberately, not
# via :latest). PHP 8.3 toolchain for composer/phpcs/phpunit — php83 packages
# match the plugin's production PHP target regardless of the distro default.
# Node is for the npm-based release pipeline (wp-scripts plugin-zip + version
# scripts).
RUN apk add --no-cache \
	php83 \
	php83-cli \
	php83-common \
	php83-ctype \
	php83-curl \
	php83-dom \
	php83-fileinfo \
	php83-iconv \
	php83-json \
	php83-mbstring \
	php83-openssl \
	php83-phar \
	php83-session \
	php83-simplexml \
	php83-tokenizer \
	php83-xml \
	php83-xmlreader \
	php83-xmlwriter \
	php83-zip \
	nodejs \
	npm \
	make \
	git \
	zip \
	curl

# Alpine's `composer` package depends on the distro's default PHP (8.5 on
# 3.24+), which would silently run the toolchain on a different PHP series
# than production. Pin the CLI to php83 and install Composer under it.
RUN ln -sf /usr/bin/php83 /usr/local/bin/php && \
	curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php && \
	php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
	rm /tmp/composer-setup.php

# The project directory is always bind-mounted to /app at run time, so the
# image deliberately contains no project files (a COPY here would only bloat
# the image and bust the cache on every source change).
WORKDIR /app

CMD ["sh"]
