# TTS (Text-to-Speech) Module

Этот модуль содержит инструменты для синтеза речи с использованием различных TTS-движков.

## Архитектура

- **Быстрый TTS**: Простые движки для быстрой генерации (Google TTS, Festival, etc.)
- **Качественный TTS**: Coqui XTTS v2 с клонированием голоса и автоматической расстановкой ударений

## Управление контейнерами

### Запуск и остановка
```bash
# Запуск основных контейнеров (app + tts)
docker-compose up -d

# Запуск только TTS контейнера
docker-compose up tts -d

# Остановка всех контейнеров
docker-compose down

# Перезапуск TTS контейнера
docker-compose restart tts

# Просмотр логов TTS контейнера
docker-compose logs -f tts
```

### Быстрый TTS (опциональный контейнер)
```bash
# Запуск быстрого TTS контейнера
docker-compose --profile optional up fasttts -d

# Остановка быстрого TTS
docker-compose --profile optional down fasttts
```

## Файлы

### `my_ruaccent.py`
Утилита для автоматической расстановки ударений в русском тексте.

**Возможности:**
- Использует библиотеку `ruaccent` для анализа текста
- Преобразует формат ударений `молок+о` в Unicode `молокó`
- Поддерживает ввод через аргументы командной строки или stdin

**Использование:**
```bash
# Через аргументы
my_ruaccent "молоко в стакане"
# Результат: молокó в стакáне

# Через stdin
echo "молоко в стакане" | my_ruaccent
```

## TTS Wrapper Script

Универсальный скрипт `tts-wrapper.sh` для управления всеми аспектами генерации речи.

### Полный синтаксис
```bash
./tts-wrapper.sh [OPTIONS] TEXT OUTPUT_FILE [REFERENCE_VOICE]

Опции:
  --fast, -f           Быстрый TTS (Google TTS по умолчанию)
  --quality, -q        Качественный TTS (Coqui XTTS v2)
  --voice ENGINE       Движок дл�� быстрого TTS: gtts, festival, espeak, pyttsx3
  --language LANG      Язык для качественного TTS: ru, en, es, fr, de, it, etc.
  --no-accents         Отключить автоматическую расстановку ударений
  --help, -h           Показать справку
```

### Быстрый синтез
```bash
# Google TTS (русский по умолчанию)
./tts-wrapper.sh --fast "Привет мир" output.wav
```

### Качественный синтез с языками
```bash
# Русский с ударениями и клонированием голоса
./tts-wrapper.sh --quality "молоко в стакане" ru_output.wav reference_ru.wav

# Английский с клонированием голоса
./tts-wrapper.sh --quality --language en "Hello beautiful world" en_output.wav reference_en.wav

# Испанский
./tts-wrapper.sh --quality --language es "Hola mundo hermoso" es_output.wav reference_es.wav

# Французский
./tts-wrapper.sh --quality --language fr "Bonjour le monde" fr_output.wav reference_fr.wav

# Немецкий
./tts-wrapper.sh --quality --language de "Hallo schöne Welt" de_output.wav reference_de.wav

# Итальянский
./tts-wrapper.sh --quality --language it "Ciao mondo bellissimo" it_output.wav reference_it.wav

# Русский без автоматических ударений
./tts-wrapper.sh --quality --no-accents --language ru "простой текст" simple_ru.wav reference_ru.wav

# reference file can be even not wav & file can be passed instead of text
./bin/tts-wrapper.sh --quality --file creativity/text.txt creativity/narration.wav reference4.m4a
```

### Работа с референсными голосами
```bash
# Использование разных референсных файлов для разных языков
./tts-wrapper.sh --quality --language en "Professional announcement" output.wav shared/voice_professional.wav
./tts-wrapper.sh --quality --language ru "Личное сообщение" output.wav shared/voice_personal.wav

# Клонирование конкретного голоса
./tts-wrapper.sh --quality --language en "Clone my voice" cloned.wav shared/my_voice_sample.wav
```

## Прямая работа с контейнерами

