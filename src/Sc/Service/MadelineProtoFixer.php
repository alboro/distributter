<?php

namespace Sc\Service;

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

        $this->logger->info('Checking for hanging MadelineProto processes');

        // Find MadelineProto processes
        $processes = shell_exec("ps aux | grep -E '(madeline-ipc|MadelineProto)' | grep -v grep | awk '{print $2}' || true");

        if (empty(trim($processes))) {
            $this->logger->info('No hanging MadelineProto processes found');
            return;
        }

        $pids = array_filter(explode("\n", trim($processes)));
        $this->logger->warning('Found hanging MadelineProto processes: ' . implode(', ', $pids));

        // Try graceful termination first
        foreach ($pids as $pid) {
            if (is_numeric($pid)) {
                posix_kill((int)$pid, SIGTERM);
            }
        }

        sleep(3);

        // Force kill if still running
        $remainingProcesses = shell_exec("ps aux | grep -E '(madeline-ipc|MadelineProto)' | grep -v grep | awk '{print $2}' || true");
        if (!empty(trim($remainingProcesses))) {
            $remainingPids = array_filter(explode("\n", trim($remainingProcesses)));
            $this->logger->warning('Force killing remaining processes: ' . implode(', ', $remainingPids));

            foreach ($remainingPids as $pid) {
                if (is_numeric($pid)) {
                    posix_kill((int)$pid, SIGKILL);
                }
            }
        }

        $this->logger->info('Cleaned up hanging processes');
    }

    /**
     * Clean up corrupted session files
     */
    private function cleanupSessionFiles(): void
    {
        $this->logger->info('Cleaning up MadelineProto session files');

        $sessionDirs = $this->findSessionDirectories();
        $cleanedCount = 0;

        foreach ($sessionDirs as $sessionDir) {
            if (!is_dir($sessionDir)) {
                continue;
            }

            $this->logger->debug("Cleaning session directory: $sessionDir");

            // Files that commonly cause issues
            $problematicFiles = [
                'ipc',
                'callback.ipc',
                'lock'
            ];

            foreach ($problematicFiles as $file) {
                $filePath = $sessionDir . '/' . $file;
                if (file_exists($filePath)) {
                    unlink($filePath);
                    $cleanedCount++;
                    $this->logger->debug("Removed: $filePath");
                }
            }

            // Remove all .lock files
            $lockFiles = glob($sessionDir . '/*.lock');
            foreach ($lockFiles as $lockFile) {
                if (file_exists($lockFile)) {
                    unlink($lockFile);
                    $cleanedCount++;
                    $this->logger->debug("Removed: $lockFile");
                }
            }
        }

        $this->logger->info("Cleaned up $cleanedCount session files");
    }

    /**
     * Find all MadelineProto session directories
     */
    private function findSessionDirectories(): array
    {
        $sessionDirs = [];

        // Add the main session path from config
        if (is_dir($this->sessionPath)) {
            $sessionDirs[] = $this->sessionPath;
        }

        // Search common locations
        $searchPaths = [
            $this->projectRoot,
            $this->projectRoot . '/bin',
            $this->projectRoot . '/config',
            $this->projectRoot . '/storage',
            $this->projectRoot . '/sessions'
        ];

        foreach ($searchPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            // Look for .madeline directories
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isDir() && str_contains($file->getFilename(), '.madeline')) {
                    $sessionDirs[] = $file->getPathname();
                }
            }
        }

        return array_unique($sessionDirs);
    }

    /**
     * Check PHP configuration for MadelineProto compatibility
     */
    private function checkPhpConfiguration(): void
    {
        $this->logger->info('Checking PHP configuration');

        // Check proc_open
        if (!function_exists('proc_open')) {
            $this->logger->error('proc_open function is disabled. MadelineProto requires proc_open to work.');
            $this->logger->error('Please enable proc_open in php.ini by removing it from disable_functions');
            throw new \RuntimeException('proc_open is disabled');
        } else {
            $this->logger->info('proc_open function is available');
        }

        // Check open_basedir
        $openBasedir = ini_get('open_basedir');
        if (!empty($openBasedir)) {
            $this->logger->warning("open_basedir is set: $openBasedir");
            $this->logger->warning('This might cause issues with MadelineProto');
        } else {
            $this->logger->info('open_basedir is not restricting access');
        }

        // Check memory limit
        $memoryLimit = ini_get('memory_limit');
        $this->logger->info("PHP memory limit: $memoryLimit");

        // Check max execution time
        $maxExecutionTime = ini_get('max_execution_time');
        $this->logger->info("PHP max execution time: $maxExecutionTime");
    }

    /**
     * Check if the exception is a MadelineProto IPC issue
     */
    public static function isMadelineProtoIpcException(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Could not connect to MadelineProto') ||
               str_contains($message, 'Too many open files') ||
               str_contains($message, 'IPC server') ||
               str_contains($message, 'proc_open') ||
               str_contains($message, 'open_basedir');
    }
}
