<?php

declare(strict_types=1);

/**
 * Скрипт миграции storage.json из формата v2 в формат v3
 *
 * Старый формат:
 * {
 *   "date": 1649596807,
 *   "items": [
 *     {"7019": 24},
 *     {"7019": 25},
 *     {"7055": 26}
 *   ]
 * }
 *
 * Новый формат:
 * {
 *   "items": [
 *     {"vk": "7019", "tg": "24,25"},
 *     {"vk": "7055", "tg": "26"}
 *   ]
 * }
 */

function migrateStorage(string $inputFile, string $outputFile): void
{
    // Проверяем существование входного файла
    if (!file_exists($inputFile)) {
        throw new RuntimeException("Входной файл не найден: $inputFile");
    }

    // Читаем старые данные
    $oldData = json_decode(file_get_contents($inputFile), true);
    if ($oldData === null) {
        throw new RuntimeException("Ошибка парсинга JSON из файла: $inputFile");
    }

    if (!isset($oldData['items']) || !is_array($oldData['items'])) {
        throw new RuntimeException("Неверный формат данных: отсутствует массив 'items'");
    }

    echo "Начинаем миграцию...\n";
    echo "Входной файл: $inputFile\n";
    echo "Выходной файл: $outputFile\n";
    echo "Количество записей для обработки: " . count($oldData['items']) . "\n\n";

    // Группируем данные по VK ID
    $groupedData = [];
    $processedCount = 0;

    foreach ($oldData['items'] as $item) {
        if (!is_array($item) || count($item) !== 1) {
            echo "Пропускаем некорректную запись: " . json_encode($item) . "\n";
            continue;
        }

        // Извлекаем VK ID и TG ID
        $vkId = (string)array_key_first($item);
        $tgId = (string)$item[$vkId];

        // Группируем по VK ID
        if (!isset($groupedData[$vkId])) {
            $groupedData[$vkId] = [];
        }

        $groupedData[$vkId][] = $tgId;
        $processedCount++;
    }

    echo "Обработано записей: $processedCount\n";
    echo "Уникальных VK постов: " . count($groupedData) . "\n\n";

    // Формируем новый формат
    $newData = [
        'items' => []
    ];

    foreach ($groupedData as $vkId => $tgIds) {
        $newData['items'][] = [
            'vk' => (string)$vkId,  // Принудительно приводим к строке
            'tg' => implode(',', $tgIds)
        ];
    }

    // Сортируем по VK ID для консистентности (сравниваем как числа для правильной сортировки)
    usort($newData['items'], fn($a, $b) => (int)$a['vk'] <=> (int)$b['vk']);

    // Сохраняем в новый файл с использованием JsonSerializable для контроля типов
    $jsonOutput = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($outputFile, $jsonOutput) === false) {
        throw new RuntimeException("Ошибка записи в файл: $outputFile");
    }

    echo "Миграция завершена успешно!\n";
    echo "Создан файл: $outputFile\n";
    echo "Размер нового файла: " . number_format(filesize($outputFile)) . " байт\n";

    // Показываем статистику
    showMigrationStats($groupedData);
}

function showMigrationStats(array $groupedData): void
{
    echo "\n=== Статистика миграции ===\n";

    $totalTgMessages = 0;
    $multiMessagePosts = 0;
    $maxMessagesPerPost = 0;

    foreach ($groupedData as $vkId => $tgIds) {
        $messageCount = count($tgIds);
        $totalTgMessages += $messageCount;

        if ($messageCount > 1) {
            $multiMessagePosts++;
        }

        if ($messageCount > $maxMessagesPerPost) {
            $maxMessagesPerPost = $messageCount;
        }
    }

    echo "Всего VK постов: " . count($groupedData) . "\n";
    echo "Всего TG сообщений: $totalTgMessages\n";
    echo "Постов с множественными сообщениями: $multiMessagePosts\n";
    echo "Максимум сообщений на пост: $maxMessagesPerPost\n";

    // Показываем примеры постов с множественными сообщениями
    if ($multiMessagePosts > 0) {
        echo "\nПримеры постов с множественными сообщениями:\n";
        $exampleCount = 0;
        foreach ($groupedData as $vkId => $tgIds) {
            if (count($tgIds) > 1 && $exampleCount < 5) {
                echo "  VK $vkId -> TG " . implode(',', $tgIds) . " (" . count($tgIds) . " сообщений)\n";
                $exampleCount++;
            }
        }
    }
}

function validateMigration(string $inputFile, string $outputFile): void
{
    echo "\n=== Валидация результата ===\n";

    // Читаем оба файла
    $oldData = json_decode(file_get_contents($inputFile), true);
    $newData = json_decode(file_get_contents($outputFile), true);

    // Подсчитываем общее количество связей
    $oldItemsCount = count($oldData['items']);

    $newItemsCount = 0;
    foreach ($newData['items'] as $item) {
        $tgIds = explode(',', $item['tg']);
        $newItemsCount += count($tgIds);
    }

    echo "Записей в старом формате: $oldItemsCount\n";
    echo "Записей в новом формате: $newItemsCount\n";

    if ($oldItemsCount === $newItemsCount) {
        echo "✅ Валидация пройдена: количество записей совпадает\n";
    } else {
        echo "❌ Ошибка валидации: количество записей не совпадает\n";
        exit(1);
    }
}

// Основная логика
try {
    $inputFile = 'new_storage.json';
    $outputFile = 'storage.v3.json';

    echo "🔄 Миграция storage.json v2 -> v3\n";
    echo "=====================================\n\n";

    migrateStorage($inputFile, $outputFile);
    validateMigration($inputFile, $outputFile);

    echo "\n🎉 Миграция выполнена успешно!\n";

} catch (Throwable $e) {
    echo "❌ Ошибка миграции: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
