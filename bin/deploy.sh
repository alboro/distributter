#!/bin/bash

# deployment script for remote server
# Runs locally, deploys to server via SSH

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Server configuration
SERVER_HOST="${DEPLOY_HOST:-your-server.com}"
SERVER_USER="${DEPLOY_USER:-deploy}"
SERVER_PORT="${DEPLOY_PORT:-22}"
SERVER_PATH="${DEPLOY_PATH:-/var/www/distributter}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"

# SSH key
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_rsa}"

# Logging functions
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

# Check SSH connection
check_ssh_connection() {
    log "INFO" "Checking SSH connection to $SERVER_USER@$SERVER_HOST:$SERVER_PORT"

    if ! ssh -i "$SSH_KEY" -p "$SERVER_PORT" -o ConnectTimeout=10 -o BatchMode=yes \
         "$SERVER_USER@$SERVER_HOST" "echo 'SSH connection OK'" >/dev/null 2>&1; then
        log "ERROR" "Unable to connect to server via SSH"
        log "INFO" "Please ensure that:"
        log "INFO" "  - SSH key is set up: $SSH_KEY"
        log "INFO" "  - Server is reachable: $SERVER_HOST:$SERVER_PORT"
        log "INFO" "  - User exists: $SERVER_USER"
        exit 1
    fi

    log "INFO" "SSH connection established successfully"
}

# Execute command on remote server
ssh_exec() {
    local command="$1"
    local description="${2:-command execution}"
    local show_output="${3:-false}"

    log "DEBUG" "Executing on server: $command"

    if [ "$show_output" = "true" ]; then
        # Show command output
        ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
            "cd '$SERVER_PATH' && $command" || {
            log "ERROR" "Error during $description"
            return 1
        }
    else
        # Hide command output, only return result
        ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
            "cd '$SERVER_PATH' && $command" 2>/dev/null || {
            log "ERROR" "Error during $description"
            return 1
        }
    fi
}

# Deploy to server
deploy_to_server() {
    log "INFO" "Starting deployment to server $SERVER_HOST"

    log "INFO" "Updating server..."

    # Execute deployment commands step by step for better diagnostics
    log "INFO" "Step 1: Creating backup"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && backup_dir=\"/tmp/distributter_backup_\$(date +%Y%m%d_%H%M%S)\" && mkdir -p \"\$backup_dir\" && cp -r . \"\$backup_dir/\" && echo \"\$backup_dir\" > .last_backup && echo 'Backup created: '\$backup_dir"

    log "INFO" "Step 2: Resetting local changes"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && git reset --hard HEAD && git clean -fd && echo 'Local changes reset'"

    log "INFO" "Step 3: Updating code from Git"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && git fetch origin && git reset --hard origin/$DEPLOY_BRANCH"

    log "INFO" "Step 4: Updating Composer dependencies"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && bin/build.sh"

    log "INFO" "Step 5: Checking syntax"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && php -l src/Sc/Service/Synchronizer.php"

    log "INFO" "Step 6: Logging deployment"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && echo \"\$(date '+%Y-%m-%d %H:%M:%S') [DEPLOY] Successfully updated to commit \$(git rev-parse --short HEAD)\" >> log.log && echo 'New commit: '\$(git rev-parse --short HEAD)"

    log "INFO" "Step 7: Cleaning up backup"
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
        "cd '$SERVER_PATH' && rm -f .last_backup && backup_dir=\$(cat .last_backup 2>/dev/null || echo '') && [ -n \"\$backup_dir\" ] && rm -rf \"\$backup_dir\" 2>/dev/null && echo 'Backup removed' || echo 'Backup not found'"

    log "INFO" "Deployment completed successfully!"
    log "INFO" "Application is ready to run via cron"
}

