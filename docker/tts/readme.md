# TTS (Text-to-Speech) Module

Этот модуль содержит инструменты для синтеза речи с использованием различных TTS-движков.

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

# reference file can be even not wav & file can be passed instead of text
./bin/tts-wrapper.sh --quality --file creativity/text.txt creativity/narration.wav reference4.m4a
```

### Работа с референсными голосами
```bash
# Использование разных референсных файлов для разных языков
./tts-wrapper.sh --quality --language en "Professional announcement" output.wav shared/voice_professional.wav
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

**distributter-tts:**
- TTS (Coqui TTS)
- ruaccent (автоматическая расстановка ударений)
- torch, torchaudio (для нейронных моделей)
- transformers, spacy (для обработки текста)
- gTTS (Google Text-to-Speech)
- ffmpeg (для конвертации аудио)

## Примеры использования

### Многоязычная генерация
```bash
# Создание объявлений на разных языках с одним референсным голосом
./tts-wrapper.sh --quality --language ru "Добро пожаловать" welcome_ru.wav reference.wav
./tts-wrapper.sh --quality --language en "Welcome" welcome_en.wav reference.wav
./tts-wrapper.sh --quality --language es "Bienvenidos" welcome_es.wav reference.wav
```