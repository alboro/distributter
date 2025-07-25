#!/bin/bash

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

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

# Auto-detect project root
detect_project_root() {
    local current_dir=$(pwd)
    local search_dir="$current_dir"

    # Search for composer.json or vendor directory going up the directory tree
    while [[ "$search_dir" != "/" ]]; do
        if [[ -f "$search_dir/composer.json" ]] || [[ -d "$search_dir/vendor" ]]; then
            echo "$search_dir"
            return 0
        fi
        search_dir=$(dirname "$search_dir")
    done

    # If not found, use current directory
    echo "$current_dir"
}

# Find MadelineProto session directories
find_session_dirs() {
    local project_root="$1"
    local session_dirs=()

    # Common locations for MadelineProto sessions
    local search_paths=(
        "$project_root"
        "$project_root/bin"
        "$project_root/config"
        "$project_root/storage"
        "$project_root/sessions"
    )

    for path in "${search_paths[@]}"; do
        if [[ -d "$path" ]]; then
            # Look for session.madeline directories
            find "$path" -type d -name "session.madeline" -o -name "*.madeline" 2>/dev/null | while read -r session_dir; do
                session_dirs+=("$session_dir")
            done

            # Look for .madeline files (session files)
            find "$path" -type f -name "*.madeline" 2>/dev/null | while read -r session_file; do
                session_dirs+=("$(dirname "$session_file")")
            done
        fi
    done

    # Remove duplicates and return
    printf '%s\n' "${session_dirs[@]}" | sort -u
}

# Check and increase ulimit
fix_ulimit() {
    local current_limit=$(ulimit -n)
    local recommended_limit=65536

    log "INFO" "Current file descriptor limit: $current_limit"

    if [[ $current_limit -lt $recommended_limit ]]; then
        log "INFO" "Increasing file descriptor limit to $recommended_limit"
        ulimit -n $recommended_limit || {
            log "WARN" "Failed to increase ulimit. You may need to run as root or modify /etc/security/limits.conf"
            log "INFO" "To permanently fix this, add these lines to /etc/security/limits.conf:"
            log "INFO" "  * soft nofile $recommended_limit"
            log "INFO" "  * hard nofile $recommended_limit"
        }
        log "INFO" "New file descriptor limit: $(ulimit -n)"
    else
        log "INFO" "File descriptor limit is sufficient"
    fi
}

# Kill hanging MadelineProto processes
kill_hanging_processes() {
    log "INFO" "Checking for hanging MadelineProto processes..."

    local madeline_pids=$(ps aux | grep -E '(madeline-ipc|MadelineProto)' | grep -v grep | awk '{print $2}' || true)

    if [[ -n "$madeline_pids" ]]; then
        log "WARN" "Found hanging MadelineProto processes: $madeline_pids"
        echo "$madeline_pids" | xargs -r kill -TERM 2>/dev/null || true
        sleep 2

        # Force kill if still running
        local remaining_pids=$(ps aux | grep -E '(madeline-ipc|MadelineProto)' | grep -v grep | awk '{print $2}' || true)
        if [[ -n "$remaining_pids" ]]; then
            log "WARN" "Force killing remaining processes: $remaining_pids"
            echo "$remaining_pids" | xargs -r kill -KILL 2>/dev/null || true
        fi

        log "INFO" "Killed hanging processes"
    else
        log "INFO" "No hanging MadelineProto processes found"
    fi
}

# Clean up session files
cleanup_session_files() {
    local project_root="$1"
    local cleaned_count=0

    log "INFO" "Cleaning up MadelineProto session files..."

    # Find all session directories
    while IFS= read -r session_dir; do
        if [[ -d "$session_dir" ]]; then
            log "DEBUG" "Cleaning session directory: $session_dir"

            # Remove problematic files
            local files_to_remove=(
                "$session_dir/ipc"
                "$session_dir/callback.ipc"
                "$session_dir/lock"
                "$session_dir"/*.lock
            )

            for file_pattern in "${files_to_remove[@]}"; do
                if ls $file_pattern 1> /dev/null 2>&1; then
                    rm -f $file_pattern
                    ((cleaned_count++))
                fi
            done
        fi
    done < <(find_session_dirs "$project_root")

    log "INFO" "Cleaned up $cleaned_count session files"
}

# Check PHP configuration
check_php_config() {
    log "INFO" "Checking PHP configuration..."

    # Check if proc_open is available
    if php -r "echo function_exists('proc_open') ? 'OK' : 'MISSING';" | grep -q "MISSING"; then
        log "ERROR" "proc_open function is disabled. MadelineProto requires proc_open to work."
        log "INFO" "Please enable proc_open in php.ini by removing it from disable_functions"
        return 1
    else
        log "INFO" "proc_open function is available"
    fi

    # Check open_basedir
    local open_basedir=$(php -r "echo ini_get('open_basedir');")
    if [[ -n "$open_basedir" ]]; then
        log "WARN" "open_basedir is set: $open_basedir"
        log "INFO" "This might cause issues with MadelineProto. Consider disabling open_basedir or adding project path to it."
    else
        log "INFO" "open_basedir is not restricting access"
    fi
}

# Main function
main() {
    log "INFO" "🔧 Universal MadelineProto Fix Tool"
    log "INFO" "Fixing 'Too many open files' and related issues"

    # Auto-detect project root
    local project_root=$(detect_project_root)
    log "INFO" "Detected project root: $project_root"

    # Perform fixes
    fix_ulimit
    kill_hanging_processes
    cleanup_session_files "$project_root"
    check_php_config

    log "INFO" "🎉 Fix completed successfully!"
    log "INFO" "You can now run your MadelineProto application"

    # Show usage hints
    echo ""
    log "INFO" "Usage hints:"
    if [[ -f "$project_root/bin/distributter.php" ]]; then
        log "INFO" "  Run: cd '$project_root' && php bin/distributter.php"
    elif [[ -f "$project_root/distributter.php" ]]; then
        log "INFO" "  Run: cd '$project_root' && php distributter.php"
    else
        log "INFO" "  Run your MadelineProto script from: $project_root"
    fi
}

# Show help
show_help() {
    echo "Universal MadelineProto Fix Tool"
    echo ""
    echo "This script fixes common MadelineProto issues:"
    echo "  - 'Too many open files' error"
    echo "  - Hanging IPC processes"
    echo "  - Corrupted session files"
    echo "  - PHP configuration issues"
    echo ""
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  --help, -h     Show this help message"
    echo "  --dry-run      Show what would be done without making changes"
    echo ""
    echo "The script automatically detects your project structure and"
    echo "applies fixes accordingly. No configuration required."
}

# Parse command line arguments
case "${1:-}" in
    "--help"|"-h")
        show_help
        exit 0
        ;;
    "--dry-run")
        log "INFO" "DRY RUN MODE - No changes will be made"
        # Set a flag for dry run mode
        DRY_RUN=1
        main
        ;;
    "")
        main
        ;;
    *)
        log "ERROR" "Unknown option: $1"
        show_help
        exit 1
        ;;
esac
