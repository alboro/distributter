#!/bin/bash

# Универсальный скрипт для генерации TTS
# Использование: ./tts-wrapper.sh [--fast|--quality] "текст" output.wav [reference.wav]

FAST_MODE=false
TEXT=""
OUTPUT=""
REFERENCE=""
VOICE_ENGINE="gtts"  # По умолчанию используем Google TTS для быстрого режима

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
    --voice|-v)
      VOICE_ENGINE="$2"
      shift 2
      ;;
    --help|-h)
      echo "TTS Wrapper Script"
      echo "Usage: $0 [OPTIONS] TEXT OUTPUT_FILE [REFERENCE_VOICE]"
      echo ""
      echo "Options:"
      echo "  --fast, -f           Use fast TTS (Google TTS by default)"
      echo "  --quality, -q        Use quality TTS (Coqui XTTS v2)"
      echo "  --voice ENGINE, -v   Fast TTS engine: gtts, festival, espeak, pyttsx3"
      echo "  --help, -h           Show this help"
      echo ""
      echo "Examples:"
      echo "  $0 --fast \"Привет мир\" output.wav"
      echo "  $0 --fast --voice festival \"Привет мир\" output.wav"
      echo "  $0 --quality \"Привет мир\" output.wav reference.wav"
      exit 0
      ;;
    *)
      if [ -z "$TEXT" ]; then
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

# Проверяем обязательные параметры
if [ -z "$TEXT" ] || [ -z "$OUTPUT" ]; then
  echo "Error: TEXT and OUTPUT_FILE are required"
  echo "Use --help for usage information"
  exit 1
fi

# Определяем контейнер и команду
if [ "$FAST_MODE" = true ]; then
  echo "🚀 Using FAST TTS ($VOICE_ENGINE)..."
  docker exec distributter-rhvoice quick-tts --voice "$VOICE_ENGINE" "$TEXT" "/app/shared/$OUTPUT"
else
  echo "🎯 Using QUALITY TTS (Coqui XTTS v2)..."
  if [ -z "$REFERENCE" ]; then
    echo "Error: Reference voice file is required for quality TTS"
    exit 1
  fi

  # Создаем временный файл с текстом
  echo "$TEXT" > "/Users/aldem/PhpstormProjects/vk2tg/shared/temp_text.txt"

  # Запускаем Coqui TTS
  docker exec distributter-tts bash -c "echo 'y' | tts --text \"\$(cat /app/shared/temp_text.txt)\" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx ru --speaker_wav /app/shared/$REFERENCE --out_path /app/shared/$OUTPUT"

  # Удаляем временный файл
  rm -f "/Users/aldem/PhpstormProjects/vk2tg/shared/temp_text.txt"
fi

echo "✅ Audio generated: $OUTPUT"
