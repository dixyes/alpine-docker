# Hyperf alpine docker 构建系统

[EN](Readme.EN.md)

这个仓库提供alpine docker镜像构建系统

## 镜像种类

### PHP基础镜像

hyperf/php:sometags 镜像提供了php和以下扩展:

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

如果你需要更多扩展，使用`apk install`安装或者联系我们来添加

PHP镜像和基于PHP镜像的其他镜像包含[debuggable_php](#debuggable_php-%E5%91%BD%E4%BB%A4)命令用来开启调试符号和安装gdb

### Swoole/Swow 镜像

这些镜像安装了特定版本的Swoole/Swow

Swoole镜像的配置在/etc/php${suffix}/conf.d/50_swoole.ini:

```ini
extension=swoole.so
swoole.use_shortname = 'Off'
```

Swow镜像的配置在/etc/php${suffix}/conf.d/50_swow.ini:

```ini
extension=swow.so
```

### Swoole/Swow 可调试镜像

Swoole/Swow可调试镜像提供了Swoole/Swow镜像相同的功能，同时提供了Swoole/Swow的调试符号

Swoole/Swow可调试镜像相较于无调试符号的版本体积稍大

## 命名方案

### Hyperf php 基础镜像

```plain
hyperf/php:<php version>[-<distro kind>[-<distro version>]]
hyperf/php:<distro kind>[-<distro version>]
hyperf/php:latest
```

其中：

- php version是PHP版本号，如"8.0"/"7.4"或"8.0.1"/"7.4.20"
- distro kind是发行版名称，如"alpine"
- distro version是发行版的版本号如alpine的"3.14"/"edge"

#### Hyperf php 基础镜像的别名

```plain
hyperf/php:latest => hyperf/php:alpine (默认发行版)
hyperf/php:8.0 => hyperf/php:8.0.9 (假定最新的PHP 8.0稳定版本是8.0.9)
hyperf/php:8.0.9 => hyperf/php:8.0.9-alpine (默认发行版)
hyperf/php:8.0.9-alpine => hyperf/php:8.0.9-alpine-3.14 (假定最新的alpine稳定发行版是3.14)
hyperf/php:alpine => hyperf/php:alpine-3.14 (假定最新的alpine稳定发行版是3.14)
hyperf/php:alpine-3.14 => hyperf/php:8.0-alpine-3.14 (假定最新的PHP稳定版本是8.0)
hyperf/php:8.0-alpine-3.14 => hyperf/php:8.0.9-alpine-3.14 (假定最新的PHP 8.0稳定版本是8.0.9)
```

这意味着你可以用以下别名来使用hyperf/php:8.0.9-alpine-3.14镜像:

- hyperf/php:8.0.9-alpine
- hyperf/php:8.0.9
- hyperf/php:8.0-alpine-3.14
- hyperf/php:8.0-alpine
- hyperf/php:alpine-3.14
- hyperf/php:alpine
- hyperf/php:latest

因此你可以用hyperf/php:8.0-alpine来获取当前最新的稳定版alpine和当前最新的PHP稳定版本

### Hyperf swoole 镜像

```plain
hyperf/swoole:<swoole version>[-php-<php version>[-<distro kind>[-<distro version>]]]
hyperf/swoole:php-<php version>[-<distro kind>[-<distro version>]]
hyperf/swoole:latest
```

其中：

- swoole version是swoole的版本号，如"4.7"/"4.6"或"4.7.0"/"4.6.7"
- php version是PHP版本号，如"8.0"/"7.4"或"8.0.1"/"7.4.20"
- distro kind是发行版名称，如"alpine"
- distro version是发行版的版本号如alpine的"3.14"/"edge"

#### Hyperf swoole 镜像的别名

别名类似于php

如果最新的版本为

- swoole 4.6.7
- php 8.0.9
- alpine 3.14

hyperf/swoole:4.6.7-php-8.0.8-alpine-3.13会有以下别名

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

### Hyperf Swoole 可调试镜像

```plain
hyperf/swoole:<swoole version>-php-<php version>-<distro kind>-<distro version>-debuggable
```

#### Hyperf Swoole 可调试镜像的别名

为了对齐版本，Hyperf Swoole 可调试镜像没有别名

### Hyperf Swow 镜像和可调试镜像

和Swoole镜像命名一致

## debuggable_php 命令

以上所有镜像都包含了`debuggable_php`命令用于开启调试符号和安装gdb，这个命令必须以root权限运行

用法:

```plain
debuggable_php enable [--force-gdbinit]
debuggable_php disable [--keep] [--force-gdbinit]
debuggable_php status
enable:
    安装gdb和php{7,8}-dbg包，获取并安装源码到/usr/src，生成.gdbinit到root的homedir
    如果已经存在gdbinit，指定--force-gdbinit来覆盖它
disable:
    移除gdb和php{7,8}-dbg包，
    如果gdbinit被修改，指定--force-gdbinit来删除它，否则不删除它
    指定--keep保留下载的源码
status:
    显示状态
```

## 自动构建

镜像将会在GitHub Workflow中自动构建

Alpine版本包括

```plain
3.14
edge
3.13
3.12
3.11
3.10
```

PHP版本是以上镜像中提供的版本

Swoole版本包括以下分支中最新的patch版本

```plain
4.7
4.6
4.5
```

Swoole也提供master分支的版本

Swow版本为0.1的最新patch和develop/ci分支.

## 开放源代码许可

提醒我填上这段
