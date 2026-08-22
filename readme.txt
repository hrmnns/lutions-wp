=== Lutions Public Portal ===
Contributors: lutions
Tags: lutions, tickets, portal, support, public-api
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 0.2.0
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
* `[lutions_public_portal]` renders public categories, projects, and ticket navigation on one WordPress page.
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

Ticket lists are sorted by creation date descending by default, so newly created tickets appear first. Use `sort_by="created|updated"` and `sort_order="asc|desc"` to choose a different order.

Optional public metadata attributes are `show_priority="true"`, `show_type="true"`, `show_ticket_type="true"`, and `show_counts="true"`. Counts include only public comments and public, non-quarantined attachments. Set `show_more="true"` to show a right-aligned More link below the list; it uses `detail_url` or the configured ticket detail page URL as its target.

= Public project stats =

`[lutions_portal_stats project="bug"]`

The `project` attribute is the public Lutions project slug. Ticket details are rendered on the same WordPress page unless a ticket detail page URL is configured under Settings > Lutions.

= Widget ticket list with detail target =

`[lutions_public_tickets project="bug" detail_url="/lutions-wp/" mode="list"]`

Use `detail_url` or the central ticket detail page URL setting when the list is placed in a widget or sidebar and ticket clicks should open on a normal portal page in the main content area. Normal WordPress widgets are detected automatically and stay lists when the target page renders the ticket detail. Add `mode="list"` or `context="widget"` as a fallback for custom builders.

To add a More link below a compact list, use `show_more="true"`. For example:

`[lutions_public_tickets project="bug" limit="5" detail_url="/news/" mode="list" show_more="true"]`

The plugin builds requests from the configured API base URL, for example `/api/v1/public/projects/bug/tickets`.

Ticket descriptions and public comments are rendered from the API's explicit Markdown fields when available. Public attachment images emitted as Markdown images are shown inline. Standalone YouTube links are rendered as responsive youtube-nocookie.com embeds. Neutral Markdown blockquotes from Lutions, for example placeholders for unavailable inline images, are shown as compact notices. The generated HTML is sanitized through WordPress before output. Older Lutions instances continue to work through the plain-text fallback fields.

== Versioning ==

The current public MVP read version is `0.2.0`. Future public releases should continue to use Semantic Versioning.

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

= How are YouTube videos embedded? =

Standalone YouTube links in public ticket descriptions and comments render as privacy-conscious placeholders. The plugin creates the YouTube iframe only after the visitor clicks Load video.

== Screenshots ==

1. Lutions settings page with API URL, connection status, and tools.
2. Public ticket list rendered inside a WordPress page.
3. Public ticket detail rendered inside the active theme layout.

== Changelog ==

= 0.2.0 =

* Adds a complete WordPress public portal with category, project, and ticket navigation on one configured page.
* Adds project-specific and shared WordPress ticket detail page routing.
* Extends normal WordPress search with public Lutions categories, projects, and tickets.
* Adds configurable ticket metadata placement and complete public portal help.

= 0.1.7 =

* Renders supported Markdown links in public ticket descriptions and comments as safe HTML links.
* Sorts ticket lists by creation date descending by default, with shortcode options for creation or update date and ascending or descending order.

= 0.1.6 =

* Adds an optional right-aligned More link below compact ticket lists through `show_more="true"`.
* Organizes the Lutions settings page into Connection, Tools, Help, and About tabs.
* Documents the priority and project requirements for ticket detail targets in the settings help.

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
