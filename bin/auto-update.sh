#!/bin/bash

# Автоматическое обновление без интерактивности
set -e

PROJECT_DIR="$(dirname "$0")/.."
cd "$PROJECT_DIR"

# Логирование
LOG_FILE="/var/log/vk2tg-update.log"
exec > >(tee -a "$LOG_FILE")
exec 2>&1

echo "$(date): Начинаем автоматическое обновление vk2tg"

# Проверяем есть ли изменения
git fetch origin
if git diff --quiet HEAD origin/main; then
    echo "$(date): Нет новых изменений"
    exit 0
fi

echo "$(date): Найдены новые изменения, обновляем..."

# Обновляем без подтверждения
git pull origin main
composer install --no-dev --optimize-autoloader

# Проверяем работоспособность
if php -l src/Sc/Vk2Tg.php; then
    echo "$(date): Обновление выполнено успешно"
    # Перезапускаем сервис (если используется systemd)
    # systemctl restart vk2tg
else
    echo "$(date): ОШИБКА: Проблемы с синтаксисом PHP после обновления"
    exit 1
fi
