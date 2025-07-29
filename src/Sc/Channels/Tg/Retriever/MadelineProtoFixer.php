<?php

namespace Sc\Channels\Tg\Retriever;

use Psr\Log\LoggerInterface;

/**
 * Fixes common MadelineProto issues like "Too many open files"
 */
class MadelineProtoFixer
{
    private LoggerInterface $logger;
    private string $projectRoot;
    private string $sessionPath;

    public function __construct(LoggerInterface $logger, string $sessionPath, ?string $projectRoot = null)
    {
        $this->logger = $logger;
        $this->projectRoot = $projectRoot ?? $this->detectProjectRoot();
        $this->sessionPath = $sessionPath;
    }

    /**
     * Auto-detect project root by looking for composer.json or vendor directory
     */
    private function detectProjectRoot(): string
    {
        $currentDir = getcwd();
        $searchDir = $currentDir;

        while ($searchDir !== '/') {
            if (file_exists($searchDir . '/composer.json') || is_dir($searchDir . '/vendor')) {
                return $searchDir;
            }
            $searchDir = dirname($searchDir);
        }

        return $currentDir;
    }

    /**
     * Main fix method - attempts to resolve MadelineProto issues
     */
    public function fixIssues(): bool
    {
        $this->logger->info('🔧 Starting MadelineProto issue resolution');

        try {
            $this->increaseFileDescriptorLimit();
            $this->killHangingProcesses();
            $this->cleanupSessionFiles();
            $this->checkPhpConfiguration();

            $this->logger->info('✅ MadelineProto issues fixed successfully');
            return true;

        } catch (\Exception $e) {
            $this->logger->error('❌ Failed to fix MadelineProto issues: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Increase file descriptor limit for current process
     */
    private function increaseFileDescriptorLimit(): void
    {
        if (!function_exists('shell_exec')) {
            $this->logger->warning('shell_exec disabled, cannot check/increase ulimit');
            return;
        }

        $currentLimit = (int) shell_exec('ulimit -n');
        $recommendedLimit = 65536;

        $this->logger->info("Current file descriptor limit: $currentLimit");

        if ($currentLimit < $recommendedLimit) {
            $this->logger->info("Attempting to increase limit to $recommendedLimit");

            // Try to increase limit for current process
            shell_exec("ulimit -n $recommendedLimit 2>/dev/null");

            $newLimit = (int) shell_exec('ulimit -n');
            if ($newLimit > $currentLimit) {
                $this->logger->info("Successfully increased limit to: $newLimit");
            } else {
                $this->logger->warning("Could not increase ulimit. Consider adding to /etc/security/limits.conf:");
                $this->logger->warning("* soft nofile $recommendedLimit");
                $this->logger->warning("* hard nofile $recommendedLimit");
            }
        } else {
            $this->logger->info('File descriptor limit is sufficient');
        }
    }

    /**
     * Kill hanging MadelineProto processes
     */
    private function killHangingProcesses(): void
    {
        if (!function_exists('shell_exec')) {
            $this->logger->warning('shell_exec disabled, cannot kill hanging processes');
            return;
        }

        $this->logger->info('🔍 Checking for hanging MadelineProto processes');

        // Find all MadelineProto related processes
        $processes = shell_exec("ps aux | grep -E 'MadelineProto worker|entry.php.*madeline' | grep -v grep");

        if (empty(trim($processes))) {
            $this->logger->info('No hanging MadelineProto processes found');
            return;
        }

        $this->logger->info('Found hanging MadelineProto processes:');
        $this->logger->info($processes);

        // Kill MadelineProto worker processes
        $killedWorkers = shell_exec("pkill -f 'MadelineProto worker' 2>/dev/null; echo $?");
        if (trim($killedWorkers) === '0') {
            $this->logger->info('✅ Killed MadelineProto worker processes');
        }

        // Kill entry.php processes
        $killedEntry = shell_exec("pkill -f 'entry.php.*madeline' 2>/dev/null; echo $?");
        if (trim($killedEntry) === '0') {
            $this->logger->info('✅ Killed MadelineProto entry processes');
        }

        // Wait a moment for processes to terminate
        sleep(2);

        // Force kill any remaining processes
        $remainingProcesses = shell_exec("ps aux | grep -E 'MadelineProto worker|entry.php.*madeline' | grep -v grep");
        if (!empty(trim($remainingProcesses))) {
            $this->logger->warning('Some processes still running, force killing...');
            shell_exec("pkill -9 -f 'MadelineProto worker' 2>/dev/null");
            shell_exec("pkill -9 -f 'entry.php.*madeline' 2>/dev/null");
            $this->logger->info('✅ Force killed remaining processes');
        }
    }

    /**
     * Clean up MadelineProto session files
     */
    private function cleanupSessionFiles(): void
    {
        $this->logger->info('🧹 Cleaning up MadelineProto session files');

        $sessionDir = $this->sessionPath;
        if (!is_dir($sessionDir)) {
            $this->logger->info("Session directory doesn't exist: $sessionDir");
            return;
        }

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
                    $this->logger->info("✅ Removed: $file");
                    $cleanedFiles++;
                } else {
                    $this->logger->warning("❌ Failed to remove: $file");
                }
            }
        }

        // Clean up any ipc* files
        $ipcFiles = glob($sessionDir . '/ipc*');
        foreach ($ipcFiles as $ipcFile) {
            if (unlink($ipcFile)) {
                $this->logger->info("✅ Removed: " . basename($ipcFile));
                $cleanedFiles++;
            }
        }

        if ($cleanedFiles > 0) {
            $this->logger->info("✅ Cleaned up $cleanedFiles session files");
        } else {
            $this->logger->info('No session files needed cleaning');
        }
    }

