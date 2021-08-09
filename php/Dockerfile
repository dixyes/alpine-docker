# hyperf/php image
#
# @link     https://www.hyperf.io
# @document https://doc.hyperf.io
# @contact  group@hyperf.io
# @license  https://github.com/hyperf/hyperf/blob/master/LICENSE

ARG ALPINE_VERSION

FROM alpine:$ALPINE_VERSION

ARG ALPINE_VERSION
ARG PHP_VERSION

LABEL maintainer="Hyperf Developers <group@hyperf.io>" version="2.0" license="MIT"

ADD debuggable_php /usr/local/bin/

RUN set -eo pipefail ; \
    # setup suffix
    suffix=${PHP_VERSION%%.*}; \
    # determine if use phpx-json
    if [ x$suffix = 'x7' ];\
    then \
        php_json="php7-json~${PHP_VERSION}"; \
        php_session="php7-session~${PHP_VERSION}"; \
    fi ;\
    apk add --no-cache \
        # Install base packages ('ca-certificates' will install 'nghttp2-libs')
        ca-certificates \
        curl \
        wget \
        tar \
        xz \
        openssl \
        tzdata \
        pcre \
        php${suffix}~${PHP_VERSION} \
        # align phpx-common with phpx version
        php${suffix}-common~${PHP_VERSION} \
        # in php8, php json/session extension is built-in
        ${php_json} \
        ${php_session} \
        php${suffix}-bcmath~${PHP_VERSION} \
        php${suffix}-curl~${PHP_VERSION} \
        php${suffix}-tokenizer~${PHP_VERSION} \
        php${suffix}-ctype~${PHP_VERSION} \
        php${suffix}-dom~${PHP_VERSION} \
        php${suffix}-gd~${PHP_VERSION} \
        php${suffix}-iconv~${PHP_VERSION} \
        php${suffix}-mbstring~${PHP_VERSION} \
        php${suffix}-mysqlnd~${PHP_VERSION} \
        php${suffix}-openssl~${PHP_VERSION} \
        php${suffix}-pdo~${PHP_VERSION} \
        php${suffix}-pdo_mysql~${PHP_VERSION} \
        php${suffix}-pdo_sqlite~${PHP_VERSION} \
        php${suffix}-phar~${PHP_VERSION} \
        php${suffix}-posix~${PHP_VERSION} \
        php${suffix}-sockets~${PHP_VERSION} \
        php${suffix}-sodium~${PHP_VERSION} \
        php${suffix}-sysvshm~${PHP_VERSION} \
        php${suffix}-sysvmsg~${PHP_VERSION} \
        php${suffix}-sysvsem~${PHP_VERSION} \
        php${suffix}-zip~${PHP_VERSION} \
        php${suffix}-xml~${PHP_VERSION} \
        php${suffix}-xmlreader~${PHP_VERSION} \
        php${suffix}-simplexml~${PHP_VERSION} \
        php${suffix}-pcntl~${PHP_VERSION} \
        php${suffix}-opcache~${PHP_VERSION} \
        php${suffix}-pecl-redis \
        php${suffix}-pecl-igbinary \
    && \
    ln -sf /usr/bin/php${suffix} /usr/bin/php && \
    rm -rf /var/cache/apk/* /tmp/* && \
    # validate extensions loading
    { [ x$(php -r '' 2>&1) = "x" ] || exit 1; } && \
    printf "\033[42;37m PHP version is \033[0m\n" && \
    php -v && \
    printf "\033[42;37m PHP modules are \033[0m\n" && \
    php -m && \
    printf "\033[42;37m Build Completed :).\033[0m\n"
