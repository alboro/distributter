#!/bin/bash

# Скрипт для обновления проекта vk2tg из Git на сервере
# Работает без взаимодействия с пользователем
set -e

echo "🔄 Начинаем обновление проекта vk2tg..."

# Переходим в директорию проекта
cd "$(dirname "$0")/.."

# Логирование
LOG_FILE="${LOG_FILE:-/var/log/vk2tg/update.log}"
mkdir -p "$(dirname "$LOG_FILE")"

# Функция логирования
log() {
    local message="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] $message"
    echo "[$timestamp] $message" >> "$LOG_FILE"
}

log "Начинаем автоматическое обновление vk2tg"

# Сохраняем текущий коммит для возможного отката
CURRENT_COMMIT=$(git rev-parse --short HEAD)
log "Текущий коммит: $CURRENT_COMMIT"

# Получаем последние изменения
log "Получаем изменения из Git..."
git fetch origin

# Проверяем есть ли изменения
REMOTE_COMMIT=$(git rev-parse --short origin/main)
if [ "$CURRENT_COMMIT" = "$REMOTE_COMMIT" ]; then
    log "Нет новых изменений для обновления"
    exit 0
fi

log "Найдены новые изменения: $CURRENT_COMMIT -> $REMOTE_COMMIT"

# Показываем что изменилось
log "Список изменений:"
git log --oneline $CURRENT_COMMIT..origin/main | head -5 | while read line; do
    log "  $line"
done

# Создаем резервную копию
BACKUP_DIR="/tmp/vk2tg_backup_$(date +%Y%m%d_%H%M%S)"
log "Создаем резервную копию в $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"
cp -r . "$BACKUP_DIR/" 2>/dev/null || true

# Обновляем код
log "Обновляем код..."
if git pull origin main; then
    log "Код обновлен успешно"
else
    log "ОШИБКА: Не удалось обновить код"
    exit 1
fi

# Обновляем зависимости
log "Обновляем зависимости Composer..."
if composer install --no-dev --optimize-autoloader --no-interaction; then
    log "Зависимости обновлены успешно"
else
    log "ОШИБКА: Не удалось обновить зависимости"
    log "Восстанавливаем из резервной копии..."
    cp -r "$BACKUP_DIR/"* . 2>/dev/null || true
    exit 1
fi

# Проверяем работоспособность
log "Проверяем работоспособность..."
if php -l src/Sc/Synchronizer.php > /dev/null 2>&1; then
    log "Проверка синтаксиса прошла успешно"
else
    log "ОШИБКА: Проблемы с синтаксисом PHP"
    log "Восстанавливаем из резервной копии..."
    cp -r "$BACKUP_DIR/"* . 2>/dev/null || true
    composer install --no-dev --optimize-autoloader --no-interaction > /dev/null 2>&1
    exit 1
fi

# Тестируем основной функционал (если есть тестовый файл)
if [ -f "bin/test.php" ]; then
    log "Запускаем базовые тесты..."
    if timeout 30 php bin/test.php > /dev/null 2>&1; then
        log "Базовые тесты прошли успешно"
    else
        log "ПРЕДУПРЕЖДЕНИЕ: Базовые тесты не прошли, но продолжаем"
    fi
fi

# Удаляем резервную копию при успешном обновлении
log "Удаляем резервную копию..."
rm -rf "$BACKUP_DIR" 2>/dev/null || true

NEW_COMMIT=$(git rev-parse --short HEAD)
log "Обновление завершено успешно!"
log "Новый коммит: $NEW_COMMIT"
log "Перезапустите сервис для применения изменений"

echo "✅ Обновление завершено успешно!"
echo "🏃 Перезапустите сервис для применения изменений"
