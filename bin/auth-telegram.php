<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Sc\Config\AppConfig;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings;

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../bin/');
$dotenv->safeLoad();

$config = AppConfig::fromEnvironment();

if (empty($config->tgRetrieverConfig->apiId) || empty($config->tgRetrieverConfig->apiHash)) {
    echo "Error: TG_API_ID and TG_API_HASH must be set in environment\n";
    exit(1);
}

$settings = new Settings();
$settings->getAppInfo()->setApiId((int)$config->tgRetrieverConfig->apiId);
$settings->getAppInfo()->setApiHash($config->tgRetrieverConfig->apiHash);

echo "Initializing Telegram authentication...\n";
echo "Session file: {$config->tgRetrieverConfig->sessionFile}\n";

$madelineProto = new API($config->tgRetrieverConfig->sessionFile, $settings);
$madelineProto->start();