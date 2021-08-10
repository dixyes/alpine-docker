# Hyperf alpine docker build system

[CN](Readme.CN.md)

This repository holds build system for hyperf alpine-based images.

## Image varients

### Base php image

hyperf/php:sometags images is php base image for building swoole and swow, it have extensions enabled:

```plain
[PHP Modules]
bcmath
Core
ctype
curl
date
dom
filter
gd
hash
iconv
igbinary
json
libxml
mbstring
mysqlnd
openssl
pcntl
pcre
PDO
pdo_mysql
pdo_sqlite
Phar
posix
readline
redis
Reflection
session
SimpleXML
sockets
sodium
SPL
standard
sysvmsg
sysvsem
sysvshm
tokenizer
xml
xmlreader
Zend OPcache
zip
zlib

[Zend Modules]
Zend OPcache

```

If you need more extensions, use `apk install` to install it or contact us to add it.

PHP images and images based on it have [debuggable_php](#debuggable_php-command) command to enable debug symbol for debugging.

### Swoole/Swow image

These images built and installed specified version of Swoole/Swow on them.

Swoole image have ini config in /etc/php${suffix}/conf.d/50_swoole.ini:

```ini
extension=swoole.so
swoole.use_shortname = 'Off'
```

Swow image have ini config in /etc/php${suffix}/conf.d/50_swow.ini:

```ini
extension=swow.so
```

### Swoole/Swow debuggable image

Swoole/Swow debuggable images provide same functionality as Swoole/Swow images do, they also includes debug symbol for Swoole/Swow extension.

Size of debuggable images is slightly bigger then non-debuggable varients.

## Naming scheme

### Hyperf php base images

```plain
hyperf/php:<php version>[-<distro kind>[-<distro version>]]
hyperf/php:<distro kind>[-<distro version>]
hyperf/php:latest
```

Which:

- php version is a version code like "8.0"/"7.4" or "8.0.1"/"7.4.20"
- distro kind is a string described base image distro like "alpine"
- distro version is a version code lile "3.14"/"edge" for alpine

#### Alias for Hyperf php base images

```plain
hyperf/php:latest => hyperf/php:alpine (default distro)
hyperf/php:8.0 => hyperf/php:8.0.9 (assuming latest stable version of php 8.0 is 8.0.9)
hyperf/php:8.0.9 => hyperf/php:8.0.9-alpine (default distro)
hyperf/php:8.0.9-alpine => hyperf/php:8.0.9-alpine-3.14 (assuming latest stable version of alpine is 3.14)
hyperf/php:alpine => hyperf/php:alpine-3.14 (assuming latest stable version of alpine is 3.14)
hyperf/php:alpine-3.14 => hyperf/php:8.0-alpine-3.14 (assuming latest stable version of php is 8.0)
hyperf/php:8.0-alpine-3.14 => hyperf/php:8.0.9-alpine-3.14 (assuming latest stable version of php 8.0 is 8.0.9)
```

That meaning a image built as hyperf/php:8.0.9-alpine-3.14 will have tag alias like:

- hyperf/php:8.0.9-alpine
- hyperf/php:8.0.9
- hyperf/php:8.0-alpine-3.14
- hyperf/php:8.0-alpine
- hyperf/php:alpine-3.14
- hyperf/php:alpine
- hyperf/php:latest

So that user can use some tag like hyperf/php:8.0-ubuntu to pinning images using php 8.0 branch and ubuntu base

### Hyperf swoole images

```plain
hyperf/swoole:<swoole version>[-php-<php version>[-<distro kind>[-<distro version>]]]
hyperf/swoole:php-<php version>[-<distro kind>[-<distro version>]]
hyperf/swoole:latest
```

Which:

- swoole version is a version code like "4.7"/"4.6" or "4.7.0"/"4.6.7"
- php version is a version code like "8.0"/"7.4" or "8.0.1"/"7.4.20"
- distro kind is a string described base image distro like "alpine"
- distro version is a version code lile "3.14"/"edge" for alpine

#### Alias for Hyperf swoole images

They have a similar alias scheme like php:

If latest stable is

- swoole 4.6.7
- php 8.0.9
- alpine 3.14

hyperf/swoole:4.6.7-php-8.0.8-alpine-3.13 will aliased as

```plain
latest
4.6
4.6.7
4.6-php-8.0
4.6-php-8.0.9
4.6-php-8.0-alpine
4.6-php-8.0-alpine-3.14
4.6-php-8.0.9-alpine
4.6-php-8.0.9-alpine-3.14
4.6.7-php-8.0
4.6.7-php-8.0.9
4.6.7-php-8.0-alpine
4.6.7-php-8.0-alpine-3.14
4.6.7-php-8.0.9-alpine
4.6.7-php-8.0.9-alpine-3.14
php-8.0
php-8.0.9
php-8.0-alpine
php-8.0-alpine-3.14
php-8.0.9-alpine
php-8.0.9-alpine-3.14
```

### Hyperf swoole debuggable images

```plain
hyperf/swoole:<swoole version>-php-<php version>-<distro kind>-<distro version>-debuggable
```

#### Alias for Hyperf swoole debuggable images

No alias for debuggable images for version alignment

### Hyperf Swow images and debuggable images

same as Swoole images.

## debuggable_php command

All images mentioned here provides a command line tool `debuggable_php` can be used to control php debug symbol installation. This command needs running as root user.

Usage:

```plain
debuggable_php enable [--force-gdbinit]
debuggable_php disable [--keep] [--force-gdbinit]
debuggable_php status
enable:
    Install gdb and php{7,8}-dbg package. Fetch sources and generate
    gdbinit for root. if gdbinit is provided, and --force-gdbinit
    is specified, overwrite it, otherwise not modify it.
disable:
    Remove gdb and php{7,8}-dbg package. Keep sources if "--keep" is provided,
    otherwise remove them. if gdbinit is modified, and --force-gdbinit
    is specified, remove it, otherwise not modify it.
status:
    Show status
```

## Automatically build

These images is automatically built in github workflows daily.

Alpine versions will be one of

```plain
3.14
edge
3.13
3.12
3.11
3.10
```

PHP versions is latest versions provided in these images.

Swoole versions will be last patch version in these branches

```plain
4.7
4.6
4.5
```

and master branch

Swow versions will be last patch version in 0.1 branch and develop/ci branch.

## License

remind me to fill this
