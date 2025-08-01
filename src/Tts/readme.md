# Coqui TTS

# 1. Install Python (if not already installed)
sudo apt update
sudo apt install python3 python3-pip
# 2. Install Coqui TTS
pip3 install TTS
# 3. Convert text to audio (Simple English model):
tts --text "Hello world!" --model_name tts_models/en/ljspeech/tacotron2-DDC --out_path output.wav

# For Russian text, use multilingual XTTS v2 with language and reference audio:
tts --text "Привет мир!" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx ru --speaker_wav path/to/reference.wav --out_path ru_output.wav
# additional
sudo apt install ffmpeg

## Language Change

### Popular languages:
```bash
# English (several variants)
tts --text "Hello, world!" --model_name tts_models/en/ljspeech/tacotron2-DDC --out_path en_output.wav
tts --text "Hello, world!" --model_name tts_models/en/vctk/vits --out_path en_output2.wav

# German
tts --text "Hallo Welt!" --model_name tts_models/de/thorsten/tacotron2-DDC --out_path de_output.wav

# French
tts --text "Bonjour le monde!" --model_name tts_models/fr/css10/vits --out_path fr_output.wav

# Spanish
tts --text "¡Hola mundo!" --model_name tts_models/es/css10/vits --out_path es_output.wav

# Italian
tts --text "Ciao mondo!" --model_name tts_models/it/mai_female/glow-tts --out_path it_output.wav

# Ukrainian
tts --text "Привіт світ!" --model_name tts_models/uk/mai/glow-tts --out_path uk_output.wav

# Russian (using multilingual model with language specification)
tts --text "Привет мир!" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx ru --speaker_wav reference.wav --out_path ru_output.wav
```

### View all available models:
```bash
# List of all models
tts --list_models

# Or use our script to group by languages
python3 src/Tts/list-models.py
```

### Multilingual models:
```bash
# YourTTS - supports many languages (simpler to use)
tts --text "Hello world" --model_name tts_models/multilingual/multi-dataset/your_tts --out_path multilingual.wav

# XTTS v2 - voice cloning in many languages (requires reference audio)
# First, you need a reference audio file (3-10 seconds of clear speech)
tts --text "Hello world" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx en --speaker_wav reference.wav --out_path xtts.wav

# For Russian with XTTS v2:
tts --text "Привет мир!" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx ru --speaker_wav reference.wav --out_path xtts_ru.wav
```

### XTTS v2 Voice Cloning:
XTTS v2 is a powerful voice cloning model that supports 17 languages including Russian:
- **Supported languages**: en, es, fr, de, it, pt, pl, tr, ru, nl, cs, ar, zh-cn, hu, ko, ja, hi
- **Requires reference audio**: You need a 3-10 second clear audio sample of the target voice
- **High quality**: Produces very natural speech with voice cloning capabilities

```bash
# Example with voice cloning
tts --text "Это клонированный голос!" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx ru --speaker_wav my_voice_sample.wav --out_path cloned_voice.wav
```
from docker
```
docker exec distributter-tts bash -c "echo 'y' | tts --text \"\$(cat /app/shared/test.txt)\" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx ru --speaker_wav /app/shared/reference.wav --out_path /app/shared/russian.wav"
```

## Important points:

1. **Quality depends on the model** - some languages have better models
2. **Model size** - the first launch will download the model (it can be large)
3. **Language specifics** - consider the pronunciation features
4. **Multilingual models** - can work with several languages, but the quality may be lower than specialized ones
