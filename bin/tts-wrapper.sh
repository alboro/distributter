#!/bin/bash

# Универсальный скрипт для генерации TTS
# Использование: ./bin/tts-wrapper.sh [--fast|--quality] "текст" output.wav [reference.wav]

# Определяем базовую директорию проекта (родительская директория от bin/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Функция для конвертации аудио файлов в WAV
convert_to_wav() {
  local input_file="$1"
  local output_file="$2"

  echo "🔄 Converting $input_file to WAV format..."

  # Используем ffmpeg в контейнере tts для конвертации
  docker exec distributter-tts ffmpeg -i "/app/shared/$input_file" -ar 22050 -ac 1 -acodec pcm_s16le "/app/shared/$output_file" -y 2>/dev/null

  if [ $? -eq 0 ]; then
    echo "✅ Conversion successful: $output_file"
    return 0
  else
    echo "❌ Conversion failed"
    return 1
  fi
}

FAST_MODE=false
TEXT=""
OUTPUT=""
REFERENCE=""
INPUT_FILE=""
USE_ACCENTS=true     # По умолчанию используем ударения для качественного TTS

# Парсим аргументы
while [[ $# -gt 0 ]]; do
  case $1 in
    --fast|-f)
      FAST_MODE=true
      shift
      ;;
    --quality|-q)
      FAST_MODE=false
      shift
      ;;
    --file|--input-file)
      INPUT_FILE="$2"
      shift 2
      ;;
    --no-accents)
      USE_ACCENTS=false
      shift
      ;;
    --help|-h)
      echo "TTS Wrapper Script"
      echo "Usage: $0 [OPTIONS] TEXT|--file FILE OUTPUT_FILE [REFERENCE_VOICE]"
      echo ""
      echo "Options:"
      echo "  --fast, -f           Use fast TTS (Google TTS)"
      echo "  --quality, -q        Use quality TTS (Coqui XTTS v2)"
      echo "  --file FILE          Read text from file instead of command line"
      echo "  --input-file FILE    Same as --file"
      echo "  --no-accents         Disable automatic accent placement for quality TTS"
      echo "  --help, -h           Show this help"
      echo ""
      echo "Examples:"
      echo "  $0 --fast \"Привет мир\" output.wav"
      echo "  $0 --quality \"Молоко в стакане\" output.wav reference.wav"
      echo "  $0 --quality --file creativity/text.txt output.wav reference.wav"
      echo "  $0 --fast --file message.txt audio.wav"
      echo "  $0 --quality --no-accents \"Текст без ударений\" output.wav reference.wav"
      exit 0
      ;;
    --*)
      echo "❌ Unknown option: $1"
      echo "Use --help for available options"
      exit 1
      ;;
    *)
      if [ -z "$TEXT" ] && [ -z "$INPUT_FILE" ]; then
        TEXT="$1"
      elif [ -z "$OUTPUT" ]; then
        OUTPUT="$1"
      elif [ -z "$REFERENCE" ]; then
        REFERENCE="$1"
      fi
      shift
      ;;
  esac
done

