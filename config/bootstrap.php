<?php

declare(strict_types=1);

namespace Sc;

use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(-1);
set_time_limit(300); // 5 minutes maximum
ignore_user_abort(false);

$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->load(); // Используем load() вместо safeLoad() чтобы не перезаписывать существующие переменные
