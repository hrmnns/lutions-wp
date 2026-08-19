<?php

declare(strict_types=1);

namespace LutionsWp;

final class AdminSettings
{
    public const OPTION_API_BASE_URL = 'lutions_wp_api_base_url';
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
    }

    public static function renderPage(): void
    {
        self::ensureAdminIncludes();

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage Lutions settings.', 'lutions-wp'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Lutions', 'lutions-wp') . '</h1>';
        self::renderAdminNotice();
        settings_errors('lutions_wp_messages');
        echo '<form method="post" action="options.php">';
        settings_fields('lutions_wp_settings');
        do_settings_sections('lutions-wp');
        submit_button(__('Save settings', 'lutions-wp'));
        echo '</form>';
        self::renderActionForms();
        echo '</div>';
    }

    public static function renderConnectionSection(): void
    {
        echo '<p>';
        echo esc_html__('Configure the public Lutions instance used by the shortcodes. Production URLs must use HTTPS.', 'lutions-wp');
        echo '</p>';
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

    public static function testConnection(): void
    {
        self::ensureAdminIncludes();
        self::assertCanManageOptions();
        check_admin_referer('lutions_wp_test_connection');

        $result = (new PublicTicketClient())->testConnection();
        self::storeAdminNotice($result['message'], $result['ok'] ? 'success' : 'error');

        self::redirectToSettings();
    }

    public static function clearCache(): void
    {
        self::ensureAdminIncludes();
        self::assertCanManageOptions();
        check_admin_referer('lutions_wp_clear_cache');

        update_option(self::OPTION_CACHE_VERSION, (string) time(), false);
        self::storeAdminNotice(__('Lutions public read cache was cleared.', 'lutions-wp'), 'success');

        self::redirectToSettings();
    }

    public static function configuredApiBaseUrl(): string
    {
        $value = get_option(self::OPTION_API_BASE_URL, '');

        return is_string($value) ? $value : '';
    }

    private static function renderActionForms(): void
    {
        echo '<hr />';
        echo '<h2>' . esc_html__('Tools', 'lutions-wp') . '</h2>';
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

    private static function assertCanManageOptions(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage Lutions settings.', 'lutions-wp'));
        }
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

    private static function redirectToSettings(): void
    {
        wp_safe_redirect(add_query_arg(
            [
                'page' => 'lutions-wp',
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
