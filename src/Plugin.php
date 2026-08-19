<?php

declare(strict_types=1);

namespace LutionsWp;

final class Plugin
{
    public static function boot(): void
    {
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
        $project = isset($attributes['project']) && is_scalar($attributes['project'])
            ? sanitize_key((string) $attributes['project'])
            : '';
        $limit = isset($attributes['limit']) && is_scalar($attributes['limit'])
            ? max(1, min(50, (int) $attributes['limit']))
            : 10;
        $title = isset($attributes['title']) && is_scalar($attributes['title'])
            ? sanitize_text_field((string) $attributes['title'])
            : __('Public tickets', 'lutions-wp');

        if ($project === '') {
            return self::renderNotice(
                __('Configure a public Lutions project with the project shortcode attribute.', 'lutions-wp'),
            );
        }

        $requestedProject = get_query_var('lutions_project');
        $requestedTicket = get_query_var('lutions_ticket');
        if (
            is_string($requestedProject)
            && is_string($requestedTicket)
            && sanitize_key($requestedProject) === $project
            && sanitize_key($requestedTicket) !== ''
        ) {
            return self::renderPublicTicketDetail($project, $requestedTicket, self::currentPageUrl());
        }

        $result = (new PublicTicketClient())->getTickets($project, $limit);
        if (! $result['ok']) {
            return self::renderNotice($result['message']);
        }

        $items = '';
        foreach ($result['tickets'] as $ticket) {
            $items .= sprintf(
                '<li><a href="%s">%s: %s</a><span> (%s)</span></li>',
                esc_url(self::ticketDetailUrl($ticket['projectSlug'], $ticket['ticketSlug'])),
                esc_html($ticket['reference']),
                esc_html($ticket['title']),
                esc_html($ticket['status']),
            );
        }

        if ($items === '') {
            return self::renderNotice(__('No public tickets are currently available for this project.', 'lutions-wp'));
        }

        return sprintf(
            '<section class="lutions-wp-tickets"><h2>%s</h2><ul>%s</ul></section>',
            esc_html($title),
            $items,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function renderPortalStats(array $attributes = []): string
    {
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
            '<section class="lutions-wp-stats"><h2>%s</h2><p>%s: <strong>%d</strong></p><ul>%s</ul><p>%s: %s</p></section>',
            esc_html($title),
            esc_html__('Public tickets', 'lutions-wp'),
            (int) $result['stats']['totalPublicTickets'],
            $statusItems,
            esc_html__('Last updated', 'lutions-wp'),
            esc_html($lastUpdated),
        );
    }

    private static function ticketDetailUrl(string $projectSlug, string $ticketSlug): string
    {
        return add_query_arg(
            [
                'lutions_project' => $projectSlug,
                'lutions_ticket' => $ticketSlug,
            ],
            self::currentPageUrl(),
        );
    }

    private static function currentPageUrl(): string
    {
        $permalink = get_permalink();

        return is_string($permalink) && $permalink !== '' ? $permalink : home_url('/');
    }

    public static function renderPublicTicketDetail(string $projectSlug, string $ticketSlug, ?string $backUrl = null): string
    {
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

        return sprintf(
            '<article class="lutions-wp-ticket-detail"><p><a href="%s">%s</a></p><h1>%s: %s</h1><p>%s</p><div>%s</div>%s%s</article>',
            esc_url($backUrl ?? home_url('/')),
            esc_html__('Back', 'lutions-wp'),
            esc_html($ticketData['reference']),
            esc_html($ticketData['title']),
            esc_html($status),
            nl2br(esc_html($ticketData['description'])),
            $comments,
            $attachments,
        );
    }

    /**
     * @param list<array{body: string, authorName: string, createdAt: string}> $comments
     */
    private static function renderComments(array $comments): string
    {
        if ($comments === []) {
            return '';
        }

        $items = '';
        foreach ($comments as $comment) {
            $items .= sprintf(
                '<li><p>%s</p><div>%s</div></li>',
                nl2br(esc_html($comment['body'])),
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
        return sprintf('<p class="lutions-wp-notice">%s</p>', esc_html($message));
    }
}
