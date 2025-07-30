FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
    libonig-dev \
    cron \
    python3 \
    python3-pip \
    ffmpeg \
    && docker-php-ext-install \
    zip \
    mbstring \
    bcmath \
    pcntl \
    posix \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install TTS
RUN pip3 install TTS

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php
RUN mv composer.phar /usr/local/bin/composer
RUN chmod +x /usr/local/bin/composer

COPY . /app
WORKDIR /app
RUN chmod +x bin/build.sh
RUN sh bin/build.sh

# Add cron job (every 5 minutes) with stdout output
RUN echo "*/5 * * * * cd /app && php bin/distributter.php" | crontab -

# Create startup script for cron
RUN echo '#!/bin/bash\n\
echo "Starting cron daemon..."\n\
echo "Distributter will run every 5 minutes."\n\
echo "First run will be within 5 minutes."\n\
echo ""\n\
# Run initial sync immediately for testing\n\
echo "Running initial sync..."\n\
cd /app && php bin/distributter.php\n\
echo ""\n\
echo "Starting cron for scheduled runs..."\n\
# Run cron in foreground mode\n\
exec cron -f' > /app/start.sh

RUN chmod +x /app/start.sh

# Start cron and follow logs
CMD ["/app/start.sh"]
