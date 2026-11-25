# The HTTP contract of pollora/headless

This is what a front end (or any client) can rely on. From 1.0 it follows semantic versioning: a route, a field, a header or a
setting listed here is not removed or changed in a way that breaks a client before 2.0. New routes, new fields and new optional
parameters can appear in any minor release, so **ignore the keys you do not know**.

What is **not** part of the contract: the PHP classes (`Pollora\Headless\…`, internal: use the settings, the hooks and the
WP-CLI commands), the order of the keys inside an object (except where a list below says it is fixed), and the text of an
error `message` (match on `code`).

Examples use `https://cms.example.com` for WordPress and `https://www.example.com` for the front end.

## Conventions

- **Base**: `https://cms.example.com/wp-json/headless/v1`. Fields added to WordPress's own routes (`/wp/v2/...`) are listed under [REST fields](#rest-fields).
- **Format**: JSON, UTF-8. Dates are ISO 8601; `*_gmt` ones are UTC.
- **Errors**: `{ "code": "…", "message": "…", "data": { "status": 4xx } }` with the matching HTTP status. Clients match on `code`.
- **Addresses**: every address a route hands out (post links, menu items, canonical URLs, redirect targets, search results) is on the front end. Files (`uploads`) stay on WordPress, or on the CDN set in `media.cdn_url`. A route that gives no front-end address is only active once `frontend_url` is set.
- **Authentication**: public routes need none. Private content needs an API token (`Authorization: Bearer hl.…`, made with `wp headless token create`), an application password, or a preview session (`hlp.…`, only for the preview route).
- **Caching**: public `GET` answers carry `Cache-Control`, `ETag` and answer `304` to `If-None-Match` (setting `rest_cache`). Answers that depend on who asks carry `Cache-Control: no-store, private` and `Vary: Authorization`.
- **CORS**: only the origins of `cors.origins` and the front-end URL are answered; every answer carries `Vary: Origin`; no wildcard. Allowed request headers: `Authorization`, `Content-Type`, `Accept`, `X-WP-Nonce`, `X-HTTP-Method-Override`, `X-GraphQL-Preview`.

## Routes

| Route | Auth | Setting | Purpose |
| --- | --- | --- | --- |
| `GET /site` | none | `manifest` | The manifest of the site |
| `GET /redirects` | none | `redirects` | Every redirect rule |
| `GET /redirects/resolve?path=` | none | `redirects` | Where one path goes |
| `GET /media/{id}` | none (published files) | `media` | A file of the library |
| `GET /search?q=` | none | `search` | The site search |
| `GET /forms`, `GET /forms/{provider}/{id}` | none | `forms` | Forms and how to send them |
| `POST /preview/exchange` | link token | `preview` | A preview session |
| `GET /preview/posts/{id}` | session or token | `preview` | A post as its editor sees it |

### `GET /site`

```json
{
  "version": 1,
  "site": { "id": 1, "name": "…", "description": "…", "url": "https://www.example.com", "wordpress_url": "https://cms.example.com",
            "locale": "en_US", "language": "en", "timezone": "Europe/Paris", "date_format": "F j, Y", "time_format": "g:i a", "multisite": false },
  "languages": [ { "code": "en", "wordpress_url": "https://cms.example.com/", "url": "https://www.example.com/" } ],
  "front_page": { "show_on_front": "posts", "page_on_front": 0, "page_for_posts": 0, "posts_per_page": 10 },
  "menus": [ { "id": 3, "name": "Main", "slug": "main", "items": [ { "id": 11, "title": "About", "url": "https://www.example.com/about/", "path": "/about/",
             "target": "", "classes": [], "type": "post_type", "object": "page", "object_id": 7, "children": [] } ] } ],
  "locations": [ { "location": "primary", "description": "Primary Menu", "menu": "main" } ],
  "post_types": [ { "name": "post", "label": "Posts", "rest_base": "posts", "rest_namespace": "wp/v2", "hierarchical": false, "has_archive": false, "rewrite_slug": "post" } ],
  "taxonomies": [ { "name": "category", "label": "Categories", "rest_base": "categories", "rest_namespace": "wp/v2", "hierarchical": true, "post_types": ["post"], "rewrite_slug": "category" } ],
  "permalink_structure": "/%postname%/"
}
```

