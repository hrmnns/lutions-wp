<?php

declare(strict_types=1);

namespace LutionsWp;

final class AdminSettings
{
    public const OPTION_API_BASE_URL = 'lutions_wp_api_base_url';
    public const OPTION_DETAIL_PAGE_URL = 'lutions_wp_detail_page_url';
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
            __('Ticket detail page URL', 'lutions-wp'),
            [self::class, 'renderDetailPageUrlField'],
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

        printf(
            '<input type="url" class="regular-text code" name="%s" value="%s" placeholder="%s" />',
            esc_attr(self::OPTION_DETAIL_PAGE_URL),
            esc_attr($value),
            esc_attr(home_url('/lutions-wp/')),
        );
        echo '<p class="description">';
        echo esc_html__(
            'Optional. Ticket links from widgets or sidebars open on this WordPress page. Leave empty to open details on the current page.',
            'lutions-wp',
        );
        echo '</p>';
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
                'Lists public tickets for the configured public Lutions project. If a ticket detail page URL is configured, ticket clicks open there.',
                'lutions-wp',
            ),
        );
        self::renderHelpRow(
            __('Ticket detail target', 'lutions-wp'),
            '[lutions_public_tickets project="bug" detail_url="/bugs/"]',
            __(
                'detail_url takes priority over the Ticket detail page URL setting. Without either value, ticket details open on the current page.'
                . ' The target page must contain a ticket list shortcode for the same project'
                . ' and must render ticket details, not mode="list" or context="widget".',
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
            __('Ticket list metadata', 'lutions-wp'),
            '[lutions_public_tickets project="bug" title="" show_status="false" date_field="created"]',
            __(
                'Hides the heading and status suffix, and shows the selected date in the WordPress date format.'
                . ' date_field supports created, updated, closed, and none.'
                . ' show_date="true" remains supported as a shortcut for date_field="created".',
                'lutions-wp',
            ),
        );
        $extendedMetadataShortcode = '[lutions_public_tickets project="bug" show_priority="true"'
            . ' show_type="true" show_ticket_type="true" show_counts="true"]';
        self::renderHelpRow(
            __('Extended ticket metadata', 'lutions-wp'),
            $extendedMetadataShortcode,
            __(
                'Optionally shows public priority, issue type, public ticket type, and counts for public comments'
                . ' and public attachments. Private or quarantined child data is never counted.',
                'lutions-wp',
            ),
        );
        $widgetShortcode = '[lutions_public_tickets project="bug" detail_url="/lutions-wp/" mode="list"'
            . ' title="" show_status="false" date_field="updated" show_counts="true"]';
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
