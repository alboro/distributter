#!/bin/bash

# Безопасное обновление с возможностью отката
set -e

PROJECT_DIR="$(dirname "$0")/.."
BACKUP_DIR="/tmp/vk2tg_backup_$(date +%Y%m%d_%H%M%S)"

echo "🛡️ Безопасное обновление vk2tg с возможностью отката"

cd "$PROJECT_DIR"

# Создаем резервную копию
echo "💾 Создаем резервную копию в $BACKUP_DIR..."
mkdir -p "$BACKUP_DIR"
cp -r . "$BACKUP_DIR/"

# Получаем изменения
echo "📥 Получаем изменения из Git..."
git fetch origin

# Показываем изменения
echo "📊 Предстоящие изменения:"
git log --oneline HEAD..origin/main | head -5

# Обновляем
echo "⬇️ Обновляем код..."
if git pull origin main; then
    echo "✅ Код обновлен успешно"
else
    echo "❌ Ошибка при обновлении кода"
    echo "🔄 Восстанавливаем из резервной копии..."
    cp -r "$BACKUP_DIR/"* .
    exit 1
fi

# Обновляем зависимости
echo "📦 Обновляем зависимости..."
if composer install --no-dev --optimize-autoloader; then
    echo "✅ Зависимости обновлены"
else
    echo "❌ Ошибка при обновлении зависимостей"
    echo "🔄 Восстанавливаем из резервной копии..."
    cp -r "$BACKUP_DIR/"* .
    composer install --no-dev --optimize-autoloader
    exit 1
fi

# Тестируем
echo "🧪 Тестируем обновленный код..."
if php -l src/Sc/Vk2Tg.php && php bin/test.php; then
    echo "✅ Тесты прошли успешно"
    echo "🗑️ Удаляем резервную копию..."
    rm -rf "$BACKUP_DIR"
else
    echo "❌ Тесты не прошли!"
    echo "🔄 Восстанавливаем из резервной копии..."
    cp -r "$BACKUP_DIR/"* .
    composer install --no-dev --optimize-autoloader
    echo "💾 Резервная копия сохранена в $BACKUP_DIR"
    exit 1
fi

echo "🎉 Обновление завершено успешно!"
