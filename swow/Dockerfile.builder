# hyperf/swow builder image
#
# @link     https://www.hyperf.io
# @document https://doc.hyperf.io
# @contact  group@hyperf.io
# @license  https://github.com/hyperf/hyperf/blob/master/LICENSE

ARG ALPINE_VERSION
ARG PHP_VERSION

FROM hyperf/php:${PHP_VERSION}-alpine-${ALPINE_VERSION}

ARG SWOW_FN
ARG PHP_VERSION

# build extension
RUN set -eo pipefail; \
    suffix=${PHP_VERSION%%.*}; \
    # build time dependencies
    apk add --no-cache --virtual .build-deps \
        # build tools
        autoconf \
        file \
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
    && \
    # download swow source
    mkdir -p /usr/src/swow && \
    cd /usr/src && \
    curl -sfSL "https://github.com/swow/swow/archive/${SWOW_FN}.tar.gz" -o swow.tar.gz && \
    tar -xf swow.tar.gz -C swow --strip-components=1 && \
    rm swow.tar.gz && \
    cd swow/ext && \
    # build swow
    phpize${suffix} && \
    ./configure \
        --with-php-config=/usr/bin/php-config${suffix} \
        --enable-swow-curl \
        --enable-swow-ssl && \
    mkdir -p /tmp/withdebug/usr/src && \
    cp -r /usr/src/swow /tmp/withdebug/usr/src/swow && \
    make -s -j$(nproc) EXTRA_CFLAGS='-g -O2' && \
    for d in /tmp/stripped /tmp/withdebug ; \
    do \
        make install INSTALL_ROOT="$d" && \
        mkdir -p "$d"/etc/php${suffix}/conf.d && \
        echo "extension=swow.so" > "$d"/etc/php${suffix}/conf.d/50_swow.ini ; \
    done && \
    cd /tmp/stripped && \
    { find . -type f -name "*.so" -exec strip -s {} \; || : ; } &&\
    printf "\033[42;37m Built Swow is \033[0m\n" && \
    php -dextension=/usr/src/swow/ext/.libs/swow.so --ri swow
