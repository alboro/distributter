#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys
import re
from ruaccent import RUAccent

def accent_to_unicode(text):
    """Преобразует ударения из формата ruaccent (+о) в Unicode (ó)"""
    # Словарь соответствий для основных гласных
    accent_map = {
        "а": "а́", "е": "е́", "ё": "ё", "и": "и́", "о": "о́",
        "у": "у́", "ы": "ы́", "э": "э́", "ю": "ю́", "я": "я́",
        "А": "А́", "Е": "Е́", "Ё": "Ё", "И": "И́", "О": "О́",
        "У": "У́", "Ы": "Ы́", "Э": "Э́", "Ю": "Ю́", "Я": "Я́"
    }

    # Заменяем паттерн: знак ударения + гласная на ударную гласную
    def replace_accent(match):
        letter = match.group(1)
        return accent_map.get(letter, letter)

    # Ищем паттерн: знак ударения (+) + гласная
    pattern = r"\+([аеёиоуыэюяАЕЁИОУЫЭЮЯ])"
    result = re.sub(pattern, replace_accent, text)

    return result

def main():
    # Инициализируем RUAccent
    try:
        accentizer = RUAccent()
        accentizer.load()
    except Exception as e:
        print(f"Error loading RUAccent: {e}", file=sys.stderr)
        sys.exit(1)

    # Читаем текст из stdin или аргументов
    if len(sys.argv) > 1:
        text = " ".join(sys.argv[1:])
    else:
        text = sys.stdin.read().strip()

    if not text:
        print("No input text provided", file=sys.stderr)
        sys.exit(1)

    try:
        # Обрабатываем текст через ruaccent
        accented_text = accentizer.process_all(text)

        # Преобразуем в Unicode ударения
        unicode_text = accent_to_unicode(accented_text)

        # Выводим результат
        print(unicode_text)

    except Exception as e:
        print(f"Error processing text: {e}", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
