<?php

declare(strict_types=1);
// https://dev.vk.com/api/access-token/authcode-flow-community

require_once __DIR__ . '/../vendor/autoload.php';

$client_id = 8052550; // 3529945
$client_secret = '7GcNWzLn0vB9jh43c2l1';
$redirect_uri = 'http://deism.aldem.ru/';
$code = '4a917bcf9673cab271';

/* * / var_dump(
    file_get_contents("https://oauth.vk.com/access_token?client_id=$client_id&client_secret=$client_secret&redirect_uri=$redirect_uri&code=$code")
); return; /* */

$oauth = new VK\OAuth\VKOAuth();

$response = $oauth->getAccessToken($client_id, $client_secret, $redirect_uri, $code);
var_dump($response);