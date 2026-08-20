# Lutions Public Portal for WordPress

![Latest release](https://img.shields.io/github/v/release/hrmnns/lutions-wp?label=release)
![Release ZIP](https://github.com/hrmnns/lutions-wp/actions/workflows/release.yml/badge.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php)
![Lutions API](https://img.shields.io/badge/Lutions%20Public%20Read%20API-v1.0-blue)
![License](https://img.shields.io/github/license/hrmnns/lutions-wp)

**Lutions Public Portal** is a WordPress plugin for showing public Lutions
tickets on a WordPress site.

It is intentionally easy to install: download the WordPress ZIP from the latest
GitHub release, upload it in WordPress, configure your Lutions API URL, and add
a shortcode to a page, post, or widget.

## Current version

- Plugin version: **0.1.6**
- Lutions Public Read API: **v1.0**
- Latest release: <https://github.com/hrmnns/lutions-wp/releases/latest>
- WordPress ZIP: download `lutions-wp-<version>-wordpress.zip` from the latest
  release assets.

## What the plugin does

- Lists public tickets from a public Lutions project.
- Opens ticket details inside the active WordPress theme layout.
- Works in pages, posts, sidebars, and widgets.
- Provides a small public project statistics shortcode.
- Stores the Lutions API base URL in WordPress admin settings.
- Performs server-side API requests and cache handling.
- Does **not** need a Lutions API token for the current read-only MVP.

The plugin never decides which tickets are public. Visibility remains enforced
by the Lutions Public Read API.

## Installation

1. Open the latest release:
   <https://github.com/hrmnns/lutions-wp/releases/latest>
2. Download `lutions-wp-<version>-wordpress.zip`.
3. In WordPress, go to **Plugins -> Installieren -> Plugin hochladen**.
4. Upload the ZIP file.
5. Activate **Lutions Public Portal**.
6. Go to **Settings -> Lutions**.
7. Enter your Lutions Public Read API base URL, for example:

   ```text
   https://example.lutions.test/api/v1
   ```

8. Use **Test connection** to verify that WordPress can reach Lutions.
9. Add a shortcode to a page, post, or widget.

Production URLs must use HTTPS. Plain HTTP is accepted only for documented local
development hosts such as `localhost`, `127.0.0.1`, and
`host.docker.internal`.

## Shortcodes

### Public ticket list

```text
[lutions_public_tickets project="bug"]
```

`project` is the public Lutions project slug.

### Ticket list with title and limit

```text
[lutions_public_tickets project="bug" title="Public tickets" limit="10"]
```

Use `title=""` to hide the heading.

### Optional metadata

```text
[lutions_public_tickets project="bug" show_priority="true" show_type="true" show_ticket_type="true" show_counts="true"]
```

Supported metadata options:

- `show_status="false"` hides the status suffix.
- `show_priority="true"` shows the public priority.
- `show_type="true"` shows the Lutions issue type.
- `show_ticket_type="true"` shows the public ticket type.
- `show_counts="true"` shows public comment and attachment counts.
- `show_date="true"` shows the creation date.
- `date_field="created|updated|closed|none"` selects the displayed date.
- `show_more="true"` displays a right-aligned **More** link below the list. It
  uses `detail_url` (or the configured ticket detail page URL) as its target.

Counts include only public comments and public, non-quarantined attachments.

### Widget or sidebar list

For widget/sidebar placements, configure **Ticket detail page URL** under
**Settings -> Lutions** so ticket clicks open on a normal WordPress page in the
main content area.

Normal WordPress widgets are detected automatically and stay in list mode while
the main content renders ticket details. For custom builders, use:

```text
[lutions_public_tickets project="bug" context="widget"]
```

To link a compact list to the complete list on a separate page, set both
`detail_url` and `show_more`:

```text
[lutions_public_tickets project="bug" limit="5" detail_url="/news/" mode="list" show_more="true"]
```

or:

```text
[lutions_public_tickets project="bug" mode="list"]
```

### Public project stats

```text
[lutions_portal_stats project="bug"]
```

This renders total public tickets, counts by status, and the latest public
ticket update timestamp for one public project.

## Markdown rendering

Ticket descriptions and public comments use explicit Markdown fields from
Lutions when available. The plugin renders a deliberately small Markdown subset
and sanitizes the generated HTML through WordPress before output.

Older Lutions instances remain usable through plain-text fallback fields.

## Configuration sources

The plugin reads the API URL in this order:

1. WordPress option saved under **Settings -> Lutions**.
2. `LUTIONS_WP_API_BASE_URL` PHP constant.
3. `LUTIONS_WP_API_BASE_URL` environment variable.

For normal WordPress usage, the settings page is the recommended option.

## Security model

- The current MVP is read-only.
- No privileged Lutions credentials are stored or sent to the browser.
- WordPress does not reproduce Lutions visibility rules.
- Private tickets, private comments, private attachments, quarantined
  attachments, assignees, reporters, and private labels are not exposed by the
  Public Read API contract.

If future versions add public ticket submission, that feature will require a
separate scoped security model.

## Local development

Requirements:

- PHP 8.1+
- Composer
- WordPress 6.4+

```bash
composer install
composer lint
composer analyse
```

The repository also includes a local WordPress/MariaDB smoke environment:

```bash
docker compose up -d
```

Open `http://localhost:8088`, complete the WordPress setup, activate
**Lutions Public Portal**, and configure the API URL under
**Settings -> Lutions**.

Stop the smoke environment with:

```bash
docker compose down
```

Use `docker compose down -v` only when intentionally discarding the local
WordPress database.

## Release packaging

GitHub releases provide a WordPress-compatible ZIP built by GitHub Actions. The
ZIP contains a stable top-level `lutions-wp/` plugin folder, so WordPress can
install or update it through the normal plugin upload flow.

The generic GitHub source archive is not the preferred WordPress install
artifact. Use `lutions-wp-<version>-wordpress.zip` instead.

## Multilingual usage

The plugin is prepared for multilingual WordPress installations:

- Text domain: `lutions-wp`
- Domain path: `/languages`
- Source strings: English

For GitHub-only distribution, translation files can be maintained directly under
`languages/`, for example `lutions-wp-de_DE.po` and `lutions-wp-de_DE.mo`.

## Compatibility

Plugin releases follow Semantic Versioning and declare the compatible Lutions
Public Read API version. The current MVP targets Lutions Public Read API v1.0.

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE).

## Security and support

Please follow [SECURITY.md](SECURITY.md) for vulnerabilities,
[SUPPORT.md](SUPPORT.md) for usage questions, and
[CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidance.
