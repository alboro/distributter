FROM php:8.4-cli

# Установка системных пакетов одним слоем
RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
    libonig-dev \
    cron \
    procps \
    htop \
    git \
    logrotate \
    && docker-php-ext-install \
    zip \
    mbstring \
    bcmath \
    pcntl \
    posix \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Установка Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /app
WORKDIR /app

# Перемещаем start.sh в bin/ и даем права на выполнение
RUN cp docker/app/start.sh start.sh
RUN chmod +x bin/build.sh
RUN chmod +x start.sh

RUN sh bin/build.sh

# Create logs directory
RUN mkdir -p logs

# Configure logrotate for cron logs
RUN echo '/app/var/logs/cron.log {\n\
    daily\n\
    rotate 7\n\
    compress\n\
    delaycompress\n\
    missingok\n\
    notifempty\n\
    create 644 root root\n\
    postrotate\n\
        # Nothing needed here as we are just appending to the log\n\
    endscript\n\
}' > /etc/logrotate.d/distributter

# Add cron jobs - изменяем на каждую минуту для тестирования и указываем полный путь к PHP
RUN echo "* * * * * cd /app && CRON_RUN=1 /usr/local/bin/php bin/distributter-docker.php >> /app/var/logs/cron.log 2>&1" > /tmp/crontab && \
    echo "0 2 * * * /usr/sbin/logrotate /etc/logrotate.d/distributter" >> /tmp/crontab && \
    crontab /tmp/crontab && \
    rm /tmp/crontab

CMD ["/bin/bash", "/app/start.sh"]
