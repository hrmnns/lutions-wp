=== Lutions Public Portal ===
Contributors: lutions
Tags: lutions, tickets, portal, support, public-api
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 0.1.5
License: MIT
License URI: https://github.com/hrmnns/lutions-wp/blob/main/LICENSE

Reference WordPress integration for the Lutions Public Read API.

== Description ==

Lutions Public Portal renders public Lutions tickets inside WordPress through shortcodes.

The plugin is intentionally read-only in its first MVP. It stores the Lutions API base URL in WordPress, performs server-side requests, and never exposes privileged Lutions credentials to browser JavaScript.
With an API base URL ending in `/api/v1`, public ticket resources are read under `/public/projects/...`.

The settings page includes connection diagnostics, shortcode examples, plugin/API version information, and a short translation note.

Current shortcodes:

* `[lutions_public_tickets project="project-slug"]` lists public tickets and links to theme-integrated detail views.
* `[lutions_portal_stats project="project-slug"]` renders public project statistics.
* `[lutions_release_feed]` is reserved for a later, explicitly defined public feed contract.

== Installation ==

1. Upload or clone the plugin into `wp-content/plugins/lutions-wp`.
2. Activate "Lutions Public Portal" in the WordPress admin area.
3. Go to Settings > Lutions.
4. Configure the Lutions Public Read API base URL. The URL must point to `/api/v1`.
5. Use "Test connection" to verify that the public API is reachable.
6. Add a shortcode such as `[lutions_public_tickets project="bug"]` to a page.

== Shortcodes ==

= Public ticket list =

`[lutions_public_tickets project="bug"]`

= Public ticket list with custom title and limit =

`[lutions_public_tickets project="bug" title="Public tickets" limit="10"]`

Use `title=""` to hide the list heading. Ticket lists use a flat, non-indented list style for sidebar and widget layouts.

Use `show_status="false"` to hide the status suffix. Use `show_date="true"` to show the ticket creation date using the WordPress date format, or use `date_field="created|updated|closed|none"` to choose the displayed date.

Optional public metadata attributes are `show_priority="true"`, `show_type="true"`, `show_ticket_type="true"`, and `show_counts="true"`. Counts include only public comments and public, non-quarantined attachments.

= Public project stats =

`[lutions_portal_stats project="bug"]`

The `project` attribute is the public Lutions project slug. Ticket details are rendered on the same WordPress page unless a ticket detail page URL is configured under Settings > Lutions.

= Widget ticket list with detail target =

`[lutions_public_tickets project="bug" detail_url="/lutions-wp/" mode="list"]`

Use `detail_url` or the central ticket detail page URL setting when the list is placed in a widget or sidebar and ticket clicks should open on a normal portal page in the main content area. Normal WordPress widgets are detected automatically and stay lists when the target page renders the ticket detail. Add `mode="list"` or `context="widget"` as a fallback for custom builders.

The plugin builds requests from the configured API base URL, for example `/api/v1/public/projects/bug/tickets`.

Ticket descriptions and public comments are rendered from the API's explicit Markdown fields when available. The generated HTML is sanitized through WordPress before output. Older Lutions instances continue to work through the plain-text fallback fields.

== Versioning ==

The current public MVP read version is `0.1.5`. Future public releases should continue to use Semantic Versioning.

The plugin targets Lutions Public Read API v1.0 in the current MVP.

== Translations ==

The plugin uses the text domain `lutions-wp` and the `/languages` domain path. Source strings are English. For GitHub-only distribution, translation files can be added under `languages/`, for example `lutions-wp-de_DE.po` and `lutions-wp-de_DE.mo`.

== Frequently Asked Questions ==

= Does the plugin require a Lutions API token? =

No. The current MVP consumes only public read endpoints. A future public submission feature would need its own scoped submission-channel security model.

= Can production instances use plain HTTP? =

No. Production API URLs must use HTTPS. Plain HTTP is accepted only for documented local development hosts in local/development WordPress environments.

= Does the plugin render private or internal tickets? =

No. The plugin relies on the Lutions Public Read API contract. Internal visibility decisions must remain enforced by Lutions, not by WordPress.

== Screenshots ==

1. Lutions settings page with API URL, connection status, and tools.
2. Public ticket list rendered inside a WordPress page.
3. Public ticket detail rendered inside the active theme layout.

== Changelog ==

= 0.1.5 =

* Keeps normal WordPress widget and sidebar ticket lists in list mode while the main content renders ticket details.
* Adds `context="widget"` as an explicit shortcode fallback for custom builders.
* Reworks the GitHub README for easier WordPress installation, version, shortcode, and security orientation.

= 0.1.4 =

* Adds optional public ticket metadata display for priority, issue type, ticket type, dates, and public counts.
* Keeps existing shortcode defaults backwards compatible.
* Updates settings-page help and documentation for widget/list metadata options.

= 0.1.3 =

* Keeps widget and sidebar ticket lists in list mode with `mode="list"`.
* Lets widget ticket links open on a configured portal page in the main content area.
* Adds GitHub Actions packaging for WordPress-compatible release ZIP files.

= 0.1.2 =

* Added a configurable ticket detail page URL for widget and sidebar placements.
* Added a `detail_url` shortcode override for public ticket lists.
* Restricted ticket detail targets to the current WordPress site.

= 0.1.1 =

* Render public ticket descriptions and public comments from explicit Lutions Markdown fields when available.
* Sanitize generated Markdown HTML through WordPress before output.
* Keep plain-text fallbacks for older Lutions instances.

= 0.1.0 =

* Initial public read MVP.
* Added public ticket list shortcode.
* Added theme-integrated ticket detail rendering.
* Added public project stats shortcode.
* Added WordPress admin settings for the Lutions API base URL.
* Added connection test and cache clearing tools.
