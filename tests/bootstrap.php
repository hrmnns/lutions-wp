<?php

declare(strict_types=1);

// WordPress function stubs belong here when static analysis requires them.

if (!defined('LUTIONS_WP_FILE')) {
	define('LUTIONS_WP_FILE', __FILE__);
}

if (!defined('LUTIONS_WP_PATH')) {
	define('LUTIONS_WP_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

class WP_Error
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

/** @param array<string, string> $args */
function add_query_arg(array $args, string $url): string
{
	return $url . '?' . http_build_query($args);
}

function get_query_var(string $queryVar, mixed $defaultValue = ''): mixed
{
	return $defaultValue;
}

function get_header(): void
{
}

function get_footer(): void
{
}

function get_transient(string $key): mixed
{
	return false;
}

function set_transient(string $key, mixed $value, int $expiration): bool
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
