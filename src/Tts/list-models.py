#!/usr/bin/env python3
"""
Script to list available TTS models by language
"""

from TTS.api import TTS

def list_models():
    """List all available TTS models grouped by language"""
    models = TTS.list_models()

    # Group models by language
    by_language = {}
    for model in models:
        if 'tts_models/' in model:
            parts = model.split('/')
            if len(parts) >= 3:
                lang = parts[1]
                if lang not in by_language:
                    by_language[lang] = []
                by_language[lang].append(model)

    # Print grouped models
    for lang, lang_models in sorted(by_language.items()):
        print(f"\n=== {lang.upper()} ===")
        for model in lang_models:
            print(f"  {model}")

if __name__ == "__main__":
    list_models()
