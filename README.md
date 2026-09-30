# MAIA Connector (maia-wordpress-plugin)

WordPress plugin that lets the MAIA commerce assistant read and edit **Elementor** pages, read and change the **theme CSS**, and clear the site **cache**.

Elementor keeps a page's layout as JSON in the private post meta `_elementor_data`, which the
standard WordPress REST API does not expose. This plugin adds a small REST API under
`/wp-json/maia/v1` for that data. Writes go through Elementor's own document API, so Elementor
normalises the data, writes a revision and regenerates the page CSS.

Scope of version 0.1: read the element tree, and change the **settings of one existing element**
(texts, images, links, colours). It cannot add, move or delete elements.

## Requirements

- WordPress 6.0+, PHP 7.4+
- Elementor (only needed for the `/elementor/*` routes; `/status` reports whether it is active)

## Installation

1. Build the ZIP: `git archive --format=zip --prefix=maia-wordpress-plugin/ -o maia-wordpress-plugin.zip HEAD`
2. WordPress → Plugins → Add New → Upload Plugin, upload the ZIP, activate it.
3. WordPress → Users → Profile → Application Passwords: create one for MAIA and store the user
   name and the password in the MAIA shop settings.

## Authentication and permissions

WordPress's own authentication is used; MAIA sends an **application password** (HTTP Basic auth).
WooCommerce API keys (`ck_…`/`cs_…`) do **not** work, because WooCommerce only accepts them on its
own `wc/*` routes. Application passwords need HTTPS (or `WP_ENVIRONMENT_TYPE=local`).

Every route runs as that user and checks the user's WordPress capabilities:

| Route | Capability |
| --- | --- |
| `GET /status`, `GET /elementor/documents` | `edit_posts` (the list only contains posts the user may edit) |
| Everything on `/elementor/documents/{id}` | `edit_post` for that post |
| `POST /cache/purge` | `manage_options` (administrator) |
| `GET /theme`, `GET /theme/file` | `edit_theme_options` (administrator) |
| `PUT /theme/css` | `edit_theme_options` **and** `edit_css` |

Users without `unfiltered_html` (e.g. authors, or everyone when `DISALLOW_UNFILTERED_HTML` is set)
get every string they write filtered through `wp_kses_post`.

## API (`/wp-json/maia/v1`)

Errors use the WordPress format `{ "code", "message", "data": { "status" } }`.

| Code | Status | Meaning |
| --- | --- | --- |
| `rest_forbidden` | 401/403 | Not logged in, or the user may not edit this post |
| `maia_elementor_inactive` | 503 | Elementor is not active |
| `maia_not_found` | 404 | No post with this id |
| `maia_not_elementor` | 404 | The post is not built with Elementor |
| `maia_element_not_found` | 404 | No element with this id in the document |
| `maia_invalid_settings` | 400 | `settings` is not an object of setting key → value |
| `maia_conflict` | 409 | `expected_hash` no longer matches, so someone else changed the document |
| `maia_save_failed` | 500 | Elementor (or, for `PUT /theme/css`, WordPress) refused to save |
| `maia_cache_url_outside_site` | 400 | `url` of `/cache/purge` is not a page of this site |
| `maia_invalid_css` | 400 | `css` of `PUT /theme/css` is not a text, is longer than 200,000 characters or contains markup (`<tag`, `</`) |
| `maia_theme_unknown` | 404 | `theme` of `GET /theme/file` is neither the active theme nor its parent |
| `maia_theme_file_invalid` | 400 | `path` is not a plain relative path to a `.css` file |
| `maia_theme_file_not_found` | 404 | the stylesheet does not exist in the theme |

### `GET /status`

```json
{ "plugin_version": "0.3.0", "api_version": 1, "wordpress_version": "6.8", "elementor": { "active": true, "version": "3.30.0" },
  "cache": { "active": ["WP Rocket", "WordPress object cache"], "url_purge": ["WP Rocket"] } }
```

### `GET /elementor/documents?search=&page=1&per_page=20`

Pages, posts and Elementor templates built with Elementor, most recently modified first.
Totals are in the `X-WP-Total` / `X-WP-TotalPages` headers.

```json
[{ "id": 5, "title": "Landing", "type": "page", "status": "publish", "link": "https://shop.de/landing/", "modified": "2026-09-25T12:16:33" }]
```

### `GET /elementor/documents/{id}?format=outline|full`

`outline` (default) gives the structure plus a plain-text `summary` of up to 120 characters per
element, without settings. It is small enough to send to an LLM for finding an element. `full`
returns the whole element tree with every setting.

```json
{
  "id": 5, "title": "Landing", "type": "page", "status": "publish", "link": "…", "modified": "…",
  "hash": "3a5b250e9cda87b4f64de14fa788e91e",
  "format": "outline",
  "elements": [{ "id": "c1a2b3c4", "elType": "container", "elements": [
    { "id": "h1a2b3c4", "elType": "widget", "widgetType": "heading", "summary": "Summer sale" }
  ]}]
}
```

`hash` is a fingerprint of the element tree. Send it back as `expected_hash` when writing.

### `GET /elementor/documents/{id}/elements/{element_id}`

