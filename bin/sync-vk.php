<?php

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(-1);

use Sc\Vk2Tg;

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->safeLoad();

$vk2tg = new Vk2Tg();
try {
    $vk2tg->send();
    // $vk2tg->pin();
} catch (\Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
