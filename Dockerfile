FROM php:8.2-cli
WORKDIR /app
COPY . /app
RUN echo "allow_url_fopen = On" > /usr/local/etc/php/conf.d/allow_url_fopen.ini
CMD ["php", "-S", "0.0.0.0:80"]
