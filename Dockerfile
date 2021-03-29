FROM php:7.4-apache

RUN apt-get update \
    && \
        apt-get -y install \
            locales \
            libicu-dev \
            libxslt-dev \
            zip \
            unzip \
            libzip-dev \
            zlib1g-dev \
            git \
    && \
        for locale in en_GB en_US fi_FI fr_FR sv_SE nb_NO nn_NO; do \
            echo "${locale}.UTF-8 UTF-8" >> /etc/locale.gen ; \
        done \
    && \
        locale-gen

RUN a2enmod rewrite

RUN docker-php-ext-configure opcache --enable-opcache \
    && \
        docker-php-ext-install \
            opcache \
            gettext \
            intl \
            xsl \
            zip

# Overwrite default Apache vhost config
COPY 000-default.conf /etc/apache2/sites-available/

COPY skosmos-entrypoint /usr/local/bin/

ENTRYPOINT ["/usr/local/bin/skosmos-entrypoint"]
CMD ["apache2-foreground"]
