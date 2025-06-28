#!/bin/bash

# Скрипт деплоя vk2tg на удаленный сервер
# Запускается локально, деплоит на сервер по SSH

set -euo pipefail

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Конфигурация сервера
SERVER_HOST="${DEPLOY_HOST:-your-server.com}"
SERVER_USER="${DEPLOY_USER:-deploy}"
SERVER_PORT="${DEPLOY_PORT:-22}"
SERVER_PATH="${DEPLOY_PATH:-/var/www/vk2tg}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"

# SSH ключ
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_rsa}"

# Функции логирования
log() {
    local level=$1
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    case $level in
        "INFO")  echo -e "${GREEN}[INFO]${NC} $message" ;;
        "WARN")  echo -e "${YELLOW}[WARN]${NC} $message" ;;
        "ERROR") echo -e "${RED}[ERROR]${NC} $message" ;;
        "DEBUG") echo -e "${BLUE}[DEBUG]${NC} $message" ;;
    esac
}

# Проверка SSH соединения
check_ssh_connection() {
    log "INFO" "Проверяем SSH соединение с $SERVER_USER@$SERVER_HOST:$SERVER_PORT"

    if ! ssh -i "$SSH_KEY" -p "$SERVER_PORT" -o ConnectTimeout=10 -o BatchMode=yes \
         "$SERVER_USER@$SERVER_HOST" "echo 'SSH connection OK'" >/dev/null 2>&1; then
        log "ERROR" "Не удается подключиться к серверу по SSH"
        log "INFO" "Убедитесь что:"
        log "INFO" "  - SSH ключ настроен: $SSH_KEY"
        log "INFO" "  - Сервер доступен: $SERVER_HOST:$SERVER_PORT"
        log "INFO" "  - Пользователь существует: $SERVER_USER"
        exit 1
    fi

    log "INFO" "SSH соединение установлено успешно"
}

# Выполнение команды на удаленном сервере
ssh_exec() {
    local command="$1"
    local description="${2:-выполнение команды}"
    local show_output="${3:-false}"

    log "DEBUG" "Выполняем на сервере: $command"

    if [ "$show_output" = "true" ]; then
        # Показываем вывод команды
        ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
            "cd '$SERVER_PATH' && $command" || {
            log "ERROR" "Ошибка при $description"
            return 1
        }
    else
        # Скрываем вывод команды, только возвращаем результат
        ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
            "cd '$SERVER_PATH' && $command" 2>/dev/null || {
            log "ERROR" "Ошибка при $description"
            return 1
        }
    fi
}

# Проверка состояния удаленного репозитория
check_remote_repo() {
    log "INFO" "Проверяем состояние репозитория на сервере..."

    # Проверя��м что директория существует
    if ! ssh_exec "test -d '$SERVER_PATH'" "проверке директории проекта"; then
        log "ERROR" "Директория проекта не найдена на сервере: $SERVER_PATH"
        exit 1
    fi

    # Проверяем что это Git репозиторий
    if ! ssh_exec "test -d .git" "проверке Git репозитория"; then
        log "ERROR" "Директория не является Git репозиторием"
        exit 1
    fi

    # Получаем текущую ветку и коммит
    local current_branch=$(ssh_exec "git rev-parse --abbrev-ref HEAD" "получении текущей ветки")
    local current_commit=$(ssh_exec "git rev-parse --short HEAD" "получении текущего коммита")

    log "INFO" "Текущая ветка на сервере: $current_branch"
    log "INFO" "Текущий коммит на сервере: $current_commit"

    # Получаем последние изменения
    ssh_exec "git fetch origin" "получении изменений из репозитория"

    # Проверяем есть ли новые изменения
    local remote_commit=$(ssh_exec "git rev-parse --short origin/$DEPLOY_BRANCH" "получении удаленного коммита")

    if [[ "$current_commit" == "$remote_commit" ]]; then
        log "INFO" "На сервере уже установлена последняя версия"
        log "INFO" "Локальный коммит: $current_commit"
        log "INFO" "Удаленный коммит: $remote_commit"
        return 1
    fi

    log "INFO" "Найдены новые изменения для деплоя:"
    log "INFO" "  Текущий: $current_commit"
    log "INFO" "  Новый: $remote_commit"

    # Показываем список изменений
    log "INFO" "Список изменений:"
    ssh_exec "git log --oneline $current_commit..origin/$DEPLOY_BRANCH | head -5" "получении списка изменений"

    return 0
}