# Если указан файл, читаем текст из него
if [ -n "$INPUT_FILE" ]; then
  # Если путь не абсолютный и не относительный, ищем в shared/
  if [[ "$INPUT_FILE" != /* && "$INPUT_FILE" != ./* && "$INPUT_FILE" != shared/* ]]; then
    INPUT_FILE_PATH="$PROJECT_DIR/shared/$INPUT_FILE"
    echo "🔍 Looking for file in shared/: $INPUT_FILE"
  elif [[ "$INPUT_FILE" == shared/* ]]; then
    INPUT_FILE_PATH="$PROJECT_DIR/$INPUT_FILE"
  else
    INPUT_FILE_PATH="$INPUT_FILE"
  fi

  if [ ! -f "$INPUT_FILE_PATH" ]; then
    echo "❌ Input file not found: $INPUT_FILE"
    echo "   Checked location: $INPUT_FILE_PATH"
    exit 1
  fi

  TEXT=$(cat "$INPUT_FILE_PATH")
  if [ -z "$TEXT" ]; then
    echo "❌ Input file is empty: $INPUT_FILE_PATH"
    exit 1
  fi
  echo "📄 Reading text from file: $INPUT_FILE_PATH"
fi

# Проверяем обязательные параметры
if [ -z "$TEXT" ] || [ -z "$OUTPUT" ]; then
  echo "Error: TEXT (or --file) and OUTPUT_FILE are required"
  echo "Use --help for usage information"
  exit 1
fi

# Определяем контейнер и команду
if [ "$FAST_MODE" = true ]; then
  echo "🚀 Using FAST TTS (Google TTS)..."
  docker exec distributter-tts quick-tts "$TEXT" "/app/shared/$OUTPUT"
else
  echo "🎯 Using QUALITY TTS (Coqui XTTS v2)..."
  if [ -z "$REFERENCE" ]; then
    echo "Error: Reference voice file is required for quality TTS"
    exit 1
  fi

  # Проверяем референсный файл и добавляем путь shared/ если нужно
  if [ ! -f "$PROJECT_DIR/shared/$REFERENCE" ] && [ ! -f "$REFERENCE" ]; then
    echo "❌ Reference file not found: $REFERENCE"
    echo "   Checked locations:"
    echo "   - $PROJECT_DIR/shared/$REFERENCE"
    echo "   - $REFERENCE"
    exit 1
  fi

  # Если файл найден в shared/, используем относительный путь
  if [ -f "$PROJECT_DIR/shared/$REFERENCE" ]; then
    REFERENCE_PATH="$REFERENCE"
    echo "📁 Found reference file: shared/$REFERENCE"
  else
    # Файл указан с полным путем
    REFERENCE_PATH=$(basename "$REFERENCE")
    echo "📁 Using reference file: $REFERENCE"
  fi

  # Проверяем и конвертируем референсный файл если нужно
  REFERENCE_WAV="$REFERENCE_PATH"
  REFERENCE_EXT="${REFERENCE_PATH##*.}"
  REFERENCE_EXT=$(echo "$REFERENCE_EXT" | tr '[:upper:]' '[:lower:]')

  if [ "$REFERENCE_EXT" != "wav" ]; then
    echo "🔍 Detected non-WAV reference file: $REFERENCE_PATH (.$REFERENCE_EXT)"

    # Создаем имя для конвертированного файла
    REFERENCE_BASE="${REFERENCE_PATH%.*}"
    REFERENCE_WAV="${REFERENCE_BASE}_converted.wav"

    # Проверяем, существует ли уже конв��ртированный файл
    if [ -f "$PROJECT_DIR/shared/$REFERENCE_WAV" ]; then
      echo "📁 Found existing converted file: $REFERENCE_WAV"
    else
      # Конвертируем в WAV
      if ! convert_to_wav "$REFERENCE_PATH" "$REFERENCE_WAV"; then
        echo "❌ Failed to convert reference file to WAV"
        exit 1
      fi
    fi

    echo "🎵 Using converted reference: $REFERENCE_WAV"
  else
    echo "🎵 Using WAV reference: $REFERENCE_WAV"
  fi

  # Обрабатываем текст для качественного TTS
  PROCESSED_TEXT="$TEXT"

  if [ "$USE_ACCENTS" = true ]; then
    echo "📝 Processing text with accent placement..."

    # Обрабатываем текст через my_ruaccent
    PROCESSED_TEXT=$(docker exec distributter-tts my_ruaccent "$TEXT")

    if [ $? -eq 0 ] && [ -n "$PROCESSED_TEXT" ]; then
      echo "✅ Accents placed: $PROCESSED_TEXT"
    else
      echo "⚠️ Accent placement failed, using original text"
      PROCESSED_TEXT="$TEXT"
    fi
  else
    echo "📝 Using original text without accent placement..."
    PROCESSED_TEXT="$TEXT"
  fi

  # Создаем временный файл с обработанным текстом
  echo "$PROCESSED_TEXT" > "$PROJECT_DIR/shared/temp_text.txt"

  # Запускаем Coqui TTS с обработанным текстом и конвертированным рефе��енсом
  docker exec distributter-tts bash -c "echo 'y' | tts --text \"\$(cat /app/shared/temp_text.txt)\" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx ru --speaker_wav /app/shared/$REFERENCE_WAV --out_path /app/shared/$OUTPUT"

  # Удаляем временный файл
  rm -f "$PROJECT_DIR/shared/temp_text.txt"
fi

echo "✅ Audio generated: $OUTPUT"
