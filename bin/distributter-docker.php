<?php

// Настройки для работы MadelineProto в Docker контейнере
$_ENV['MADELINE_PROTO_SLOW_IPC'] = true; // just: define('MADELINE_WORKER_TYPE', 'madeline-ipc')

require_once __DIR__ . '/distributter.php';