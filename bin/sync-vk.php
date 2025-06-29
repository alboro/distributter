<?php

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(-1);

use Sc\Synchronizer;

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->safeLoad();

$syncer = new Synchronizer();
try {
    $syncer->invoke();
} catch (\Throwable $e) {
    $syncer->logger()->error(
        get_class($e) . ': ' . $e->getMessage()
    );
}
