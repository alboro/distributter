<?php

// Простой тестовый файл для проверки работоспособности vk2tg
require_once __DIR__ . '/../vendor/autoload.php';

try {
    // Проверяем автозагрузку классов
    if (!class_exists('Sc\Vk2Tg')) {
        throw new Exception('Класс Sc\Vk2Tg не найден');
    }

    // Проверяем наличие основных переменных окружения
    $requiredEnvVars = ['VK_TOKEN', 'TG_BOT_TOKEN', 'TG_CHANNEL_ID', 'VK_GROUP_ID'];
    $missingVars = [];

    foreach ($requiredEnvVars as $var) {
        if (!getenv($var)) {
            $missingVars[] = $var;
        }
    }

    if (!empty($missingVars)) {
        echo "WARN: Отсутствуют переменные окружения: " . implode(', ', $missingVars) . "\n";
    }

    // Проверяем создание основного объекта
    $vk2tg = new Sc\Vk2Tg();

    echo "OK: Все базовые тесты прошли успешно\n";
    exit(0);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "ERROR: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}
