<?php

require 'vendor/autoload.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->safeLoad();

try {
    echo "Initializing MadelineProto..." . PHP_EOL;

    $settings = new Settings();
    $settings->getAppInfo()->setApiId(2834756);
    $settings->getAppInfo()->setApiHash('234987ty2394857t');

    // Disable MadelineProto logging
    $settings->getLogger()->setType(\danog\MadelineProto\Logger::ECHO_LOGGER);
    $settings->getLogger()->setLevel(\danog\MadelineProto\Logger::LEVEL_FATAL);

    $madelineProto = new API('session.madeline', $settings);
    $madelineProto->start();

    echo "Connection successful!" . PHP_EOL;

    $inviteLink = 'https://t.me/+';
    $channelId = '';

    // First update peers database
    echo "Updating peers database..." . PHP_EOL;
    try {
        // Get dialogs list to update peers database
        $dialogs = $madelineProto->messages->getDialogs([
            'offset_date' => 0,
            'offset_id' => 0,
            'offset_peer' => ['_' => 'inputPeerEmpty'],
            'limit' => 100,
            'hash' => [0]
        ]);
        echo "Loaded dialogs: " . count($dialogs['dialogs']) . PHP_EOL;

        // Also try to get self info for database update
        $self = $madelineProto->getSelf();
        echo "Authorized as: " . ($self['first_name'] ?? 'Unknown') . PHP_EOL;

    } catch (Exception $e) {
        echo "Peers database update error: " . $e->getMessage() . PHP_EOL;
    }

    echo "Checking channel availability: $channelId" . PHP_EOL;

    try {
        $channelInfo = $madelineProto->getInfo($channelId);
        echo "Channel found!" . PHP_EOL;
        echo "Type: " . $channelInfo['type'] . PHP_EOL;
        echo "ID: " . $channelInfo['bot_api_id'] . PHP_EOL;

    } catch (Exception $e) {
        echo "Channel unavailable: " . $e->getMessage() . PHP_EOL;
        echo "Trying to join via invite link..." . PHP_EOL;

        try {
            // Parse invite link
            $hash = str_replace(['https://t.me/+', 'https://t.me/joinchat/'], '', $inviteLink);
            echo "Invite link hash: $hash" . PHP_EOL;

            // Join channel
            $result = $madelineProto->messages->importChatInvite(['hash' => $hash]);
            echo "Join result: " . json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;

            // Now check channel availability again
            echo "Rechecking channel availability..." . PHP_EOL;
            $channelInfo = $madelineProto->getInfo($channelId);
            echo "Channel is now available!" . PHP_EOL;
            echo "Type: " . $channelInfo['type'] . PHP_EOL;
            echo "ID: " . $channelInfo['bot_api_id'] . PHP_EOL;

        } catch (Exception $e2) {
            echo "Join error: " . $e2->getMessage() . PHP_EOL;
        }
    }

    // Try to get messages
    echo "Trying to get messages from the channel..." . PHP_EOL;
    try {
        $messages = $madelineProto->messages->getHistory([
            'peer' => $channelId,
            'limit' => 1,
        ]);

        echo "Messages received: " . count($messages['messages']) . PHP_EOL;

        if (!empty($messages['messages'])) {
            $lastMessage = $messages['messages'][0];
            echo "Last message ID: " . $lastMessage['id'] . PHP_EOL;
            echo "Text: " . (substr($lastMessage['message'] ?? '', 0, 100) . '...') . PHP_EOL;
        }

    } catch (Exception $e) {
        echo "Error getting messages: " . $e->getMessage() . PHP_EOL;
    }

} catch (Exception $e) {
    echo "General error: " . $e->getMessage() . PHP_EOL;
}