# Деплой на сервер
deploy_to_server() {
    log "INFO" "Начинаем деплой на сервер $SERVER_HOST"

    log "INFO" "Выполняем обновление на сервере..."

    # Выполняем команды деплоя пошагово для лучшей диагностики
    log "INFO" "Шаг 1: Создание резервной копии"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && backup_dir=\"/tmp/vk2tg_backup_\$(date +%Y%m%d_%H%M%S)\" && mkdir -p \"\$backup_dir\" && cp -r . \"\$backup_dir/\" && echo \"\$backup_dir\" > .last_backup && echo 'Резервная копия создана: '\$backup_dir"

    log "INFO" "Шаг 2: Сброс локальных изменений"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && git reset --hard HEAD && git clean -fd && echo 'Локальные изменения сброшены'"

    log "INFO" "Шаг 3: Обновление кода из Git"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && git checkout $DEPLOY_BRANCH && git pull origin $DEPLOY_BRANCH"

    log "INFO" "Шаг 4: Обновление зависимостей Composer"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && composer install --no-dev --optimize-autoloader --no-interaction"

    log "INFO" "Шаг 5: Проверка синтаксиса"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && php -l src/Sc/Vk2Tg.php"

    log "INFO" "Шаг 6: Запись лога деплоя"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && echo \"\$(date '+%Y-%m-%d %H:%M:%S') [DEPLOY] Успешно обновлено до коммита \$(git rev-parse --short HEAD)\" >> log.log && echo 'Новый коммит: '\$(git rev-parse --short HEAD)"

    log "INFO" "Шаг 7: Очистка резервной копии"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && rm -f .last_backup && backup_dir=\$(cat .last_backup 2>/dev/null || echo '') && [ -n \"\$backup_dir\" ] && rm -rf \"\$backup_dir\" 2>/dev/null && echo 'Резервная копия удалена' || echo 'Резервная копия не найдена'"

    log "INFO" "Деплой выполнен успешно!"
    log "INFO" "Приложение готово к запуску по крону"
}

# Функция отката
rollback_on_server() {
    log "WARN" "Выполняем откат на сервере..."

    local rollback_command="
        set -e

        if [ ! -f .last_backup ]; then
            echo '[ERROR] Файл резервной копии не найден'
            exit 1
        fi

        backup_path=\$(cat .last_backup)
        if [ ! -d \"\$backup_path\" ]; then
            echo '[ERROR] Резервная копия не найдена'
            exit 1
        fi

        echo '[INFO] Восстанавливаем из резервной копии...'
        cp -r \"\$backup_path/\"* .

        echo '[INFO] Устанавливаем зависимости...'
        composer install --no-dev --optimize-autoloader --no-interaction

        echo '[INFO] Откат выполнен'
    "

    if ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
       "cd '$SERVER_PATH' && bash -c '$rollback_command'"; then
        log "INFO" "Откат выполнен успешно"
    else
        log "ERROR" "Ошибка при выполнении отката"
        exit 1
    fi
}

# Показ статуса
show_status() {
    log "INFO" "Статус проекта на сервере $SERVER_HOST"

    echo ""
    log "INFO" "=== Git статус ==="
    ssh_exec "git status --porcelain | wc -l | xargs echo 'Измененных файлов:'" "получении Git статуса"
    ssh_exec "git rev-parse --abbrev-ref HEAD | xargs echo 'Текущая ветка:'" "получении текущей ветки"
    ssh_exec "git log -1 --format='Последний коммит: %h - %s (%cr) <%an>'" "получении последнего коммита"

    echo ""
    log "INFO" "=== Статус cron заданий ==="
    ssh_exec "crontab -l | grep -E 'vk2tg|sync-vk' | wc -l | xargs echo 'Активных cron заданий:'" "проверке cron заданий" || log "WARN" "Не удалось проверить cron задания"

    echo ""
    log "INFO" "=== Последние запуски (из логов) ==="
    ssh_exec "tail -n 5 log.log 2>/dev/null || echo 'Логи не найдены'" "получении логов из проекта" || true
}

# Основная функция
main() {
    log "INFO" "🚀 Система деплоя vk2tg"
    log "INFO" "Сервер: $SERVER_USER@$SERVER_HOST:$SERVER_PORT"
    log "INFO" "Путь: $SERVER_PATH"
    log "INFO" "Ветка: $DEPLOY_BRANCH"

    case "${1:-deploy}" in
        "deploy")
            check_ssh_connection
            if check_remote_repo; then
                read -p "❓ Продолжить деплой? (y/n): " -n 1 -r
                echo
                if [[ $REPLY =~ ^[Yy]$ ]]; then
                    deploy_to_server
                else
                    log "INFO" "Деплой отменен"
                fi
            fi
            ;;
        "force")
            check_ssh_connection
            deploy_to_server
            ;;
        "rollback")
            check_ssh_connection
            rollback_on_server
            ;;
        "status")
            check_ssh_connection
            show_status
            ;;
        "logs")
            check_ssh_connection
            log "INFO" "Показываем логи приложения..."
            ssh_exec "tail -n 20 log.log 2>/dev/null || echo 'Логи не найдены'" "получении логов приложения"
            ;;
        "ssh")
            check_ssh_connection
            log "INFO" "Подключаемся к серверу..."
            ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
                "cd '$SERVER_PATH' && bash"
            ;;
        "help"|"-h"|"--help")
            echo "Использование: $0 [команда]"
            echo ""
            echo "Команды:"
            echo "  deploy   - Деплой с подтверждением (по умолчанию)"
            echo "  force    - Принудительный деплой без подтверждения"
            echo "  rollback - Откат к предыдущей версии"
            echo "  status   - Показать статус проекта на сервере"
            echo "  logs     - Показать ��оги приложения"
            echo "  ssh      - Подключиться к серверу по SSH"
            echo "  help     - Показать эту справку"
            echo ""
            echo "Переменные окружения:"
            echo "  DEPLOY_HOST   - Хост сервера"
            echo "  DEPLOY_USER   - Пользователь SSH"
            echo "  DEPLOY_PORT   - Порт SSH (по умол��анию: 22)"
            echo "  DEPLOY_PATH   - Путь к проекту на сервере"
            echo "  DEPLOY_BRANCH - Ветка для деплоя (по умолчанию: main)"
            echo "  SSH_KEY       - Путь к SSH ключу (по умолчанию: ~/.ssh/id_rsa)"
            echo ""
            echo "Пример:"
            echo "  DEPLOY_HOST=myserver.com DEPLOY_USER=deploy $0 deploy"
            ;;
        *)
            log "ERROR" "Неизвестная команда: $1"
            log "INFO" "Используйте '$0 help' для справки"
            exit 1
            ;;
    esac
}

# Проверка зависимостей
check_dependencies() {
    local missing_deps=()

    for cmd in ssh git; do
        if ! command -v "$cmd" > /dev/null 2>&1; then
            missing_deps+=("$cmd")
        fi
    done

    if [[ ${#missing_deps[@]} -gt 0 ]]; then
        log "ERROR" "Отсутствуют зависимости: ${missing_deps[*]}"
        exit 1
    fi

    if [[ ! -f "$SSH_KEY" ]]; then
        log "ERROR" "SSH ключ не найден: $SSH_KEY"
        exit 1
    fi
}

# Обработка сигналов
cleanup() {
    log "WARN" "Получен сигнал завершения..."
    exit 1
}

trap cleanup SIGINT SIGTERM

# Проверяем зависимости и запускаем
check_dependencies
main "$@"