### Контейнер distributter-tts (качественный TTS)
```bash
# Проверка статуса
docker exec distributter-tts python3 -c "from TTS.api import TTS; print('✅ TTS ready')"

# Список доступных моделей
docker exec distributter-tts tts --list_models | grep -E "(ru|multilingual)"

# Прямой запуск с ударениями
docker exec distributter-tts my_ruaccent "молоко в стакане"

# Генерация TTS напрямую
docker exec distributter-tts bash -c "echo 'y' | tts \
  --text 'молокó в стакáне' \
  --model_name tts_models/multilingual/multi-dataset/xtts_v2 \
  --language_idx ru \
  --speaker_wav /app/shared/reference.wav \
  --out_path /app/shared/output.wav"

# Многоязычный TTS
docker exec distributter-tts bash -c "echo 'y' | tts \
  --text 'Hello world' \
  --model_name tts_models/multilingual/multi-dataset/xtts_v2 \
  --language_idx en \
  --speaker_wav /app/shared/reference_en.wav \
  --out_path /app/shared/output_en.wav"
```

### Контейнер distributter-fasttts (быстрый TTS)
```bash
# Проверка доступности
docker exec distributter-fasttts quick-tts --help

# Google TTS
docker exec distributter-fasttts quick-tts --voice gtts "Быстрая генерация" /app/shared/fast.wav

# Festival TTS
docker exec distributter-fasttts quick-tts --voice festival "Fast generation" /app/shared/fast_en.wav

# Espeak с настройкой скорости
docker exec distributter-fasttts quick-tts --voice espeak --speed 120 "Медленная речь" /app/shared/slow.wav
```

## Поддерживаемые языки

### Coqui XTTS v2 (качественный TTS)
- **ru** - русский 🇷🇺
- **en** - английский 🇺🇸
- **es** - испанский 🇪🇸  
- **fr** - французский 🇫🇷
- **de** - немецкий 🇩🇪
- **it** - итальянский 🇮🇹
- **pt** - португальский 🇵🇹
- **pl** - польский 🇵🇱
- **tr** - турецкий 🇹🇷
- **nl** - голландский 🇳🇱
- **cs** - чешский 🇨🇿
- **ar** - арабский 🇸🇦
- **zh-cn** - китайский 🇨🇳
- **hu** - венгерский 🇭🇺
- **ko** - корейский 🇰🇷
- **ja** - японский 🇯🇵
- **hi** - хинди 🇮🇳

### Google TTS (быстрый TTS)
Поддерживает большинство языков мира через коды ISO (ru, en, es, fr, de, it, pt, pl, etc.)

## Установка зависимостей

Все необходимые пакеты устанавливаются автоматически при сборке Docker контейнеров:

**distributter-tts:**
- TTS (Coqui TTS)
- ruaccent (автоматическая расстановка ударений)
- torch, torchaudio (для нейронных моделей)
- transformers, spacy (для обработки текста)

**distributter-fasttts:**
- gTTS (Google Text-to-Speech)
- festival, espeak (локальные TTS движки)
- ffmpeg (для конвертации аудио)

## Примеры использования

### Многоязычная генерация
```bash
# Создание объявлений на разных языках с одним референсным голосом
./tts-wrapper.sh --quality --language ru "Добро пожаловать" welcome_ru.wav reference.wav
./tts-wrapper.sh --quality --language en "Welcome" welcome_en.wav reference.wav
./tts-wrapper.sh --quality --language es "Bienvenidos" welcome_es.wav reference.wav
```

### Генерация с разными голосами
```bash
# Мужской голос
./tts-wrapper.sh --quality "Деловая презентация" business.wav shared/male_voice.wav

# Женский голос  
./tts-wrapper.sh --quality "Личное сообщение" personal.wav shared/female_voice.wav

# Детский голос
./tts-wrapper.sh --quality "Сказка для детей" story.wav shared/child_voice.wav
```

### Пакетная обработ��а
```bash
# Скрипт для массовой генерации
for text in "Первое сообщение" "Второе сообщение" "Третье сообщение"; do
  filename=$(echo "$text" | sed 's/ /_/g' | tr '[:upper:]' '[:lower:]').wav
  ./tts-wrapper.sh --quality "$text" "$filename" reference.wav
done
```
