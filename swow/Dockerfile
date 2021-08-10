# hyperf/swow image
#
# @link     https://www.hyperf.io
# @document https://doc.hyperf.io
# @contact  group@hyperf.io
# @license  https://github.com/hyperf/hyperf/blob/master/LICENSE

ARG ALPINE_VERSION
ARG PHP_VERSION
ARG SWOW_VERSION

FROM hyperf/swow:${SWOW_VERSION}-php-${PHP_VERSION}-alpine-${ALPINE_VERSION}-builder as builder

FROM hyperf/php:${PHP_VERSION}-alpine-${ALPINE_VERSION}

LABEL maintainer="Hyperf Developers <group@hyperf.io>" version="2.0" license="MIT"

ARG COMPOSER_VERSION

RUN set -eo pipefail ; \
    suffix=$(php -r 'echo PHP_MAJOR_VERSION;'); \
    # runtime libraries
    apk add --no-cache \
        zlib \
        openssl \
    && \
    # generate configure
    echo "memory_limit=1G" > /etc/php${suffix}/conf.d/00_default.ini && \
    echo "opcache.enable_cli = 'On'" >> /etc/php${suffix}/conf.d/00_opcache.ini && \
    # install composer
    curl --retry 3 -sfSL "https://github.com/composer/composer/releases/download/${COMPOSER_VERSION}/composer.phar" -o /usr/local/bin/composer; \
    chmod 0755 /usr/local/bin/composer && \
    # php info
    printf "\033[42;37m PHP version is \033[0m\n" && \
    php -v && \
    printf "\033[42;37m Used Composer is \033[0m\n" && \
    composer -V && \
    printf "\033[42;37m Build Completed :).\033[0m\n"

# install swow
COPY --from=builder /tmp/stripped /
