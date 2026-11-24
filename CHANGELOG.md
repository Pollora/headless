# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows semantic versioning (`0.x` may change between minors).

## [Unreleased]

### Added
- Front gate: redirect, holding page or JSON for the public pages, with an allow-list (administration, login, REST, GraphQL, cron, uploads, XML-RPC, sitemap, robots.txt).
- Head clean-up and XML-RPC switch-off.
- `UrlMapper`, the single place a WordPress URL becomes a front-end URL.
- Settings from `HEADLESS_CONFIG` / `HEADLESS_FRONTEND_URL` (WordPress, Bedrock) or `config/headless.php` (Pollora).
- Public surface: feeds (404), core sitemap off, origin noindex.
- `wp headless status` and `wp headless theme install [--activate]` (the empty theme WordPress needs).
- Multisite: per-site settings (`sites`), site context. Sub-directory installs.
- Integration suite against a real WordPress.
- CORS policy for the REST API (listed origins only, `Vary: Origin`, patterns, preflight).
- REST security: users routes need a login, no users provider in the core sitemap, `DISALLOW_FILE_EDIT`.
- REST speed: `Cache-Control`, `ETag` and 304 on public answers, `per_page` ceiling.
- Front-end links: permalinks and content links point to the front end.
- Multilingual sites: Polylang and WPML, a front end per language (`frontend_urls`).
- API tokens with scopes, routes, expiry and rotation (`wp headless token`).
- mu-plugin loader (`stubs/pollora-headless.php`) and a Pollora service provider discovered by Composer.
