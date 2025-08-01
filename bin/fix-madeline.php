#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Sc\Channels\Tg\Retriever\MadelineProtoFixer;

// Create logger for the fixer
$logger = new Logger('madeline-fixer');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Session path
$sessionPath = __DIR__ . '/session.madeline'; // @todo: take from env

// Create fixer instance
$fixer = new MadelineProtoFixer($logger, $sessionPath);

// Parse command line arguments
$command = $argv[1] ?? 'fix';

switch ($command) {
    case 'fix':
        echo "🔧 Starting MadelineProto auto-fix...\n";
        $success = $fixer->fixIssues();
        echo $success ? "✅ Fix completed successfully\n" : "❌ Fix failed\n";
        exit($success ? 0 : 1);

    case 'health':
        echo "🏥 Checking MadelineProto health...\n";
        $healthy = $fixer->isHealthy();
        echo $healthy ? "✅ MadelineProto is healthy\n" : "⚠️ MadelineProto has issues\n";
        if (!$healthy) {
            echo "Run 'php bin/fix-madeline.php fix' to attempt automatic repair\n";
        }
        exit($healthy ? 0 : 1);

    case 'maintenance':
        echo "🔧 Performing maintenance...\n";
        $fixer->performMaintenance();
        echo "✅ Maintenance completed\n";
        exit(0);

    case 'diagnostics':
        echo "📋 Gathering diagnostics...\n";
        $diagnostics = $fixer->getDiagnostics();

        echo "\n=== MadelineProto Diagnostics ===\n";
        echo "Timestamp: " . $diagnostics['timestamp'] . "\n";
        echo "Session Path: " . $diagnostics['session_path'] . "\n";
        echo "Session Exists: " . ($diagnostics['session_exists'] ? 'Yes' : 'No') . "\n";
        echo "PHP Version: " . $diagnostics['php_version'] . "\n";
        echo "Memory Limit: " . $diagnostics['memory_limit'] . "\n";
        echo "Max Execution Time: " . $diagnostics['max_execution_time'] . "\n";
        echo "proc_open Available: " . ($diagnostics['proc_open_available'] ? 'Yes' : 'No') . "\n";
        echo "shell_exec Available: " . ($diagnostics['shell_exec_available'] ? 'Yes' : 'No') . "\n";
        echo "Open Basedir: " . ($diagnostics['open_basedir'] ?: 'None') . "\n";

        if (isset($diagnostics['file_descriptor_limit'])) {
            echo "File Descriptor Limit: " . $diagnostics['file_descriptor_limit'] . "\n";
            echo "MadelineProto Processes: " . $diagnostics['madeline_processes'] . "\n";
            echo "Open Files Count: " . $diagnostics['open_files_count'] . "\n";
        }

        echo "\n=== Session Files ===\n";
        foreach ($diagnostics['session_files'] as $filename => $info) {
            echo sprintf("%-20s: %s (%d bytes, modified: %s)\n",
                $filename,
                $info['exists'] ? 'EXISTS' : 'MISSING',
                $info['size'],
                $info['modified'] ?: 'N/A'
            );
        }

        exit(0);

    case 'clean':
        echo "🧹 Cleaning MadelineProto session files...\n";

        // Create a temporary fixer just for cleaning
        $cleanLogger = new Logger('cleaner');
        $cleanLogger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
        $cleanFixer = new MadelineProtoFixer($cleanLogger, $sessionPath);

        // Kill processes first
        echo "Stopping MadelineProto processes...\n";
        if (function_exists('shell_exec')) {
            shell_exec("pkill -f 'MadelineProto worker' 2>/dev/null");
            shell_exec("pkill -f 'entry.php.*madeline' 2>/dev/null");
            sleep(2);
        }

        // Clean session files
        $sessionDir = $sessionPath;
        if (is_dir($sessionDir)) {
            $filesToClean = [
                'ipcState.php.lock',
                'lightState.php.lock',
                'safe.php.lock',
                'lock',
                'callback.ipc',
                'ipc'
            ];

            $cleanedFiles = 0;
            foreach ($filesToClean as $file) {
                $fullPath = $sessionDir . '/' . $file;
                if (file_exists($fullPath)) {
                    if (unlink($fullPath)) {
                        echo "✅ Removed: $file\n";
                        $cleanedFiles++;
                    } else {
                        echo "❌ Failed to remove: $file\n";
                    }
                }
            }

            // Clean up any ipc* files
            $ipcFiles = glob($sessionDir . '/ipc*');
            foreach ($ipcFiles as $ipcFile) {
                if (unlink($ipcFile)) {
                    echo "✅ Removed: " . basename($ipcFile) . "\n";
                    $cleanedFiles++;
                }
            }

            echo "✅ Cleaned $cleanedFiles files\n";
        } else {
            echo "⚠️ Session directory not found: $sessionDir\n";
        }

        exit(0);

    case 'help':
    default:
        echo "MadelineProto Fixer Tool\n";
        echo "Usage: php bin/fix-madeline.php [command]\n\n";
        echo "Commands:\n";
        echo "  fix          - Automatically fix MadelineProto issues (default)\n";
        echo "  health       - Check MadelineProto health status\n";
        echo "  maintenance  - Perform routine maintenance\n";
        echo "  diagnostics  - Show detailed diagnostic information\n";
        echo "  clean        - Clean session files and stop processes\n";
        echo "  help         - Show this help message\n\n";
        echo "Examples:\n";
        echo "  php bin/fix-madeline.php fix\n";
        echo "  php bin/fix-madeline.php health\n";
        echo "  php bin/fix-madeline.php diagnostics\n";
        exit(0);
}
