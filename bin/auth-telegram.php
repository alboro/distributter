<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Sc\Config\AppConfig;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings;

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../bin/');
$dotenv->safeLoad();

$config = AppConfig::fromEnvironment();

if (empty($config->tgRetrieverApiId) || empty($config->tgRetrieverApiHash)) {
    echo "Error: TG_API_ID and TG_API_HASH must be set in environment\n";
    exit(1);
}

$settings = new Settings();
$settings->getAppInfo()->setApiId((int)$config->tgRetrieverApiId);
$settings->getAppInfo()->setApiHash($config->tgRetrieverApiHash);

echo "Initializing Telegram authentication...\n";
echo "Session file: {$config->tgRetrieverSessionFile}\n";

$madelineProto = new API($config->tgRetrieverSessionFile, $settings);
$madelineProto->start();