# Rollback function
rollback_on_server() {
    log "WARN" "Performing rollback on server..."

    local rollback_command="
        set -e

        if [ ! -f .last_backup ]; then
            echo '[ERROR] Backup file not found'
            exit 1
        fi

        backup_path=\$(cat .last_backup)
        if [ ! -d \"\$backup_path\" ]; then
            echo '[ERROR] Backup not found'
            exit 1
        fi

        echo '[INFO] Restoring from backup...'
        cp -r \"\$backup_path/\"* .

        echo '[INFO] Installing dependencies...'
        composer install --no-dev --optimize-autoloader --no-interaction

        echo '[INFO] Rollback completed'
    "

    if ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
       "cd '$SERVER_PATH' && bash -c '$rollback_command'"; then
        log "INFO" "Rollback completed successfully"
    else
        log "ERROR" "Error during rollback"
        exit 1
    fi
}

# Show status
show_status() {
    log "INFO" "Project status on server $SERVER_HOST"

    echo ""
    log "INFO" "=== Git status ==="
    ssh_exec "git status --porcelain | wc -l | xargs echo 'Changed files:'" "getting Git status"
    ssh_exec "git rev-parse --abbrev-ref HEAD | xargs echo 'Current branch:'" "getting current branch"
    ssh_exec "git log -1 --format='Last commit: %h - %s (%cr) <%an>'" "getting last commit"

    echo ""
    log "INFO" "=== Cron job status ==="
    ssh_exec "crontab -l | grep -E 'vk2tg|sync-vk|distributter' | wc -l | xargs echo 'Active cron jobs:'" "checking cron jobs" || log "WARN" "Failed to check cron jobs"

    echo ""
    log "INFO" "=== Recent runs (from logs) ==="
    ssh_exec "tail -n 5 log.log 2>/dev/null || echo 'Logs not found'" "getting logs from project" || true
}

# Main function
main() {
    log "INFO" "🚀 Distributter Deployment System"
    log "INFO" "Server: $SERVER_USER@$SERVER_HOST:$SERVER_PORT"
    log "INFO" "Path: $SERVER_PATH"
    log "INFO" "Branch: $DEPLOY_BRANCH"

    case "${1:-deploy}" in
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
            log "INFO" "Showing application logs..."
            ssh_exec "tail -n 20 log.log 2>/dev/null || echo 'Logs not found'" "getting application logs"
            ;;
        "ssh")
            check_ssh_connection
            log "INFO" "Connecting to server..."
            ssh -i "$SSH_KEY" -p "$SERVER_PORT" "$SERVER_USER@$SERVER_HOST" \
                "cd '$SERVER_PATH' && bash"
            ;;
        "help"|"-h"|"--help")
            echo "Usage: $0 [command]"
            echo ""
            echo "Commands:"
            echo "  force    - Force deployment without confirmation"
            echo "  rollback - Rollback to previous version"
            echo "  status   - Show project status on server"
            echo "  logs     - Show application logs"
            echo "  ssh      - Connect to server via SSH"
            echo "  help     - Show this help message"
            echo ""
            echo "Environment variables:"
            echo "  DEPLOY_HOST   - Server host"
            echo "  DEPLOY_USER   - SSH user"
            echo "  DEPLOY_PORT   - SSH port (default: 22)"
            echo "  DEPLOY_PATH   - Path to project on server"
            echo "  DEPLOY_BRANCH - Branch for deployment (default: main)"
            echo "  SSH_KEY       - Path to SSH key (default: ~/.ssh/id_rsa)"
            echo ""
            echo "Example:"
            echo "  DEPLOY_HOST=myserver.com DEPLOY_USER=deploy $0 force"
            ;;
        *)
            log "ERROR" "Unknown command: $1"
            log "INFO" "Use '$0 help' for assistance"
            exit 1
            ;;
    esac
}

# Check dependencies
check_dependencies() {
    local missing_deps=()

    for cmd in ssh git; do
        if ! command -v "$cmd" > /dev/null 2>&1; then
            missing_deps+=("$cmd")
        fi
    done

    if [[ ${#missing_deps[@]} -gt 0 ]]; then
        log "ERROR" "Missing dependencies: ${missing_deps[*]}"
        exit 1
    fi

    if [[ ! -f "$SSH_KEY" ]]; then
        log "ERROR" "SSH key not found: $SSH_KEY"
        exit 1
    fi
}

# Signal handling
cleanup() {
    log "WARN" "Termination signal received..."
    exit 1
}

trap cleanup SIGINT SIGTERM

# Check dependencies and run
check_dependencies
main "$@"