`version` changes only when a key of this object changes meaning. `path` of a menu item is `null` for a link to another site. `languages` is `[]` on a single-language site.

### `GET /redirects` and `GET /redirects/resolve?path=/old/`

```json
{ "count": 2, "redirects": [ { "from": "/old/", "to": "/new/", "status": 301, "regex": false, "source": "manual" } ], "truncated": false }
```

`resolve` answers `200` always: `{ "redirect": null }`, or `{ "redirect": { "from", "to", "status", "source": "manual" | "old-slug" | "rankmath" } }`. `path` must start with `/` and has at most 512 characters (`400` otherwise). `status` is 301, 302, 307, 308 or 410 (a page that is gone: `to` is empty). `to` is a path or an absolute http(s) URL, never `//host`.

### `GET /media/{id}`

```json
{ "id": 42, "kind": "image", "mime": "image/jpeg", "url": "https://cms.example.com/wp-content/uploads/a.jpg", "title": "…", "alt": "…", "caption": "…", "description": "…",
  "width": 1600, "height": 900, "filesize": 123456, "duration": null, "focal_point": { "x": 0.25, "y": 0.75 },
  "sizes": { "medium": { "url": "…", "width": 300, "height": 169 }, "full": { "url": "…", "width": 1600, "height": 900 } },
  "srcset": "… 300w, … 1600w", "modified_gmt": "2026-01-01T00:00:00" }
```

`kind` is `image`, `video`, `audio` or `file`. `focal_point` is `null` without `pollora/media`. Errors: `media_not_found` (404; also a file attached to a draft, for a visitor).

### `GET /search`

Parameters: `q` (required, 2 to 100 characters), `type` (comma-separated content types; default every public type), `lang` (a language code of the site), `page` (1 to 100), `per_page` (capped by `per_page_max`).

```json
{ "query": "finch", "page": 1, "per_page": 10, "total": 1, "pages": 1,
  "results": [ { "id": 4, "type": "post", "title": "…", "excerpt": "…", "url": "https://www.example.com/finch/", "language": null, "date": "2026-11-25T08:53:16+00:00", "image": null } ] }
```

Headers `X-WP-Total` and `X-WP-TotalPages`. Password-protected posts are left out. Errors: `headless_search_term` (400).

### `GET /forms`, `GET /forms/{provider}/{id}`

```json
{ "forms": [ { "provider": "cf7", "id": "12", "title": "Contact" } ] }
```

```json
{ "provider": "cf7", "id": "12", "title": "Contact",
  "fields": [ { "name": "your-email", "type": "email", "required": true, "multiple": false, "options": [] } ],
  "submit": { "method": "POST", "url": "https://cms.example.com/wp-json/contact-form-7/v1/contact-forms/12/feedback", "content_type": "multipart/form-data", "extra": { "_wpcf7_unit_tag": "wpcf7-f12-o1" } } }
```

`type` is one of `text, email, url, tel, number, range, date, textarea, select, checkbox, radio, acceptance, quiz, file`. `submit.extra` are fields to send with the others. Provider: `cf7`. Errors: `headless_form_not_found` (404).

### Preview

`POST /preview/exchange` with `{ "token": "<link token>" }` gives `{ "session": "hlp.…", "expires_in": 1800, "post": { "id", "type", "status", "link" } }`. The link token comes in the address the editor's Preview button opens (`…/api/preview?token=…&id=42&type=post&path=…&site=1&locale=en_US`); it works once, for five minutes.

`GET /preview/posts/{id}?rev=autosave|live|<revision id>` with `Authorization: Bearer hlp.…` (or an API token of a user who can edit the post) gives the post as `/wp/v2/` gives it, plus `preview: { "source": "autosave" | "live" | "revision", "status", "modified_gmt", "scheduled_gmt" }`. `_fields` works. `headless_blocks`, `headless_seo` and `headless_featured_media` are `null` here.

Errors, by `code`: `preview_token_invalid`, `preview_token_expired` (401), `preview_token_used` (409), `preview_forbidden` (403), `preview_not_found` (404).

## REST fields

Added to the objects of WordPress's REST API. The costly ones are computed only when `_fields` names them.

