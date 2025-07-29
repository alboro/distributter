<?php

/**
 * @deprecated this file was deprecated in favor of distributter.php
 * But since it's still being called by cron, we'll add safety checks
 */

// Add safety checks to prevent conflicts with user processes
$lockFile = __DIR__ . '/sync-vk.lock';
$sessionDir = __DIR__ . '/session.madeline';

// Check if another instance is running
if (file_exists($lockFile)) {
    $pid = (int) file_get_contents($lockFile);
    if ($pid && posix_kill($pid, 0)) {
        echo "Another instance is running (PID: $pid), exiting\n";
        exit(0);
    }
    // Remove stale lock file
    unlink($lockFile);
}

// Create lock file
file_put_contents($lockFile, getmypid());

// Cleanup function
function cleanup() {
    global $lockFile;
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// Register cleanup function
register_shutdown_function('cleanup');

// Check for and stop any hanging MadelineProto processes
$currentUser = get_current_user();
$processes = shell_exec("ps aux | grep -E 'MadelineProto worker|entry.php.*madeline' | grep -v grep");

if (!empty(trim($processes))) {
    echo "Found hanging MadelineProto processes, cleaning up...\n";

    // Kill hanging processes
    shell_exec("pkill -f 'MadelineProto worker' 2>/dev/null");
    shell_exec("pkill -f 'entry.php.*madeline' 2>/dev/null");

    // Wait a moment
    sleep(2);

    // Force kill if needed
    shell_exec("pkill -9 -f 'MadelineProto worker' 2>/dev/null");
    shell_exec("pkill -9 -f 'entry.php.*madeline' 2>/dev/null");

    echo "Cleaned up hanging processes\n";
}

// Clean up session lock files if they exist
if (is_dir($sessionDir)) {
    $lockFiles = [
        $sessionDir . '/ipcState.php.lock',
        $sessionDir . '/lightState.php.lock',
        $sessionDir . '/safe.php.lock',
        $sessionDir . '/lock',
        $sessionDir . '/callback.ipc',
        $sessionDir . '/ipc'
    ];

    foreach ($lockFiles as $file) {
        if (file_exists($file)) {
            unlink($file);
            echo "Removed lock file: " . basename($file) . "\n";
        }
    }

    // Fix permissions
    if (function_exists('shell_exec')) {
        shell_exec("chown -R $currentUser:$currentUser " . escapeshellarg($sessionDir) . " 2>/dev/null");
    }
}

echo "Starting distributter.php\n";

require_once __DIR__ . '/distributter.php';