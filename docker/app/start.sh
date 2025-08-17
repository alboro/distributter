#!/bin/bash

# Создаем директорию для логов если её нет
mkdir -p /app/var/logs

# Записываем в лог информацию о запуске
echo "$(date): Container started, cron will begin shortly" >> /app/var/logs/cron.log

# Запускаем cron в фоне
echo "Starting cron for scheduled runs..."
service cron start
echo "$(date): Cron service started" >> /app/var/logs/cron.log

# Показываем логи в реальном времени
echo "Starting distributter container..."
echo "Distributter will run every minute for testing."
echo "First run will be within 1 minute."

# Следим за логами cron в реальном времени
tail -f /app/var/logs/cron.log