| Field | On | Shape |
| --- | --- | --- |
| `link` (core) | posts, terms, users | the address on the front end |
| `content.rendered` (core) | posts | links to the site point to the front end |
| `headless_blocks` | posts | list of `{ id, name, attributes, html, rendered, inner_blocks }`, same keys in the same order; `null` for a protected post |
| `headless_seo` | posts, terms | `{ source, title, description, robots: { index, follow, directives }, canonical, open_graph, twitter, json_ld, breadcrumbs }`, `null` when no SEO plugin |
| `headless_featured_media` | posts | the shape of `GET /media/{id}`, or `null` |

## Notifications to your front end (webhook)

Configured under `revalidation.targets` (a URL and a shared secret). A `POST` with a JSON body:

```json
{ "id": "5f0c1a…", "sent_at": 1767225600, "truncated": false,
  "events": [ { "event": "post.published", "tags": ["post:12", "type:post", "site:1"], "urls": ["https://www.example.com/hello/"],
                "post": { "id": 12, "type": "post", "slug": "hello", "old_slug": null, "status_from": "draft", "status_to": "publish" }, "site": 1, "locale": "en_US" } ],
  "tags": ["post:12", "type:post", "site:1"], "urls": ["https://www.example.com/hello/"], "paths": ["/hello/"] }
```

Events: `post.published`, `post.updated`, `post.unpublished`, `post.deleted`, `term.changed`, `menu.changed`, `options.changed`, `media.changed`, and `ping` (from `wp headless revalidate ping`). Tags: `post:{id}`, `type:{post type}`, `term:{id}`, `taxonomy:{name}`, `menus`, `menu:{id}`, `menu:{slug}`, `options`, `option:{name}`, `media`, `media:{id}`, `site:{id}`.

Headers: `X-Headless-Signature: sha256=<hex>` (HMAC-SHA256 of `<timestamp>.<raw body>` with the secret), `X-Headless-Timestamp` (seconds), `X-Headless-Delivery` (the `id`; a retry repeats it), `X-Headless-Attempt` (1 to 5). Answer `2xx` to confirm; any other answer is retried after 30 s, 2 min, 10 min and 1 h. Reject a timestamp older than five minutes and ignore a delivery you have already handled.

## API tokens

`Authorization: Bearer hl.<user>.<id>.<secret>`, shown once by `wp headless token create <user> --name=… --scopes=read|write --routes=… --expires=…`. A `read` token makes `GET`, `HEAD` and `OPTIONS` requests; `write` also changes things. `--routes` limits a token to route prefixes (`/wp/v2/posts`; `/graphql` for GraphQL). A wrong, revoked or expired token answers `401` (`headless_token_invalid`) on REST; outside its scopes `403` (`headless_token_scope`). On GraphQL a wrong token is treated as no token.

## GraphQL

When WPGraphQL is active: introspection is closed to visitors, the batch size and the depth are capped (`graphql` setting), the CORS answer follows the policy above, and API tokens work with the same scopes (a token without `write` cannot run a mutation). A server-side client previews with its token and `X-GraphQL-Preview: database_id=12`.

## WP-CLI

`wp headless status`, `doctor [--format=json]`, `theme install [--activate]`, `token create|revoke|rotate`, `redirect add|remove|test`, `revalidate ping`, `schema openapi|graphql [--file=]`. Exit codes follow WP-CLI: non-zero when a command fails (`doctor` fails when a check fails).

## Settings

Every setting, with its default, is in `config/headless.php` (Pollora) or the `HEADLESS_CONFIG` constant (WordPress, Bedrock), with the short constants `HEADLESS_FRONTEND_URL`, `HEADLESS_REVALIDATE_URL`, `HEADLESS_REVALIDATE_SECRET` and `HEADLESS_SECRET`. A setting is not removed or given another meaning before 2.0; an invalid value stops the site from starting with a message that names the key.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `headless/search/args` | filter | change the `WP_Query` arguments of `GET /search` |
| `headless/forms/providers` | filter | add a form provider (`FormProvider`) |
| `pollora/headless/flush`, `pollora/headless/retry` | action | the scheduled sending of notifications |
| `pollora/media/focal_point` | filter | the focal point of a file |
