<?php

declare(strict_types=1);

namespace LutionsWp;

final class MarkdownRenderer
{
    public static function render(string $markdown, string $fallbackPlainText = ''): string
    {
        $source = trim($markdown) !== '' ? $markdown : $fallbackPlainText;
        if (trim($source) === '') {
            return '';
        }

        $html = self::blocksToHtml(self::normalizeLineEndings($source));

        return wp_kses_post($html);
    }

    private static function normalizeLineEndings(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    private static function blocksToHtml(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $html = '';
        $paragraph = [];
        $listItems = [];
        $lineCount = count($lines);

        for ($index = 0; $index <= $lineCount; $index++) {
            $line = $index < $lineCount ? rtrim($lines[$index] ?? '') : '';
            $trimmed = trim($line);

            if (preg_match('/^(#{1,4})\s+(.+)$/u', $trimmed, $headingMatch) === 1) {
                $html .= self::flushList($listItems);
                $html .= self::flushParagraph($paragraph);

                $level = strlen($headingMatch[1]);
                $html .= sprintf(
                    '<h%d>%s</h%d>',
                    $level,
                    self::inlineToHtml($headingMatch[2]),
                    $level,
                );
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $listMatch) === 1) {
                $html .= self::flushParagraph($paragraph);
                $listItems[] = (string) $listMatch[1];
                continue;
            }

            if ($trimmed === '') {
                $html .= self::flushList($listItems);
                $html .= self::flushParagraph($paragraph);
                continue;
            }

            $html .= self::flushList($listItems);
            $paragraph[] = $trimmed;
        }

        return $html;
    }

    /**
     * @param list<string> $paragraph
     */
    private static function flushParagraph(array &$paragraph): string
    {
        if ($paragraph === []) {
            return '';
        }

        $content = implode('<br>', array_map([self::class, 'inlineToHtml'], $paragraph));
        $paragraph = [];

        return '<p>' . $content . '</p>';
    }

    /**
     * @param list<string> $listItems
     */
    private static function flushList(array &$listItems): string
    {
        if ($listItems === []) {
            return '';
        }

        $items = '';
        foreach ($listItems as $item) {
            $items .= '<li>' . self::inlineToHtml($item) . '</li>';
        }

        $listItems = [];

        return '<ul>' . $items . '</ul>';
    }

    private static function inlineToHtml(string $text): string
    {
        $escaped = esc_html($text);

        $escaped = (string) preg_replace('/`([^`]+)`/u', '<code>$1</code>', $escaped);
        $escaped = (string) preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('/\*([^*]+)\*/u', '<em>$1</em>', $escaped);

        return $escaped;
    }
}
