<?php

declare(strict_types=1);

namespace LutionsWp;

final class Plugin
{
    private static bool $publicSearchResultsRendered = false;

    public static function boot(): void
    {
        AdminSettings::boot();
        add_action('init', [self::class, 'loadTextDomain']);
        add_action('init', [self::class, 'registerShortcodes']);
        add_filter('query_vars', [self::class, 'registerTicketQueryVars']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueFrontendAssets']);
        add_action('get_footer', [self::class, 'renderPublicSearchResults']);
        add_filter('render_block_core/query', [self::class, 'appendPublicSearchResultsToQueryBlock'], 10, 2);
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
        add_shortcode('lutions_release_feed', [self::class, 'renderContractPendingNotice']);
        add_shortcode('lutions_portal_stats', [self::class, 'renderPortalStats']);
    }

    public static function renderPublicSearchResults(): void
    {
        if (self::$publicSearchResultsRendered) {
            return;
        }

        echo self::publicSearchResultsMarkup();
    }

    /**
     * @param array<string, mixed> $block
     */
    public static function appendPublicSearchResultsToQueryBlock(string $blockContent, array $block): string
    {
        $query = $block['attrs']['query'] ?? null;
        if (! is_array($query) || ($query['inherit'] ?? false) !== true || self::$publicSearchResultsRendered) {
            return $blockContent;
        }

        $searchResults = self::publicSearchResultsMarkup();
        if ($searchResults === '') {
            return $blockContent;
        }

        return str_replace(
            'wp-block-query-no-results',
            'wp-block-query-no-results lutions-wp-core-empty-hidden',
            $blockContent,
        ) . $searchResults;
    }

    private static function publicSearchResultsMarkup(): string
    {
        if (is_admin() || ! is_search()) {
            return '';
        }

        $searchQuery = trim((string) get_search_query(false));
        if (strlen($searchQuery) < 2) {
            return '';
        }

        $result = (new PublicTicketClient())->searchPublicContent($searchQuery);
        if (! $result['ok'] || ($result['categories'] === [] && $result['projects'] === [] && $result['tickets'] === [])) {
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

    /**
     * @param list<string> $queryVars
     * @return list<string>
     */
    public static function registerTicketQueryVars(array $queryVars): array
    {
        $queryVars[] = 'lutions_project';
        $queryVars[] = 'lutions_ticket';
        $queryVars[] = 'lutions_portal_category';
        $queryVars[] = 'lutions_portal_project';

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
            self::renderPortalTickets($ticketsResult['tickets']),
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
            ]);
        }

        $result = (new PublicTicketClient())->getTickets($project, $limit, $sortBy, $sortOrder);
        if (! $result['ok']) {
            return self::renderNotice($result['message']);
        }

        $items = '';
        foreach ($result['tickets'] as $ticket) {
            $ticketDetailBaseUrl = self::ticketDetailBaseUrl($attributes, $ticket['projectKey']);
            $ticketTitle = $showKeyInTitle
                ? $ticket['reference'] . ': ' . $ticket['title']
                : $ticket['title'];
            $items .= sprintf(
                '<li><a href="%s">%s</a>%s</li>',
                esc_url(self::ticketDetailUrl($ticket['projectSlug'], $ticket['ticketSlug'], $ticketDetailBaseUrl)),
                esc_html($ticketTitle),
                self::renderTicketListMeta($ticket, [
                    'metaFields' => $listMetaFields,
                ]),
            );
        }

        if ($items === '') {
            return self::renderNotice(__('No public tickets are currently available for this project.', 'lutions-wp'));
        }

        $heading = $title !== ''
            ? sprintf('<h2>%s</h2>', esc_html($title))
            : '';
        $moreLink = $showMore
            ? sprintf(
                '<p class="lutions-wp-ticket-list-more"><a href="%s">%s</a></p>',
                esc_url($detailBaseUrl),
                esc_html__('More', 'lutions-wp'),
            )
            : '';

        return sprintf(
            '<section class="lutions-wp-tickets">%s<ul>%s</ul>%s</section>',
            $heading,
            $items,
            $moreLink,
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
    private static function renderPortalTickets(array $tickets): string
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

        $markup = '<section class="lutions-wp-tickets"><h2>%s</h2><ul>%s</ul></section>';

        return sprintf($markup, esc_html__('Public tickets', 'lutions-wp'), $items);
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
     * @param array<string, mixed> $ticket
     * @param array{metaFields: list<string>} $options
     */
    private static function renderTicketListMeta(array $ticket, array $options): string
    {
        $parts = self::ticketMetaParts($ticket, $options['metaFields']);

        if ($parts === []) {
            return '';
        }

        return sprintf(
            '<span class="lutions-wp-ticket-meta"> (%s)</span>',
            esc_html(implode(' / ', $parts)),
        );
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
            if (in_array($field, ['key', 'created', 'updated', 'priority', 'status'], true) && ! in_array($field, $fields, true)) {
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

        return in_array($value, ['created', 'updated'], true) ? $value : 'created';
    }

    /** @param array<string, mixed> $attributes */
    private static function sortOrderAttribute(array $attributes): string
    {
        $value = isset($attributes['sort_order']) && is_scalar($attributes['sort_order'])
            ? sanitize_key((string) $attributes['sort_order'])
            : 'desc';

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

    /**
     * @param array{showKeyInTitle?: bool, metaFields?: list<string>} $presentation
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
        $metadata = self::renderTicketDetailMeta($ticketData, $metaFields);
        $title = $showKeyInTitle
            ? $ticketData['reference'] . ': ' . $ticketData['title']
            : $ticketData['title'];
        $comments = self::renderComments($ticketData['comments']);
        $attachments = self::renderAttachments($ticketData['attachments']);
        $markup = '<article class="lutions-wp-ticket-detail"><p><a href="%s">%s</a></p>';
        $markup .= '<h1>%s</h1>%s';
        $markup .= '<div class="lutions-wp-ticket-description">%s</div>%s%s</article>';

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
