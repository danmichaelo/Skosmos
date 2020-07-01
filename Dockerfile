FROM php:7.4-apache

RUN apt-get update && \
  apt-get -y install locales libicu-dev && \
  for locale in en_GB en_US fi_FI fr_FR sv_SE nb_NO nn_NO; do \
    echo "${locale}.UTF-8 UTF-8" >> /etc/locale.gen ; \
  done && \
  locale-gen

RUN a2enmod rewrite
RUN docker-php-ext-install gettext intl
