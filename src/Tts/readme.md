# 1. Install Python (if not already installed)
sudo apt update
sudo apt install python3 python3-pip
# 2. Install Coqui TTS
pip3 install TTS
# 3. Convert text to audio (Russian voice):
tts --text "Это тестовое сообщение." --model_name tts_models/ru/v3_1_1 --out_path output.wav
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
# YourTTS - supports many languages
tts --text "Hello world" --model_name tts_models/multilingual/multi-dataset/your_tts --out_path multilingual.wav

# XTTS - voice cloning in many languages
tts --text "Hello world" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --out_path xtts.wav
```

## Important points:

1. **Quality depends on the model** - some languages have better models
2. **Model size** - the first launch will download the model (it can be large)
3. **Language specifics** - consider the pronunciation features
4. **Multilingual models** - can work with several languages, but the quality may be lower than specialized ones
