<?php

/**
 * Plugin Name: Lutions Public Portal
 * Plugin URI: https://github.com/hrmnns/lutions-wp
 * Description: Reference WordPress integration for the Lutions Public Read API.
 * Version: 0.3.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Lutions
 * Text Domain: lutions-wp
 * Domain Path: /languages
 * License: MIT
 * License URI: https://github.com/hrmnns/lutions-wp/blob/main/LICENSE
 *
 * @package LutionsWp
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('LUTIONS_WP_VERSION', '0.3.0');
define('LUTIONS_WP_PUBLIC_API_VERSION', '1.0');
define('LUTIONS_WP_FILE', __FILE__);
define('LUTIONS_WP_PATH', plugin_dir_path(__FILE__));
define('LUTIONS_WP_URL', plugin_dir_url(__FILE__));

require_once LUTIONS_WP_PATH . 'src/Plugin.php';
require_once LUTIONS_WP_PATH . 'src/AdminSettings.php';
require_once LUTIONS_WP_PATH . 'src/MarkdownRenderer.php';
require_once LUTIONS_WP_PATH . 'src/PublicTicketClient.php';

register_activation_hook(LUTIONS_WP_FILE, [\LutionsWp\Plugin::class, 'activate']);
register_deactivation_hook(LUTIONS_WP_FILE, [\LutionsWp\Plugin::class, 'deactivate']);

\LutionsWp\Plugin::boot();