One element with all its settings. It lists its children's ids, not the children themselves.

```json
{ "document_id": 5, "hash": "…", "element": { "id": "h1a2b3c4", "elType": "widget", "widgetType": "heading",
  "settings": { "title": "Summer sale", "title_color": "#000000" }, "children": [] } }
```

### `PATCH /elementor/documents/{id}/elements/{element_id}`

```json
{ "settings": { "title": "Winter sale", "align": "center" }, "expected_hash": "3a5b250e…" }
```

- Only the keys you send change. `null` removes a setting.
- `expected_hash` is optional but recommended. A stale hash returns `409 maia_conflict`.
- The response gives the old values of the changed keys as `before` (a key that was not set comes
  back as `null`). **Sending `before` as `settings` undoes the change**, which is what MAIA stores in
  its mutation log.

```json
{
  "document_id": 5, "element_id": "h1a2b3c4",
  "before": { "title": "Summer sale", "align": null },
  "after":  { "title": "Winter sale", "align": "center" },
  "hash": "be4646237ee51e5bdcf2ab9d50abe292",
  "element": { "id": "h1a2b3c4", "elType": "widget", "widgetType": "heading", "settings": { "…": "…" }, "children": [] }
}
```

`element` is read back after saving, so it shows what Elementor actually stored.

## Development

```bash
composer install
composer test   # unit tests of the tree logic (no WordPress needed)
```

`includes/class-elementor-tree.php` holds the pure tree logic (find, outline, patch, validation).
`includes/class-rest-controller.php` holds the routes, permissions and the Elementor calls.

### `POST /cache/purge`

Empties the caches of the site, or purges one page. Body (optional): `{ "url": "https://shop.de/landing/" }`.
The URL must be a page of this site (same host as the site address, http/https, no login data).

```json
{ "scope": "all", "cleared": ["WP Rocket", "WordPress object cache"], "failed": [] }
```

`cleared` names the caches that were emptied, `failed` the ones that threw an error (the others still run). An empty
`cleared` means no supported cache is active (or, for a single page, none of them can purge one page).

Supported: WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, SiteGround
Optimizer, Breeze, Hummingbird, WP-Optimize, Autoptimize, Elementor's generated CSS and the WordPress object cache.
Each one is only called when it is active, through its own public function or action. A single page can be purged in
WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, Cache Enabler and SiteGround Optimizer; the object cache is
only flushed for a full clear. `GET /status` shows which of them are active (`cache.active`, `cache.url_purge`).

Not reached: caches outside WordPress (a CDN such as Cloudflare, the hoster's own page cache).

### Theme CSS

MAIA works out from `GET /theme` which kind of theme a site has, reads the theme's stylesheets to learn its
selectors, and changes the site's **Additional CSS** (the `custom_css` post the Customizer saves under
Appearance → Customize → Additional CSS). The theme's own files are never written: Additional CSS survives theme
updates, edits to `style.css` do not.

#### `GET /theme`

```json
{
  "theme": { "stylesheet": "klassic-child", "name": "Klassic Child", "version": "1.2", "is_block_theme": false,
             "parent": { "stylesheet": "klassic", "name": "Klassic" } },
  "additional_css": { "css": ".btn { color: #b00; }", "hash": "…", "writable": true },
  "files": [ { "theme": "klassic-child", "path": "style.css", "size": 101 }, { "theme": "klassic", "path": "assets/css/main.css", "size": 4211 } ],
  "files_cut": false
}
```

`files` lists the `.css` files of the active theme and its parent (up to 3 levels deep, at most 100, `node_modules`,
`vendor` and `.git` skipped, symlinks ignored). `additional_css.css` is the stored text itself (not the filtered
`wp_get_custom_css()`), `hash` fingerprints it, `writable` says whether the calling user may change it.
`is_block_theme` matters: a block theme keeps its CSS in the global styles (`wp/v2/global-styles`), and MAIA uses
that instead of this route.

#### `GET /theme/file?theme=klassic&path=assets/css/main.css`

The text of one stylesheet, read only: `{ "theme", "path", "css", "size", "truncated" }`. At most 100,000 bytes are
returned (`truncated: true` when it was longer). `theme` must be the active theme or its parent; `path` must be a plain
relative path ending in `.css`: no `..`, no absolute or Windows paths, no other file types, and the real location has
to stay inside the theme directory.

#### `PUT /theme/css`

Replaces the whole Additional CSS of the active theme. Body: `{ "css": "…", "expected_hash": "…" }`. An empty `css`
removes it. `expected_hash` (the `hash` of the last read) makes a stale write fail with `409 maia_conflict`.

```json
{ "stylesheet": "klassic-child", "before": ".btn { color: #b00; }", "after": ".btn { color: #b00; }\n.card { top: 0; }", "hash": "…" }
```

`before` undoes the change when sent as `css`. The CSS must not contain markup (same rule as the Customizer). WordPress
maps `edit_css` to `unfiltered_html`, which editors have on a single site, so the route also requires
`edit_theme_options`; it is denied when `DISALLOW_UNFILTERED_HTML` is set and on a multisite for everyone but super admins.
A page cache may keep serving the old CSS until it is purged (`POST /cache/purge`).
