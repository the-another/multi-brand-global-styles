FROM alpine:3.20

# PHP 8.3 toolchain for composer/phpcs/phpunit, Node for the npm-based
# release pipeline (wp-scripts plugin-zip + version scripts).
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
	composer \
	nodejs \
	npm \
	make \
	git \
	zip \
	curl

# The project directory is always bind-mounted to /app at run time, so the
# image deliberately contains no project files (a COPY here would only bloat
# the image and bust the cache on every source change).
WORKDIR /app

CMD ["sh"]
