<?php

namespace Sc;

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Sc\Service\Synchronizer;
use Sc\Config\AppConfig;

error_reporting(-1);
set_time_limit(300); // 5 minutes maximum
ignore_user_abort(false);

// Check for already running process
$lockFile = __DIR__ . '/sync.lock';
if (file_exists($lockFile)) {
    $lockData = json_decode(file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
    $pid = $lockData['pid'] ?? 0;
    $startTime = $lockData['start_time'] ?? 0;

    // Check if process is really running and not hanging for more than 10 minutes
    if ($pid > 0 && posix_kill($pid, 0)) {
        if (time() - $startTime < 600) { // 10 minutes
            exit("Process already running with PID: $pid\n");
        }
        // If process is hanging for more than 10 minutes - kill it
        posix_kill($pid, SIGTERM);
        sleep(2);
        if (posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }
    }
    unlink($lockFile);
}

// Create lock file
$lockData = [
    'pid' => getmypid(),
    'start_time' => time()
];
file_put_contents($lockFile, json_encode($lockData, JSON_THROW_ON_ERROR));

// Remove lock file on shutdown
register_shutdown_function(static function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->safeLoad();

$syncer = new Synchronizer(
    AppConfig::fromEnvironment()
);
try {
    $syncer->invoke();
} catch (\Throwable $e) {
    $syncer->logger()->error(
        get_class($e) . ': ' . $e->getMessage()
    );
}
