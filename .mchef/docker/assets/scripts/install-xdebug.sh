#!/bin/bash

if php --version | grep "Xdebug"; then
  echo "PHP Xdebug already installed";
  exit 0;
fi
if pecl list | grep "xdebug"; then
    echo "PHP Xdebug already installed but not loaded";
else
    pecl install xdebug;
fi
if [ ! -d "/var/log/xdebug" ]; then
  mkdir -p /var/log/xdebug;
  chmod -R 777 /var/log/xdebug;
fi
extdir=$(ls /usr/local/lib/php/extensions) &&
    {
    echo "zend_extension=/usr/local/lib/php/extensions/$extdir/xdebug.so";
    echo "xdebug.output_dir = /var/log/xdebug";
    echo "xdebug.mode = debug";
    echo "xdebug.start_with_request = trigger";
    echo "xdebug.client_host = host.docker.internal";
    echo "xdebug.client_port = 9003";
    echo "xdebug.show_error_trace = 1";
    } > /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini &&
exit 0;
