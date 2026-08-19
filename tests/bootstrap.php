<?php

declare(strict_types=1);

// WordPress function stubs belong here when static analysis requires them.

if (!defined('LUTIONS_WP_FILE')) {
	define('LUTIONS_WP_FILE', __FILE__);
}

if (!defined('LUTIONS_WP_PATH')) {
	define('LUTIONS_WP_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('LUTIONS_WP_URL')) {
	define('LUTIONS_WP_URL', 'https://example.test/wp-content/plugins/lutions-wp/');
}

if (!defined('LUTIONS_WP_VERSION')) {
	define('LUTIONS_WP_VERSION', '0.1.0');
}

if (!defined('LUTIONS_WP_PUBLIC_API_VERSION')) {
	define('LUTIONS_WP_PUBLIC_API_VERSION', '1.0');
}

class WP_Error
{
}

class WP_Post
{
}

function add_action(string $hook, callable $callback): bool
{
	return true;
}

function add_filter(string $hook, callable $callback): bool
{
	return true;
}

function add_shortcode(string $tag, callable $callback): bool
{
	return true;
}

function add_options_page(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback): string|false
{
	return 'settings_page_' . $menuSlug;
}

/** @param array<string, mixed> $arguments */
function register_setting(string $optionGroup, string $optionName, array $arguments = []): bool
{
	return true;
}

function add_settings_section(string $id, string $title, callable $callback, string $page): bool
{
	return true;
}

function add_settings_field(string $id, string $title, callable $callback, string $page, string $section = 'default'): bool
{
	return true;
}

function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): bool
{
	return true;
}

function register_activation_hook(string $file, callable $callback): void
{
}

function register_deactivation_hook(string $file, callable|string $callback): void
{
}

function flush_rewrite_rules(bool $hard = true): bool
{
	return true;
}

function load_plugin_textdomain(string $domain, bool $deprecated = false, string $pluginRelPath = ''): bool
{
	return true;
}

function plugin_basename(string $file): string
{
	return basename($file);
}

function plugin_dir_path(string $file): string
{
	return dirname($file) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url(string $file): string
{
	return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function esc_html__(string $text, string $domain): string
{
	return $text;
}

function __(string $text, string $domain): string
{
	return $text;
}

function sanitize_key(string $key): string
{
	return strtolower($key);
}

function sanitize_text_field(string $text): string
{
	return $text;
}

function esc_url(string $url): string
{
	return $url;
}

function esc_html(string $text): string
{
	return $text;
}

function wp_kses_post(string $data): string
{
	return $data;
}

function esc_attr(string $text): string
{
	return $text;
}

function home_url(string $path = ''): string
{
	return 'https://example.test' . $path;
}

function get_permalink(int|\WP_Post|null $post = null): string|false
{
	return 'https://example.test/lutions-wp/';
}

function get_option(string $option, mixed $defaultValue = false): mixed
{
	return $defaultValue;
}

function update_option(string $option, mixed $value, bool|string|null $autoload = null): bool
{
	return true;
}

function delete_option(string $option): bool
{
	return true;
}

/** @param array<string, string> $args */
function add_query_arg(array $args, string $url): string
{
	return $url . '?' . http_build_query($args);
}

function get_query_var(string $queryVar, mixed $defaultValue = ''): mixed
{
	return $defaultValue;
}

function current_user_can(string $capability): bool
{
	return true;
}

function wp_die(string $message = ''): never
{
	exit($message);
}

function settings_errors(string $setting = '', bool $sanitize = false, bool $hideOnUpdate = false): void
{
}

function settings_fields(string $optionGroup): void
{
}

function do_settings_sections(string $page): void
{
}

function submit_button(string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true): void
{
}

/**
 * @param list<string> $dependencies
 */
function wp_enqueue_style(string $handle, string $source = '', array $dependencies = [], string|bool|null $version = false, string $media = 'all'): void
{
}

function admin_url(string $path = ''): string
{
	return 'https://example.test/wp-admin/' . $path;
}

/**
 * @param array<string, string> $args
 */
function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
{
	return '';
}

function check_admin_referer(string $action = '-1', string $queryArg = '_wpnonce'): int|false
{
	return 1;
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
}

/** @return list<array<string, string>> */
function get_settings_errors(string $setting = '', bool $sanitize = false): array
{
	return [];
}

function wp_safe_redirect(string $location, int $status = 302, string $xRedirectBy = 'WordPress'): bool
{
	return true;
}

function get_transient(string $key): mixed
{
	return false;
}

function set_transient(string $key, mixed $value, int $expiration): bool
{
	return true;
}

function delete_transient(string $key): bool
{
	return true;
}

function is_wp_error(mixed $thing): bool
{
	return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(array $response): int
{
	return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body(array $response): string
{
	return (string) ($response['body'] ?? '');
}

function wp_parse_url(string $url, int $component = -1): array|string|int|null|false
{
	return parse_url($url, $component);
}

function wp_get_environment_type(): string
{
	return 'local';
}

/** @return array<string, mixed>|WP_Error */
function wp_remote_get(string $url, array $arguments = []): array|WP_Error
{
	return new WP_Error();
}

/** @return array<string, mixed>|WP_Error */
function wp_safe_remote_get(string $url, array $arguments = []): array|WP_Error
{
	return new WP_Error();
}
