# MAIA Connector (maia-wordpress-plugin)

WordPress plugin that lets the MAIA commerce assistant read and edit **Elementor** pages.

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
| `maia_save_failed` | 500 | Elementor refused to save |

### `GET /status`

```json
{ "plugin_version": "0.1.0", "api_version": 1, "wordpress_version": "6.8", "elementor": { "active": true, "version": "3.30.0" } }
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
