FROM cloudron/base:3.0.0@sha256:455c70428723e3a823198c57472785437eb6eab082e79b3ff04ea584faf46e92

RUN mkdir -p /app/code
WORKDIR /app/code

# TODO: update URL to Tachyon release once CI/CD publishes releases
VERSION=3.1.3
RUN wget https://github.com/YOUR_ORG/tachyon/releases/download/v${VERSION}/tachyon-${VERSION}.zip -O /tmp/tachyon.zip && \
    unzip /tmp/tachyon.zip -d /app/code && \
    rm /tmp/tachyon.zip && \
    find /app/code/snappymail -type d -exec chmod 755 {} \; && \
    find /app/code/snappymail -type f -exec chmod 644 {} \; && \
    rm -rf /app/code/data && ln -s /app/data /app/code/data && \
    chown -R www-data:www-data /app/code/snappymail

# configure apache
RUN rm /etc/apache2/sites-enabled/*
RUN sed -e 's,^ErrorLog.*,ErrorLog "|/bin/cat",' -i /etc/apache2/apache2.conf
COPY apache/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf

RUN a2disconf other-vhosts-access-log
ADD apache/tachyon.conf /etc/apache2/sites-enabled/tachyon.conf
RUN echo "Listen 8000" > /etc/apache2/ports.conf

# mod_php config
RUN a2enmod rewrite
RUN crudini --set /etc/php/8.2/apache2/php.ini PHP upload_max_filesize 25M && \
    crudini --set /etc/php/8.2/apache2/php.ini PHP post_max_size 25M && \
    crudini --set /etc/php/8.2/apache2/php.ini Session session.save_path /run/tachyon/sessions && \
    crudini --set /etc/php/8.2/apache2/php.ini Session session.gc_probability 1 && \
    crudini --set /etc/php/8.2/apache2/php.ini Session session.gc_divisor 100

RUN ln -s /app/data/php.ini /etc/php/8.2/apache2/conf.d/99-cloudron.ini && \
    ln -s /app/data/php.ini /etc/php/8.2/cli/conf.d/99-cloudron.ini

ADD start.sh /app/code/start.sh

CMD [ "/app/code/start.sh" ]
