<?php

declare(strict_types=1);

namespace LutionsWp;

final class Plugin
{
    private static bool $publicSearchResultsRendered = false;
    private static bool $publicSearchResultResolved = false;

    /** @var array{projectSlug: string, ticketSlug: string, canonicalUrl: string, title: string, description: string, publishedAt: string, updatedAt: string}|null */
    private static ?array $seoTicketContext = null;
    private static bool $seoTicketContextResolved = false;

    /**
     * @var array{
     *     categories: list<array{name: string, key: string, slug: string, lutionsPublicUrl: string}>,
     *     projects: list<array{name: string, key: string, slug: string, lutionsPublicUrl: string}>,
     *     tickets: list<array{reference: string, title: string, projectKey: string, projectSlug: string, ticketSlug: string}>
     * }|null
     */
    private static ?array $publicSearchResult = null;

    public static function boot(): void
    {
        AdminSettings::boot();
        add_action('init', [self::class, 'loadTextDomain']);
        add_action('init', [self::class, 'registerFeeds']);
        add_action('init', [self::class, 'registerRewriteRules']);
        add_action('init', [self::class, 'registerShortcodes']);
        add_filter('query_vars', [self::class, 'registerTicketQueryVars']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueFrontendAssets']);
        add_action('loop_end', [self::class, 'renderPublicSearchResultsAfterMainLoop']);
        add_action('get_footer', [self::class, 'renderPublicSearchResults']);
        add_filter('wp_robots', [self::class, 'filterRobotsForPublicContent']);
        add_filter('document_title_parts', [self::class, 'filterDocumentTitleParts']);
        add_filter('get_canonical_url', [self::class, 'filterCanonicalUrl'], 10, 2);
        add_action('wp_head', [self::class, 'renderTicketSeoMeta'], 1);
        add_action('init', [self::class, 'registerPublicTicketSitemapProvider'], 20);
        add_filter('render_block_core/query', [self::class, 'appendPublicSearchResultsToQueryBlock'], 10, 2);
        add_filter('body_class', [self::class, 'filterBodyClassForPublicSearchResults']);
    }

    public static function activate(): void
    {
        self::registerFeeds();
        self::registerRewriteRules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function loadTextDomain(): void
    {
        load_plugin_textdomain('lutions-wp', false, dirname(plugin_basename(LUTIONS_WP_FILE)) . '/languages');
    }

    public static function registerShortcodes(): void
    {
        add_shortcode('lutions_public_tickets', [self::class, 'renderPublicTickets']);
        add_shortcode('lutions_public_ticket_detail', [self::class, 'renderPublicTicketDetailShortcode']);
        add_shortcode('lutions_public_portal', [self::class, 'renderPublicPortal']);
        add_shortcode('lutions_public_submission', [self::class, 'renderPublicSubmission']);
        add_shortcode('lutions_release_feed', [self::class, 'renderContractPendingNotice']);
        add_shortcode('lutions_portal_stats', [self::class, 'renderPortalStats']);
    }

    public static function registerFeeds(): void
    {
        add_feed(AdminSettings::configuredProjectFeedBase(), [self::class, 'renderProjectFeed']);
    }

    public static function registerRewriteRules(): void
    {
        $feedBase = AdminSettings::configuredProjectFeedBase();

        add_rewrite_rule(
            '^feed/' . $feedBase . '/([^/]+)/?$',
            'index.php?feed=' . $feedBase . '&lutions_project=$matches[1]',
            'top',
        );
    }

    public static function renderProjectFeed(): void
    {
        $projectSlug = get_query_var('lutions_project');
        $project = is_string($projectSlug) ? sanitize_key($projectSlug) : '';
        if ($project === '') {
            self::renderRssFeed(__('Lutions project feed', 'lutions-wp'), home_url('/'), []);

            return;
        }

        $client = new PublicTicketClient();
        $projectResult = $client->getPublicProject($project);
        $ticketsResult = $client->getTickets($project, 20, 'published', 'desc');
        if (! $projectResult['ok'] || ! $ticketsResult['ok']) {
            self::renderRssFeed(__('Lutions project feed', 'lutions-wp'), home_url('/'), []);

            return;
        }

        $projectData = $projectResult['project'];
        $title = sprintf(
            __('%s - Lutions updates', 'lutions-wp'),
            is_string($projectData['name'] ?? null) && $projectData['name'] !== '' ? $projectData['name'] : strtoupper($project),
        );
        $link = self::projectFeedUrl($project);
        $items = [];
        foreach ($ticketsResult['tickets'] as $ticket) {
            $items[] = self::rssItemFromTicket($ticket);
        }

        self::renderRssFeed($title, $link, $items);
    }

    public static function projectFeedUrl(string $projectSlug): string
    {
        return home_url('/feed/' . AdminSettings::configuredProjectFeedBase() . '/' . rawurlencode(sanitize_key($projectSlug)) . '/');
    }

    /**
     * @param array<string, bool|string> $robots
     * @return array<string, bool|string>
     */
    public static function filterRobotsForPublicContent(array $robots): array
    {
        if (
            is_admin()
            || ! AdminSettings::publicContentNoindexEnabled()
            || ! self::isLutionsPublicContentRequest()
        ) {
            return $robots;
        }

        $robots['noindex'] = true;
        $robots['nofollow'] = true;

        return $robots;
    }

    /**
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    public static function filterDocumentTitleParts(array $parts): array
    {
        $context = self::seoTicketContext();
        if ($context !== null) {
            $parts['title'] = $context['title'];
        }

        return $parts;
    }

    public static function filterCanonicalUrl(string $canonicalUrl, mixed $post): string
    {
        unset($post);

        $context = self::seoTicketContext();

        return $context !== null ? $context['canonicalUrl'] : $canonicalUrl;
    }

    public static function renderTicketSeoMeta(): void
    {
        $context = self::seoTicketContext();
        if ($context === null) {
            return;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $context['title'],
            'description' => $context['description'],
            'mainEntityOfPage' => $context['canonicalUrl'],
            'url' => $context['canonicalUrl'],
            'datePublished' => $context['publishedAt'],
            'dateModified' => $context['updatedAt'],
        ];
        $schema = array_filter($schema, static fn (mixed $value): bool => $value !== '');
        $json = json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        printf('<meta name="description" content="%s" />' . "\n", esc_attr($context['description']));
        printf('<meta property="og:type" content="article" />' . "\n");
        printf('<meta property="og:title" content="%s" />' . "\n", esc_attr($context['title']));
        printf('<meta property="og:description" content="%s" />' . "\n", esc_attr($context['description']));
        printf('<meta property="og:url" content="%s" />' . "\n", esc_url($context['canonicalUrl']));
        if ($context['publishedAt'] !== '') {
            printf('<meta property="article:published_time" content="%s" />' . "\n", esc_attr($context['publishedAt']));
        }
        if ($context['updatedAt'] !== '') {
            printf('<meta property="article:modified_time" content="%s" />' . "\n", esc_attr($context['updatedAt']));
        }
        if (is_string($json)) {
            echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
        }
    }

    public static function registerPublicTicketSitemapProvider(): void
    {
        if (AdminSettings::publicContentNoindexEnabled() || ! function_exists('wp_sitemaps_get_server')) {
            return;
        }

        wp_sitemaps_get_server()->registry->add_provider('lutions', new PublicTicketSitemapProvider());
    }

    /** @return list<array{loc: string, lastmod?: string}> */
    public static function publicTicketSitemapUrls(int $page): array
    {
        $routes = self::seoProjectRoutes();
        if ($routes === []) {
            return [];
        }

        $remainingPages = $page;
        $client = new PublicTicketClient();
        foreach ($routes as $route) {
            $result = $client->getTickets($route['slug'], 50, 'published', 'desc');
            if (! $result['ok']) {
                continue;
            }
            $routePages = (int) ceil($result['pagination']['total'] / 50);
            if ($remainingPages > $routePages) {
                $remainingPages -= $routePages;
                continue;
            }

            $pageResult = $remainingPages === 1 ? $result : $client->getTickets($route['slug'], 50, 'published', 'desc', $remainingPages);
            if (! $pageResult['ok']) {
                return [];
            }

            $entries = [];
            foreach ($pageResult['tickets'] as $ticket) {
                $ticketSlug = is_string($ticket['ticketSlug'] ?? null) ? $ticket['ticketSlug'] : '';
                if ($ticketSlug === '') {
                    continue;
                }
                $lastModified = self::seoDate((string) ($ticket['updatedAt'] ?? $ticket['publishedAt'] ?? ''));
                $entry = ['loc' => self::canonicalTicketDetailUrl($route['slug'], $ticketSlug, $route['detailUrl'])];
                if ($lastModified !== '') {
                    $entry['lastmod'] = $lastModified;
                }
                $entries[] = $entry;
            }

            return $entries;
        }

        return [];
    }

    public static function publicTicketSitemapPageCount(): int
    {
        $pages = 0;
        $client = new PublicTicketClient();
        foreach (self::seoProjectRoutes() as $route) {
            $result = $client->getTickets($route['slug'], 1, 'published', 'desc');
            if ($result['ok']) {
                $pages += (int) ceil($result['pagination']['total'] / 50);
            }
        }

        return $pages;
    }

    public static function renderPublicSearchResults(): void
    {
        if (self::$publicSearchResultsRendered) {
            return;
        }

        echo self::publicSearchResultsMarkup();
    }

    public static function renderPublicSearchResultsAfterMainLoop(mixed $query): void
    {
        if (self::$publicSearchResultsRendered || ! self::isMainSearchQuery($query)) {
            return;
        }

        echo self::publicSearchResultsMarkup();
    }

    /**
     * @param array<string, mixed> $block
     */
    public static function appendPublicSearchResultsToQueryBlock(string $blockContent, array $block): string
    {
        if (
            (! is_search() && ! self::publicSearchResultHasItems())
            || self::$publicSearchResultsRendered
        ) {
            return $blockContent;
        }

        $searchResults = self::publicSearchResultsMarkup();
        if ($searchResults === '') {
            return $blockContent;
        }

        if (str_contains($blockContent, 'wp-block-query-no-results')) {
            $blockContent = str_replace(
                'wp-block-query-no-results',
                'wp-block-query-no-results lutions-wp-core-empty-hidden',
                $blockContent,
            );
        }

        return $blockContent . $searchResults;
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public static function filterBodyClassForPublicSearchResults(array $classes): array
    {
        if (self::publicSearchResultHasItems()) {
            $classes[] = 'lutions-wp-search-has-results';
        }

        return array_values(array_unique($classes));
    }

    private static function publicSearchResultsMarkup(): string
    {
        $result = self::publicSearchResult();
        if ($result === null) {
            return '';
        }

        self::enqueueFrontendAssets();
        $items = '';
        foreach ($result['categories'] as $category) {
            $items .= self::renderSearchPortalItem($category['name'], self::portalCategoryUrl($category['slug']), __('Category', 'lutions-wp'));
        }
        foreach ($result['projects'] as $project) {
            $items .= self::renderSearchPortalItem($project['name'], self::portalProjectUrl($project['slug']), $project['key']);
        }
        foreach ($result['tickets'] as $ticket) {
            $detailBaseUrl = self::ticketDetailBaseUrlForProject($ticket['projectKey']);
            $ticketMarkup = esc_html($ticket['reference']) . ': ' . esc_html($ticket['title']);
            if ($detailBaseUrl !== null) {
                $ticketMarkup = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(self::ticketDetailUrl($ticket['projectSlug'], $ticket['ticketSlug'], $detailBaseUrl)),
                    $ticketMarkup,
                );
            }
            $items .= sprintf(
                '<li>%s</li>',
                $ticketMarkup,
            );
        }

        self::$publicSearchResultsRendered = true;

        return sprintf('<section class="lutions-wp-search-results"><h2>%s</h2><ul>%s</ul></section>', esc_html__('Lutions results', 'lutions-wp'), $items);
    }

    private static function publicSearchResultHasItems(): bool
    {
        return self::publicSearchResult() !== null;
    }

    /**
     * @return array{
     *     categories: list<array{name: string, key: string, slug: string, lutionsPublicUrl: string}>,
     *     projects: list<array{name: string, key: string, slug: string, lutionsPublicUrl: string}>,
     *     tickets: list<array{reference: string, title: string, projectKey: string, projectSlug: string, ticketSlug: string}>
     * }|null
     */
    private static function publicSearchResult(): ?array
    {
        if (self::$publicSearchResultResolved) {
            return self::$publicSearchResult;
        }

        if (is_admin() || ! is_search()) {
            return null;
        }

        self::$publicSearchResultResolved = true;
        $searchQuery = trim((string) get_search_query(false));
        if (strlen($searchQuery) < 2) {
            return null;
        }

        $result = (new PublicTicketClient())->searchPublicContent($searchQuery);
        if (! $result['ok'] || ($result['categories'] === [] && $result['projects'] === [] && $result['tickets'] === [])) {
            return null;
        }

        self::$publicSearchResult = [
            'categories' => $result['categories'],
            'projects' => $result['projects'],
            'tickets' => $result['tickets'],
        ];

        return self::$publicSearchResult;
    }

    private static function isMainSearchQuery(mixed $query): bool
    {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            return false;
        }

        if (is_object($query) && method_exists($query, 'is_main_query')) {
            if (! (bool) $query->is_main_query()) {
                return false;
            }
        }

        return is_search() || self::publicSearchResultHasItems();
    }

    /**
     * @param list<string> $queryVars
     * @return list<string>
     */
    public static function registerTicketQueryVars(array $queryVars): array
    {
        $queryVars[] = 'lutions_project';
        $queryVars[] = 'lutions_ticket';
        $queryVars[] = 'lutions_page';
        $queryVars[] = 'lutions_sort_by';
        $queryVars[] = 'lutions_sort_order';
        $queryVars[] = 'lutions_portal_category';
        $queryVars[] = 'lutions_portal_project';

        return $queryVars;
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array{title: string, link: string, guid: string, pubDate: string, description: string}
     */
    private static function rssItemFromTicket(array $ticket): array
    {
        $projectSlug = is_string($ticket['projectSlug'] ?? null) ? $ticket['projectSlug'] : '';
        $ticketSlug = is_string($ticket['ticketSlug'] ?? null) ? $ticket['ticketSlug'] : '';
        $reference = is_string($ticket['reference'] ?? null) ? $ticket['reference'] : '';
        $title = is_string($ticket['title'] ?? null) ? $ticket['title'] : '';
        $status = is_string($ticket['status'] ?? null) ? $ticket['status'] : '';
        $publishedAt = is_string($ticket['publishedAt'] ?? null) ? $ticket['publishedAt'] : '';
        $updatedAt = is_string($ticket['updatedAt'] ?? null) ? $ticket['updatedAt'] : '';
        $createdAt = is_string($ticket['createdAt'] ?? null) ? $ticket['createdAt'] : '';
        $detailBaseUrl = self::ticketDetailBaseUrlForProject($projectSlug);
        $itemTitle = trim($reference . ': ' . $title, ': ');
        $date = $publishedAt !== '' ? $publishedAt : ($updatedAt !== '' ? $updatedAt : $createdAt);

        return [
            'title' => $itemTitle,
            'link' => $detailBaseUrl !== null ? self::ticketDetailUrl($projectSlug, $ticketSlug, $detailBaseUrl, 'published', 'desc') : '',
            'guid' => 'lutions:' . $projectSlug . ':' . $ticketSlug,
            'pubDate' => self::rssDate($date),
            'description' => $status !== '' ? sprintf(__('Status: %s', 'lutions-wp'), $status) : '',
        ];
    }

    /**
     * @param list<array{title: string, link: string, guid: string, pubDate: string, description: string}> $items
     */
    private static function renderRssFeed(string $title, string $link, array $items): void
    {
        if (! headers_sent()) {
            header('Content-Type: application/rss+xml; charset=UTF-8');
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0"><channel>';
        echo '<title>' . self::xmlEscape($title) . '</title>';
        echo '<link>' . self::xmlEscape($link) . '</link>';
        echo '<description>' . self::xmlEscape(__('Public Lutions project updates.', 'lutions-wp')) . '</description>';
        echo '<lastBuildDate>' . self::xmlEscape(gmdate(DATE_RSS)) . '</lastBuildDate>';
        foreach ($items as $item) {
            echo '<item>';
            echo '<title>' . self::xmlEscape($item['title']) . '</title>';
            if ($item['link'] !== '') {
                echo '<link>' . self::xmlEscape($item['link']) . '</link>';
            }
            echo '<guid isPermaLink="false">' . self::xmlEscape($item['guid']) . '</guid>';
            if ($item['pubDate'] !== '') {
                echo '<pubDate>' . self::xmlEscape($item['pubDate']) . '</pubDate>';
            }
            if ($item['description'] !== '') {
                echo '<description>' . self::xmlEscape($item['description']) . '</description>';
            }
            echo '</item>';
        }
        echo '</channel></rss>';
    }

    private static function rssDate(string $isoDate): string
    {
        $timestamp = strtotime($isoDate);
        if ($timestamp === false) {
            return '';
        }

        return gmdate(DATE_RSS, $timestamp);
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private static function isLutionsPublicContentRequest(): bool
    {
        foreach (['lutions_project', 'lutions_ticket', 'lutions_portal_category', 'lutions_portal_project'] as $queryVar) {
            $value = get_query_var($queryVar);
            if (is_string($value) && sanitize_key($value) !== '') {
                return true;
            }
        }

        global $post;
        if (! $post instanceof \WP_Post) {
            return false;
        }

        foreach (['lutions_public_tickets', 'lutions_public_ticket_detail', 'lutions_public_portal', 'lutions_portal_stats'] as $shortcode) {
            if (function_exists('has_shortcode') && has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
            if (str_contains($post->post_content, '[' . $shortcode)) {
                return true;
            }
        }

        return false;
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
     * Renders an independent public reporting page. Submission data deliberately
     * travels directly from the visitor's browser to Lutions: WordPress does not
     * retain it and Lutions can apply its own visitor rate limit and verification.
     *
     * @param array<string, mixed> $attributes
     */
    public static function renderPublicSubmission(array $attributes = []): string
    {
        self::enqueueFrontendAssets();
        $diagnostics = PublicTicketClient::apiBaseUrlDiagnostics();
        if (! $diagnostics['valid']) {
            return self::renderNotice(__('The Lutions API base URL is not configured.', 'lutions-wp'));
        }

        $title = isset($attributes['title']) && is_scalar($attributes['title'])
            ? sanitize_text_field((string) $attributes['title'])
            : __('Send a report', 'lutions-wp');
        $heading = $title !== '' ? sprintf('<h1>%s</h1>', esc_html($title)) : '';
        $formId = 'lutions-wp-public-submission-' . uniqid();
        $descriptionMinLength = AdminSettings::submissionDescriptionMinLength();
        $descriptionTooShortMessage = esc_attr(
            sprintf(__('Please describe your report in at least %d characters.', 'lutions-wp'), $descriptionMinLength),
        );

        return sprintf(
            '<section class="lutions-wp-submission">%1$s'
            . '<p>%2$s</p>'
            . '<div class="lutions-wp-submission-status" role="status" aria-live="polite" tabindex="-1"></div>'
            . '<form id="%3$s" class="lutions-wp-submission-form" data-lutions-wp-submission data-api-base-url="%4$s" '
            . 'data-loading-message="%5$s" data-unavailable-message="%6$s" data-unsupported-message="%7$s" '
            . 'data-network-message="%8$s" data-success-message="%9$s" data-sending-message="%10$s" '
            . 'data-verification-label="%11$s" data-verification-help="%12$s" '
            . 'data-required-message="%13$s" data-description-min-length="' . $descriptionMinLength . '" '
            . 'data-description-too-short-message="' . $descriptionTooShortMessage . '" '
            . 'novalidate aria-busy="true">'
            . '<fieldset disabled>'
            . '<p class="lutions-wp-submission-intro">%14$s</p>'
            . '<p><label for="%3$s-type">%15$s</label><select id="%3$s-type" name="type" required>'
            . '<option value="bug">%16$s</option><option value="feature">%17$s</option></select></p>'
            . '<p><label for="%3$s-name">%18$s</label><input id="%3$s-name" name="name" type="text" '
            . 'maxlength="120" autocomplete="name" /></p>'
            . '<p><label for="%3$s-contact">%19$s</label><input id="%3$s-contact" name="contact" type="text" '
            . 'maxlength="190" autocomplete="email" /></p>'
            . '<p><label for="%3$s-email">%20$s</label><input id="%3$s-email" name="updateEmail" type="email" '
            . 'maxlength="190" autocomplete="email" /></p>'
            . '<p><label for="%3$s-subject">%21$s</label><input id="%3$s-subject" name="subject" type="text" minlength="5" maxlength="160" required /></p>'
            . '<p><label for="%3$s-description">%22$s</label><textarea id="%3$s-description" name="description" '
            . 'rows="8" minlength="' . $descriptionMinLength . '" maxlength="5000" required></textarea></p>'
            . '<p class="lutions-wp-submission-honeypot" aria-hidden="true"><label for="%3$s-website">%23$s</label>'
            . '<input id="%3$s-website" name="companyWebsite" type="text" tabindex="-1" autocomplete="off" /></p>'
            . '<p class="lutions-wp-submission-challenge" hidden><label></label><span></span>'
            . '<input name="verificationAnswer" type="text" inputmode="numeric" maxlength="32" /></p>'
            . '<p><button type="submit">%24$s</button></p>'
            . '</fieldset></form></section>',
            $heading,
            esc_html__('Use this form to report an error or suggest an improvement. Submitted reports are reviewed internally.', 'lutions-wp'),
            esc_attr($formId),
            esc_attr($diagnostics['url']),
            esc_attr(__('Loading report form…', 'lutions-wp')),
            esc_attr(__('Reporting is currently unavailable.', 'lutions-wp')),
            esc_attr(__('The configured human verification method is not supported on this site.', 'lutions-wp')),
            esc_attr(__('Your report could not be sent. Please try again later.', 'lutions-wp')),
            esc_attr(__('Thank you. Your report was sent.', 'lutions-wp')),
            esc_attr(__('Sending report…', 'lutions-wp')),
            esc_html__('Human verification', 'lutions-wp'),
            esc_html__('Please answer the question to show that you are human.', 'lutions-wp'),
            esc_attr(__('Please complete all required fields and provide a name or contact method.', 'lutions-wp')),
            esc_html__('Fields marked as required must be completed. You must provide either your name or a contact method.', 'lutions-wp'),
            esc_html__('Type', 'lutions-wp'),
            esc_html__('Error', 'lutions-wp'),
            esc_html__('Improvement request', 'lutions-wp'),
            esc_html__('Name', 'lutions-wp'),
            esc_html__('Contact method', 'lutions-wp'),
            esc_html__('Email for status updates (optional)', 'lutions-wp'),
            esc_html__('Subject', 'lutions-wp'),
            esc_html__('Description', 'lutions-wp'),
            esc_html__('Website', 'lutions-wp'),
            esc_html__('Send report', 'lutions-wp'),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function renderPublicPortal(array $attributes = []): string
    {
        self::enqueueFrontendAssets();
        $title = isset($attributes['title']) && is_scalar($attributes['title'])
            ? sanitize_text_field((string) $attributes['title'])
            : __('Public portal', 'lutions-wp');
        $configuredCategory = isset($attributes['category']) && is_scalar($attributes['category'])
            ? sanitize_key((string) $attributes['category'])
            : '';
        $categorySlug = get_query_var('lutions_portal_category');
        $projectSlug = get_query_var('lutions_portal_project');

        if (is_string($projectSlug) && sanitize_key($projectSlug) !== '') {
            return self::renderPublicPortalProject(sanitize_key($projectSlug));
        }
        if (is_string($categorySlug) && sanitize_key($categorySlug) !== '') {
            return self::renderPublicPortalCategory(sanitize_key($categorySlug));
        }
        if ($configuredCategory !== '') {
            return self::renderPublicPortalCategory($configuredCategory);
        }

        $result = (new PublicTicketClient())->getPublicPortal();
        if (! $result['ok']) {
            return self::renderNotice($result['message']);
        }

        $categories = self::renderPortalCategories($result['categories']);
        $projects = self::renderPortalProjects($result['projects']);
        $heading = $title !== '' ? sprintf('<h1>%s</h1>', esc_html($title)) : '';

        return sprintf(
            '<section class="lutions-wp-portal">%s%s%s</section>',
            $heading,
            $categories,
            $projects,
        );
    }

    private static function renderPublicPortalCategory(string $categorySlug): string
    {
        $result = (new PublicTicketClient())->getPublicCategory($categorySlug);
        if (! $result['ok']) {
            return self::renderNotice($result['message']);
        }

        $category = $result['category'];
        $description = $category['description'] !== '' ? sprintf('<p>%s</p>', esc_html($category['description'])) : '';

        return sprintf(
            '<section class="lutions-wp-portal"><p class="lutions-wp-portal-back"><a href="%s">%s</a></p><h1>%s</h1>%s%s</section>',
            esc_url(self::portalHomeUrl()),
            esc_html__('All projects', 'lutions-wp'),
            esc_html($category['name']),
            $description,
            self::renderPortalProjects($result['projects'], $categorySlug),
        );
    }

    private static function renderPublicPortalProject(string $projectSlug): string
    {
        $client = new PublicTicketClient();
        $projectResult = $client->getPublicProject($projectSlug);
        if (! $projectResult['ok']) {
            return self::renderNotice($projectResult['message']);
        }
        $ticketsResult = $client->getTickets($projectSlug, 20);
        if (! $ticketsResult['ok']) {
            return self::renderNotice($ticketsResult['message']);
        }

        $project = $projectResult['project'];
        $description = $project['description'] !== '' ? sprintf('<p>%s</p>', esc_html($project['description'])) : '';
        $backUrl = self::portalHomeUrl();
        $backLabel = __('All projects', 'lutions-wp');
        $categorySlug = get_query_var('lutions_portal_category');
        if (is_string($categorySlug) && sanitize_key($categorySlug) !== '') {
            $backUrl = self::portalCategoryUrl(sanitize_key($categorySlug), true);
            $backLabel = __('Back to category', 'lutions-wp');
        }

        return sprintf(
            '<section class="lutions-wp-portal"><p class="lutions-wp-portal-back"><a href="%s">%s</a></p><h1>%s</h1>%s%s</section>',
            esc_url($backUrl),
            esc_html($backLabel),
            esc_html($project['name']),
            $description,
            self::renderPortalTickets($ticketsResult['tickets'], $projectSlug),
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
        $detailBaseUrl = self::ticketDetailBaseUrl($attributes, $project);
        $renderDetail = self::shouldRenderTicketDetail($attributes);
        $showKeyInTitle = self::booleanAttribute($attributes, 'show_key_in_title', true);
        $listMetaFields = self::metaFieldsAttribute($attributes, 'meta_in_list');
        $detailMetaFields = self::metaFieldsAttribute($attributes, 'meta_in_detail');
        $showMore = self::booleanAttribute($attributes, 'show_more', false);
        $showRss = self::booleanAttribute($attributes, 'show_rss', ! self::isWidgetContext($attributes));
        $paginationEnabled = self::booleanAttribute($attributes, 'pagination', false);
        $presentation = self::ticketListPresentation($attributes);
        $excerptWords = $presentation['excerpt_words'];
        $page = $paginationEnabled ? self::ticketListCurrentPage() : 1;
        $sortBy = self::sortByAttribute($attributes);
        $sortOrder = self::sortOrderAttribute($attributes);

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
            return self::renderPublicTicketDetail($project, $requestedTicket, $detailBaseUrl, [
                'showKeyInTitle' => $showKeyInTitle,
                'metaFields' => $detailMetaFields,
                'sortBy' => $sortBy,
                'sortOrder' => $sortOrder,
            ]);
        }

        $result = (new PublicTicketClient())->getTickets($project, $limit, $sortBy, $sortOrder, $page, $excerptWords);
        if (! $result['ok']) {
            return self::renderNotice($result['message']);
        }

        $items = '';
        foreach ($result['tickets'] as $index => $ticket) {
            $ticketDetailBaseUrl = self::ticketDetailBaseUrl($attributes, $ticket['projectKey']);
            $ticketDetailUrl = self::ticketDetailUrl(
                $ticket['projectSlug'],
                $ticket['ticketSlug'],
                $ticketDetailBaseUrl,
                $sortBy,
                $sortOrder,
            );
            $ticketTitle = $showKeyInTitle
                ? $ticket['reference'] . ': ' . $ticket['title']
                : $ticket['title'];
            $metadata = self::renderTicketListMeta($ticket, [
                'metaFields' => $listMetaFields,
                'parenthesize' => $presentation['meta_position'] === 'inline',
            ]);
            $metadataAbove = $presentation['meta_position'] === 'above' && $metadata !== ''
                ? sprintf('<div class="lutions-wp-ticket-meta-row">%s</div>', $metadata)
                : '';
            $metadataInline = $presentation['meta_position'] === 'inline' ? $metadata : '';
            $typeBadge = $presentation['show_type_badge'] ? self::renderTicketTypeBadge($ticket) : '';
            $featuredClass = $presentation['featured_latest'] && $index === 0 ? ' is-featured' : '';
            $items .= sprintf(
                '<li class="lutions-wp-ticket-item%s">%s%s<div class="lutions-wp-ticket-title"><a href="%s">%s</a>%s</div>%s</li>',
                esc_attr($featuredClass),
                $metadataAbove,
                $typeBadge,
                esc_url($ticketDetailUrl),
                esc_html($ticketTitle),
                $metadataInline,
                self::renderTicketListExcerpt($ticket, $ticketDetailUrl, $presentation['show_read_more']),
            );
        }

        if ($items === '') {
            return self::renderNotice(__('No public tickets are currently available for this project.', 'lutions-wp'));
        }

        $heading = $title !== ''
            ? sprintf('<h2>%s</h2>', esc_html($title))
            : '';
        $feedLink = $showRss ? self::renderTicketListFeedLinkRow($project) : '';
        $moreLink = $showMore
            ? sprintf(
                '<p class="lutions-wp-ticket-list-more"><a href="%s">%s</a></p>',
                esc_url($detailBaseUrl),
                esc_html__('More', 'lutions-wp'),
            )
            : '';
        $pagination = $paginationEnabled
            ? self::renderTicketListPagination((int) $result['pagination']['page'], (bool) $result['pagination']['hasNextPage'])
            : '';

        return sprintf(
            '<section class="lutions-wp-tickets lutions-wp-tickets--%s">%s<ul>%s</ul>%s%s%s</section>',
            esc_attr($presentation['layout']),
            $heading,
            $items,
            $pagination,
            $moreLink,
            $feedLink,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function renderPublicTicketDetailShortcode(array $attributes = []): string
    {
        $project = get_query_var('lutions_project');
        $ticket = get_query_var('lutions_ticket');
        if (! is_string($project) || ! is_string($ticket) || sanitize_key($project) === '' || sanitize_key($ticket) === '') {
            return self::renderNotice(__('Select a public ticket to view its details.', 'lutions-wp'));
        }

        return self::renderPublicTicketDetail(
            $project,
            $ticket,
            self::currentPageUrl(),
            [
                'showKeyInTitle' => self::booleanAttribute($attributes, 'show_key_in_title', true),
                'metaFields' => self::metaFieldsAttribute($attributes, 'meta_in_detail'),
                'sortBy' => self::sortByQueryOrAttribute($attributes),
                'sortOrder' => self::sortOrderQueryOrAttribute($attributes),
            ],
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

    /** @param list<array<string, string|int>> $categories */
    private static function renderPortalCategories(array $categories): string
    {
        if ($categories === []) {
            return '';
        }

        $items = '';
        foreach ($categories as $category) {
            $description = is_string($category['shortDescription'] ?? null) && $category['shortDescription'] !== ''
                ? sprintf('<p>%s</p>', esc_html($category['shortDescription']))
                : '';
            $count = is_int($category['publicProjectCount'] ?? null) ? $category['publicProjectCount'] : 0;
            $countLabel = $count === 1
                ? __('1 public project', 'lutions-wp')
                : sprintf(__('%d public projects', 'lutions-wp'), $count);
            $items .= sprintf(
                '<li><h2><a href="%s">%s</a></h2>%s<p class="lutions-wp-portal-count">%s</p></li>',
                esc_url(self::portalCategoryUrl((string) $category['slug'], true)),
                esc_html((string) $category['name']),
                $description,
                esc_html($countLabel),
            );
        }

        $markup = '<section class="lutions-wp-portal-section"><h2>%s</h2>';
        $markup .= '<ul class="lutions-wp-portal-cards">%s</ul></section>';

        return sprintf($markup, esc_html__('Categories', 'lutions-wp'), $items);
    }

    /** @param list<array<string, string>> $projects */
    private static function renderPortalProjects(array $projects, ?string $categorySlug = null): string
    {
        if ($projects === []) {
            return '';
        }

        $items = '';
        foreach ($projects as $project) {
            $description = $project['description'] !== '' ? sprintf('<p>%s</p>', esc_html($project['description'])) : '';
            $items .= sprintf(
                '<li><h2><a href="%s">%s</a><span class="lutions-wp-portal-key">%s</span></h2>%s</li>',
                esc_url(self::portalProjectUrl($project['slug'], $categorySlug, true)),
                esc_html($project['name']),
                esc_html($project['key']),
                $description,
            );
        }

        $markup = '<section class="lutions-wp-portal-section"><h2>%s</h2>';
        $markup .= '<ul class="lutions-wp-portal-cards">%s</ul></section>';

        return sprintf($markup, esc_html__('Projects', 'lutions-wp'), $items);
    }

    /** @param list<array<string, mixed>> $tickets */
    private static function renderPortalTickets(array $tickets, string $projectSlug = ''): string
    {
        if ($tickets === []) {
            return self::renderNotice(__('No public tickets are currently available for this project.', 'lutions-wp'));
        }

        $items = '';
        foreach ($tickets as $ticket) {
            $title = (string) $ticket['reference'] . ': ' . (string) $ticket['title'];
            $detailBaseUrl = self::ticketDetailBaseUrlForProject((string) $ticket['projectKey']);
            $titleMarkup = $detailBaseUrl === null
                ? esc_html($title)
                : sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(self::ticketDetailUrl((string) $ticket['projectSlug'], (string) $ticket['ticketSlug'], $detailBaseUrl)),
                    esc_html($title),
                );
            $metadata = self::renderTicketListMeta($ticket, ['metaFields' => ['status', 'priority', 'updated']]);
            $items .= sprintf('<li>%s%s</li>', $titleMarkup, $metadata);
        }

        $heading = sprintf('<h2>%s</h2>', esc_html__('Public tickets', 'lutions-wp'));
        $feedLink = $projectSlug !== '' ? self::renderTicketListFeedLinkRow($projectSlug) : '';
        $markup = '<section class="lutions-wp-tickets">%s<ul>%s</ul>%s</section>';

        return sprintf($markup, $heading, $items, $feedLink);
    }

    private static function renderSearchPortalItem(string $label, ?string $url, string $meta): string
    {
        $labelMarkup = $url === null
            ? esc_html($label)
            : sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($label));

        return sprintf('<li>%s <span>%s</span></li>', $labelMarkup, esc_html($meta));
    }

    private static function portalHomeUrl(): string
    {
        $configuredUrl = AdminSettings::configuredPortalPageUrl();

        return $configuredUrl !== '' ? $configuredUrl : self::currentPageUrl();
    }

    private static function portalCategoryUrl(string $categorySlug, bool $useCurrentPageFallback = false): ?string
    {
        $configuredUrl = AdminSettings::configuredPortalPageUrl();
        if ($configuredUrl === '' && ! $useCurrentPageFallback) {
            return null;
        }

        return add_query_arg(['lutions_portal_category' => $categorySlug], $configuredUrl !== '' ? $configuredUrl : self::currentPageUrl());
    }

    private static function portalProjectUrl(string $projectSlug, ?string $categorySlug = null, bool $useCurrentPageFallback = false): ?string
    {
        $configuredUrl = AdminSettings::configuredPortalPageUrl();
        if ($configuredUrl === '' && ! $useCurrentPageFallback) {
            return null;
        }

        $args = ['lutions_portal_project' => $projectSlug];
        if ($categorySlug !== null && $categorySlug !== '') {
            $args['lutions_portal_category'] = $categorySlug;
        }

        return add_query_arg($args, $configuredUrl !== '' ? $configuredUrl : self::currentPageUrl());
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
     * @param array<string, mixed> $attributes
     * @return array{layout: string, featured_latest: bool, excerpt_words: int, show_type_badge: bool, show_read_more: bool, meta_position: string}
     */
    private static function ticketListPresentation(array $attributes): array
    {
        $presentation = AdminSettings::ticketListPresentation();
        $presentation['layout'] = self::enumAttribute(
            $attributes,
            'layout',
            ['classic', 'editorial', 'compact'],
            $presentation['layout'],
        );
        $presentation['featured_latest'] = self::booleanAttribute(
            $attributes,
            'featured_latest',
            $presentation['featured_latest'],
        );
        $presentation['excerpt_words'] = self::integerAttribute(
            $attributes,
            'excerpt_words',
            100,
            $presentation['excerpt_words'],
        );
        $presentation['show_type_badge'] = self::booleanAttribute(
            $attributes,
            'show_type_badge',
            $presentation['show_type_badge'],
        );
        $presentation['show_read_more'] = self::booleanAttribute(
            $attributes,
            'show_read_more',
            $presentation['show_read_more'],
        );
        $presentation['meta_position'] = self::enumAttribute(
            $attributes,
            'meta_position',
            ['inline', 'above'],
            $presentation['meta_position'],
        );

        return $presentation;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $allowedValues
     */
    private static function enumAttribute(array $attributes, string $name, array $allowedValues, string $default): string
    {
        if (! isset($attributes[$name]) || ! is_scalar($attributes[$name])) {
            return $default;
        }

        $value = strtolower(trim((string) $attributes[$name]));

        return in_array($value, $allowedValues, true) ? $value : $default;
    }

    /** @param array<string, mixed> $attributes */
    private static function integerAttribute(array $attributes, string $name, int $maximum, int $default): int
    {
        if (! isset($attributes[$name]) || ! is_scalar($attributes[$name])) {
            return $default;
        }

        return max(0, min($maximum, (int) $attributes[$name]));
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array{metaFields: list<string>, parenthesize?: bool} $options
     */
    private static function renderTicketListMeta(array $ticket, array $options): string
    {
        $parts = self::ticketMetaParts($ticket, $options['metaFields']);

        if ($parts === []) {
            return '';
        }

        $text = implode(' / ', $parts);
        if ($options['parenthesize'] ?? true) {
            $text = '(' . $text . ')';
        }

        return sprintf('<span class="lutions-wp-ticket-meta"> %s</span>', esc_html($text));
    }

    /** @param array<string, mixed> $ticket */
    private static function renderTicketListExcerpt(array $ticket, string $ticketDetailUrl, bool $showReadMore): string
    {
        $excerpt = is_string($ticket['descriptionExcerpt'] ?? null) ? trim($ticket['descriptionExcerpt']) : '';
        if ($excerpt === '') {
            return '';
        }

        $moreLink = $showReadMore && (bool) ($ticket['descriptionExcerptTruncated'] ?? false)
            ? sprintf(
                '… <a class="lutions-wp-ticket-excerpt-more" href="%s">%s</a>',
                esc_url($ticketDetailUrl),
                esc_html__('More', 'lutions-wp'),
            )
            : '';

        return sprintf('<p class="lutions-wp-ticket-excerpt">%s%s</p>', esc_html($excerpt), $moreLink);
    }

    /** @param array<string, mixed> $ticket */
    private static function renderTicketTypeBadge(array $ticket): string
    {
        $ticketType = is_array($ticket['ticketType'] ?? null) && is_string($ticket['ticketType']['name'] ?? null)
            ? trim($ticket['ticketType']['name'])
            : '';
        $type = $ticketType !== '' ? $ticketType : (is_string($ticket['type'] ?? null) ? trim($ticket['type']) : '');

        return $type !== ''
            ? sprintf('<span class="lutions-wp-ticket-type-badge">%s</span>', esc_html($type))
            : '';
    }

    private static function renderTicketListFeedLink(string $projectSlug): string
    {
        return sprintf(
            '<a class="lutions-wp-ticket-list-feed" href="%s" rel="alternate" type="application/rss+xml">%s</a>',
            esc_url(self::projectFeedUrl($projectSlug)),
            esc_html__('RSS feed', 'lutions-wp'),
        );
    }

    private static function renderTicketListFeedLinkRow(string $projectSlug): string
    {
        return sprintf(
            '<p class="lutions-wp-ticket-list-feed-row">%s</p>',
            self::renderTicketListFeedLink($projectSlug),
        );
    }

    private static function renderTicketListPagination(int $page, bool $hasNextPage): string
    {
        $page = max(1, $page);
        if ($page <= 1 && ! $hasNextPage) {
            return '';
        }

        $links = [];
        if ($page > 1) {
            $links[] = sprintf(
                '<a class="lutions-wp-ticket-list-page-link" href="%s" rel="prev">%s</a>',
                esc_url(self::ticketListPageUrl($page - 1)),
                esc_html__('Previous', 'lutions-wp'),
            );
        }

        $links[] = sprintf(
            '<span class="lutions-wp-ticket-list-page-current">%s</span>',
            esc_html(sprintf(__('Page %d', 'lutions-wp'), $page)),
        );

        if ($hasNextPage) {
            $links[] = sprintf(
                '<a class="lutions-wp-ticket-list-page-link" href="%s" rel="next">%s</a>',
                esc_url(self::ticketListPageUrl($page + 1)),
                esc_html__('Next', 'lutions-wp'),
            );
        }

        return sprintf(
            '<nav class="lutions-wp-ticket-list-pagination" aria-label="%s">%s</nav>',
            esc_attr(__('Ticket list pagination', 'lutions-wp')),
            implode('', $links),
        );
    }

    private static function ticketListPageUrl(int $page): string
    {
        return add_query_arg(
            ['lutions_page' => $page > 1 ? (string) $page : false],
            self::currentPageUrl(),
        );
    }

    private static function ticketListCurrentPage(): int
    {
        $value = get_query_var('lutions_page', 1);
        if (! is_scalar($value)) {
            return 1;
        }

        return max(1, (int) $value);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function metaFieldsAttribute(array $attributes, string $attribute): array
    {
        if (! isset($attributes[$attribute]) || ! is_scalar($attributes[$attribute])) {
            return ['status', 'priority', 'created'];
        }

        $value = strtolower(trim((string) $attributes[$attribute]));
        if ($value === 'none') {
            return [];
        }

        $fields = [];
        foreach (explode(',', $value) as $field) {
            $field = sanitize_key(trim($field));
            if (in_array($field, ['key', 'created', 'updated', 'published', 'priority', 'status'], true) && ! in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $ticket
     * @param list<string> $metaFields
     * @return list<string>
     */
    private static function ticketMetaParts(array $ticket, array $metaFields): array
    {
        $parts = [];
        foreach ($metaFields as $field) {
            $value = match ($field) {
                'key' => is_string($ticket['reference'] ?? null) ? $ticket['reference'] : '',
                'created' => self::formatDate(is_string($ticket['createdAt'] ?? null) ? $ticket['createdAt'] : ''),
                'updated' => self::formatDate(is_string($ticket['updatedAt'] ?? null) ? $ticket['updatedAt'] : ''),
                'published' => self::formatDate(is_string($ticket['publishedAt'] ?? null) ? $ticket['publishedAt'] : ''),
                'status' => is_string($ticket['status'] ?? null) ? $ticket['status'] : '',
                'priority' => is_string($ticket['priority'] ?? null) ? $ticket['priority'] : '',
                default => '',
            };
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts;
    }

    /** @param array<string, mixed> $attributes */
    private static function sortByAttribute(array $attributes): string
    {
        $value = isset($attributes['sort_by']) && is_scalar($attributes['sort_by'])
            ? sanitize_key((string) $attributes['sort_by'])
            : 'created';

        return self::normalizeSortBy($value);
    }

    /** @param array<string, mixed> $attributes */
    private static function sortOrderAttribute(array $attributes): string
    {
        $value = isset($attributes['sort_order']) && is_scalar($attributes['sort_order'])
            ? sanitize_key((string) $attributes['sort_order'])
            : 'desc';

        return self::normalizeSortOrder($value);
    }

    /** @param array<string, mixed> $attributes */
    private static function sortByQueryOrAttribute(array $attributes): string
    {
        $value = get_query_var('lutions_sort_by');
        if (is_string($value) && $value !== '') {
            return self::normalizeSortBy($value);
        }

        return self::sortByAttribute($attributes);
    }

    /** @param array<string, mixed> $attributes */
    private static function sortOrderQueryOrAttribute(array $attributes): string
    {
        $value = get_query_var('lutions_sort_order');
        if (is_string($value) && $value !== '') {
            return self::normalizeSortOrder($value);
        }

        return self::sortOrderAttribute($attributes);
    }

    private static function normalizeSortBy(string $value): string
    {
        $value = sanitize_key($value);

        return in_array($value, ['created', 'updated', 'published'], true) ? $value : 'created';
    }

    private static function normalizeSortOrder(string $value): string
    {
        $value = sanitize_key($value);

        return in_array($value, ['asc', 'desc'], true) ? $value : 'desc';
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
        if (self::isWidgetContext($attributes)) {
            return false;
        }

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
    private static function isWidgetContext(array $attributes): bool
    {
        $context = isset($attributes['context']) && is_scalar($attributes['context'])
            ? sanitize_key((string) $attributes['context'])
            : '';
        if (in_array($context, ['widget', 'sidebar'], true)) {
            return true;
        }

        return doing_filter('widget_text') || doing_filter('widget_block_content');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function ticketDetailBaseUrl(array $attributes, string $projectSlug): string
    {
        $attributeUrl = isset($attributes['detail_url']) && is_scalar($attributes['detail_url'])
            ? AdminSettings::normalizeLocalPageUrl((string) $attributes['detail_url'])
            : null;
        if (is_string($attributeUrl) && $attributeUrl !== '') {
            return $attributeUrl;
        }

        $configuredUrl = self::ticketDetailBaseUrlForProject($projectSlug);
        if ($configuredUrl !== null) {
            return $configuredUrl;
        }

        return self::currentPageUrl();
    }

    private static function ticketDetailBaseUrlForProject(string $projectSlug): ?string
    {
        $projectKey = sanitize_key($projectSlug);
        $projectUrls = AdminSettings::configuredProjectDetailPageUrls();
        if (isset($projectUrls[$projectKey])) {
            return $projectUrls[$projectKey];
        }

        $configuredUrl = AdminSettings::configuredDetailPageUrl();

        return $configuredUrl !== '' ? $configuredUrl : null;
    }

    /**
     * @return array{
     *     projectSlug: string, ticketSlug: string, canonicalUrl: string, title: string,
     *     description: string, publishedAt: string, updatedAt: string
     * }|null
     */
    private static function seoTicketContext(): ?array
    {
        if (self::$seoTicketContextResolved) {
            return self::$seoTicketContext;
        }

        self::$seoTicketContextResolved = true;
        if (is_admin() || AdminSettings::publicContentNoindexEnabled()) {
            return null;
        }

        $projectSlug = get_query_var('lutions_project');
        $ticketSlug = get_query_var('lutions_ticket');
        if (! is_string($projectSlug) || ! is_string($ticketSlug)) {
            return null;
        }

        $projectSlug = sanitize_key($projectSlug);
        $ticketSlug = sanitize_key($ticketSlug);
        if ($projectSlug === '' || $ticketSlug === '') {
            return null;
        }

        $client = new PublicTicketClient();
        $projectResult = $client->getPublicProject($projectSlug);
        $projectKey = is_string($projectResult['project']['key'] ?? null) ? sanitize_key($projectResult['project']['key']) : '';
        $detailPageUrls = AdminSettings::configuredProjectDetailPageUrls();
        $detailBaseUrl = $projectKey !== '' ? ($detailPageUrls[$projectKey] ?? '') : '';
        if (! $projectResult['ok'] || $detailBaseUrl === '') {
            return null;
        }

        $ticketResult = $client->getTicketDetail($projectSlug, $ticketSlug);
        $ticket = $ticketResult['ticket'];
        if (! $ticketResult['ok'] || ! is_string($ticket['title'] ?? null) || ! is_string($ticket['description'] ?? null)) {
            return null;
        }

        $description = self::seoDescription($ticket['description']);
        if ($description === '') {
            $description = $ticket['title'];
        }

        self::$seoTicketContext = [
            'projectSlug' => $projectSlug,
            'ticketSlug' => $ticketSlug,
            'canonicalUrl' => self::canonicalTicketDetailUrl($projectSlug, $ticketSlug, $detailBaseUrl),
            'title' => $ticket['title'],
            'description' => $description,
            'publishedAt' => self::seoDate(is_string($ticket['publishedAt'] ?? null) ? $ticket['publishedAt'] : ''),
            'updatedAt' => self::seoDate(is_string($ticket['updatedAt'] ?? null) ? $ticket['updatedAt'] : ''),
        ];

        return self::$seoTicketContext;
    }

    /** @return list<array{slug: string, detailUrl: string}> */
    private static function seoProjectRoutes(): array
    {
        if (AdminSettings::publicContentNoindexEnabled()) {
            return [];
        }

        $detailPageUrls = AdminSettings::configuredProjectDetailPageUrls();
        if ($detailPageUrls === []) {
            return [];
        }

        $options = (new PublicTicketClient())->getPublicProjectOptions();
        if (! $options['ok']) {
            return [];
        }

        $routes = [];
        foreach ($options['projects'] as $project) {
            $key = sanitize_key($project['key']);
            $slug = sanitize_key($project['slug']);
            $detailUrl = $key !== '' ? ($detailPageUrls[$key] ?? '') : '';
            if ($slug !== '' && $detailUrl !== '') {
                $routes[] = ['slug' => $slug, 'detailUrl' => $detailUrl];
            }
        }

        return $routes;
    }

    private static function canonicalTicketDetailUrl(string $projectSlug, string $ticketSlug, string $detailBaseUrl): string
    {
        return add_query_arg(
            [
                'lutions_project' => $projectSlug,
                'lutions_ticket' => $ticketSlug,
            ],
            $detailBaseUrl,
        );
    }

    private static function seoDescription(string $description): string
    {
        $plainText = trim((string) preg_replace('/\s+/', ' ', strip_tags($description)));
        if (strlen($plainText) <= 160) {
            return $plainText;
        }

        return rtrim(substr($plainText, 0, 157)) . '...';
    }

    private static function seoDate(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? '' : gmdate(DATE_W3C, $timestamp);
    }

    private static function ticketDetailUrl(
        string $projectSlug,
        string $ticketSlug,
        string $detailBaseUrl,
        string $sortBy = 'created',
        string $sortOrder = 'desc',
    ): string {
        return add_query_arg(
            [
                'lutions_project' => $projectSlug,
                'lutions_ticket' => $ticketSlug,
                'lutions_sort_by' => $sortBy,
                'lutions_sort_order' => $sortOrder,
            ],
            $detailBaseUrl,
        );
    }

    private static function currentPageUrl(): string
    {
        $permalink = get_permalink();

        return is_string($permalink) && $permalink !== '' ? $permalink : home_url('/');
    }

    /**
     * @param array{showKeyInTitle?: bool, metaFields?: list<string>, sortBy?: string, sortOrder?: string} $presentation
     */
    public static function renderPublicTicketDetail(
        string $projectSlug,
        string $ticketSlug,
        ?string $backUrl = null,
        array $presentation = [],
    ): string {
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
        $showKeyInTitle = $presentation['showKeyInTitle'] ?? true;
        $metaFields = $presentation['metaFields'] ?? ['status', 'priority', 'created'];
        $sortBy = self::normalizeSortBy(is_string($presentation['sortBy'] ?? null) ? $presentation['sortBy'] : 'created');
        $sortOrder = self::normalizeSortOrder(is_string($presentation['sortOrder'] ?? null) ? $presentation['sortOrder'] : 'desc');
        $metadata = self::renderTicketDetailMeta($ticketData, $metaFields);
        $title = $showKeyInTitle
            ? $ticketData['reference'] . ': ' . $ticketData['title']
            : $ticketData['title'];
        $comments = self::renderComments($ticketData['comments']);
        $attachments = self::renderAttachments($ticketData['attachments']);
        $navigation = self::renderTicketNavigation($project, $ticket, $backUrl, $sortBy, $sortOrder);
        $markup = '<article class="lutions-wp-ticket-detail"><p><a href="%s">%s</a></p>';
        $markup .= '<h1>%s</h1>%s';
        $markup .= '<div class="lutions-wp-ticket-description">%s</div>%s%s%s</article>';

        return sprintf(
            $markup,
            esc_url($backUrl ?? home_url('/')),
            esc_html__('Back', 'lutions-wp'),
            esc_html($title),
            $metadata,
            MarkdownRenderer::render(
                is_string($ticketData['descriptionMarkdown'] ?? null) ? $ticketData['descriptionMarkdown'] : '',
                $ticketData['description'],
            ),
            $comments,
            $attachments,
            $navigation,
        );
    }

    private static function renderTicketNavigation(
        string $projectSlug,
        string $ticketSlug,
        ?string $detailBaseUrl,
        string $sortBy,
        string $sortOrder,
    ): string {
        if (! AdminSettings::ticketNavigationEnabled()) {
            return '';
        }

        $adjacent = (new PublicTicketClient())->getAdjacentTickets($projectSlug, $ticketSlug, $sortBy, $sortOrder);
        if (! $adjacent['ok']) {
            return '';
        }

        $links = [];
        foreach (['newer' => __('Newer ticket', 'lutions-wp'), 'older' => __('Older ticket', 'lutions-wp')] as $direction => $label) {
            $ticket = $adjacent[$direction];
            if (! is_array($ticket)) {
                continue;
            }
            $target = self::ticketDetailBaseUrlForProject((string) $ticket['projectKey'])
                ?? $detailBaseUrl
                ?? self::currentPageUrl();
            $links[] = sprintf(
                '<a href="%s"><span>%s</span><strong>%s</strong></a>',
                esc_url(self::ticketDetailUrl((string) $ticket['projectSlug'], (string) $ticket['ticketSlug'], $target, $sortBy, $sortOrder)),
                esc_html($label),
                esc_html((string) $ticket['title']),
            );
        }

        return $links === [] ? '' : sprintf(
            '<nav class="lutions-wp-ticket-navigation" aria-label="%s">%s</nav>',
            esc_attr(__('Ticket navigation', 'lutions-wp')),
            implode('', $links),
        );
    }

    /**
     * @param array<string, mixed> $ticket
     * @param list<string> $metaFields
     */
    private static function renderTicketDetailMeta(
        array $ticket,
        array $metaFields,
    ): string {
        $parts = self::ticketMetaParts($ticket, $metaFields);

        if ($parts === []) {
            return '';
        }

        return sprintf(
            '<p class="lutions-wp-ticket-meta">%s</p>',
            esc_html(implode(' / ', $parts)),
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

    public static function enqueueFrontendAssets(): void
    {
        wp_enqueue_style(
            'lutions-wp-frontend',
            LUTIONS_WP_URL . 'assets/css/frontend.css',
            [],
            self::assetVersion('assets/css/frontend.css'),
        );

        wp_enqueue_script(
            'lutions-wp-frontend',
            LUTIONS_WP_URL . 'assets/js/frontend.js',
            [],
            self::assetVersion('assets/js/frontend.js'),
            true,
        );
    }

    private static function assetVersion(string $relativePath): string
    {
        $path = LUTIONS_WP_PATH . $relativePath;
        if (! is_file($path)) {
            return LUTIONS_WP_VERSION;
        }

        $modifiedAt = filemtime($path);

        return $modifiedAt === false ? LUTIONS_WP_VERSION : LUTIONS_WP_VERSION . '-' . $modifiedAt;
    }
}
