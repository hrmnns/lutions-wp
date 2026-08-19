# Lutions Public Portal for WordPress

This repository is the public reference implementation for the Lutions Public Read API. It provides a first shortcode for public tickets and native WordPress ticket-detail pages. Later releases can add Gutenberg blocks, release feeds, and explicitly defined portal statistics.

## Status

The repository consumes Public Read API v1.0 through server-side requests only. It does not contain credentials, customer data, or internal Lutions API examples.
With an API base URL ending in `/api/v1`, the plugin reads public ticket resources under `/public/projects/...`.

## Planned widgets

- `[lutions_public_tickets]` lists public tickets for one public Lutions project and links to native WordPress detail pages.
- `[lutions_release_feed]`
- `[lutions_portal_stats]` renders small public project stats: total public tickets, counts by status, and last public ticket update.

## Design principles

- Consume only documented Public Read API DTOs.
- Prefer server-side retrieval and caching.
- Never put privileged Lutions credentials in browser code.
- Never reproduce Lutions visibility, approval, or anti-abuse logic in WordPress.
- Treat the plugin as a reference for custom portal developers, not as the only supported frontend.

## Development

Requires PHP 8.1 or newer and a WordPress installation compatible with WordPress 6.4 or newer.

```bash
composer install
composer lint
composer analyse
```

## Docker smoke environment

The repository includes an isolated local WordPress/MariaDB stack. It does not
reuse the Lutions database and loads this repository directly as the
`lutions-wp` plugin.

```bash
docker compose up -d
```

Open `http://localhost:8088`, complete the local WordPress setup, and activate
**Lutions Public Portal** in the WordPress plugin screen. Configure the Lutions
instance under **Settings -> Lutions**, or copy `.env.example` to `.env` for the
Docker fallback. The local API URL is read server-side; it is not a shortcode
attribute and is never exposed to browser JavaScript. Detail links use query
parameters on the current WordPress page, so the ticket detail is rendered
inside the same theme layout as the ticket overview and does not depend on
server rewrite configuration.

Use `[lutions_portal_stats project="bug"]` to render the matching public project
stats block. The release feed shortcode is intentionally still a placeholder in
this MVP.

Server-side plugin requests reach the local Lutions backend at
`http://host.docker.internal:8000`. Production URLs must use HTTPS; plain HTTP
is accepted only for the documented local Docker hosts in a local/development
WordPress environment. The browser never receives Lutions credentials.

## Configuration

Go to **Settings -> Lutions** in the WordPress admin area to configure the
Public Read API base URL, test the connection, and clear the plugin cache.
The settings page also shows the effective API URL, the configuration source,
the configuration status, and the exact endpoint used by the connection test.
It also contains a compact **Embed Lutions content** help section with shortcode
examples, an **About** section with plugin/API version information, and a short
translation note for multilingual WordPress installations.

The plugin reads the URL in this order:

1. WordPress option saved on the Lutions settings page.
2. `LUTIONS_WP_API_BASE_URL` PHP constant.
3. `LUTIONS_WP_API_BASE_URL` environment variable.

Production URLs must use HTTPS. Plain HTTP is accepted only for localhost,
`127.0.0.1`, and `host.docker.internal` in local/development WordPress
environments.

## Embedding content

Add shortcodes to normal WordPress pages or posts:

```text
[lutions_public_tickets project="bug"]
[lutions_public_tickets project="bug" title="Public tickets" limit="10"]
[lutions_portal_stats project="bug"]
```

The `project` attribute is the public Lutions project slug. Ticket details are
opened on the same WordPress page by using query parameters, so the active theme
keeps rendering header, navigation, and footer.
The plugin builds these requests from the configured API base URL, for example
`/api/v1/public/projects/bug/tickets`.

Ticket descriptions and public comments use the API's explicit Markdown fields
when available. The plugin renders a deliberately small Markdown subset:
headings, bullet lists, paragraphs, emphasis, strong text, and inline code. The
generated HTML is sanitized through WordPress before output. If an older Lutions
instance does not provide Markdown fields, the plugin falls back to the
plain-text fields.

## Versioning and compatibility

The plugin version is declared in the WordPress plugin header and in the
`LUTIONS_WP_VERSION` constant. The currently targeted Lutions Public Read API is
declared through `LUTIONS_WP_PUBLIC_API_VERSION`.

The current public MVP read version is `0.1.1`. Future releases should continue
to use plain Semantic Versioning and matching Git tags or GitHub releases.

## Multilingual usage

The plugin is prepared for multilingual WordPress installations:

- Text domain: `lutions-wp`
- Domain path: `/languages`
- Source strings: English

For GitHub-only distribution, translation files can be maintained directly under
`languages/`, for example `lutions-wp-de_DE.po` and `lutions-wp-de_DE.mo`. If the
plugin later moves to WordPress.org, translations can also be handled through
the WordPress translation platform.

## WordPress plugin conventions

The plugin follows the WordPress Settings API for admin configuration and keeps
frontend rendering theme-friendly. Styles are namespaced under `lutions-wp-*`
and are loaded only when a Lutions shortcode is rendered.

The repository also contains a WordPress.org-style `readme.txt` and a
`.distignore` file so distribution packaging can exclude local development and
quality tooling files. This does not imply that the plugin is already published
in the official WordPress Plugin Directory.

The initial GitHub repository uses the MIT License. MIT is GPL-compatible, but
if the plugin is later submitted to WordPress.org, the project should explicitly
revisit whether to switch to the WordPress-recommended `GPL-2.0-or-later`
license.

Stop the smoke environment with `docker compose down`. The named MariaDB volume
is retained. Use `docker compose down -v` only when intentionally discarding
the local WordPress database.

## Compatibility

Plugin releases will follow Semantic Versioning and declare the compatible Lutions Public Read API version. The compatibility matrix is added with the first API-enabled release.

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE).

## Security and support

Please follow [SECURITY.md](SECURITY.md) for vulnerabilities, [SUPPORT.md](SUPPORT.md) for usage questions, and [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidance.
