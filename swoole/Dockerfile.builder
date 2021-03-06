# hyperf/swoole builder image
#
# @link     https://www.hyperf.io
# @document https://doc.hyperf.io
# @contact  group@hyperf.io
# @license  https://github.com/hyperf/hyperf/blob/master/LICENSE

ARG ALPINE_VERSION
ARG PHP_VERSION

FROM hyperf/php:${PHP_VERSION}-alpine-${ALPINE_VERSION}

ARG SWOOLE_FN
ARG PHP_VERSION

# build extension
RUN set -eo pipefail; \
    suffix=${PHP_VERSION%%.*}; \
    # build time dependencies
    apk add --no-cache --virtual .build-deps \
        # libraries
        libstdc++ \
        # build tools
        autoconf \
        file \
        g++ \
        gcc \
        libc-dev \
        make \
        pkgconf \
        re2c \
        libtool \
        automake \
        # headers
        php${suffix}-dev~${PHP_VERSION} \
        php${suffix}-pear~${PHP_VERSION} \
        zlib-dev \
        openssl-dev \
        curl-dev \
        brotli-dev \
    && \
    # download swoole source
    mkdir -p /usr/src/swoole && \
    cd /usr/src && \
    curl -sfSL "https://github.com/swoole/swoole-src/archive/${SWOOLE_FN}.tar.gz" -o swoole.tar.gz && \
    tar -xf swoole.tar.gz -C swoole --strip-components=1 && \
    rm swoole.tar.gz && \
    cd swoole && \
    # build swoole
    phpize${suffix} && \
    ./configure \
        --with-php-config=/usr/bin/php-config${suffix} \
        --enable-openssl \
        --enable-http2 \
        --enable-swoole-curl \
        --enable-swoole-json && \
    mkdir -p /tmp/withdebug/usr/src && \
    cp -r /usr/src/swoole /tmp/withdebug/usr/src/swoole && \
    make -s -j$(nproc) EXTRA_CFLAGS='-g -O2' && \
    for d in /tmp/stripped /tmp/withdebug ; \
    do \
        make install INSTALL_ROOT="$d" && \
        mkdir -p "$d"/etc/php${suffix}/conf.d && \
        echo "extension=swoole.so" > "$d"/etc/php${suffix}/conf.d/50_swoole.ini && \
        echo "swoole.use_shortname = 'Off'" >> "$d"/etc/php${suffix}/conf.d/50_swoole.ini ; \
    done && \
    cd /tmp/stripped && \
    { find . -type f -name "*.so" -exec strip -s {} \; || : ; } &&\
    printf "\033[42;37m Built Swoole is \033[0m\n" && \
    php -dextension=/usr/src/swoole/.libs/swoole.so --ri swoole
