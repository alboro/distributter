#!/bin/bash

echo "=== Fixing MadelineProto 'Too many open files' issue ==="

# Check current ulimit
echo "Current file descriptor limit: $(ulimit -n)"

# Increase ulimit for current session
ulimit -n 65536
echo "Increased file descriptor limit to: $(ulimit -n)"

# Clean up MadelineProto session files
echo "Cleaning up MadelineProto session files..."
cd /srv/aldem/vk-sync/vk2tg/vk2tg/bin/session.madeline
rm -f ipc callback.ipc lock *.lock
echo "Cleaned up socket and lock files"

# Also clean up the main session directory
cd /srv/aldem/vk-sync/vk2tg/vk2tg/session.madeline
rm -f ipc callback.ipc lock *.lock
echo "Cleaned up main session files"

# Check for any remaining MadelineProto processes
echo "Checking for remaining MadelineProto processes..."
ps aux | grep madeline-ipc | grep -v grep

echo "=== Fix completed ==="
echo "You can now run: cd /srv/aldem/vk-sync/vk2tg/vk2tg && php bin/distributter.php"
