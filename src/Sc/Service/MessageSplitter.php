<?php

declare(strict_types=1);

namespace Sc\Service;

/**
 * Component for safely splitting long messages while preserving HTML structure
 */
readonly class MessageSplitter
{
    public function __construct(
        private int $maxMessageLength = 4096
    ) {}

    /**
     * Splits long message into parts while preserving HTML structure
     */
    public function splitMessageSafely(string $text): array
    {
        if (strlen($text) <= $this->maxMessageLength) {
            return [$text];
        }

        // Split text into sentences
        $sentences = $this->splitIntoSentences($text);

        $parts = [];
        $currentPart = '';
        $htmlStack = [];

        foreach ($sentences as $sentence) {
            $sentenceWithHtml = $this->processHtmlInSentence($sentence, $htmlStack);

            // Check if sentence fits in current part
            $testPart = $currentPart . $sentenceWithHtml;

            if (strlen($testPart) > $this->maxMessageLength) {
                // If current part is not empty, save it
                if (!empty($currentPart)) {
                    $currentPart .= $this->closeOpenTags($htmlStack);
                    $parts[] = $currentPart;
                    $currentPart = $this->reopenTags($htmlStack);
                }

                // If even one sentence is too long, split it forcefully
                if (strlen($currentPart . $sentenceWithHtml) > $this->maxMessageLength) {
                    $sentenceParts = $this->splitLongSentence($sentenceWithHtml, $htmlStack);
                    foreach ($sentenceParts as $index => $sentencePart) {
                        if ($index === 0) {
                            $currentPart .= $sentencePart;
                        } else {
                            $parts[] = $currentPart;
                            $currentPart = $sentencePart;
                        }
                    }
                } else {
                    $currentPart .= $sentenceWithHtml;
                }
            } else {
                $currentPart .= $sentenceWithHtml;
            }
        }

        if (!empty($currentPart)) {
            $parts[] = $currentPart;
        }

        return array_filter($parts, fn($part) => !empty(trim($part)));
    }

    /**
     * Splits text into sentences
     */
    private function splitIntoSentences(string $text): array
    {
        // Split by periods, exclamation and question marks
        // Consider that after punctuation there may be space or newline
        $sentences = preg_split('/([.!?]+)(\s+|$)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $result = [];
        $currentSentence = '';

        for ($i = 0; $i < count($sentences); $i += 3) {
            $sentence = $sentences[$i] ?? '';
            $punctuation = $sentences[$i + 1] ?? '';
            $space = $sentences[$i + 2] ?? '';

            if (!empty($sentence) || !empty($punctuation)) {
                $result[] = $sentence . $punctuation . $space;
            }
        }

        return array_filter($result, fn($s) => !empty(trim($s)));
    }

    /**
     * Processes HTML tags in sentence
     */
    private function processHtmlInSentence(string $sentence, array &$htmlStack): string
    {
        $result = '';
        $i = 0;

        while ($i < strlen($sentence)) {
            $char = $sentence[$i];

            if ($char === '<') {
                $tagEnd = strpos($sentence, '>', $i);
                if ($tagEnd !== false) {
                    $tag = substr($sentence, $i, $tagEnd - $i + 1);
                    $result .= $tag;
                    $this->updateHtmlStack($tag, $htmlStack);
                    $i = $tagEnd + 1;
                    continue;
                }
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }

    /**
     * Updates HTML tags stack
     */
    private function updateHtmlStack(string $tag, array &$htmlStack): void
    {
        if (preg_match('/<\/(\w+)>/', $tag, $matches)) {
            // Closing tag - remove from stack
            $tagName = $matches[1];
            $htmlStack = array_filter($htmlStack, fn($openTag) => !str_contains($openTag, $tagName));
        } elseif (preg_match('/<(\w+)(?:\s[^>]*)?>/', $tag, $matches) && !str_ends_with($tag, '/>')) {
            // Opening tag (not self-closing) - add to stack
            $htmlStack[] = $tag;
        }
    }

    /**
     * Closes all open HTML tags
     */
    private function closeOpenTags(array $htmlStack): string
    {
        $closingTags = '';
        foreach (array_reverse($htmlStack) as $openTag) {
            if (preg_match('/<(\w+)/', $openTag, $matches)) {
                $closingTags .= '</' . $matches[1] . '>';
            }
        }
        return $closingTags;
    }

    /**
     * Reopens HTML tags in new message part
     */
    private function reopenTags(array $htmlStack): string
    {
        return implode('', $htmlStack);
    }

    /**
     * Forcefully splits too long sentence
     */
    private function splitLongSentence(string $sentence, array &$htmlStack): array
    {
        $parts = [];
        $currentPart = '';
        $i = 0;

        while ($i < strlen($sentence)) {
            $char = $sentence[$i];

            // Check for HTML tag
            if ($char === '<') {
                $tagEnd = strpos($sentence, '>', $i);
                if ($tagEnd !== false) {
                    $tag = substr($sentence, $i, $tagEnd - $i + 1);

                    if (strlen($currentPart . $tag) > $this->maxMessageLength) {
                        $currentPart .= $this->closeOpenTags($htmlStack);
                        $parts[] = $currentPart;
                        $currentPart = $this->reopenTags($htmlStack);
                    }

                    $currentPart .= $tag;
                    $this->updateHtmlStack($tag, $htmlStack);
                    $i = $tagEnd + 1;
                    continue;
                }
            }

            // Regular character
            if (strlen($currentPart . $char) > $this->maxMessageLength) {
                $breakPoint = $this->findSafeBreakPoint($currentPart);

                if ($breakPoint > 0) {
                    $partToSave = substr($currentPart, 0, $breakPoint);
                    $remainder = substr($currentPart, $breakPoint);

                    $partToSave .= $this->closeOpenTags($htmlStack);
                    $parts[] = $partToSave;
                    $currentPart = $this->reopenTags($htmlStack) . $remainder . $char;
                } else {
                    $currentPart .= $this->closeOpenTags($htmlStack);
                    $parts[] = $currentPart;
                    $currentPart = $this->reopenTags($htmlStack) . $char;
                }
            } else {
                $currentPart .= $char;
            }

            $i++;
        }

        if (!empty($currentPart)) {
            $parts[] = $currentPart;
        }

        return $parts;
    }

    /**
     * Finds a safe break point in the text (space, punctuation, etc.)
     */
    private function findSafeBreakPoint(string $text): int
    {
        $length = strlen($text);

        // Look for safe break points from the end
        for ($i = $length - 1; $i >= 0; $i--) {
            $char = $text[$i];

            // Safe break points: space, newline, punctuation
            if (in_array($char, [' ', "\n", "\r", "\t", '.', ',', ';', ':', '!', '?', '-'])) {
                return $i + 1; // Return position after the break character
            }
        }

        return 0; // No safe break point found
    }
}
