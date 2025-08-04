FROM python:3.11-slim

WORKDIR /app

# Install system dependencies including Rust and ffmpeg
RUN apt-get update && apt-get install -y \
    build-essential \
    python3-dev \
    curl \
    git \
    ffmpeg \
    && curl https://sh.rustup.rs -sSf | sh -s -- -y \
    && export PATH="$HOME/.cargo/bin:$PATH" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Make sure Rust is in PATH for subsequent RUN commands
ENV PATH="/root/.cargo/bin:${PATH}"

# Upgrade pip and install build tools
RUN pip install --upgrade pip setuptools wheel

COPY docker/tts/tts_server.py /app/tts_server.py

EXPOSE 8000

CMD ["uvicorn", "tts_server:app", "--host", "0.0.0.0", "--port", "8000"]

# Install TTS and required dependencies with specific PyTorch version
RUN pip install --break-system-packages \
    torch==2.4.0 \
    torchaudio==2.4.0 \
    "numpy>=1.24.0,<2.0.0" \
    "scipy>=1.10.0" \
    "pandas>=1.5.0,<2.0.0" \
    "transformers>=4.33.0,<4.50.0" \
    spacy \
    gruut \
    sudachipy \
    ruaccent \
    gTTS \
    TTS

# Copy and install my_ruaccent utility
COPY docker/tts/my_ruaccent.py /usr/local/bin/my_ruaccent
RUN chmod +x /usr/local/bin/my_ruaccent

# Create quick-tts script for Google TTS
RUN echo '#!/bin/bash\n\
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then\n\
  echo "Usage: quick-tts [OPTIONS] TEXT OUTPUT_FILE"\n\
  echo "  --lang LANG      Language code (default: ru)"\n\
  echo "  TEXT             Text to synthesize"\n\
  echo "  OUTPUT_FILE      Output WAV file"\n\
  exit 0\n\
fi\n\
\n\
LANG="ru"\n\
TEXT=""\n\
OUTPUT=""\n\
\n\
while [[ $# -gt 0 ]]; do\n\
  case $1 in\n\
    --lang|-l)\n\
      LANG="$2"\n\
      shift 2\n\
      ;;\n\
    *)\n\
      if [ -z "$TEXT" ]; then\n\
        TEXT="$1"\n\
      elif [ -z "$OUTPUT" ]; then\n\
        OUTPUT="$1"\n\
      fi\n\
      shift\n\
      ;;\n\
  esac\n\
done\n\
\n\
if [ -z "$TEXT" ] || [ -z "$OUTPUT" ]; then\n\
  echo "Error: TEXT and OUTPUT_FILE are required"\n\
  exit 1\n\
fi\n\
\n\
# Google TTS через gTTS\n\
python3 -c "\n\
from gtts import gTTS\n\
import os\n\
try:\n\
    tts = gTTS(text='"'"'$TEXT'"'"', lang='"'"'$LANG'"'"', slow=False)\n\
    tts.save('"'"'/tmp/temp.mp3'"'"')\n\
    os.system('"'"'ffmpeg -i /tmp/temp.mp3 -ar 22050 -ac 1 $OUTPUT -y 2>/dev/null'"'"')\n\
    if os.path.exists('"'"'/tmp/temp.mp3'"'"'):\n\
        os.remove('"'"'/tmp/temp.mp3'"'"')\n\
    print('"'"'✅ gTTS: Audio generated successfully'"'"')\n\
except Exception as e:\n\
    print(f'"'"'❌ gTTS Error: {e}'"'"')\n\
    exit(1)\n\
"\n\
\n\
# Проверяем, создался ли файл\n\
if [ ! -f "$OUTPUT" ] || [ ! -s "$OUTPUT" ]; then\n\
  echo "❌ Failed to create audio file"\n\
  exit 1\n\
fi\n\
' > /usr/local/bin/quick-tts && chmod +x /usr/local/bin/quick-tts

# Create shared directory
RUN mkdir -p /app/shared

# Добавьте в конец вашего Dockerfile
RUN pip install fastapi uvicorn python-multipart requests

COPY docker/tts/tts_server.py /app/tts_server.py

EXPOSE 8000

CMD ["uvicorn", "tts_server:app", "--host", "0.0.0.0", "--port", "8000"]