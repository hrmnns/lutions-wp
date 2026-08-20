<?php

declare(strict_types=1);

namespace LutionsWp;

final class Plugin
{
    public static function boot(): void
    {
        AdminSettings::boot();
        add_action('init', [self::class, 'loadTextDomain']);
        add_action('init', [self::class, 'registerShortcodes']);
        add_filter('query_vars', [self::class, 'registerTicketQueryVars']);
    }

    public static function loadTextDomain(): void
    {
        load_plugin_textdomain('lutions-wp', false, dirname(plugin_basename(LUTIONS_WP_FILE)) . '/languages');
    }

    public static function registerShortcodes(): void
    {
        add_shortcode('lutions_public_tickets', [self::class, 'renderPublicTickets']);
        add_shortcode('lutions_release_feed', [self::class, 'renderContractPendingNotice']);
        add_shortcode('lutions_portal_stats', [self::class, 'renderPortalStats']);
    }

    /**
     * @param list<string> $queryVars
     * @return list<string>
     */
    public static function registerTicketQueryVars(array $queryVars): array
    {
        $queryVars[] = 'lutions_project';
        $queryVars[] = 'lutions_ticket';

        return $queryVars;
    }

    /**
     * The plugin deliberately performs no API calls before LUPOR-4 defines the
     * public read contract, credentials, cache behavior, and error format.
     *
     * @param array<string, mixed> $attributes
     */
    public static function renderContractPendingNotice(array $attributes = []): string
    {
        unset($attributes);
        self::enqueueFrontendAssets();

        return sprintf(
            '<p class="lutions-wp-notice">%s</p>',
            esc_html__('The Lutions Public Read API contract is not available yet.', 'lutions-wp'),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function renderPublicTickets(array $attributes = []): string
    {
        self::enqueueFrontendAssets();

        $project = isset($attributes['project']) && is_scalar($attributes['project'])
            ? sanitize_key((string) $attributes['project'])
            : '';
        $limit = isset($attributes['limit']) && is_scalar($attributes['limit'])
            ? max(1, min(50, (int) $attributes['limit']))
            : 10;
        $title = isset($attributes['title']) && is_scalar($attributes['title'])
            ? sanitize_text_field((string) $attributes['title'])
            : __('Public tickets', 'lutions-wp');
        $detailBaseUrl = self::ticketDetailBaseUrl($attributes);
        $renderDetail = self::shouldRenderTicketDetail($attributes);
        $showStatus = self::booleanAttribute($attributes, 'show_status', true);
        $showPriority = self::booleanAttribute($attributes, 'show_priority', false);
        $showType = self::booleanAttribute($attributes, 'show_type', false);
        $showTicketType = self::booleanAttribute($attributes, 'show_ticket_type', false);
        $showCounts = self::booleanAttribute($attributes, 'show_counts', false);
        $showDate = self::booleanAttribute($attributes, 'show_date', false);
        $dateField = self::dateFieldAttribute($attributes, $showDate);

        if ($project === '') {
            return self::renderNotice(
                __('Configure a public Lutions project with the project shortcode attribute.', 'lutions-wp'),
            );
        }

        $requestedProject = get_query_var('lutions_project');
        $requestedTicket = get_query_var('lutions_ticket');
        if (
            $renderDetail
            &&
            is_string($requestedProject)
            && is_string($requestedTicket)
            && sanitize_key($requestedProject) === $project
            && sanitize_key($requestedTicket) !== ''
        ) {
            return self::renderPublicTicketDetail($project, $requestedTicket, $detailBaseUrl);
        }

        $result = (new PublicTicketClient())->getTickets($project, $limit);
        if (! $result['ok']) {
            return self::renderNotice($result['message']);
        }

        $items = '';
        foreach ($result['tickets'] as $ticket) {
            $items .= sprintf(
                '<li><a href="%s">%s: %s</a>%s</li>',
                esc_url(self::ticketDetailUrl($ticket['projectSlug'], $ticket['ticketSlug'], $detailBaseUrl)),
                esc_html($ticket['reference']),
                esc_html($ticket['title']),
                self::renderTicketListMeta($ticket, [
                    'showStatus' => $showStatus,
                    'showPriority' => $showPriority,
                    'showType' => $showType,
                    'showTicketType' => $showTicketType,
                    'showCounts' => $showCounts,
                    'dateField' => $dateField,
                ]),
            );
        }

        if ($items === '') {
            return self::renderNotice(__('No public tickets are currently available for this project.', 'lutions-wp'));
        }

        $heading = $title !== ''
            ? sprintf('<h2>%s</h2>', esc_html($title))
            : '';

        return sprintf(
            '<section class="lutions-wp-tickets">%s<ul>%s</ul></section>',
            $heading,
            $items,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function renderPortalStats(array $attributes = []): string
    {
        self::enqueueFrontendAssets();

        $project = isset($attributes['project']) && is_scalar($attributes['project'])
            ? sanitize_key((string) $attributes['project'])
            : '';
        $title = isset($attributes['title']) && is_scalar($attributes['title'])
            ? sanitize_text_field((string) $attributes['title'])
            : __('Public ticket stats', 'lutions-wp');

        if ($project === '') {
            return self::renderNotice(
                __('Configure a public Lutions project with the project shortcode attribute.', 'lutions-wp'),
            );
        }

        $result = (new PublicTicketClient())->getProjectStats($project);
        if (! $result['ok']) {
            return self::renderNotice($result['message']);
        }

        $statusItems = '';
        foreach ($result['stats']['byStatus'] as $status => $count) {
            $statusItems .= sprintf(
                '<li><span>%s</span>: <strong>%d</strong></li>',
                esc_html($status),
                (int) $count,
            );
        }

        if ($statusItems === '') {
            $statusItems = sprintf('<li>%s</li>', esc_html__('No public tickets counted by status yet.', 'lutions-wp'));
        }

        $lastUpdated = $result['stats']['lastUpdatedAt'] !== ''
            ? $result['stats']['lastUpdatedAt']
            : __('not available', 'lutions-wp');

        return sprintf(
            '<section class="lutions-wp-stats"><h2>%s</h2><p>%s: <strong>%d</strong></p><ul>%s</ul><p class="lutions-wp-stats-updated">%s: %s</p></section>',
            esc_html($title),
            esc_html__('Public tickets', 'lutions-wp'),
            (int) $result['stats']['totalPublicTickets'],
            $statusItems,
            esc_html__('Last updated', 'lutions-wp'),
            esc_html($lastUpdated),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function booleanAttribute(array $attributes, string $name, bool $default): bool
    {
        if (! isset($attributes[$name]) || ! is_scalar($attributes[$name])) {
            return $default;
        }

        $value = strtolower(trim((string) $attributes[$name]));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array{showStatus: bool, showPriority: bool, showType: bool, showTicketType: bool, showCounts: bool, dateField: string} $options
     */
    private static function renderTicketListMeta(array $ticket, array $options): string
    {
        $parts = [];
        if ($options['showStatus'] && is_string($ticket['status'] ?? null) && $ticket['status'] !== '') {
            $parts[] = $ticket['status'];
        }

        if ($options['showPriority'] && is_string($ticket['priority'] ?? null) && $ticket['priority'] !== '') {
            $parts[] = $ticket['priority'];
        }

        if ($options['showType'] && is_string($ticket['type'] ?? null) && $ticket['type'] !== '') {
            $parts[] = $ticket['type'];
        }

        if ($options['showTicketType']) {
            $ticketType = is_array($ticket['ticketType'] ?? null) ? $ticket['ticketType'] : null;
            $ticketTypeName = is_string($ticketType['name'] ?? null) ? $ticketType['name'] : '';
            if ($ticketTypeName !== '') {
                $parts[] = $ticketTypeName;
            }
        }

        $dateValue = self::ticketDateValue($ticket, $options['dateField']);
        if ($dateValue !== '') {
            $parts[] = self::formatDate($dateValue);
        }

        if ($options['showCounts']) {
            $commentCount = is_int($ticket['publicCommentCount'] ?? null) ? $ticket['publicCommentCount'] : 0;
            $attachmentCount = is_int($ticket['publicAttachmentCount'] ?? null) ? $ticket['publicAttachmentCount'] : 0;
            if ($commentCount > 0) {
                $parts[] = sprintf(
                    $commentCount === 1 ? __('%d public comment', 'lutions-wp') : __('%d public comments', 'lutions-wp'),
                    $commentCount,
                );
            }
            if ($attachmentCount > 0) {
                $parts[] = sprintf(
                    $attachmentCount === 1 ? __('%d public attachment', 'lutions-wp') : __('%d public attachments', 'lutions-wp'),
                    $attachmentCount,
                );
            }
        }

        if ($parts === []) {
            return '';
        }

        return sprintf(
            '<span class="lutions-wp-ticket-list-meta"> (%s)</span>',
            esc_html(implode(' · ', $parts)),
        );
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private static function ticketDateValue(array $ticket, string $dateField): string
    {
        $field = match ($dateField) {
            'created' => 'createdAt',
            'updated' => 'updatedAt',
            'closed' => 'closedAt',
            default => '',
        };

        if ($field === '') {
            return '';
        }

        return is_string($ticket[$field] ?? null) ? $ticket[$field] : '';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function dateFieldAttribute(array $attributes, bool $showDate): string
    {
        $default = $showDate ? 'created' : 'none';
        if (! isset($attributes['date_field']) || ! is_scalar($attributes['date_field'])) {
            return $default;
        }

        $value = sanitize_key((string) $attributes['date_field']);

        return in_array($value, ['created', 'updated', 'closed', 'none'], true) ? $value : $default;
    }

    private static function formatDate(string $isoDate): string
    {
        $timestamp = strtotime($isoDate);
        if ($timestamp === false) {
            return $isoDate;
        }

        $format = get_option('date_format', 'Y-m-d');
        $dateFormat = is_string($format) && $format !== '' ? $format : 'Y-m-d';

        return date_i18n($dateFormat, $timestamp);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function shouldRenderTicketDetail(array $attributes): bool
    {
        $mode = isset($attributes['mode']) && is_scalar($attributes['mode'])
            ? sanitize_key((string) $attributes['mode'])
            : '';
        if ($mode === 'list') {
            return false;
        }

        $renderDetail = isset($attributes['render_detail']) && is_scalar($attributes['render_detail'])
            ? strtolower(trim((string) $attributes['render_detail']))
            : '';

        return ! in_array($renderDetail, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function ticketDetailBaseUrl(array $attributes): string
    {
        $attributeUrl = isset($attributes['detail_url']) && is_scalar($attributes['detail_url'])
            ? AdminSettings::normalizeLocalPageUrl((string) $attributes['detail_url'])
            : null;
        if (is_string($attributeUrl) && $attributeUrl !== '') {
            return $attributeUrl;
        }

        $configuredUrl = AdminSettings::configuredDetailPageUrl();
        if ($configuredUrl !== '') {
            return $configuredUrl;
        }

        return self::currentPageUrl();
    }

    private static function ticketDetailUrl(string $projectSlug, string $ticketSlug, string $detailBaseUrl): string
    {
        return add_query_arg(
            [
                'lutions_project' => $projectSlug,
                'lutions_ticket' => $ticketSlug,
            ],
            $detailBaseUrl,
        );
    }

    private static function currentPageUrl(): string
    {
        $permalink = get_permalink();

        return is_string($permalink) && $permalink !== '' ? $permalink : home_url('/');
    }

    public static function renderPublicTicketDetail(string $projectSlug, string $ticketSlug, ?string $backUrl = null): string
    {
        self::enqueueFrontendAssets();

        $project = sanitize_key($projectSlug);
        $ticket = sanitize_key($ticketSlug);

        if ($project === '' || $ticket === '') {
            return self::renderNotice(__('The requested public ticket could not be found.', 'lutions-wp'));
        }

        $result = (new PublicTicketClient())->getTicketDetail($project, $ticket);
        if (! $result['ok']) {
            return self::renderNotice($result['message']);
        }

        $ticketData = $result['ticket'];
        $status = trim($ticketData['status'] . ($ticketData['priority'] !== '' ? ' / ' . $ticketData['priority'] : ''));
        $comments = self::renderComments($ticketData['comments']);
        $attachments = self::renderAttachments($ticketData['attachments']);
        $markup = '<article class="lutions-wp-ticket-detail"><p><a href="%s">%s</a></p>';
        $markup .= '<h1>%s: %s</h1><p class="lutions-wp-ticket-meta">%s</p>';
        $markup .= '<div class="lutions-wp-ticket-description">%s</div>%s%s</article>';

        return sprintf(
            $markup,
            esc_url($backUrl ?? home_url('/')),
            esc_html__('Back', 'lutions-wp'),
            esc_html($ticketData['reference']),
            esc_html($ticketData['title']),
            esc_html($status),
            MarkdownRenderer::render(
                is_string($ticketData['descriptionMarkdown'] ?? null) ? $ticketData['descriptionMarkdown'] : '',
                $ticketData['description'],
            ),
            $comments,
            $attachments,
        );
    }

    /**
     * @param list<array{body: string, bodyMarkdown: string, authorName: string, createdAt: string}> $comments
     */
    private static function renderComments(array $comments): string
    {
        if ($comments === []) {
            return '';
        }

        $items = '';
        foreach ($comments as $comment) {
            $items .= sprintf(
                '<li><div class="lutions-wp-ticket-comment-body">%s</div><div class="lutions-wp-ticket-comment-meta">%s</div></li>',
                MarkdownRenderer::render($comment['bodyMarkdown'], $comment['body']),
                esc_html(trim($comment['authorName'] . ' ' . $comment['createdAt'])),
            );
        }

        return sprintf(
            '<section class="lutions-wp-ticket-comments"><h2>%s</h2><ul>%s</ul></section>',
            esc_html__('Public comments', 'lutions-wp'),
            $items,
        );
    }

    /**
     * @param list<array{filename: string, downloadUrl: string}> $attachments
     */
    private static function renderAttachments(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }

        $items = '';
        foreach ($attachments as $attachment) {
            $items .= sprintf(
                '<li><a href="%s" rel="noopener">%s</a></li>',
                esc_url($attachment['downloadUrl']),
                esc_html($attachment['filename']),
            );
        }

        return sprintf(
            '<section class="lutions-wp-ticket-attachments"><h2>%s</h2><ul>%s</ul></section>',
            esc_html__('Public attachments', 'lutions-wp'),
            $items,
        );
    }

    private static function renderNotice(string $message): string
    {
        self::enqueueFrontendAssets();

        return sprintf('<p class="lutions-wp-notice">%s</p>', esc_html($message));
    }

    private static function enqueueFrontendAssets(): void
    {
        wp_enqueue_style(
            'lutions-wp-frontend',
            LUTIONS_WP_URL . 'assets/css/frontend.css',
            [],
            LUTIONS_WP_VERSION,
        );
    }
}
