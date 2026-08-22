<?php

declare(strict_types=1);

namespace LutionsWp;

final class MarkdownRenderer
{
    private const YOUTUBE_VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    public static function render(string $markdown, string $fallbackPlainText = ''): string
    {
        $source = trim($markdown) !== '' ? $markdown : $fallbackPlainText;
        if (trim($source) === '') {
            return '';
        }

        $html = self::blocksToHtml(self::normalizeLineEndings($source));

        return self::sanitizeHtml($html);
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
            $youtubeVideoId = self::youtubeVideoIdFromLine($trimmed);

            if ($youtubeVideoId !== null) {
                $html .= self::flushList($listItems);
                $html .= self::flushParagraph($paragraph);
                $html .= self::youtubeEmbedToHtml($youtubeVideoId);
                continue;
            }

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

            if (preg_match('/^>\s*(.+)$/u', $trimmed, $blockquoteMatch) === 1) {
                $html .= self::flushList($listItems);
                $html .= self::flushParagraph($paragraph);
                $html .= '<blockquote>' . self::inlineToHtml((string) $blockquoteMatch[1]) . '</blockquote>';
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
        $escaped = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^()\s]+)\)/u',
            [self::class, 'markdownImageToHtml'],
            $escaped,
        );
        $escaped = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^()\s]+)\)/u',
            [self::class, 'markdownLinkToHtml'],
            $escaped,
        );

        return $escaped;
    }

    /**
     * @param array<int, string> $match
     */
    private static function markdownLinkToHtml(array $match): string
    {
        $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = wp_parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
        $hasCredentials = is_array($parts) && (isset($parts['user']) || isset($parts['pass']));

        if ($hasCredentials || (! self::isSafeAbsoluteUrl($scheme, $host) && ! self::isSafeRootRelativeUrl($url))) {
            return $match[0];
        }

        return sprintf(
            '<a href="%s" rel="nofollow noopener noreferrer">%s</a>',
            esc_url($url),
            $match[1],
        );
    }

    /**
     * @param array<int, string> $match
     */
    private static function markdownImageToHtml(array $match): string
    {
        $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = wp_parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
        $hasCredentials = is_array($parts) && (isset($parts['user']) || isset($parts['pass']));

        if ($hasCredentials || (! self::isSafeAbsoluteUrl($scheme, $host) && ! self::isSafeRootRelativeUrl($url))) {
            return $match[0];
        }

        return sprintf(
            '<img src="%s" alt="%s" loading="lazy" />',
            esc_url($url),
            esc_attr($match[1]),
        );
    }

    private static function isSafeAbsoluteUrl(string $scheme, string $host): bool
    {
        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    private static function isSafeRootRelativeUrl(string $url): bool
    {
        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_starts_with($url, '/\\');
    }

    private static function youtubeVideoIdFromLine(string $line): ?string
    {
        $videoId = self::youtubeVideoIdFromUrl($line);
        if ($videoId !== null) {
            return $videoId;
        }

        if (preg_match('/^\[([^\]]+)\]\(([^()\s]+)\)$/u', $line, $match) !== 1) {
            return null;
        }

        $url = html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::youtubeVideoIdFromUrl($url);
    }

    private static function youtubeVideoIdFromUrl(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $normalized = (string) preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $value);
        $parts = wp_parse_url($normalized);
        if (! is_array($parts)) {
            return null;
        }

        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $videoId = null;

        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            $videoId = isset($segments[0]) ? (string) $segments[0] : null;
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if ($path === '/watch') {
                $query = [];
                parse_str(isset($parts['query']) ? (string) $parts['query'] : '', $query);
                $videoId = is_string($query['v'] ?? null) ? $query['v'] : null;
            } else {
                $segments = array_values(array_filter(explode('/', trim($path, '/'))));
                $videoId = ($segments[0] ?? '') === 'embed' && isset($segments[1]) ? (string) $segments[1] : null;
            }
        }

        return is_string($videoId) && preg_match(self::YOUTUBE_VIDEO_ID_PATTERN, $videoId) === 1 ? $videoId : null;
    }

    private static function youtubeEmbedToHtml(string $videoId): string
    {
        $watchUrl = 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
        $embedUrl = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId) . '?autoplay=0&playsinline=1';

        $placeholder = '<div class="lutions-wp-youtube-embed lutions-wp-youtube-embed-placeholder" '
            . 'data-lutions-wp-youtube-embed data-embed-src="%s" data-embed-title="%s">'
            . '<button class="lutions-wp-youtube-embed-cover" type="button" data-lutions-wp-youtube-load aria-label="%s">'
            . '<span class="lutions-wp-youtube-embed-poster" aria-hidden="true">'
            . '<span class="lutions-wp-youtube-embed-play"></span>'
            . '</span>'
            . '<span class="lutions-wp-youtube-embed-content">'
            . '<span class="lutions-wp-youtube-embed-kicker">%s</span>'
            . '<strong class="lutions-wp-youtube-embed-title">%s</strong>'
            . '<span class="lutions-wp-youtube-embed-copy">%s</span>'
            . '</span>'
            . '</button>'
            . '<div class="lutions-wp-youtube-embed-actions">'
            . '<a class="lutions-wp-youtube-embed-link" href="%s" rel="nofollow noopener noreferrer" target="_blank">%s</a>'
            . '</div>'
            . '</div>';

        return sprintf(
            $placeholder,
            esc_url($embedUrl),
            esc_attr(__('YouTube video', 'lutions-wp')),
            esc_attr(__('Load YouTube video', 'lutions-wp')),
            esc_html__('External video', 'lutions-wp'),
            esc_html__('YouTube video', 'lutions-wp'),
            esc_html__('Loads only after you click play.', 'lutions-wp'),
            esc_url($watchUrl),
            esc_html__('Open on YouTube', 'lutions-wp'),
        );
    }

    private static function sanitizeHtml(string $html): string
    {
        if (! function_exists('wp_kses')) {
            return wp_kses_post($html);
        }

        return wp_kses($html, self::allowedHtml());
    }

    /**
     * @return array<string, array<string, bool|list<string>>>
     */
    private static function allowedHtml(): array
    {
        $allowed = [
            'a' => [
                'class' => true,
                'href' => true,
                'rel' => true,
                'target' => true,
            ],
            'blockquote' => [],
            'br' => [],
            'code' => [],
            'div' => [
                'aria-hidden' => true,
                'class' => true,
                'data-embed-src' => true,
                'data-embed-title' => true,
                'data-lutions-wp-youtube-embed' => true,
            ],
            'em' => [],
            'h1' => [],
            'h2' => [],
            'h3' => [],
            'h4' => [],
            'img' => [
                'alt' => true,
                'loading' => true,
                'src' => true,
            ],
            'button' => [
                'aria-label' => true,
                'class' => true,
                'data-lutions-wp-youtube-load' => true,
                'type' => true,
            ],
            'li' => [],
            'p' => [],
            'span' => [
                'aria-hidden' => true,
                'class' => true,
            ],
            'strong' => [
                'class' => true,
            ],
            'ul' => [],
        ];

        return $allowed;
    }
}