    /**
     * Check PHP configuration for MadelineProto requirements
     */
    private function checkPhpConfiguration(): void
    {
        $this->logger->info('⚙️ Checking PHP configuration');

        $requiredExtensions = ['curl', 'mbstring', 'xml', 'json', 'openssl', 'pcntl'];
        $missingExtensions = [];

        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $missingExtensions[] = $ext;
            }
        }

        if (!empty($missingExtensions)) {
            $this->logger->error('❌ Missing PHP extensions: ' . implode(', ', $missingExtensions));
        } else {
            $this->logger->info('✅ All required PHP extensions are loaded');
        }

        // Check important PHP settings
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = ini_get('max_execution_time');

        $this->logger->info("Memory limit: $memoryLimit");
        $this->logger->info("Max execution time: $maxExecutionTime");

        // Check if proc_open is available (required by MadelineProto)
        if (!function_exists('proc_open')) {
            $this->logger->error('❌ proc_open function is disabled - required by MadelineProto');
        } else {
            $this->logger->info('✅ proc_open function is available');
        }

        // Check open_basedir restriction
        $openBasedir = ini_get('open_basedir');
        if (!empty($openBasedir)) {
            $this->logger->warning("⚠️ open_basedir restriction is set: $openBasedir");
            $this->logger->warning('This may cause MadelineProto issues');
        } else {
            $this->logger->info('✅ No open_basedir restrictions');
        }
    }

    /**
     * Check if MadelineProto is currently running properly
     */
    public function isHealthy(): bool
    {
        // Check for hanging processes
        if (function_exists('shell_exec')) {
            $processes = shell_exec("ps aux | grep -E 'MadelineProto worker|entry.php.*madeline' | grep -v grep | wc -l");
            $processCount = (int) trim($processes);

            if ($processCount > 2) { // More than expected processes
                $this->logger->warning("Too many MadelineProto processes running: $processCount");
                return false;
            }
        }

        // Check for lock files that might indicate issues
        $problemFiles = [
            $this->sessionPath . '/ipcState.php.lock',
            $this->sessionPath . '/lightState.php.lock',
            $this->sessionPath . '/safe.php.lock'
        ];

        foreach ($problemFiles as $file) {
            if (file_exists($file)) {
                // Check if lock file is stale (older than 5 minutes)
                $age = time() - filemtime($file);
                if ($age > 300) {
                    $this->logger->warning("Stale lock file detected: " . basename($file) . " (age: {$age}s)");
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Perform preventive maintenance
     */
    public function performMaintenance(): void
    {
        $this->logger->info('🔧 Performing MadelineProto maintenance');

        // Clean up old log files if they're too large
        $logFile = $this->projectRoot . '/bin/MadelineProto.log';
        if (file_exists($logFile)) {
            $size = filesize($logFile);
            $maxSize = 50 * 1024 * 1024; // 50MB

            if ($size > $maxSize) {
                $this->logger->info("MadelineProto.log is large ({$size} bytes), truncating...");

                // Keep last 1000 lines
                $lines = file($logFile);
                if ($lines && count($lines) > 1000) {
                    $keepLines = array_slice($lines, -1000);
                    file_put_contents($logFile, implode('', $keepLines));
                    $this->logger->info('✅ Truncated MadelineProto.log');
                }
            }
        }

        // Check system resources
        if (function_exists('shell_exec')) {
            $openFiles = shell_exec("lsof | wc -l 2>/dev/null");
            if ($openFiles) {
                $this->logger->info("Current open files: " . trim($openFiles));
            }
        }
    }

    /**
     * Get diagnostic information
     */
    public function getDiagnostics(): array
    {
        $diagnostics = [
            'timestamp' => date('Y-m-d H:i:s'),
            'session_path' => $this->sessionPath,
            'session_exists' => is_dir($this->sessionPath),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'proc_open_available' => function_exists('proc_open'),
            'shell_exec_available' => function_exists('shell_exec'),
            'open_basedir' => ini_get('open_basedir'),
        ];

        if (function_exists('shell_exec')) {
            $diagnostics['file_descriptor_limit'] = (int) shell_exec('ulimit -n 2>/dev/null');
            $diagnostics['madeline_processes'] = trim(shell_exec("ps aux | grep -E 'MadelineProto worker|entry.php.*madeline' | grep -v grep | wc -l"));
            $diagnostics['open_files_count'] = trim(shell_exec("lsof | wc -l 2>/dev/null"));
        }

        // Check session files
        $sessionFiles = [];
        if (is_dir($this->sessionPath)) {
            $files = scandir($this->sessionPath);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $fullPath = $this->sessionPath . '/' . $file;
                    $sessionFiles[$file] = [
                        'exists' => file_exists($fullPath),
                        'size' => file_exists($fullPath) ? filesize($fullPath) : 0,
                        'modified' => file_exists($fullPath) ? date('Y-m-d H:i:s', filemtime($fullPath)) : null
                    ];
                }
            }
        }
        $diagnostics['session_files'] = $sessionFiles;

        return $diagnostics;
    }
}
