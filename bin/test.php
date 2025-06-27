<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->safeLoad();

$vk = new VK\Client\VKApiClient();
$response = $vk->users()->get(getenv('VK_TOKEN'), [
    'user_ids'  => [170387180],
    'fields'    => [],
]);
var_dump($response[0]['first_name'] . ' ' . $response[0]['last_name']);
