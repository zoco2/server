FROM php:8.3-fpm

COPY php.ini /usr/local/etc/php/
COPY docker.conf /usr/local/etc/php-fpm.d/docker.conf
COPY .bashrc /root/

# Copy the entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# ---- apt 源换阿里云（bookworm 是 deb822 格式的 debian.sources）----
RUN sed -i 's|deb.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources \
 && apt-get update

# mix
RUN apt-get install -y --no-install-recommends \
      build-essential zlib1g-dev default-mysql-client curl gnupg procps vim git unzip libzip-dev libpq-dev \
 && docker-php-ext-install zip pdo_mysql pdo_pgsql pgsql bcmath mysqli pcntl

# intl
RUN apt-get install -y --no-install-recommends libicu-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-install intl

# gd
RUN apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
 && docker-php-ext-install gd

# redis（pecl 阿里云无镜像，慢就构建时传 --build-arg http_proxy=...）
RUN pecl install redis && docker-php-ext-enable redis

# pcov
RUN pecl install pcov && docker-php-ext-enable pcov

# Xdebug
# RUN pecl install xdebug \
# && docker-php-ext-enable xdebug \
# && echo ";zend_extension=xdebug" > /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# ---- Node.js / npm / yarn：阿里云 nodesource + npmmirror ----
RUN curl -sL https://mirrors.aliyun.com/nodesource/setup_22.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*
RUN npm config set registry https://registry.npmmirror.com \
 && npm install npm@latest -g \
 && npm install yarn -g \
 && yarn config set registry https://registry.npmmirror.com

# ---- Composer：阿里云 phar + 阿里云 packagist 镜像 ----
RUN curl -sS https://mirrors.aliyun.com/composer/composer.phar -o /usr/local/bin/composer \
 && chmod +x /usr/local/bin/composer \
 && composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV COMPOSER_HOME /composer
ENV PATH $PATH:/composer/vendor/bin
RUN composer config --global process-timeout 3600 


EXPOSE 5173
WORKDIR /var/www

# entrypoint
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]