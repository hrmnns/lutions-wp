<?php

declare(strict_types=1);

namespace LutionsWp;

final class AdminSettings
{
    public const OPTION_API_BASE_URL = 'lutions_wp_api_base_url';
    public const OPTION_DETAIL_PAGE_URL = 'lutions_wp_detail_page_url';
    public const OPTION_PORTAL_PAGE_URL = 'lutions_wp_portal_page_url';
    public const OPTION_PROJECT_DETAIL_PAGE_URLS = 'lutions_wp_project_detail_page_urls';
    public const OPTION_TICKET_NAVIGATION_ENABLED = 'lutions_wp_ticket_navigation_enabled';
    public const OPTION_CACHE_VERSION = 'lutions_wp_cache_version';
    private const NOTICE_TRANSIENT = 'lutions_wp_admin_notice';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_lutions_wp_test_connection', [self::class, 'testConnection']);
        add_action('admin_post_lutions_wp_clear_cache', [self::class, 'clearCache']);
    }

    public static function registerMenu(): void
    {
        add_options_page(
            __('Lutions', 'lutions-wp'),
            __('Lutions', 'lutions-wp'),
            'manage_options',
            'lutions-wp',
            [self::class, 'renderPage'],
        );
    }

    public static function registerSettings(): void
    {
        self::ensureAdminIncludes();

        register_setting('lutions_wp_settings', self::OPTION_API_BASE_URL, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeApiBaseUrl'],
            'default' => '',
        ]);
        register_setting('lutions_wp_settings', self::OPTION_DETAIL_PAGE_URL, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeDetailPageUrl'],
            'default' => '',
        ]);
        register_setting('lutions_wp_settings', self::OPTION_PORTAL_PAGE_URL, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizePortalPageUrl'],
            'default' => '',
        ]);
        register_setting('lutions_wp_settings', self::OPTION_PROJECT_DETAIL_PAGE_URLS, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeProjectDetailPageUrls'],
            'default' => [],
        ]);
        register_setting('lutions_wp_settings', self::OPTION_TICKET_NAVIGATION_ENABLED, [
            'type' => 'boolean',
            'sanitize_callback' => [self::class, 'sanitizeBoolean'],
            'default' => false,
        ]);

        add_settings_section(
            'lutions_wp_connection',
            __('Connection', 'lutions-wp'),
            [self::class, 'renderConnectionSection'],
            'lutions-wp',
        );

        add_settings_field(
            self::OPTION_API_BASE_URL,
            __('Lutions API base URL', 'lutions-wp'),
            [self::class, 'renderApiBaseUrlField'],
            'lutions-wp',
            'lutions_wp_connection',
        );
        add_settings_field(
            self::OPTION_DETAIL_PAGE_URL,
            __('Default ticket detail page', 'lutions-wp'),
            [self::class, 'renderDetailPageUrlField'],
            'lutions-wp',
            'lutions_wp_connection',
        );
        add_settings_field(
            self::OPTION_PORTAL_PAGE_URL,
            __('Public portal page', 'lutions-wp'),
            [self::class, 'renderPortalPageUrlField'],
            'lutions-wp',
            'lutions_wp_connection',
        );
        add_settings_field(
            self::OPTION_PROJECT_DETAIL_PAGE_URLS,
            __('Project detail page overrides', 'lutions-wp'),
            [self::class, 'renderProjectDetailPageUrlsField'],
            'lutions-wp',
            'lutions_wp_connection',
        );
        add_settings_field(
            self::OPTION_TICKET_NAVIGATION_ENABLED,
            __('Ticket navigation', 'lutions-wp'),
            [self::class, 'renderTicketNavigationField'],
            'lutions-wp',
            'lutions_wp_connection',
        );
    }

    public static function renderPage(): void
    {
        self::ensureAdminIncludes();

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage Lutions settings.', 'lutions-wp'));
        }

        $tab = self::requestedTab();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Lutions', 'lutions-wp') . '</h1>';
        self::renderAdminNotice();
        settings_errors('lutions_wp_messages');
        self::renderTabs($tab);

        match ($tab) {
            'connection' => self::renderConnectionSettings(),
            'tools' => self::renderActionForms(),
            'help' => self::renderShortcodeHelp(),
            'about' => self::renderAbout(),
        };

        echo '</div>';
    }

    public static function renderConnectionSection(): void
    {
        echo '<p>';
        echo esc_html__('Configure the public Lutions instance used by the shortcodes. Production URLs must use HTTPS.', 'lutions-wp');
        echo '</p>';
        self::renderConnectionDiagnostics();
    }

    /** @return 'connection'|'tools'|'help'|'about' */
    private static function requestedTab(): string
    {
        $tab = isset($_GET['tab']) && is_scalar($_GET['tab'])
            ? sanitize_key((string) $_GET['tab'])
            : 'connection';

        return in_array($tab, ['connection', 'tools', 'help', 'about'], true)
            ? $tab
            : 'connection';
    }

    /** @param 'connection'|'tools'|'help'|'about' $activeTab */
    private static function renderTabs(string $activeTab): void
    {
        $tabs = [
            'connection' => __('Connection', 'lutions-wp'),
            'tools' => __('Tools', 'lutions-wp'),
            'help' => __('Help', 'lutions-wp'),
            'about' => __('About', 'lutions-wp'),
        ];

        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr(__('Lutions settings sections', 'lutions-wp')) . '">';
        foreach ($tabs as $tab => $label) {
            $url = add_query_arg(
                [
                    'page' => 'lutions-wp',
                    'tab' => $tab,
                ],
                admin_url('options-general.php'),
            );
            $class = $tab === $activeTab ? ' nav-tab-active' : '';

            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($url),
                esc_attr($class),
                esc_html($label),
            );
        }
        echo '</nav>';
    }

    private static function renderConnectionSettings(): void
    {
        echo '<form method="post" action="options.php">';
        settings_fields('lutions_wp_settings');
        do_settings_sections('lutions-wp');
        submit_button(__('Save settings', 'lutions-wp'));
        echo '</form>';
    }

    public static function renderApiBaseUrlField(): void
    {
        $value = self::configuredApiBaseUrl();

        printf(
            '<input type="url" class="regular-text code" name="%s" value="%s" placeholder="https://example.com/api/v1" />',
            esc_attr(self::OPTION_API_BASE_URL),
            esc_attr($value),
        );
        echo '<p class="description">';
        echo esc_html__('Example: https://your-lutions-instance.example/api/v1.', 'lutions-wp') . ' ';
        echo esc_html__('Local HTTP is allowed only for localhost, 127.0.0.1, and host.docker.internal in local/development environments.', 'lutions-wp');
        echo '</p>';
    }

    public static function renderDetailPageUrlField(): void
    {
        $value = self::configuredDetailPageUrl();
        self::renderPageUrlSelect(self::OPTION_DETAIL_PAGE_URL, $value);
        echo '<p class="description">';
        echo esc_html__(
            'Optional default for ticket details and global search results. Leave empty to keep ticket-list links on the current page.',
            'lutions-wp',
        );
        echo '</p>';
    }

    public static function renderPortalPageUrlField(): void
    {
        self::renderPageUrlSelect(self::OPTION_PORTAL_PAGE_URL, self::configuredPortalPageUrl());
        echo '<p class="description">';
        echo esc_html__('Select the WordPress page that contains lutions_public_portal. Category and project search results open on this page.', 'lutions-wp');
        echo '</p>';
    }

    public static function renderProjectDetailPageUrlsField(): void
    {
        $routes = self::configuredProjectDetailPageUrls();
        $projectOptions = self::projectKeyOptions($routes);
        echo '<style>';
        echo '#lutions-wp-project-detail-routes th,#lutions-wp-project-detail-routes td{padding:12px 14px;}';
        echo '#lutions-wp-project-detail-routes th:first-child{width:32%;}';
        echo '#lutions-wp-project-detail-routes th:last-child,#lutions-wp-project-detail-routes td:last-child{text-align:right;width:1%;white-space:nowrap;}';
        echo '#lutions-wp-project-detail-routes .regular-text{max-width:100%;width:100%;}';
        echo '</style>';
        echo '<table class="widefat striped" id="lutions-wp-project-detail-routes"><thead><tr>';
        echo '<th>' . esc_html__('Project key', 'lutions-wp') . '</th>';
        echo '<th>' . esc_html__('Ticket detail page', 'lutions-wp') . '</th><th></th></tr></thead><tbody>';
        $index = 0;
        foreach ($routes as $projectKey => $url) {
            self::renderProjectDetailPageUrlRow((string) $index, $projectKey, $url, $projectOptions);
            $index++;
        }
        echo '</tbody></table>';
        printf(
            '<p><button type="button" class="button" id="lutions-wp-add-project-detail-route">%s</button></p>',
            esc_html__('Add project override', 'lutions-wp'),
        );
        echo '<p class="description">';
        echo esc_html__('Optional. An override takes precedence over the default page for this project in lists and global search results.', 'lutions-wp');
        echo '</p>';
        $rowMarkup = '<td>' . self::projectKeySelectMarkup(
            self::OPTION_PROJECT_DETAIL_PAGE_URLS . '[__INDEX__][project_key]',
            '',
            $projectOptions,
        ) . '</td>';
        $rowMarkup .= '<td>' . self::pageUrlSelectMarkup(
            self::OPTION_PROJECT_DETAIL_PAGE_URLS . '[__INDEX__][url]',
            '',
        ) . '</td>';
        $rowMarkup .= '<td><button type="button" class="button-link-delete">'
            . esc_html__('Remove', 'lutions-wp') . '</button></td>';
        $script = '(function(){const table=document.getElementById("lutions-wp-project-detail-routes");';
        $script .= 'const button=document.getElementById("lutions-wp-add-project-detail-route");';
        $script .= 'if(!table||!button){return;}const add=function(){const row=document.createElement("tr");';
        $script .= 'row.innerHTML=' . wp_json_encode($rowMarkup) . '.replaceAll("__INDEX__","new_"+Date.now());';
        $script .= 'table.tBodies[0].appendChild(row);};';
        $script .= 'button.addEventListener("click",add);table.addEventListener("click",function(event){';
        $script .= 'if(event.target instanceof HTMLButtonElement&&event.target.classList.contains("button-link-delete")){';
        $script .= 'event.target.closest("tr").remove();}});}());';
        echo '<script>' . $script . '</script>';
    }

    public static function renderTicketNavigationField(): void
    {
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
            esc_attr(self::OPTION_TICKET_NAVIGATION_ENABLED),
            checked(self::ticketNavigationEnabled(), true, false),
            esc_html__('Show newer and older public tickets on ticket detail pages.', 'lutions-wp'),
        );
    }

    public static function sanitizeBoolean(mixed $value): bool
    {
        return $value === '1' || $value === 1 || $value === true || $value === 'true';
    }

    public static function sanitizeApiBaseUrl(mixed $value): string
    {
        self::ensureAdminIncludes();

        $rawValue = is_scalar($value) ? trim((string) $value) : '';
        if ($rawValue === '') {
            return '';
        }

        $normalized = PublicTicketClient::normalizeApiBaseUrl($rawValue);
        if ($normalized === null) {
            add_settings_error(
                'lutions_wp_messages',
                'lutions_wp_invalid_api_url',
                __('The Lutions API base URL must be HTTPS, except for documented local development hosts.', 'lutions-wp'),
                'error',
            );

            return self::configuredApiBaseUrl();
        }

        return $normalized;
    }

    public static function sanitizeDetailPageUrl(mixed $value): string
    {
        self::ensureAdminIncludes();

        $rawValue = is_scalar($value) ? trim((string) $value) : '';
        if ($rawValue === '') {
            return '';
        }

        $normalized = self::normalizeLocalPageUrl($rawValue);
        if ($normalized === null) {
            add_settings_error(
                'lutions_wp_messages',
                'lutions_wp_invalid_detail_url',
                __('The ticket detail page URL must point to this WordPress site.', 'lutions-wp'),
                'error',
            );

            return self::configuredDetailPageUrl();
        }

        return $normalized;
    }

    public static function sanitizePortalPageUrl(mixed $value): string
    {
        $rawValue = is_scalar($value) ? trim((string) $value) : '';
        if ($rawValue === '') {
            return '';
        }

        $normalized = self::normalizeLocalPageUrl($rawValue);
        if ($normalized === null) {
            add_settings_error(
                'lutions_wp_messages',
                'lutions_wp_invalid_portal_url',
                __('The public portal page URL must point to this WordPress site.', 'lutions-wp'),
                'error',
            );

            return self::configuredPortalPageUrl();
        }

        return $normalized;
    }

    /** @return array<string, string> */
    public static function sanitizeProjectDetailPageUrls(mixed $value): array
    {
        self::ensureAdminIncludes();
        if (! is_array($value)) {
            return [];
        }

        $routes = [];
        $pendingProjectKey = null;
        foreach ($value as $storedProjectKey => $row) {
            if (is_string($storedProjectKey) && is_string($row)) {
                $projectKey = sanitize_key($storedProjectKey);
                $url = self::normalizeLocalPageUrl($row);
                if ($projectKey !== '' && is_string($url) && $url !== '') {
                    $routes[$projectKey] = $url;
                }
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $projectKey = isset($row['project_key']) && is_scalar($row['project_key'])
                ? sanitize_key((string) $row['project_key'])
                : '';
            $url = isset($row['url']) && is_scalar($row['url'])
                ? self::normalizeLocalPageUrl((string) $row['url'])
                : null;
            if ($projectKey === '' && ($url === null || $url === '')) {
                continue;
            }
            if ($projectKey !== '' && ($url === null || $url === '')) {
                $pendingProjectKey = $projectKey;
                continue;
            }
            if ($projectKey === '' && $pendingProjectKey !== null) {
                $routes[$pendingProjectKey] = $url;
                $pendingProjectKey = null;
                continue;
            }
            if ($projectKey === '') {
                add_settings_error(
                    'lutions_wp_messages',
                    'lutions_wp_invalid_project_detail_route',
                    __('Each project detail page override needs both a project key and a local page URL.', 'lutions-wp'),
                    'error',
                );
                continue;
            }
            $routes[$projectKey] = $url;
            $pendingProjectKey = null;
        }

        if ($pendingProjectKey !== null) {
            add_settings_error(
                'lutions_wp_messages',
                'lutions_wp_invalid_project_detail_route',
                __('Each project detail page override needs both a project key and a local page URL.', 'lutions-wp'),
                'error',
            );
        }

        return $routes;
    }

    public static function testConnection(): void
    {
        self::ensureAdminIncludes();
        self::assertCanManageOptions();
        check_admin_referer('lutions_wp_test_connection');

        $result = (new PublicTicketClient())->testConnection();
        self::storeAdminNotice($result['message'], $result['ok'] ? 'success' : 'error');

        self::redirectToSettings('tools');
    }

    public static function clearCache(): void
    {
        self::ensureAdminIncludes();
        self::assertCanManageOptions();
        check_admin_referer('lutions_wp_clear_cache');

        update_option(self::OPTION_CACHE_VERSION, (string) time(), false);
        self::storeAdminNotice(__('Lutions public read cache was cleared.', 'lutions-wp'), 'success');

        self::redirectToSettings('tools');
    }

    public static function configuredApiBaseUrl(): string
    {
        $value = get_option(self::OPTION_API_BASE_URL, '');

        return is_string($value) ? $value : '';
    }

    public static function configuredDetailPageUrl(): string
    {
        $value = get_option(self::OPTION_DETAIL_PAGE_URL, '');

        return is_string($value) ? $value : '';
    }

    public static function ticketNavigationEnabled(): bool
    {
        return (bool) get_option(self::OPTION_TICKET_NAVIGATION_ENABLED, false);
    }

    public static function configuredPortalPageUrl(): string
    {
        $value = get_option(self::OPTION_PORTAL_PAGE_URL, '');

        return is_string($value) ? self::normalizeLocalPageUrl($value) ?? '' : '';
    }

    /** @return array<string, string> */
    public static function configuredProjectDetailPageUrls(): array
    {
        $value = get_option(self::OPTION_PROJECT_DETAIL_PAGE_URLS, []);
        if (! is_array($value)) {
            return [];
        }

        $routes = [];
        foreach ($value as $projectKey => $url) {
            if (! is_string($projectKey) || ! is_string($url)) {
                continue;
            }
            $normalizedKey = sanitize_key($projectKey);
            $normalizedUrl = self::normalizeLocalPageUrl($url);
            if ($normalizedKey !== '' && is_string($normalizedUrl) && $normalizedUrl !== '') {
                $routes[$normalizedKey] = $normalizedUrl;
            }
        }

        return $routes;
    }

    public static function normalizeLocalPageUrl(string $configured): ?string
    {
        $url = trim($configured);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return home_url($url);
        }

        $parts = wp_parse_url($url);
        $homeParts = wp_parse_url(home_url('/'));
        if (
            ! is_array($parts) || ! is_array($homeParts)
            || ! isset($parts['scheme'], $parts['host'], $homeParts['host'])
        ) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (strtolower((string) $parts['host']) !== strtolower((string) $homeParts['host'])) {
            return null;
        }

        $configuredPort = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;
        $homePort = isset($homeParts['port']) && is_int($homeParts['port']) ? $homeParts['port'] : null;
        if ($configuredPort !== $homePort) {
            return null;
        }

        return rtrim($url, '?&');
    }

    /**
     * @param list<array{key: string, name: string}> $projectOptions
     */
    private static function renderProjectDetailPageUrlRow(
        string $index,
        string $projectKey,
        string $url,
        array $projectOptions,
    ): void {
        printf(
            '<tr><td>%s</td><td>%s</td>'
            . '<td><button type="button" class="button-link-delete">%s</button></td></tr>',
            self::projectKeySelectMarkup(
                self::OPTION_PROJECT_DETAIL_PAGE_URLS . '[' . $index . '][project_key]',
                $projectKey,
                $projectOptions,
            ),
            self::pageUrlSelectMarkup(self::OPTION_PROJECT_DETAIL_PAGE_URLS . '[' . $index . '][url]', $url),
            esc_html__('Remove', 'lutions-wp'),
        );
    }

    /**
     * @param array<string, string> $routes
     * @return list<array{key: string, name: string}>
     */
    private static function projectKeyOptions(array $routes): array
    {
        $result = (new PublicTicketClient())->getPublicProjectOptions();
        $options = $result['projects'];
        $knownKeys = array_map(
            static fn (string $projectKey): string => sanitize_key($projectKey),
            array_column($options, 'key'),
        );
        foreach (array_keys($routes) as $projectKey) {
            if (! in_array(sanitize_key($projectKey), $knownKeys, true)) {
                $options[] = ['key' => $projectKey, 'name' => $projectKey];
            }
        }

        return $options;
    }

    /**
     * @param list<array{key: string, name: string}> $projectOptions
     */
    private static function projectKeySelectMarkup(string $fieldName, string $selectedKey, array $projectOptions): string
    {
        $options = '<option value="">' . esc_html__('Select a public project', 'lutions-wp') . '</option>';
        foreach ($projectOptions as $project) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($project['key']),
                selected(sanitize_key($project['key']), sanitize_key($selectedKey), false),
                esc_html($project['key'] . ' — ' . $project['name']),
            );
        }

        return sprintf('<select class="regular-text" name="%s">%s</select>', esc_attr($fieldName), $options);
    }

    private static function renderPageUrlSelect(string $fieldName, string $selectedUrl): void
    {
        echo self::pageUrlSelectMarkup($fieldName, $selectedUrl);
    }

    private static function pageUrlSelectMarkup(string $fieldName, string $selectedUrl): string
    {
        $options = '<option value="">' . esc_html__('Select a page', 'lutions-wp') . '</option>';
        foreach (get_pages(['post_status' => 'publish', 'sort_column' => 'post_title']) as $page) {
            $url = get_permalink($page->ID);
            if (! is_string($url) || $url === '') {
                continue;
            }
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($url),
                selected($url, $selectedUrl, false),
                esc_html($page->post_title),
            );
        }

        return sprintf(
            '<select class="regular-text" name="%s">%s</select>',
            esc_attr($fieldName),
            $options,
        );
    }

    private static function renderActionForms(): void
    {
        $diagnostics = PublicTicketClient::apiBaseUrlDiagnostics();
        $testUrl = $diagnostics['valid'] ? $diagnostics['url'] . '/public' : '';

        echo '<h2>' . esc_html__('Tools', 'lutions-wp') . '</h2>';
        if ($testUrl !== '') {
            printf(
                '<p class="description">%s <code>%s</code></p>',
                esc_html__('Connection test target:', 'lutions-wp'),
                esc_html($testUrl),
            );
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
        echo '<input type="hidden" name="action" value="lutions_wp_test_connection" />';
        wp_nonce_field('lutions_wp_test_connection');
        submit_button(__('Test connection', 'lutions-wp'), 'secondary', 'submit', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
        echo '<input type="hidden" name="action" value="lutions_wp_clear_cache" />';
        wp_nonce_field('lutions_wp_clear_cache');
        submit_button(__('Clear cache', 'lutions-wp'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private static function renderAbout(): void
    {
        self::renderAboutPanel();
        self::renderLocalizationHelp();
    }

    private static function renderShortcodeHelp(): void
    {
        echo '<h2>' . esc_html__('Embed Lutions content', 'lutions-wp') . '</h2>';
        echo '<p>';
        $intro = __('Add one of these shortcodes to a WordPress page or post.', 'lutions-wp');
        $intro .= ' ' . __('Configure the API URL only here in the plugin settings, not in shortcode attributes.', 'lutions-wp');
        echo esc_html($intro);
        echo '</p>';
        echo '<table class="widefat striped" role="presentation"><tbody>';
        self::renderHelpRow(
            __('Public ticket list', 'lutions-wp'),
            '[lutions_public_tickets project="bug"]',
            __(
                'Lists public tickets for the configured public Lutions project. Ticket links use detail_url, a project override, or the default detail page.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Ticket detail target', 'lutions-wp'),
            '[lutions_public_tickets project="bug" detail_url="/bugs/"]',
            __(
                'detail_url takes priority over a project override and the default detail page. Without either value, ticket details open on the current page.'
                . ' The target page must contain a ticket list shortcode for the same project'
                . ' and must render ticket details, not mode="list" or context="widget".',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Shared ticket detail page', 'lutions-wp'),
            '[lutions_public_ticket_detail meta_in_detail="key,status,priority,updated"]',
            __(
                'Renders the public ticket selected by the URL, regardless of its project. Use it on the default detail page shared by multiple projects.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Ticket detail page routing', 'lutions-wp'),
            __('Settings → Lutions → Default ticket detail page', 'lutions-wp'),
            __(
                'Select the shared WordPress page that contains lutions_public_ticket_detail.'
                . ' It is used by global search results and by ticket links without a more specific target.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Project detail page override', 'lutions-wp'),
            __('Settings → Lutions → Project detail page overrides', 'lutions-wp'),
            __(
                'Select a public Lutions project and its dedicated WordPress detail page.'
                . ' An override takes precedence over the default page; multiple projects can use the shared default page.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Ticket list with title and limit', 'lutions-wp'),
            '[lutions_public_tickets project="bug" title="Public tickets" limit="10"]',
            __('Limits the list to 1-50 tickets and shows a custom heading. Use title="" to hide the heading.', 'lutions-wp'),
        );
        self::renderHelpRow(
            __('Ticket list order', 'lutions-wp'),
            '[lutions_public_tickets project="bug" sort_by="created" sort_order="desc"]',
            __(
                'Lists newly created tickets first by default. Use sort_by="updated" or sort_order="asc" to choose a different public timestamp order.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Ticket metadata', 'lutions-wp'),
            '[lutions_public_tickets project="bug" show_key_in_title="true"'
            . ' meta_in_list="key,priority,created" meta_in_detail="key,status,priority,updated"]',
            __(
                'Lists show metadata behind the title; details show it in a separate row.'
                . ' meta_in_list and meta_in_detail independently select key, created, updated, priority, and status in the displayed order.'
                . ' show_key_in_title independently controls the ticket key before the title.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Ticket metadata without a row', 'lutions-wp'),
            '[lutions_public_tickets project="bug" show_key_in_title="false" meta_in_list="none" meta_in_detail="none"]',
            __(
                'Hides the ticket key in titles and omits the metadata row on lists and details.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Complete public portal', 'lutions-wp'),
            '[lutions_public_portal]',
            __(
                'Shows public categories and projects on one WordPress page.'
                . ' Select this page as Public portal page under Settings → Lutions'
                . ' so category and project search results open inside WordPress.'
                . ' Use title="" to hide its heading or category="support" to show one category.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('WordPress search', 'lutions-wp'),
            '/?s=BUG-20',
            __(
                'The normal WordPress search adds a separate Lutions result section for public categories, projects, and tickets.'
                . ' Category and project links use the configured Public portal page; ticket links use a project override or the default detail page.'
                . ' Categories and projects match name or key,'
                . ' tickets match ticket key or title.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Inline public images', 'lutions-wp'),
            '![Screenshot](https://lutions.example/api/v1/public/attachments/.../download)',
            __(
                'Ticket details render public attachment images from Lutions Markdown fields inline.'
                . ' Private, metadata-only, or quarantined attachments are not exposed by the Public Read API.',
                'lutions-wp',
            ),
        );
        $widgetShortcode = '[lutions_public_tickets project="bug" detail_url="/lutions-wp/" mode="list"'
            . ' title="" show_key_in_title="false" meta_in_list="updated" meta_in_detail="none"]';
        self::renderHelpRow(
            __('Widget ticket list with detail target', 'lutions-wp'),
            $widgetShortcode,
            __(
                'Keeps the widget as a list and opens ticket details on the configured portal page.'
                . ' Normal WordPress widgets are detected automatically; mode="list" or context="widget"'
                . ' can be used as a fallback for custom builders.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Public project stats', 'lutions-wp'),
            '[lutions_portal_stats project="bug"]',
            __('Shows the total number of public tickets, counts by status, and the last public update.', 'lutions-wp'),
        );
        echo '</tbody></table>';
    }

    private static function renderAboutPanel(): void
    {
        echo '<h2>' . esc_html__('About Lutions Public Portal', 'lutions-wp') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::renderInfoRow(__('Plugin version', 'lutions-wp'), LUTIONS_WP_VERSION);
        self::renderInfoRow(__('Compatible Public Read API', 'lutions-wp'), 'v' . LUTIONS_WP_PUBLIC_API_VERSION);
        self::renderInfoRow(__('Mode', 'lutions-wp'), __('Read-only public portal MVP', 'lutions-wp'));
        self::renderInfoRow(__('API tokens', 'lutions-wp'), __('Not required for public read widgets.', 'lutions-wp'));
        echo '<tr><th scope="row">' . esc_html__('Project links', 'lutions-wp') . '</th><td>';
        printf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url('https://github.com/hrmnns/lutions-wp'),
            esc_html__('GitHub repository', 'lutions-wp'),
        );
        echo ' &middot; ';
        printf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url('https://github.com/hrmnns/lutions-wp/blob/main/SECURITY.md'),
            esc_html__('Security policy', 'lutions-wp'),
        );
        echo ' &middot; ';
        printf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url('https://github.com/hrmnns/lutions-wp/blob/main/SUPPORT.md'),
            esc_html__('Support', 'lutions-wp'),
        );
        echo '</td></tr>';
        echo '</tbody></table>';
    }

    private static function renderLocalizationHelp(): void
    {
        echo '<h2>' . esc_html__('Languages and translations', 'lutions-wp') . '</h2>';
        echo '<p>';
        $intro = __('The plugin is prepared for multilingual WordPress installations.', 'lutions-wp');
        $intro .= ' ' . __('It uses the text domain lutions-wp and the languages directory.', 'lutions-wp');
        echo esc_html($intro);
        echo '</p>';
        echo '<p class="description">';
        $description = __('Code strings use English source text.', 'lutions-wp');
        $description .= ' ' . __('Translation files can be added under languages, for example lutions-wp-de_DE.po and lutions-wp-de_DE.mo.', 'lutions-wp');
        echo esc_html($description);
        echo '</p>';
    }

    private static function renderHelpRow(string $label, string $shortcode, string $description): void
    {
        printf(
            '<tr><td><strong>%s</strong></td><td><code>%s</code><p class="description">%s</p></td></tr>',
            esc_html($label),
            esc_html($shortcode),
            esc_html($description),
        );
    }

    private static function renderInfoRow(string $label, string $value): void
    {
        printf(
            '<tr><th scope="row">%s</th><td>%s</td></tr>',
            esc_html($label),
            esc_html($value),
        );
    }

    private static function assertCanManageOptions(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage Lutions settings.', 'lutions-wp'));
        }
    }

    private static function renderConnectionDiagnostics(): void
    {
        $diagnostics = PublicTicketClient::apiBaseUrlDiagnostics();
        $sourceLabels = [
            'wordpress-option' => __('WordPress option', 'lutions-wp'),
            'php-constant' => __('PHP constant', 'lutions-wp'),
            'environment' => __('Environment variable', 'lutions-wp'),
            'not-configured' => __('Not configured', 'lutions-wp'),
        ];
        $sourceLabel = $sourceLabels[$diagnostics['source']] ?? $diagnostics['source'];
        $effectiveUrl = $diagnostics['url'] !== '' ? $diagnostics['url'] : __('not available', 'lutions-wp');
        $validLabel = $diagnostics['valid'] ? __('valid', 'lutions-wp') : __('not usable', 'lutions-wp');

        echo '<table class="form-table" role="presentation"><tbody>';
        printf(
            '<tr><th scope="row">%s</th><td><code>%s</code></td></tr>',
            esc_html__('Effective API URL', 'lutions-wp'),
            esc_html($effectiveUrl),
        );
        printf(
            '<tr><th scope="row">%s</th><td>%s</td></tr>',
            esc_html__('Configuration source', 'lutions-wp'),
            esc_html($sourceLabel),
        );
        printf(
            '<tr><th scope="row">%s</th><td>%s</td></tr>',
            esc_html__('Configuration status', 'lutions-wp'),
            esc_html($validLabel),
        );
        echo '</tbody></table>';
    }

    private static function storeAdminNotice(string $message, string $type): void
    {
        set_transient(self::NOTICE_TRANSIENT, [
            'message' => $message,
            'type' => $type === 'success' ? 'success' : 'error',
        ], 30);
    }

    private static function renderAdminNotice(): void
    {
        $notice = get_transient(self::NOTICE_TRANSIENT);
        if (! is_array($notice)) {
            return;
        }

        delete_transient(self::NOTICE_TRANSIENT);
        $message = is_string($notice['message'] ?? null) ? $notice['message'] : '';
        $type = ($notice['type'] ?? '') === 'success' ? 'success' : 'error';
        if ($message === '') {
            return;
        }

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message),
        );
    }

    /** @param 'connection'|'tools'|'help'|'about' $tab */
    private static function redirectToSettings(string $tab = 'connection'): void
    {
        wp_safe_redirect(add_query_arg(
            [
                'page' => 'lutions-wp',
                'tab' => $tab,
            ],
            admin_url('options-general.php'),
        ));
        exit;
    }

    private static function ensureAdminIncludes(): void
    {
        if (
            defined('ABSPATH')
            && ! function_exists('settings_errors')
            && file_exists(ABSPATH . 'wp-admin/includes/template.php')
        ) {
            require_once ABSPATH . 'wp-admin/includes/template.php';
        }
    }
}
