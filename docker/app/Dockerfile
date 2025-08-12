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
RUN chmod +x bin/build.sh
RUN sh bin/build.sh

# Create logs directory
RUN mkdir -p /app/logs

# Configure logrotate for cron logs
RUN echo '/app/logs/cron.log {\n\
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

# Add cron jobs - create crontab file with both jobs
RUN echo "*/5 * * * * cd /app && /usr/local/bin/php bin/distributter-docker.php >> /app/logs/cron.log 2>&1" > /tmp/crontab && \
    echo "0 2 * * * /usr/sbin/logrotate /etc/logrotate.d/distributter" >> /tmp/crontab && \
    crontab /tmp/crontab && \
    rm /tmp/crontab

# Create startup script
RUN echo '#!/bin/bash\n\
echo "Starting distributter container..."\n\
echo "Distributter will run every minute for testing."\n\
echo "Check logs in /app/logs/cron.log"\n\
echo ""\n\
# Create logs directory if not exists\n\
mkdir -p /app/logs\n\
# Create empty log file if not exists\n\
touch /app/logs/cron.log\n\
# Add initial log entry with timestamp\n\
echo "$(date): Container started, cron will begin shortly" >> /app/logs/cron.log\n\
echo "Starting cron for scheduled runs..."\n\
# Start cron service\n\
service cron start\n\
echo "$(date): Cron service started" >> /app/logs/cron.log\n\
echo "Cron started. Logs will appear in /app/logs/cron.log"\n\
# Keep container running by tailing the log file\n\
tail -f /app/logs/cron.log' > /app/start.sh && chmod +x /app/start.sh

CMD ["/app/start.sh"]
