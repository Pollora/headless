# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [semantic versioning](https://semver.org/): from 1.0.0 the HTTP contract ([docs/HTTP-CONTRACT.md](docs/HTTP-CONTRACT.md)), the settings, the hooks and the WP-CLI commands do not change in a way that breaks a client before 2.0. The PHP classes are internal.

## [Unreleased]

### Changed

- PHPStan runs at level `max`, with no baseline. No setting, command, hook, route or payload changes.
- Damaged or unexpected data is checked where it is read. A list or an object where text or a number is expected (an option, a database row, a filter, a plugin) reads as empty or `0`, never as `Array` or `1`; the measures of a media file that are not numbers are `null`; database rows, menu items, forms and form tags that cannot be read are left out.
- `wp headless token` and `wp headless schema openapi` refuse an option given as a list (from `wp-cli.yml`, for instance) instead of reading it as `Array`.
- A `headless/search/args` filter that returns neither an array nor a string is ignored.
- In a Pollora project, a `pollora.headless` configuration that is not an array, or a hook service without `add()`, raises a named exception.
- The three SQL queries name their tables with `%i`, which needs WordPress 6.2 or later.

## [1.0.0] - 2026-11-25

First release. WordPress prepared for headless mode on classic installs, Bedrock and Pollora, for any front end that speaks HTTP.

### Added

**Front and public surface**
- Front gate: redirect, holding page or JSON for the public pages, with an allow-list (administration, login, REST, GraphQL, cron, uploads, XML-RPC, sitemap, robots.txt).
- Head clean-up, XML-RPC switch-off, feeds off, core sitemap off, the WordPress host marked noindex.
- `UrlMapper`, the single place a WordPress URL becomes a front-end URL; permalinks and the links inside content point to the front end.
- `wp headless status` and `wp headless theme install [--activate]` (the empty theme WordPress needs).
- Settings from `HEADLESS_CONFIG` / `HEADLESS_FRONTEND_URL` (WordPress, Bedrock) or `config/headless.php` (Pollora); mu-plugin loader (`stubs/pollora-headless.php`) and a Pollora service provider discovered by Composer.
- Multisite (per-site settings), WordPress in a sub-directory, Polylang and WPML with a front end per language.

**REST API**
- CORS policy (listed origins only, `Vary: Origin`, subdomain patterns, preflight).
- Security: users routes need a login, no users provider in the core sitemap, `DISALLOW_FILE_EDIT`.
- Speed: `Cache-Control`, `ETag` and 304 on public answers, `per_page` ceiling.
- API tokens with scopes, routes, expiry and rotation (`wp headless token`).
- `rest_plugins`: load only the listed plugins on REST requests (advanced, off by default).

**Content for a front end**
- Preview of drafts, scheduled and private posts: one-time link token, a session, `GET /headless/v1/preview/posts/{id}` (autosave, live or revision).
- Revalidation: signed notifications of changes (posts, terms, menus, options, files), merged, sent off the request (Action Scheduler or WP-Cron) with retries; `wp headless revalidate ping`.
- Site manifest `GET /headless/v1/site` (site, languages, menus as trees, locations, front page, content types, taxonomies).
- Redirects: managed rules (`wp headless redirect`), old slugs of renamed posts, Rank Math's redirections; `GET /headless/v1/redirects` and `/redirects/resolve`.
- Media: `GET /headless/v1/media/{id}`, the `headless_featured_media` field, the focal point of `pollora/media`, `media.cdn_url`.
- Blocks as stable JSON (`headless_blocks`).
- SEO metadata in one shape (`headless_seo`) from Yoast SEO, Rank Math, All in One SEO and SEOPress, with every address on the front end.
- Search: `GET /headless/v1/search` (`headless/search/args` filter).
- Forms: `GET /headless/v1/forms` and `/forms/{provider}/{id}` from Contact Form 7 (`headless/forms/providers` filter for other plugins).

**GraphQL (optional)**
- WPGraphQL's settings imposed in code (introspection, debug, batch size, depth), CORS from the package policy, API tokens with scopes (a read token cannot run a mutation), `X-GraphQL-Preview` allowed by CORS.

**Tooling**
- `wp headless doctor [--format=json]`: checks on the running site, with the fix for each finding; fails when a check fails.
- `wp headless schema openapi|graphql`: the schema of the API as files for a client generator.
- `docs/HTTP-CONTRACT.md`, the HTTP contract, with integration tests that keep it true.
- Integration suite against a real WordPress (with Yoast SEO, Polylang, WPGraphQL, Contact Form 7) and `tests/E2E/run.sh`, the contract checked over real HTTP.

### Security
A review of tokens, preview, CORS, redirects, the gate, webhooks and the new routes led to these fixes before the release:
- A redirect rule can no longer send a visitor to another site through `$1` (`/go/(.*)` to `/$1` with `/go//evil.com`).
- Redirect patterns that repeat a repetition (`(a+)+`) are refused, and the resolve route takes paths of 512 characters at most.
- The front gate sees `/wp-admin/../` as the home page.
- A rotated token never outlives the expiry it already had.
- A preview token only opens posts of the site that made it (multisite).
- `preview.secret` must have 32 characters at least: the site refuses to start with a shorter one.
- Search pages stop at 100.
- A wrong `hl.` token answers 401 on REST (it was served as an anonymous request).

### Known limits
- A wrong or expired API token on GraphQL is treated as no token.
- Gravity Forms, WPForms and The SEO Framework are not supported; the redirections of Yoast Premium, All in One SEO Pro and SEOPress are not read.
- No purge of a CDN cache, no rate limit (leave it to the CDN).
- Rank Math, All in One SEO and SEOPress are tested against what they print, not against the real plugin; WPML is not tested on a real site.

[Unreleased]: https://github.com/Pollora/headless/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Pollora/headless/releases/tag/v1.0.0
