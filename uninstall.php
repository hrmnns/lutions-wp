<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('lutions_wp_api_base_url');
delete_option('lutions_wp_public_content_noindex');
delete_option('lutions_wp_project_feed_base');
delete_option('lutions_wp_cache_version');
delete_transient('lutions_wp_admin_notice');
