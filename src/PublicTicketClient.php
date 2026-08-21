<?php

declare(strict_types=1);

namespace LutionsWp;

final class PublicTicketClient
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return array{ok: bool, message: string, tickets: list<array<string, string>>}
     */
    public function getTickets(string $projectSlug, int $limit, string $sortBy = 'created', string $sortOrder = 'desc'): array
    {
        $apiBaseUrl = $this->apiBaseUrl();
        if ($apiBaseUrl === null) {
            return $this->ticketListFailure(__('The Lutions API base URL is not configured.', 'lutions-wp'));
        }

        $endpoint = sprintf(
            '%s/public/projects/%s/tickets?limit=%d&sort_by=%s&sort_order=%s',
            $apiBaseUrl,
            rawurlencode($projectSlug),
            $limit,
            rawurlencode($sortBy),
            rawurlencode($sortOrder),
        );
        $cacheKey = $this->cacheKey('lutions_wp_tickets', $endpoint);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->requestPayload($endpoint);
        if ($payload === null) {
            return $this->ticketListFailure(__('Public tickets are temporarily unavailable.', 'lutions-wp'));
        }

        $tickets = isset($payload['data']['tickets']) && is_array($payload['data']['tickets'])
            ? $this->mapTickets($payload['data']['tickets'])
            : null;
        if ($tickets === null) {
            return $this->ticketListFailure(__('Public tickets are temporarily unavailable.', 'lutions-wp'));
        }

        $result = ['ok' => true, 'message' => '', 'tickets' => $tickets];
        set_transient($cacheKey, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /**
     * @return array{ok: bool, message: string, ticket: array<string, mixed>}
     */
    public function getTicketDetail(string $projectSlug, string $ticketSlug): array
    {
        $apiBaseUrl = $this->apiBaseUrl();
        if ($apiBaseUrl === null) {
            return $this->ticketDetailFailure(__('The Lutions API base URL is not configured.', 'lutions-wp'));
        }

        $endpoint = sprintf(
            '%s/public/projects/%s/tickets/%s',
            $apiBaseUrl,
            rawurlencode($projectSlug),
            rawurlencode($ticketSlug),
        );
        $cacheKey = $this->cacheKey('lutions_wp_ticket', $endpoint);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->requestPayload($endpoint);
        $ticket = is_array($payload) && isset($payload['data']['ticket']) && is_array($payload['data']['ticket'])
            ? $this->mapTicketDetail($payload['data']['ticket'], $apiBaseUrl)
            : null;
        if ($ticket === null) {
            return $this->ticketDetailFailure(__('The requested public ticket could not be loaded.', 'lutions-wp'));
        }

        $result = ['ok' => true, 'message' => '', 'ticket' => $ticket];
        set_transient($cacheKey, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /**
     * @return array{ok: bool, message: string, stats: array{totalPublicTickets: int, byStatus: array<string, int>, lastUpdatedAt: string}}
     */
    public function getProjectStats(string $projectSlug): array
    {
        $apiBaseUrl = $this->apiBaseUrl();
        if ($apiBaseUrl === null) {
            return $this->projectStatsFailure(__('The Lutions API base URL is not configured.', 'lutions-wp'));
        }

        $endpoint = sprintf(
            '%s/public/projects/%s/stats',
            $apiBaseUrl,
            rawurlencode($projectSlug),
        );
        $cacheKey = $this->cacheKey('lutions_wp_stats', $endpoint);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->requestPayload($endpoint);
        $stats = is_array($payload) && isset($payload['data']['stats']) && is_array($payload['data']['stats'])
            ? $this->mapProjectStats($payload['data']['stats'])
            : null;
        if ($stats === null) {
            return $this->projectStatsFailure(__('Public ticket stats are temporarily unavailable.', 'lutions-wp'));
        }

        $result = ['ok' => true, 'message' => '', 'stats' => $stats];
        set_transient($cacheKey, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $apiBaseUrl = $this->apiBaseUrl();
        if ($apiBaseUrl === null) {
            return [
                'ok' => false,
                'message' => __('The Lutions API base URL is not configured or is not allowed.', 'lutions-wp'),
            ];
        }

        $response = $this->request($apiBaseUrl . '/public');
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => __('The Lutions API could not be reached.', 'lutions-wp'),
            ];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode === 200) {
            return [
                'ok' => true,
                'message' => __('The Lutions API is reachable.', 'lutions-wp'),
            ];
        }

        if ($statusCode === 503) {
            return [
                'ok' => false,
                'message' => __('The Lutions API is reachable, but the public portal is disabled.', 'lutions-wp'),
            ];
        }

        return [
            'ok' => false,
            'message' => __('The Lutions API returned an unexpected response.', 'lutions-wp'),
        ];
    }

    public static function normalizeApiBaseUrl(string $configured): ?string
    {
        $url = rtrim(trim($configured), '/');
        $parts = wp_parse_url($url);

        if ($url === '' || ! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme === 'https') {
            return $url;
        }

        $localHost = in_array($parts['host'], ['localhost', '127.0.0.1', 'host.docker.internal'], true);
        if (
            $scheme === 'http'
            && $localHost
            && in_array(wp_get_environment_type(), ['local', 'development'], true)
        ) {
            return $url;
        }

        return null;
    }

    /**
     * @return array{url: string, source: string, valid: bool}
     */
    public static function apiBaseUrlDiagnostics(): array
    {
        $configuredOption = AdminSettings::configuredApiBaseUrl();
        if ($configuredOption !== '') {
            return self::diagnosticsForConfiguredValue($configuredOption, 'wordpress-option');
        }

        if (defined('LUTIONS_WP_API_BASE_URL')) {
            return self::diagnosticsForConfiguredValue((string) constant('LUTIONS_WP_API_BASE_URL'), 'php-constant');
        }

        $configuredEnv = getenv('LUTIONS_WP_API_BASE_URL');
        if (is_string($configuredEnv) && $configuredEnv !== '') {
            return self::diagnosticsForConfiguredValue($configuredEnv, 'environment');
        }

        return [
            'url' => '',
            'source' => 'not-configured',
            'valid' => false,
        ];
    }

    private function apiBaseUrl(): ?string
    {
        $diagnostics = self::apiBaseUrlDiagnostics();

        return $diagnostics['valid'] ? $diagnostics['url'] : null;
    }

    /**
     * @return array{url: string, source: string, valid: bool}
     */
    private static function diagnosticsForConfiguredValue(string $configured, string $source): array
    {
        $normalized = self::normalizeApiBaseUrl($configured);

        return [
            'url' => $normalized ?? rtrim(trim($configured), '/'),
            'source' => $source,
            'valid' => $normalized !== null,
        ];
    }

    /** @return array<string, mixed>|\WP_Error */
    private function request(string $endpoint)
    {
        $isLocalDockerTarget = str_starts_with($endpoint, 'http://host.docker.internal:');
        $arguments = [
            'timeout' => 10,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
        ];

        return $isLocalDockerTarget
            ? wp_remote_get($endpoint, $arguments)
            : wp_safe_remote_get($endpoint, $arguments);
    }

    /** @return array<string, mixed>|null */
    private function requestPayload(string $endpoint): ?array
    {
        $response = $this->request($endpoint);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($payload) ? $payload : null;
    }

    private function cacheKey(string $prefix, string $endpoint): string
    {
        $cacheVersion = get_option(AdminSettings::OPTION_CACHE_VERSION, '1');
        $normalizedCacheVersion = is_scalar($cacheVersion) ? (string) $cacheVersion : '1';

        return $prefix . '_' . md5($normalizedCacheVersion . '|' . $endpoint);
    }

    /**
     * @param list<mixed> $tickets
     * @return list<array<string, mixed>>|null
     */
    private function mapTickets(array $tickets): ?array
    {
        $mapped = [];
        foreach ($tickets as $ticket) {
            if (
                ! is_array($ticket) || ! is_string($ticket['reference'] ?? null) || ! is_string($ticket['title'] ?? null)
                || ! is_string($ticket['status'] ?? null) || ! is_string($ticket['projectSlug'] ?? null)
                || ! is_string($ticket['ticketSlug'] ?? null)
            ) {
                return null;
            }

            $mapped[] = [
                'reference' => $ticket['reference'],
                'title' => $ticket['title'],
                'status' => $ticket['status'],
                'priority' => is_string($ticket['priority'] ?? null) ? $ticket['priority'] : '',
                'type' => is_string($ticket['type'] ?? null) ? $ticket['type'] : '',
                'ticketType' => is_array($ticket['ticketType'] ?? null) ? [
                    'key' => is_string($ticket['ticketType']['key'] ?? null) ? $ticket['ticketType']['key'] : '',
                    'name' => is_string($ticket['ticketType']['name'] ?? null) ? $ticket['ticketType']['name'] : '',
                ] : null,
                'createdAt' => is_string($ticket['createdAt'] ?? null) ? $ticket['createdAt'] : '',
                'updatedAt' => is_string($ticket['updatedAt'] ?? null) ? $ticket['updatedAt'] : '',
                'closedAt' => is_string($ticket['closedAt'] ?? null) ? $ticket['closedAt'] : '',
                'isClosed' => is_bool($ticket['isClosed'] ?? null) ? $ticket['isClosed'] : false,
                'statusCategory' => is_string($ticket['statusCategory'] ?? null) ? $ticket['statusCategory'] : '',
                'publicCommentCount' => is_int($ticket['publicCommentCount'] ?? null) ? $ticket['publicCommentCount'] : 0,
                'publicAttachmentCount' => is_int($ticket['publicAttachmentCount'] ?? null) ? $ticket['publicAttachmentCount'] : 0,
                'projectSlug' => $ticket['projectSlug'],
                'ticketSlug' => $ticket['ticketSlug'],
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array<string, mixed>|null
     */
    private function mapTicketDetail(array $ticket, string $apiBaseUrl): ?array
    {
        if (
            ! is_string($ticket['reference'] ?? null) || ! is_string($ticket['title'] ?? null)
            || ! is_string($ticket['description'] ?? null)
        ) {
            return null;
        }

        $origin = $this->originFromApiBaseUrl($apiBaseUrl);
        if ($origin === null) {
            return null;
        }

        $comments = $this->mapComments(is_array($ticket['comments'] ?? null) ? $ticket['comments'] : [], $origin);
        $attachments = $this->mapAttachments(is_array($ticket['attachments'] ?? null) ? $ticket['attachments'] : [], $apiBaseUrl);
        if ($comments === null || $attachments === null) {
            return null;
        }

        return [
            'reference' => $ticket['reference'],
            'title' => $ticket['title'],
            'description' => $ticket['description'],
            'descriptionMarkdown' => is_string($ticket['descriptionMarkdown'] ?? null)
                ? $this->resolvePublicAttachmentMarkdownUrls($ticket['descriptionMarkdown'], $origin)
                : '',
            'status' => is_string($ticket['status'] ?? null) ? $ticket['status'] : '',
            'priority' => is_string($ticket['priority'] ?? null) ? $ticket['priority'] : '',
            'comments' => $comments,
            'attachments' => $attachments,
        ];
    }

    /**
     * @param list<mixed> $comments
     * @return list<array{body: string, bodyMarkdown: string, authorName: string, createdAt: string}>|null
     */
    private function mapComments(array $comments, string $origin): ?array
    {
        $mapped = [];
        foreach ($comments as $comment) {
            if (! is_array($comment) || ! is_string($comment['body'] ?? null)) {
                return null;
            }

            $mapped[] = [
                'body' => $comment['body'],
                'bodyMarkdown' => is_string($comment['bodyMarkdown'] ?? null)
                    ? $this->resolvePublicAttachmentMarkdownUrls($comment['bodyMarkdown'], $origin)
                    : '',
                'authorName' => is_string($comment['authorName'] ?? null) ? $comment['authorName'] : '',
                'createdAt' => is_string($comment['createdAt'] ?? null) ? $comment['createdAt'] : '',
            ];
        }

        return $mapped;
    }

    /**
     * @param list<mixed> $attachments
     * @return list<array{filename: string, downloadUrl: string}>|null
     */
    private function mapAttachments(array $attachments, string $apiBaseUrl): ?array
    {
        $origin = $this->originFromApiBaseUrl($apiBaseUrl);
        if ($origin === null) {
            return null;
        }

        $mapped = [];
        foreach ($attachments as $attachment) {
            if (
                ! is_array($attachment) || ! is_string($attachment['filename'] ?? null)
                || ! is_string($attachment['downloadUrl'] ?? null)
                || ! str_starts_with($attachment['downloadUrl'], '/api/v1/public/attachments/')
            ) {
                return null;
            }

            $mapped[] = [
                'filename' => $attachment['filename'],
                'downloadUrl' => $origin . $attachment['downloadUrl'],
            ];
        }

        return $mapped;
    }

    private function originFromApiBaseUrl(string $apiBaseUrl): ?string
    {
        $scheme = wp_parse_url($apiBaseUrl, PHP_URL_SCHEME);
        $host = wp_parse_url($apiBaseUrl, PHP_URL_HOST);
        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $origin = sprintf('%s://%s', $scheme, $host);
        $port = wp_parse_url($apiBaseUrl, PHP_URL_PORT);
        if (is_int($port)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private function resolvePublicAttachmentMarkdownUrls(string $markdown, string $origin): string
    {
        return (string) preg_replace_callback(
            '/\((\/api\/v1\/public\/attachments\/[^()\s]+\/download)\)/',
            static fn (array $matches): string => '(' . $origin . $matches[1] . ')',
            $markdown,
        );
    }

    /**
     * @param array<string, mixed> $stats
     * @return array{totalPublicTickets: int, byStatus: array<string, int>, lastUpdatedAt: string}|null
     */
    private function mapProjectStats(array $stats): ?array
    {
        if (! is_int($stats['totalPublicTickets'] ?? null) || ! is_array($stats['byStatus'] ?? null)) {
            return null;
        }

        $byStatus = [];
        foreach ($stats['byStatus'] as $status => $count) {
            if (! is_string($status) || ! is_int($count)) {
                return null;
            }

            $byStatus[$status] = $count;
        }

        return [
            'totalPublicTickets' => $stats['totalPublicTickets'],
            'byStatus' => $byStatus,
            'lastUpdatedAt' => is_string($stats['lastUpdatedAt'] ?? null) ? $stats['lastUpdatedAt'] : '',
        ];
    }

    /**
     * @return array{ok: false, message: string, tickets: list<array<string, string>>}
     */
    private function ticketListFailure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'tickets' => []];
    }

    /**
     * @return array{ok: false, message: string, ticket: array<string, mixed>}
     */
    private function ticketDetailFailure(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'ticket' => [
                'reference' => '',
                'title' => '',
                'description' => '',
                'descriptionMarkdown' => '',
                'status' => '',
                'priority' => '',
                'comments' => [],
                'attachments' => [],
            ],
        ];
    }

    /**
     * @return array{ok: false, message: string, stats: array{totalPublicTickets: int, byStatus: array<string, int>, lastUpdatedAt: string}}
     */
    private function projectStatsFailure(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'stats' => [
                'totalPublicTickets' => 0,
                'byStatus' => [],
                'lastUpdatedAt' => '',
            ],
        ];
    }
}
